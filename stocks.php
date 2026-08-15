<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
$isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$pdo->exec($isSqlite
    ? 'CREATE TABLE IF NOT EXISTS stock_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, stock_code TEXT NOT NULL UNIQUE, stock_name TEXT NOT NULL, brand TEXT, model TEXT, device_type TEXT, serial_no TEXT NOT NULL UNIQUE, uts_lot_no TEXT, warranty_start TEXT, warranty_end TEXT, sgk_status TEXT, min_stock INTEGER DEFAULT 0, max_stock INTEGER DEFAULT 0, purchase_price REAL DEFAULT 0, sale_price REAL DEFAULT 0, vat_rate REAL DEFAULT 20, unit_cost REAL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS stock_cards (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, stock_code VARCHAR(100) NOT NULL UNIQUE, stock_name VARCHAR(190) NOT NULL, brand VARCHAR(190) NULL, model VARCHAR(190) NULL, device_type VARCHAR(100) NULL, serial_no VARCHAR(190) NOT NULL UNIQUE, uts_lot_no VARCHAR(190) NULL, warranty_start DATE NULL, warranty_end DATE NULL, sgk_status VARCHAR(100) NULL, min_stock INT NOT NULL DEFAULT 0, max_stock INT NOT NULL DEFAULT 0, purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0, sale_price DECIMAL(12,2) NOT NULL DEFAULT 0, vat_rate DECIMAL(5,2) NOT NULL DEFAULT 20, unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$hasImagePath = $isSqlite ? (bool)array_filter($pdo->query('PRAGMA table_info(stock_cards)')->fetchAll(), static fn($column) => $column['name'] === 'image_path') : (function() use ($pdo): bool {$query=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="stock_cards" AND column_name="image_path"');$query->execute();return(bool)$query->fetchColumn();})();
if (!$hasImagePath) $pdo->exec('ALTER TABLE stock_cards ADD COLUMN image_path VARCHAR(255) NULL');
$hasPowerUsage = $isSqlite ? (bool)array_filter($pdo->query('PRAGMA table_info(stock_cards)')->fetchAll(), static fn($column) => $column['name'] === 'power_usage') : (function() use ($pdo): bool {$query=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="stock_cards" AND column_name="power_usage"');$query->execute();return(bool)$query->fetchColumn();})();
if (!$hasPowerUsage) $pdo->exec('ALTER TABLE stock_cards ADD COLUMN power_usage VARCHAR(50) NULL');
$hasProductColor = $isSqlite ? (bool)array_filter($pdo->query('PRAGMA table_info(stock_cards)')->fetchAll(), static fn($column) => $column['name'] === 'product_color') : (function() use ($pdo): bool {$query=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="stock_cards" AND column_name="product_color"');$query->execute();return(bool)$query->fetchColumn();})();
if (!$hasProductColor) $pdo->exec('ALTER TABLE stock_cards ADD COLUMN product_color VARCHAR(50) NULL');
$pdo->exec($isSqlite
    ? 'CREATE TABLE IF NOT EXISTS app_migrations (migration_key VARCHAR(190) PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS app_migrations (migration_key VARCHAR(190) PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$stockImageMigration = '20260815_resound_stock_images_v1';
$stockImageMigrationCheck = $pdo->prepare('SELECT 1 FROM app_migrations WHERE migration_key=?');
$stockImageMigrationCheck->execute([$stockImageMigration]);
if (!$stockImageMigrationCheck->fetchColumn()) {
    $resoundStockCodes = [
        'KE267', 'KE277', 'REFLT', 'KE4CIC-MP', 'KE4CIC-HP', 'EQ988', 'EQ788', 'RU960 DRWC',
        'RU761 DRWC', 'RU961-DRW', 'RU961-DRWC', 'RU988', 'RU977', 'RU761-DRW', 'RU760-DRWC',
        'RU788', 'RU777', 'RU561-DRWC', 'RU561-DRW', 'RU560-DRWC', 'RU588', 'RU577', 'EQ998',
        'EQ798', 'EQ588', 'EQ598', 'RE9CIC-W-MP', 'RE9CIC-W-HP', 'RE7CIC-W-MP', 'RE7CIC-W-HP',
        'RE5CIC-W-MP', 'RE5CIC-W-HP', 'KE477', 'KE467', 'KE398', 'KE388', 'KE377', 'KE367',
        'KE361-DRWC', 'KE361-DRW', 'KE3CIC-W-MP', 'KE3CIC-W-HP', 'KE188',
    ];
    $placeholders = implode(',', array_fill(0, count($resoundStockCodes), '?'));
    $stockImageUpdate = $pdo->prepare("UPDATE stock_cards SET image_path=? WHERE TRIM(COALESCE(image_path,''))='' AND stock_code IN ($placeholders)");
    $stockImageUpdate->execute(['assets/uploads/stocks/resound-hearing-aids-family.png', ...$resoundStockCodes]);
    $pdo->prepare('INSERT INTO app_migrations(migration_key) VALUES(?)')->execute([$stockImageMigration]);
}
$pdo->exec($isSqlite
    ? 'CREATE TABLE IF NOT EXISTS stock_movements (id INTEGER PRIMARY KEY AUTOINCREMENT, stock_id INTEGER NOT NULL, movement_type TEXT NOT NULL, quantity INTEGER NOT NULL, movement_date TEXT NOT NULL, description TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS stock_movements (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, stock_id INT UNSIGNED NOT NULL, movement_type VARCHAR(30) NOT NULL, quantity INT NOT NULL, movement_date DATE NOT NULL, description TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX stock_movements_stock_id (stock_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verify_csrf();
    $deleteId = filter_var($_POST['delete_id'], FILTER_VALIDATE_INT);
    if ($deleteId) $pdo->prepare('DELETE FROM stock_cards WHERE id = ?')->execute([$deleteId]);
    header('Location: ' . url('stocks.php?deleted=1')); exit;
}

$stocks = $pdo->query("SELECT s.*, COALESCE(m.stock_quantity, 0) AS stock_quantity FROM stock_cards s LEFT JOIN (SELECT stock_id, SUM(CASE WHEN movement_type = 'Giriş' THEN quantity WHEN movement_type = 'Çıkış' THEN -quantity ELSE 0 END) AS stock_quantity FROM stock_movements GROUP BY stock_id) m ON m.stock_id = s.id ORDER BY s.stock_name, s.brand, s.model, s.id DESC")->fetchAll();
patient_header('Stok Kartları Listesi', 'stock');
?>
<main class="patient-container stock-list-page">
  <section class="vuexy-form-card stock-list-card">
    <header class="form-card-title stock-list-heading">
      <span><h1>Stok Kartları Listesi</h1><p><?=count($stocks)?> kayıt</p></span>
      <a class="button stock-new-button" href="<?=e(url('stock-card.php'))?>">+ Yeni Stok Kartı</a>
    </header>
    <div class="stock-search"><i class="icon-base ti tabler-search"></i><input id="stock-list-search" type="search" placeholder="Stok kodu, stok adı, marka veya model ara" autocomplete="off"><select id="stock-type-filter" aria-label="Stok tipine göre süz"><option value="">Tüm stok tipleri</option><?php foreach (array_values(array_unique(array_filter(array_map(static fn($stock) => trim((string)($stock['stock_type'] ?? '')), $stocks)))) as $stockType): ?><option value="<?=e($stockType)?>"><?=e($stockType)?></option><?php endforeach; ?></select></div>
    <div class="table-responsive">
      <table class="stock-list-table">
        <thead><tr><th>Stok Kodu</th><th>Stok Tipi</th><th>Stok Adı</th><th>Marka / Model</th><th>Cihaz Tipi</th><th>Güç Kullanımı</th><th>Renk</th><th>Görsel</th><th>Stok Miktarı</th><th>Kritik Stok</th><th>KDV Oranı</th><th>İşlemler</th></tr></thead>
        <tbody>
        <?php if (!$stocks): ?>
          <tr><td colspan="12" class="stock-empty">Henüz stok kartı bulunmuyor.</td></tr>
        <?php else: foreach ($stocks as $stock): ?>
          <tr data-stock-type="<?=e((string)($stock['stock_type'] ?? ''))?>">
            <td><?=e($stock['stock_code'])?></td><td><?=e($stock['stock_type'] ?: '—')?></td><td><?=e($stock['stock_name'])?></td>
            <td><?=e(trim((string)$stock['brand'] . ' ' . (string)$stock['model']) ?: '—')?></td>
            <td><?=e($stock['device_type'] ?: '—')?></td><td><?=e($stock['power_usage'] ?: '—')?></td><td><?=e($stock['product_color'] ?: '—')?></td><td class="stock-image-cell"><?php if (!empty($stock['image_path'])): ?><button type="button" class="stock-image-button" data-image-src="<?=e(url($stock['image_path']))?>" data-image-alt="<?=e($stock['stock_name'])?> görseli"><img src="<?=e(url($stock['image_path']))?>" alt="<?=e($stock['stock_name'])?> görseli"></button><?php else: ?>—<?php endif ?></td><td><?=e((string)$stock['stock_quantity'])?></td><td><?=e((string)$stock['min_stock'])?> / <?=e((string)$stock['max_stock'])?></td><td><?=e('%' . (string)(int)$stock['vat_rate'])?></td>
            <td class="stock-actions"><a class="stock-action-button stock-history-action" title="Stok Hareketleri" href="<?=e(url('stock-movements.php?stock_id=' . (int)$stock['id']))?>"><i class="icon-base ti tabler-history"></i></a><a class="stock-action-button stock-edit" title="Düzenle" href="<?=e(url('stock-card.php?id=' . (int)$stock['id']))?>"><i class="icon-base ti tabler-pencil"></i></a><a class="stock-action-button stock-delete" style="margin-left:2px!important" title="Sil" href="#" onclick="event.preventDefault();if(confirm('Bu stok kartı silinsin mi?'))document.getElementById('stock-delete-<?=e((string)$stock['id'])?>').submit();"><i class="icon-base ti tabler-trash"></i></a><form id="stock-delete-<?=e((string)$stock['id'])?>" class="stock-delete-form" method="post" action="<?=e(url('stocks.php'))?>"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="delete_id" value="<?=e((string)$stock['id'])?>"></form></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<div id="stock-image-modal" class="stock-image-modal" hidden role="dialog" aria-modal="true" aria-label="Ürün görseli"><button type="button" class="stock-image-modal-close" aria-label="Kapat">×</button><div class="stock-image-modal-content"><img src="" alt=""></div></div>
<script>(()=>{const modal=document.getElementById('stock-image-modal'),image=modal?.querySelector('img'),close=modal?.querySelector('button');if(!modal||!image||!close)return;const hide=()=>{modal.hidden=true;image.src=''};document.querySelectorAll('.stock-image-button').forEach(button=>button.addEventListener('click',()=>{image.src=button.dataset.imageSrc||'';image.alt=button.dataset.imageAlt||'';modal.hidden=false}));close.addEventListener('click',hide);modal.addEventListener('click',event=>{if(event.target===modal)hide()});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)hide()})})();</script>
<script>(()=>{const input=document.getElementById('stock-list-search'),typeFilter=document.getElementById('stock-type-filter'),rows=[...document.querySelectorAll('.stock-list-table tbody tr')];if(!input||!typeFilter)return;const filter=()=>{const query=input.value.trim().toLocaleLowerCase('tr-TR'),type=typeFilter.value;rows.forEach(row=>{if(row.classList.contains('stock-empty'))return;const matchesText=!query||row.textContent.toLocaleLowerCase('tr-TR').includes(query),matchesType=!type||row.dataset.stockType===type;row.hidden=!(matchesText&&matchesType)})};input.addEventListener('input',filter);typeFilter.addEventListener('change',filter)})();</script>
<style>
.stock-list-page{width:100%!important;max-width:none!important;margin:0!important;padding:28px 32px 48px!important}.stock-list-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.stock-list-heading{display:flex!important;align-items:center;justify-content:space-between;gap:18px;padding:22px 24px;border-bottom:1px solid #e1e2e8}.stock-list-heading h1{margin:0 0 5px;font-size:21px;color:#2f2b3d}.stock-list-heading p{margin:0;color:#7b7b8d}.stock-new-button{white-space:nowrap;text-decoration:none}.stock-search{display:flex;align-items:center;gap:9px;padding:14px 24px;border-bottom:1px solid #e1e2e8;color:#7b7b8d}.stock-search input{width:100%;height:40px;padding:0 12px;border:1px solid #d5d3de;border-radius:6px;font:inherit}.table-responsive{overflow-x:auto}.stock-list-table{width:100%;min-width:1280px;border-collapse:collapse}.stock-list-table th,.stock-list-table td{padding:15px 18px;border-bottom:1px solid #e1e2e8;text-align:left;white-space:nowrap}.stock-list-table th{font-size:12px;text-transform:uppercase;color:#6d6b7f}.stock-list-table tbody tr:last-child td{border-bottom:0}.stock-image-button{display:block;padding:0;border:0;background:transparent;cursor:zoom-in}.stock-image-cell img{display:block;width:42px;height:42px;object-fit:cover;border:1px solid #e1e2e8;border-radius:6px}.stock-image-modal{position:fixed;z-index:1000;inset:0;display:grid;place-items:center;padding:32px;background:#1f1d2dcc}.stock-image-modal[hidden]{display:none}.stock-image-modal-content{box-sizing:border-box;max-width:min(800px,85vw);max-height:80vh;padding:12px;overflow:hidden;border-radius:12px;background:#fff;box-shadow:0 20px 60px rgba(0,0,0,.28)}.stock-image-modal-content img{display:block;width:auto;height:auto;max-width:100%;max-height:calc(80vh - 24px);object-fit:contain}.stock-image-modal-close{position:fixed;top:18px;right:22px;width:42px;height:42px;border:0;border-radius:50%;background:#fff;color:#2f2b3d;font-size:30px;line-height:1;cursor:pointer}.stock-actions{display:flex!important;align-items:center!important;gap:8px!important}.stock-actions .stock-action-button{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:42px!important;min-width:42px!important;height:42px!important;min-height:42px!important;margin:0!important;padding:0!important;border:0!important;border-radius:7px!important;color:#fff!important;text-decoration:none!important}.stock-actions .stock-history-action{position:static!important;background:#f5a33b!important;color:#fff!important}.stock-actions .stock-edit{background:#19a94b!important}.stock-actions .stock-delete{background:#e54b59!important}.stock-delete-form{display:none!important}@media(max-width:720px){.stock-list-page{padding:20px 12px 36px!important}.stock-list-heading{align-items:flex-start;flex-direction:column}.stock-new-button{width:100%;text-align:center}.stock-image-modal{padding:18px}.stock-image-modal-content{max-width:92vw;max-height:75vh}.stock-image-modal-content img{max-height:calc(75vh - 24px)}}
</style>
<style>.stock-list-table th,.stock-list-table td{padding:10px 12px}.stock-list-table td{font-size:12px}.stock-search select{height:40px;min-width:190px;padding:0 12px;border:1px solid #d5d3de;border-radius:6px;background:#fff;font:inherit;color:#2f2b3d}@media(max-width:720px){.stock-search{align-items:stretch;flex-direction:column}.stock-search select{width:100%}}</style>
<?php patient_footer(); ?>
