<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$stocks = [];
$stockTypes = [];
$salesByStock = [];
$selectedStockType = trim((string)($_GET['stock_type'] ?? ''));
try {
    $pdo = db();
    $stockTypes = $pdo->query("SELECT DISTINCT stock_type FROM stock_cards WHERE stock_type IS NOT NULL AND stock_type<>'' ORDER BY stock_type")->fetchAll(PDO::FETCH_COLUMN);
    if ($selectedStockType !== '' && !in_array($selectedStockType, $stockTypes, true)) $selectedStockType = '';
    $sql = "SELECT s.id,s.stock_code,s.stock_name,s.brand,s.model,s.stock_type,s.image_path,COALESCE(q.stock_quantity,0) AS stock_quantity FROM stock_cards s INNER JOIN (SELECT stock_id,SUM(CASE WHEN movement_type='Giriş' THEN quantity WHEN movement_type='Çıkış' THEN -quantity ELSE 0 END) AS stock_quantity FROM stock_movements GROUP BY stock_id) q ON q.stock_id=s.id AND q.stock_quantity>0";
    $params = [];
    if ($selectedStockType !== '') { $sql .= ' WHERE s.stock_type=?'; $params[] = $selectedStockType; }
    $sql .= ' ORDER BY s.stock_type,s.brand,s.model,s.stock_name';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $stocks = $statement->fetchAll();
    $salesStatement = $pdo->query("SELECT id,service_date,sales_details FROM patient_services WHERE service_name='Satış' AND COALESCE(sales_details,'')<>'' ORDER BY service_date DESC,id DESC");
    foreach ($salesStatement->fetchAll() as $sale) {
        $details = json_decode((string)$sale['sales_details'], true);
        if (!is_array($details)) continue;
        $paymentType = trim((string)($details['sales_payment_type'] ?? ''));
        $amount = trim((string)($details['sales_payment_amount'] ?? ''));
        $register = static function (string $stockType, string $brand, string $model) use (&$salesByStock, $paymentType, $amount): void {
            if ($brand === '' || $model === '') return;
            $key = $stockType . '|' . $brand . '|' . $model;
            if (!isset($salesByStock[$key])) $salesByStock[$key] = ['payment_type'=>$paymentType, 'amount'=>$amount];
        };
        $register('İşitme Cihazı', trim((string)($details['sales_brand'] ?? '')), trim((string)($details['sales_model'] ?? '')));
        for ($number = 2; $number <= 4; $number++) $register('İşitme Cihazı', trim((string)($details["sales_device_{$number}_brand"] ?? '')), trim((string)($details["sales_device_{$number}_model"] ?? '')));
        $register('Şarj Cihazı', trim((string)($details['sales_charger_brand'] ?? '')), trim((string)($details['sales_charger_model'] ?? '')));
    }
} catch (Throwable $exception) {
    error_log('sales.php stocks: ' . $exception->getMessage());
}

patient_header('Satışlar', 'sales');
?>
<style>
.sales-page{max-width:1500px;margin:0 auto;padding:96px 20px 48px}.sales-card{overflow:hidden;border:1px solid var(--line);border-radius:9px;background:var(--card);box-shadow:0 .25rem 1.125rem rgba(47,43,61,.1)}.sales-head{padding:22px 24px;border-bottom:1px solid var(--line)}.sales-head h1{margin:0 0 5px;font-size:21px}.sales-head p{margin:0;color:var(--muted)}.sales-search{display:flex;align-items:center;gap:9px;padding:16px 24px;border-bottom:1px solid var(--line);color:var(--muted)}.sales-search input,.sales-search select{height:39px;padding:0 12px;border:1px solid #d5d3de;border-radius:6px;background:var(--card);color:var(--text);font:inherit}.sales-search input{width:100%}.sales-search select{width:200px;flex:0 0 200px}.sales-scroll{overflow:auto}.sales-table{width:100%;min-width:850px;border-collapse:collapse}.sales-table th,.sales-table td{padding:14px 18px;border-bottom:1px solid var(--line);text-align:left}.sales-table th{font-size:12px;text-transform:uppercase}.sales-table td{font-size:13px;color:var(--muted)}.sales-stock{font-weight:700;color:#16883d!important;text-align:center!important}.sales-image{width:42px;height:42px;object-fit:cover;border:1px solid var(--line);border-radius:6px}.sales-empty{text-align:center!important;padding:38px!important}@media(max-width:560px){.sales-page{padding:92px 14px 30px}.sales-search{align-items:stretch;flex-direction:column}.sales-search select{width:100%;flex:none}}
</style>
<main class="sales-page"><section class="sales-card"><header class="sales-head"><h1>Satışlar</h1><p>Stokta bulunan tüm ürünler.</p></header><div class="sales-search"><i class="icon-base ti tabler-search"></i><select id="sales-stock-type" aria-label="Stok tipi"><option value="">Tüm stok tipleri</option><?php foreach ($stockTypes as $stockType): ?><option value="<?=e($stockType)?>" <?=$stockType===$selectedStockType?'selected':''?>><?=e($stockType)?></option><?php endforeach; ?></select><input id="sales-search" type="search" placeholder="Stok kodu, stok adı, marka veya model ara" autocomplete="off"></div><div class="sales-scroll"><table class="sales-table"><thead><tr><th>Stok Tipi</th><th>Stok Kodu</th><th>Stok Adı</th><th>Marka</th><th>Model</th><th>Görsel</th><th>Ödeme Tipi</th><th>Giren</th><th>Stok Miktarı</th></tr></thead><tbody><?php foreach ($stocks as $stock): $saleInfo=$salesByStock[(string)$stock['stock_type'].'|'.(string)$stock['brand'].'|'.(string)$stock['model']] ?? null; ?><tr><td><?=e($stock['stock_type'] ?: '—')?></td><td><?=e($stock['stock_code'])?></td><td><?=e($stock['stock_name'])?></td><td><?=e($stock['brand'] ?: '—')?></td><td><?=e($stock['model'] ?: '—')?></td><td><?php if (!empty($stock['image_path'])): ?><img class="sales-image" src="<?=e(url($stock['image_path']))?>" alt="<?=e($stock['stock_name'])?>"><?php else: ?>—<?php endif ?></td><td><?=e($saleInfo['payment_type'] ?? '—')?></td><td><?=e($saleInfo['amount'] ?? '—')?></td><td class="sales-stock"><?=e((string)$stock['stock_quantity'])?></td></tr><?php endforeach; ?><?php if (!$stocks): ?><tr><td class="sales-empty" colspan="9">Stokta ürün bulunmuyor.</td></tr><?php endif; ?></tbody></table></div></section></main>
<script>(()=>{const input=document.getElementById('sales-search'),stockType=document.getElementById('sales-stock-type');stockType?.addEventListener('change',()=>{const url=new URL(location.href);if(stockType.value)url.searchParams.set('stock_type',stockType.value);else url.searchParams.delete('stock_type');location.assign(url)});if(!input)return;input.addEventListener('input',()=>{const q=input.value.trim().toLocaleLowerCase('tr-TR');document.querySelectorAll('.sales-table tbody tr').forEach(row=>{if(row.querySelector('.sales-empty'))return;row.hidden=!!q&&!row.textContent.toLocaleLowerCase('tr-TR').includes(q)})})})();</script>
<?php patient_footer(); ?>
