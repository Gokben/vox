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
    ? 'CREATE TABLE IF NOT EXISTS models (id INTEGER PRIMARY KEY AUTOINCREMENT, brand_id INTEGER NOT NULL, name VARCHAR(190) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE (brand_id, name COLLATE NOCASE))'
    : 'CREATE TABLE IF NOT EXISTS models (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, brand_id INT UNSIGNED NOT NULL, name VARCHAR(190) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY models_brand_name_unique (brand_id, name), CONSTRAINT models_brand_fk FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$hasBrandStockType = $sqlite ? (bool)array_filter($pdo->query('PRAGMA table_info(brands)')->fetchAll(), static fn($column) => $column['name'] === 'stock_type') : (bool)$pdo->query("SHOW COLUMNS FROM brands LIKE 'stock_type'")->fetch();
$hasModelStockType = $sqlite ? (bool)array_filter($pdo->query('PRAGMA table_info(models)')->fetchAll(), static fn($column) => $column['name'] === 'stock_type') : (bool)$pdo->query("SHOW COLUMNS FROM models LIKE 'stock_type'")->fetch();
if (!$hasBrandStockType) $pdo->exec('ALTER TABLE brands ADD COLUMN stock_type VARCHAR(50) NULL');
if (!$hasModelStockType) $pdo->exec('ALTER TABLE models ADD COLUMN stock_type VARCHAR(50) NULL');
seed_brand_models_once($pdo);
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS stock_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, stock_code TEXT NOT NULL UNIQUE, stock_name TEXT NOT NULL, brand TEXT, model TEXT, device_type TEXT, serial_no TEXT NOT NULL UNIQUE, uts_lot_no TEXT, warranty_start TEXT, warranty_end TEXT, sgk_status TEXT, min_stock INTEGER DEFAULT 0, max_stock INTEGER DEFAULT 0, purchase_price REAL DEFAULT 0, sale_price REAL DEFAULT 0, vat_rate REAL DEFAULT 20, unit_cost REAL DEFAULT 0, stock_type TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS stock_cards (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, stock_code VARCHAR(100) NOT NULL UNIQUE, stock_name VARCHAR(190) NOT NULL, brand VARCHAR(190) NULL, model VARCHAR(190) NULL, device_type VARCHAR(100) NULL, serial_no VARCHAR(190) NOT NULL UNIQUE, uts_lot_no VARCHAR(190) NULL, warranty_start DATE NULL, warranty_end DATE NULL, sgk_status VARCHAR(100) NULL, min_stock INT NOT NULL DEFAULT 0, max_stock INT NOT NULL DEFAULT 0, purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0, sale_price DECIMAL(12,2) NOT NULL DEFAULT 0, vat_rate DECIMAL(5,2) NOT NULL DEFAULT 20, unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0, stock_type VARCHAR(50) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->prepare('UPDATE stock_cards SET stock_type = ? WHERE stock_type = ?')->execute(['İşitme Cihazı', 'Kulaklık']);
$hasStockType = $sqlite ? (bool)array_filter($pdo->query('PRAGMA table_info(stock_cards)')->fetchAll(), static fn($column) => $column['name'] === 'stock_type') : (function() use ($pdo): bool {$query=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="stock_cards" AND column_name="stock_type"');$query->execute();return(bool)$query->fetchColumn();})();
if (!$hasStockType) $pdo->exec('ALTER TABLE stock_cards ADD COLUMN stock_type VARCHAR(50) NULL');
$hasImagePath = $sqlite ? (bool)array_filter($pdo->query('PRAGMA table_info(stock_cards)')->fetchAll(), static fn($column) => $column['name'] === 'image_path') : (function() use ($pdo): bool {$query=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="stock_cards" AND column_name="image_path"');$query->execute();return(bool)$query->fetchColumn();})();
if (!$hasImagePath) $pdo->exec('ALTER TABLE stock_cards ADD COLUMN image_path VARCHAR(255) NULL');
$hasPowerUsage = $sqlite ? (bool)array_filter($pdo->query('PRAGMA table_info(stock_cards)')->fetchAll(), static fn($column) => $column['name'] === 'power_usage') : (function() use ($pdo): bool {$query=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="stock_cards" AND column_name="power_usage"');$query->execute();return(bool)$query->fetchColumn();})();
if (!$hasPowerUsage) $pdo->exec('ALTER TABLE stock_cards ADD COLUMN power_usage VARCHAR(50) NULL');
$hasProductColor = $sqlite ? (bool)array_filter($pdo->query('PRAGMA table_info(stock_cards)')->fetchAll(), static fn($column) => $column['name'] === 'product_color') : (function() use ($pdo): bool {$query=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="stock_cards" AND column_name="product_color"');$query->execute();return(bool)$query->fetchColumn();})();
if (!$hasProductColor) $pdo->exec('ALTER TABLE stock_cards ADD COLUMN product_color VARCHAR(50) NULL');

$fields = ['stock_code','stock_name','brand','model','device_type','power_usage','product_color','min_stock','max_stock','stock_type'];
$brands = $pdo->query('SELECT id,name,stock_type FROM brands ORDER BY name')->fetchAll();
$models = $pdo->query('SELECT MIN(id) AS id,brand_id,name,MAX(stock_type) AS stock_type FROM models GROUP BY brand_id,name ORDER BY name')->fetchAll();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$editing = $id > 0;
$form = array_fill_keys($fields, '');
$form['vat_rate'] = '20';
$imagePath = '';
$previousImagePath = '';
$error = '';

if ($editing) {
    $statement = $pdo->prepare('SELECT * FROM stock_cards WHERE id = ?');
    $statement->execute([$id]);
    $record = $statement->fetch();
    if (!$record) { http_response_code(404); exit('Stok kartı bulunamadı.'); }
    foreach ($fields as $field) $form[$field] = (string)($record[$field] ?? '');
    $imagePath = (string)($record['image_path'] ?? '');
    $previousImagePath = $imagePath;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($fields as $field) $form[$field] = trim((string)($_POST[$field] ?? ''));
    $form['serial_no'] = $form['stock_code'];
    $form['min_stock'] = max(0, (int)$form['min_stock']);
    $form['max_stock'] = max(0, (int)$form['max_stock']);
    if ($form['stock_code'] === '' || $form['stock_name'] === '') $error = 'Stok kodu ve stok adı zorunludur.';
    elseif ($form['max_stock'] && $form['max_stock'] < $form['min_stock']) $error = 'Azami stok miktarı asgari stok miktarından düşük olamaz.';
    else {
        if (($_POST['image_action'] ?? '') === 'clear') $imagePath = '';
        if (!empty($_FILES['product_image']['tmp_name']) || (int)($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $error = 'Ürün görseli yüklenemedi.';
            elseif ((int)$_FILES['product_image']['size'] > 2097152) $error = 'Ürün görseli en fazla 2 MB olabilir.';
            else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['product_image']['tmp_name']);
                $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if (!isset($types[$mime])) $error = 'Yalnızca JPG, PNG, GIF veya WEBP görsel yükleyebilirsiniz.';
                else {
                    $directory = __DIR__ . '/assets/uploads/stocks';
                    if (!is_dir($directory)) mkdir($directory, 0775, true);
                    $filename = 'stock-' . bin2hex(random_bytes(12)) . '.' . $types[$mime];
                    if (!move_uploaded_file($_FILES['product_image']['tmp_name'], $directory . '/' . $filename)) $error = 'Ürün görseli kaydedilemedi.';
                    else $imagePath = 'assets/uploads/stocks/' . $filename;
                }
            }
        }
        if ($error !== '') { /* Görsel yükleme hatasını formda göster. */ }
        else try {
            if ($editing) {
                $assignments = implode(',', array_map(static fn($field) => $field . ' = ?', $fields));
                $values = array_map(static fn($field) => $form[$field], $fields); $values[] = $id;
                $pdo->prepare('UPDATE stock_cards SET ' . $assignments . ' WHERE id = ?')->execute($values);
                $pdo->prepare('UPDATE stock_cards SET image_path = ? WHERE id = ?')->execute([$imagePath ?: null, $id]);
            } else {
                $insertFields = [...$fields, 'serial_no', 'image_path'];
                $values = array_map(static fn($field) => $form[$field], $fields); $values[] = $form['stock_code']; $values[] = $imagePath ?: null;
                $pdo->prepare('INSERT INTO stock_cards (' . implode(',', $insertFields) . ') VALUES (' . implode(',', array_fill(0, count($insertFields), '?')) . ')')->execute($values);
            }
            if ($previousImagePath !== '' && $imagePath !== $previousImagePath && is_file(__DIR__ . '/' . $previousImagePath)) @unlink(__DIR__ . '/' . $previousImagePath);
            header('Location: ' . url('stocks.php?saved=1')); exit;
        } catch (PDOException $exception) { $error = 'Stok kodu benzersiz olmalıdır.'; }
    }
}

patient_header($editing ? 'Stok Kartı Düzenle' : 'Yeni Stok Kartı', 'stock');
?>
<main class="patient-container stock-card-page"><section class="stock-card">
  <header><div><h1><?=$editing ? 'Stok Kartı Düzenle' : 'Yeni Stok Kartı'?></h1><p>Stok, cihaz ve fiyat bilgilerini tanımlayın.</p></div><div class="stock-product-image"><?php if ($imagePath): ?><div class="stock-image-preview"><button type="button" id="stock-card-image-open" data-image-src="<?=e(url($imagePath))?>" data-image-alt="Ürün görseli"><img src="<?=e(url($imagePath))?>" alt="Ürün görseli"></button></div><?php endif ?><div class="stock-image-controls"><label class="stock-image-plus">+<span class="stock-image-add" title="Görsel ekle" aria-label="Görsel ekle"><i class="fa-solid fa-image icon-base ti tabler-photo"></i><input id="product-image-input" form="stock-card-form" type="file" name="product_image" accept="image/jpeg,image/png,image/gif,image/webp"></span></label><input id="product-image-action" form="stock-card-form" type="hidden" name="image_action" value=""></div></div></header>
  <?php if ($error): ?><p class="stock-alert error"><?=e($error)?></p><?php endif ?>
  <form id="stock-card-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=csrf()?>">
    <h2>Temel Bilgiler ve Kodlama</h2><div class="stock-grid">
      <label>Stok Kodu *<input name="stock_code" value="<?=e($form['stock_code'])?>" required></label><label>Stok Adı *<input name="stock_name" value="<?=e($form['stock_name'])?>" placeholder="Cihazın tam ticari adı" required></label>
      <label>Marka<select name="brand" id="stock-brand"><option value="">Seçiniz</option><?php foreach ($brands as $brand): ?><option value="<?=e($brand['name'])?>" <?= $form['brand'] === $brand['name'] ? 'selected' : '' ?>><?=e($brand['name'])?></option><?php endforeach ?><?php if ($form['brand'] !== '' && !in_array($form['brand'], array_column($brands, 'name'), true)): ?><option value="<?=e($form['brand'])?>" selected><?=e($form['brand'])?></option><?php endif ?></select></label>
      <label>Model<select name="model" id="stock-model"><option value="">Model seçiniz</option><?php foreach ($models as $model): ?><option value="<?=e($model['name'])?>" data-brand-id="<?=e((string)$model['brand_id'])?>" <?= $form['model'] === $model['name'] ? 'selected' : '' ?>><?=e($model['name'])?></option><?php endforeach ?><?php if ($form['model'] !== '' && !in_array($form['model'], array_column($models, 'name'), true)): ?><option value="<?=e($form['model'])?>" selected><?=e($form['model'])?></option><?php endif ?></select></label>
      <label>Cihaz Tipi<select name="device_type"><option value="">Seçiniz</option><?php foreach (['Kulak arkası (BTE)','Kanal içi (CIC)','Kanal içi (ITC)','Kanal İçi Alıcı RIC/RIE'] as $type): ?><option <?=$form['device_type'] === $type ? 'selected' : ''?>><?=e($type)?></option><?php endforeach ?></select></label><label>Güç Kullanımı<select name="power_usage"><option value="">Seçiniz</option><?php foreach (['Pilli','Şarjlı'] as $powerUsage): ?><option <?=$form['power_usage'] === $powerUsage ? 'selected' : ''?>><?=e($powerUsage)?></option><?php endforeach ?></select></label><label>Ürün Rengi<select name="product_color"><option value="">Seçiniz</option><?php foreach (['Bej','Siyah','Şampanya'] as $productColor): ?><option <?=$form['product_color'] === $productColor ? 'selected' : ''?>><?=e($productColor)?></option><?php endforeach ?></select></label>
    </div>
    <h2>Finansal ve Depo Bilgileri</h2><div class="stock-grid">
      <label>Kritik / Asgari Stok<input type="number" min="0" name="min_stock" value="<?=e((string)$form['min_stock'])?>"></label><label>Azami Stok<input type="number" min="0" name="max_stock" value="<?=e((string)$form['max_stock'])?>"></label>
      <label>Stok Tipi<select name="stock_type"><option value="">Seçiniz</option><?php foreach(['İşitme Cihazı','Sarf Malzeme','Pil'] as $stockType):?><option <?=$form['stock_type']===$stockType?'selected':''?>><?=e($stockType)?></option><?php endforeach?></select></label>
    </div>
    <footer><a href="<?=e(url('stocks.php'))?>">İptal</a><button class="button"><?=$editing ? 'Güncelle' : 'Kaydet'?></button></footer>
  </form>
</section></main>
<div id="stock-card-image-modal" class="stock-card-image-modal" hidden role="dialog" aria-modal="true" aria-label="Ürün görseli"><button type="button" class="stock-card-image-modal-close" aria-label="Kapat">×</button><div class="stock-card-image-modal-content"><img src="" alt=""></div></div>
<script>
(() => { const brand = document.getElementById('stock-brand'), model = document.getElementById('stock-model'); if (!brand || !model) return; const filterModels = () => { const selected = brand.options[brand.selectedIndex]; const brandId = selected?.dataset.id || <?= json_encode((string)($brands[array_search($form['brand'], array_column($brands, 'name'), true)]['id'] ?? '')) ?>; [...model.options].forEach(option => { if (!option.dataset.brandId) return; option.hidden = !!brandId && option.dataset.brandId !== brandId; }); if (model.selectedOptions[0]?.hidden) model.value = ''; }; [...brand.options].forEach(option => { const entry = <?= json_encode($brands) ?>.find(item => item.name === option.value); if (entry) option.dataset.id = entry.id; }); brand.addEventListener('change', () => { model.value = ''; filterModels(); }); filterModels(); })();
</script>
<script>(()=>{const input=document.getElementById('product-image-input'),action=document.getElementById('product-image-action');if(input&&action){input.addEventListener('click',()=>{action.value='clear'});input.addEventListener('change',()=>{if(input.files.length)action.value='upload'})}const open=document.getElementById('stock-card-image-open'),modal=document.getElementById('stock-card-image-modal'),image=modal?.querySelector('img'),close=modal?.querySelector('button');if(!open||!modal||!image||!close)return;const hide=()=>{modal.hidden=true;image.src=''};open.addEventListener('click',()=>{image.src=open.dataset.imageSrc||'';image.alt=open.dataset.imageAlt||'';modal.hidden=false});close.addEventListener('click',hide);modal.addEventListener('click',event=>{if(event.target===modal)hide()});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)hide()})})();</script>
<script>(()=>{const stockType=document.querySelector('select[name="stock_type"]')?.closest('label'),stockCode=document.querySelector('input[name="stock_code"]')?.closest('label'),grid=stockCode?.parentElement;if(stockType&&stockCode&&grid)grid.insertBefore(stockType,stockCode)})();</script>
<script>(()=>{const type=document.querySelector('select[name="stock_type"]'),brand=document.getElementById('stock-brand'),model=document.getElementById('stock-model'),brands=<?=json_encode($brands,JSON_UNESCAPED_UNICODE)?>,models=<?=json_encode($models,JSON_UNESCAPED_UNICODE)?>;if(!type||!brand||!model)return;const typeMatches=(types,selected)=>{const values=String(types||'').split(',').map(value=>value.trim()).filter(Boolean);return selected!==''&&(values.length?values.includes(selected):selected==='İşitme Cihazı')};const update=reset=>{const selectedType=type.value,selectedBrand=brand.value;if(reset){brand.value='';model.value=''}brand.disabled=!selectedType;model.disabled=!selectedType||!brand.value;[...brand.options].forEach(option=>{if(!option.value)return;const entry=brands.find(item=>item.name===option.value);option.hidden=!entry||!typeMatches(entry.stock_type,selectedType)});if(brand.selectedOptions[0]?.hidden)brand.value='';[...model.options].forEach(option=>{if(!option.value)return;const entry=models.find(item=>item.name===option.value&&String(item.brand_id)===String(brand.selectedOptions[0]?.dataset.id||''));const brandEntry=brands.find(item=>String(item.id)===String(entry?.brand_id||''));option.hidden=!entry||!brand.value||!typeMatches(entry.stock_type||brandEntry?.stock_type,selectedType)});if(model.selectedOptions[0]?.hidden)model.value='';model.disabled=!selectedType||!brand.value};[...brand.options].forEach(option=>{const entry=brands.find(item=>item.name===option.value);if(entry)option.dataset.id=entry.id});type.addEventListener('change',()=>update(true));brand.addEventListener('change',()=>update(true));update(false)})();</script>
<script>(()=>{const type=document.querySelector('select[name="stock_type"]'),brand=document.getElementById('stock-brand'),model=document.getElementById('stock-model'),brands=<?=json_encode($brands,JSON_UNESCAPED_UNICODE)?>,models=<?=json_encode($models,JSON_UNESCAPED_UNICODE)?>;if(!type||!brand||!model)return;const match=(types,selected)=>{const values=String(types||'').split(',').map(value=>value.trim()).filter(Boolean);return values.length?values.includes(selected):selected==='İşitme Cihazı'};brand.addEventListener('change',event=>{event.stopImmediatePropagation();model.value='';const brandId=brand.selectedOptions[0]?.dataset.id||'';[...model.options].forEach(option=>{if(!option.value)return;const entry=models.find(item=>item.name===option.value&&String(item.brand_id)===String(brandId));const brandEntry=brands.find(item=>String(item.id)===String(brandId));option.hidden=!entry||!match(entry.stock_type||brandEntry?.stock_type,type.value)});model.disabled=!brand.value},true)})();</script>
<script>(()=>{const type=document.querySelector('select[name="stock_type"]'),brand=document.getElementById('stock-brand'),model=document.getElementById('stock-model'),brands=<?=json_encode($brands,JSON_UNESCAPED_UNICODE)?>,models=<?=json_encode($models,JSON_UNESCAPED_UNICODE)?>;if(!type||!brand||!model)return;const matches=(types,selected)=>{const values=String(types||'').split(',').map(value=>value.trim()).filter(Boolean);return values.length?values.includes(selected):selected==='İşitme Cihazı'};const rebuildModels=()=>{const brandId=brand.selectedOptions[0]?.dataset.id||'',selected=model.value;model.replaceChildren(new Option('Model seçiniz',''));models.filter(item=>String(item.brand_id)===String(brandId)&&matches(item.stock_type||(brands.find(entry=>String(entry.id)===String(brandId))?.stock_type||''),type.value)).forEach(item=>model.add(new Option(item.name,item.name)));if([...model.options].some(option=>option.value===selected))model.value=selected;model.disabled=!brandId};document.addEventListener('change',event=>{if(event.target===brand){event.stopImmediatePropagation();rebuildModels()}},true);type.addEventListener('change',()=>setTimeout(rebuildModels,0));rebuildModels()})();</script>
<script>(()=>{const type=document.querySelector('select[name="stock_type"]'),fields=['device_type','power_usage','product_color'].map(name=>document.querySelector(`[name="${name}"]`)).filter(Boolean);if(!type)return;const toggle=()=>{const isBattery=type.value==='Pil';fields.forEach(field=>{field.closest('label').hidden=isBattery;if(isBattery)field.value=''})};type.addEventListener('change',toggle);toggle()})();</script>
<script>(()=>{const type=document.querySelector('select[name="stock_type"]'),materialFields=['brand','model','device_type','power_usage','product_color'].map(name=>document.querySelector(`[name="${name}"]`)).filter(Boolean);if(!type)return;const toggle=()=>{const isMaterial=type.value==='Sarf Malzeme';materialFields.forEach(field=>{const hide=isMaterial;field.closest('label').hidden=hide;field.disabled=hide;if(hide)field.value=''})};type.addEventListener('change',toggle);toggle()})();</script>
<script>(()=>{const type=document.querySelector('select[name="stock_type"]'),fields=['brand','model','device_type','power_usage','product_color'].map(name=>document.querySelector(`[name="${name}"]`)).filter(Boolean);if(!type)return;const toggle=()=>{const isMaterial=type.value==='Sarf Malzeme',isBattery=type.value==='Pil';fields.forEach(field=>{const deviceField=['device_type','power_usage','product_color'].includes(field.name),hide=isMaterial||(isBattery&&deviceField);field.closest('label').hidden=hide;field.disabled=isMaterial;if(hide)field.value=''})};type.addEventListener('change',toggle);toggle()})();</script>
<style>.stock-card-page{width:100%!important;max-width:1100px!important;min-height:100vh;margin:0 auto!important;padding:46px 20px 48px!important}.stock-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.stock-card>header{padding:22px 24px;border-bottom:1px solid #e1e2e8}.stock-card h1{margin:0 0 5px;font-size:21px;color:#2f2b3d}.stock-card>header p{margin:0;color:#7b7b8d}.stock-card form{padding:8px 24px 24px}.stock-card form h2{margin:20px 0 14px;padding-bottom:9px;border-bottom:1px solid #e1e2e8;color:#19a94b;font-size:14px}.stock-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}.stock-grid label{display:flex;flex-direction:column;gap:7px;font-size:14px;color:#2f2b3d}.stock-grid label[hidden]{display:none!important}.stock-grid input,.stock-grid select{height:42px;box-sizing:border-box;border:1px solid #d5d3de;border-radius:6px;padding:0 12px;background:#fff;font:inherit}.stock-grid input[type=file]{padding:9px 12px}.stock-product-image{grid-column:1/-1;display:flex;align-items:center;gap:20px;padding:14px;border:1px solid #e1e2e8;border-radius:7px;background:#fafafa}.stock-product-image>label{flex:1}.stock-product-image small{color:#7b7b8d}.stock-image-preview{display:flex;align-items:center;gap:12px}.stock-image-preview img{width:74px;height:74px;object-fit:cover;border:1px solid #d5d3de;border-radius:7px}.stock-image-preview .stock-image-remove{display:flex;flex-direction:row;align-items:center;gap:7px;white-space:nowrap;color:#d63f4d}.stock-image-remove input{width:16px;height:16px;padding:0}@media(max-width:720px){.stock-card-page{max-width:none!important;padding:92px 14px 30px!important}.stock-grid{grid-template-columns:1fr}.stock-product-image{align-items:stretch;flex-direction:column}.stock-product-image>label{width:100%}}.stock-card footer{display:flex;align-items:center;justify-content:flex-end;gap:14px;margin-top:24px}.stock-card footer a{color:#7b7b8d;text-decoration:none}.stock-alert{margin:16px 24px;padding:12px 14px;border-radius:7px}.stock-alert.error{background:#ffe3e3;color:#a21d1d}</style>
<style>.stock-card>header{display:flex;align-items:center;justify-content:space-between;gap:24px}.stock-card>header .stock-product-image{display:flex;align-items:center;gap:12px;padding:0;border:0;background:transparent}.stock-card>header .stock-image-controls{flex:none;min-width:92px}.stock-card>header .stock-image-preview button{display:block;padding:0;border:0;background:transparent;cursor:zoom-in}.stock-card>header .stock-image-preview img{display:block;width:64px;height:64px}.stock-image-controls{display:flex;flex:1;flex-direction:column;gap:10px}.stock-image-add{display:inline-flex;align-items:center;justify-content:center;width:42px;min-height:42px;border-radius:6px;background:#19a94b;color:#fff;font-size:19px;cursor:pointer}.stock-image-add input{position:absolute;width:1px!important;height:1px!important;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}.stock-image-remove{display:flex!important;flex-direction:row!important;align-items:center;gap:7px!important;white-space:nowrap;color:#d63f4d!important}.stock-image-remove input{width:16px!important;height:16px!important;padding:0!important}.stock-card-image-modal{position:fixed;z-index:1000;inset:0;display:grid;place-items:center;padding:32px;background:#1f1d2dcc}.stock-card-image-modal[hidden]{display:none}.stock-card-image-modal-content{max-width:100%;max-height:100%;overflow:auto;background:#fff}.stock-card-image-modal-content img{display:block;width:auto;height:auto;max-width:none;max-height:none}.stock-card-image-modal-close{position:fixed;top:18px;right:22px;width:42px;height:42px;border:0;border-radius:50%;background:#fff;color:#2f2b3d;font-size:30px;line-height:1;cursor:pointer}@media(max-width:720px){.stock-card>header{align-items:flex-start;flex-direction:column}.stock-image-controls{width:100%}.stock-card-image-modal{padding:18px}}</style>
<?php patient_footer(); ?>
