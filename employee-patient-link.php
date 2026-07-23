<?php
declare(strict_types=1);

function ensure_employee_active_schema(): void
{
    static $ready = false;
    if ($ready) return;
    $ready = true;
    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS employees(id INTEGER PRIMARY KEY AUTOINCREMENT,full_name TEXT NOT NULL,email TEXT NOT NULL UNIQUE,start_date DATE NOT NULL,end_date DATE NULL,job_title TEXT NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $columns = array_column($pdo->query('PRAGMA table_info(employees)')->fetchAll(), 'name');
        if (!in_array('active', $columns, true)) $pdo->exec('ALTER TABLE employees ADD COLUMN active INTEGER NOT NULL DEFAULT 1');
    } else {
        $pdo->exec('CREATE TABLE IF NOT EXISTS employees(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,full_name VARCHAR(190) NOT NULL,email VARCHAR(190) NOT NULL UNIQUE,start_date DATE NOT NULL,end_date DATE NULL,job_title VARCHAR(190) NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!$pdo->query("SHOW COLUMNS FROM employees LIKE 'active'")->fetch()) $pdo->exec('ALTER TABLE employees ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1');
    }
}

/**
 * Mevcut hasta tablosundaki personel sütunlarını Çalışanlar tablosundaki
 * Ad Soyad kayıtlarıyla eşleştirir. Eski hasta verileri değiştirilmez.
 */
function patient_staff_names(bool $includeInactive = false): array
{
    ensure_employee_active_schema();
    $names = [
        'staff_cansu' => 'Cansu',
        'staff_busra' => 'Büşra',
        'staff_belma' => 'Belma Baysan',
        'staff_yeliz' => 'Yeliz',
        'staff_gunes' => 'Güneş',
        'staff_erva' => 'Erva',
        'staff_merve' => 'Merve',
        'staff_seyma' => 'Şeyma',
    ];

    try {
        $employees = db()->query('SELECT full_name,active FROM employees ORDER BY id')->fetchAll();
        foreach ($employees as $employee) {
            $fullName = trim((string)$employee['full_name']);
            $normalized = strtr(mb_strtolower($fullName, 'UTF-8'), ['ç'=>'c','ğ'=>'g','ı'=>'i','ö'=>'o','ş'=>'s','ü'=>'u']);
            $column = $normalized === 'cansu' ? 'staff_cansu' : ($normalized === 'busra' ? 'staff_busra' : (($normalized === 'belma baysan' || str_starts_with($normalized, 'belma ')) ? 'staff_belma' : (str_starts_with($normalized, 'yeliz') ? 'staff_yeliz' : (str_starts_with($normalized, 'gunes') ? 'staff_gunes' : (str_starts_with($normalized, 'erva') ? 'staff_erva' : (str_starts_with($normalized, 'merve') ? 'staff_merve' : (str_starts_with($normalized, 'seyma') ? 'staff_seyma' : null)))))));
            if ($column === null) continue;
            if (!empty($employee['active']) || $includeInactive) $names[$column] = $fullName;
            else unset($names[$column]);
        }
    } catch (Throwable $e) {
        // Çalışanlar tablosu henüz oluşmadıysa mevcut etiketler kullanılmaya devam eder.
    }

    return $names;
}

function ensure_patient_staff_yeliz_schema(): void
{
    static $ready = false;
    if ($ready) return;
    $ready = true;
    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $columns = array_column($pdo->query('PRAGMA table_info(patients)')->fetchAll(), 'name');
        if (!in_array('staff_yeliz', $columns, true)) $pdo->exec('ALTER TABLE patients ADD COLUMN staff_yeliz INTEGER NOT NULL DEFAULT 0');
        if (!in_array('staff_gunes', $columns, true)) $pdo->exec('ALTER TABLE patients ADD COLUMN staff_gunes INTEGER NOT NULL DEFAULT 0');
        if (!in_array('staff_erva', $columns, true)) $pdo->exec('ALTER TABLE patients ADD COLUMN staff_erva INTEGER NOT NULL DEFAULT 0');
        if (!in_array('staff_merve', $columns, true)) $pdo->exec('ALTER TABLE patients ADD COLUMN staff_merve INTEGER NOT NULL DEFAULT 0');
        if (!in_array('staff_seyma', $columns, true)) $pdo->exec('ALTER TABLE patients ADD COLUMN staff_seyma INTEGER NOT NULL DEFAULT 0');
    } elseif (!$pdo->query("SHOW COLUMNS FROM patients LIKE 'staff_yeliz'")->fetch()) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN staff_yeliz TINYINT(1) NOT NULL DEFAULT 0');
    }
    if ($driver !== 'sqlite' && !$pdo->query("SHOW COLUMNS FROM patients LIKE 'staff_gunes'")->fetch()) $pdo->exec('ALTER TABLE patients ADD COLUMN staff_gunes TINYINT(1) NOT NULL DEFAULT 0');
    if ($driver !== 'sqlite' && !$pdo->query("SHOW COLUMNS FROM patients LIKE 'staff_erva'")->fetch()) $pdo->exec('ALTER TABLE patients ADD COLUMN staff_erva TINYINT(1) NOT NULL DEFAULT 0');
    if ($driver !== 'sqlite' && !$pdo->query("SHOW COLUMNS FROM patients LIKE 'staff_merve'")->fetch()) $pdo->exec('ALTER TABLE patients ADD COLUMN staff_merve TINYINT(1) NOT NULL DEFAULT 0');
    if ($driver !== 'sqlite' && !$pdo->query("SHOW COLUMNS FROM patients LIKE 'staff_seyma'")->fetch()) $pdo->exec('ALTER TABLE patients ADD COLUMN staff_seyma TINYINT(1) NOT NULL DEFAULT 0');
}

function patient_staff_list(array $patient, array $staffNames): string
{
    $selected = [];
    foreach ($staffNames as $column => $fullName) {
        if (!empty($patient[$column])) $selected[] = $fullName;
    }
    return implode(', ', $selected);
}

function start_patient_staff_ui_link(array $staffNames, array $selectedStaff = [], array $staffOrders = []): void
{
    $json = json_encode($staffNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    $ordersJson = json_encode($staffOrders, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $selectedJson = json_encode($selectedStaff, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    ob_start(static function (string $html) use ($json, $selectedJson, $ordersJson): string {
        $script = <<<'HTML'
<script>
(()=>{
 const names=__STAFF_NAMES__;
 const selectedStaff=__SELECTED_STAFF__;
 const staffOrders=__STAFF_ORDERS__;
 document.querySelectorAll('.check-row input[name^="staff_"]').forEach(input=>{
   const label=input.closest('label');
   if(label&&!names[input.name]) label.remove();
   else if(label) label.lastChild.textContent=' '+names[input.name];
 });
 const staffRow=Array.from(document.querySelectorAll('.check-row')).find(row=>row.querySelector('input[name^="staff_"]'));
 if(staffRow){
   ['staff_yeliz','staff_gunes','staff_erva','staff_merve','staff_seyma'].forEach(column=>{
     if(!names[column]||staffRow.querySelector(`input[name="${column}"]`))return;
     const label=document.createElement('label');
     label.innerHTML=`<input type="checkbox" name="${column}" value="1"> `;
     const input=label.querySelector('input');
     input.checked=!!selectedStaff[column];
     label.append(document.createTextNode(names[column]));
     staffRow.append(label);
   });
 }
 const oldNames={Cansu:'staff_cansu','Büşra':'staff_busra','Belma Baysan':'staff_belma',Yeliz:'staff_yeliz',Güneş:'staff_gunes',Erva:'staff_erva',Merve:'staff_merve','Şeyma':'staff_seyma'};
 document.querySelectorAll('.patient-table tbody tr').forEach(row=>{
   // Hasta listesine Kaynak sütunu eklendiği için İlgili hücresi 15. kolondadır.
   const cell=row.cells[15];
   if(!cell)return;
   cell.textContent=cell.textContent.split(',').map(value=>{
     const current=value.trim(),column=oldNames[current];
     return column?(names[column]||''):current;
   }).filter(Boolean).join(', ');
   Object.entries(staffOrders).forEach(([column,orders])=>{
     if(names[column]&&orders.map(String).includes(row.cells[0]?.textContent.trim())&&!cell.textContent.split(',').map(v=>v.trim()).includes(names[column])) cell.textContent=[cell.textContent,names[column]].filter(Boolean).join(', ');
   });
 });
})();
</script>
HTML;
        $script = str_replace(['__STAFF_NAMES__', '__SELECTED_STAFF__', '__STAFF_ORDERS__'], [$json ?: '{}', $selectedJson ?: '{}', $ordersJson ?: '{}'], $script);
        return str_replace('</body>', $script . '</body>', $html);
    });
}
