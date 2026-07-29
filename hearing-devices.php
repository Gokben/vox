<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
$isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$selectedBrand = trim((string)($_GET['brand'] ?? ''));
$selectedModel = trim((string)($_GET['model'] ?? ''));
$selectedYear = trim((string)($_GET['year'] ?? ''));
try {
    $pdo->exec($isSqlite
        ? 'CREATE TABLE IF NOT EXISTS stock_movements (id INTEGER PRIMARY KEY AUTOINCREMENT, stock_id INTEGER NOT NULL, movement_type TEXT NOT NULL, quantity INTEGER NOT NULL, movement_date TEXT NOT NULL, serial_numbers TEXT NULL, invoice_no TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
        : 'CREATE TABLE IF NOT EXISTS stock_movements (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, stock_id INT UNSIGNED NOT NULL, movement_type VARCHAR(30) NOT NULL, quantity INT NOT NULL, movement_date DATE NOT NULL, serial_numbers TEXT NULL, invoice_no VARCHAR(100) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX stock_movements_stock_id (stock_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $movementColumns = $isSqlite
        ? array_column($pdo->query('PRAGMA table_info(stock_movements)')->fetchAll(), 'name')
        : array_column($pdo->query('SHOW COLUMNS FROM stock_movements')->fetchAll(), 'Field');
    if (!in_array('current_account_id', $movementColumns, true)) {
        $pdo->exec('ALTER TABLE stock_movements ADD COLUMN current_account_id ' . ($isSqlite ? 'INTEGER NULL' : 'INT UNSIGNED NULL'));
    }
    $hearingBrandsStatement = $pdo->prepare("SELECT DISTINCT brand FROM stock_cards WHERE stock_type=? AND brand IS NOT NULL AND brand<>'' ORDER BY brand");
    $hearingBrandsStatement->execute(['İşitme Cihazı']);
    $hearingBrands = $hearingBrandsStatement->fetchAll(PDO::FETCH_COLUMN);
    if ($selectedBrand !== '' && !in_array($selectedBrand, $hearingBrands, true)) $selectedBrand = '';
    $hearingModelsSql = "SELECT DISTINCT model FROM stock_cards WHERE stock_type=? AND model IS NOT NULL AND model<>''";
    $hearingModelsParams = ['İşitme Cihazı'];
    if ($selectedBrand !== '') { $hearingModelsSql .= ' AND brand=?'; $hearingModelsParams[] = $selectedBrand; }
    $hearingModelsSql .= ' ORDER BY model';
    $hearingModelsStatement = $pdo->prepare($hearingModelsSql);
    $hearingModelsStatement->execute($hearingModelsParams);
    $hearingModels = $hearingModelsStatement->fetchAll(PDO::FETCH_COLUMN);
    $hearingYearsSql = $isSqlite ? "SELECT DISTINCT strftime('%Y',m.movement_date) AS year_value FROM stock_movements m INNER JOIN stock_cards s ON s.id=m.stock_id WHERE s.stock_type=? AND m.movement_date IS NOT NULL ORDER BY year_value DESC" : "SELECT DISTINCT YEAR(m.movement_date) AS year_value FROM stock_movements m INNER JOIN stock_cards s ON s.id=m.stock_id WHERE s.stock_type=? AND m.movement_date IS NOT NULL ORDER BY year_value DESC";
    $hearingYearsStatement = $pdo->prepare($hearingYearsSql);
    $hearingYearsStatement->execute(['İşitme Cihazı']);
    $hearingYears = array_values(array_map('strval', array_filter($hearingYearsStatement->fetchAll(PDO::FETCH_COLUMN), static fn($year): bool => preg_match('/^\d{4}$/', (string)$year) === 1)));
    if ($selectedModel !== '' && !in_array($selectedModel, $hearingModels, true)) $selectedModel = '';
    if ($selectedYear !== '' && !in_array($selectedYear, $hearingYears, true)) $selectedYear = '';
    $where = ['s.stock_type=?'];
    $params = ['İşitme Cihazı'];
    if ($selectedBrand !== '') { $where[] = 's.brand=?'; $params[] = $selectedBrand; }
    if ($selectedModel !== '') { $where[] = 's.model=?'; $params[] = $selectedModel; }
    if ($selectedYear !== '') { $where[] = $isSqlite ? "strftime('%Y',m.movement_date)=?" : 'YEAR(m.movement_date)=?'; $params[] = $selectedYear; }
    $statement = $pdo->prepare("SELECT s.id,s.stock_code,s.stock_name,s.brand,s.model,s.stock_type,m.id AS movement_id,m.current_account_id,m.movement_date,m.invoice_no,m.serial_numbers,q.stock_quantity FROM stock_cards s INNER JOIN (SELECT stock_id,SUM(CASE WHEN movement_type='Giriş' THEN quantity WHEN movement_type='Çıkış' THEN -quantity ELSE 0 END) AS stock_quantity FROM stock_movements GROUP BY stock_id) q ON q.stock_id=s.id AND q.stock_quantity>0 LEFT JOIN stock_movements m ON m.stock_id=s.id AND m.movement_type='Giriş' WHERE " . implode(' AND ', $where) . " ORDER BY s.brand,s.model,s.stock_name,m.movement_date,m.id");
    $statement->execute($params);
    $movementRows = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log('hearing-devices.php query: ' . $exception->getMessage());
    $movementRows = [];
    $hearingBrands = [];
    $hearingModels = [];
    $hearingYears = [];
}

$deviceGroups = [];
foreach ($movementRows as $movement) {
    $stockId = (int)$movement['id'];
    if (!isset($deviceGroups[$stockId])) {
        $deviceGroups[$stockId] = [
            'stock_id' => $stockId,
            'stock_type' => (string)$movement['stock_type'],
            'stock_code' => (string)$movement['stock_code'],
            'stock_name' => (string)$movement['stock_name'],
            'brand' => (string)$movement['brand'],
            'model' => (string)$movement['model'],
            'stock_quantity' => max(0, (int)$movement['stock_quantity']),
            'serials' => [],
            'entries' => [],
            'movement_date' => (string)$movement['movement_date'],
            'invoice_no' => (string)$movement['invoice_no'],
        ];
    }
    $serials = json_decode((string)($movement['serial_numbers'] ?? ''), true);
    if (!is_array($serials)) $serials = [];
    $serials = array_values(array_map(static fn($serial): string => trim((string)$serial), $serials));
    $movementId = (int)($movement['movement_id'] ?? 0);
    $deviceGroups[$stockId]['entries'][] = ['id' => $movementId, 'current_account_id' => (int)($movement['current_account_id'] ?? 0), 'serials' => $serials, 'movement_date' => (string)$movement['movement_date'], 'invoice_no' => (string)$movement['invoice_no']];
    foreach ($serials as $serialIndex => $serial) {
        $serial = trim((string)$serial);
        if ($serial !== '') $deviceGroups[$stockId]['serials'][] = ['serial_no' => $serial, 'movement_id' => $movementId, 'serial_index' => $serialIndex, 'current_account_id' => (int)($movement['current_account_id'] ?? 0), 'movement_date' => (string)$movement['movement_date'], 'invoice_no' => (string)$movement['invoice_no']];
    }
}
$devices = [];
foreach ($deviceGroups as $device) {
    for ($index = 0; $index < $device['stock_quantity']; $index++) {
        $entry = $device['entries'][0] ?? ['id' => 0, 'current_account_id' => 0, 'serials' => [], 'movement_date' => $device['movement_date'], 'invoice_no' => $device['invoice_no']];
        $serial = $device['serials'][$index] ?? ['serial_no' => '', 'movement_id' => $entry['id'], 'serial_index' => count($entry['serials']) + $index - count($device['serials']), 'current_account_id' => $entry['current_account_id'], 'movement_date' => $entry['movement_date'], 'invoice_no' => $entry['invoice_no']];
        $devices[] = [
            'stock_id' => $device['stock_id'],
            'stock_type' => $device['stock_type'],
            'stock_code' => $device['stock_code'],
            'stock_name' => $device['stock_name'],
            'brand' => $device['brand'],
            'model' => $device['model'],
            'serial_no' => $serial['serial_no'],
            'movement_id' => (int)$serial['movement_id'],
            'serial_index' => (int)$serial['serial_index'],
            'current_account_id' => (int)$serial['current_account_id'],
            'movement_date' => $serial['movement_date'],
            'invoice_no' => $serial['invoice_no'],
            'stock_quantity' => 1,
        ];
    }
}

patient_header('İşitme Cihazları', 'stock');
?>
<style>
.hearing-devices-page{max-width:1500px;margin:0 auto;padding:96px 20px 48px}.hearing-devices-card{overflow:hidden;border:1px solid var(--line);border-radius:9px;background:var(--card);box-shadow:0 .25rem 1.125rem rgba(47,43,61,.1)}.hearing-devices-head{padding:22px 24px;border-bottom:1px solid var(--line)}.hearing-devices-head h1{margin:0 0 5px;font-size:21px}.hearing-devices-head p{margin:0;color:var(--muted)}.hearing-devices-tools{display:flex;align-items:center;padding:16px 24px;border-bottom:1px solid var(--line)}.hearing-devices-tools input{width:min(420px,100%);height:39px;padding:0 12px;border:1px solid #d5d3de;border-radius:6px;background:var(--card);color:var(--text);font:inherit}.hearing-devices-scroll{overflow:auto}.hearing-devices-table{width:100%;min-width:1000px;border-collapse:collapse}.hearing-devices-table th,.hearing-devices-table td{padding:14px 18px;border-bottom:1px solid var(--line);text-align:left}.hearing-devices-table th{font-size:12px;text-transform:uppercase}.hearing-devices-table td{font-size:13px;color:var(--muted)}.serial-number{font-weight:700;color:#dc3545!important}.hearing-devices-empty{text-align:center!important;padding:38px!important}.hearing-devices-foot{padding:15px 24px;color:var(--muted)}@media(max-width:560px){.hearing-devices-page{padding:92px 14px 30px}.hearing-devices-tools input{width:100%}}
</style>
<main class="hearing-devices-page"><section class="hearing-devices-card"><header class="hearing-devices-head"><h1>İşitme Cihazları</h1><p>Stokta bulunan işitme cihazlarını seri numaralarıyla görüntüleyin.</p></header><div class="hearing-devices-tools"><input id="hearing-devices-search" type="search" placeholder="Stok kodu, cihaz adı, marka, model veya seri no ara" autocomplete="off"></div><div class="hearing-devices-scroll"><table class="hearing-devices-table"><thead><tr><th>Stok Kodu</th><th>Cihaz Adı</th><th>Marka</th><th>Model</th><th>Seri No</th><th>Giriş Tarihi</th><th>Fatura No</th><th>Stok Miktarı</th></tr></thead><tbody><?php foreach ($devices as $device): ?><tr><td><?=e($device['stock_code'])?></td><td><?=e($device['stock_name'])?></td><td><?=e($device['brand'])?></td><td><?=e($device['model'])?></td><td class="serial-number"><?=e($device['serial_no'] ?: 'Seri no girilmedi')?></td><td><?=e(format_date_tr($device['movement_date']))?></td><td><?=e($device['invoice_no'] ?: '—')?></td><td><?=e((string)$device['stock_quantity'])?></td></tr><?php endforeach; ?><?php if (!$devices): ?><tr><td class="hearing-devices-empty" colspan="8">Stokta seri numarasıyla izlenen işitme cihazı bulunamadı.</td></tr><?php endif; ?></tbody></table></div><footer class="hearing-devices-foot"><?=count($devices)?> seri numarası listeleniyor.</footer></section></main>
<style>.hearing-devices-head{position:relative;padding-right:150px}.hearing-devices-save{position:absolute;right:24px;top:50%;display:inline-flex;align-items:center;gap:7px;height:39px;padding:0 14px;transform:translateY(-50%);border:0;border-radius:6px;background:#20a447;color:#fff;font:inherit;font-weight:700;cursor:pointer}.hearing-devices-save:hover{background:#16883d}.hearing-devices-tools select{width:190px;height:39px;padding:0 11px;border:1px solid #d5d3de;border-radius:6px;background:var(--card);color:var(--text);font:inherit}.serial-number input{width:100%;min-width:145px;height:34px;padding:0 9px;border:1px solid #d5d3de;border-radius:5px;background:var(--card);color:#dc3545;font:inherit;font-weight:700}.serial-number input:focus{outline:2px solid #bfe9ca;border-color:#20a447}.serial-number input.serial-saving{opacity:.65}.serial-number input.serial-error{border-color:#dc3545;background:#fff0f0}@media(max-width:560px){.hearing-devices-head{padding-right:24px;padding-bottom:78px}.hearing-devices-save{top:auto;bottom:20px;transform:none;left:24px;right:24px;justify-content:center}.hearing-devices-tools{flex-direction:column;align-items:stretch}.hearing-devices-tools select{width:100%}}</style>
<script>
const hearingDevicesSearch = document.getElementById('hearing-devices-search');
const hearingDeviceBrands = <?=json_encode(array_values($hearingBrands), JSON_UNESCAPED_UNICODE)?>;
const hearingDeviceModels = <?=json_encode(array_values($hearingModels), JSON_UNESCAPED_UNICODE)?>;
const hearingDeviceYears = <?=json_encode(array_values($hearingYears), JSON_UNESCAPED_UNICODE)?>;
const hearingDeviceCurrentBrand = <?=json_encode($selectedBrand, JSON_UNESCAPED_UNICODE)?>;
const hearingDeviceCurrentModel = <?=json_encode($selectedModel, JSON_UNESCAPED_UNICODE)?>;
const hearingDeviceCurrentYear = <?=json_encode($selectedYear, JSON_UNESCAPED_UNICODE)?>;
if (hearingDevicesSearch) {
  const brandSelect = document.createElement('select');
  brandSelect.setAttribute('aria-label', 'İşitme cihazı markası');
  brandSelect.innerHTML = '<option value="">Marka seçiniz</option>' + hearingDeviceBrands.map(brand => '<option></option>').join('');
  [...brandSelect.options].forEach((option, index) => { if (index) { option.value = hearingDeviceBrands[index - 1]; option.textContent = hearingDeviceBrands[index - 1]; } option.selected = option.value === hearingDeviceCurrentBrand; });
  const modelSelect = document.createElement('select');
  modelSelect.setAttribute('aria-label', 'İşitme cihazı modeli');
  modelSelect.innerHTML = '<option value="">Model seçiniz</option>' + hearingDeviceModels.map(model => '<option></option>').join('');
  [...modelSelect.options].forEach((option, index) => { if (index) { option.value = hearingDeviceModels[index - 1]; option.textContent = hearingDeviceModels[index - 1]; } option.selected = option.value === hearingDeviceCurrentModel; });
  modelSelect.disabled = !brandSelect.value;
  const yearSelect = document.createElement('select');
  yearSelect.setAttribute('aria-label', 'Giriş yılı');
  yearSelect.innerHTML = '<option value="">Yıl seçiniz</option>' + hearingDeviceYears.map(year => '<option></option>').join('');
  [...yearSelect.options].forEach((option, index) => { if (index) { option.value = hearingDeviceYears[index - 1]; option.textContent = hearingDeviceYears[index - 1]; } option.selected = option.value === hearingDeviceCurrentYear; });
  const applySelection = () => {
    const url = new URL(window.location.href);
    if (brandSelect.value) url.searchParams.set('brand', brandSelect.value); else url.searchParams.delete('brand');
    if (modelSelect.value) url.searchParams.set('model', modelSelect.value); else url.searchParams.delete('model');
    if (yearSelect.value) url.searchParams.set('year', yearSelect.value); else url.searchParams.delete('year');
    url.searchParams.delete('stock_type');
    window.location.assign(url.toString());
  };
  brandSelect.addEventListener('change', () => { modelSelect.value = ''; applySelection(); });
  modelSelect.addEventListener('change', applySelection);
  yearSelect.addEventListener('change', applySelection);
  hearingDevicesSearch.before(brandSelect, modelSelect, yearSelect);
}
hearingDevicesSearch?.addEventListener('input', () => {
  const query = hearingDevicesSearch.value.trim().toLocaleLowerCase('tr-TR');
  document.querySelectorAll('.hearing-devices-table tbody tr').forEach(row => {
    if (row.querySelector('.hearing-devices-empty')) return;
    row.hidden = query !== '' && !row.textContent.toLocaleLowerCase('tr-TR').includes(query);
  });
});
</script>
<script>
const hearingDeviceRows = <?=json_encode($devices, JSON_UNESCAPED_UNICODE)?>;
const serialSaveUrl = <?=json_encode(url('hearing-device-serial-save.php'))?>;
const serialCsrf = <?=json_encode(csrf())?>;
document.querySelectorAll('.hearing-devices-table tbody tr').forEach((row, index) => {
  const device = hearingDeviceRows[index];
  const cell = row.querySelector('.serial-number');
  if (!device || !cell || !device.movement_id) return;
  row.style.cursor = 'pointer';
  row.addEventListener('dblclick', event => {
    if (event.target.closest('input,button,a,label,select')) return;
    if (device.current_account_id && device.invoice_no) window.location.href = <?=json_encode(url('current-account-movements.php?id='))?> + encodeURIComponent(device.current_account_id) + '&invoice=' + encodeURIComponent(device.invoice_no);
  });
  if (device.stock_type !== 'İşitme Cihazı') return;
  const input = document.createElement('input');
  input.type = 'text';
  input.maxLength = 190;
  input.placeholder = 'Seri no giriniz';
  input.value = device.serial_no || '';
  input.title = 'Seri numarasını düzenleyin';
  cell.textContent = '';
  cell.append(input);
  let savedValue = input.value;
  const saveSerial = async () => {
    const serialNo = input.value.trim();
    if (serialNo === savedValue) return;
    input.classList.remove('serial-error');
    input.classList.add('serial-saving');
    const body = new URLSearchParams({csrf: serialCsrf, movement_id: String(device.movement_id), serial_index: String(device.serial_index), serial_no: serialNo});
    try {
      const response = await fetch(serialSaveUrl, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body});
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Seri numarası kaydedilemedi.');
      savedValue = serialNo;
    } catch (error) {
      input.value = savedValue;
      input.classList.add('serial-error');
      alert(error.message || 'Seri numarası kaydedilemedi.');
    } finally {
      input.classList.remove('serial-saving');
    }
  };
  input.saveSerialNumber = saveSerial;
  input.addEventListener('change', saveSerial);
  input.addEventListener('blur', saveSerial);
});
const hearingDevicesHeader = document.querySelector('.hearing-devices-head');
if (hearingDevicesHeader) {
  const saveButton = document.createElement('button');
  saveButton.type = 'button';
  saveButton.className = 'hearing-devices-save';
  saveButton.innerHTML = '<i class="icon-base ti tabler-device-floppy"></i> Kaydet';
  hearingDevicesHeader.append(saveButton);
  saveButton.addEventListener('click', async () => {
    const inputs = [...document.querySelectorAll('.serial-number input')];
    saveButton.disabled = true;
    saveButton.textContent = 'Kaydediliyor…';
    await Promise.all(inputs.map(input => input.saveSerialNumber?.()));
    saveButton.innerHTML = '<i class="icon-base ti tabler-check"></i> Kaydedildi';
    setTimeout(() => { saveButton.disabled = false; saveButton.innerHTML = '<i class="icon-base ti tabler-device-floppy"></i> Kaydet'; }, 1200);
  });
}
</script>
<?php patient_footer(); ?>
