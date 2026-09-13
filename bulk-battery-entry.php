<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

function bulk_battery_amount(string $value): float { $value=trim(str_replace(' ','',$value)); if(str_contains($value,',')) return (float)str_replace(',', '.', str_replace('.', '', $value)); return (float)str_replace('.', '', $value); }
$pdo=db();
$accounts=$pdo->query("SELECT id,code,title,short_name FROM current_accounts WHERE account_type IN ('supplier','both') ORDER BY title")->fetchAll();
$batteries=$pdo->query("SELECT id,stock_code,stock_name,brand,model,vat_rate FROM stock_cards WHERE stock_type='Pil' ORDER BY brand,model,stock_name")->fetchAll();
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $accountId=(int)($_POST['current_account_id']??0);$invoice=trim((string)($_POST['invoice_no']??''));$date=trim((string)($_POST['movement_date']??''));$quantities=(array)($_POST['quantity']??[]);$prices=(array)($_POST['unit_price']??[]);$vatRates=(array)($_POST['vat_rate']??[]);
    if(!$accountId||$invoice===''||$date==='')$error='Cari, fatura no ve giriş tarihi zorunludur.';
    else{
        $validAccount=$pdo->prepare('SELECT 1 FROM current_accounts WHERE id=?');$validAccount->execute([$accountId]);
        if(!$validAccount->fetchColumn())$error='Geçerli bir cari seçiniz.';
        else{
            $stockIds=array_column($batteries,'id');$entries=[];
            foreach($quantities as $stockId=>$quantity){$stockId=(int)$stockId;$quantity=(int)$quantity;if($quantity<1||!in_array($stockId,$stockIds,true))continue;$unit=max(0,bulk_battery_amount((string)($prices[$stockId]??'')));$vat=(int)bulk_battery_amount((string)($vatRates[$stockId]??'0'));if(!in_array($vat,[0,10,20],true)){$error='KDV oranı yalnız %0, %10 veya %20 olabilir.';break;}$entries[]=[$stockId,$quantity,$unit,$vat];}
            if($error===''){if(!$entries)$error='En az bir pil için miktar giriniz.';else{$insert=$pdo->prepare('INSERT INTO stock_movements(stock_id,movement_type,quantity,movement_date,description,current_account_id,invoice_no,serial_numbers,purchase_price,sale_price,vat_rate,unit_cost) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$updateVat=$pdo->prepare('UPDATE stock_cards SET vat_rate=? WHERE id=?');foreach($entries as [$stockId,$quantity,$unit,$vat]){$insert->execute([$stockId,'Giriş',$quantity,$date,'Toplu Pil Stok Girişi',$accountId,$invoice,json_encode([],JSON_UNESCAPED_UNICODE),$unit*$quantity,0,$vat,$unit]);$updateVat->execute([$vat,$stockId]);}header('Location: '.url('stock-entry.php?saved=1'));exit;}}
        }
    }
}
patient_header('Toplu Pil Stok Girişi','stock');
?>
<main class="patient-container bulk-battery-page"><section class="bulk-battery-card"><header><h1>Toplu Pil Stok Girişi</h1><p>Ortak fatura bilgileriyle birden çok pil kalemini tek seferde kaydedin.</p></header><?php if($error):?><p class="bulk-error"><?=e($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><section class="bulk-common"><label>Cari *<select name="current_account_id" required><option value="">Seçiniz</option><?php foreach($accounts as $account):?><option value="<?=e((string)$account['id'])?>"><?=e($account['code'].' — '.($account['short_name']?:$account['title']))?></option><?php endforeach;?></select></label><label>Fatura No *<input name="invoice_no" required></label><label>Giriş Tarihi *<input type="date" name="movement_date" value="<?=date('Y-m-d')?>" required></label></section><div class="table-responsive"><table><thead><tr><th>PİL STOK KARTI</th><th>MİKTAR</th><th>BİRİM ALIŞ FİYATI</th><th>KDV (%)</th><th>TOPLAM ALIŞ FİYATI</th></tr></thead><tbody><?php foreach($batteries as $battery):?><tr data-row><td><?=e($battery['stock_code'].' — '.$battery['stock_name'])?></td><td><input type="number" min="0" name="quantity[<?=e((string)$battery['id'])?>]" value="0"></td><td><input class="bulk-money" inputmode="decimal" name="unit_price[<?=e((string)$battery['id'])?>]" value="0,00"></td><td><select class="bulk-vat" name="vat_rate[<?=e((string)$battery['id'])?>]"><?php foreach([0,10,20] as $rate):?><option value="<?=$rate?>" <?=$rate===(int)$battery['vat_rate']?'selected':''?>>%<?=$rate?></option><?php endforeach;?></select></td><td class="bulk-total">0,00 TL</td></tr><?php endforeach;if(!$batteries):?><tr><td colspan="5" class="bulk-empty">Pil stok kartı bulunmuyor.</td></tr><?php endif;?></tbody></table></div><footer><a href="<?=e(url('stock-entry.php'))?>">İptal</a><button class="button" type="submit"><i class="icon-base ti tabler-device-floppy"></i></button></footer></form></section></main>
<script>(()=>{const parse=value=>{value=String(value||'').replace(/\s/g,'');return Number(value.includes(',')?value.replaceAll('.','').replace(',','.'):value.replaceAll('.',''))||0},format=value=>new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value);document.querySelectorAll('[data-row]').forEach(row=>{const quantity=row.querySelector('[name^="quantity"]'),price=row.querySelector('.bulk-money'),total=row.querySelector('.bulk-total'),update=()=>total.textContent=format(parse(quantity.value)*parse(price.value))+' TL';quantity.addEventListener('input',update);price.addEventListener('input',update);price.addEventListener('blur',()=>{price.value=format(parse(price.value));update()});update()})})();</script>

<?php patient_footer(); ?>
