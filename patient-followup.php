<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/source-bootstrap.php';
require __DIR__ . '/service-type-bootstrap.php';
require __DIR__ . '/service-name-bootstrap.php';
require __DIR__ . '/service-action-bootstrap.php';
require __DIR__ . '/complaint-bootstrap.php';
require __DIR__ . '/employee-patient-link.php';
require __DIR__ . '/patient-layout.php';

$pdo = db();
ensure_patient_source_schema();
ensure_patient_staff_yeliz_schema();
$staffNames = patient_staff_names(true);
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS patient_services (id INTEGER PRIMARY KEY AUTOINCREMENT, patient_id INTEGER NOT NULL, service_date TEXT NOT NULL, service_status TEXT NOT NULL, performed_action TEXT, action_date TEXT, opened_by TEXT, branch_name TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS patient_services (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, patient_id INT UNSIGNED NOT NULL, service_date DATE NOT NULL, service_status VARCHAR(80) NOT NULL, performed_action TEXT NULL, action_date DATE NULL, opened_by VARCHAR(190) NULL, branch_name VARCHAR(190) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS service_card_type_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(190) NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)'
    : 'CREATE TABLE IF NOT EXISTS service_card_type_definitions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
if ((int)$pdo->query('SELECT COUNT(*) FROM service_card_type_definitions')->fetchColumn() === 0) {
    $insertServiceType = $pdo->prepare('INSERT INTO service_card_type_definitions(name,active,sort_order) VALUES(?,?,?)');
    foreach (['Yüz yüze', 'Telefon', 'Çevrim içi'] as $order => $name) $insertServiceType->execute([$name, 1, $order + 1]);
}
$serviceCardTypes = $pdo->query('SELECT * FROM service_card_type_definitions WHERE active=1 ORDER BY sort_order,name')->fetchAll();

$extraColumns = ['record_no VARCHAR(60) NULL','appointment_date DATE NULL','start_time VARCHAR(10) NULL','end_time VARCHAR(10) NULL','service_type VARCHAR(150) NULL','service_location VARCHAR(150) NULL','branch_id INT NULL','contact_person VARCHAR(190) NULL','appointment_status VARCHAR(100) NULL','complaint TEXT NULL','observation TEXT NULL','service_name VARCHAR(150) NULL','stock_id BIGINT NULL','sales_details TEXT NULL','result_name VARCHAR(100) NULL','related_personnel TEXT NULL','satisfaction TINYINT NULL','action_name VARCHAR(150) NULL','repair_details TEXT NULL','description TEXT NULL'];
$knownColumns = $sqlite ? array_column($pdo->query('PRAGMA table_info(patient_services)')->fetchAll(), 'name') : array_column($pdo->query('SHOW COLUMNS FROM patient_services')->fetchAll(), 'Field');
foreach ($extraColumns as $definition) {
    $column = explode(' ', $definition, 2)[0];
    if (in_array($column, $knownColumns, true)) continue;
    $pdo->exec('ALTER TABLE patient_services ADD COLUMN ' . $definition);
}

$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS app_migrations (migration_key VARCHAR(190) PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS app_migrations (migration_key VARCHAR(190) PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$servicePersonnelNameMigration = '20260728_service_contact_person_full_names_v1';
$servicePersonnelNameCheck = $pdo->prepare('SELECT 1 FROM app_migrations WHERE migration_key=?');
$servicePersonnelNameCheck->execute([$servicePersonnelNameMigration]);
if (!$servicePersonnelNameCheck->fetchColumn()) {
    $servicePersonnelNames = [
        'Yeliz' => 'Yeliz Girgin Özkan',
        'Büşra' => 'Büşra Akar Avcı',
        'Erva' => 'Erva Özsarı',
        'Güneş' => 'Güneş İba',
        'Merve' => 'Merve Koçal',
        'Şeyma' => 'Şeyma Nur Büyükkayın',
        'Cansu, Belma Baysan' => 'Merve Cansu Eryılmaz, Belma Baysan',
        'Büşra, Belma Baysan' => 'Büşra Akar Avcı, Belma Baysan',
        'Cansu, Büşra' => 'Merve Cansu Eryılmaz, Büşra Akar Avcı',
    ];
    $normalizeContactPerson = $pdo->prepare('UPDATE patient_services SET contact_person=? WHERE contact_person=?');
    foreach ($servicePersonnelNames as $oldName => $fullName) $normalizeContactPerson->execute([$fullName, $oldName]);
    $pdo->prepare('INSERT INTO app_migrations(migration_key) VALUES(?)')->execute([$servicePersonnelNameMigration]);
}
$serviceMigrationKey = '20260725_patient_service_cards_and_personnel_v1';
$serviceMigrationCheck = $pdo->prepare('SELECT 1 FROM app_migrations WHERE migration_key=?');
$serviceMigrationCheck->execute([$serviceMigrationKey]);
$needsServiceMigration = !$serviceMigrationCheck->fetchColumn();

// Hasta Kartındaki eski Hizmet Yeri bilgisini bir kez Hizmet Kartlarına taşır.
// İşlem tekrarlanabilir: kartı olan hastaya ikinci kart açılmaz.
$patientColumns = $sqlite
    ? array_column($pdo->query('PRAGMA table_info(patients)')->fetchAll(), 'name')
    : array_column($pdo->query('SHOW COLUMNS FROM patients')->fetchAll(), 'Field');
if ($needsServiceMigration && in_array('service_location', $patientColumns, true)) {
    $serviceInsert = $pdo->prepare('INSERT INTO patient_services(patient_id,service_date,service_status,opened_by,branch_name,record_no,service_location,appointment_date,appointment_status,result_name) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $patientsWithoutService = $pdo->query("SELECT p.id,p.record_date,p.service_location,b.name AS branch_name FROM patients p LEFT JOIN branches b ON b.id=p.branch_id WHERE NOT EXISTS (SELECT 1 FROM patient_services s WHERE s.patient_id=p.id)")->fetchAll();
    foreach ($patientsWithoutService as $legacyPatient) {
        $date = preg_match('/^20\\d{2}-\\d{2}-\\d{2}$/', (string)$legacyPatient['record_date']) ? $legacyPatient['record_date'] : date('Y-m-d');
        $serviceInsert->execute([(int)$legacyPatient['id'], $date, 'Beklemede', 'Sistem', (string)($legacyPatient['branch_name'] ?? ''), 'HK-AUTO-' . (int)$legacyPatient['id'], (string)($legacyPatient['service_location'] ?? ''), $date, 'Beklemede', 'Beklemede']);
    }
    $legacyLocations = $pdo->query("SELECT id,service_location FROM patients WHERE COALESCE(service_location,'')<>''")->fetchAll();
    $latestService = $pdo->prepare('SELECT id,service_location FROM patient_services WHERE patient_id=? ORDER BY id DESC LIMIT 1');
    $updateServiceLocation = $pdo->prepare("UPDATE patient_services SET service_location=? WHERE id=? AND COALESCE(service_location,'')='' ");
    $clearLegacyLocation = $pdo->prepare('UPDATE patients SET service_location=NULL WHERE id=?');
    foreach ($legacyLocations as $legacyLocation) {
        $latestService->execute([(int)$legacyLocation['id']]);
        $service = $latestService->fetch();
        if ($service) $updateServiceLocation->execute([(string)$legacyLocation['service_location'], (int)$service['id']]);
        $clearLegacyLocation->execute([(int)$legacyLocation['id']]);
    }
}

// Hasta Kartındaki ilgili personeli hizmet kartına aktarır ve Hasta Kartından kaldırır.
if ($needsServiceMigration) {
    $staffColumns = array_keys($staffNames);
    $staffUpdate = $pdo->prepare("UPDATE patient_services SET related_personnel=? WHERE id=? AND COALESCE(related_personnel,'')='' ");
    $latestServiceForPersonnel = $pdo->prepare('SELECT id FROM patient_services WHERE patient_id=? ORDER BY id DESC LIMIT 1');
    $clearPatientPersonnel = $pdo->prepare('UPDATE patients SET ' . implode(',', array_map(static fn(string $column): string => $column . '=0', $staffColumns)) . ' WHERE id=?');
    foreach ($pdo->query('SELECT * FROM patients') as $personnelPatient) {
        $personnel = patient_staff_list($personnelPatient, $staffNames);
        if ($personnel === '') continue;
        $latestServiceForPersonnel->execute([(int)$personnelPatient['id']]);
        $serviceForPersonnel = $latestServiceForPersonnel->fetch();
        if (!$serviceForPersonnel) continue;
        $staffUpdate->execute([$personnel, (int)$serviceForPersonnel['id']]);
        $clearPatientPersonnel->execute([(int)$personnelPatient['id']]);
    }
    $pdo->prepare('INSERT INTO app_migrations(migration_key) VALUES(?)')->execute([$serviceMigrationKey]);
}

$id = (int)($_GET['id'] ?? 0);
$patientStatement = $pdo->prepare('SELECT patients.id,patients.full_name,patients.service_location,patients.anamnesis,patients.approval,patients.considering,patients.rejected,branches.name AS branch_name FROM patients LEFT JOIN branches ON branches.id=patients.branch_id WHERE patients.id=?');
$patientStatement->execute([$id]);
$patient = $patientStatement->fetch();
if (!$patient) { http_response_code(404); exit('Hasta kaydı bulunamadı.'); }
$patientOutcome = !empty($patient['approval']) ? 'Onay' : (!empty($patient['considering']) ? 'Düşünecek' : (!empty($patient['rejected']) ? 'Ret' : ''));
$branches = $pdo->query('SELECT id,name FROM branches ORDER BY name')->fetchAll();
$serviceLocations = array_filter(service_type_definitions(), static fn(array $location): bool => (int)$location['active'] === 1);
$serviceNames = array_filter(service_name_definitions(), static fn(array $name): bool => (int)$name['active'] === 1);
$stockCards = $pdo->query('SELECT id,stock_code,stock_name,brand,model,stock_type FROM stock_cards ORDER BY stock_name,stock_code')->fetchAll();
$hearingDeviceStatement = $pdo->prepare("SELECT s.id,s.brand,s.model,s.sale_price,(SELECT m.serial_numbers FROM stock_movements m WHERE m.stock_id=s.id AND m.movement_type='Giriş' AND COALESCE(m.serial_numbers,'') NOT IN ('','[]') ORDER BY m.movement_date DESC,m.id DESC LIMIT 1) AS serial_numbers FROM stock_cards s INNER JOIN (SELECT stock_id,SUM(CASE WHEN movement_type='Giriş' THEN quantity WHEN movement_type='Çıkış' THEN -quantity ELSE 0 END) AS stock_quantity FROM stock_movements GROUP BY stock_id) q ON q.stock_id=s.id AND q.stock_quantity>=1 WHERE s.stock_type=? AND EXISTS (SELECT 1 FROM stock_movements m WHERE m.stock_id=s.id AND m.movement_type='Giriş' AND COALESCE(m.serial_numbers,'') NOT IN ('','[]')) ORDER BY s.brand,s.model,s.id");
$hearingDeviceStatement->execute(['İşitme Cihazı']);
$hearingDeviceStocks = $hearingDeviceStatement->fetchAll();
$chargerDeviceStatement = $pdo->prepare("SELECT s.id,s.brand,s.model,s.sale_price,(SELECT m.serial_numbers FROM stock_movements m WHERE m.stock_id=s.id AND m.movement_type='Giriş' AND COALESCE(m.serial_numbers,'') NOT IN ('','[]') ORDER BY m.movement_date DESC,m.id DESC LIMIT 1) AS serial_numbers FROM stock_cards s INNER JOIN (SELECT stock_id,SUM(CASE WHEN movement_type='Giriş' THEN quantity WHEN movement_type='Çıkış' THEN -quantity ELSE 0 END) AS stock_quantity FROM stock_movements GROUP BY stock_id) q ON q.stock_id=s.id AND q.stock_quantity>=1 WHERE s.stock_type=? AND EXISTS (SELECT 1 FROM stock_movements m WHERE m.stock_id=s.id AND m.movement_type='Giriş' AND COALESCE(m.serial_numbers,'') NOT IN ('','[]')) ORDER BY s.brand,s.model,s.id");
$chargerDeviceStatement->execute(['Şarj Cihazı']);
$chargerDeviceStocks = $chargerDeviceStatement->fetchAll();
$consumableStatement = $pdo->prepare("SELECT s.id,s.stock_code,s.stock_name,s.stock_type,s.sale_price FROM stock_cards s INNER JOIN (SELECT stock_id,SUM(CASE WHEN movement_type='Giriş' THEN quantity WHEN movement_type='Çıkış' THEN -quantity ELSE 0 END) AS stock_quantity FROM stock_movements GROUP BY stock_id) q ON q.stock_id=s.id AND q.stock_quantity>=1 WHERE s.stock_type IN (?,?) ORDER BY s.stock_type,s.stock_name,s.stock_code");
$consumableStatement->execute(['Sarf Malzeme','Pil']);
$consumableStocks = $consumableStatement->fetchAll();
$customerAccounts = $pdo->query("SELECT id,code,title FROM current_accounts WHERE account_type IN ('supplier','both') ORDER BY title,code")->fetchAll();
$serviceActions = array_filter(service_action_definitions(), static fn(array $action): bool => (int)$action['active'] === 1);
$repairIssueDefinitions = array_filter(complaint_definitions(), static fn(array $issue): bool => (int)$issue['active'] === 1);
$editId = (int)($_GET['edit'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0;
$serviceCard = [];
if ($editId) {
    $editStatement = $pdo->prepare('SELECT * FROM patient_services WHERE id=? AND patient_id=?');
    $editStatement->execute([$editId, $id]);
    $serviceCard = $editStatement->fetch() ?: [];
    if (!$serviceCard) { http_response_code(404); exit('Hizmet kartı bulunamadı.'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    $postedEditId = (int)($_POST['edit_id'] ?? 0);
    if ($action === 'delete' && $postedEditId) {
        $pdo->prepare('DELETE FROM patient_services WHERE id=? AND patient_id=?')->execute([$postedEditId, $id]);
        redirect('patient-followup.php?id=' . $id);
    }
    $postedServiceName = trim((string)($_POST['service_name'] ?? ''));
    $postedStockId = (int)($_POST['stock_id'] ?? 0);
    $values = [
        'record_no'=>trim((string)($_POST['record_no'] ?? '')),
        'service_date'=>(string)($_POST['record_date'] ?? date('Y-m-d')),
        'service_status'=>trim((string)($_POST['result_name'] ?? 'Beklemede')) === 'Red' ? 'Ret' : trim((string)($_POST['result_name'] ?? 'Beklemede')),
        'performed_action'=>trim((string)($_POST['action_name'] ?? '')),
        'action_date'=>(string)($_POST['action_date'] ?? ''),
        'opened_by'=>(string)($_SESSION['user']['name'] ?? ''),
        'branch_name'=>(string)($_POST['branch_name'] ?? ''),
        'appointment_date'=>(string)($_POST['appointment_date'] ?? ''),
        'start_time'=>(string)($_POST['start_time'] ?? ''), 'end_time'=>(string)($_POST['end_time'] ?? ''),
        'service_type'=>trim((string)($_POST['service_type'] ?? '')), 'service_location'=>trim((string)($_POST['service_location'] ?? '')),
        'branch_id'=>(int)($_POST['branch_id'] ?? 0), 'contact_person'=>trim((string)($_POST['contact_person'] ?? '')),
        'appointment_status'=>trim((string)($_POST['appointment_status'] ?? '')), 'complaint'=>trim((string)($_POST['complaint'] ?? '')),
        'observation'=>trim((string)($_POST['observation'] ?? '')), 'service_name'=>$postedServiceName, 'stock_id'=>$postedServiceName === 'Satış' && $postedStockId > 0 ? $postedStockId : null,
        'result_name'=>trim((string)($_POST['result_name'] ?? '')) === 'Red' ? 'Ret' : trim((string)($_POST['result_name'] ?? '')), 'related_personnel'=>trim((string)($_POST['related_personnel'] ?? '')), 'satisfaction'=>(int)($_POST['satisfaction'] ?? 0),
        'action_name'=>trim((string)($_POST['action_name'] ?? '')), 'repair_details'=>(string)($_POST['repair_details'] ?? ''), 'sales_details'=>$postedServiceName === 'Satış' ? (string)($_POST['sales_details'] ?? '') : null, 'description'=>trim((string)($_POST['description'] ?? '')),
    ];
    if ($values['record_no'] === '') $values['record_no'] = 'HK' . date('ymdHis');
    if ($postedEditId) {
        $set = implode(',', array_map(static fn(string $column): string => $column . '=?', array_keys($values)));
        $pdo->prepare('UPDATE patient_services SET ' . $set . ' WHERE id=? AND patient_id=?')->execute([...array_values($values), $postedEditId, $id]);
    } else {
        $columns = array_merge(['patient_id'], array_keys($values));
        $pdo->prepare('INSERT INTO patient_services (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute([$id, ...array_values($values)]);
        $pdo->prepare('UPDATE patients SET service_location=NULL WHERE id=?')->execute([$id]);
        if ($postedServiceName === 'Satış') {
            $salesDetails = json_decode((string)$values['sales_details'], true);
            if (!is_array($salesDetails)) $salesDetails = [];
            $accountId = filter_var($salesDetails['sales_current_account'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $movementDate = $values['service_date'] ?: date('Y-m-d');
            $description = 'Hizmet kartı satışı: ' . $values['record_no'];
            $findStock = $pdo->prepare('SELECT id FROM stock_cards WHERE stock_type=? AND brand=? AND model=? ORDER BY id LIMIT 1');
            $addExit = $pdo->prepare('INSERT INTO stock_movements(stock_id,movement_type,quantity,movement_date,description,current_account_id,serial_numbers) VALUES(?,?,?,?,?,?,?)');
            $addDeviceExit = static function (string $type, string $brand, string $model, string $serial, int $quantity = 1) use ($findStock, $addExit, $movementDate, $description, $accountId): void {
                if ($brand === '' || $model === '' || $quantity < 1) return;
                $findStock->execute([$type, $brand, $model]);
                $stockId = (int)$findStock->fetchColumn();
                if (!$stockId) return;
                $serialNumbers = $serial === '' ? null : json_encode([$serial], JSON_UNESCAPED_UNICODE);
                $addExit->execute([$stockId, 'Çıkış', $quantity, $movementDate, $description, $accountId, $serialNumbers]);
            };
            $addDeviceExit('İşitme Cihazı', trim((string)($salesDetails['sales_brand'] ?? '')), trim((string)($salesDetails['sales_model'] ?? '')), trim((string)($salesDetails['sales_device_serial'] ?? '')));
            for ($deviceNumber = 2; $deviceNumber <= 4; $deviceNumber++) {
                $addDeviceExit('İşitme Cihazı', trim((string)($salesDetails["sales_device_{$deviceNumber}_brand"] ?? '')), trim((string)($salesDetails["sales_device_{$deviceNumber}_model"] ?? '')), trim((string)($salesDetails["sales_device_{$deviceNumber}_serial"] ?? '')));
            }
            $addDeviceExit('Şarj Cihazı', trim((string)($salesDetails['sales_charger_brand'] ?? '')), trim((string)($salesDetails['sales_charger_model'] ?? '')), trim((string)($salesDetails['sales_charger_serial'] ?? '')));
            $consumableStockId = filter_var($salesDetails['sales_consumable_stock_id'] ?? null, FILTER_VALIDATE_INT);
            $consumableQuantity = max(0, (int)($salesDetails['sales_consumable_quantity'] ?? 0));
            if ($consumableStockId && $consumableQuantity > 0) {
                $addExit->execute([$consumableStockId, 'Çıkış', $consumableQuantity, $movementDate, $description, $accountId, null]);
            }
        }
    }
    redirect('patient-followup.php?id=' . $id);
}

$servicesStatement = $pdo->prepare('SELECT * FROM patient_services WHERE patient_id=? ORDER BY service_date DESC,id DESC');
$servicesStatement->execute([$id]);
$services = $servicesStatement->fetchAll();
patient_header('Hizmetler', 'patients');
$requestedServiceName = trim((string)($_GET['service_name'] ?? ''));
$form = array_merge(['record_no'=>'HK' . date('ymdHis'),'service_date'=>date('Y-m-d'),'appointment_date'=>date('Y-m-d'),'start_time'=>'15:00','end_time'=>'17:00','service_type'=>'','service_location'=>(string)($patient['service_location'] ?? ''),'branch_id'=>'','contact_person'=>patient_staff_list($patient, $staffNames),'appointment_status'=>'Beklemede','complaint'=>(string)($patient['anamnesis'] ?? ''),'observation'=>'','service_name'=>$requestedServiceName,'stock_id'=>null,'sales_details'=>'','result_name'=>$patientOutcome ?: 'Beklemede','related_personnel'=>patient_staff_list($patient, $staffNames),'satisfaction'=>1,'action_name'=>'','action_date'=>date('Y-m-d'),'repair_details'=>'','description'=>''], $serviceCard);
if ($form['result_name'] === 'Red') $form['result_name'] = 'Ret';
if ($editId && trim((string)$form['service_location']) === '') $form['service_location'] = (string)($patient['service_location'] ?? '');
if ($editId && trim((string)$form['complaint']) === '') $form['complaint'] = (string)($patient['anamnesis'] ?? '');
if ($patientOutcome !== '' && ($form['result_name'] === '' || $form['result_name'] === 'Beklemede')) $form['result_name'] = $patientOutcome;
if ($editId && trim((string)$form['related_personnel']) === '') $form['related_personnel'] = patient_staff_list($patient, $staffNames);
if (trim((string)$form['related_personnel']) !== '' && (trim((string)$form['contact_person']) === '' || $form['contact_person'] === 'Vox Yöneticisi')) $form['contact_person'] = $form['related_personnel'];

// Pasif personel yeni seçimlerde gösterilmez. Ancak hasta kartında ilgili
// personel olarak daha önce kaydedilmişse, geçmiş kaydı korumak için görünür.
$activeStaffNames = patient_staff_names();
$contactPersonOptions = array_values(array_unique($activeStaffNames));
$registeredPersonnel = preg_split('/\s*,\s*/u', (string)$form['related_personnel'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
foreach ($registeredPersonnel as $person) {
    if (!in_array($person, $contactPersonOptions, true)) $contactPersonOptions[] = $person;
}
$currentContactPerson = trim((string)$form['contact_person']);
if ($currentContactPerson !== '') {
    $normalizePerson = static function (string $name): string {
        $name = mb_strtolower(trim($name), 'UTF-8');
        return preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? '';
    };
    $normalizedCurrent = $normalizePerson($currentContactPerson);
    foreach ($contactPersonOptions as $person) {
        $normalizedPerson = $normalizePerson($person);
        if ($normalizedCurrent === $normalizedPerson
            || str_starts_with($normalizedPerson, $normalizedCurrent . ' ')
            || str_starts_with($normalizedCurrent, $normalizedPerson . ' ')) {
            $form['contact_person'] = $person;
            $currentContactPerson = $person;
            break;
        }
    }
    if (!in_array($currentContactPerson, $contactPersonOptions, true)) $contactPersonOptions[] = $currentContactPerson;
}
?>
<style>
.services-page{max-width:1120px;margin:0 auto;padding:96px 20px 48px!important}.services-card{background:var(--card);border:1px solid var(--line);border-radius:8px;box-shadow:0 .25rem 1.125rem rgba(47,43,61,.1);overflow:hidden}.services-head{display:flex;align-items:center;justify-content:space-between;min-height:70px;padding:0 24px;border-bottom:1px solid var(--line)}.services-head h2{margin:0;font-size:19px;font-weight:600}.service-form{padding:20px 16px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 16px}.service-field{display:flex;flex-direction:column;gap:6px;color:var(--text);font-size:12px}.service-field input,.service-field select,.service-field textarea{box-sizing:border-box;width:100%;min-height:38px;padding:8px 10px;border:1px solid #d5d3de;border-radius:5px;background:var(--card);color:var(--text);font:inherit}.service-field textarea{min-height:58px;resize:vertical}.service-wide{grid-column:1/-1}.service-three{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;grid-column:1/-1}.sales-details{grid-column:1/-1;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px 16px;padding:18px;border:1px solid #d9e7dc;border-radius:7px;background:#fbfefb}.sales-details[hidden]{display:none}.sales-details h3{grid-column:1/-1;margin:0;color:#159447;font-size:14px}.sales-details .sales-wide{grid-column:span 2}.sales-details .sales-full{grid-column:1/-1}@media(max-width:850px){.sales-details{grid-template-columns:repeat(2,minmax(0,1fr))}}.satisfaction{grid-column:1/-1;text-align:center}.satisfaction label{font-size:12px;display:block;margin-bottom:5px}.faces{display:flex;justify-content:center;gap:12px}.faces input{position:absolute;opacity:0}.faces label{display:grid;place-items:center;width:40px;height:40px;border-radius:50%;border:1px solid #9da0a9;font-size:23px;cursor:pointer}.faces label:nth-of-type(1){background:#fff09c}.faces label:nth-of-type(2){background:#b5ddbc}.faces label:nth-of-type(3){background:#9fdbf1}.faces label:nth-of-type(4){background:#f5a2a2}.faces input:checked+label{outline:3px solid #7367f0}.action-box{grid-column:1/-1;margin-top:2px;padding:16px;border-radius:7px;background:#fff;box-shadow:0 .15rem .7rem rgba(47,43,61,.1);display:grid;grid-template-columns:1fr 1fr;gap:12px 16px}.action-box h3{grid-column:1/-1;margin:0;font-size:13px}.action-box .button{justify-self:end}.service-form footer{grid-column:1/-1;display:flex;gap:10px}.services-toolbar{display:flex;justify-content:space-between;padding:18px 24px;border-top:1px solid var(--line);border-bottom:1px solid var(--line);color:var(--muted)}.services-table{width:100%;border-collapse:collapse}.services-table th,.services-table td{padding:14px 18px;border-bottom:1px solid var(--line);text-align:left}.services-table th{font-size:11px;color:var(--muted)}.service-empty{text-align:center;color:var(--muted)}@media(max-width:720px){.services-page{padding:92px 12px 30px!important}.service-form,.action-box{grid-template-columns:1fr}.service-three,.sales-details{grid-template-columns:1fr}.sales-details .sales-wide{grid-column:1}.services-table{min-width:850px}.services-card{overflow:auto}}
</style>
<style>
/* Hasta Kartı ile aynı form ölçüleri ve yazı hiyerarşisi. */
.services-page{max-width:1100px!important;padding:28px 20px 48px!important}.services-head h2{font-size:20px!important;font-weight:500!important}.service-form{display:block!important;padding:10px 24px 24px!important}.service-field{display:grid!important;grid-template-columns:150px minmax(0,1fr)!important;align-items:start!important;gap:0!important;margin:14px 0!important;font-size:14px!important}.service-field input,.service-field select,.service-field textarea{grid-column:2!important;width:100%!important;min-height:40px!important;height:40px!important;padding:8px 12px!important;border:1px solid #d5d3de!important;border-radius:6px!important;box-shadow:none!important}.service-field textarea{height:76px!important;padding-top:10px!important}.service-three{display:contents!important}.service-three .service-field{display:grid!important}.service-wide{grid-column:auto!important}.satisfaction{margin:14px 0!important;text-align:left!important;padding-left:150px!important}.satisfaction>label{font-size:14px!important;color:var(--text)!important}.faces{justify-content:flex-start!important}.action-box{margin:20px 0!important;padding:16px!important;border:1px solid var(--line)!important;border-radius:7px!important;box-shadow:none!important;display:grid!important;grid-template-columns:1fr 1fr!important}.action-box h3{font-size:14px!important}.action-box .service-field{grid-template-columns:120px minmax(0,1fr)!important;margin:0!important}.service-form footer{margin:22px 0 0 150px!important}.service-form footer .button{min-width:100px!important}@media(max-width:720px){.services-page{padding:20px 12px 30px!important}.service-form{padding:10px 16px 22px!important}.service-field{grid-template-columns:1fr!important;gap:7px!important}.service-field input,.service-field select,.service-field textarea{grid-column:1!important}.satisfaction{padding-left:0!important}.action-box{grid-template-columns:1fr!important}.action-box .service-field{grid-template-columns:1fr!important}.service-form footer{margin-left:0!important}}
</style>
<style>
/* Hizmet Adı sayfasındaki kart, boşluk ve liste hiyerarşisi. */
.services-page{width:100%!important;max-width:1000px!important;min-height:100vh!important;margin:0 auto!important;padding:46px 20px 48px!important}
.services-card{background:#fff!important;border:1px solid #e1e2e8!important;border-radius:10px!important;margin-bottom:24px!important;box-shadow:0 3px 12px #1e283c0f!important}
.services-head{position:relative!important;display:block!important;min-height:0!important;padding:22px 24px!important;border-bottom:1px solid #e1e2e8!important}
.services-head h2{margin:0!important;color:#2f2b3d!important;font-size:21px!important;line-height:1.25!important;font-weight:600!important}
.services-head .button{position:absolute;right:24px;top:50%;transform:translateY(-50%);white-space:nowrap}
.services-toolbar{padding:18px 24px!important;border-top:0!important;border-bottom:1px solid #e1e2e8!important}
.services-table{min-width:780px!important}.services-table th,.services-table td{padding:14px 18px!important;border-bottom:1px solid #e1e2e8!important}.services-table th{font-size:12px!important;color:#5d5b6d!important}.services-card:has(.services-table){overflow:visible!important}.services-card:has(.services-table) .table-responsive{overflow:auto}
.satisfaction{padding-left:0!important;text-align:center!important}.satisfaction>label{text-align:center!important;margin-bottom:14px!important}.faces{justify-content:center!important;gap:20px!important}.faces label{width:66px!important;height:66px!important;font-size:40px!important}
.faces input:checked+label{outline:3px solid #19a94b!important;box-shadow:0 0 0 5px rgba(25,169,75,.16)!important}.action-box .action-add-button{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:44px!important;height:44px!important;min-width:44px!important;padding:0!important;font-size:24px!important;font-weight:400!important;line-height:1!important}
.action-box{display:block!important;margin:14px 0!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important}.action-box .service-field{grid-template-columns:150px minmax(0,1fr)!important;margin:14px 0!important}
.service-form-head{display:flex!important;align-items:center!important;justify-content:space-between!important}.service-back-link{color:var(--muted)!important;text-decoration:none!important;font-size:14px!important;white-space:nowrap}.service-back-link:hover{color:#19a94b!important}
.service-input-with-icon{display:flex!important;align-items:stretch!important;grid-column:2!important;min-height:40px!important;border:1px solid #d5d3de!important;border-radius:6px!important;background:var(--card)!important;overflow:hidden!important}.service-input-icon{display:grid!important;place-items:center!important;flex:0 0 46px!important;width:46px!important;color:#686574!important;font-size:17px!important}.service-input-with-icon input,.service-input-with-icon select,.service-input-with-icon textarea{width:100%!important;min-width:0!important;height:38px!important;min-height:38px!important;margin:0!important;padding:8px 12px 8px 0!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important}.service-input-with-icon textarea{height:76px!important;padding-top:10px!important}
@media(max-width:720px){.services-page{max-width:none!important;padding:92px 14px 30px!important}.services-head{padding-right:170px!important}.services-head .button{right:16px}.service-form-head{padding-right:16px!important}.action-box .service-field{grid-template-columns:1fr!important}.service-input-with-icon{grid-column:1!important}}
</style>
<main class="patient-container services-page"><section class="services-card">
<?php if($showForm): ?><header class="services-head service-form-head"><h2><?= $editId ? 'Hizmet Kartı Düzenle' : 'Yeni Hizmet Kartı' ?> - <?=e($patient['full_name'])?></h2><a class="service-back-link" href="<?=e(url('patient-followup.php?id='.$id))?>">Listeye dön</a></header><form id="service-card-form" class="service-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="edit_id" value="<?=$editId?>"><input type="hidden" id="repair_details" name="repair_details" value="<?=e((string)$form['repair_details'])?>"><input type="hidden" id="sales_stock_id" name="stock_id" value="<?=e((string)($form['stock_id'] ?? ''))?>"><input type="hidden" id="sales_details" name="sales_details" value="<?=e((string)($form['sales_details'] ?? ''))?>">
<label class="service-field">Kayıt No<input name="record_no" value="HK<?=date('ymdHis')?>"></label><label class="service-field">Kayıt Tarihi<input type="date" name="record_date" value="<?=e((string)$form['service_date'])?>"></label>
<div class="service-three"><label class="service-field">Randevu Tarihi<input type="date" name="appointment_date" value="<?=date('Y-m-d')?>"></label><label class="service-field">Başlangıç Saati<select name="start_time" required><?php for($hour=9;$hour<=19;$hour++):foreach([0,15,30,45] as $minute):if($hour===19&&$minute>0)continue;$time=sprintf('%02d:%02d',$hour,$minute);?><option value="<?=$time?>"><?=$time?></option><?php endforeach;endfor;?></select></label><label class="service-field">Bitiş Saati<select name="end_time" required><?php for($hour=9;$hour<=19;$hour++):foreach([0,15,30,45] as $minute):if(($hour===9&&$minute<15)||($hour===19&&$minute>0))continue;$time=sprintf('%02d:%02d',$hour,$minute);?><option value="<?=$time?>"><?=$time?></option><?php endforeach;endfor;?></select></label></div>
<label class="service-field">Hizmet Tipi<select name="service_type"><option value="">Seçiniz</option><?php foreach($serviceCardTypes as $type):?><option value="<?=e($type['name'])?>"><?=e($type['name'])?></option><?php endforeach?></select></label><label class="service-field">Hizmet Yeri<select name="service_location"><option value="">Seçiniz</option><?php foreach($serviceLocations as $location):?><option value="<?=e($location['name'])?>"><?=e($location['name'])?></option><?php endforeach?></select></label>
<label class="service-field service-wide">Şube Seçin<select name="branch_id"><option value="">Seçiniz</option><?php foreach($branches as $branch):?><option value="<?=(int)$branch['id']?>" <?=((string)$patient['branch_name']===(string)$branch['name'])?'selected':''?>><?=e($branch['name'])?></option><?php endforeach?></select><input type="hidden" name="branch_name" value="<?=e((string)$patient['branch_name'])?>"></label>
<label class="service-field">İlgilenen Kişi<select name="contact_person"><option value="">Seçiniz</option><?php foreach($contactPersonOptions as $person):?><option value="<?=e($person)?>"><?=e($person)?></option><?php endforeach?></select></label><label class="service-field">Randevu Durumu<select name="appointment_status"><option>Beklemede</option><option>Onaylandı</option><option>Tamamlandı</option><option>İptal</option></select></label>
<label class="service-field service-wide">Anamnez<textarea name="complaint" placeholder="Anamnez Girin"></textarea></label><label class="service-field service-wide">Gözlem<textarea name="observation" placeholder="Gözlem Girin"></textarea></label>
<label class="service-field">Hizmet Adı<select name="service_name"><option value="">Seçiniz</option><?php foreach($serviceNames as $serviceName):?><option value="<?=e($serviceName['name'])?>"><?=e($serviceName['name'])?></option><?php endforeach?></select></label><label class="service-field">Sonuç<select name="result_name"><option>Beklemede</option><option>Onay</option><option>Düşünecek</option><option>Ret</option><option>Tamamlandı</option><option>İptal</option></select></label>
<section class="action-box"><label class="service-field">Aksiyon<select name="action_name"><option value="">Seçiniz</option><?php foreach($serviceActions as $serviceAction):?><option value="<?=e($serviceAction['name'])?>"><?=e($serviceAction['name'])?></option><?php endforeach?></select></label><label class="service-field">Aksiyon Tarihi<input type="date" name="action_date" value="<?=date('Y-m-d')?>"></label></section>
<div class="satisfaction"><label>Memnuniyet</label><div class="faces"><?php foreach(['🙂','😐','🙁','😡'] as $score=>$face):?><input id="s<?=$score+1?>" type="radio" name="satisfaction" value="<?=$score+1?>" <?=$score===0?'checked':''?>><label for="s<?=$score+1?>"><?=$face?></label><?php endforeach?></div></div>
<label class="service-field service-wide">Açıklama<textarea name="description"></textarea></label><footer><button class="button"><?=$editId ? 'Güncelle' : 'Kaydet'?></button><a class="cancel-link" href="<?=e(url('patient-followup.php?id='.$id))?>">İptal</a></footer></form><script>document.addEventListener('DOMContentLoaded',()=>{const values=<?=json_encode($form, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;Object.entries(values).forEach(([name,value])=>{const field=document.querySelector(`[name="${name}"]`);if(field&&name!=='branch_name')field.value=value??'';});});</script>
<?php else: ?><header class="services-head"><h2>Hasta Hizmet Kartı Yönetimi - <?=e($patient['full_name'])?></h2><a class="button" href="<?=e(url('patient-followup.php?id='.$id.'&new=1'))?>">＋ Yeni Hizmet Kartı Ekle</a></header><div class="services-toolbar"><span>Toplam <?=count($services)?> kayıt</span><span>Ara: <input type="search" placeholder="Ara"></span></div><table class="services-table"><thead><tr><th>SIRA</th><th>TARİH</th><th>DURUM</th><th>YAPILAN İŞLEM</th><th>AKSİYON</th><th>İLGİLENEN</th><th>ŞUBE</th><th>İŞLEM</th></tr></thead><tbody><?php foreach($services as $index=>$service):?><tr data-edit-url="<?=e(url('patient-followup.php?id='.$id.'&edit='.(int)$service['id']))?>"><td><?=$index+1?></td><td><?=e(format_date_tr($service['service_date']))?></td><td><?=e($service['service_status'])?></td><td><?=e($service['service_name'] ?? '')?:'—'?></td><td><?=e(format_date_tr($service['action_date']))?></td><td><?=e($service['contact_person'] ?? '')?></td><td><?=e($service['branch_name'])?></td><td><a class="button" href="<?=e(url('patient-followup.php?id='.$id.'&edit='.(int)$service['id']))?>" title="Düzenle"><i class="icon-base ti tabler-edit"></i></a><form method="post" style="display:inline" onsubmit="return confirm('Bu hizmet kartı silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="edit_id" value="<?=(int)$service['id']?>"><button class="button" style="background:#e04f55" title="Sil"><i class="icon-base ti tabler-trash"></i></button></form></td></tr><?php endforeach;if(!$services):?><tr><td colspan="8" class="service-empty">Henüz hizmet kartı bulunmuyor.</td></tr><?php endif?></tbody></table><script>document.querySelectorAll('.services-table tbody tr[data-edit-url]').forEach(row=>{row.style.cursor='pointer';row.addEventListener('dblclick',event=>{if(event.target.closest('a,button,form,input'))return;window.location.href=row.dataset.editUrl;});});</script><?php endif; ?>
</section></main>
<?php if (!$showForm): ?>
<script>
(() => {
  const newServiceButton = document.querySelector('.services-head > .button');
  if (newServiceButton) newServiceButton.textContent = '+ Hizmet';
  document.querySelectorAll('.services-table tbody tr').forEach(row => {
    const actions = row.lastElementChild;
    if (!actions || actions.querySelector('[data-patient-back]')) return;
    actions.style.display = 'flex';
    actions.style.alignItems = 'center';
    actions.style.justifyContent = 'center';
    actions.style.gap = '8px';
    const back = document.createElement('a');
    back.href = <?=json_encode(url('patient-form.php?id=' . $id . '&return=patients.php'))?>;
    back.title = 'Hasta kartına dön';
    back.setAttribute('aria-label', 'Hasta kartına dön');
    back.setAttribute('data-patient-back', '1');
    back.className = 'button';
    back.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;width:40px;min-width:40px;height:40px;min-height:40px;margin:0;padding:0;border:1px solid #f3a64a;border-radius:6px;background:#f3a64a;color:#202020';
    const deleteForm = actions.querySelector('form');
    if (deleteForm) deleteForm.style.margin = '0';
    back.innerHTML = '<i class="icon-base ti tabler-arrow-back-up" style="font-size:20px"></i>';
    actions.insertBefore(back, actions.firstChild);
  });
})();
</script>
<?php endif; ?>
<style>
.repair-modal[hidden]{display:none!important}.repair-modal{position:fixed;z-index:1000;inset:0;display:grid;place-items:center;padding:20px}.repair-modal-backdrop{position:absolute;inset:0;background:rgba(32,33,45,.5)}.repair-dialog{position:relative;width:min(760px,100%);max-height:calc(100vh - 40px);overflow:auto;border-radius:10px;background:#fff;box-shadow:0 18px 46px rgba(0,0,0,.28)}.repair-dialog>header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid #e1e2e8}.repair-dialog h2{margin:0;font-size:18px;color:#2f2b3d}.repair-dialog h2 .ti{vertical-align:-2px;margin-right:7px}.repair-close{border:0;background:transparent;color:#8b8a95;font-size:30px;line-height:1;cursor:pointer}.repair-body{display:grid;gap:14px;padding:20px 24px}.repair-body>label,.repair-body fieldset{display:flex;flex-direction:column;gap:7px;color:#2f2b3d;font-size:14px}.repair-body small{color:#8b8a95;font-weight:400}.repair-body input:not([type=checkbox]),.repair-body select,.repair-body textarea{box-sizing:border-box;width:100%;min-height:38px;padding:8px 11px;border:1px solid #d5d3de;border-radius:6px;background:#fff;font:inherit;color:#2f2b3d}.repair-body textarea{min-height:70px;resize:vertical}.repair-check{font-size:13px;font-weight:400}.repair-body fieldset{margin:0;padding:0;border:0}.repair-body fieldset>label{display:inline-flex;align-items:center;gap:6px;margin-right:12px;font-size:14px}.repair-issues{border:1px solid #e1e2e8!important;border-radius:6px!important;padding:10px!important;max-height:205px;overflow:auto}.repair-issues>label,.repair-issue-head{display:grid;grid-template-columns:1fr 120px 120px;align-items:center;gap:8px;padding:5px 0}.repair-issues input{justify-self:start;width:16px;height:16px}.repair-issue-head{color:#8b8a95;font-size:13px}.repair-switch{display:flex!important;flex-direction:row!important;align-items:center;gap:8px}.repair-switch input{width:38px;height:21px;accent-color:#19a94b}.repair-grid{display:grid;grid-template-columns:1fr 1.4fr;gap:10px}.repair-grid label{display:flex;flex-direction:column;gap:7px;font-size:14px}.repair-dialog>footer{display:flex;justify-content:flex-end;gap:10px;padding:16px 24px 20px}.repair-cancel{border:1px solid #d5d3de;border-radius:6px;padding:10px 16px;background:#fff;color:#5d5b6d;cursor:pointer}@media(max-width:620px){.repair-modal{padding:8px}.repair-body,.repair-dialog>header,.repair-dialog>footer{padding-left:16px;padding-right:16px}.repair-issues>label,.repair-issue-head{grid-template-columns:1fr 70px 70px}.repair-grid{grid-template-columns:1fr}}
</style>
<?php if($showForm): ?>
<style>
.repair-body .repair-issues>label,.repair-body .repair-issues>.repair-issue-head{display:grid!important;grid-template-columns:minmax(0,1fr) 120px 120px!important;align-items:center!important;gap:8px!important;width:100%!important;margin:0!important}.repair-body .repair-issues>label>input{justify-self:center!important;margin:0!important}.repair-body .repair-issues>.repair-issue-head>span:not(:first-child){text-align:center!important}.sales-details-dialog{width:min(920px,100%)}.sales-details-dialog .repair-body{grid-template-columns:repeat(3,minmax(0,1fr))}.sales-device-button{grid-column:1/-1;justify-self:start}.sales-device-details{display:grid;grid-column:1/-1;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:14px}.sales-device-details[hidden]{display:none}.sales-product-cancel{position:relative;top:4px!important;display:grid;box-sizing:border-box;place-items:center;justify-self:start;align-self:center;grid-column:4;grid-row:1;width:20px!important;min-width:20px!important;max-width:20px!important;height:20px!important;min-height:20px!important;max-height:20px!important;margin-left:-13px;padding:0!important;border:0;border-radius:50%;background:#ea5455;color:#fff;font-size:18px;line-height:1}.sales-details-dialog .repair-body>label,.sales-details-dialog .repair-body .sales-device-details>label,.sales-details-dialog .repair-body #hearing-device-details-2>label,.sales-details-dialog .repair-body #charger-device-details>label{border:3px solid #fff;border-radius:7px;padding:9px}.sales-details-dialog .repair-body .sales-device-details>label>input,.sales-details-dialog .repair-body .sales-device-details>label>select,.sales-details-dialog .repair-body #hearing-device-details-2>label>input,.sales-details-dialog .repair-body #hearing-device-details-2>label>select{border:3px solid #159447}.sales-details-dialog .repair-body #charger-device-details>label>input,.sales-details-dialog .repair-body #charger-device-details>label>select{border:3px solid #795548}@media(max-width:620px){.sales-details-dialog .repair-body,.sales-device-details{grid-template-columns:1fr}.sales-product-cancel{justify-self:end}}
</style>
<style>
.sales-price-tooltip{position:relative}.sales-price-tooltip[data-list-price]:hover::after{content:attr(data-list-price);position:absolute;z-index:10;bottom:calc(100% + 7px);left:50%;transform:translateX(-50%);padding:6px 9px;border-radius:5px;background:#ea5455;color:#fff;font-size:12px;font-weight:600;white-space:nowrap;box-shadow:0 3px 8px rgba(234,84,85,.28)}.sales-details-dialog .repair-body [id^="hearing-device-details-"]>label{border:3px solid #fff;border-radius:7px;padding:9px}.sales-details-dialog .repair-body [id^="hearing-device-details-"]>label>input,.sales-details-dialog .repair-body [id^="hearing-device-details-"]>label>select{border:3px solid #159447}.sales-details-dialog .repair-body #consumable-details>label{border:3px solid #fff;border-radius:7px;padding:9px}.sales-details-dialog .repair-body #consumable-details>label>input,.sales-details-dialog .repair-body #consumable-details>label>select{border:3px solid #e6b800}
</style>
<div id="repair-modal" class="repair-modal" hidden aria-hidden="true">
  <div class="repair-modal-backdrop" data-repair-close></div>
  <section class="repair-dialog" role="dialog" aria-modal="true" aria-labelledby="repair-modal-title">
    <header><h2 id="repair-modal-title"><i class="ti tabler-tools" aria-hidden="true"></i> Tamir Kabul - Yeni Kayıt</h2><button type="button" class="repair-close" data-repair-close aria-label="Kapat">×</button></header>
    <div class="repair-body">
      <label>Hasta Kodu <small>(firma geneli benzersiz — önerilen kodu değiştirebilirsiniz)</small><input form="service-card-form" name="repair_patient_code" placeholder="Örn. MED-41"></label>
      <label>Cihaz <span class="repair-check"><input form="service-card-form" type="checkbox" name="repair_external_device"> Dış cihaz (bizim sattığımız değil)</span><select form="service-card-form" name="repair_device"><option value="">Bu hastaya ait cihaz bulunamadı — dış cihaz işaretleyin</option></select></label>
      <fieldset><legend>Birlikte alınan aksesuarlar</legend><label><input form="service-card-form" type="checkbox" name="repair_accessories[]" value="Pil"> Pil</label><label><input form="service-card-form" type="checkbox" name="repair_accessories[]" value="Garanti Kartı"> Garanti Kartı</label><label><input form="service-card-form" type="checkbox" name="repair_accessories[]" value="Kutu"> Kutu</label><label><input form="service-card-form" type="checkbox" name="repair_accessories[]" value="Kulak Kalıbı"> Kulak Kalıbı</label></fieldset>
      <label>Kalip Modeli <small>(kalıp siparişi değilse boş bırakın)</small><select form="service-card-form" name="repair_mold"><option value="">Kalıp modeli seçin</option></select></label>
      <fieldset class="repair-issues"><legend>Şikayet / Arıza</legend><div class="repair-issue-head"><span></span><span>Müşteri</span><span>Teknisyen</span></div><?php foreach($repairIssueDefinitions as $issue):?><label><span><?=e($issue['name'])?></span><input form="service-card-form" type="checkbox" name="repair_customer_issues[]" value="<?=e($issue['name'])?>"><input form="service-card-form" type="checkbox" name="repair_technician_issues[]" value="<?=e($issue['name'])?>"></label><?php endforeach?></fieldset>
      <textarea form="service-card-form" name="repair_note" placeholder="Ek açıklama (opsiyonel)"></textarea>
      <label class="repair-switch"><input form="service-card-form" type="checkbox" name="repair_warranty"><span></span> Garanti kapsamında</label>
      <label>Tamire teslim tarihi<input form="service-card-form" type="date" name="repair_delivery_date" value="<?=date('Y-m-d')?>"></label>
      <div class="repair-grid"><label>Teknik servise gönderilecekse (opsiyonel)<select form="service-card-form" name="repair_target"><option value="">Hedef</option><option>Teknik Servis</option></select></label><label>&nbsp;<input form="service-card-form" name="repair_technician" placeholder="Hangi teknik servis (ad)"></label></div>
      <label>Teslim eden (cihazı bırakan kişi)<input form="service-card-form" name="repair_delivered_by" placeholder="Ad Soyad (opsiyonel)"></label>
    </div>
    <footer><button type="button" class="repair-cancel" data-repair-close>İptal</button><button type="button" class="button" id="repair-save">Tamir Kaydı Oluştur</button></footer>
  </section>
</div>
<?php endif; ?>
<?php if($showForm): ?>
<div id="sales-stock-modal" class="repair-modal" hidden aria-hidden="true"><div class="repair-modal-backdrop" data-sales-close></div><section class="repair-dialog" role="dialog" aria-modal="true" aria-labelledby="sales-stock-title"><header><h2 id="sales-stock-title">Satış Stoğu Seç</h2><button type="button" class="repair-close" data-sales-close aria-label="Kapat">×</button></header><div class="repair-body"><input id="sales-stock-search" type="search" placeholder="Stok kodu, adı, marka veya model ara" autocomplete="off"><div id="sales-stock-list"><?php foreach($stockCards as $stock): $label=trim((string)$stock['stock_code'].' — '.(string)$stock['stock_name']); ?><button type="button" class="sales-stock-item" data-id="<?=(int)$stock['id']?>" data-label="<?=e($label)?>" data-search="<?=e(mb_strtolower($label.' '.(string)$stock['brand'].' '.(string)$stock['model'], 'UTF-8'))?>"><?=e($label)?></button><?php endforeach; if(!$stockCards): ?><p>Stok kartı bulunamadı.</p><?php endif; ?></div></div><footer><button type="button" class="repair-cancel" data-sales-close>İptal</button></footer></section></div>
<div id="sales-details-modal" class="repair-modal" hidden aria-hidden="true"><div class="repair-modal-backdrop" data-sales-details-close></div><section class="repair-dialog sales-details-dialog" role="dialog" aria-modal="true" aria-labelledby="sales-details-title"><header><h2 id="sales-details-title">Satış Bilgileri</h2><button type="button" class="repair-close" data-sales-details-close aria-label="Kapat">×</button></header><div class="repair-body"><button type="button" id="add-hearing-device" class="button sales-device-button">+ İşitme Cihazı Ekle</button><div id="hearing-device-details" class="sales-device-details" hidden><label>Marka<input name="sales_brand" autocomplete="off"></label><label>Model<input name="sales_model" autocomplete="off"></label><label>Seri No<input name="sales_device_serial" autocomplete="off"></label><label>SGK<input inputmode="decimal" name="sales_device_sgk" autocomplete="off"></label><label>İskonto Oranı<input inputmode="decimal" name="sales_device_discount_rate" autocomplete="off"></label><label>Net Fiyat<input inputmode="decimal" name="sales_device_net_price" autocomplete="off"></label></div><label>Garanti Başlangıç<input type="date" name="sales_warranty_start"></label><label>Garanti Bitiş<input type="date" name="sales_warranty_end"></label><label>Fatura No<input name="sales_invoice_no" autocomplete="off"></label><label>Ödeme Şekli<select name="sales_payment_type"><option value="">Seçiniz</option><option>Nakit</option><option>Kredi Kartı</option><option>Mail Order</option><option>Vadeli</option></select></label><label>Cari<input name="sales_current_account" autocomplete="off"></label><label>Toplam Tutar<input inputmode="decimal" name="sales_payment_amount" autocomplete="off" readonly></label></div><footer><button type="button" class="repair-cancel" data-sales-details-close>İptal</button><button type="button" class="button" id="sales-details-save">Tamam</button></footer></section></div>
<?php endif; ?>
<script>
(() => {
  const modal = document.getElementById('repair-modal');
  const form = document.getElementById('service-card-form');
  const serviceName = form?.querySelector('[name="service_name"]');
  const details = document.getElementById('repair_details');
  if (!modal || !form || !serviceName || !details) return;
  const controls = [...modal.querySelectorAll('[name]')];
  const open = () => { modal.hidden = false; modal.setAttribute('aria-hidden', 'false'); };
  const close = () => { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); };
  const restore = () => { try { const values = JSON.parse(details.value || '{}'); controls.forEach(control => { const value = values[control.name]; if (control.type === 'checkbox') control.checked = Array.isArray(value) ? value.includes(control.value) : Boolean(value); else if (value !== undefined) control.value = value; }); } catch (_) {} };
  const persist = () => { const values = {}; controls.forEach(control => { if (control.type === 'checkbox') { if (control.name.endsWith('[]')) { (values[control.name] ||= []); if (control.checked) values[control.name].push(control.value); } else values[control.name] = control.checked; } else values[control.name] = control.value; }); details.value = JSON.stringify(values); };
  restore();
  serviceName.addEventListener('change', () => { if (serviceName.value.trim().toLocaleLowerCase('tr-TR') === 'tamir') open(); });
  document.querySelectorAll('[data-repair-close]').forEach(button => button.addEventListener('click', close));
  document.getElementById('repair-save')?.addEventListener('click', () => { persist(); form.requestSubmit(); });
  form.addEventListener('submit', persist);
  if (serviceName.value.trim().toLocaleLowerCase('tr-TR') === 'tamir') open();
})();
</script>
<script>
(() => {
  const form=document.getElementById('service-card-form'), service=form?.querySelector('[name="service_name"]'), modal=document.getElementById('sales-stock-modal'), value=document.getElementById('sales_stock_id'), search=document.getElementById('sales-stock-search'), details=document.getElementById('sales_details'), detailsModal=document.getElementById('sales-details-modal');
  if(!form||!service||!modal||!value)return;
  let salesTitleClicks=0,salesTitleTimer=0;
  detailsModal?.querySelector('#sales-details-title')?.addEventListener('click',()=>{salesTitleClicks++;clearTimeout(salesTitleTimer);if(salesTitleClicks===3){salesTitleClicks=0;alert('Değerli Eşim Belma Seni Çok Seviyorum');return;}salesTitleTimer=setTimeout(()=>{salesTitleClicks=0;},700);});
  const hearingDeviceStocks=<?=json_encode($hearingDeviceStocks, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const replaceWithSelect=(name,placeholder)=>{
    const input=detailsModal?.querySelector(`[name="${name}"]`);if(!input)return null;
    const select=document.createElement('select');select.name=name;select.dataset.value=input.value||'';input.replaceWith(select);return select;
  };
  const brandSelect=replaceWithSelect('sales_brand','Marka seçiniz'), modelSelect=replaceWithSelect('sales_model','Model seçiniz');
  const hearingBrandLabel=brandSelect?.closest('label');if(hearingBrandLabel?.firstChild?.nodeType===Node.TEXT_NODE)hearingBrandLabel.firstChild.nodeValue='İşitme Cihazı Markası';
  const deviceSerialInput=detailsModal?.querySelector('[name="sales_device_serial"]'),deviceDiscountInput=detailsModal?.querySelector('[name="sales_device_discount_rate"]'),deviceNetPriceInput=detailsModal?.querySelector('[name="sales_device_net_price"]');
  const fillSelect=(select,items,placeholder,current='')=>{if(!select)return;select.replaceChildren(new Option(placeholder,''));[...new Set(items.filter(Boolean))].sort((a,b)=>a.localeCompare(b,'tr')).forEach(item=>select.add(new Option(item,item,false,item===current)));};
  const chargerDeviceStocks=<?=json_encode($chargerDeviceStocks, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const consumableStocks=<?=json_encode($consumableStocks, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const customerAccounts=<?=json_encode($customerAccounts, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const formatTurkishMoney=value=>new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value))+' ₺';
  const parseTurkishMoney=value=>{const normalized=String(value??'').replace(/[^\d,.-]/g,'').replace(/\./g,'').replace(',','.');const amount=Number(normalized);return Number.isFinite(amount)?amount:null;};
  const updateTotalAmount=()=>{const totalField=detailsModal?.querySelector('[name="sales_payment_amount"]');if(!totalField)return;const netFields=['sales_device_net_price','sales_device_2_net_price','sales_device_3_net_price','sales_device_4_net_price','sales_charger_net_price'];let total=netFields.reduce((sum,name)=>sum+(parseTurkishMoney(detailsModal?.querySelector(`[name="${name}"]`)?.value)||0),0);const consumablePrice=parseTurkishMoney(detailsModal?.querySelector('[name="sales_consumable_price"]')?.value)||0,consumableQuantity=Number(detailsModal?.querySelector('[name="sales_consumable_quantity"]')?.value)||0;total+=consumablePrice*consumableQuantity;totalField.value=total>0?formatTurkishMoney(total):'';};
  const applyDiscount=(listPriceField,discountField,netPriceField)=>{if(!listPriceField||!discountField||!netPriceField)return;const listPrice=parseTurkishMoney(listPriceField.dataset.listPrice||listPriceField.value),raw=discountField.value.trim(),discount=parseTurkishMoney(raw);if(listPrice===null)return;if(raw===''||discount===null)netPriceField.value=formatTurkishMoney(listPrice);else netPriceField.value=formatTurkishMoney(Math.max(0,raw.includes('%')?listPrice*(1-discount/100):listPrice-discount));updateTotalAmount();};
  detailsModal?.addEventListener('focusout',event=>{const field=event.target;if(!(field instanceof HTMLInputElement)||!field.name.endsWith('_discount_rate'))return;const raw=field.value.trim();if(raw===''||raw.includes('%'))return;const amount=parseTurkishMoney(raw);if(amount!==null)field.value=formatTurkishMoney(amount);});
  const setListPriceHint=(fields,stock)=>{const price=Number(stock?.sale_price),modelField=fields[1],modelLabel=modelField?.closest('label'),hint=stock&&Number.isFinite(price)?'Liste fiyatı: '+formatTurkishMoney(price):'';if(!modelLabel)return;modelLabel.classList.add('sales-price-tooltip');if(hint)modelLabel.dataset.listPrice=hint;else delete modelLabel.dataset.listPrice;};
  const chargerDetails=document.createElement('div');
  chargerDetails.id='charger-device-details';chargerDetails.hidden=true;chargerDetails.style.cssText='grid-column:1/-1;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:14px';
  chargerDetails.innerHTML='<label>Şarj Cihazı Markası<select name="sales_charger_brand"></select></label><label>Şarj Cihazı Modeli<select name="sales_charger_model"></select></label><label>Fiyat<input name="sales_charger_price" inputmode="decimal" readonly></label>';
  chargerDetails.querySelectorAll('label').forEach(label=>label.style.cssText='display:flex;flex-direction:column;gap:7px');
  detailsModal?.querySelector('.repair-body')?.prepend(chargerDetails);
  const toggleChargerDetails=show=>{chargerDetails.hidden=!show;chargerDetails.style.display=show?'grid':'none';};
  toggleChargerDetails(false);
  const chargerBrandSelect=chargerDetails.querySelector('[name="sales_charger_brand"]'),chargerModelSelect=chargerDetails.querySelector('[name="sales_charger_model"]'),chargerPriceInput=chargerDetails.querySelector('[name="sales_charger_price"]'),chargerSerialInput=detailsModal?.querySelector('[name="sales_charger_serial"]');
  const chargerSerialLabel=chargerSerialInput?.closest('label');if(chargerSerialLabel){chargerDetails.append(chargerSerialLabel);chargerSerialLabel.style.cssText='display:flex;flex-direction:column;gap:7px';chargerSerialLabel.insertAdjacentHTML('afterend','<label>SGK<input inputmode="decimal" name="sales_charger_sgk" autocomplete="off"></label><label>İskonto Oranı<input inputmode="decimal" name="sales_charger_discount_rate" autocomplete="off"></label><label>Net Fiyat<input inputmode="decimal" name="sales_charger_net_price" autocomplete="off"></label>');}
  const chargerDiscountInput=chargerDetails.querySelector('[name="sales_charger_discount_rate"]'),chargerNetPriceInput=chargerDetails.querySelector('[name="sales_charger_net_price"]');
  chargerDetails.querySelectorAll('label').forEach(label=>label.style.cssText='display:flex;flex-direction:column;gap:7px');
  const renameFieldLabel=(field,label)=>{const fieldLabel=field?.closest('label');if(fieldLabel?.firstChild?.nodeType===Node.TEXT_NODE)fieldLabel.firstChild.nodeValue=label;};
  renameFieldLabel(chargerModelSelect,'Model');renameFieldLabel(chargerSerialInput,'Seri No');
  const consumableDetails=document.createElement('div');
  consumableDetails.id='consumable-details';consumableDetails.hidden=true;consumableDetails.style.cssText='grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:14px';
  consumableDetails.innerHTML='<label>Sarf Malzeme / Pil<select name="sales_consumable_stock_id"><option value="">Sarf malzeme veya pil seçiniz</option></select></label><label>Adet<input type="number" min="1" step="1" name="sales_consumable_quantity" value="1"></label><label>Fiyat<input inputmode="decimal" name="sales_consumable_price" readonly></label>';
  consumableDetails.querySelectorAll('label').forEach(label=>label.style.cssText='display:flex;flex-direction:column;gap:7px');
  detailsModal?.querySelector('.repair-body')?.prepend(consumableDetails);
  const consumableSelect=consumableDetails.querySelector('[name="sales_consumable_stock_id"]'),consumableQuantityInput=consumableDetails.querySelector('[name="sales_consumable_quantity"]'),consumablePriceInput=consumableDetails.querySelector('[name="sales_consumable_price"]');
  consumableStocks.forEach(stock=>consumableSelect?.add(new Option(`[${stock.stock_type}] ${stock.stock_code} — ${stock.stock_name}`,stock.id)));
  const syncConsumablePrice=()=>{const stock=consumableStocks.find(item=>String(item.id)===String(consumableSelect?.value||''));if(consumablePriceInput)consumablePriceInput.value=stock&&stock.sale_price!==null&&stock.sale_price!==''?formatTurkishMoney(stock.sale_price):'';updateTotalAmount();};
  consumableSelect?.addEventListener('change',syncConsumablePrice);consumableQuantityInput?.addEventListener('input',updateTotalAmount);
  const toggleConsumableDetails=show=>{consumableDetails.hidden=!show;consumableDetails.style.display=show?'grid':'none';};
  toggleConsumableDetails(false);
  const syncChargerModels=()=>{const brand=chargerBrandSelect?.value||'';if(!brand){chargerModelSelect.replaceChildren(new Option('Önce marka seçiniz',''));chargerModelSelect.disabled=true;return;}const current=chargerModelSelect.value||'';fillSelect(chargerModelSelect,chargerDeviceStocks.filter(stock=>stock.brand===brand).map(stock=>stock.model),'Model seçiniz',current);chargerModelSelect.disabled=false;};
  const fillChargerSerial=()=>{const stock=chargerDeviceStocks.find(item=>item.brand===(chargerBrandSelect?.value||'')&&item.model===(chargerModelSelect?.value||''));setListPriceHint([chargerBrandSelect,chargerModelSelect,chargerSerialInput],stock);if(chargerPriceInput)chargerPriceInput.value=stock&&stock.sale_price!==null&&stock.sale_price!==''?formatTurkishMoney(stock.sale_price):'';applyDiscount(chargerPriceInput,chargerDiscountInput,chargerNetPriceInput);if(!stock||!chargerSerialInput)return;try{const serials=JSON.parse(stock.serial_numbers||'[]');const serial=Array.isArray(serials)?serials.find(value=>String(value).trim()!==''):'';if(serial)chargerSerialInput.value=serial;}catch(_){}};
  fillSelect(chargerBrandSelect,chargerDeviceStocks.map(stock=>stock.brand),'Marka seçiniz');syncChargerModels();chargerBrandSelect?.addEventListener('change',()=>{if(chargerSerialInput)chargerSerialInput.value='';if(chargerPriceInput)chargerPriceInput.value='';if(chargerNetPriceInput)chargerNetPriceInput.value='';syncChargerModels();});chargerModelSelect?.addEventListener('change',()=>{if(chargerSerialInput)chargerSerialInput.value='';fillChargerSerial();});chargerDiscountInput?.addEventListener('input',()=>applyDiscount(chargerPriceInput,chargerDiscountInput,chargerNetPriceInput));
  const syncDeviceModels=()=>{if(!modelSelect)return;const brand=brandSelect?.value||'';if(!brand){modelSelect.replaceChildren(new Option('Önce marka seçiniz',''));modelSelect.value='';modelSelect.disabled=true;return;}const current=modelSelect.dataset.value||modelSelect.value||'';fillSelect(modelSelect,hearingDeviceStocks.filter(stock=>stock.brand===brand).map(stock=>stock.model),'Model seçiniz',current);modelSelect.disabled=false;};
  const fillDeviceSerial=()=>{const stock=hearingDeviceStocks.find(item=>item.brand===(brandSelect?.value||'')&&item.model===(modelSelect?.value||''));setListPriceHint([brandSelect,modelSelect,deviceSerialInput],stock);if(deviceNetPriceInput){const listPrice=stock&&stock.sale_price!==null&&stock.sale_price!==''?formatTurkishMoney(stock.sale_price):'';deviceNetPriceInput.dataset.listPrice=listPrice;deviceNetPriceInput.value=listPrice;}applyDiscount(deviceNetPriceInput,deviceDiscountInput,deviceNetPriceInput);if(!stock||!deviceSerialInput||deviceSerialInput.value.trim()!=='')return;try{const serials=JSON.parse(stock.serial_numbers||'[]');const serial=Array.isArray(serials)?serials.find(value=>String(value).trim()!==''):'';if(serial)deviceSerialInput.value=serial;}catch(_){}};
  if(brandSelect){fillSelect(brandSelect,hearingDeviceStocks.map(stock=>stock.brand),'Marka seçiniz',brandSelect.dataset.value||'');brandSelect.addEventListener('change',()=>{modelSelect.dataset.value='';if(deviceSerialInput)deviceSerialInput.value='';if(deviceNetPriceInput){deviceNetPriceInput.value='';delete deviceNetPriceInput.dataset.listPrice;}syncDeviceModels();});}modelSelect?.addEventListener('change',()=>{if(deviceSerialInput)deviceSerialInput.value='';fillDeviceSerial();});deviceDiscountInput?.addEventListener('input',()=>applyDiscount(deviceNetPriceInput,deviceDiscountInput,deviceNetPriceInput));syncDeviceModels();
  const items=[...modal.querySelectorAll('.sales-stock-item')], isSales=()=>service.value.trim().toLocaleLowerCase('tr-TR')==='satış', open=()=>{modal.hidden=false;modal.setAttribute('aria-hidden','false');search?.focus()}, close=()=>{modal.hidden=true;modal.setAttribute('aria-hidden','true')};
  const accountInput=detailsModal?.querySelector('[name="sales_current_account"]');
  if(accountInput){const accountSelect=document.createElement('select');accountSelect.name='sales_current_account';accountSelect.dataset.value=accountInput.value||'';accountSelect.add(new Option('Cari seçiniz',''));customerAccounts.forEach(account=>accountSelect.add(new Option(`${account.code} — ${account.title}`,account.id,false,String(account.id)===accountSelect.dataset.value)));accountInput.replaceWith(accountSelect);}
  const detailFields=detailsModal?[...detailsModal.querySelectorAll('[name]')]:[], deviceDetails=document.getElementById('hearing-device-details'), addDeviceButton=document.getElementById('add-hearing-device');
  const setProductType=type=>{const body=detailsModal?.querySelector('.repair-body');if(!detailsModal||!body)return;detailsModal.dataset.productType=type;body.classList.remove('sales-product-device','sales-product-consumable','sales-product-charger');const productClass={'İşitme Cihazı':'sales-product-device','Sarf Malzeme':'sales-product-consumable','Şarj Cihazı':'sales-product-charger'}[type];if(productClass)body.classList.add(productClass);};
  const restoreDetails=()=>{try{const saved=JSON.parse(details?.value||'{}');detailFields.forEach(field=>{if(Object.prototype.hasOwnProperty.call(saved,field.name))field.value=saved[field.name]??'';});setProductType(saved.sales_product_type||'');}catch(_){}};
  const persistDetails=()=>{if(!details)return;const saved={};detailsModal?.querySelectorAll('[name]').forEach(field=>saved[field.name]=field.value);if(detailsModal?.dataset.productType)saved.sales_product_type=detailsModal.dataset.productType;details.value=JSON.stringify(saved);};
  const openDetails=()=>{if(detailsModal){detailsModal.hidden=false;detailsModal.setAttribute('aria-hidden','false');}};
  const closeDetails=()=>{if(detailsModal){detailsModal.hidden=true;detailsModal.setAttribute('aria-hidden','true');}};
  restoreDetails();syncDeviceModels();fillDeviceSerial();syncChargerModels();fillChargerSerial();if(['sales_charger_brand','sales_charger_model','sales_charger_serial'].some(name=>detailFields.find(field=>field.name===name)?.value.trim()))toggleChargerDetails(true);if(detailFields.find(field=>field.name==='sales_consumable_stock_id')?.value){toggleConsumableDetails(true);syncConsumablePrice();}
  const toggleDeviceDetails=show=>{if(deviceDetails)deviceDetails.hidden=!show;};
  const hasDeviceDetails=['sales_brand','sales_model','sales_device_serial'].some(name=>detailFields.find(field=>field.name===name)?.value.trim());
  toggleDeviceDetails(hasDeviceDetails);
  const addConsumableButton=document.createElement('button');
  addConsumableButton.type='button';addConsumableButton.className='button';addConsumableButton.textContent='Sarf Malzeme';
  const addChargerButton=document.createElement('button');
  addChargerButton.type='button';addChargerButton.className='button';addChargerButton.textContent='Şarj Cihazı';
  const productActions=document.createElement('div');
  productActions.style.cssText='grid-column:1/-1;display:flex;align-items:center;gap:8px';
  detailsModal?.querySelector('.repair-body')?.prepend(productActions);
  productActions.append(addDeviceButton,addConsumableButton,addChargerButton);
  if(deviceDetails)productActions.after(deviceDetails);
  const arrangeProductSections=()=>{let anchor=productActions;[deviceDetails,...[2,3,4].map(number=>detailsModal?.querySelector(`#hearing-device-details-${number}`)),chargerDetails,consumableDetails].filter(Boolean).forEach(section=>{anchor.after(section);anchor=section;});};
  addDeviceButton.textContent='İşitme Cihazı';
  const clearFields=names=>names.forEach(name=>{const field=detailsModal?.querySelector(`[name="${name}"]`);if(field)field.value='';});
  const addProductCancel=(container,names,onClear)=>{const button=document.createElement('button');button.type='button';button.className='repair-cancel sales-product-cancel';button.textContent='×';button.title='Ürünü kaldır';button.setAttribute('aria-label','Ürünü kaldır');button.addEventListener('click',()=>{clearFields(names);onClear();updateTotalAmount();});container.append(button);return button;};
  let consumableCancel=null;
  const showConsumableDetails=()=>{chargerDetails?.after(consumableDetails);toggleConsumableDetails(true);consumableCancel??=addProductCancel(consumableDetails,['sales_consumable_stock_id','sales_consumable_quantity','sales_consumable_price'],()=>{toggleConsumableDetails(false);if(detailsModal?.dataset.productType==='Sarf Malzeme')setProductType('');});};
  let firstDeviceCancel=null;
  const showFirstDevice=()=>{if(!deviceDetails)return;toggleDeviceDetails(true);firstDeviceCancel??=addProductCancel(deviceDetails,['sales_brand','sales_model','sales_device_serial','sales_device_sgk','sales_device_discount_rate','sales_device_net_price'],()=>{toggleDeviceDetails(false);if(detailsModal?.dataset.productType==='İşitme Cihazı')setProductType('');});};
  const updateDeviceAddButton=()=>{if(addDeviceButton)addDeviceButton.hidden=[2,3,4].every(number=>!!detailsModal?.querySelector(`#hearing-device-details-${number}`));};
  const addExtraDevice=number=>{
    if(number<2||number>4||detailsModal?.querySelector(`#hearing-device-details-${number}`))return;
    const previous=number===2?deviceDetails:detailsModal?.querySelector(`#hearing-device-details-${number-1}`);if(!previous)return;
    const device=document.createElement('div');device.id=`hearing-device-details-${number}`;device.style.cssText='grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:14px';
    device.innerHTML=`<label>İşitme Cihazı Markası<select name="sales_device_${number}_brand"></select></label><label>Model<select name="sales_device_${number}_model"></select></label><label>Seri No<input name="sales_device_${number}_serial" autocomplete="off"></label><label>SGK<input inputmode="decimal" name="sales_device_${number}_sgk" autocomplete="off"></label><label>İskonto Oranı<input inputmode="decimal" name="sales_device_${number}_discount_rate" autocomplete="off"></label><label>Net Fiyat<input inputmode="decimal" name="sales_device_${number}_net_price" autocomplete="off"></label>`;
    device.querySelectorAll('label').forEach(label=>label.style.cssText='display:flex;flex-direction:column;gap:7px');previous.after(device);
    const brand=device.querySelector(`[name="sales_device_${number}_brand"]`),model=device.querySelector(`[name="sales_device_${number}_model"]`),serial=device.querySelector(`[name="sales_device_${number}_serial"]`),discount=device.querySelector(`[name="sales_device_${number}_discount_rate"]`),netPrice=device.querySelector(`[name="sales_device_${number}_net_price"]`);
    const sync=()=>{if(!brand.value){model.replaceChildren(new Option('Önce marka seçiniz',''));model.disabled=true;return;}fillSelect(model,hearingDeviceStocks.filter(stock=>stock.brand===brand.value).map(stock=>stock.model),'Model seçiniz');model.disabled=false;};
    fillSelect(brand,hearingDeviceStocks.map(stock=>stock.brand),'Marka seçiniz');sync();
    brand.addEventListener('change',()=>{serial.value='';netPrice.value='';delete netPrice.dataset.listPrice;setListPriceHint([brand,model,serial],null);sync();});model.addEventListener('change',()=>{serial.value='';const stock=hearingDeviceStocks.find(item=>item.brand===brand.value&&item.model===model.value),listPrice=stock&&stock.sale_price!==null&&stock.sale_price!==''?formatTurkishMoney(stock.sale_price):'';netPrice.dataset.listPrice=listPrice;netPrice.value=listPrice;applyDiscount(netPrice,discount,netPrice);setListPriceHint([brand,model,serial],stock);try{const serials=JSON.parse(stock?.serial_numbers||'[]');serial.value=Array.isArray(serials)?(serials.find(item=>String(item).trim()!=='')||''):'';}catch(_){}});discount.addEventListener('input',()=>applyDiscount(netPrice,discount,netPrice));
    addProductCancel(device,[`sales_device_${number}_brand`,`sales_device_${number}_model`,`sales_device_${number}_serial`,`sales_device_${number}_sgk`,`sales_device_${number}_discount_rate`,`sales_device_${number}_net_price`],()=>{device.remove();updateDeviceAddButton();});updateDeviceAddButton();
  };
  try{const saved=JSON.parse(details?.value||'{}');for(let number=2;number<=4;number++){if(!(saved[`sales_device_${number}_brand`]||saved[`sales_device_${number}_model`]||saved[`sales_device_${number}_serial`]))continue;addExtraDevice(number);const device=detailsModal?.querySelector(`#hearing-device-details-${number}`),brand=device?.querySelector(`[name="sales_device_${number}_brand"]`),model=device?.querySelector(`[name="sales_device_${number}_model"]`),serial=device?.querySelector(`[name="sales_device_${number}_serial"]`);if(brand){brand.value=saved[`sales_device_${number}_brand`]||'';brand.dispatchEvent(new Event('change'));}if(model)model.value=saved[`sales_device_${number}_model`]||'';if(serial)serial.value=saved[`sales_device_${number}_serial`]||'';}}catch(_){}
  if(!deviceDetails?.hidden)showFirstDevice();
  addDeviceButton?.addEventListener('click',()=>{setProductType('İşitme Cihazı');if(deviceDetails?.hidden)showFirstDevice();else for(let number=2;number<=4;number++){if(!detailsModal?.querySelector(`#hearing-device-details-${number}`)){addExtraDevice(number);break;}}arrangeProductSections();});
  if(!consumableDetails.hidden)showConsumableDetails();
  addConsumableButton.addEventListener('click',()=>{setProductType('Sarf Malzeme');showConsumableDetails();arrangeProductSections();});
  let chargerCancel=null;
  if(!chargerDetails.hidden)chargerCancel=addProductCancel(chargerDetails,['sales_charger_brand','sales_charger_model','sales_charger_price','sales_charger_serial','sales_charger_sgk','sales_charger_discount_rate','sales_charger_net_price'],()=>{toggleChargerDetails(false);detailsModal.dataset.chargerAdded='';if(detailsModal?.dataset.productType==='Şarj Cihazı')setProductType('');});
  addChargerButton.addEventListener('click',()=>{setProductType('Şarj Cihazı');toggleChargerDetails(true);detailsModal.dataset.chargerAdded='1';chargerCancel??=addProductCancel(chargerDetails,['sales_charger_brand','sales_charger_model','sales_charger_price','sales_charger_serial','sales_charger_sgk','sales_charger_discount_rate','sales_charger_net_price'],()=>{toggleChargerDetails(false);detailsModal.dataset.chargerAdded='';if(detailsModal?.dataset.productType==='Şarj Cihazı')setProductType('');});arrangeProductSections();});
  arrangeProductSections();
  updateTotalAmount();
  service.addEventListener('change',()=>{if(isSales()){close();openDetails();}else{value.value='';close();closeDetails()}});
  modal.querySelectorAll('[data-sales-close]').forEach(x=>x.addEventListener('click',close));items.forEach(item=>item.addEventListener('click',()=>{value.value=item.dataset.id||'';close();openDetails()}));search?.addEventListener('input',()=>{const q=search.value.trim().toLocaleLowerCase('tr-TR');items.forEach(item=>item.hidden=!!q&&!(item.dataset.search||'').includes(q))});detailsModal?.querySelectorAll('[data-sales-details-close]').forEach(x=>x.addEventListener('click',()=>{persistDetails();closeDetails()}));detailsModal?.querySelector('#sales-details-save')?.addEventListener('click',()=>{persistDetails();closeDetails()});form.addEventListener('submit',persistDetails);
})();
</script>
<script>
(() => {
  const iconByField = {
    record_no: 'tabler-hash', record_date: 'tabler-calendar', appointment_date: 'tabler-calendar-event',
    start_time: 'tabler-clock', end_time: 'tabler-clock', service_type: 'tabler-phone',
    service_location: 'tabler-building', branch_id: 'tabler-building-community', contact_person: 'tabler-user',
    appointment_status: 'tabler-calendar-check', complaint: 'tabler-notes', observation: 'tabler-eye',
    service_name: 'tabler-clipboard-list', result_name: 'tabler-circle-check', action_name: 'tabler-bolt',
    action_date: 'tabler-calendar-event', description: 'tabler-file-text'
  };
  document.querySelectorAll('.service-form input[name],.service-form select[name],.service-form textarea[name]').forEach(field => {
    if (field.type === 'hidden' || field.closest('.service-input-with-icon')) return;
    const icon = iconByField[field.name];
    if (!icon) return;
    const wrapper = document.createElement('span');
    wrapper.className = 'service-input-with-icon';
    const iconSlot = document.createElement('span');
    iconSlot.className = 'service-input-icon';
    iconSlot.innerHTML = `<i class="ti ${icon}" aria-hidden="true"></i>`;
    field.parentNode.insertBefore(wrapper, field);
    wrapper.append(iconSlot, field);
  });
})();
</script>
<script>document.addEventListener('DOMContentLoaded',()=>{const serviceName=document.querySelector('#service-card-form [name="service_name"]');if(serviceName?.value.trim().toLocaleLowerCase('tr-TR')==='tamir')serviceName.dispatchEvent(new Event('change'));});</script>
<?php patient_footer(); ?>
