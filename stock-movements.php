<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$stockId = filter_input(INPUT_GET, 'stock_id', FILTER_VALIDATE_INT) ?: 0;
$stock = null;
if ($stockId > 0) {
    $statement = db()->prepare('SELECT * FROM stock_cards WHERE id = ?');
    $statement->execute([$stockId]);
    $stock = $statement->fetch();
}
if (!$stock) { http_response_code(404); exit('Stok kartı bulunamadı.'); }
$movementTable = $pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$pdo->exec($sqlite ? 'CREATE TABLE IF NOT EXISTS stock_movements (id INTEGER PRIMARY KEY AUTOINCREMENT, stock_id INTEGER NOT NULL, movement_type TEXT NOT NULL, quantity INTEGER NOT NULL, movement_date TEXT NOT NULL, description TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)' : 'CREATE TABLE IF NOT EXISTS stock_movements (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, stock_id INT UNSIGNED NOT NULL, movement_type VARCHAR(30) NOT NULL, quantity INT NOT NULL, movement_date DATE NOT NULL, description TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX stock_movements_stock_id (stock_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$movementStatement = $pdo->prepare('SELECT * FROM stock_movements WHERE stock_id = ? ORDER BY movement_date DESC, id DESC');
$movementStatement->execute([$stockId]);
$movements = $movementStatement->fetchAll();

patient_header('Stok Hareketleri', 'stock');
?>
<main class="patient-container stock-movements-page"><section class="vuexy-form-card stock-movements-card">
  <header class="form-card-title"><h1>Stok Hareketleri</h1><p><?=e($stock['stock_code'])?> — <?=e($stock['stock_name'])?></p></header>
  <?php if (!$movements): ?><div class="stock-movements-empty"><i class="icon-base ti tabler-history"></i><p>Bu stok kartına ait henüz hareket bulunmuyor.</p><a class="button" href="<?=e(url('stocks.php'))?>">Stok Listesine Dön</a></div><?php else: ?><table class="stock-movements-table"><thead><tr><th>Tarih</th><th>Hareket</th><th>Miktar</th><th>Açıklama</th></tr></thead><tbody><?php foreach ($movements as $movement): ?><tr><td><?=e(format_date_tr($movement['movement_date']))?></td><td><?=e($movement['movement_type'])?></td><td><?=e((string)$movement['quantity'])?></td><td><?=e($movement['description'] ?: '—')?></td></tr><?php endforeach ?></tbody></table><?php endif ?>
</section></main>
<style>.stock-movements-page{max-width:1180px!important;margin:0 auto!important;padding:28px 20px 48px!important}.stock-movements-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.stock-movements-card .form-card-title{padding:22px 24px;border-bottom:1px solid #e1e2e8}.stock-movements-card h1{margin:0 0 5px;font-size:21px}.stock-movements-card p{margin:0;color:#7b7b8d}.stock-movements-empty{padding:54px 24px;text-align:center;color:#7b7b8d}.stock-movements-empty i{font-size:32px;color:#19a94b}.stock-movements-empty p{margin:12px 0 20px}.stock-movements-table{width:100%;border-collapse:collapse}.stock-movements-table th,.stock-movements-table td{padding:15px 20px;text-align:left;border-bottom:1px solid #e1e2e8}.stock-movements-table th{font-size:12px;text-transform:uppercase;color:#6d6b7f}@media(max-width:720px){.stock-movements-page{padding:20px 12px 36px!important}}</style>
<?php patient_footer(); ?>
