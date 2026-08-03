<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
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

$statement = $pdo->prepare('SELECT s.id,s.stock_code,s.stock_name,s.brand,s.model,s.device_type,s.image_path,COALESCE(i.list_price,0) AS sale_price FROM stock_cards s LEFT JOIN stock_price_list_items i ON i.stock_id=s.id AND i.price_list_id=? WHERE s.stock_type=? AND s.brand=? ORDER BY s.stock_name,s.stock_code');
$statement->execute([$priceListId, 'İşitme Cihazı', $priceList['brand']]);
$stocks = $statement->fetchAll();
$groups = [];
foreach ($stocks as $stock) $groups[trim((string)$stock['brand']) ?: 'Diğer Markalar'][] = $stock;

patient_header('Liste Fiyatları', 'stock');
?>
<main class="patient-container stock-prices-page">
  <section class="stock-prices-card">
    <header><div><h1><?=e($priceList['brand'])?> Liste Fiyatları</h1><p><?=e(format_date_tr($priceList['valid_from']))?> — <?=e(format_date_tr($priceList['valid_until']))?> geçerlilik dönemi.</p></div><span><?=count($stocks)?> ürün</span></header>
    <?php if (isset($_GET['saved'])): ?><p class="stock-prices-success">Liste fiyatı kaydedildi.</p><?php endif; ?>
    <?php if (!$groups): ?><p class="stock-prices-empty">İşitme cihazı stok kartı bulunmuyor.</p><?php else: ?><form method="post" class="stock-prices-form"><input type="hidden" name="csrf" value="<?=csrf()?>">
      <?php foreach ($groups as $brand => $brandStocks): ?>
        <section class="price-brand-group"><h2><?=e($brand)?></h2><div class="table-responsive"><table><thead><tr><th>STOK KODU</th><th>STOK ADI</th><th>MODEL</th><th>GÖRSEL</th><th>CİHAZ TİPİ</th><th>LİSTE FİYATI</th><th></th></tr></thead><tbody>
          <?php foreach ($brandStocks as $stock): ?><tr><td><?=e($stock['stock_code'])?></td><td><?=e($stock['stock_name'])?></td><td><?=e($stock['model'] ?: '—')?></td><td class="price-image-cell"><?php if ($stock['image_path']): ?><button type="button" class="price-image-open" data-image-src="<?=e(url($stock['image_path']))?>" data-image-alt="<?=e($stock['stock_name'])?> görseli"><img src="<?=e(url($stock['image_path']))?>" alt="<?=e($stock['stock_name'])?> görseli"></button><?php else: ?>—<?php endif; ?></td><td><?=e($stock['device_type'] ?: '—')?></td><td colspan="2"><div class="price-form"><input name="list_prices[<?=e((string)$stock['id'])?>]" type="text" inputmode="decimal" value="<?=e(number_format((float)$stock['sale_price'], 2, ',', '.'))?>"><span>TL</span></div></td></tr><?php endforeach; ?>
        </tbody></table></div></section>
      <?php endforeach; ?><footer class="stock-prices-footer"><button type="submit" title="Liste fiyatlarını kaydet" aria-label="Liste fiyatlarını kaydet"><i class="icon-base ti tabler-device-floppy"></i></button></footer></form>
    <?php endif; ?>
  </section>
</main>
<div id="price-image-modal" class="price-image-modal" hidden role="dialog" aria-modal="true" aria-label="Ürün görseli"><button type="button" class="price-image-modal-close" aria-label="Kapat">×</button><div><img src="" alt=""></div></div>
<script>(()=>{const modal=document.getElementById('price-image-modal'),image=modal?.querySelector('img'),close=modal?.querySelector('button');if(!modal||!image||!close)return;const hide=()=>{modal.hidden=true;image.src=''};document.querySelectorAll('.price-image-open').forEach(button=>button.addEventListener('click',()=>{image.src=button.dataset.imageSrc||'';image.alt=button.dataset.imageAlt||'';modal.hidden=false}));close.addEventListener('click',hide);modal.addEventListener('click',event=>{if(event.target===modal)hide()});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)hide()})})();</script>
<script>(()=>{const parse=value=>{value=String(value||'').replace(/\s/g,'');return Number(value.includes(',')?value.replaceAll('.','').replace(',','.'):value.replaceAll('.',''))||0},format=value=>new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(parse(value));document.querySelectorAll('input[name^="list_prices["]').forEach(input=>input.addEventListener('blur',()=>input.value=format(input.value)))})();</script>
<style>.stock-prices-page{max-width:1180px!important;margin:auto;padding:28px 20px 48px}.stock-prices-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;overflow:hidden;box-shadow:0 3px 12px #1e283c0f}.stock-prices-card>header{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px 24px;border-bottom:1px solid #e1e2e8}.stock-prices-card h1{margin:0 0 5px;font-size:21px}.stock-prices-card header p{margin:0;color:#7b7b8d}.stock-prices-card>header span{color:#7b7b8d}.price-brand-group{padding:0 24px 22px}.price-brand-group h2{margin:22px 0 10px;color:#19a94b;font-size:16px}.price-brand-group table{width:100%;border-collapse:collapse}.price-brand-group th,.price-brand-group td{padding:12px 14px;border-bottom:1px solid #e7e6eb;text-align:left;white-space:nowrap}.price-brand-group th{background:#f4fbf6;color:#6d6b7f;font-size:12px}.price-brand-group th:nth-last-child(2),.price-brand-group td:nth-last-child(2){text-align:right}.price-form{display:inline-flex;align-items:center;gap:7px}.price-form input{width:130px;height:36px;padding:0 9px;border:1px solid #d2d2dc;border-radius:6px;text-align:right;font:inherit}.stock-prices-footer{display:flex;justify-content:flex-end;padding:2px 24px 24px}.stock-prices-footer button{display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 16px;border:0;border-radius:6px;background:#19a94b;color:#fff;font-weight:700;cursor:pointer}.stock-prices-success,.stock-prices-empty{margin:18px 24px;padding:12px 14px;border-radius:7px}.stock-prices-success{background:#e5f7ea;color:#137735}.stock-prices-empty{color:#7b7b8d;background:#f7f7fa}.table-responsive{overflow-x:auto}@media(max-width:720px){.stock-prices-page{padding:20px 12px}.stock-prices-card>header{align-items:flex-start;flex-direction:column}.price-brand-group{padding:0 12px 18px}.price-brand-group table{min-width:760px}}</style>
<style>.stock-prices-footer button{justify-content:center;width:40px;padding:0}.stock-prices-footer button i{font-size:20px}.price-image-open{display:block;padding:0;border:0;background:transparent;cursor:zoom-in}.price-image-cell img{display:block;width:42px;height:42px;object-fit:cover;border:1px solid #e1e2e8;border-radius:6px}.price-image-modal{position:fixed;z-index:1000;inset:0;display:grid;place-items:center;padding:32px;background:#1f1d2dcc}.price-image-modal[hidden]{display:none}.price-image-modal>div{max-width:100%;max-height:100%;overflow:auto;background:#fff}.price-image-modal img{display:block;width:auto;height:auto;max-width:none;max-height:none}.price-image-modal-close{position:fixed;top:18px;right:22px;width:42px;height:42px;border:0;border-radius:50%;background:#fff;color:#2f2b3d;font-size:30px;line-height:1;cursor:pointer}</style>
<?php patient_footer(); ?>
