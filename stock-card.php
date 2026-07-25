<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/brand-model-bootstrap.php';
require __DIR__ . '/patient-layout.php';

$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS brands (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(190) NOT NULL UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS brands (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS models (id INTEGER PRIMARY KEY AUTOINCREMENT, brand_id INTEGER NOT NULL, name VARCHAR(190) NOT NULL UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS models (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, brand_id INT UNSIGNED NOT NULL, name VARCHAR(190) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY models_name_unique (name), CONSTRAINT models_brand_fk FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
seed_brand_models_once($pdo);
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS stock_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, stock_code TEXT NOT NULL UNIQUE, stock_name TEXT NOT NULL, brand TEXT, model TEXT, device_type TEXT, serial_no TEXT NOT NULL UNIQUE, uts_lot_no TEXT, warranty_start TEXT, warranty_end TEXT, sgk_status TEXT, min_stock INTEGER DEFAULT 0, max_stock INTEGER DEFAULT 0, purchase_price REAL DEFAULT 0, sale_price REAL DEFAULT 0, vat_rate REAL DEFAULT 20, unit_cost REAL DEFAULT 0, stock_type TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS stock_cards (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, stock_code VARCHAR(100) NOT NULL UNIQUE, stock_name VARCHAR(190) NOT NULL, brand VARCHAR(190) NULL, model VARCHAR(190) NULL, device_type VARCHAR(100) NULL, serial_no VARCHAR(190) NOT NULL UNIQUE, uts_lot_no VARCHAR(190) NULL, warranty_start DATE NULL, warranty_end DATE NULL, sgk_status VARCHAR(100) NULL, min_stock INT NOT NULL DEFAULT 0, max_stock INT NOT NULL DEFAULT 0, purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0, sale_price DECIMAL(12,2) NOT NULL DEFAULT 0, vat_rate DECIMAL(5,2) NOT NULL DEFAULT 20, unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0, stock_type VARCHAR(50) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$hasStockType = $sqlite ? (bool)array_filter($pdo->query('PRAGMA table_info(stock_cards)')->fetchAll(), static fn($column) => $column['name'] === 'stock_type') : (function() use ($pdo): bool {$query=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="stock_cards" AND column_name="stock_type"');$query->execute();return(bool)$query->fetchColumn();})();
if (!$hasStockType) $pdo->exec('ALTER TABLE stock_cards ADD COLUMN stock_type VARCHAR(50) NULL');

$fields = ['stock_code','stock_name','brand','model','device_type','sgk_status','serial_no','uts_lot_no','warranty_start','warranty_end','min_stock','max_stock','purchase_price','sale_price','vat_rate','unit_cost','stock_type'];
$brands = $pdo->query('SELECT id,name FROM brands ORDER BY name')->fetchAll();
$models = $pdo->query('SELECT id,brand_id,name FROM models ORDER BY name')->fetchAll();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$editing = $id > 0;
$form = array_fill_keys($fields, '');
$form['vat_rate'] = '20';
$error = '';

if ($editing) {
    $statement = $pdo->prepare('SELECT * FROM stock_cards WHERE id = ?');
    $statement->execute([$id]);
    $record = $statement->fetch();
    if (!$record) { http_response_code(404); exit('Stok kartı bulunamadı.'); }
    foreach ($fields as $field) $form[$field] = (string)($record[$field] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($fields as $field) $form[$field] = trim((string)($_POST[$field] ?? ''));
    $form['min_stock'] = max(0, (int)$form['min_stock']);
    $form['max_stock'] = max(0, (int)$form['max_stock']);
    foreach (['purchase_price','sale_price','vat_rate','unit_cost'] as $field) $form[$field] = (float)str_replace(',', '.', $form[$field]);
    if ($form['stock_code'] === '' || $form['stock_name'] === '' || $form['serial_no'] === '') $error = 'Stok kodu, stok adı ve seri numarası zorunludur.';
    elseif ($form['max_stock'] && $form['max_stock'] < $form['min_stock']) $error = 'Azami stok miktarı asgari stok miktarından düşük olamaz.';
    else {
        try {
            if ($editing) {
                $assignments = implode(',', array_map(static fn($field) => $field . ' = ?', $fields));
                $values = array_values($form); $values[] = $id;
                $pdo->prepare('UPDATE stock_cards SET ' . $assignments . ' WHERE id = ?')->execute($values);
            } else {
                $pdo->prepare('INSERT INTO stock_cards (' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')')->execute(array_values($form));
            }
            header('Location: ' . url('stocks.php?saved=1')); exit;
        } catch (PDOException $exception) { $error = 'Stok kodu ve seri numarası benzersiz olmalıdır.'; }
    }
}

patient_header($editing ? 'Stok Kartı Düzenle' : 'Yeni Stok Kartı', 'stock');
?>
<main class="patient-container stock-card-page"><section class="stock-card">
  <header><h1><?=$editing ? 'Stok Kartı Düzenle' : 'Yeni Stok Kartı'?></h1><p>İşitme cihazı için stok, izlenebilirlik, garanti ve fiyat bilgilerini tanımlayın.</p></header>
  <?php if ($error): ?><p class="stock-alert error"><?=e($error)?></p><?php endif ?>
  <form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>">
    <h2>Temel Bilgiler ve Kodlama</h2><div class="stock-grid">
      <label>Stok Kodu *<input name="stock_code" value="<?=e($form['stock_code'])?>" required></label><label>Stok Adı *<input name="stock_name" value="<?=e($form['stock_name'])?>" placeholder="Cihazın tam ticari adı" required></label>
      <label>Marka<select name="brand" id="stock-brand"><option value="">Seçiniz</option><?php foreach ($brands as $brand): ?><option value="<?=e($brand['name'])?>" <?= $form['brand'] === $brand['name'] ? 'selected' : '' ?>><?=e($brand['name'])?></option><?php endforeach ?><?php if ($form['brand'] !== '' && !in_array($form['brand'], array_column($brands, 'name'), true)): ?><option value="<?=e($form['brand'])?>" selected><?=e($form['brand'])?></option><?php endif ?></select></label>
      <label>Model<select name="model" id="stock-model"><option value="">Önce marka seçiniz</option><?php foreach ($models as $model): ?><option value="<?=e($model['name'])?>" data-brand-id="<?=e((string)$model['brand_id'])?>" <?= $form['model'] === $model['name'] ? 'selected' : '' ?>><?=e($model['name'])?></option><?php endforeach ?><?php if ($form['model'] !== '' && !in_array($form['model'], array_column($models, 'name'), true)): ?><option value="<?=e($form['model'])?>" selected><?=e($form['model'])?></option><?php endif ?></select></label>
      <label>Cihaz Tipi<select name="device_type"><option value="">Seçiniz</option><?php foreach (['Kulak arkası (BTE)','Kanal içi (CIC)','Kanal içi (ITC)','RIC'] as $type): ?><option <?=$form['device_type'] === $type ? 'selected' : ''?>><?=e($type)?></option><?php endforeach ?></select></label><label>SGK Durumu<select name="sgk_status"><option value="">Seçiniz</option><?php foreach (['SGK kapsamı dahilinde','SGK kapsamı dışında','SGK takibi gerekli'] as $status): ?><option <?=$form['sgk_status'] === $status ? 'selected' : ''?>><?=e($status)?></option><?php endforeach ?></select></label>
    </div>
    <h2>Takip ve İzlenebilirlik Bilgileri</h2><div class="stock-grid">
      <label>Seri Numarası (S/N) *<input name="serial_no" value="<?=e($form['serial_no'])?>" required></label><label>ÜTS / Lot No<input name="uts_lot_no" value="<?=e($form['uts_lot_no'])?>"></label>
      <label>Garanti Başlangıç Tarihi<input type="date" name="warranty_start" value="<?=e($form['warranty_start'])?>"></label><label>Garanti Bitiş Tarihi<input type="date" name="warranty_end" value="<?=e($form['warranty_end'])?>"></label>
    </div>
    <h2>Finansal ve Depo Bilgileri</h2><div class="stock-grid">
      <label>Kritik / Asgari Stok<input type="number" min="0" name="min_stock" value="<?=e((string)$form['min_stock'])?>"></label><label>Azami Stok<input type="number" min="0" name="max_stock" value="<?=e((string)$form['max_stock'])?>"></label>
      <label>Alış Fiyatı<input type="number" min="0" step="0.01" name="purchase_price" value="<?=e((string)$form['purchase_price'])?>"></label><label>Satış Fiyatı<input type="number" min="0" step="0.01" name="sale_price" value="<?=e((string)$form['sale_price'])?>"></label>
      <label>KDV Oranı (%)<input type="number" min="0" step="0.01" name="vat_rate" value="<?=e((string)$form['vat_rate'])?>"></label><label>Birim Maliyet<input type="number" min="0" step="0.01" name="unit_cost" value="<?=e((string)$form['unit_cost'])?>"></label><label>Stok Tipi<select name="stock_type"><option value="">Seçiniz</option><?php foreach(['Kulaklık','Sarf Malzeme'] as $stockType):?><option <?=$form['stock_type']===$stockType?'selected':''?>><?=e($stockType)?></option><?php endforeach?></select></label>
    </div>
    <footer><a href="<?=e(url('stocks.php'))?>">İptal</a><button class="button"><?=$editing ? 'Güncelle' : 'Kaydet'?></button></footer>
  </form>
</section></main>
<script>
(() => { const brand = document.getElementById('stock-brand'), model = document.getElementById('stock-model'); if (!brand || !model) return; const filterModels = () => { const selected = brand.options[brand.selectedIndex]; const brandId = selected?.dataset.id || <?= json_encode((string)($brands[array_search($form['brand'], array_column($brands, 'name'), true)]['id'] ?? '')) ?>; [...model.options].forEach(option => { if (!option.dataset.brandId) return; option.hidden = !!brandId && option.dataset.brandId !== brandId; }); if (model.selectedOptions[0]?.hidden) model.value = ''; }; [...brand.options].forEach(option => { const entry = <?= json_encode($brands) ?>.find(item => item.name === option.value); if (entry) option.dataset.id = entry.id; }); brand.addEventListener('change', () => { model.value = ''; filterModels(); }); filterModels(); })();
</script>
<style>.stock-card-page{width:100%!important;max-width:1100px!important;min-height:100vh;margin:0 auto!important;padding:46px 20px 48px!important}.stock-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.stock-card>header{padding:22px 24px;border-bottom:1px solid #e1e2e8}.stock-card h1{margin:0 0 5px;font-size:21px;color:#2f2b3d}.stock-card>header p{margin:0;color:#7b7b8d}.stock-card form{padding:8px 24px 24px}.stock-card form h2{margin:20px 0 14px;padding-bottom:9px;border-bottom:1px solid #e1e2e8;color:#19a94b;font-size:14px}.stock-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}.stock-grid label{display:flex;flex-direction:column;gap:7px;font-size:14px;color:#2f2b3d}.stock-grid input,.stock-grid select{height:42px;box-sizing:border-box;border:1px solid #d5d3de;border-radius:6px;padding:0 12px;background:#fff;font:inherit}.stock-card footer{display:flex;align-items:center;justify-content:flex-end;gap:14px;margin-top:24px}.stock-card footer a{color:#7b7b8d;text-decoration:none}.stock-alert{margin:16px 24px;padding:12px 14px;border-radius:7px}.stock-alert.error{background:#ffe3e3;color:#a21d1d}@media(max-width:720px){.stock-card-page{max-width:none!important;padding:92px 14px 30px!important}.stock-grid{grid-template-columns:1fr}}</style>
<?php patient_footer(); ?>
