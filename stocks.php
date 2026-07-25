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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verify_csrf();
    $deleteId = filter_var($_POST['delete_id'], FILTER_VALIDATE_INT);
    if ($deleteId) $pdo->prepare('DELETE FROM stock_cards WHERE id = ?')->execute([$deleteId]);
    header('Location: ' . url('stocks.php?deleted=1')); exit;
}

$stocks = $pdo->query('SELECT * FROM stock_cards ORDER BY stock_name, brand, model, id DESC')->fetchAll();
patient_header('Stok Listesi', 'stock');
?>
<main class="patient-container stock-list-page">
  <section class="vuexy-form-card stock-list-card">
    <header class="form-card-title stock-list-heading">
      <span><h1>Stok Listesi</h1><p><?=count($stocks)?> kayıt</p></span>
      <a class="button stock-new-button" href="<?=e(url('stock-card.php'))?>">+ Yeni Stok Kartı</a>
    </header>
    <div class="table-responsive">
      <table class="stock-list-table">
        <thead><tr><th>Stok Kodu</th><th>Stok Adı</th><th>Marka / Model</th><th>Cihaz Tipi</th><th>Seri No</th><th>ÜTS / Lot No</th><th>Garanti Bitiş</th><th>Kritik Stok</th><th>Satış Fiyatı</th><th>İşlemler</th></tr></thead>
        <tbody>
        <?php if (!$stocks): ?>
          <tr><td colspan="10" class="stock-empty">Henüz stok kartı bulunmuyor.</td></tr>
        <?php else: foreach ($stocks as $stock): ?>
          <tr>
            <td><?=e($stock['stock_code'])?></td><td><?=e($stock['stock_name'])?></td>
            <td><?=e(trim((string)$stock['brand'] . ' ' . (string)$stock['model']) ?: '—')?></td>
            <td><?=e($stock['device_type'] ?: '—')?></td><td><?=e($stock['serial_no'])?></td>
            <td><?=e($stock['uts_lot_no'] ?: '—')?></td>
            <td><?=!empty($stock['warranty_end']) ? e(format_date_tr($stock['warranty_end'])) : '—'?></td>
            <td><?=e((string)$stock['min_stock'])?> / <?=e((string)$stock['max_stock'])?></td>
            <td><?=number_format((float)$stock['sale_price'], 2, ',', '.')?> ₺</td>
            <td class="stock-actions"><a class="stock-action-button stock-movements" title="Stok Hareketleri" href="<?=e(url('stock-movements.php?stock_id=' . (int)$stock['id']))?>"><i class="icon-base ti tabler-history"></i></a><a class="stock-action-button stock-edit" title="Düzenle" href="<?=e(url('stock-card.php?id=' . (int)$stock['id']))?>"><i class="icon-base ti tabler-pencil"></i></a><a class="stock-action-button stock-delete" style="margin-left:2px!important" title="Sil" href="#" onclick="event.preventDefault();if(confirm('Bu stok kartı silinsin mi?'))document.getElementById('stock-delete-<?=e((string)$stock['id'])?>').submit();"><i class="icon-base ti tabler-trash"></i></a><form id="stock-delete-<?=e((string)$stock['id'])?>" class="stock-delete-form" method="post" action="<?=e(url('stocks.php'))?>"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="delete_id" value="<?=e((string)$stock['id'])?>"></form></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<style>
.stock-list-page{width:100%!important;max-width:none!important;margin:0!important;padding:28px 32px 48px!important}.stock-list-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.stock-list-heading{display:flex!important;align-items:center;justify-content:space-between;gap:18px;padding:22px 24px;border-bottom:1px solid #e1e2e8}.stock-list-heading h1{margin:0 0 5px;font-size:21px;color:#2f2b3d}.stock-list-heading p{margin:0;color:#7b7b8d}.stock-new-button{white-space:nowrap;text-decoration:none}.table-responsive{overflow-x:auto}.stock-list-table{width:100%;min-width:1180px;border-collapse:collapse}.stock-list-table th,.stock-list-table td{padding:15px 18px;border-bottom:1px solid #e1e2e8;text-align:left;white-space:nowrap}.stock-list-table th{font-size:12px;text-transform:uppercase;color:#6d6b7f}.stock-list-table tbody tr:last-child td{border-bottom:0}.stock-empty{text-align:center!important;color:#7b7b8d;padding:28px!important}.stock-actions{display:flex!important;align-items:center!important;gap:8px!important}.stock-delete-form{display:none!important}.stock-actions .stock-action-button{box-sizing:border-box!important;display:inline-flex!important;flex:0 0 42px!important;align-items:center!important;justify-content:center!important;width:42px!important;min-width:42px!important;max-width:42px!important;height:42px!important;min-height:42px!important;max-height:42px!important;margin:0!important;padding:0!important;border:0!important;border-radius:7px!important;color:#fff!important;line-height:1!important;text-decoration:none!important;cursor:pointer}.stock-movements{background:#f5a33b!important}.stock-edit{background:#19a94b!important}.stock-actions .stock-delete{margin-left:-6px!important;background:#e54b59!important}@media(max-width:720px){.stock-list-page{padding:20px 12px 36px!important}.stock-list-heading{align-items:flex-start;flex-direction:column}.stock-new-button{width:100%;text-align:center}}
</style>
<?php patient_footer(); ?>
