<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
$canEditPrices = is_admin();
if (!$canEditPrices && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    http_response_code(403);
    exit('Fiyat listelerini yalnızca yöneticiler değiştirebilir.');
}
require_once __DIR__ . '/price-list-item-edit.php';
require __DIR__ . '/patient-layout.php';

function stock_price_parse_amount(string $value): float
{
    $value = trim(str_replace(' ', '', $value));
    if (str_contains($value, ',')) return (float)str_replace(',', '.', str_replace('.', '', $value));
    return (float)str_replace('.', '', $value);
}

$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$priceListId = filter_input(INPUT_GET, 'price_list_id', FILTER_VALIDATE_INT) ?: 0;
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS stock_price_lists (id INTEGER PRIMARY KEY AUTOINCREMENT, brand VARCHAR(190) NOT NULL, valid_from DATE NOT NULL, valid_until DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS stock_price_lists (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, brand VARCHAR(190) NOT NULL, valid_from DATE NOT NULL, valid_until DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS stock_price_list_items (price_list_id INTEGER NOT NULL, stock_id INTEGER NOT NULL, list_price DECIMAL(12,2) NOT NULL DEFAULT 0, PRIMARY KEY(price_list_id,stock_id))'
    : 'CREATE TABLE IF NOT EXISTS stock_price_list_items (price_list_id INT UNSIGNED NOT NULL, stock_id INT UNSIGNED NOT NULL, list_price DECIMAL(12,2) NOT NULL DEFAULT 0, PRIMARY KEY(price_list_id,stock_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$priceListStatement = $pdo->prepare('SELECT * FROM stock_price_lists WHERE id=?');
$priceListStatement->execute([$priceListId]);
$priceList = $priceListStatement->fetch();
if (!$priceList) { header('Location: ' . url('price-lists.php')); exit; }
$hasStockType = $sqlite
    ? (bool)array_filter($pdo->query('PRAGMA table_info(stock_cards)')->fetchAll(), static fn(array $column): bool => $column['name'] === 'stock_type')
    : (bool)$pdo->query("SHOW COLUMNS FROM stock_cards LIKE 'stock_type'")->fetch();
if (!$hasStockType) $pdo->exec('ALTER TABLE stock_cards ADD COLUMN stock_type VARCHAR(50) NULL');
$hasImagePath = $sqlite
    ? (bool)array_filter($pdo->query('PRAGMA table_info(stock_cards)')->fetchAll(), static fn(array $column): bool => $column['name'] === 'image_path')
    : (bool)$pdo->query("SHOW COLUMNS FROM stock_cards LIKE 'image_path'")->fetch();
if (!$hasImagePath) $pdo->exec('ALTER TABLE stock_cards ADD COLUMN image_path VARCHAR(255) NULL');
$pdo->prepare('UPDATE stock_cards SET stock_type=? WHERE stock_type=?')->execute(['İşitme Cihazı', 'Kulaklık']);

$itemError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'save_item') {
        try {
            update_price_list_item($pdo, $priceListId, (int)($_POST['stock_id'] ?? 0), $_POST);
            redirect('stock-prices.php?price_list_id='.$priceListId.'&saved=1'.(isset($_GET['_vox_window'])?'&_vox_window=1':''));
        } catch (InvalidArgumentException $exception) {
            $itemError = $exception->getMessage();
            http_response_code(422);
        }
    } else {
    $listPrices = $_POST['list_prices'] ?? [];
    if (is_array($listPrices)) {
        $statement = $sqlite
            ? $pdo->prepare('INSERT INTO stock_price_list_items(price_list_id,stock_id,list_price) VALUES(?,?,?) ON CONFLICT(price_list_id,stock_id) DO UPDATE SET list_price=excluded.list_price')
            : $pdo->prepare('INSERT INTO stock_price_list_items(price_list_id,stock_id,list_price) VALUES(?,?,?) ON DUPLICATE KEY UPDATE list_price=VALUES(list_price)');
        foreach ($listPrices as $stockId => $listPrice) {
            $stockId = (int)$stockId;
            if ($stockId < 1) continue;
            $statement->execute([$priceListId, $stockId, max(0, stock_price_parse_amount((string)$listPrice))]);
        }
    }
    header('Location: ' . url('stock-prices.php?price_list_id=' . $priceListId . '&saved=1')); exit;
    }
}

$statement = $pdo->prepare('SELECT s.id,s.stock_code,s.stock_name,s.brand,s.model,s.device_type,s.image_path,COALESCE(i.list_price,0) AS sale_price FROM stock_cards s LEFT JOIN stock_price_list_items i ON i.stock_id=s.id AND i.price_list_id=? WHERE (s.stock_type=? OR i.stock_id IS NOT NULL) AND s.brand=? ORDER BY s.stock_name,s.stock_code');
$statement->execute([$priceListId, 'İşitme Cihazı', $priceList['brand']]);
$stocks = $statement->fetchAll();
$brandModels = $canEditPrices ? price_list_brand_models($pdo, (string)$priceList['brand']) : [];
// Model descriptions transcribed from the supplied Signia July 2026 price list.
$modelDescriptions = [];
if (strcasecmp((string)$priceList['brand'], 'Signia') === 0) {
    $descriptionData = json_decode((string)file_get_contents(__DIR__ . '/assets/data/signia-model-descriptions.json'), true, 512, JSON_THROW_ON_ERROR);
    $modelDescriptions = $descriptionData['models'];
}
$groups = [];
foreach ($stocks as $stock) $groups[trim((string)$stock['brand']) ?: 'Diğer Markalar'][] = $stock;

patient_header('Liste Fiyatları', 'stock');
?>
<main class="patient-container stock-prices-page">
  <section class="stock-prices-card">
    <header><div><h1><?=e($priceList['brand'])?> Liste Fiyatları</h1><p><?=e(format_date_tr($priceList['valid_from']))?> — <?=e(format_date_tr($priceList['valid_until']))?> geçerlilik dönemi.</p></div><span><?=count($stocks)?> ürün <?php if($canEditPrices): ?>· <a href="<?=e(url('price-lists.php?edit='.$priceListId))?>" title="Marka ve geçerlilik tarihlerini düzenle">Liste bilgilerini düzenle</a><?php endif ?></span></header>
    <?php if (isset($_GET['saved'])): ?><p class="stock-prices-success">Liste fiyatı kaydedildi.</p><?php endif; ?>
    <?php if (!$groups): ?><p class="stock-prices-empty">İşitme cihazı stok kartı bulunmuyor.</p><?php else: ?><form method="post" class="stock-prices-form"><input type="hidden" name="csrf" value="<?=csrf()?>">
      <?php foreach ($groups as $brand => $brandStocks): ?>
        <section class="price-brand-group"><h2><?=e($brand)?></h2><div class="table-responsive"><table><thead><tr><th>STOK KODU</th><th>STOK ADI</th><th>MODEL</th><th>GÖRSEL</th><th>CİHAZ TİPİ</th><th>LİSTE FİYATI</th><th>DÜZENLE</th></tr></thead><tbody>
          <?php foreach ($brandStocks as $stock): ?><tr><td><?=e($stock['stock_code'])?></td><td><?=e($stock['stock_name'])?></td><td><?php $modelDescription = $modelDescriptions[strtoupper(trim((string)$stock['model']))] ?? ''; ?><?php if ($modelDescription !== ''): ?><span class="price-model-description" tabindex="0" title="<?=e($modelDescription)?>" aria-label="<?=e($stock['model'].': '.$modelDescription)?>" style="cursor:help;border-bottom:1px dotted currentColor"><?=e($stock['model'])?></span><?php else: ?><?=e($stock['model'] ?: '—')?><?php endif ?></td><td class="price-image-cell"><?php if ($stock['image_path']): ?><button type="button" class="price-image-open" data-image-src="<?=e(url($stock['image_path']))?>" data-image-alt="<?=e($stock['stock_name'])?> görseli"><img width="42" height="42" src="<?=e(url($stock['image_path']))?>" alt="<?=e($stock['stock_name'])?> görseli"></button><?php else: ?>—<?php endif; ?></td><td><?=e($stock['device_type'] ?: '—')?></td><td><div class="price-form"><input name="list_prices[<?=e((string)$stock['id'])?>]" type="text" inputmode="decimal" <?=!$canEditPrices?'readonly':''?> value="<?=e(number_format((float)$stock['sale_price'], 2, ',', '.'))?>"><span>TL</span></div></td><td><?php if($canEditPrices): ?><button type="button" class="price-item-edit" title="Kalemi düzenle" aria-label="Kalemi düzenle" data-price-item="<?=e(json_encode($stock,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))?>"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="m16 3 5 5M4 15 16 3l5 5L9 20l-6 1 1-6Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button><?php endif ?></td></tr><?php endforeach; ?>
        </tbody></table></div></section>
      <?php endforeach; ?><?php if($canEditPrices): ?><footer class="stock-prices-footer"><button type="submit" title="Liste fiyatlarını kaydet" aria-label="Liste fiyatlarını kaydet"><i class="icon-base ti tabler-device-floppy"></i></button></footer><?php endif ?></form>
    <?php endif; ?>
  </section>
</main>
<?php if($canEditPrices): ?><dialog id="price-item-dialog" aria-labelledby="price-item-title">
<form method="post" class="price-item-editor">
  <header><h2 id="price-item-title">Fiyat Kalemini Düzenle</h2><button type="button" data-close-price-item aria-label="Kapat">×</button></header>
  <?php if($itemError): ?><p role="alert"><?=e($itemError)?></p><?php endif ?>
  <input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_item"><input type="hidden" name="stock_id">
  <label>Stok adı<input name="stock_name" maxlength="190" required></label>
  <label>Model<select name="model"><option value="">Seçiniz</option><?php foreach($brandModels as $modelName): ?><option value="<?=e($modelName)?>"><?=e($modelName)?></option><?php endforeach ?></select></label>
  <label>Cihaz tipi<input name="device_type" maxlength="190"></label>
  <label>Liste fiyatı (TL)<input name="list_price" inputmode="decimal" required placeholder="1.250,50"></label>
  <p>Stok bilgileri ortak stok kartında, fiyat yalnızca bu listede güncellenir.</p>
  <footer><button type="button" data-close-price-item>Vazgeç</button><button type="submit" title="Kaydet" aria-label="Kaydet"><i class="icon-base ti tabler-device-floppy" aria-hidden="true"></i></button></footer>
</form></dialog>
<script>
(()=>{
  const dialog=document.getElementById('price-item-dialog'),form=dialog.querySelector('form');
  const open=(item)=>{
    const modelSelect=form.elements.model;
    modelSelect.querySelector('[data-legacy-model]')?.remove();
    const model=item.model??'';
    if(model && !Array.from(modelSelect.options).some(option=>option.value===model)){
      const option=new Option(model,model);option.hidden=true;option.dataset.legacyModel='1';modelSelect.add(option);
    }
    for(const key of ['stock_id','stock_name','model','device_type','list_price'])form.elements[key].value=item[key]??'';
    dialog.showModal();form.elements.stock_name.focus();
  };
  document.querySelectorAll('[data-price-item]').forEach(button=>button.addEventListener('click',()=>{
    const item=JSON.parse(button.dataset.priceItem);
    const livePrice=button.closest('tr').querySelector('input[name^="list_prices["]');
    open({...item,stock_id:item.id,list_price:livePrice.value});
  }));
  dialog.querySelectorAll('[data-close-price-item]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
  <?php if($itemError): ?>open(<?=json_encode(array_intersect_key($_POST,array_flip(['stock_id','stock_name','model','device_type','list_price'])),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>);<?php endif ?>
})();
</script>

<?php endif ?><div id="price-image-modal" class="price-image-modal<?=strcasecmp((string)$priceList['brand'], 'Signia') === 0 ? ' price-image-modal-signia' : ''?>" hidden role="dialog" aria-modal="true" aria-label="Ürün görseli"><button type="button" class="price-image-modal-close" aria-label="Kapat">×</button><div><img src="" alt=""></div></div>
<script>(()=>{const modal=document.getElementById('price-image-modal'),image=modal?.querySelector('img'),close=modal?.querySelector('button');if(!modal||!image||!close)return;const hide=()=>{modal.hidden=true;image.src=''};document.querySelectorAll('.price-image-open').forEach(button=>button.addEventListener('click',()=>{image.src=button.dataset.imageSrc||'';image.alt=button.dataset.imageAlt||'';modal.hidden=false}));close.addEventListener('click',hide);modal.addEventListener('click',event=>{if(event.target===modal)hide()});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)hide()})})();</script>
<script>(()=>{const parse=value=>{value=String(value||'').replace(/\s/g,'');return Number(value.includes(',')?value.replaceAll('.','').replace(',','.'):value.replaceAll('.',''))||0},format=value=>new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(parse(value));document.querySelectorAll('input[name^="list_prices["]').forEach(input=>input.addEventListener('blur',()=>input.value=format(input.value)))})();</script>



<?php patient_footer(); ?>
