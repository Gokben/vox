<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/source-bootstrap.php';
require __DIR__ . '/service-type-bootstrap.php';
require __DIR__ . '/service-name-bootstrap.php';
require __DIR__ . '/service-action-bootstrap.php';
require __DIR__ . '/complaint-bootstrap.php';
require __DIR__ . '/cash-bootstrap.php';
require __DIR__ . '/bank-bootstrap.php';
require __DIR__ . '/employee-patient-link.php';
require __DIR__ . '/patient-layout.php';

$pdo = db();
ensure_cash_schema($pdo);
$bankDefinitions = array_values(array_filter(bank_definitions(), static fn(array $bank): bool => (int)$bank['active'] === 1));
$mailOrderAccounts = [];
try {
    $mailOrderAccounts = $pdo->query("SELECT id,code,title,COALESCE(short_name,'') AS short_name FROM current_accounts ORDER BY title")->fetchAll();
} catch (Throwable $exception) {
}
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
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS stock_price_lists (id INTEGER PRIMARY KEY AUTOINCREMENT, brand VARCHAR(190) NOT NULL, valid_from DATE NOT NULL, valid_until DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS stock_price_lists (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, brand VARCHAR(190) NOT NULL, valid_from DATE NOT NULL, valid_until DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS stock_price_list_items (price_list_id INTEGER NOT NULL, stock_id INTEGER NOT NULL, list_price DECIMAL(12,2) NOT NULL DEFAULT 0, PRIMARY KEY(price_list_id,stock_id))'
    : 'CREATE TABLE IF NOT EXISTS stock_price_list_items (price_list_id INT UNSIGNED NOT NULL, stock_id INT UNSIGNED NOT NULL, list_price DECIMAL(12,2) NOT NULL DEFAULT 0, PRIMARY KEY(price_list_id,stock_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$stockCards = $pdo->query('SELECT id,stock_code,stock_name,brand,model,stock_type FROM stock_cards ORDER BY stock_name,stock_code')->fetchAll();
$stockPriceItems = $pdo->query('SELECT i.stock_id,i.list_price,l.valid_from,l.valid_until,l.id AS price_list_id FROM stock_price_list_items i INNER JOIN stock_price_lists l ON l.id=i.price_list_id ORDER BY l.valid_from DESC,l.id DESC')->fetchAll();
$hearingDeviceStatement = $pdo->prepare("SELECT s.id,s.brand,s.model,s.sale_price,(SELECT m.serial_numbers FROM stock_movements m WHERE m.stock_id=s.id AND m.movement_type='Giriş' AND COALESCE(m.serial_numbers,'') NOT IN ('','[]') ORDER BY m.movement_date DESC,m.id DESC LIMIT 1) AS serial_numbers FROM stock_cards s INNER JOIN (SELECT stock_id,SUM(CASE WHEN movement_type='Giriş' THEN quantity WHEN movement_type='Çıkış' THEN -quantity ELSE 0 END) AS stock_quantity FROM stock_movements GROUP BY stock_id) q ON q.stock_id=s.id AND q.stock_quantity>=1 WHERE s.stock_type=? AND EXISTS (SELECT 1 FROM stock_movements m WHERE m.stock_id=s.id AND m.movement_type='Giriş' AND COALESCE(m.serial_numbers,'') NOT IN ('','[]')) ORDER BY s.brand,s.model,s.id");
$hearingDeviceStatement->execute(['İşitme Cihazı']);
$hearingDeviceStocks = $hearingDeviceStatement->fetchAll();
$chargerDeviceStatement = $pdo->prepare("SELECT s.id,s.brand,s.model,s.sale_price,(SELECT m.serial_numbers FROM stock_movements m WHERE m.stock_id=s.id AND m.movement_type='Giriş' AND COALESCE(m.serial_numbers,'') NOT IN ('','[]') ORDER BY m.movement_date DESC,m.id DESC LIMIT 1) AS serial_numbers FROM stock_cards s INNER JOIN (SELECT stock_id,SUM(CASE WHEN movement_type='Giriş' THEN quantity WHEN movement_type='Çıkış' THEN -quantity ELSE 0 END) AS stock_quantity FROM stock_movements GROUP BY stock_id) q ON q.stock_id=s.id AND q.stock_quantity>=1 WHERE s.stock_type=? AND EXISTS (SELECT 1 FROM stock_movements m WHERE m.stock_id=s.id AND m.movement_type='Giriş' AND COALESCE(m.serial_numbers,'') NOT IN ('','[]')) ORDER BY s.brand,s.model,s.id");
$chargerDeviceStatement->execute(['Şarj Cihazı']);
$chargerDeviceStocks = $chargerDeviceStatement->fetchAll();
$salesExitSerialStatement = $pdo->query("SELECT s.id,s.stock_type,s.brand,s.model,m.invoice_no,m.serial_numbers FROM stock_movements m INNER JOIN stock_cards s ON s.id=m.stock_id WHERE m.movement_type='Çıkış' AND COALESCE(m.invoice_no,'')<>'' AND COALESCE(m.serial_numbers,'') NOT IN ('','[]') ORDER BY m.id DESC");
$salesExitSerials = $salesExitSerialStatement->fetchAll();
$serialMovementStatement = $pdo->prepare("SELECT m.stock_id,m.movement_type,m.serial_numbers FROM stock_movements m INNER JOIN stock_cards s ON s.id=m.stock_id WHERE s.stock_type IN (?,?) AND COALESCE(m.serial_numbers,'') NOT IN ('','[]') ORDER BY m.id");
$serialMovementStatement->execute(['İşitme Cihazı','Şarj Cihazı']);
$availableSerials = [];
foreach ($serialMovementStatement as $serialMovement) {
    $serialNumbers = json_decode((string)$serialMovement['serial_numbers'], true);
    if (!is_array($serialNumbers)) continue;
    $stockId = (int)$serialMovement['stock_id'];
    $availableSerials[$stockId] ??= [];
    foreach ($serialNumbers as $serialNumber) {
        $serialNumber = trim((string)$serialNumber);
        if ($serialNumber === '') continue;
        if ((string)$serialMovement['movement_type'] === 'Giriş') {
            if (!in_array($serialNumber, $availableSerials[$stockId], true)) $availableSerials[$stockId][] = $serialNumber;
        } else {
            $availableSerials[$stockId] = array_values(array_filter($availableSerials[$stockId], static fn(string $value): bool => $value !== $serialNumber));
        }
    }
}
foreach ($hearingDeviceStocks as &$deviceStock) $deviceStock['serial_numbers'] = json_encode($availableSerials[(int)$deviceStock['id']] ?? [], JSON_UNESCAPED_UNICODE);
unset($deviceStock);
foreach ($chargerDeviceStocks as &$deviceStock) $deviceStock['serial_numbers'] = json_encode($availableSerials[(int)$deviceStock['id']] ?? [], JSON_UNESCAPED_UNICODE);
unset($deviceStock);
$consumableStatement = $pdo->prepare("SELECT s.id,s.stock_code,s.stock_name,s.stock_type,s.sale_price FROM stock_cards s INNER JOIN (SELECT stock_id,SUM(CASE WHEN movement_type='Giriş' THEN quantity WHEN movement_type='Çıkış' THEN -quantity ELSE 0 END) AS stock_quantity FROM stock_movements GROUP BY stock_id) q ON q.stock_id=s.id AND q.stock_quantity>=1 WHERE s.stock_type IN (?,?) ORDER BY s.stock_type,s.stock_name,s.stock_code");
$consumableStatement->execute(['Sarf Malzeme','Pil']);
$consumableStocks = $consumableStatement->fetchAll();
$serviceActions = array_filter(service_action_definitions(), static fn(array $action): bool => (int)$action['active'] === 1);
$repairIssueDefinitions = array_filter(complaint_definitions(), static fn(array $issue): bool => (int)$issue['active'] === 1);
$openIncomeRecord = isset($_GET['open_income_record']);
$openSalesDetails = isset($_GET['open_sales_details']);
$fromSgkList = isset($_GET['from_sgk_list']);
$editId = (int)($_GET['edit'] ?? 0);
if ($openIncomeRecord && !$editId) {
    $latestSaleStatement = $pdo->prepare("SELECT id FROM patient_services WHERE patient_id=? AND service_name='Satış' ORDER BY id DESC LIMIT 1");
    $latestSaleStatement->execute([$id]);
    $editId = (int)$latestSaleStatement->fetchColumn();
}
$showForm = isset($_GET['new']) || $editId > 0;
$serviceCard = [];
if ($editId) {
    $editStatement = $pdo->prepare('SELECT * FROM patient_services WHERE id=? AND patient_id=?');
    $editStatement->execute([$editId, $id]);
    $serviceCard = $editStatement->fetch() ?: [];
    if (!$serviceCard) { http_response_code(404); exit('Hizmet kartı bulunamadı.'); }
}

// Stok çıkışı oluşmuş satıştaki ürünler, iade/iptal işlemi olmadan değiştirilemez.
$saleStockLocked = false;
if ($serviceCard && trim((string)($serviceCard['service_name'] ?? '')) === 'Satış' && trim((string)($serviceCard['record_no'] ?? '')) !== '') {
    $savedProductDetails = json_decode((string)($serviceCard['sales_details'] ?? ''), true);
    if (!is_array($savedProductDetails)) $savedProductDetails = [];
    foreach ($savedProductDetails as $key => $savedValue) {
        if (preg_match('/^sales_(?:brand|model|device(?:_|$)|charger_|consumable_)/', (string)$key) && trim((string)$savedValue) !== '') {
            $saleStockLocked = true;
            break;
        }
    }
    $stockExitStatement = $pdo->prepare("SELECT 1 FROM stock_movements WHERE movement_type='Çıkış' AND description=? LIMIT 1");
    $stockExitStatement->execute(['Hizmet kartı satışı: ' . trim((string)$serviceCard['record_no'])]);
    $saleStockLocked = $saleStockLocked || (bool)$stockExitStatement->fetchColumn();
}

// Kasa tahsilatı tamamlanan satışın hizmet türü sonradan değiştirilmemelidir.
$hasCompletedCashTransaction = static function () use ($pdo, $id): bool {
    try {
        $sourceUrl = url('patient-followup.php?id=' . $id);
        $statement = $pdo->prepare("SELECT 1 FROM cash_transactions WHERE source_url=? AND transaction_type='income' LIMIT 1");
        $statement->execute([$sourceUrl]);
        return (bool)$statement->fetchColumn();
    } catch (Throwable $exception) {
        return false;
    }
};
$serviceNameLocked = $editId > 0
    && trim((string)($serviceCard['service_name'] ?? '')) === 'Satış'
    && $hasCompletedCashTransaction();
// Tahsilat yapılmış satışta stok hareketi eksik ya da eski kayıt ayrıntıları
// boş olsa bile son ürün kalemi silinemez.
$saleProductDeleteLocked = $serviceNameLocked;
$savedCashPaymentType = '';
$savedCashRecord = [];
$savedCashRecords = [];
if (trim((string)($serviceCard['service_name'] ?? '')) === 'Satış') {
    try {
        $cashPaymentStatement = $pdo->prepare("SELECT id,transaction_date,amount,description,payment_type,installment_count,bank_name,commission_rate,current_account_id,term_schedule FROM cash_transactions WHERE source_url=? AND transaction_type='income' ORDER BY transaction_date,id");
        $cashPaymentStatement->execute([url('patient-followup.php?id=' . $id)]);
        $savedCashRecords = $cashPaymentStatement->fetchAll();
        $savedCashRecord = $savedCashRecords[0] ?? [];
        $savedCashPaymentType = match ((string)($savedCashRecord['payment_type'] ?? '')) {
            'cash' => 'Nakit', 'credit_card' => 'Kredi Kartı', 'mail_order' => 'Mail Order', 'term' => 'Vadeli', default => '',
        };
    } catch (Throwable $exception) {
        $savedCashPaymentType = '';
        $savedCashRecord = [];
    }
}
$savedSalesDetailsForIncome = json_decode((string)($serviceCard['sales_details'] ?? ''), true);
if (!is_array($savedSalesDetailsForIncome)) $savedSalesDetailsForIncome = [];
$hasSelectedSalesPaymentType = trim((string)($savedSalesDetailsForIncome['sales_payment_type'] ?? '')) !== '';
$showIncomeRecordButton = $editId > 0
    && trim((string)($serviceCard['service_name'] ?? '')) === 'Satış'
    && $hasSelectedSalesPaymentType
    && count($savedCashRecords) === 0;
$showSalesDetailsButton = $showForm;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    $postedEditId = (int)($_POST['edit_id'] ?? 0);
    $cashDeleteId = (int)($_POST['cash_delete_id'] ?? 0);
    if ($action === 'cash_delete_only' && $cashDeleteId) {
        $cashDeleteStatement = $pdo->prepare("DELETE FROM cash_transactions WHERE id=? AND transaction_type='income' AND source_url=?");
        $cashDeleteStatement->execute([$cashDeleteId, url('patient-followup.php?id=' . $id)]);
        redirect('patient-followup.php?id=' . $id . '&edit=' . $postedEditId . '&open_income_record=1');
    }
    if ($action === 'cash_cancel_income' && $postedEditId > 0) {
        $cancelIncomeStatement = $pdo->prepare("DELETE FROM cash_transactions WHERE transaction_type='income' AND source_url=?");
        $cancelIncomeStatement->execute([url('patient-followup.php?id=' . $id)]);
        redirect('patient-followup.php?id=' . $id . '&edit=' . $postedEditId);
    }
    if ($action === 'cash_term_schedule_only') {
        $cashId = (int)($_POST['cash_id'] ?? 0);
        $plan = trim((string)($_POST['term_schedule'] ?? ''));
        $check = $pdo->prepare("UPDATE cash_transactions SET term_schedule=? WHERE id=? AND transaction_type='income' AND source_url=? AND payment_type='term'");
        $check->execute([$plan ?: null, $cashId, url('patient-followup.php?id=' . $id)]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $check->rowCount() > 0]);
        exit;
    }
    if ($action === 'cash_update_only' && $postedEditId > 0) {
        $saleTotalStatement = $pdo->prepare("SELECT sales_details FROM patient_services WHERE id=? AND patient_id=? AND service_name='Satış'");
        $saleTotalStatement->execute([$postedEditId, $id]);
        $saleTotalDetails = json_decode((string)$saleTotalStatement->fetchColumn(), true);
        if (!is_array($saleTotalDetails)) $saleTotalDetails = [];
        $moneyValue = static function (mixed $value): float {
            $text = preg_replace('/[^0-9,.-]/u', '', (string)$value);
            if (str_contains($text, ',')) $text = str_replace('.', '', $text);
            return (float)str_replace(',', '.', $text);
        };
        $scheduleTotal = static function (string $schedule) use ($moneyValue): float {
            $total = 0.0;
            foreach ((array)json_decode($schedule, true) as $installment) $total += $moneyValue($installment['amount'] ?? 0);
            return $total;
        };
        $saleTotal = $moneyValue($saleTotalDetails['sales_payment_amount'] ?? 0);
        $primaryType = (string)($_POST['cash_update_payment_type'] ?? '');
        $primarySchedule = (string)($_POST['cash_update_term_schedule'] ?? $_POST['term_schedule_json'] ?? '');
        $primaryTotal = $primaryType === 'term' ? $scheduleTotal($primarySchedule) : $moneyValue($_POST['cash_update_amount'] ?? 0);
        $extraType = (string)($_POST['cash_update_extra_payment_type'] ?? '');
        $extraSchedule = (string)($_POST['cash_update_extra_term_schedule'] ?? '');
        $extraTotal = $extraType === 'term' ? $scheduleTotal($extraSchedule) : $moneyValue($_POST['cash_update_extra_amount'] ?? 0);
        if ($saleTotal > 0 && abs(($primaryTotal + $extraTotal) - $saleTotal) > 0.009) {
            $_SESSION['income_validation_error'] = 'Gelir kayıtları toplamı, Satış Bilgileri ekranındaki ' . number_format($saleTotal, 2, ',', '.') . ' ₺ toplam tutara eşit olmalıdır. Lütfen düzeltin ve yeniden kaydedin.';
            $_SESSION['income_validation_draft'] = [
                'payment_type' => $extraType,
                'amount' => (string)($_POST['cash_update_extra_amount'] ?? ''),
                'description' => (string)($_POST['cash_update_extra_description'] ?? ''),
                'installment_count' => (string)($_POST['cash_update_extra_installment_count'] ?? '1'),
                'bank_name' => (string)($_POST['cash_update_extra_bank_name'] ?? ''),
                'commission_rate' => (string)($_POST['cash_update_extra_commission_rate'] ?? ''),
                'current_account_id' => (string)($_POST['cash_update_extra_current_account_id'] ?? ''),
                'term_schedule' => $extraSchedule,
            ];
            redirect('patient-followup.php?id=' . $id . '&edit=' . $postedEditId . '&open_income_record=1');
        }
    }
    $cashUpdateId = (int)($_POST['cash_update_id'] ?? 0);
    if ($cashUpdateId) {
        $cashUpdateDate = trim((string)($_POST['cash_update_date'] ?? ''));
        $cashUpdateDescription = trim((string)($_POST['cash_update_description'] ?? ''));
        $cashUpdateAmount = (float)str_replace(',', '.', (string)($_POST['cash_update_amount'] ?? '0'));
        $cashUpdatePayment = (string)($_POST['cash_update_payment_type'] ?? '');
        $cashUpdateInstallments = max(1, (int)($_POST['cash_update_installment_count'] ?? 1));
        $cashUpdateBank = trim((string)($_POST['cash_update_bank_name'] ?? ''));
        $cashUpdateRate = (float)str_replace(',', '.', (string)($_POST['cash_update_commission_rate'] ?? '0'));
        $cashUpdateTermSchedule = trim((string)($_POST['cash_update_term_schedule'] ?? ''));
        if ($cashUpdateTermSchedule === '') $cashUpdateTermSchedule = trim((string)($_POST['term_schedule_json'] ?? ''));
        if ($cashUpdatePayment !== 'term') $cashUpdateTermSchedule = '';
        if ($cashUpdatePayment === 'term' && $cashUpdateTermSchedule !== '') {
            $cashUpdateAmount = 0.0;
            foreach ((array)json_decode($cashUpdateTermSchedule, true) as $installment) {
                if (!is_array($installment) || empty($installment['paid'])) continue;
                $cashUpdateAmount += (float)str_replace(',', '.', preg_replace('/[^0-9,.-]/u', '', (string)($installment['amount'] ?? '')));
            }
        }
        if ($cashUpdateDate !== '' && $cashUpdateDescription !== '' && ($cashUpdateAmount > 0 || $cashUpdatePayment === 'term') && in_array($cashUpdatePayment, ['cash','credit_card','mail_order','term'], true)) {
            if ($cashUpdatePayment === 'term' && $cashUpdateTermSchedule === '') {
                $cashUpdateStatement = $pdo->prepare("UPDATE cash_transactions SET transaction_date=?,description=?,amount=?,payment_type=?,installment_count=?,bank_name=?,commission_rate=? WHERE id=? AND transaction_type='income'");
                $cashUpdateStatement->execute([$cashUpdateDate, $cashUpdateDescription, $cashUpdateAmount, $cashUpdatePayment, $cashUpdateInstallments, $cashUpdateBank ?: null, $cashUpdateRate ?: null, $cashUpdateId]);
            } else {
                $cashUpdateStatement = $pdo->prepare("UPDATE cash_transactions SET transaction_date=?,description=?,amount=?,payment_type=?,installment_count=?,bank_name=?,commission_rate=?,term_schedule=? WHERE id=? AND transaction_type='income'");
                $cashUpdateStatement->execute([$cashUpdateDate, $cashUpdateDescription, $cashUpdateAmount, $cashUpdatePayment, $cashUpdateInstallments, $cashUpdateBank ?: null, $cashUpdateRate ?: null, $cashUpdateTermSchedule ?: null, $cashUpdateId]);
            }
        }
    }
    $cashUpdateExtraId = (int)($_POST['cash_update_extra_id'] ?? 0);
    if ($cashUpdateExtraId) {
        $cashUpdateExtraDescription = trim((string)($_POST['cash_update_extra_description'] ?? ''));
        $cashUpdateExtraAmount = (float)str_replace(',', '.', (string)($_POST['cash_update_extra_amount'] ?? '0'));
        $cashUpdateExtraPayment = (string)($_POST['cash_update_extra_payment_type'] ?? '');
        $cashUpdateExtraInstallments = max(1, (int)($_POST['cash_update_extra_installment_count'] ?? 1));
        $cashUpdateExtraBank = trim((string)($_POST['cash_update_extra_bank_name'] ?? ''));
        $cashUpdateExtraRate = (float)str_replace(',', '.', (string)($_POST['cash_update_extra_commission_rate'] ?? '0'));
        $cashUpdateExtraAccountId = (int)($_POST['cash_update_extra_current_account_id'] ?? 0);
        $cashUpdateExtraTermSchedule = trim((string)($_POST['cash_update_extra_term_schedule'] ?? ''));
        if ($cashUpdateExtraPayment !== 'term') $cashUpdateExtraTermSchedule = '';
        $cashUpdateExtraValidationAmount = $cashUpdateExtraAmount;
        if ($cashUpdateExtraPayment === 'term' && $cashUpdateExtraTermSchedule !== '') {
            $cashUpdateExtraPlan = json_decode($cashUpdateExtraTermSchedule, true);
            $cashUpdateExtraValidationAmount = 0.0;
            $cashUpdateExtraAmount = 0.0;
            foreach (is_array($cashUpdateExtraPlan) ? $cashUpdateExtraPlan : [] as $installment) {
                $installmentAmountText = preg_replace('/[^0-9,.-]/u', '', (string)($installment['amount'] ?? '0'));
                if (str_contains($installmentAmountText, ',')) $installmentAmountText = str_replace('.', '', $installmentAmountText);
                $installmentAmount = (float)str_replace(',', '.', $installmentAmountText);
                $cashUpdateExtraValidationAmount += $installmentAmount;
                if (!empty($installment['paid'])) $cashUpdateExtraAmount += $installmentAmount;
            }
        }
        if ($cashUpdateExtraDescription !== '' && $cashUpdateExtraValidationAmount > 0 && in_array($cashUpdateExtraPayment, ['cash','credit_card','mail_order','term'], true)) {
            $cashUpdateExtraStatement = $pdo->prepare("UPDATE cash_transactions SET description=?,amount=?,payment_type=?,installment_count=?,bank_name=?,commission_rate=?,current_account_id=?,term_schedule=? WHERE id=? AND transaction_type='income'");
            $cashUpdateExtraStatement->execute([$cashUpdateExtraDescription, $cashUpdateExtraAmount, $cashUpdateExtraPayment, $cashUpdateExtraInstallments, $cashUpdateExtraBank ?: null, $cashUpdateExtraRate ?: null, $cashUpdateExtraAccountId ?: null, $cashUpdateExtraTermSchedule ?: null, $cashUpdateExtraId]);
        }
    } elseif (trim((string)($_POST['cash_update_extra_payment_type'] ?? '')) !== '') {
        $cashUpdateExtraDescription = trim((string)($_POST['cash_update_extra_description'] ?? ''));
        $cashUpdateExtraAmount = (float)str_replace(',', '.', (string)($_POST['cash_update_extra_amount'] ?? '0'));
        $cashUpdateExtraPayment = (string)($_POST['cash_update_extra_payment_type'] ?? '');
        $cashUpdateExtraInstallments = max(1, (int)($_POST['cash_update_extra_installment_count'] ?? 1));
        $cashUpdateExtraBank = trim((string)($_POST['cash_update_extra_bank_name'] ?? ''));
        $cashUpdateExtraRate = (float)str_replace(',', '.', (string)($_POST['cash_update_extra_commission_rate'] ?? '0'));
        $cashUpdateExtraAccountId = (int)($_POST['cash_update_extra_current_account_id'] ?? 0);
        $cashUpdateDate = trim((string)($_POST['cash_update_date'] ?? ''));
        if ($cashUpdateDate !== '' && $cashUpdateExtraDescription !== '' && $cashUpdateExtraAmount > 0 && in_array($cashUpdateExtraPayment, ['cash','credit_card','mail_order','term'], true)) {
            $cashInsertExtraStatement = $pdo->prepare('INSERT INTO cash_transactions(transaction_date,description,transaction_type,amount,payment_type,installment_count,bank_name,commission_rate,current_account_id,source_url,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $cashInsertExtraStatement->execute([$cashUpdateDate, $cashUpdateExtraDescription, 'income', $cashUpdateExtraAmount, $cashUpdateExtraPayment, $cashUpdateExtraInstallments, $cashUpdateExtraBank ?: null, $cashUpdateExtraRate ?: null, $cashUpdateExtraAccountId ?: null, url('patient-followup.php?id=' . $id), (int)($_SESSION['user']['id'] ?? 0)]);
        }
    }
    if ($action === 'cash_update_only') {
        if ((string)($_POST['ajax'] ?? '') === '1') {
            $cashRefreshStatement = $pdo->prepare("SELECT id,transaction_date,amount,description,payment_type,installment_count,bank_name,commission_rate,current_account_id,term_schedule FROM cash_transactions WHERE source_url=? AND transaction_type='income' ORDER BY transaction_date,id");
            $cashRefreshStatement->execute([url('patient-followup.php?id=' . $id)]);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'records' => $cashRefreshStatement->fetchAll()]);
            exit;
        }
        redirect('patient-followup.php?id=' . $id . '&edit=' . $postedEditId . '&open_income_record=1');
    }
    $savedServiceName = '';
    if ($postedEditId) {
        $savedServiceStatement = $pdo->prepare('SELECT service_name FROM patient_services WHERE id=? AND patient_id=?');
        $savedServiceStatement->execute([$postedEditId, $id]);
        $savedServiceName = trim((string)$savedServiceStatement->fetchColumn());
    }
    if ($action === 'delete' && $postedEditId) {
        $pdo->prepare('DELETE FROM patient_services WHERE id=? AND patient_id=?')->execute([$postedEditId, $id]);
        redirect('patient-followup.php?id=' . $id);
    }
    $postedServiceName = trim((string)($_POST['service_name'] ?? ''));
    // Satış penceresinin Kaydet düğmesi bu işareti gönderir; hizmet türü
    // tarayıcıdaki seçim durumundan bağımsız olarak Satış olarak korunur.
    if (isset($_POST['return_to_sales_details']) || isset($_POST['save_sales_details'])) $postedServiceName = 'Satış';
    $postedSalesDetails = json_decode((string)($_POST['sales_details'] ?? ''), true);
    if ($postedServiceName !== 'Satış' && is_array($postedSalesDetails)) {
        foreach ($postedSalesDetails as $key => $value) {
            if (!preg_match('/^sales_(?:brand|model|device_(?:serial|[2-4]_(?:brand|model|serial))|charger_(?:brand|model|serial)|consumable_stock_id|payment_type)$/', (string)$key)) continue;
            if (trim((string)$value) !== '') {
                $postedServiceName = 'Satış';
                break;
            }
        }
    }
    if ($postedEditId) {
        if ($savedServiceName === 'Satış' && $hasCompletedCashTransaction()) {
            $postedServiceName = $savedServiceName;
        }
    }
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
    if ($saleProductDeleteLocked && $postedEditId && $postedServiceName === 'Satış') {
        $savedSalesDetails = json_decode((string)($serviceCard['sales_details'] ?? ''), true);
        $postedSalesDetails = json_decode((string)$values['sales_details'], true);
        if (!is_array($savedSalesDetails)) $savedSalesDetails = [];
        if (!is_array($postedSalesDetails)) $postedSalesDetails = [];
        $productGroup = static function (string $key): ?string {
            if (preg_match('/^sales_device_([2-4])_/', $key, $match)) return 'device_' . $match[1];
            if (preg_match('/^sales_(?:brand|model|device_(?:serial|sgk|discount_rate|net_price))$/', $key)) return 'device_1';
            if (str_starts_with($key, 'sales_charger_')) return 'charger';
            if (str_starts_with($key, 'sales_consumable_')) return 'consumable';
            return null;
        };
        $lockedGroups = [];
        foreach ($savedSalesDetails as $key => $savedValue) {
            $group = $productGroup((string)$key);
            if ($group !== null && trim((string)$savedValue) !== '') $lockedGroups[$group] = true;
        }
        foreach (array_keys($lockedGroups) as $lockedGroup) {
            $hasPostedProduct = false;
            foreach ($postedSalesDetails as $key => $postedValue) {
                if ($productGroup((string)$key) === $lockedGroup && trim((string)$postedValue) !== '') {
                    $hasPostedProduct = true;
                    break;
                }
            }
            if ($hasPostedProduct) continue;
            $remainingProductGroups = [];
            foreach ($postedSalesDetails as $key => $postedValue) {
                $group = $productGroup((string)$key);
                if ($group !== null && trim((string)$postedValue) !== '') $remainingProductGroups[$group] = true;
            }
            // Tahsilat yapılmış satışta son ürün kalemi korunur; diğer kalemler silinebilir.
            if (count($remainingProductGroups) >= 1) continue;
            foreach (array_keys($postedSalesDetails) as $key) {
                if ($productGroup((string)$key) === $lockedGroup) unset($postedSalesDetails[$key]);
            }
            foreach ($savedSalesDetails as $key => $savedValue) {
                if ($productGroup((string)$key) === $lockedGroup) $postedSalesDetails[$key] = $savedValue;
            }
        }
        $values['stock_id'] = $serviceCard['stock_id'] ?? null;
        $values['sales_details'] = json_encode($postedSalesDetails, JSON_UNESCAPED_UNICODE);
    }
    if ($postedServiceName === 'Satış' && trim((string)($_POST['sales_invoice_no'] ?? '')) !== '') {
        $salesDetails = json_decode((string)$values['sales_details'], true);
        if (!is_array($salesDetails)) $salesDetails = [];
        $salesDetails['sales_invoice_no'] = trim((string)$_POST['sales_invoice_no']);
        $values['sales_details'] = json_encode($salesDetails, JSON_UNESCAPED_UNICODE);
    }
    // Satış kaydı, ürün veya ödeme ayrıntısı henüz girilmemiş olsa da korunur.
    if (false && $postedServiceName === 'Satış') {
        $salesDetails = json_decode((string)$values['sales_details'], true);
        if (!is_array($salesDetails)) $salesDetails = [];
        // Sadece yarım kalmış alanlar (ör. tek başına marka seçimi) satış
        // bilgisi sayılmaz. Hizmet adı, gerçek bir ürün veya ödeme bilgisi
        // yoksa tekrar formdaki “Seçiniz” değerine döner.
        $hasProduct = false;
        foreach ([1, 2, 3, 4] as $deviceNumber) {
            $suffix = $deviceNumber === 1 ? '' : '_' . $deviceNumber;
            $deviceKeys = $deviceNumber === 1
                ? ['sales_brand', 'sales_model', 'sales_device_serial']
                : ['sales_brand' . $suffix, 'sales_model' . $suffix, 'sales_device_serial' . $suffix];
            if (array_reduce($deviceKeys, static fn(bool $valid, string $key): bool => $valid && trim((string)($salesDetails[$key] ?? '')) !== '', true)) { $hasProduct = true; break; }
        }
        $hasCharger = trim((string)($salesDetails['sales_charger_brand'] ?? '')) !== ''
            && trim((string)($salesDetails['sales_charger_model'] ?? '')) !== '';
        $hasConsumable = (int)($salesDetails['sales_consumable_stock_id'] ?? 0) > 0
            && (int)($salesDetails['sales_consumable_quantity'] ?? 0) > 0;
        $hasProduct = $hasProduct || $hasCharger || $hasConsumable;
        $paymentType = trim((string)($salesDetails['sales_payment_type'] ?? ''));
        $paymentAmount = (float)str_replace(',', '.', str_replace('.', '', preg_replace('/[^0-9,.-]/u', '', (string)($salesDetails['sales_payment_amount'] ?? ''))));
        $hasPaymentInfo = $paymentType !== '' && $paymentAmount > 0;
        if (!$hasProduct && !$hasPaymentInfo) {
            $values['service_name'] = '';
            $values['stock_id'] = null;
            $values['sales_details'] = null;
        }
    }
    if ($values['record_no'] === '') $values['record_no'] = 'HK' . date('ymdHis');
    $savedServiceId = $postedEditId;
    if ($postedEditId) {
        $set = implode(',', array_map(static fn(string $column): string => $column . '=?', array_keys($values)));
        $pdo->prepare('UPDATE patient_services SET ' . $set . ' WHERE id=? AND patient_id=?')->execute([...array_values($values), $postedEditId, $id]);
    } else {
        $columns = array_merge(['patient_id'], array_keys($values));
        $pdo->prepare('INSERT INTO patient_services (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute([$id, ...array_values($values)]);
        $savedServiceId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE patients SET service_location=NULL WHERE id=?')->execute([$id]);
    }
    // Satış bilgileri ilk defa kaydedildikten sonra da stok çıkışı oluşturulur.
    // Aynı hizmet kartı tekrar kaydedilirse önce eski çıkışlar yenilenir; mükerrer stok düşümü oluşmaz.
    if ($postedServiceName === 'Satış') {
            $salesDetails = json_decode((string)$values['sales_details'], true);
            if (!is_array($salesDetails)) $salesDetails = [];
            $accountId = filter_var($salesDetails['sales_current_account'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $invoiceNo = trim((string)($salesDetails['sales_invoice_no'] ?? ''));
            $movementDate = $values['service_date'] ?: date('Y-m-d');
            $description = 'Hizmet kartı satışı: ' . $values['record_no'];
            $pdo->prepare("DELETE FROM stock_movements WHERE movement_type='Çıkış' AND description=?")->execute([$description]);
            $findStock = $pdo->prepare('SELECT id FROM stock_cards WHERE stock_type=? AND brand=? AND model=? ORDER BY id LIMIT 1');
            $addExit = $pdo->prepare('INSERT INTO stock_movements(stock_id,movement_type,quantity,movement_date,description,current_account_id,invoice_no,serial_numbers) VALUES(?,?,?,?,?,?,?,?)');
            $existingInvoiceSerialExit = $pdo->prepare("SELECT 1 FROM stock_movements WHERE movement_type='Çıkış' AND invoice_no=? AND serial_numbers LIKE ? LIMIT 1");
            $addDeviceExit = static function (string $type, string $brand, string $model, string $serial, int $quantity = 1) use ($findStock, $addExit, $existingInvoiceSerialExit, $movementDate, $description, $accountId, $invoiceNo): void {
                if ($brand === '' || $model === '' || $quantity < 1) return;
                if ($serial !== '' && $invoiceNo !== '') {
                    $existingInvoiceSerialExit->execute([$invoiceNo, '%"' . $serial . '"%']);
                    if ($existingInvoiceSerialExit->fetchColumn()) return;
                }
                $findStock->execute([$type, $brand, $model]);
                $stockId = (int)$findStock->fetchColumn();
                if (!$stockId) return;
                $serialNumbers = $serial === '' ? null : json_encode([$serial], JSON_UNESCAPED_UNICODE);
                $addExit->execute([$stockId, 'Çıkış', $quantity, $movementDate, $description, $accountId, $invoiceNo ?: null, $serialNumbers]);
            };
            $addDeviceExit('İşitme Cihazı', trim((string)($salesDetails['sales_brand'] ?? '')), trim((string)($salesDetails['sales_model'] ?? '')), trim((string)($salesDetails['sales_device_serial'] ?? '')));
            for ($deviceNumber = 2; $deviceNumber <= 4; $deviceNumber++) {
                $addDeviceExit('İşitme Cihazı', trim((string)($salesDetails["sales_device_{$deviceNumber}_brand"] ?? '')), trim((string)($salesDetails["sales_device_{$deviceNumber}_model"] ?? '')), trim((string)($salesDetails["sales_device_{$deviceNumber}_serial"] ?? '')));
            }
            $addDeviceExit('Şarj Cihazı', trim((string)($salesDetails['sales_charger_brand'] ?? '')), trim((string)($salesDetails['sales_charger_model'] ?? '')), trim((string)($salesDetails['sales_charger_serial'] ?? '')));
            $consumableStockId = filter_var($salesDetails['sales_consumable_stock_id'] ?? null, FILTER_VALIDATE_INT);
            $consumableQuantity = max(0, (int)($salesDetails['sales_consumable_quantity'] ?? 0));
            if ($consumableStockId && $consumableQuantity > 0) {
                $addExit->execute([$consumableStockId, 'Çıkış', $consumableQuantity, $movementDate, $description, $accountId, $invoiceNo ?: null, null]);
            }
    }
    if (isset($_POST['return_to_sales_details']) && $savedServiceId > 0) redirect('patient-followup.php?id=' . $id . '&edit=' . $savedServiceId . '&open_sales_details=1');
    redirect('patient-followup.php?id=' . $id);
}

$servicesStatement = $pdo->prepare('SELECT * FROM patient_services WHERE patient_id=? ORDER BY service_date DESC,id DESC');
$servicesStatement->execute([$id]);
$services = $servicesStatement->fetchAll();
$incomeValidationError = (string)($_SESSION['income_validation_error'] ?? '');
$incomeValidationDraft = $_SESSION['income_validation_draft'] ?? [];
if (!is_array($incomeValidationDraft)) $incomeValidationDraft = [];
unset($_SESSION['income_validation_error']);
unset($_SESSION['income_validation_draft']);
patient_header('Hizmetler', 'patients');
if ($incomeValidationError !== ''): ?><script>window.addEventListener('DOMContentLoaded',()=>setTimeout(()=>{const openIncome=()=>{const form=document.querySelector('form[action*="cash.php"]'),modal=form?.parentElement;if(!modal){setTimeout(openIncome,50);return;}modal.hidden=false;modal.style.display='grid';setTimeout(()=>alert(<?=json_encode($incomeValidationError, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>),0);};openIncome();},350));</script><?php endif;
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
.service-form-head{display:flex!important;align-items:center!important;justify-content:space-between!important}.service-form-actions{display:flex!important;align-items:center!important;gap:12px!important}.service-back-link{color:var(--muted)!important;text-decoration:none!important;font-size:14px!important;white-space:nowrap}.service-back-link:hover{color:#19a94b!important}
.service-input-with-icon{display:flex!important;align-items:stretch!important;grid-column:2!important;min-height:40px!important;border:1px solid #d5d3de!important;border-radius:6px!important;background:var(--card)!important;overflow:hidden!important}.service-input-icon{display:grid!important;place-items:center!important;flex:0 0 46px!important;width:46px!important;color:#686574!important;font-size:17px!important}.service-input-with-icon input,.service-input-with-icon select,.service-input-with-icon textarea{width:100%!important;min-width:0!important;height:38px!important;min-height:38px!important;margin:0!important;padding:8px 12px 8px 0!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important}.service-input-with-icon textarea{height:76px!important;padding-top:10px!important}
.service-name-locked{display:flex!important;align-items:center!important;gap:8px!important;grid-column:2!important}.service-name-locked input{grid-column:auto!important;flex:1!important;background:#f4f4f6!important;color:#6d6b78!important;cursor:not-allowed!important}.service-name-income-slot{display:flex!important;align-items:center!important;gap:8px!important;grid-column:2!important;width:100%!important;min-width:0!important}.service-name-income-slot select,.service-name-income-slot>.service-input-with-icon{grid-column:auto!important;flex:1 1 auto!important;width:100%!important;min-width:0!important}.service-detail-button,.sales-details-link{display:inline-grid!important;place-items:center!important;flex:0 0 40px!important;width:40px!important;height:40px!important;padding:0!important;border:0!important;border-radius:6px!important;background:#19a94b!important;color:#fff!important;cursor:pointer!important;font-size:19px!important}.sales-details-link{text-decoration:none!important}.service-detail-button:hover,.sales-details-link:hover{background:#14833d!important}.sales-income-link{display:inline-grid!important;place-items:center!important;flex:0 0 40px!important;width:40px!important;height:40px!important;margin:0!important;padding:0!important;border-radius:6px!important;background:#19a94b!important;color:#fff!important;text-decoration:none!important;font-size:19px!important}.sales-income-link:hover{background:#14833d!important}
.service-detail-button,.sales-details-link{box-sizing:border-box!important;align-self:center!important;flex-basis:36px!important;width:36px!important;min-width:36px!important;max-width:36px!important;height:36px!important;min-height:36px!important;max-height:36px!important;font-size:18px!important}
@media(max-width:720px){.services-page{max-width:none!important;padding:92px 14px 30px!important}.services-head{padding-right:170px!important}.services-head .button{right:16px}.service-form-head{padding-right:16px!important}.action-box .service-field{grid-template-columns:1fr!important}.service-input-with-icon{grid-column:1!important}}
</style>
<style>.service-form footer .button{box-sizing:border-box!important;width:36px!important;min-width:36px!important;max-width:36px!important;height:36px!important;min-height:36px!important;max-height:36px!important;padding:0!important;display:inline-grid!important;place-items:center!important}</style>
<main class="patient-container services-page"><section class="services-card">
<?php if($showForm): ?><header class="services-head service-form-head"><h2><?= $editId ? 'Hizmet Kartı Düzenle' : 'Yeni Hizmet Kartı' ?> - <?=e($patient['full_name'])?></h2><span class="service-form-actions"><a class="service-back-link" href="<?=e(url('patient-followup.php?id='.$id))?>">Listeye dön</a></span></header><form id="service-card-form" class="service-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="edit_id" value="<?=$editId?>"><input type="hidden" id="repair_details" name="repair_details" value="<?=e((string)$form['repair_details'])?>"><input type="hidden" id="sales_stock_id" name="stock_id" value="<?=e((string)($form['stock_id'] ?? ''))?>"><input type="hidden" id="sales_details" name="sales_details" value="<?=e((string)($form['sales_details'] ?? ''))?>">
<label class="service-field">Kayıt No<input name="record_no" value="<?=e((string)$form['record_no'])?>"></label><label class="service-field">Kayıt Tarihi<input type="date" name="record_date" value="<?=e((string)$form['service_date'])?>"></label>
<div class="service-three"><label class="service-field">Randevu Tarihi<input type="date" name="appointment_date" value="<?=e((string)$form['appointment_date'])?>"></label><label class="service-field">Başlangıç Saati<select name="start_time" required><?php for($hour=9;$hour<=19;$hour++):foreach([0,15,30,45] as $minute):if($hour===19&&$minute>0)continue;$time=sprintf('%02d:%02d',$hour,$minute);?><option value="<?=$time?>" <?=((string)$form['start_time']===$time)?'selected':''?>><?=$time?></option><?php endforeach;endfor;?></select></label><label class="service-field">Bitiş Saati<select name="end_time" required><?php for($hour=9;$hour<=19;$hour++):foreach([0,15,30,45] as $minute):if(($hour===9&&$minute<15)||($hour===19&&$minute>0))continue;$time=sprintf('%02d:%02d',$hour,$minute);?><option value="<?=$time?>" <?=((string)$form['end_time']===$time)?'selected':''?>><?=$time?></option><?php endforeach;endfor;?></select></label></div>
<label class="service-field">Hizmet Tipi<select name="service_type"><option value="">Seçiniz</option><?php foreach($serviceCardTypes as $type):?><option value="<?=e($type['name'])?>" <?=((string)$form['service_type']===(string)$type['name'])?'selected':''?>><?=e($type['name'])?></option><?php endforeach?></select></label><label class="service-field">Hizmet Yeri<select name="service_location"><option value="">Seçiniz</option><?php foreach($serviceLocations as $location):?><option value="<?=e($location['name'])?>" <?=((string)$form['service_location']===(string)$location['name'])?'selected':''?>><?=e($location['name'])?></option><?php endforeach?></select></label>
<label class="service-field service-wide">Şube Seçin<select name="branch_id"><option value="">Seçiniz</option><?php foreach($branches as $branch):?><option value="<?=(int)$branch['id']?>" <?=((int)$form['branch_id']===(int)$branch['id'])?'selected':''?>><?=e($branch['name'])?></option><?php endforeach?></select><input type="hidden" name="branch_name" value="<?=e((string)$form['branch_name'])?>"></label>
<label class="service-field">İlgilenen Kişi<select name="contact_person"><option value="">Seçiniz</option><?php foreach($contactPersonOptions as $person):?><option value="<?=e($person)?>" <?=((string)$form['contact_person']===(string)$person)?'selected':''?>><?=e($person)?></option><?php endforeach?></select></label><label class="service-field">Randevu Durumu<select name="appointment_status"><?php foreach(['Beklemede','Onaylandı','Tamamlandı','İptal'] as $status):?><option <?=((string)$form['appointment_status']===$status)?'selected':''?>><?=$status?></option><?php endforeach?></select></label>
<label class="service-field service-wide">Anamnez<textarea name="complaint" placeholder="Anamnez Girin"><?=e((string)$form['complaint'])?></textarea></label><label class="service-field service-wide">Gözlem<textarea name="observation" placeholder="Gözlem Girin"><?=e((string)$form['observation'])?></textarea></label>
<?php if ($serviceNameLocked): ?><label class="service-field">Hizmet Adı<span class="service-name-locked"><input value="<?=e((string)$form['service_name'])?>" readonly aria-label="Kilitli hizmet adı"><input type="hidden" name="service_name" value="<?=e((string)$form['service_name'])?>"><button type="button" class="service-detail-button" id="service-detail-button" title="Satış detayını aç" aria-label="Satış detayını aç"><i class="ti tabler-file-search"></i></button></span></label><?php else: ?><label class="service-field">Hizmet Adı<span class="service-name-income-slot"><select name="service_name"><option value="">Seçiniz</option><?php foreach($serviceNames as $serviceName):?><option value="<?=e($serviceName['name'])?>" <?=((string)$form['service_name']===(string)$serviceName['name'])?'selected':''?>><?=e($serviceName['name'])?></option><?php endforeach?></select><?php if($showSalesDetailsButton): ?><button type="button" class="sales-details-link" id="sales-details-link" title="Satış Kartını Aç" aria-label="Satış Kartını Aç"><i class="ti tabler-file-search"></i></button><?php endif ?></span></label><?php endif; ?><label class="service-field">Sonuç<select name="result_name"><?php foreach(['Beklemede','Onay','Düşünecek','Ret','Tamamlandı','İptal'] as $result):?><option <?=((string)$form['result_name']===$result)?'selected':''?>><?=$result?></option><?php endforeach?></select></label>
<section class="action-box"><label class="service-field">Aksiyon<select name="action_name"><option value="">Seçiniz</option><?php foreach($serviceActions as $serviceAction):?><option value="<?=e($serviceAction['name'])?>" <?=((string)$form['action_name']===(string)$serviceAction['name'])?'selected':''?>><?=e($serviceAction['name'])?></option><?php endforeach?></select></label><label class="service-field">Aksiyon Tarihi<input type="date" name="action_date" value="<?=e((string)$form['action_date'])?>"></label></section>
<div class="satisfaction"><label>Memnuniyet</label><div class="faces"><?php foreach(['🙂','😐','🙁','😡'] as $score=>$face):?><input id="s<?=$score+1?>" type="radio" name="satisfaction" value="<?=$score+1?>" <?=((int)$form['satisfaction']===$score+1)?'checked':''?>><label for="s<?=$score+1?>"><?=$face?></label><?php endforeach?></div></div>
<label class="service-field service-wide">Açıklama<textarea name="description"><?=e((string)$form['description'])?></textarea></label><footer><button class="button"><?=$editId ? 'Güncelle' : 'Kaydet'?></button><a class="cancel-link" href="<?=e(url('patient-followup.php?id='.$id))?>">İptal</a></footer></form>
<script>(()=>{const iconByField={record_no:'tabler-hash',record_date:'tabler-calendar',appointment_date:'tabler-calendar-event',start_time:'tabler-clock',end_time:'tabler-clock',service_type:'tabler-phone',service_location:'tabler-building',branch_id:'tabler-building-community',contact_person:'tabler-user',appointment_status:'tabler-calendar-check',complaint:'tabler-notes',observation:'tabler-eye',service_name:'tabler-clipboard-list',result_name:'tabler-circle-check',action_name:'tabler-bolt',action_date:'tabler-calendar-event',description:'tabler-file-text'};document.querySelectorAll('.service-form input[name],.service-form select[name],.service-form textarea[name]').forEach(field=>{if(field.type==='hidden'||field.closest('.service-input-with-icon'))return;const icon=iconByField[field.name];if(!icon)return;const wrapper=document.createElement('span');wrapper.className='service-input-with-icon';const iconSlot=document.createElement('span');iconSlot.className='service-input-icon';iconSlot.innerHTML=`<i class="ti ${icon}" aria-hidden="true"></i>`;field.parentNode.insertBefore(wrapper,field);wrapper.append(iconSlot,field);});})();</script>
<script>document.addEventListener('DOMContentLoaded',()=>{const openSalesDetails=()=>{const modal=document.getElementById('sales-details-modal');if(!modal)return;modal.hidden=false;modal.setAttribute('aria-hidden','false');};document.getElementById('service-detail-button')?.addEventListener('click',openSalesDetails);document.getElementById('sales-details-link')?.addEventListener('click',openSalesDetails);});</script>
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
.repair-modal[hidden]{display:none!important}.repair-modal{position:fixed;z-index:1000;inset:0;display:grid;place-items:center;padding:20px}.repair-modal-backdrop{position:absolute;inset:0;background:rgba(32,33,45,.5)}.repair-dialog{position:relative;width:min(760px,100%);max-height:calc(100vh - 40px);overflow:auto;border-radius:10px;background:#fff;box-shadow:0 18px 46px rgba(0,0,0,.28)}.repair-dialog>header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid #e1e2e8}.repair-dialog h2{margin:0;font-size:18px;color:#2f2b3d}.repair-dialog h2 .ti{vertical-align:-2px;margin-right:7px}.repair-close{border:0;background:transparent;color:#8b8a95;font-size:30px;line-height:1;cursor:pointer}.repair-body{display:grid;gap:14px;padding:20px 24px}.repair-body>label,.repair-body fieldset{display:flex;flex-direction:column;gap:7px;color:#2f2b3d;font-size:14px}.repair-body small{color:#8b8a95;font-weight:400}.repair-body input:not([type=checkbox]),.repair-body select,.repair-body textarea{box-sizing:border-box;width:100%;min-height:38px;padding:8px 11px;border:1px solid #d5d3de;border-radius:6px;background:#fff;font:inherit;color:#2f2b3d}.repair-body textarea{min-height:70px;resize:vertical}.repair-check{font-size:13px;font-weight:400}.repair-body fieldset{margin:0;padding:0;border:0}.repair-body fieldset>label{display:inline-flex;align-items:center;gap:6px;margin-right:12px;font-size:14px}.repair-issues{border:1px solid #e1e2e8!important;border-radius:6px!important;padding:10px!important;max-height:205px;overflow:auto}.repair-issues>label,.repair-issue-head{display:grid;grid-template-columns:1fr 120px 120px;align-items:center;gap:8px;padding:5px 0}.repair-issues input{justify-self:start;width:16px;height:16px}.repair-issue-head{color:#8b8a95;font-size:13px}.repair-switch{display:flex!important;flex-direction:row!important;align-items:center;gap:8px}.repair-switch input{width:38px;height:21px;accent-color:#19a94b}.repair-grid{display:grid;grid-template-columns:1fr 1.4fr;gap:10px}.repair-grid label{display:flex;flex-direction:column;gap:7px;font-size:14px}.repair-dialog>footer{display:flex;justify-content:flex-end;gap:10px;padding:16px 24px 20px}.repair-cancel{border:1px solid #d5d3de;border-radius:6px;padding:10px 16px;background:#fff;color:#5d5b6d;cursor:pointer}form[action*="cash.php"]>footer button{display:inline-grid!important;place-items:center!important;box-sizing:border-box!important;width:36px!important;min-width:36px!important;max-width:36px!important;height:36px!important;min-height:36px!important;max-height:36px!important;padding:0!important}@media(max-width:620px){.repair-modal{padding:8px}.repair-body,.repair-dialog>header,.repair-dialog>footer{padding-left:16px;padding-right:16px}.repair-issues>label,.repair-issue-head{grid-template-columns:1fr 70px 70px}.repair-grid{grid-template-columns:1fr}}
</style>
<?php if($showForm): ?>
<style>
.repair-body .repair-issues>label,.repair-body .repair-issues>.repair-issue-head{display:grid!important;grid-template-columns:minmax(0,1fr) 120px 120px!important;align-items:center!important;gap:8px!important;width:100%!important;margin:0!important}.repair-body .repair-issues>label>input{justify-self:center!important;margin:0!important}.repair-body .repair-issues>.repair-issue-head>span:not(:first-child){text-align:center!important}.sales-details-dialog{width:min(920px,100%)}.sales-details-dialog .repair-body{grid-template-columns:repeat(3,minmax(0,1fr))}.sales-details-dialog>footer button{box-sizing:border-box!important;width:36px!important;min-width:36px!important;max-width:36px!important;height:36px!important;min-height:36px!important;max-height:36px!important;padding:0!important}.sales-device-button{grid-column:1/-1;justify-self:start}.sales-device-details{position:relative;display:grid;grid-column:1/-1;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.sales-device-details[hidden]{display:none}#charger-device-details,#consumable-details{position:relative}.sales-product-cancel{position:absolute;z-index:1;top:40px!important;right:-12px!important;display:grid;box-sizing:border-box;place-items:center;width:20px!important;min-width:20px!important;max-width:20px!important;height:20px!important;min-height:20px!important;max-height:20px!important;margin:0;padding:0!important;border:0;border-radius:50%;background:#ea5455;color:#fff;font-size:18px;line-height:1}.sales-product-cancel[hidden]{display:none!important}.sales-list-price{color:#ea5455!important;font-weight:700!important}.sales-details-dialog .repair-body>label,.sales-details-dialog .repair-body .sales-device-details>label,.sales-details-dialog .repair-body #hearing-device-details-2>label,.sales-details-dialog .repair-body #charger-device-details>label{border:3px solid #fff;border-radius:7px;padding:9px}.sales-details-dialog .repair-body .sales-device-details>label>input,.sales-details-dialog .repair-body .sales-device-details>label>select,.sales-details-dialog .repair-body #hearing-device-details-2>label>input,.sales-details-dialog .repair-body #hearing-device-details-2>label>select{border:3px solid #159447}.sales-details-dialog .repair-body #charger-device-details>label>input,.sales-details-dialog .repair-body #charger-device-details>label>select{border:3px solid #795548}@media(max-width:620px){.sales-details-dialog .repair-body,.sales-device-details{grid-template-columns:1fr}.sales-product-cancel{top:40px!important;right:4px!important}}
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
<div id="sales-details-modal" class="repair-modal" hidden aria-hidden="true"><div class="repair-modal-backdrop" data-sales-details-close></div><section class="repair-dialog sales-details-dialog" role="dialog" aria-modal="true" aria-labelledby="sales-details-title"><header><h2 id="sales-details-title">Satış Bilgileri</h2><button type="button" class="repair-close" data-sales-details-close aria-label="Kapat">×</button></header><div class="repair-body"><button type="button" id="add-hearing-device" class="button sales-device-button">+ İşitme Cihazı Ekle</button><div id="hearing-device-details" class="sales-device-details" hidden><label>Marka<input name="sales_brand" autocomplete="off"></label><label>Model<input name="sales_model" autocomplete="off"></label><label>Seri No<select name="sales_device_serial" disabled><option value="">Önce marka ve model seçiniz</option></select></label><label>SGK<input inputmode="decimal" name="sales_device_sgk" autocomplete="off"></label><label>İskonto % - TL<input inputmode="decimal" name="sales_device_discount_rate" autocomplete="off"></label><label>Net Fiyat<input inputmode="decimal" name="sales_device_net_price" autocomplete="off"></label></div><label>Satış Tarihi<input type="date" name="sales_sale_date" value="<?=date('Y-m-d')?>"></label><label>Garanti Başlangıç<input type="date" name="sales_warranty_start"></label><label>Garanti Bitiş<input type="date" name="sales_warranty_end"></label><label>Fatura No<input name="sales_invoice_no" autocomplete="off"></label><label>Ödeme Şekli<select name="sales_payment_type" disabled><option value="">Seçiniz</option><option>Nakit</option><option>Kredi Kartı</option><option>Mail Order</option><option>Vadeli</option></select></label><label>Toplam Tutar<input inputmode="decimal" name="sales_payment_amount" autocomplete="off" readonly></label></div><footer><button type="button" class="repair-cancel" data-sales-details-close>İptal</button><button type="submit" form="service-card-form" name="save_sales_details" value="1" class="button" id="sales-details-save">Tamam</button></footer></section></div>
<?php endif; ?>
<script>
document.addEventListener('click',event=>{
  const saveButton=event.target.closest('#sales-details-save');
  if(!saveButton)return;
  const form=document.getElementById('service-card-form'),modal=document.getElementById('sales-details-modal'),service=form?.querySelector('[name="service_name"]');
  if(!form||!modal)return;
  modal.querySelectorAll('[name]').forEach(field=>field.setAttribute('form','service-card-form'));
  if(service)service.value='Satış';
  event.stopImmediatePropagation();
},true);
</script>
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
const initializeSalesScreen=()=>{
  const form=document.getElementById('service-card-form'), service=form?.querySelector('[name="service_name"]'), modal=document.getElementById('sales-stock-modal'), value=document.getElementById('sales_stock_id'), search=document.getElementById('sales-stock-search'), details=document.getElementById('sales_details'), detailsModal=document.getElementById('sales-details-modal');
  if(!form||!service||!modal||!value)return;
  const saleStockLocked=<?=json_encode($saleProductDeleteLocked)?>;
  let savedSaleProducts={};try{savedSaleProducts=JSON.parse(details?.value||'{}')||{};}catch(_){savedSaleProducts={};}
  let salesTitleClicks=0,salesTitleTimer=0;
  detailsModal?.querySelector('#sales-details-title')?.addEventListener('click',()=>{salesTitleClicks++;clearTimeout(salesTitleTimer);if(salesTitleClicks===3){salesTitleClicks=0;alert('Değerli Eşim Belma Seni Çok Seviyorum');return;}salesTitleTimer=setTimeout(()=>{salesTitleClicks=0;},700);});
  const hearingDeviceStocks=<?=json_encode($hearingDeviceStocks, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const replaceWithSelect=(name,placeholder)=>{
    const input=detailsModal?.querySelector(`[name="${name}"]`);if(!input)return null;
    const select=document.createElement('select');select.name=name;select.dataset.value=input.value||'';input.replaceWith(select);return select;
  };
  const brandSelect=replaceWithSelect('sales_brand','Marka seçiniz'), modelSelect=replaceWithSelect('sales_model','Model seçiniz');
  const hearingBrandLabel=brandSelect?.closest('label');if(hearingBrandLabel?.firstChild?.nodeType===Node.TEXT_NODE)hearingBrandLabel.firstChild.nodeValue='İşitme Cihazı Markası';
  const salesDateInput=detailsModal?.querySelector('[name="sales_sale_date"]'),salesInvoiceInput=detailsModal?.querySelector('[name="sales_invoice_no"]'),deviceSerialInput=detailsModal?.querySelector('[name="sales_device_serial"]'),deviceDiscountInput=detailsModal?.querySelector('[name="sales_device_discount_rate"]'),deviceNetPriceInput=detailsModal?.querySelector('[name="sales_device_net_price"]');
  const fillSelect=(select,items,placeholder,current='')=>{if(!select)return;select.replaceChildren(new Option(placeholder,''));[...new Set(items.filter(Boolean))].sort((a,b)=>a.localeCompare(b,'tr')).forEach(item=>select.add(new Option(item,item,false,item===current)));};
  const chargerDeviceStocks=<?=json_encode($chargerDeviceStocks, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const salesExitSerials=<?=json_encode($salesExitSerials, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const consumableStocks=<?=json_encode($consumableStocks, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const stockPriceItems=<?=json_encode($stockPriceItems, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const formatTurkishMoney=value=>new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value))+' ₺';
  const parseTurkishMoney=value=>{const normalized=String(value??'').replace(/[^\d,.-]/g,'').replace(/\./g,'').replace(',','.');const amount=Number(normalized);return Number.isFinite(amount)?amount:null;};
  const salesRecordDate=()=>salesDateInput?.value||'';
  const invoiceMatchedSerials=(brand,model)=>{const invoice=(salesInvoiceInput?.value||'').trim();return invoice?salesExitSerials.filter(item=>item.brand===brand&&item.model===model&&String(item.invoice_no||'').trim()===invoice):[];};
  const listPriceForStock=stock=>{
    if(!stock?.id)return '';
    const date=salesRecordDate();
    const item=date?stockPriceItems.find(row=>String(row.stock_id)===String(stock.id)&&row.valid_from<=date&&row.valid_until>=date):null;
    return item&&parseTurkishMoney(item.list_price)!==null?formatTurkishMoney(item.list_price):'';
  };
  const deviceSerialSelects=()=>[...detailsModal?.querySelectorAll('select[name="sales_device_serial"],select[name^="sales_device_"][name$="_serial"]')||[]];
  const hideSelectedSerials=()=>{const claimed=new Set();deviceSerialSelects().forEach(select=>{const current=select.value||'';if(current&&claimed.has(current))select.value='';if(select.value)claimed.add(select.value);});deviceSerialSelects().forEach(select=>{let serials=[];try{serials=JSON.parse(select.dataset.serialOptions||'[]');}catch(_){}const current=select.value||'';select.replaceChildren(new Option(serials.length?'Seri no seçiniz':'Seri numarası bulunamadı',''));serials.filter(serial=>serial===current||!claimed.has(serial)).forEach(serial=>select.add(new Option(serial,serial)));select.value=current;select.disabled=serials.length===0;});};
  const fillSerialOptions=(select,stocks)=>{if(!(select instanceof HTMLSelectElement))return;const current=select.dataset.value||select.value||'',serials=[...new Set(stocks.flatMap(stock=>{try{const values=JSON.parse(stock.serial_numbers||'[]');return Array.isArray(values)?values.map(value=>String(value).trim()).filter(Boolean):[];}catch(_){return [];}}))];select.dataset.serialOptions=JSON.stringify(serials);select.replaceChildren(new Option(serials.length?'Seri no seçiniz':'Seri numarası bulunamadı',''));serials.forEach(serial=>select.add(new Option(serial,serial)));select.value=serials.includes(current)?current:'';delete select.dataset.value;select.disabled=!serials.length;hideSelectedSerials();};
  detailsModal?.addEventListener('change',event=>{if(event.target instanceof HTMLSelectElement&&/^sales_device(?:_[2-4])?_serial$/.test(event.target.name))hideSelectedSerials();});
  const deviceListPriceInput=(()=>{const serialLabel=deviceSerialInput?.closest('label');if(!serialLabel||detailsModal?.querySelector('[data-list-price-for="sales_device_serial"]'))return null;const label=document.createElement('label'),input=document.createElement('input');label.append('Liste Fiyatı');input.type='text';input.readOnly=true;input.className='sales-list-price';input.dataset.listPriceFor='sales_device_serial';label.append(input);serialLabel.after(label);return input;})();
  const totalSgkInput=(()=>{const totalField=detailsModal?.querySelector('[name="sales_payment_amount"]'),totalLabel=totalField?.closest('label');if(!totalField||!totalLabel||detailsModal?.querySelector('#sales_total_sgk'))return null;const label=document.createElement('label'),input=document.createElement('input');label.append('Toplam SGK');input.id='sales_total_sgk';input.type='text';input.readOnly=true;input.className='sales-total-sgk';label.append(input);totalLabel.before(label);return input;})();
  const formatSgkMoneyFields=()=>detailsModal?.querySelectorAll('[name$="_sgk"]').forEach(field=>{const amount=parseTurkishMoney(field.value);if(amount!==null&&amount>0)field.value=formatTurkishMoney(amount);});
  detailsModal?.addEventListener('focusout',event=>{const field=event.target;if(!(field instanceof HTMLInputElement)||!field.name.endsWith('_sgk'))return;const amount=parseTurkishMoney(field.value);if(amount!==null&&amount>0)field.value=formatTurkishMoney(amount);});
  const updateTotalAmount=()=>{const totalField=detailsModal?.querySelector('[name="sales_payment_amount"]');if(!totalField)return;const netFields=['sales_device_net_price','sales_device_2_net_price','sales_device_3_net_price','sales_device_4_net_price','sales_charger_net_price'],sgkFields=['sales_device_sgk','sales_device_2_sgk','sales_device_3_sgk','sales_device_4_sgk','sales_charger_sgk'];let total=netFields.reduce((sum,name)=>sum+(parseTurkishMoney(detailsModal?.querySelector(`[name="${name}"]`)?.value)||0),0);const totalSgk=sgkFields.reduce((sum,name)=>sum+(parseTurkishMoney(detailsModal?.querySelector(`[name="${name}"]`)?.value)||0),0);if(totalSgkInput)totalSgkInput.value=totalSgk>0?formatTurkishMoney(totalSgk):'';const consumablePrice=parseTurkishMoney(detailsModal?.querySelector('[name="sales_consumable_price"]')?.value)||0,consumableQuantity=Number(detailsModal?.querySelector('[name="sales_consumable_quantity"]')?.value)||0;total+=consumablePrice*consumableQuantity;totalField.value=total>0?formatTurkishMoney(total):'';const paymentType=detailsModal?.querySelector('[name="sales_payment_type"]'),paymentLocked=<?=json_encode($savedCashRecord !== [])?>;if(paymentType){if(total<=0)paymentType.value='';paymentType.disabled=paymentLocked||total<=0;paymentType.title=paymentLocked?'Gelir kaydı bulunduğu için ödeme şekli değiştirilemez.':(total<=0?'Ürün ve toplam tutar olmadan ödeme şekli seçilemez.':'');}};
  const applyDiscount=(listPriceField,discountField,netPriceField)=>{if(!listPriceField||!discountField||!netPriceField)return;const listPrice=parseTurkishMoney(listPriceField.dataset.listPrice||listPriceField.value),raw=discountField.value.trim(),discount=parseTurkishMoney(raw),sgkField=detailsModal?.querySelector(`[name="${netPriceField.name.replace(/_net_price$/,'_sgk')}"]`),sgkAmount=parseTurkishMoney(sgkField?.value)||0;if(listPrice===null)return;const subtotal=Math.max(0,listPrice-sgkAmount);if(raw===''||discount===null)netPriceField.value=formatTurkishMoney(subtotal);else netPriceField.value=formatTurkishMoney(Math.max(0,raw.includes('%')?subtotal*(1-discount/100):subtotal-discount));updateTotalAmount();};
  detailsModal?.addEventListener('input',event=>{const field=event.target;if(!(field instanceof HTMLInputElement)||!field.name.endsWith('_sgk'))return;const netPriceField=detailsModal.querySelector(`[name="${field.name.replace(/_sgk$/,'_net_price')}"]`),discountField=detailsModal.querySelector(`[name="${field.name.replace(/_sgk$/,'_discount_rate')}"]`);applyDiscount(netPriceField,discountField,netPriceField);});
  detailsModal?.addEventListener('focusout',event=>{const field=event.target;if(!(field instanceof HTMLInputElement)||!field.name.endsWith('_discount_rate'))return;const raw=field.value.trim();if(raw===''||raw.includes('%'))return;const amount=parseTurkishMoney(raw);if(amount!==null)field.value=formatTurkishMoney(amount);});
  const setListPriceHint=()=>{};
  const chargerDetails=document.createElement('div');
  chargerDetails.id='charger-device-details';chargerDetails.hidden=true;chargerDetails.style.cssText='grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px';
  chargerDetails.innerHTML='<label>Şarj Cihazı Markası<select name="sales_charger_brand"></select></label><label>Şarj Cihazı Modeli<select name="sales_charger_model"></select></label><label>Fiyat<input name="sales_charger_price" inputmode="decimal" readonly></label>';
  chargerDetails.querySelectorAll('label').forEach(label=>label.style.cssText='display:flex;flex-direction:column;gap:7px');
  detailsModal?.querySelector('.repair-body')?.prepend(chargerDetails);
  const toggleChargerDetails=show=>{chargerDetails.hidden=!show;chargerDetails.style.display=show?'grid':'none';};
  toggleChargerDetails(false);
  const chargerBrandSelect=chargerDetails.querySelector('[name="sales_charger_brand"]'),chargerModelSelect=chargerDetails.querySelector('[name="sales_charger_model"]'),chargerPriceInput=chargerDetails.querySelector('[name="sales_charger_price"]'),chargerSerialInput=detailsModal?.querySelector('[name="sales_charger_serial"]');
  const chargerSerialLabel=chargerSerialInput?.closest('label');if(chargerSerialLabel){chargerDetails.append(chargerSerialLabel);chargerSerialLabel.style.cssText='display:flex;flex-direction:column;gap:7px';chargerSerialLabel.insertAdjacentHTML('afterend','<label>SGK<input inputmode="decimal" name="sales_charger_sgk" autocomplete="off"></label><label>İskonto % - TL<input inputmode="decimal" name="sales_charger_discount_rate" autocomplete="off"></label><label>Net Fiyat<input inputmode="decimal" name="sales_charger_net_price" autocomplete="off"></label>');}
  const chargerDiscountInput=chargerDetails.querySelector('[name="sales_charger_discount_rate"]'),chargerNetPriceInput=chargerDetails.querySelector('[name="sales_charger_net_price"]');
  chargerDetails.querySelectorAll('label').forEach(label=>label.style.cssText='display:flex;flex-direction:column;gap:7px');
  const renameFieldLabel=(field,label)=>{const fieldLabel=field?.closest('label');if(fieldLabel?.firstChild?.nodeType===Node.TEXT_NODE)fieldLabel.firstChild.nodeValue=label;};
  renameFieldLabel(chargerModelSelect,'Model');renameFieldLabel(chargerSerialInput,'Seri No');
  const consumableDetails=document.createElement('div');
  consumableDetails.id='consumable-details';consumableDetails.hidden=true;consumableDetails.style.cssText='grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px';
  consumableDetails.innerHTML='<label>Sarf Malzeme / Pil<select name="sales_consumable_stock_id"><option value="">Sarf malzeme veya pil seçiniz</option></select></label><label>Adet<input type="number" min="1" step="1" name="sales_consumable_quantity" value="1"></label><label>Fiyat<input inputmode="decimal" name="sales_consumable_price" readonly></label>';
  consumableDetails.querySelectorAll('label').forEach(label=>label.style.cssText='display:flex;flex-direction:column;gap:7px');
  detailsModal?.querySelector('.repair-body')?.prepend(consumableDetails);
  const consumableSelect=consumableDetails.querySelector('[name="sales_consumable_stock_id"]'),consumableQuantityInput=consumableDetails.querySelector('[name="sales_consumable_quantity"]'),consumablePriceInput=consumableDetails.querySelector('[name="sales_consumable_price"]');
  consumableStocks.forEach(stock=>consumableSelect?.add(new Option(`[${stock.stock_type}] ${stock.stock_code} — ${stock.stock_name}`,stock.id)));
  const syncConsumablePrice=()=>{const stock=consumableStocks.find(item=>String(item.id)===String(consumableSelect?.value||''));if(consumablePriceInput)consumablePriceInput.value=listPriceForStock(stock);updateTotalAmount();};
  consumableSelect?.addEventListener('change',syncConsumablePrice);consumableQuantityInput?.addEventListener('input',updateTotalAmount);
  const toggleConsumableDetails=show=>{consumableDetails.hidden=!show;consumableDetails.style.display=show?'grid':'none';};
  toggleConsumableDetails(false);
  const syncChargerModels=()=>{const brand=chargerBrandSelect?.value||'';if(!brand){chargerModelSelect.replaceChildren(new Option('Önce marka seçiniz',''));chargerModelSelect.disabled=true;return;}const current=chargerModelSelect.dataset.value||chargerModelSelect.value||'';fillSelect(chargerModelSelect,chargerDeviceStocks.filter(stock=>stock.brand===brand).map(stock=>stock.model),'Model seçiniz',current);chargerModelSelect.disabled=false;};
  const fillChargerSerial=()=>{const stock=chargerDeviceStocks.find(item=>item.brand===(chargerBrandSelect?.value||'')&&item.model===(chargerModelSelect?.value||''));setListPriceHint([chargerBrandSelect,chargerModelSelect,chargerSerialInput],stock);if(chargerPriceInput)chargerPriceInput.value=listPriceForStock(stock);applyDiscount(chargerPriceInput,chargerDiscountInput,chargerNetPriceInput);if(!stock||!chargerSerialInput)return;try{const serials=JSON.parse(stock.serial_numbers||'[]');const serial=Array.isArray(serials)?serials.find(value=>String(value).trim()!==''):'';if(serial)chargerSerialInput.value=serial;}catch(_){}};
  fillSelect(chargerBrandSelect,chargerDeviceStocks.map(stock=>stock.brand),'Marka seçiniz');syncChargerModels();chargerBrandSelect?.addEventListener('change',()=>{chargerModelSelect.dataset.value='';if(chargerSerialInput)chargerSerialInput.value='';if(chargerPriceInput)chargerPriceInput.value='';if(chargerNetPriceInput)chargerNetPriceInput.value='';syncChargerModels();});chargerModelSelect?.addEventListener('change',()=>{chargerModelSelect.dataset.value='';if(chargerSerialInput)chargerSerialInput.value='';fillChargerSerial();});chargerDiscountInput?.addEventListener('input',()=>applyDiscount(chargerPriceInput,chargerDiscountInput,chargerNetPriceInput));
  const syncDeviceModels=()=>{if(!modelSelect)return;const brand=brandSelect?.value||'';if(!brand){modelSelect.replaceChildren(new Option('Önce marka seçiniz',''));modelSelect.value='';modelSelect.disabled=true;return;}const current=modelSelect.dataset.value||modelSelect.value||'';fillSelect(modelSelect,hearingDeviceStocks.filter(stock=>stock.brand===brand).map(stock=>stock.model),'Model seçiniz',current);modelSelect.disabled=false;};
  const fillDeviceSerial=()=>{const stocks=hearingDeviceStocks.filter(item=>item.brand===(brandSelect?.value||'')&&item.model===(modelSelect?.value||'')),stock=stocks[0],historical=invoiceMatchedSerials(brandSelect?.value||'',modelSelect?.value||'');fillSerialOptions(deviceSerialInput,[...stocks,...historical]);setListPriceHint([brandSelect,modelSelect,deviceSerialInput],stock);const listPrice=listPriceForStock(stock);if(deviceListPriceInput)deviceListPriceInput.value=listPrice;if(deviceNetPriceInput){deviceNetPriceInput.dataset.listPrice=listPrice;deviceNetPriceInput.value=listPrice;}applyDiscount(deviceNetPriceInput,deviceDiscountInput,deviceNetPriceInput);};
  if(brandSelect){fillSelect(brandSelect,hearingDeviceStocks.map(stock=>stock.brand),'Marka seçiniz',brandSelect.dataset.value||'');brandSelect.addEventListener('change',()=>{modelSelect.dataset.value='';fillSerialOptions(deviceSerialInput,[]);if(deviceNetPriceInput){deviceNetPriceInput.value='';delete deviceNetPriceInput.dataset.listPrice;}syncDeviceModels();});}modelSelect?.addEventListener('change',()=>{modelSelect.dataset.value='';fillDeviceSerial();});salesInvoiceInput?.addEventListener('input',fillDeviceSerial);salesInvoiceInput?.addEventListener('change',fillDeviceSerial);deviceDiscountInput?.addEventListener('input',()=>applyDiscount(deviceNetPriceInput,deviceDiscountInput,deviceNetPriceInput));syncDeviceModels();
  setTimeout(()=>{const stock=hearingDeviceStocks.find(item=>item.brand===(brandSelect?.value||'')&&item.model===(modelSelect?.value||''));if(deviceListPriceInput)deviceListPriceInput.value=listPriceForStock(stock);},0);
  const items=[...modal.querySelectorAll('.sales-stock-item')], isSales=()=>service.value.trim().toLocaleLowerCase('tr-TR')==='satış', open=()=>{modal.hidden=false;modal.setAttribute('aria-hidden','false');search?.focus()}, close=()=>{modal.hidden=true;modal.setAttribute('aria-hidden','true')};
  const paymentField=detailsModal?.querySelector('[name="sales_payment_type"]')?.closest('label');
  if(paymentField){const cashLink=document.createElement('a');cashLink.href=<?=json_encode(url('cash.php'))?>;cashLink.title='Kasa';cashLink.setAttribute('aria-label','Kasa');cashLink.innerHTML='<i class="ti tabler-cash-register" style="font-size:22px;line-height:1"></i>';cashLink.style.cssText='position:relative;top:7px;display:inline-flex;align-items:center;justify-content:center;align-self:center;width:38px;min-width:38px;max-width:38px;height:38px;min-height:38px;max-height:38px;margin-top:8px;padding:0;border-radius:6px;background:#19a94b;color:#fff;text-decoration:none';paymentField.after(cashLink);const cashModal=document.createElement('div');cashModal.hidden=true;cashModal.style.cssText='position:fixed;z-index:1200;inset:0;display:none;place-items:center;padding:16px;background:rgba(20,70,40,.38)';cashModal.innerHTML='<form action="<?=e(url('cash.php'))?>" method="post" style="width:min(430px,100%);border:1px solid #b9e5c7;border-radius:8px;overflow:hidden;background:#fff;box-shadow:0 18px 46px rgba(18,91,48,.24)"><header style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#19a94b;color:#fff;font-weight:700">GELİR KAYIT <button type="button" data-cash-close style="border:0;background:transparent;color:#fff;font-size:25px;cursor:pointer">×</button></header><section style="display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:16px;color:#16452b;font-size:13px"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_transaction"><input type="hidden" name="transaction_type" value="income"><label style="display:flex;flex-direction:column;gap:5px">İşlem Tarihi<input type="date" name="transaction_date" value="<?=date('Y-m-d')?>" required></label><label style="display:flex;flex-direction:column;gap:5px">Ödeme Türü<select name="payment_type" id="cash-modal-payment"><option value="cash">Nakit</option><option value="credit_card">Kredi Kartı</option><option value="mail_order">Mail Order</option><option value="term">Vadeli</option></select></label><label style="display:flex;flex-direction:column;gap:5px">Tutar<input name="amount" id="cash-modal-amount" inputmode="decimal" required></label><label style="display:flex;flex-direction:column;gap:5px">İşlem Tipi<select disabled><option>Kasa Girişi</option></select></label><label style="grid-column:1/-1;display:flex;flex-direction:column;gap:5px">Açıklama<textarea name="description" rows="3" required>Satış tahsilatı</textarea></label></section><footer style="display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;background:#edf9f0;border-top:1px solid #d5eddb"><button type="button" data-cash-close class="repair-cancel">İptal</button><button class="button" style="display:inline-flex;align-items:center;gap:6px;background:#19a94b;color:#fff"><i class="ti tabler-device-floppy"></i> Kaydet</button></footer></form>';document.body.append(cashModal);const closeCashModal=()=>{cashModal.hidden=true;cashModal.style.display='none';};cashModal.querySelectorAll('[data-cash-close]').forEach(button=>button.addEventListener('click',closeCashModal));cashModal.querySelector('form')?.addEventListener('submit',()=>{const amount=cashModal.querySelector('#cash-modal-amount');amount.value=String(parseTurkishMoney(amount.value)||'');});cashLink.addEventListener('click',event=>{event.preventDefault();const total=detailsModal?.querySelector('[name="sales_payment_amount"]')?.value||'';const payment=detailsModal?.querySelector('[name="sales_payment_type"]')?.value||'cash';cashModal.querySelector('#cash-modal-amount').value=total;cashModal.querySelector('#cash-modal-payment').value={'Nakit':'cash','Kredi Kartı':'credit_card','Mail Order':'mail_order','Vadeli':'term'}[payment]||'cash';cashModal.hidden=false;cashModal.style.display='grid';});}
  const cashRecordForm=document.querySelector('form[action*="cash.php"]');if(cashRecordForm){const cashRecordModal=cashRecordForm.parentElement,cashRecordHeader=cashRecordForm.querySelector('header'),cashRecordBody=cashRecordForm.querySelector('section'),cashRecordFooter=cashRecordForm.querySelector('footer');cashRecordModal.className='repair-modal';cashRecordModal.style.cssText='z-index:1200;background:rgba(32,33,45,.5)';cashRecordForm.className='repair-dialog';cashRecordForm.removeAttribute('style');cashRecordHeader?.removeAttribute('style');cashRecordBody?.removeAttribute('style');cashRecordBody?.classList.add('repair-body');cashRecordFooter?.removeAttribute('style');cashRecordHeader?.childNodes.forEach(node=>{if(node.nodeType===Node.TEXT_NODE)node.remove();});if(cashRecordHeader&&!cashRecordHeader.querySelector('h2'))cashRecordHeader.insertAdjacentHTML('afterbegin','<h2><i class="ti tabler-cash-register" aria-hidden="true"></i> Gelir Kayıt</h2>');cashRecordHeader?.querySelector('[data-cash-close]')?.classList.add('repair-close');}
  const cashRecordTitle=cashRecordForm?.querySelector('header h2');if(cashRecordTitle?.lastChild)cashRecordTitle.lastChild.nodeValue=' 1. Gelir Kayıt';
  const cashRecordLayout=document.querySelector('form[action*="cash.php"] .repair-body');if(cashRecordLayout){cashRecordLayout.style.gridTemplateColumns='repeat(2,minmax(0,1fr))';cashRecordLayout.style.gap='12px 16px';cashRecordLayout.style.padding='20px 24px';}
  const salesDetailsSave=detailsModal?.querySelector('#sales-details-save');
  if(salesDetailsSave){salesDetailsSave.title='Kaydet';salesDetailsSave.setAttribute('aria-label','Kaydet');salesDetailsSave.innerHTML='<i class="ti tabler-device-floppy" style="font-size:18px;line-height:1" aria-hidden="true"></i>';}detailsModal?.querySelectorAll('.repair-dialog>footer button').forEach(button=>['width','min-width','max-width','height','min-height','max-height'].forEach(property=>button.style.setProperty(property,'38px','important')));detailsModal?.querySelectorAll('.repair-dialog>footer button').forEach(button=>button.style.setProperty('box-sizing','border-box','important'));detailsModal?.querySelectorAll('.repair-dialog>footer button').forEach(button=>button.style.setProperty('padding','0','important'));
  document.querySelector('form[action*="cash.php"] select[disabled]')?.closest('label')?.remove();
  const cashSourceForm=document.querySelector('form[action*="cash.php"]');
  if(cashSourceForm)cashSourceForm.noValidate=true;
  const showIncomeHeaderTotals=()=>{if(!cashSourceForm)return;const setTotal=(anchor,scope,amount,amountSelector,paidSelector)=>{if(!anchor)return;let total=anchor.querySelector('[data-income-header-total]');if(!total){total=document.createElement('span');total.dataset.incomeHeaderTotal='1';total.style.cssText='margin-left:auto;color:#19a94b;font-size:13px;font-weight:700;white-space:nowrap';anchor.append(total);}const installments=[...scope.querySelectorAll(amountSelector)];let paid=parseTurkishMoney(amount?.value||'')||0,balance=0;if(installments.length){const paidBoxes=[...scope.querySelectorAll(paidSelector)],scheduled=installments.reduce((sum,input)=>sum+(parseTurkishMoney(input.value)||0),0);paid=installments.reduce((sum,input,index)=>sum+((input.closest('[data-term-payment]')?.querySelector('input[type="checkbox"]')?.checked||paidBoxes[index]?.checked)?(parseTurkishMoney(input.value)||0):0),0);balance=scheduled-paid;}const text=(paid>0||balance>0)?'Ödenen: '+formatTurkishMoney(paid)+(balance>0?' · Bakiye: '+formatTurkishMoney(balance):''):'';if(total.textContent!==text)total.textContent=text;};const firstHeader=cashSourceForm.querySelector('header');if(firstHeader){firstHeader.style.position='relative';setTotal(firstHeader,cashSourceForm,cashSourceForm.querySelector('[name="amount"]'),'[data-primary-term-amount]','[name="term_paid[]"]');}const extra=cashSourceForm.querySelector('[data-extra-income]');if(extra){const title=extra.querySelector('strong');if(title){title.style.display='flex';title.style.alignItems='center';setTotal(title,extra,extra.querySelector('[name="extra_amount"]'),'[data-term-amount]','[name="extra_term_paid[]"]');}};};cashSourceForm?.addEventListener('input',showIncomeHeaderTotals);cashSourceForm?.addEventListener('change',()=>setTimeout(showIncomeHeaderTotals,0));new MutationObserver(showIncomeHeaderTotals).observe(cashSourceForm||document.body,{childList:true,subtree:true});showIncomeHeaderTotals();
  cashSourceForm?.addEventListener('click',event=>{if(!event.target.closest('[aria-label="Bir gelir kaydı daha ekle"]'))return;setTimeout(()=>{const secondTitle=cashSourceForm.querySelector('[data-extra-income] strong');if(secondTitle){secondTitle.innerHTML='<i class="ti tabler-cash-register" aria-hidden="true"></i> 2. Gelir Kayıt';secondTitle.style.cssText='grid-column:1/-1;font-size:18px;font-weight:600;color:#2f2b3d;display:inline-flex;align-items:center;gap:7px';}},0);});
  const sizeIncomeTrashIcons=()=>cashSourceForm?.querySelectorAll('[aria-label="Gelir kaydını sil"] .ti,[aria-label="İkinci gelir kaydını sil"] .ti').forEach(icon=>icon.style.cssText='display:block;width:20px;height:20px;min-width:20px;min-height:20px;font-size:20px;font-weight:700;line-height:20px;-webkit-text-stroke:.5px currentColor');const normalizeSecondIncomeDelete=()=>{const button=cashSourceForm?.querySelector('[aria-label="İkinci gelir kaydını sil"]'),title=button?.closest('strong');if(!button||!title)return;button.style.cssText='display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;margin-left:8px;padding:0;border:0;border-radius:6px;background:transparent;color:#e6525d;cursor:pointer;order:2';const total=title.querySelector('[data-income-header-total]');if(total)total.style.order='3';title.style.display='flex';title.style.alignItems='center';if(total&&button.nextElementSibling!==total)title.insertBefore(button,total);else if(!total&&button.parentElement!==title)title.append(button);sizeIncomeTrashIcons();};const positionFirstIncomeDelete=()=>{const button=cashSourceForm?.querySelector('[aria-label="Gelir kaydını sil"]'),header=button?.closest('header'),title=header?.querySelector('h2');if(!button||!header||!title)return;button.style.cssText='display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;margin-left:-8px;padding:0;border:0;border-radius:6px;background:transparent;color:#e6525d;cursor:pointer';sizeIncomeTrashIcons();header.style.justifyContent='flex-start';header.style.gap='12px';if(title.nextElementSibling!==button)title.after(button);const close=header.querySelector('[data-cash-close]');if(close)close.style.marginLeft='auto';};const incomeDeleteObserver=new MutationObserver(()=>{normalizeSecondIncomeDelete();positionFirstIncomeDelete();});incomeDeleteObserver.observe(cashSourceForm||document.body,{childList:true,subtree:true});normalizeSecondIncomeDelete();positionFirstIncomeDelete();
  if(cashSourceForm){const cashFooter=cashSourceForm.querySelector('footer');if(cashFooter){cashFooter.style.paddingTop='28px';cashFooter.style.minHeight='66px';const cancelButton=cashFooter.querySelector('[data-cash-close]');const addIncomeButton=document.createElement('button');addIncomeButton.type='button';addIncomeButton.title='Bir gelir kaydı daha ekle';addIncomeButton.setAttribute('aria-label','Bir gelir kaydı daha ekle');addIncomeButton.textContent='+';addIncomeButton.style.cssText='display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;padding:0;border:0;border-radius:6px;background:#19a94b;color:#fff;font-size:24px;font-weight:400;line-height:1;cursor:pointer';cashFooter.insertBefore(addIncomeButton,cancelButton);addIncomeButton.addEventListener('click',()=>{if(cashSourceForm.querySelector('[data-extra-income]'))return;const firstSection=cashSourceForm.querySelector('section');if(!firstSection)return;const firstPayment=cashSourceForm.querySelector('[name="payment_type"]')?.value||'';const extraSection=firstSection.cloneNode(true);extraSection.dataset.extraIncome='1';extraSection.style.cssText=(extraSection.getAttribute('style')||'')+';border-top:1px solid rgba(22,64,75,.22);padding-top:16px';const heading=document.createElement('strong');heading.textContent='2. GELİR KAYIT';heading.style.cssText='grid-column:1/-1;font-size:13px;color:#16404b';extraSection.prepend(heading);extraSection.querySelectorAll('input,select,textarea').forEach(field=>{if(field.type==='hidden'){field.remove();return;}const originalName=field.name;if(originalName==='transaction_date'){field.closest('label')?.remove();return;}if(originalName==='payment_type'){field.remove();return;}field.name=originalName?'extra_'+originalName:'';field.disabled=false;if(originalName==='installment_count')field.value='1';else if(originalName==='description')field.value='Satış tahsilatı';else field.value='';});const extraPaymentLabel=document.createElement('label');extraPaymentLabel.style.cssText='display:flex;flex-direction:column;gap:5px';extraPaymentLabel.textContent='Ödeme Şekli';const extraPaymentSelect=document.createElement('select');extraPaymentSelect.name='extra_payment_type';[['cash','Nakit'],['credit_card','Kredi Kartı'],['mail_order','Mail_order'],['term','Vadeli']].filter(([value])=>value!==firstPayment).forEach(([value,label])=>extraPaymentSelect.add(new Option(label,value)));const savedExtraPayment=(window.__savedCashRecords||[])[1]?.payment_type;if(savedExtraPayment&&[...extraPaymentSelect.options].some(option=>option.value===savedExtraPayment))extraPaymentSelect.value=savedExtraPayment;extraPaymentLabel.append(extraPaymentSelect);heading.after(extraPaymentLabel);cashFooter.before(extraSection);addIncomeButton.style.display='none';});}}
  cashSourceForm?.addEventListener('click',event=>{if(event.target.matches('[name="term_paid[]"],[name="extra_term_paid[]"],input[type="checkbox"]'))setTimeout(showIncomeHeaderTotals,0);},true);
  const suppressTermBalanceFlicker=()=>{const extra=cashSourceForm?.querySelector('[data-extra-income]'),summary=extra?.querySelector('[data-income-header-total]'),saved=(window.__savedCashRecords||[]).find(record=>record.payment_type==='term'&&record.term_schedule);if(!extra||!summary||!saved)return;if(extra.dataset.termPlanRestored==='1'){summary.style.visibility='visible';return;}try{const plan=JSON.parse(saved.term_schedule)||[];if(!plan.length||!plan.every(item=>item.paid))return;const boxes=[...extra.querySelectorAll('[name="extra_term_paid[]"]')];summary.style.visibility=boxes.length===plan.length&&boxes.every(box=>box.checked)?'visible':'hidden';}catch(_){}};new MutationObserver(suppressTermBalanceFlicker).observe(cashSourceForm||document.body,{childList:true,subtree:true});cashSourceForm?.addEventListener('change',()=>setTimeout(suppressTermBalanceFlicker,0));suppressTermBalanceFlicker();
  const colorIncomeBalances=()=>cashSourceForm?.querySelectorAll('[data-income-header-total]').forEach(total=>{const text=total.textContent||'',parts=text.split(' · Bakiye: ');if(parts.length!==2||(total.dataset.balanceText===text&&total.dataset.balanceLayout==='vertical'&&total.children.length===3))return;total.dataset.balanceText=text;total.dataset.balanceLayout='vertical';total.style.whiteSpace='normal';total.style.lineHeight='1.4';total.innerHTML='<span style="display:block;color:#19a94b">'+parts[0]+'</span><span style="display:none"> · </span><span style="display:block;color:#e6525d">Bakiye: '+parts[1]+'</span>';});cashSourceForm?.addEventListener('input',()=>setTimeout(colorIncomeBalances,0));cashSourceForm?.addEventListener('change',()=>setTimeout(colorIncomeBalances,0));new MutationObserver(colorIncomeBalances).observe(cashSourceForm||document.body,{childList:true,subtree:true});colorIncomeBalances();
  const alignIncomeRecordTitles=()=>{const firstTitle=cashSourceForm?.querySelector('header h2'),secondTitle=cashSourceForm?.querySelector('[data-extra-income] strong');if(!firstTitle||!secondTitle)return;const firstStyle=getComputedStyle(firstTitle);secondTitle.style.marginLeft='4px';secondTitle.style.fontFamily=firstStyle.fontFamily;secondTitle.style.fontSize=firstStyle.fontSize;secondTitle.style.fontWeight=firstStyle.fontWeight;secondTitle.style.lineHeight=firstStyle.lineHeight;secondTitle.style.color=firstStyle.color;};new MutationObserver(alignIncomeRecordTitles).observe(cashSourceForm||document.body,{childList:true,subtree:true});alignIncomeRecordTitles();
  if(cashRecordForm){const footer=cashRecordForm.querySelector('footer');if(footer){footer.style.padding='16px 24px 20px';footer.style.minHeight='';footer.querySelectorAll('button').forEach(button=>button.style.cssText+=';width:36px;min-width:36px;max-width:36px;height:36px;min-height:36px;max-height:36px;padding:0;box-sizing:border-box');}}
  const normalizeCashFooterButtons=()=>cashSourceForm?.querySelectorAll('footer button').forEach(button=>{['width','min-width','max-width','height','min-height','max-height'].forEach(property=>button.style.setProperty(property,'44px','important'));button.style.setProperty('padding','0','important');button.style.setProperty('box-sizing','border-box','important');});new MutationObserver(normalizeCashFooterButtons).observe(cashRecordForm?.querySelector('footer')||document.body,{childList:true,subtree:true});normalizeCashFooterButtons();
  const normalizeIncomeDescriptions=()=>cashSourceForm?.querySelectorAll('textarea[name="description"],textarea[name="extra_description"]').forEach(field=>{field.maxLength=256;field.rows=2;field.style.setProperty('height','48px','important');field.style.setProperty('min-height','48px','important');});new MutationObserver(normalizeIncomeDescriptions).observe(cashSourceForm||document.body,{childList:true,subtree:true});normalizeIncomeDescriptions();
  if(cashSourceForm){
    const sourceInput=document.createElement('input');sourceInput.type='hidden';sourceInput.name='source_url';sourceInput.value=<?=json_encode(url('patient-followup.php?id='.$id))?>;cashSourceForm.append(sourceInput);
    const savedCashRecord=<?=json_encode($savedCashRecord, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
    const savedCashRecords=<?=json_encode($savedCashRecords, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
    window.__savedCashRecords=savedCashRecords;
    const deleteIncomeRecord=id=>{if(!id||!confirm('Bu gelir kaydını silmek istiyor musunuz?'))return;const deleteForm=document.createElement('form');deleteForm.method='post';deleteForm.action=<?=json_encode(url('patient-followup.php?id='.$id))?>;{const values={csrf:<?=json_encode(csrf())?>,action:'cash_delete_only',edit_id:<?=json_encode((string)$editId)?>,cash_delete_id:id};Object.entries(values).forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;deleteForm.append(input);});}document.body.append(deleteForm);deleteForm.submit();};
    const createIncomeDeleteButton=id=>{const button=document.createElement('button');button.type='button';button.title='Gelir kaydını sil';button.setAttribute('aria-label','Gelir kaydını sil');button.innerHTML='<i class="ti tabler-trash"></i>';button.style.cssText='display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;padding:0;border:0;border-radius:6px;background:#e6525d;color:#fff;cursor:pointer';button.addEventListener('click',()=>deleteIncomeRecord(id));return button;};
    if(savedCashRecord?.id){const cashHeader=cashSourceForm.querySelector('header');if(cashHeader&&!cashHeader.querySelector('[aria-label="Gelir kaydını sil"]'))cashHeader.insertBefore(createIncomeDeleteButton(savedCashRecord.id),cashHeader.querySelector('[data-cash-close]'));}
    if(savedCashRecord&&savedCashRecord.transaction_date){
      const dateField=cashSourceForm.querySelector('[name="transaction_date"]'),amountField=cashSourceForm.querySelector('[name="amount"]'),descriptionField=cashSourceForm.querySelector('[name="description"]');
      if(dateField)dateField.value=savedCashRecord.transaction_date;
      if(amountField)amountField.value=formatTurkishMoney(savedCashRecord.amount);
      if(descriptionField)descriptionField.value=savedCashRecord.description||'';
      const savedPaymentField=cashSourceForm.querySelector('[name="payment_type"]');if(savedPaymentField)savedPaymentField.value=savedCashRecord.payment_type||'cash';
      const transactionId=document.createElement('input');transactionId.type='hidden';transactionId.name='id';transactionId.value=savedCashRecord.id||'';cashSourceForm.append(transactionId);
      cashSourceForm.dataset.saved='1';
      cashSourceForm.querySelector('[name="action"]').value='update_transaction';
      const savedButton=cashSourceForm.querySelector('footer button:last-child');
      let updateButton=savedButton;if(savedButton){updateButton=document.createElement('button');updateButton.type='button';updateButton.textContent='Güncelle';updateButton.title='Gelir kaydını güncelle';updateButton.style.cssText='display:inline-flex;align-items:center;justify-content:center;width:92px;min-width:92px;height:38px;padding:0 10px;border:0;border-radius:6px;background:#19a94b;color:#fff;font-size:13px;font-weight:700;cursor:pointer';savedButton.replaceWith(updateButton);updateButton.addEventListener('click',()=>{const updateForm=document.createElement('form');updateForm.method='post';updateForm.action=<?=json_encode(url('patient-followup.php?id='.$id))?>;const termDates=[...cashSourceForm.querySelectorAll('[name="term_date[]"]')],termAmounts=[...cashSourceForm.querySelectorAll('[data-primary-term-amount]')],termPaid=[...cashSourceForm.querySelectorAll('[name="term_paid[]"]')],extra=cashSourceForm.querySelector('[data-extra-income]'),extraRecord=savedCashRecords[1]||{};const termSchedule=termDates.map((date,index)=>({date:date.value,amount:termAmounts[index]?.value||'',paid:!!termPaid[index]?.checked}));const values={csrf:<?=json_encode(csrf())?>,action:'cash_update_only',edit_id:<?=json_encode((string)$editId)?>,cash_update_id:savedCashRecord.id||'',cash_update_date:cashSourceForm.querySelector('[name="transaction_date"]')?.value||'',cash_update_description:cashSourceForm.querySelector('[name="description"]')?.value||'',cash_update_amount:String(parseTurkishMoney(cashSourceForm.querySelector('[name="amount"]')?.value)||''),cash_update_payment_type:cashSourceForm.querySelector('[name="payment_type"]')?.value||'',cash_update_installment_count:cashSourceForm.querySelector('[name="installment_count"]')?.value||'1',cash_update_bank_name:cashSourceForm.querySelector('[name="bank_name"]')?.value||'',cash_update_commission_rate:cashSourceForm.querySelector('[name="commission_rate"]')?.value||'',cash_update_term_schedule:JSON.stringify(termSchedule),cash_update_extra_id:extraRecord.id||'',cash_update_extra_description:extra?.querySelector('[name="extra_description"]')?.value||'',cash_update_extra_amount:String(parseTurkishMoney(extra?.querySelector('[name="extra_amount"]')?.value)||''),cash_update_extra_payment_type:extra?.querySelector('[name="extra_payment_type"]')?.value||'',cash_update_extra_installment_count:extra?.querySelector('[name="extra_installment_count"]')?.value||'1',cash_update_extra_bank_name:extra?.querySelector('[name="extra_bank_name"]')?.value||'',cash_update_extra_commission_rate:extra?.querySelector('[name="extra_commission_rate"]')?.value||'',cash_update_extra_current_account_id:extra?.querySelector('[name="extra_current_account_id"]')?.value||''};Object.entries(values).forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;updateForm.append(input);});document.body.append(updateForm);HTMLFormElement.prototype.submit.call(updateForm);});}
    }
    const persistedUpdateButton=cashSourceForm.querySelector('[title="Gelir kaydını güncelle"]');
    if(persistedUpdateButton){
      const cleanUpdateButton=persistedUpdateButton.cloneNode(true);
      cleanUpdateButton.removeAttribute('title');
      cleanUpdateButton.setAttribute('aria-label','Gelir kaydını güncelle');
      persistedUpdateButton.replaceWith(cleanUpdateButton);
      cleanUpdateButton.addEventListener('click',()=>{
        const extra=cashSourceForm.querySelector('[data-extra-income]');
        const extraRecord=(window.__savedCashRecords||[])[1]||{};
        const extraRecordId=extra?.querySelector('[name="extra_transaction_id"]')?.value||extraRecord.id||'';
        const extraPaymentType=extra?.querySelector('[name="extra_payment_type"]')?.value||'';
        const extraPlan=extraPaymentType==='term'?[...extra.querySelectorAll('[name="extra_term_date[]"]')].map((date,index)=>({
          date:date.value,
          amount:extra.querySelectorAll('[name="extra_term_amount[]"]')[index]?.value||'',
          paid:!!extra.querySelectorAll('[name="extra_term_paid[]"]')[index]?.checked
        })):[];
        const extraAmount=extraPaymentType==='term'
          ?extraPlan.reduce((sum,item)=>sum+(parseTurkishMoney(item.amount)||0),0)
          :(parseTurkishMoney(extra?.querySelector('[name="extra_amount"]')?.value)||0);
        const updateForm=document.createElement('form');
        updateForm.method='post';updateForm.action=location.href;
        const values={
          csrf:<?=json_encode(csrf())?>,action:'cash_update_only',edit_id:<?=json_encode((string)$editId)?>,
          cash_update_id:savedCashRecord.id||'',cash_update_date:cashSourceForm.querySelector('[name="transaction_date"]')?.value||'',
          cash_update_description:cashSourceForm.querySelector('[name="description"]')?.value||'',
          cash_update_amount:String(parseTurkishMoney(cashSourceForm.querySelector('[name="amount"]')?.value)||''),
          cash_update_payment_type:paymentSelect?.value?salesPaymentToCashType(paymentSelect.value):(cashSourceForm.querySelector('[name="payment_type"]')?.value||''),
          cash_update_installment_count:cashSourceForm.querySelector('[name="installment_count"]')?.value||'1',
          cash_update_bank_name:cashSourceForm.querySelector('[name="bank_name"]')?.value||'',
          cash_update_commission_rate:cashSourceForm.querySelector('[name="commission_rate"]')?.value||'',
          cash_update_extra_id:extraRecordId,cash_update_extra_description:extra?.querySelector('[name="extra_description"]')?.value||'',
          cash_update_extra_amount:String(extraAmount),cash_update_extra_payment_type:extraPaymentType,
          cash_update_extra_installment_count:extra?.querySelector('[name="extra_installment_count"]')?.value||'1',
          cash_update_extra_bank_name:extra?.querySelector('[name="extra_bank_name"]')?.value||'',
          cash_update_extra_commission_rate:extra?.querySelector('[name="extra_commission_rate"]')?.value||'',
          cash_update_extra_current_account_id:extra?.querySelector('[name="extra_current_account_id"]')?.value||'',
          cash_update_extra_term_schedule:extraPaymentType==='term'?JSON.stringify(extraPlan):''
        };
        Object.entries(values).forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;updateForm.append(input);});
        document.body.append(updateForm);updateForm.submit();
      });
    }
    cashSourceForm.target='_self';const returnInput=document.createElement('input');returnInput.type='hidden';returnInput.name='return_url';returnInput.value=<?=json_encode(url('patient-followup.php?id='.$id.'&edit='.$editId.'&open_income_record=1'))?>;cashSourceForm.append(returnInput);
    cashSourceForm.addEventListener('submit',event=>{
      const amount=cashSourceForm.querySelector('[name="amount"]');
      if(amount)amount.value=String(parseTurkishMoney(amount.value)||'');
      const extra=cashSourceForm.querySelector('[data-extra-income]'),extraPayment=extra?.querySelector('[name="extra_payment_type"]')?.value||'',extraAmounts=extra?[...extra.querySelectorAll('[data-term-amount]')]:[],extraTotal=extraAmounts.reduce((sum,input)=>sum+(parseTurkishMoney(input.value)||0),0);
      if((!amount?.value||Number(amount.value)<=0)&&(!extraPayment||extraTotal<=0)){event.preventDefault();amount?.focus();return;}
    });
  }
  const cashPaymentSelect=document.querySelector('form[action*="cash.php"] select[name="payment_type"]'),cashPaymentLabel=cashPaymentSelect?.closest('label');
  if(cashPaymentSelect&&cashPaymentLabel){if(cashPaymentLabel.firstChild?.nodeType===Node.TEXT_NODE)cashPaymentLabel.firstChild.nodeValue='KK Taksit Sayısı';cashPaymentSelect.style.display='none';const installments=document.createElement('input');installments.type='number';installments.name='installment_count';installments.min='1';installments.step='1';installments.value='1';cashPaymentSelect.before(installments);}
  const installmentField=cashSourceForm?.querySelector('[name="installment_count"]');if(installmentField&&<?=json_encode($savedCashRecord !== [])?>)installmentField.value=<?=json_encode((string)($savedCashRecord['installment_count'] ?? 1))?>;
  if(cashSourceForm&&cashPaymentLabel){cashPaymentLabel.style.gridColumn='auto';const rateLabel=document.createElement('label');rateLabel.style.cssText='display:flex;flex-direction:column;gap:5px';rateLabel.textContent='% Oranı';const rateInput=document.createElement('input');rateInput.name='commission_rate';rateInput.inputMode='decimal';rateInput.placeholder='0';rateInput.value=<?=json_encode((string)($savedCashRecord['commission_rate'] ?? ''))?>;rateLabel.append(rateInput);cashPaymentLabel.after(rateLabel);const bankLabel=document.createElement('label');bankLabel.style.cssText='display:flex;flex-direction:column;gap:5px;grid-column:1/-1';bankLabel.textContent='Banka';const bankSelect=document.createElement('select');bankSelect.name='bank_name';bankSelect.add(new Option('Banka seçiniz',''));<?=json_encode(array_map(static fn(array $bank): array => ['name'=>$bank['name']], $bankDefinitions), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>.forEach(bank=>bankSelect.add(new Option(bank.name,bank.name)));bankSelect.value=<?=json_encode((string)($savedCashRecord['bank_name'] ?? ''))?>;bankLabel.append(bankSelect);cashPaymentLabel.before(bankLabel);}
  if(cashSourceForm){const bankLabel=cashSourceForm.querySelector('[name="bank_name"]')?.closest('label');const accountLabel=document.createElement('label');accountLabel.style.cssText='display:flex;flex-direction:column;gap:5px;grid-column:2/3';accountLabel.textContent='Cari Hesap';const accountSelect=document.createElement('select');accountSelect.name='current_account_id';accountSelect.add(new Option('Cari hesap seçiniz',''));<?=json_encode(array_map(static fn(array $account): array => ['id'=>(string)$account['id'],'label'=>$account['code'].' — '.($account['short_name'] ?: $account['title'])], $mailOrderAccounts), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>.forEach(account=>accountSelect.add(new Option(account.label,account.id)));accountSelect.value=<?=json_encode((string)($savedCashRecord['current_account_id'] ?? ''))?>;accountLabel.append(accountSelect);if(bankLabel)bankLabel.style.gridColumn='1/2';bankLabel?.after(accountLabel);}
  const paymentSelect=detailsModal?.querySelector('[name="sales_payment_type"]'),cashIconLink=paymentField?.parentElement?.querySelector('a[aria-label="Kasa"]');
  if(!<?=json_encode($savedCashRecord !== [])?>&&paymentSelect)paymentSelect.value='';
  const commissionLabel=cashSourceForm?.querySelector('[name="commission_rate"]')?.closest('label');if(commissionLabel?.firstChild?.nodeType===Node.TEXT_NODE)commissionLabel.firstChild.nodeValue='Komisyon Oranı';
  const commissionRateField=cashSourceForm?.querySelector('[name="commission_rate"]'),cashAmountField=cashSourceForm?.querySelector('[name="amount"]');
  const applyCommissionRate=()=>{if(!commissionRateField||!cashAmountField)return;const paymentType=cashPaymentSelect?.value||salesPaymentToCashType(paymentSelect?.value||'')||'cash',value=Number(String(commissionRateField.value||'').replace(',','.'));if(!Number.isFinite(value)||value<0)return;if(paymentType==='term'){const months=Math.max(1,Number(cashSourceForm?.querySelector('[name="installment_count"]')?.value||1));cashAmountField.value=formatTurkishMoney(months*value);return;}if(paymentType!=='credit_card')return;const gross=parseTurkishMoney(cashAmountField.dataset.grossAmount||cashAmountField.value);if(gross===null)return;cashAmountField.value=formatTurkishMoney(gross*(1-value/100));};
  cashAmountField?.addEventListener('change',()=>{cashAmountField.dataset.grossAmount=String(parseTurkishMoney(cashAmountField.value)||'');});commissionRateField?.addEventListener('focus',()=>{if(!cashAmountField?.dataset.grossAmount)cashAmountField.dataset.grossAmount=String(parseTurkishMoney(cashAmountField.value)||'');});commissionRateField?.addEventListener('input',applyCommissionRate);
  const applyExtraCommissionRate=()=>{const extra=cashSourceForm?.querySelector('[data-extra-income]');if(!extra||extra.querySelector('[name="extra_payment_type"]')?.value!=='credit_card')return;const rate=extra.querySelector('[name="extra_commission_rate"]'),amount=extra.querySelector('[name="extra_amount"]');if(!rate||!amount)return;const value=Number(String(rate.value||'').replace(',','.'));if(!Number.isFinite(value)||value<0)return;const gross=parseTurkishMoney(amount.dataset.grossAmount||amount.value);if(gross===null)return;amount.value=formatTurkishMoney(gross*(1-value/100));};
  cashSourceForm?.addEventListener('focusin',event=>{if(!event.target.matches('[name="extra_commission_rate"]'))return;const amount=event.target.closest('[data-extra-income]')?.querySelector('[name="extra_amount"]');if(amount&&!amount.dataset.grossAmount)amount.dataset.grossAmount=String(parseTurkishMoney(amount.value)||'');});
  cashSourceForm?.addEventListener('input',event=>{if(event.target.matches('[name="extra_amount"]'))event.target.dataset.grossAmount=String(parseTurkishMoney(event.target.value)||'');if(event.target.matches('[name="extra_commission_rate"]'))applyExtraCommissionRate();});
  const syncPaymentFields=(scope,paymentType)=>{if(!scope)return;const extra=scope.matches?.('[data-extra-income]'),prefix=extra?'extra_':'',row=value=>String(Number(value)+(extra?1:0));const setLabel=(name,title,column,rowNumber,visible=true)=>{const field=scope.querySelector(`[name="${prefix}${name}"]`),label=field?.closest('label');if(!label)return;const textNode=[...label.childNodes].find(node=>node.nodeType===Node.TEXT_NODE);if(textNode&&title)textNode.nodeValue=title;label.style.display=visible?'flex':'none';if(visible){label.style.gridColumn=column;label.style.gridRow=rowNumber;label.style.flexDirection='column';label.style.gap='5px';}};if(extra){const heading=scope.querySelector('strong');if(heading){heading.style.gridColumn='1/-1';heading.style.gridRow='1';}setLabel('payment_type','Ödeme Şekli','1/-1','2');}setLabel('bank_name','Banka','1/2',row(2),paymentType==='credit_card'||paymentType==='mail_order');setLabel('current_account_id','Cari Hesap','2/3',row(2),paymentType==='mail_order');setLabel('installment_count',paymentType==='term'?'Vade Sayısı':'KK Taksit Sayısı',paymentType==='term'?'1/2':'2/3',row(2),paymentType==='credit_card'||paymentType==='term');setLabel('commission_rate',paymentType==='term'?'Aylık Ödeme':'Komisyon Oranı',paymentType==='term'?'2/3':'2/3',row(paymentType==='term'?2:3),paymentType==='credit_card'||paymentType==='term');setLabel('amount',paymentType==='term'?'Toplam':'Tutar',paymentType==='credit_card'?'1/2':'1/-1',row(paymentType==='credit_card'?3:3),true);};
  const salesPaymentToCashType=value=>({'Nakit':'cash','Kredi Kartı':'credit_card','Mail Order':'mail_order','Vadeli':'term'}[value]||'');
  const hidePrimaryTermMonthlyField=()=>{if(paymentSelect?.value!=='Vadeli')return;commissionLabel?.remove();const section=cashSourceForm?.querySelector('section'),totalLabel=cashSourceForm?.querySelector('[name="amount"]')?.closest('label');if(section)section.style.setProperty('grid-template-columns','repeat(2,minmax(0,1fr))','important');if(totalLabel){const textNode=[...totalLabel.childNodes].find(node=>node.nodeType===Node.TEXT_NODE);if(textNode)textNode.nodeValue='Toplam';totalLabel.style.setProperty('display','flex','important');totalLabel.style.setProperty('grid-column','2 / 3','important');totalLabel.style.setProperty('grid-row','2','important');}};paymentSelect?.addEventListener('change',()=>setTimeout(hidePrimaryTermMonthlyField,0));setTimeout(hidePrimaryTermMonthlyField,0);
  const syncPrimaryPaymentFields=()=>{const type=cashPaymentSelect?.value||salesPaymentToCashType(paymentSelect?.value||'')||'cash';syncPaymentFields(cashSourceForm,type);};
  paymentSelect?.addEventListener('change',syncPrimaryPaymentFields);cashIconLink?.addEventListener('click',()=>setTimeout(syncPrimaryPaymentFields,0));cashSourceForm?.addEventListener('change',event=>{if(event.target.matches('[name="extra_payment_type"]'))syncPaymentFields(event.target.closest('[data-extra-income]'),event.target.value);});new MutationObserver(()=>{const extraPayment=cashSourceForm?.querySelector('[name="extra_payment_type"]');if(extraPayment)syncPaymentFields(extraPayment.closest('[data-extra-income]'),extraPayment.value);}).observe(cashSourceForm||document.body,{childList:true,subtree:true});syncPrimaryPaymentFields();
  const alignExtraPaymentFields=()=>{const extraSection=cashSourceForm?.querySelector('[data-extra-income]');if(!extraSection)return;extraSection.style.gridTemplateColumns='repeat(2,minmax(0,1fr))';syncPaymentFields(extraSection,extraSection.querySelector('[name="extra_payment_type"]')?.value||'cash');const description=extraSection.querySelector('[name="extra_description"]');if(description&&(description.value.trim()===''||description.value.trim()==='Satış tahsilatı'))description.value=<?=json_encode($patient['full_name'] . ' — Satış tahsilatı', JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;};new MutationObserver(alignExtraPaymentFields).observe(cashSourceForm||document.body,{childList:true,subtree:true});alignExtraPaymentFields();
  const placeExtraAmountBeforeDescription=()=>{const extraSection=cashSourceForm?.querySelector('[data-extra-income]');if(!extraSection)return;const type=extraSection.querySelector('[name="extra_payment_type"]')?.value||'cash',amountLabel=extraSection.querySelector('[name="extra_amount"]')?.closest('label'),descriptionLabel=extraSection.querySelector('[name="extra_description"]')?.closest('label');if(amountLabel){amountLabel.style.gridColumn='1/-1';amountLabel.style.gridRow=type==='cash'?'3':'4';}if(descriptionLabel){descriptionLabel.style.gridColumn='1/-1';descriptionLabel.style.gridRow=type==='cash'?'4':'5';}};new MutationObserver(placeExtraAmountBeforeDescription).observe(cashSourceForm||document.body,{childList:true,subtree:true});cashSourceForm?.addEventListener('change',event=>{if(event.target.matches('[name="extra_payment_type"]'))placeExtraAmountBeforeDescription();});placeExtraAmountBeforeDescription();
  const renderExtraTermSchedule=()=>{const extraSection=cashSourceForm?.querySelector('[data-extra-income]');if(!extraSection)return;const type=extraSection.querySelector('[name="extra_payment_type"]')?.value||'cash',countInput=extraSection.querySelector('[name="extra_installment_count"]'),amountInput=extraSection.querySelector('[name="extra_amount"]'),amountLabel=amountInput?.closest('label'),rateLabel=extraSection.querySelector('[name="extra_commission_rate"]')?.closest('label'),descriptionLabel=extraSection.querySelector('[name="extra_description"]')?.closest('label');extraSection.querySelector('[data-term-schedule]')?.remove();if(type!=='term'){if(amountLabel)amountLabel.style.display='flex';if(rateLabel)rateLabel.style.display=type==='credit_card'?'flex':'none';return;}if(countInput){countInput.min='1';countInput.max='12';countInput.value=String(Math.min(12,Math.max(1,Number(countInput.value)||1)));}if(amountLabel)amountLabel.style.display='none';if(rateLabel)rateLabel.style.display='none';let savedPlan=[];try{const saved=(window.__savedCashRecords||[]).find(record=>record.payment_type==='term'&&record.term_schedule);savedPlan=JSON.parse(saved?.term_schedule||'[]')||[];}catch(_){}const count=Number(countInput?.value||1),schedule=document.createElement('div'),baseDate=<?=json_encode(date('Y-m-d'))?>;schedule.dataset.termSchedule='1';schedule.style.cssText='grid-column:1/-1;grid-row:4;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 12px;padding-top:4px';const updateTotal=()=>{if(amountInput)amountInput.value=String([...schedule.querySelectorAll('[data-term-amount]')].reduce((sum,input)=>sum+(parseTurkishMoney(input.value)||0),0));};for(let index=0;index<count;index++){const savedInstallment=savedPlan[index]||{},dateValue=new Date(baseDate+'T00:00:00');dateValue.setMonth(dateValue.getMonth()+index);const dateLabel=document.createElement('label');dateLabel.style.cssText='display:flex;flex-direction:column;gap:5px';dateLabel.textContent=(index+1)+'. Vade Tarihi';const dateInput=document.createElement('input');dateInput.type='date';dateInput.name='extra_term_date[]';dateInput.value=savedInstallment.date||dateValue.toISOString().slice(0,10);dateLabel.append(dateInput);const paymentLabel=document.createElement('label');paymentLabel.style.cssText='display:flex;flex-direction:column;gap:5px';paymentLabel.textContent=(index+1)+'. Aylık Ödeme';const paymentInput=document.createElement('input');paymentInput.name='extra_term_amount[]';paymentInput.inputMode='decimal';paymentInput.setAttribute('data-term-amount','1');paymentInput.value=(parseTurkishMoney(savedInstallment.amount)||0)>0?formatTurkishMoney(parseTurkishMoney(savedInstallment.amount)):'';paymentInput.addEventListener('input',updateTotal);paymentLabel.append(paymentInput);schedule.append(dateLabel,paymentLabel);}descriptionLabel?.before(schedule);if(descriptionLabel)descriptionLabel.style.gridRow='5';updateTotal();};cashSourceForm?.addEventListener('change',event=>{if(event.target.matches('[name="extra_payment_type"],[name="extra_installment_count"]'))renderExtraTermSchedule();});cashSourceForm?.addEventListener('input',event=>{if(event.target.matches('[name="extra_installment_count"]'))renderExtraTermSchedule();});renderExtraTermSchedule();
  const shrinkTermCount=()=>{const input=cashSourceForm?.querySelector('[name="extra_installment_count"]');if(input)input.style.cssText+=';width:7ch;min-width:7ch;max-width:7ch;padding-left:4px;padding-right:4px';};new MutationObserver(shrinkTermCount).observe(cashSourceForm||document.body,{childList:true});shrinkTermCount();
  const redesignTermSchedule=()=>{const extraSection=cashSourceForm?.querySelector('[data-extra-income]');if(!extraSection||extraSection.querySelector('[name="extra_payment_type"]')?.value!=='term')return;extraSection.querySelector('[name="extra_commission_rate"]')?.closest('label')?.style.setProperty('display','none','important');extraSection.querySelector('[name="extra_amount"]')?.closest('label')?.style.setProperty('display','none','important');const schedule=extraSection.querySelector('[data-term-schedule]');if(!schedule)return;schedule.style.cssText+=';border-top:1px solid rgba(22,64,75,.18);padding-top:12px;margin-top:2px';if(!schedule.querySelector('[data-term-title]')){const title=document.createElement('strong');title.dataset.termTitle='1';title.textContent='Vade Planı';title.style.cssText='grid-column:1/-1;font-size:13px;color:#16404b';schedule.prepend(title);}const descriptionLabel=extraSection.querySelector('[name="extra_description"]')?.closest('label');if(descriptionLabel)descriptionLabel.style.gridRow='5';};new MutationObserver(redesignTermSchedule).observe(cashSourceForm||document.body,{childList:true,subtree:true});cashSourceForm?.addEventListener('change',event=>{if(event.target.matches('[name="extra_payment_type"],[name="extra_installment_count"]'))setTimeout(redesignTermSchedule,0);});redesignTermSchedule();
  const addTermPaidCheckboxes=()=>{const schedule=cashSourceForm?.querySelector('[data-term-schedule]');if(!schedule)return;schedule.querySelectorAll('[data-term-amount]').forEach(input=>{if(input.parentElement?.matches('[data-term-payment]'))return;const wrapper=document.createElement('span');wrapper.dataset.termPayment='1';wrapper.style.cssText='display:flex;align-items:center;gap:8px';input.before(wrapper);wrapper.append(input);const paidLabel=document.createElement('label');paidLabel.style.cssText='display:inline-flex;align-items:center;gap:4px;white-space:nowrap;font-size:12px';const paid=document.createElement('input');paid.type='checkbox';paid.name='extra_term_paid[]';paid.value='1';paidLabel.append(paid,' Ödendi');wrapper.append(paidLabel);});};new MutationObserver(addTermPaidCheckboxes).observe(cashSourceForm||document.body,{childList:true,subtree:true});addTermPaidCheckboxes();
  const compactTermPayments=()=>{const schedule=cashSourceForm?.querySelector('[data-term-schedule]');if(!schedule)return;schedule.querySelectorAll('[data-term-payment]').forEach(wrapper=>{const input=wrapper.querySelector('[data-term-amount]'),paidLabel=wrapper.querySelector('label'),parent=wrapper.parentElement;if(input)input.style.cssText+=';width:62%;max-width:150px';if(!paidLabel||!parent||parent.querySelector('[data-term-payment-head]'))return;const title=[...parent.childNodes].find(node=>node.nodeType===Node.TEXT_NODE),head=document.createElement('span');head.dataset.termPaymentHead='1';head.style.cssText='display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%';head.textContent=title?.nodeValue?.trim()||'Aylık Ödeme';title?.remove();head.append(paidLabel);parent.prepend(head);});};new MutationObserver(compactTermPayments).observe(cashSourceForm||document.body,{childList:true,subtree:true});compactTermPayments();
  const alignTermPaidBoxes=()=>{const schedule=cashSourceForm?.querySelector('[data-term-schedule]');if(!schedule)return;schedule.querySelectorAll('[data-term-payment]').forEach(wrapper=>{if(wrapper.querySelector('[data-term-paid-box]'))return;const parent=wrapper.parentElement,head=parent?.querySelector('[data-term-payment-head]'),oldBox=head?.querySelector('input[type="checkbox"]');if(!head||!oldBox)return;oldBox.remove();const paidLabel=head.querySelector('label');if(paidLabel){paidLabel.textContent='Ödendi';paidLabel.style.cssText='font-size:12px;white-space:nowrap'}const paidBox=document.createElement('input');paidBox.type='checkbox';paidBox.name='extra_term_paid[]';paidBox.value='1';paidBox.dataset.termPaidBox='1';paidBox.style.cssText='width:18px;height:18px;margin:0 0 0 8px';wrapper.append(paidBox);});};new MutationObserver(alignTermPaidBoxes).observe(cashSourceForm||document.body,{childList:true,subtree:true});alignTermPaidBoxes();
  const alignTermPlanColumns=()=>{const schedule=cashSourceForm?.querySelector('[data-term-schedule]');if(!schedule)return;schedule.style.gridTemplateColumns='145px 145px';schedule.style.columnGap='10px';schedule.querySelectorAll('input[type="date"],[data-term-amount]').forEach(input=>input.style.cssText+=';width:145px;min-width:145px;max-width:145px;box-sizing:border-box');schedule.querySelectorAll('[data-term-payment]').forEach(wrapper=>{const parent=wrapper.parentElement,head=parent?.querySelector('[data-term-payment-head]');if(!head)return;head.style.cssText='display:grid;grid-template-columns:126px 18px;gap:8px;align-items:center;width:100%';const label=head.querySelector('label');if(label){label.style.justifySelf='center';label.style.transform='translateX(14px)';}});};new MutationObserver(alignTermPlanColumns).observe(cashSourceForm||document.body,{childList:true,subtree:true});alignTermPlanColumns();
  const movePaidHeadingRight=()=>cashSourceForm?.querySelectorAll('[data-term-payment-head] label').forEach(label=>label.style.transform='translateX(24px)');new MutationObserver(movePaidHeadingRight).observe(cashSourceForm||document.body,{childList:true,subtree:true});movePaidHeadingRight();
  const matchExtraTermPlanToPrimary=()=>{const schedule=cashSourceForm?.querySelector('[data-extra-income] [data-term-schedule]');if(!schedule)return;schedule.style.setProperty('grid-template-columns','145px 145px','important');schedule.style.setProperty('column-gap','30px','important');schedule.style.setProperty('row-gap','10px','important');schedule.querySelectorAll('input[type="date"],[data-term-amount]').forEach(input=>input.style.setProperty('width','145px','important'));schedule.querySelectorAll('[data-term-payment-head]').forEach(head=>head.style.setProperty('grid-template-columns','126px 18px','important'));schedule.querySelectorAll('[data-term-paid-box]').forEach(box=>{box.style.setProperty('width','22px','important');box.style.setProperty('height','22px','important');});};new MutationObserver(matchExtraTermPlanToPrimary).observe(cashSourceForm||document.body,{childList:true,subtree:true});cashSourceForm?.addEventListener('change',event=>{if(event.target.matches('[name="extra_payment_type"],[name="extra_installment_count"]'))setTimeout(matchExtraTermPlanToPrimary,0);});matchExtraTermPlanToPrimary();
  const enlargePaidBoxes=()=>cashSourceForm?.querySelectorAll('[data-term-paid-box]').forEach(box=>box.style.cssText+=';width:22px;height:22px');new MutationObserver(enlargePaidBoxes).observe(cashSourceForm||document.body,{childList:true,subtree:true});enlargePaidBoxes();
  const alignPrimaryPaymentFields=()=>{const section=cashSourceForm?.querySelector('section');if(!section)return;section.style.gridTemplateColumns='repeat(2,minmax(0,1fr))';section.querySelectorAll('label').forEach(label=>label.style.cssText+=';display:flex;flex-direction:column;gap:5px;min-width:0');section.querySelectorAll('input,select,textarea').forEach(field=>field.style.cssText+=';width:100%;box-sizing:border-box');syncPaymentFields(section,cashPaymentSelect?.value||salesPaymentToCashType(paymentSelect?.value||'')||'cash');};alignPrimaryPaymentFields();
  const renderPrimaryTermSchedule=()=>{const section=cashSourceForm?.querySelector('section');if(!section)return;const type=salesPaymentToCashType(paymentSelect?.value||'')||cashPaymentSelect?.value||'cash',countInput=section.querySelector('[name="installment_count"]'),amountInput=section.querySelector('[name="amount"]'),amountLabel=amountInput?.closest('label'),rateLabel=section.querySelector('[name="commission_rate"]')?.closest('label'),descriptionLabel=section.querySelector('[name="description"]')?.closest('label');section.querySelector('[data-primary-term-schedule]')?.remove();if(type!=='term'){if(amountLabel)amountLabel.style.display='flex';if(rateLabel)rateLabel.style.display=type==='credit_card'?'flex':'none';return;}if(countInput){countInput.min='1';countInput.max='12';countInput.style.cssText+=';width:7ch;min-width:7ch;max-width:7ch';countInput.value=String(Math.min(12,Math.max(1,Number(countInput.value)||1)));}if(amountLabel)amountLabel.style.display='none';if(rateLabel)rateLabel.style.display='none';const count=Number(countInput?.value||1),schedule=document.createElement('div'),baseDate=section.querySelector('[name="transaction_date"]')?.value||<?=json_encode(date('Y-m-d'))?>;schedule.dataset.primaryTermSchedule='1';schedule.style.cssText='grid-column:1/-1;grid-row:4;display:grid;grid-template-columns:145px 145px;gap:10px;padding-top:12px;border-top:1px solid rgba(22,64,75,.18)';const title=document.createElement('strong');title.textContent='Vade Planı';title.style.cssText='grid-column:1/-1;font-size:13px';schedule.append(title);const updateTotal=()=>{if(amountInput)amountInput.value=String([...schedule.querySelectorAll('[data-primary-term-amount]')].reduce((sum,input)=>sum+(parseTurkishMoney(input.value)||0),0));};for(let index=0;index<count;index++){const dateValue=new Date(baseDate+'T00:00:00');dateValue.setMonth(dateValue.getMonth()+index);const dateLabel=document.createElement('label');dateLabel.style.cssText='display:flex;flex-direction:column;gap:5px';dateLabel.textContent=(index+1)+'. Vade Tarihi';const dateInput=document.createElement('input');dateInput.type='date';dateInput.name='term_date[]';dateInput.value=dateValue.toISOString().slice(0,10);dateInput.style.cssText='width:145px;box-sizing:border-box';dateLabel.append(dateInput);const paymentLabel=document.createElement('label');paymentLabel.style.cssText='display:flex;flex-direction:column;gap:5px';const head=document.createElement('span');head.style.cssText='display:grid;grid-template-columns:126px 18px;gap:8px;align-items:center';head.append((index+1)+'. Aylık Ödeme');const paidTitle=document.createElement('span');paidTitle.textContent='Ödendi';paidTitle.style.cssText='font-size:12px;justify-self:center;transform:translateX(24px)';head.append(paidTitle);paymentLabel.append(head);const line=document.createElement('span');line.style.cssText='display:flex;align-items:center;gap:8px';const paymentInput=document.createElement('input');paymentInput.name='term_amount[]';paymentInput.inputMode='decimal';paymentInput.setAttribute('data-primary-term-amount','1');paymentInput.style.cssText='width:145px;box-sizing:border-box';paymentInput.addEventListener('input',updateTotal);const paid=document.createElement('input');paid.type='checkbox';paid.name='term_paid[]';paid.value='1';paid.style.cssText='width:22px;height:22px;margin:0';line.append(paymentInput,paid);paymentLabel.append(line);schedule.append(dateLabel,paymentLabel);}descriptionLabel?.before(schedule);if(descriptionLabel)descriptionLabel.style.gridRow='5';updateTotal();};paymentSelect?.addEventListener('change',()=>setTimeout(renderPrimaryTermSchedule,0));cashSourceForm?.addEventListener('input',event=>{if(event.target.matches('[name="installment_count"]'))renderPrimaryTermSchedule();});renderPrimaryTermSchedule();
  const spacePrimaryTermColumns=()=>{const schedule=cashSourceForm?.querySelector('[data-primary-term-schedule]');if(schedule)schedule.style.columnGap='30px';};new MutationObserver(spacePrimaryTermColumns).observe(cashSourceForm||document.body,{childList:true,subtree:true});spacePrimaryTermColumns();
  const placePrimaryAmountBeforeDescription=()=>{const section=cashSourceForm?.querySelector('section');if(!section)return;const type=cashPaymentSelect?.value||salesPaymentToCashType(paymentSelect?.value||'')||'cash',amountLabel=section.querySelector('[name="amount"]')?.closest('label'),commissionLabel=section.querySelector('[name="commission_rate"]')?.closest('label'),descriptionLabel=section.querySelector('[name="description"]')?.closest('label');if(type==='term')return;if(amountLabel){amountLabel.style.gridColumn=type==='credit_card'?'1/2':'1/-1';amountLabel.style.gridRow='3';}if(commissionLabel&&type==='credit_card'){commissionLabel.style.gridColumn='2/3';commissionLabel.style.gridRow='3';}if(descriptionLabel){descriptionLabel.style.gridColumn='1/-1';descriptionLabel.style.gridRow='4';}};paymentSelect?.addEventListener('change',()=>setTimeout(placePrimaryAmountBeforeDescription,0));placePrimaryAmountBeforeDescription();
  const placePrimaryTermTotal=()=>{const section=cashSourceForm?.querySelector('section');if(!section)return;const type=cashPaymentSelect?.value||salesPaymentToCashType(paymentSelect?.value||'')||'cash',amountLabel=section.querySelector('[name="amount"]')?.closest('label'),rateLabel=section.querySelector('[name="commission_rate"]')?.closest('label');if(type!=='term'&&!section.querySelector('[data-primary-term-schedule]'))return;if(rateLabel)rateLabel.style.setProperty('display','none','important');if(amountLabel){const textNode=[...amountLabel.childNodes].find(node=>node.nodeType===Node.TEXT_NODE);if(textNode)textNode.nodeValue='Toplam';amountLabel.style.setProperty('display','flex','important');amountLabel.style.gridColumn='2/3';amountLabel.style.gridRow='2';}};paymentSelect?.addEventListener('change',()=>setTimeout(placePrimaryTermTotal,0));cashSourceForm?.addEventListener('input',event=>{if(event.target.matches('[name="installment_count"]'))setTimeout(placePrimaryTermTotal,0);});placePrimaryTermTotal();
  cashSourceForm?.addEventListener('input',event=>{if(event.target.matches('[name="installment_count"],[name="extra_installment_count"]'))event.stopImmediatePropagation();},true);cashSourceForm?.addEventListener('change',event=>{if(event.target.matches('[name="installment_count"]')){renderPrimaryTermSchedule();placePrimaryTermTotal();}if(event.target.matches('[name="extra_installment_count"]'))renderExtraTermSchedule();});
  const cachePrimaryTermPlan=()=>{const section=cashSourceForm?.querySelector('section'),schedule=section?.querySelector('[data-primary-term-schedule]');if(!section||!schedule)return;const dates=[...schedule.querySelectorAll('[name="term_date[]"]')],amounts=[...schedule.querySelectorAll('[data-primary-term-amount]')],paid=[...schedule.querySelectorAll('[name="term_paid[]"]')];section.dataset.primaryTermPlan=JSON.stringify(dates.map((date,index)=>({date:date.value,amount:amounts[index]?.value||'',paid:!!paid[index]?.checked})));};
  const restorePrimaryTermPlan=()=>{const section=cashSourceForm?.querySelector('section'),savedPlan=section?.dataset.primaryTermPlan;if(!section||!savedPlan)return;let plan=[];try{plan=JSON.parse(savedPlan)}catch(_){return;}const dates=[...section.querySelectorAll('[name="term_date[]"]')],amounts=[...section.querySelectorAll('[data-primary-term-amount]')],paid=[...section.querySelectorAll('[name="term_paid[]"]')];plan.forEach((item,index)=>{if(dates[index])dates[index].value=item.date||dates[index].value;if(amounts[index])amounts[index].value=item.amount||'';if(paid[index])paid[index].checked=!!item.paid;});const total=section.querySelector('[name="amount"]');if(total)total.value=String(amounts.reduce((sum,input)=>sum+(parseTurkishMoney(input.value)||0),0));delete section.dataset.primaryTermPlan;};
  document.addEventListener('input',event=>{if(event.target.matches('[name="installment_count"]')&&cashSourceForm?.contains(event.target))cachePrimaryTermPlan();},true);cashSourceForm?.addEventListener('change',event=>{if(event.target.matches('[name="installment_count"]'))setTimeout(restorePrimaryTermPlan,0);});
  new MutationObserver(placePrimaryTermTotal).observe(cashSourceForm||document.body,{childList:true,subtree:true});cashSourceForm?.addEventListener('focusin',event=>{if(event.target.matches('[name="installment_count"],[name="extra_installment_count"]'))event.target.select();});
  const enforcePrimaryTermLayout=()=>{const section=cashSourceForm?.querySelector('section');if(!section?.querySelector('[data-primary-term-schedule]'))return;const rateLabel=section.querySelector('[name="commission_rate"]')?.closest('label'),amountLabel=section.querySelector('[name="amount"]')?.closest('label');if(rateLabel){rateLabel.hidden=true;rateLabel.style.setProperty('display','none','important');}if(amountLabel){const textNode=[...amountLabel.childNodes].find(node=>node.nodeType===Node.TEXT_NODE);if(textNode)textNode.nodeValue='Toplam';amountLabel.hidden=false;amountLabel.style.setProperty('display','flex','important');amountLabel.style.gridColumn='2/3';amountLabel.style.gridRow='2';}};new MutationObserver(enforcePrimaryTermLayout).observe(cashSourceForm||document.body,{childList:true,subtree:true});enforcePrimaryTermLayout();
  const requireTermPayments=()=>cashSourceForm?.querySelectorAll('[data-primary-term-amount]').forEach(input=>{input.required=true;input.setAttribute('min','0.01');});new MutationObserver(requireTermPayments).observe(cashSourceForm||document.body,{childList:true,subtree:true});requireTermPayments();
  const forceLabel=(section,name,column,row,visible=true)=>{const label=section.querySelector(`[name="${name}"]`)?.closest('label');if(!label)return;label.hidden=!visible;label.style.setProperty('display',visible?'flex':'none','important');if(visible){label.style.setProperty('grid-column',column,'important');label.style.setProperty('grid-row',row,'important');label.style.setProperty('min-width','0','important');}};
  const repairIncomeLayouts=()=>{const primary=cashSourceForm?.querySelector('section');if(primary?.querySelector('[data-primary-term-schedule]')){primary.style.setProperty('display','grid','important');primary.style.setProperty('grid-template-columns','repeat(2,minmax(0,1fr))','important');forceLabel(primary,'installment_count','1 / 2','2');forceLabel(primary,'amount','2 / 3','2');forceLabel(primary,'commission_rate','1 / 2','3',false);const schedule=primary.querySelector('[data-primary-term-schedule]');schedule.style.setProperty('grid-column','1 / -1','important');schedule.style.setProperty('grid-row','3','important');forceLabel(primary,'description','1 / -1','4');}else if(primary&&(salesPaymentToCashType(paymentSelect?.value||'')||cashPaymentSelect?.value)==='credit_card'){primary.style.setProperty('display','grid','important');primary.style.setProperty('grid-template-columns','repeat(2,minmax(0,1fr))','important');forceLabel(primary,'bank_name','1 / 2','2');forceLabel(primary,'installment_count','2 / 3','2');forceLabel(primary,'amount','1 / 2','3');forceLabel(primary,'commission_rate','2 / 3','3');forceLabel(primary,'description','1 / -1','4');}const extra=cashSourceForm?.querySelector('[data-extra-income]');if(!extra)return;extra.style.setProperty('display','grid','important');extra.style.setProperty('grid-template-columns','repeat(2,minmax(0,1fr))','important');extra.querySelectorAll('input,select,textarea').forEach(field=>field.style.setProperty('box-sizing','border-box','important'));const type=extra.querySelector('[name="extra_payment_type"]')?.value||'cash';if(type!=='term')extra.querySelector('[data-term-schedule]')?.remove();forceLabel(extra,'extra_payment_type','1 / -1','2');if(type==='credit_card'){forceLabel(extra,'extra_bank_name','1 / 2','3');forceLabel(extra,'extra_current_account_id','2 / 3','3',false);forceLabel(extra,'extra_installment_count','2 / 3','3');forceLabel(extra,'extra_commission_rate','2 / 3','4');forceLabel(extra,'extra_amount','1 / 2','4');forceLabel(extra,'extra_description','1 / -1','5');}else if(type==='mail_order'){forceLabel(extra,'extra_bank_name','1 / 2','3');forceLabel(extra,'extra_current_account_id','2 / 3','3');forceLabel(extra,'extra_installment_count','1 / 2','4',false);forceLabel(extra,'extra_commission_rate','2 / 3','4',false);forceLabel(extra,'extra_amount','1 / -1','4');forceLabel(extra,'extra_description','1 / -1','5');}else if(type==='cash'){forceLabel(extra,'extra_bank_name','1 / 2','3',false);forceLabel(extra,'extra_current_account_id','2 / 3','3',false);forceLabel(extra,'extra_installment_count','1 / 2','3',false);forceLabel(extra,'extra_commission_rate','2 / 3','3',false);forceLabel(extra,'extra_amount','1 / -1','3');forceLabel(extra,'extra_description','1 / -1','4');}};new MutationObserver(repairIncomeLayouts).observe(cashSourceForm||document.body,{childList:true,subtree:true});cashSourceForm?.addEventListener('change',()=>setTimeout(repairIncomeLayouts,0));setTimeout(repairIncomeLayouts,0);
  const enforceCreditCardFields=()=>{const primary=cashSourceForm?.querySelector('section');if(!primary||cashPaymentSelect?.value!=='credit_card')return;primary.style.setProperty('display','grid','important');primary.style.setProperty('grid-template-columns','repeat(2,minmax(0,1fr))','important');const amountLabel=primary.querySelector('[name="amount"]')?.closest('label'),commissionLabel=primary.querySelector('[name="commission_rate"]')?.closest('label'),setTitle=(label,title)=>{const node=[...(label?.childNodes||[])].find(item=>item.nodeType===Node.TEXT_NODE);if(node)node.nodeValue=title;};forceLabel(primary,'bank_name','1 / 2','2');forceLabel(primary,'installment_count','2 / 3','2');forceLabel(primary,'amount','1 / 2','3');forceLabel(primary,'commission_rate','2 / 3','3');forceLabel(primary,'description','1 / -1','4');if(amountLabel){setTitle(amountLabel,'Tutar');amountLabel.hidden=false;amountLabel.style.setProperty('display','flex','important');amountLabel.style.setProperty('grid-column','1 / 2','important');amountLabel.style.setProperty('grid-row','3','important');}if(commissionLabel){setTitle(commissionLabel,'Komisyon Oranı');commissionLabel.hidden=false;commissionLabel.style.setProperty('display','flex','important');commissionLabel.style.setProperty('grid-column','2 / 3','important');commissionLabel.style.setProperty('grid-row','3','important');}};cashPaymentSelect?.addEventListener('change',()=>setTimeout(enforceCreditCardFields,50));cashSourceForm?.addEventListener('change',()=>setTimeout(enforceCreditCardFields,50));new MutationObserver(()=>setTimeout(enforceCreditCardFields,50)).observe(cashSourceForm||document.body,{childList:true,subtree:true});setTimeout(enforceCreditCardFields,80);setTimeout(enforceCreditCardFields,250);
  const alignPrimaryTermSummary=()=>{const section=cashSourceForm?.querySelector('section'),schedule=section?.querySelector('[data-primary-term-schedule]');if(!section||!schedule)return;const placeLabel=(name,column,row)=>{const label=section.querySelector(`[name="${name}"]`)?.closest('label');if(!label)return;label.style.setProperty('display','flex','important');label.style.setProperty('grid-column',column,'important');label.style.setProperty('grid-row',row,'important');};section.style.setProperty('grid-template-columns','minmax(190px,1.25fr) minmax(80px,.35fr) minmax(190px,1.25fr)','important');placeLabel('transaction_date','1 / 2','1');placeLabel('installment_count','2 / 3','1');placeLabel('amount','3 / 4','1');schedule.style.setProperty('display','grid','important');schedule.style.setProperty('grid-column','1 / -1','important');schedule.style.setProperty('grid-row','2','important');placeLabel('description','1 / -1','3');};const queuePrimaryTermSummary=()=>[0,80,220].forEach(delay=>setTimeout(alignPrimaryTermSummary,delay));new MutationObserver(queuePrimaryTermSummary).observe(cashSourceForm||document.body,{childList:true,subtree:true});cashSourceForm?.addEventListener('change',queuePrimaryTermSummary);cashPaymentSelect?.addEventListener('change',queuePrimaryTermSummary);paymentSelect?.addEventListener('change',queuePrimaryTermSummary);cashIconLink?.addEventListener('click',queuePrimaryTermSummary);queuePrimaryTermSummary();
  const clearPrimaryTermScheduleForCreditCard=()=>{if(cashPaymentSelect?.value==='credit_card')cashSourceForm?.querySelector('section [data-primary-term-schedule]')?.remove();};cashPaymentSelect?.addEventListener('change',()=>setTimeout(clearPrimaryTermScheduleForCreditCard,80));setTimeout(clearPrimaryTermScheduleForCreditCard,300);
  const clearExtraScheduleForNonTerm=()=>{const extra=cashSourceForm?.querySelector('[data-extra-income]');if(extra&&extra.querySelector('[name="extra_payment_type"]')?.value!=='term')extra.querySelectorAll('[data-term-schedule],[data-primary-term-schedule]').forEach(schedule=>schedule.remove());repairIncomeLayouts();};cashSourceForm?.addEventListener('change',event=>{if(event.target.matches('[name="extra_payment_type"]'))setTimeout(clearExtraScheduleForNonTerm,20);});new MutationObserver(clearExtraScheduleForNonTerm).observe(cashSourceForm||document.body,{childList:true,subtree:true});clearExtraScheduleForNonTerm();
  // Kredi kartı alanları yalnızca aşağıdaki tek yerleşim kuralı tarafından düzenlenir.
  const enforceExtraCreditCardFields=()=>{const extra=cashSourceForm?.querySelector('[data-extra-income]');if(!extra||extra.querySelector('[name="extra_payment_type"]')?.value!=='credit_card')return;let amount=extra.querySelector('[name="extra_amount"]'),commission=extra.querySelector('[name="extra_commission_rate"]');if(!amount){amount=document.createElement('input');amount.name='extra_amount';amount.inputMode='decimal';extra.append(amount);}if(!commission){commission=document.createElement('input');commission.name='extra_commission_rate';commission.inputMode='decimal';extra.append(commission);}const makeLabel=(field,title,column)=>{let label=field.closest('label');if(!label){label=document.createElement('label');field.before(label);label.append(field);}label.replaceChildren(document.createTextNode(title),field);label.dataset.creditLayout='1';label.hidden=false;label.style.cssText='display:flex!important;flex-direction:column;gap:5px;grid-column:'+column+';grid-row:4;min-width:0';field.style.cssText+=';display:block!important;width:100%!important;box-sizing:border-box';return label;};makeLabel(amount,'Tutar','1 / 2');makeLabel(commission,'Komisyon Oranı','2 / 3');};cashSourceForm?.addEventListener('change',event=>{if(event.target.matches('[name="extra_payment_type"]'))setTimeout(enforceExtraCreditCardFields,60);});setTimeout(enforceExtraCreditCardFields,300);
  document.addEventListener('click',event=>{if(!event.target.closest('[aria-label="Bir gelir kaydı daha ekle"]'))return;setTimeout(()=>cashSourceForm?.querySelector('[data-extra-income]')?.querySelectorAll('[data-term-schedule],[data-primary-term-schedule]').forEach(schedule=>schedule.remove()),0);});
  const termLayoutStyle=document.createElement('style');termLayoutStyle.textContent='form[action*="cash.php"] section:has([data-primary-term-schedule]){display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important}form[action*="cash.php"] section:has([data-primary-term-schedule]) label:has([name="commission_rate"]){display:none!important}form[action*="cash.php"] section:has([data-primary-term-schedule]) label:has([name="installment_count"]){display:flex!important;grid-column:1/2!important;grid-row:2!important}form[action*="cash.php"] section:has([data-primary-term-schedule]) label:has([name="amount"]){display:flex!important;grid-column:2/3!important;grid-row:2!important}form[action*="cash.php"] section:has([data-primary-term-schedule]) [data-primary-term-schedule]{grid-column:1/-1!important;grid-row:3!important;margin-top:8px!important}form[action*="cash.php"] section:has([data-primary-term-schedule]) label:has([name="description"]){grid-column:1/-1!important;grid-row:4!important;margin-top:8px!important}';document.head.append(termLayoutStyle);
  // Kasa ikonu, kasa işlemi tamamlanan kartta ilgili hareketleri görüntüler.
  if(cashIconLink)cashIconLink.href='#gelir-kayit';
  if(cashIconLink&&cashSourceForm){cashIconLink.addEventListener('click',()=>{const description=cashSourceForm.querySelector('[name="description"]');if(description&&(description.value.trim()===''||description.value.trim()==='Satış tahsilatı'))description.value=<?=json_encode($patient['full_name'] . ' — Satış tahsilatı', JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;},true);}
  if(<?=json_encode($savedCashRecord !== [])?>&&cashIconLink&&cashSourceForm){cashIconLink.addEventListener('click',event=>{event.preventDefault();event.stopImmediatePropagation();const recordModal=cashSourceForm.parentElement;if(recordModal){recordModal.hidden=false;recordModal.style.display='grid';}},true);}
  const syncCashIcon=()=>{const active=<?=json_encode($savedCashRecord !== [])?>||<?=json_encode($serviceNameLocked)?>||!!paymentSelect?.value;if(!cashIconLink)return;cashIconLink.style.pointerEvents=active?'auto':'none';cashIconLink.style.opacity=active?'1':'.38';cashIconLink.tabIndex=active?0:-1;cashIconLink.setAttribute('aria-disabled',active?'false':'true');};
  paymentSelect?.addEventListener('change',()=>{
    syncCashIcon();
    if(paymentSelect.value&&cashSourceForm?.dataset.saved!=='1'&&!<?=json_encode($serviceNameLocked)?>)cashIconLink?.click();
  });syncCashIcon();if(<?=json_encode($openIncomeRecord)?>)setTimeout(()=>{cashIconLink?.click();const pageUrl=new URL(location.href);pageUrl.searchParams.delete('open_income_record');history.replaceState(null,'',pageUrl.pathname+(pageUrl.search||''));},0);
  const detailFields=detailsModal?[...detailsModal.querySelectorAll('[name]')]:[], deviceDetails=document.getElementById('hearing-device-details'), addDeviceButton=document.getElementById('add-hearing-device');
  const setProductType=type=>{const body=detailsModal?.querySelector('.repair-body');if(!detailsModal||!body)return;detailsModal.dataset.productType=type;body.classList.remove('sales-product-device','sales-product-consumable','sales-product-charger');const productClass={'İşitme Cihazı':'sales-product-device','Sarf Malzeme':'sales-product-consumable','Şarj Cihazı':'sales-product-charger'}[type];if(productClass)body.classList.add(productClass);};
  const restoreDetails=()=>{try{const saved=JSON.parse(details?.value||'{}');detailFields.forEach(field=>{if(Object.prototype.hasOwnProperty.call(saved,field.name))field.value=saved[field.name]??'';});if(deviceSerialInput)deviceSerialInput.dataset.value=saved.sales_device_serial||'';if(brandSelect){brandSelect.value=saved.sales_brand||'';if(modelSelect)modelSelect.dataset.value=saved.sales_model||'';}if(chargerBrandSelect){chargerBrandSelect.value=saved.sales_charger_brand||'';if(chargerModelSelect)chargerModelSelect.dataset.value=saved.sales_charger_model||'';}formatSgkMoneyFields();if(paymentSelect&&!paymentSelect.value)paymentSelect.value=<?=json_encode($savedCashPaymentType)?>;setProductType(saved.sales_product_type||'');syncCashIcon();}catch(_){if(paymentSelect&&!paymentSelect.value)paymentSelect.value=<?=json_encode($savedCashPaymentType)?>;}};
  const persistDetails=()=>{if(!details)return;const saved={};detailsModal?.querySelectorAll('[name]').forEach(field=>saved[field.name]=field.value);if(detailsModal?.dataset.productType)saved.sales_product_type=detailsModal.dataset.productType;details.value=JSON.stringify(saved);};const invoiceStore=document.createElement('input');invoiceStore.type='hidden';invoiceStore.name='sales_invoice_no';form.append(invoiceStore);const syncInvoice=()=>{invoiceStore.value=detailsModal?.querySelector('[name="sales_invoice_no"]')?.value||'';persistDetails();};detailsModal?.querySelector('[name="sales_invoice_no"]')?.addEventListener('input',syncInvoice);detailsModal?.querySelector('[name="sales_invoice_no"]')?.addEventListener('change',syncInvoice);form.addEventListener('submit',syncInvoice);
  const openDetails=()=>{if(detailsModal){detailsModal.hidden=false;detailsModal.setAttribute('aria-hidden','false');}};
  const closeDetails=()=>{if(detailsModal){detailsModal.hidden=true;detailsModal.setAttribute('aria-hidden','true');}if(productWasRemoved){details.value='';value.value='';service.value='';document.getElementById('sales-details-link')?.remove();document.querySelector('.sales-income-link')?.remove();service.dispatchEvent(new Event('change'));}};
  const loadSavedCashCards=()=>{const records=window.__savedCashRecords||[],first=records[0],second=records[1];if(first?.payment_type==='term'){renderPrimaryTermSchedule();const installment=cashSourceForm?.querySelector('[name="installment_count"]');if(installment)installment.value=String(first.installment_count||1);renderPrimaryTermSchedule();if(!first.term_schedule){const payment=cashSourceForm?.querySelector('[data-primary-term-amount]');if(payment){payment.value=formatTurkishMoney(first.amount);payment.dispatchEvent(new Event('input'));}}placePrimaryTermTotal();}if(!second)return;const addButton=cashSourceForm?.querySelector('[aria-label="Bir gelir kaydı daha ekle"]');if(!addButton)return;addButton.click();setTimeout(()=>{const extra=cashSourceForm?.querySelector('[data-extra-income]');if(!extra)return;const set=(name,value)=>{const field=extra.querySelector(`[name="${name}"]`);if(field)field.value=value??'';};set('extra_payment_type',second.payment_type);set('extra_bank_name',second.bank_name);set('extra_current_account_id',second.current_account_id);set('extra_installment_count',second.installment_count||1);set('extra_commission_rate',second.commission_rate||'');set('extra_amount',formatTurkishMoney(second.amount));set('extra_description',second.description||'');const heading=extra.querySelector('strong');if(heading&&!heading.querySelector('[aria-label="İkinci gelir kaydını sil"]')){const deleteButton=document.createElement('button');deleteButton.type='button';deleteButton.title='İkinci gelir kaydını sil';deleteButton.setAttribute('aria-label','İkinci gelir kaydını sil');deleteButton.innerHTML='<i class="ti tabler-trash"></i>';deleteButton.style.cssText='float:right;display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;padding:0;border:0;border-radius:5px;background:#e6525d;color:#fff;cursor:pointer';deleteButton.addEventListener('click',()=>{if(!confirm('Bu ikinci gelir kaydını silmek istiyor musunuz?'))return;const form=document.createElement('form');form.method='post';form.action=<?=json_encode(url('patient-followup.php?id='.$id))?>;const values={csrf:<?=json_encode(csrf())?>,action:'cash_delete_only',edit_id:<?=json_encode((string)$editId)?>,cash_delete_id:second.id};Object.entries(values).forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.append(input);});document.body.append(form);form.submit();});heading.append(deleteButton);}extra.querySelector('[name="extra_payment_type"]')?.dispatchEvent(new Event('change',{bubbles:true}));if(second.payment_type==='term'&&second.term_schedule){try{const plan=JSON.parse(second.term_schedule)||[];setTimeout(()=>plan.forEach((item,index)=>{const date=extra.querySelectorAll('[name="extra_term_date[]"]')[index],amount=extra.querySelectorAll('[data-term-amount]')[index],paid=extra.querySelectorAll('[name="extra_term_paid[]"]')[index];if(date)date.value=item.date||date.value;if(amount)amount.value=(parseTurkishMoney(item.amount)||0)>0?formatTurkishMoney(parseTurkishMoney(item.amount)):'';if(paid)paid.checked=!!item.paid;}),0);}catch(_){}}repairIncomeLayouts();},30);};
  const restoreSavedTermSchedule=()=>{const first=(window.__savedCashRecords||[])[0];if(first?.payment_type!=='term'||!first.term_schedule)return;let plan=[];try{plan=JSON.parse(first.term_schedule)||[]}catch(_){return;}const dates=[...cashSourceForm.querySelectorAll('[name="term_date[]"]')],amounts=[...cashSourceForm.querySelectorAll('[data-primary-term-amount]')],paid=[...cashSourceForm.querySelectorAll('[name="term_paid[]"]')];plan.forEach((item,index)=>{if(dates[index]&&item.date)dates[index].value=item.date;if(amounts[index])amounts[index].value=item.amount||'';if(paid[index])paid[index].checked=!!item.paid;});const total=cashSourceForm.querySelector('[name="amount"]');if(total)total.value=formatTurkishMoney(plan.reduce((sum,item)=>sum+(parseTurkishMoney(item.amount)||0),0));};
  const restoreExtraTermSchedule=()=>{const second=(window.__savedCashRecords||[]).find(record=>record.payment_type==='term'&&record.term_schedule),extra=cashSourceForm?.querySelector('[data-extra-income]');if(!second||!extra)return;try{const plan=JSON.parse(second.term_schedule)||[];const dates=extra.querySelectorAll('[name="extra_term_date[]"]'),amounts=extra.querySelectorAll('[data-term-amount]'),paid=extra.querySelectorAll('[name="extra_term_paid[]"]');if(!amounts.length)return;plan.forEach((item,index)=>{if(dates[index]&&item.date)dates[index].value=item.date;if(amounts[index])amounts[index].value=(parseTurkishMoney(item.amount)||0)>0?formatTurkishMoney(parseTurkishMoney(item.amount)):'';if(paid[index])paid[index].checked=!!item.paid;});const total=extra.querySelector('[name="extra_amount"]');if(total)total.value=String(plan.reduce((sum,item)=>sum+(parseTurkishMoney(item.amount)||0),0));extra.dataset.termPlanRestored='1';setTimeout(()=>{showIncomeHeaderTotals();suppressTermBalanceFlicker();},0);}catch(_){}};
  cashSourceForm?.addEventListener('change',event=>{if(event.target.matches('[name="extra_payment_type"],[name="extra_installment_count"]'))setTimeout(restoreExtraTermSchedule,0);});
  document.addEventListener('click',event=>{const button=event.target.closest('[title="Gelir kaydını güncelle"]');if(!button||!cashSourceForm)return;event.preventDefault();event.stopImmediatePropagation();const dates=[...cashSourceForm.querySelectorAll('[name="term_date[]"]')],amounts=[...cashSourceForm.querySelectorAll('[data-primary-term-amount]')],paid=[...cashSourceForm.querySelectorAll('[name="term_paid[]"]')],plan=dates.map((date,index)=>({date:date.value,amount:amounts[index]?.value||'',paid:!!paid[index]?.checked})),extra=cashSourceForm.querySelector('[data-extra-income]'),extraRecord=(window.__savedCashRecords||[])[1]||{},extraPlan=[...extra?.querySelectorAll('[name="extra_term_date[]"]')||[]].map((date,index)=>({date:date.value,amount:extra?.querySelectorAll('[data-term-amount]')[index]?.value||'',paid:!!extra?.querySelectorAll('[name="extra_term_paid[]"]')[index]?.checked}));const form=document.createElement('form');form.method='post';form.action=location.href;const values={csrf:<?=json_encode(csrf())?>,action:'cash_update_only',edit_id:new URLSearchParams(location.search).get('edit')||'',cash_update_id:cashSourceForm.querySelector('[name="id"]')?.value||'',cash_update_date:cashSourceForm.querySelector('[name="transaction_date"]')?.value||'',cash_update_description:cashSourceForm.querySelector('[name="description"]')?.value||'',cash_update_amount:String(parseTurkishMoney(cashSourceForm.querySelector('[name="amount"]')?.value)||''),cash_update_payment_type:cashSourceForm.querySelector('[name="payment_type"]')?.value||'',cash_update_installment_count:cashSourceForm.querySelector('[name="installment_count"]')?.value||'1',cash_update_bank_name:cashSourceForm.querySelector('[name="bank_name"]')?.value||'',cash_update_commission_rate:cashSourceForm.querySelector('[name="commission_rate"]')?.value||'',cash_update_term_schedule:JSON.stringify(plan),cash_update_extra_id:extraRecord.id||'',cash_update_extra_description:extra?.querySelector('[name="extra_description"]')?.value||'',cash_update_extra_amount:String(parseTurkishMoney(extra?.querySelector('[name="extra_amount"]')?.value)||''),cash_update_extra_payment_type:extra?.querySelector('[name="extra_payment_type"]')?.value||'',cash_update_extra_installment_count:extra?.querySelector('[name="extra_installment_count"]')?.value||'1',cash_update_extra_bank_name:extra?.querySelector('[name="extra_bank_name"]')?.value||'',cash_update_extra_commission_rate:extra?.querySelector('[name="extra_commission_rate"]')?.value||'',cash_update_extra_current_account_id:extra?.querySelector('[name="extra_current_account_id"]')?.value||'',cash_update_extra_term_schedule:JSON.stringify(extraPlan)};Object.entries(values).forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.append(input);});document.body.append(form);form.submit();},true);
  const validateTermPayments=()=>{const saleTotal=parseTurkishMoney(detailsModal?.querySelector('[name="sales_payment_amount"]')?.value)||0,primarySchedule=[...cashSourceForm?.querySelectorAll('[data-primary-term-amount]')||[]].reduce((sum,input)=>sum+(parseTurkishMoney(input.value)||0),0),primaryAmount=primarySchedule||(parseTurkishMoney(cashSourceForm?.querySelector('[name="amount"]')?.value)||0),extra=cashSourceForm?.querySelector('[data-extra-income]'),extraSchedule=[...extra?.querySelectorAll('[data-term-amount]')||[]].reduce((sum,input)=>sum+(parseTurkishMoney(input.value)||0),0),extraAmount=extraSchedule||(parseTurkishMoney(extra?.querySelector('[name="extra_amount"]')?.value)||0);if(saleTotal>0&&Math.abs((primaryAmount+extraAmount)-saleTotal)>0.009){alert('Gelir kayıtları toplamı, Satış Bilgileri ekranındaki toplam tutara eşit olmalıdır.\nLütfen düzeltin ve yeniden kaydedin.');return false;}const primaryType=salesPaymentToCashType(paymentSelect?.value||'')||cashSourceForm?.querySelector('[name="payment_type"]')?.value;if(primaryType==='mail_order'&&!cashSourceForm?.querySelector('[name="current_account_id"]')?.value){alert('Mail Order için cari hesap seçmelisiniz.');cashSourceForm?.querySelector('[name="current_account_id"]')?.focus();return false;}if(extra?.querySelector('[name="extra_payment_type"]')?.value==='mail_order'&&!extra.querySelector('[name="extra_current_account_id"]')?.value){alert('Mail Order için cari hesap seçmelisiniz.');extra.querySelector('[name="extra_current_account_id"]')?.focus();return false;}if(primaryType!=='term')return true;const amounts=[...cashSourceForm.querySelectorAll('[data-primary-term-amount]')],hasPrimaryPayment=amounts.some(input=>(parseTurkishMoney(input.value)||0)>0);if(!hasPrimaryPayment)return true;const empty=amounts.find(input=>(parseTurkishMoney(input.value)||0)<=0);if(!empty)return true;alert('Vadeli ödeme için tüm aylık ödeme alanlarını doldurun.');empty.focus();return false;};window.addEventListener('click',event=>{const button=event.target.closest('form[action*="cash.php"] footer button');if(!button||button.matches('[data-cash-close]')||button.matches('[aria-label="Bir gelir kaydı daha ekle"]'))return;if(!validateTermPayments()){event.preventDefault();event.stopPropagation();}},true);cashSourceForm?.addEventListener('submit',event=>{if(!validateTermPayments()){event.preventDefault();event.stopImmediatePropagation();}},true);
  cashSourceForm?.addEventListener('submit',()=>{const old=cashSourceForm.querySelector('[name="term_schedule_json"]');old?.remove();const plan=[...cashSourceForm.querySelectorAll('[data-primary-term-amount]')].map((amount,index)=>({date:cashSourceForm.querySelectorAll('[name="term_date[]"]')[index]?.value||'',amount:amount.value||'',paid:!!cashSourceForm.querySelectorAll('[name="term_paid[]"]')[index]?.checked}));if(plan.length){const hidden=document.createElement('input');hidden.type='hidden';hidden.name='term_schedule_json';hidden.value=JSON.stringify(plan);cashSourceForm.append(hidden);}const extra=cashSourceForm.querySelector('[data-extra-income]');if(extra?.querySelector('[name="extra_payment_type"]')?.value==='term'){const amounts=[...extra.querySelectorAll('[data-term-amount]')];amounts.forEach(input=>input.value=String(parseTurkishMoney(input.value)||0));const total=amounts.reduce((sum,input)=>sum+(parseTurkishMoney(input.value)||0),0),totalField=extra.querySelector('[name="extra_amount"]');if(totalField)totalField.value=String(total);}});
  cashSourceForm?.addEventListener('blur',event=>{if(!event.target.matches('[data-primary-term-amount],[data-extra-income] [data-term-amount]'))return;const amount=parseTurkishMoney(event.target.value);if(amount!==null&&amount>0)event.target.value=formatTurkishMoney(amount);},true);
  // Vade planını her değişiklikte hazır tut: kaydet düğmesi formu erken okusa bile tüm taksitler gönderilir.
  const syncPrimaryTermPlan=()=>{if(!cashSourceForm)return;const rows=[...cashSourceForm.querySelectorAll('[data-primary-term-amount]')],old=cashSourceForm.querySelector('[name="term_schedule_json"]');if(!rows.length){old?.remove();return;}const dates=[...cashSourceForm.querySelectorAll('[name="term_date[]"]')],paid=[...cashSourceForm.querySelectorAll('[name="term_paid[]"]')],plan=rows.map((amount,index)=>({date:dates[index]?.value||'',amount:amount.value||'',paid:!!paid[index]?.checked}));const input=old||document.createElement('input');input.type='hidden';input.name='term_schedule_json';input.value=JSON.stringify(plan);if(!old)cashSourceForm.append(input);};
  cashSourceForm?.addEventListener('input',event=>{if(event.target.matches('[data-primary-term-amount],[name="term_date[]"]'))syncPrimaryTermPlan();},true);
  cashSourceForm?.addEventListener('change',event=>{if(event.target.matches('[data-primary-term-amount],[name="term_date[]"],[name="term_paid[]"]'))syncPrimaryTermPlan();},true);
  // Vade planı dinamik üretildiği için, form gönderilmeden önce tüm satırları tekil plan verisine zorla ekle.
  cashSourceForm?.addEventListener('submit',()=>{const old=cashSourceForm.querySelector('[name="term_schedule_json"]');old?.remove();const rows=[...cashSourceForm.querySelectorAll('[data-primary-term-amount]')];if(!rows.length)return;const dates=[...cashSourceForm.querySelectorAll('[name="term_date[]"]')],paid=[...cashSourceForm.querySelectorAll('[name="term_paid[]"]')],plan=rows.map((amount,index)=>({date:dates[index]?.value||'',amount:amount.value||'',paid:!!paid[index]?.checked}));const input=document.createElement('input');input.type='hidden';input.name='term_schedule_json';input.value=JSON.stringify(plan);cashSourceForm.append(input);},true);
  const formatIncomeMoneyWhileTyping=event=>{
    const field=event.target;
    if(!(field instanceof HTMLInputElement)||!field.matches('[name="amount"],[name="extra_amount"],[data-primary-term-amount],[data-term-amount]'))return;
    const raw=field.value,caret=field.selectionStart??raw.length;
    if(raw.trim()==='')return;
    const amount=parseTurkishMoney(raw);
    if(amount===null)return;
    const digitsBeforeCaret=(raw.slice(0,caret).match(/\d/g)||[]).length;
    const formatted=formatTurkishMoney(amount);
    field.value=formatted;
    let nextCaret=0,seenDigits=0;
    while(nextCaret<formatted.length&&seenDigits<digitsBeforeCaret){if(/\d/.test(formatted[nextCaret]))seenDigits++;nextCaret++;}
    field.setSelectionRange(nextCaret,nextCaret);
  };
  cashSourceForm?.addEventListener('input',formatIncomeMoneyWhileTyping,true);
  restoreDetails();if(!<?=json_encode($savedCashRecord !== [])?>&&paymentSelect)paymentSelect.value='';if(<?=json_encode($savedCashRecord !== [])?>&&paymentSelect){paymentSelect.disabled=true;paymentSelect.title='Gelir kaydı bulunduğu için ödeme şekli değiştirilemez.';}setTimeout(()=>{renderPrimaryTermSchedule();placePrimaryTermTotal();loadSavedCashCards();setTimeout(restoreSavedTermSchedule,60);[80,180,360].forEach(delay=>setTimeout(restoreExtraTermSchedule,delay));},0);syncCashIcon();syncDeviceModels();fillDeviceSerial();syncChargerModels();fillChargerSerial();if(['sales_charger_brand','sales_charger_model','sales_charger_serial'].some(name=>detailFields.find(field=>field.name===name)?.value.trim()))toggleChargerDetails(true);if(detailFields.find(field=>field.name==='sales_consumable_stock_id')?.value){toggleConsumableDetails(true);syncConsumablePrice();}
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
  let productWasRemoved=false;
  const clearFields=names=>names.forEach(name=>{const field=detailsModal?.querySelector(`[name="${name}"]`);if(field)field.value='';});
  const activeProductLineCount=()=>{const groups=[['sales_brand','sales_model','sales_device_serial'],...[2,3,4].map(number=>[`sales_device_${number}_brand`,`sales_device_${number}_model`,`sales_device_${number}_serial`]),['sales_charger_brand','sales_charger_model','sales_charger_serial'],['sales_consumable_stock_id']];return groups.filter(names=>names.some(name=>String(detailsModal?.querySelector(`[name="${name}"]`)?.value||'').trim()!=='' )).length;};
  const refreshProductDeleteButtons=()=>{const showButtons=!saleProductDeleteLocked||activeProductLineCount()>1;detailsModal?.querySelectorAll('.sales-product-cancel').forEach(button=>button.hidden=!showButtons);};
  const addProductCancel=(container,names,onClear)=>{const button=document.createElement('button');button.type='button';button.className='repair-cancel sales-product-cancel';button.textContent='×';button.title='Ürünü kaldır';button.setAttribute('aria-label','Ürünü kaldır');button.addEventListener('click',()=>{if(saleProductDeleteLocked&&activeProductLineCount()<=1){alert('Hasta ödeme yapmış. Son ürün kalemini silemezsiniz.');return;}productWasRemoved=true;clearFields(names);onClear();updateTotalAmount();refreshProductDeleteButtons();});container.append(button);refreshProductDeleteButtons();return button;};
  detailsModal?.addEventListener('change',event=>{if(event.target instanceof HTMLInputElement||event.target instanceof HTMLSelectElement)refreshProductDeleteButtons();});
  let consumableCancel=null;
  const showConsumableDetails=()=>{chargerDetails?.after(consumableDetails);toggleConsumableDetails(true);consumableCancel??=addProductCancel(consumableDetails,['sales_consumable_stock_id','sales_consumable_quantity','sales_consumable_price'],()=>{toggleConsumableDetails(false);if(detailsModal?.dataset.productType==='Sarf Malzeme')setProductType('');});};
  let firstDeviceCancel=null;
  const showFirstDevice=()=>{if(!deviceDetails)return;toggleDeviceDetails(true);firstDeviceCancel??=addProductCancel(deviceDetails,['sales_brand','sales_model','sales_device_serial','sales_device_sgk','sales_device_discount_rate','sales_device_net_price'],()=>{toggleDeviceDetails(false);if(detailsModal?.dataset.productType==='İşitme Cihazı')setProductType('');});};
  const updateDeviceAddButton=()=>{if(addDeviceButton)addDeviceButton.hidden=[2,3,4].every(number=>!!detailsModal?.querySelector(`#hearing-device-details-${number}`));};
  const addExtraDevice=number=>{
    if(number<2||number>4||detailsModal?.querySelector(`#hearing-device-details-${number}`))return;
    const previous=number===2?deviceDetails:detailsModal?.querySelector(`#hearing-device-details-${number-1}`);if(!previous)return;
    const device=document.createElement('div');device.id=`hearing-device-details-${number}`;device.className='sales-device-details';device.style.cssText='grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px';
    device.innerHTML=`<label>İşitme Cihazı Markası<select name="sales_device_${number}_brand"></select></label><label>Model<select name="sales_device_${number}_model"></select></label><label>Seri No<select name="sales_device_${number}_serial" disabled><option value="">Önce marka ve model seçiniz</option></select></label><label>SGK<input inputmode="decimal" name="sales_device_${number}_sgk" autocomplete="off"></label><label>İskonto % - TL<input inputmode="decimal" name="sales_device_${number}_discount_rate" autocomplete="off"></label><label>Net Fiyat<input inputmode="decimal" name="sales_device_${number}_net_price" autocomplete="off"></label>`;
    device.querySelectorAll('label').forEach(label=>label.style.cssText='display:flex;flex-direction:column;gap:7px');previous.after(device);
    const brand=device.querySelector(`[name="sales_device_${number}_brand"]`),model=device.querySelector(`[name="sales_device_${number}_model"]`),serial=device.querySelector(`[name="sales_device_${number}_serial"]`),sgk=device.querySelector(`[name="sales_device_${number}_sgk"]`),discount=device.querySelector(`[name="sales_device_${number}_discount_rate"]`),netPrice=device.querySelector(`[name="sales_device_${number}_net_price"]`);
    const sync=()=>{if(!brand.value){model.replaceChildren(new Option('Önce marka seçiniz',''));model.disabled=true;return;}const invoice=(salesInvoiceInput?.value||'').trim(),historicalModels=invoice?salesExitSerials.filter(item=>item.brand===brand.value&&String(item.invoice_no||'').trim()===invoice).map(item=>item.model):[];fillSelect(model,[...hearingDeviceStocks.filter(stock=>stock.brand===brand.value).map(stock=>stock.model),...historicalModels],'Model seçiniz');model.disabled=false;};
    fillSelect(brand,hearingDeviceStocks.map(stock=>stock.brand),'Marka seçiniz');sync();
    if(number===2){brand.value=brandSelect?.value||'';brand.dispatchEvent(new Event('change'));model.value=modelSelect?.value||'';model.dispatchEvent(new Event('change'));if(sgk)sgk.value=detailsModal?.querySelector('[name="sales_device_sgk"]')?.value||'';}
    brand.addEventListener('change',()=>{fillSerialOptions(serial,[]);netPrice.value='';delete netPrice.dataset.listPrice;setListPriceHint([brand,model,serial],null);sync();});model.addEventListener('change',()=>{const stocks=hearingDeviceStocks.filter(item=>item.brand===brand.value&&item.model===model.value),stock=stocks[0],historical=invoiceMatchedSerials(brand.value,model.value),listPrice=listPriceForStock(stock);fillSerialOptions(serial,[...stocks,...historical]);netPrice.dataset.listPrice=listPrice;netPrice.value=listPrice;applyDiscount(netPrice,discount,netPrice);setListPriceHint([brand,model,serial],stock);});discount.addEventListener('input',()=>applyDiscount(netPrice,discount,netPrice));
    addProductCancel(device,[`sales_device_${number}_brand`,`sales_device_${number}_model`,`sales_device_${number}_serial`,`sales_device_${number}_sgk`,`sales_device_${number}_discount_rate`,`sales_device_${number}_net_price`],()=>{device.remove();updateDeviceAddButton();});updateDeviceAddButton();
  };
  const addNextDevice=()=>{setProductType('İşitme Cihazı');if(deviceDetails?.hidden){showFirstDevice();}else{for(let number=2;number<=4;number++){if(!detailsModal?.querySelector(`#hearing-device-details-${number}`)){addExtraDevice(number);break;}}}arrangeProductSections();};
  detailsModal?.addEventListener('click',event=>{if(!event.target.closest('#add-hearing-device'))return;event.preventDefault();event.stopImmediatePropagation();addNextDevice();},true);
  salesInvoiceInput?.addEventListener('input',()=>{for(let number=2;number<=4;number++){const brand=detailsModal?.querySelector(`[name="sales_device_${number}_brand"]`),model=detailsModal?.querySelector(`[name="sales_device_${number}_model"]`),serial=detailsModal?.querySelector(`[name="sales_device_${number}_serial"]`);if(!brand||!model||!serial||!brand.value||!model.value)continue;const stocks=hearingDeviceStocks.filter(item=>item.brand===brand.value&&item.model===model.value);fillSerialOptions(serial,[...stocks,...invoiceMatchedSerials(brand.value,model.value)]);}});
  salesDateInput?.addEventListener('change',()=>{fillDeviceSerial();fillChargerSerial();syncConsumablePrice();[2,3,4].forEach(number=>{const brand=detailsModal?.querySelector(`[name="sales_device_${number}_brand"]`),model=detailsModal?.querySelector(`[name="sales_device_${number}_model"]`),netPrice=detailsModal?.querySelector(`[name="sales_device_${number}_net_price"]`),discount=detailsModal?.querySelector(`[name="sales_device_${number}_discount_rate"]`);if(!brand||!model||!netPrice)return;const stock=hearingDeviceStocks.find(item=>item.brand===brand.value&&item.model===model.value),listPrice=listPriceForStock(stock);netPrice.dataset.listPrice=listPrice;applyDiscount(netPrice,discount,netPrice);});});
  try{const saved=JSON.parse(details?.value||'{}');for(let number=2;number<=4;number++){if(!(saved[`sales_device_${number}_brand`]||saved[`sales_device_${number}_model`]||saved[`sales_device_${number}_serial`]))continue;addExtraDevice(number);const device=detailsModal?.querySelector(`#hearing-device-details-${number}`),brand=device?.querySelector(`[name="sales_device_${number}_brand"]`),model=device?.querySelector(`[name="sales_device_${number}_model"]`),serial=device?.querySelector(`[name="sales_device_${number}_serial"]`);if(brand){brand.value=saved[`sales_device_${number}_brand`]||'';brand.dispatchEvent(new Event('change'));}if(model){model.value=saved[`sales_device_${number}_model`]||'';model.dispatchEvent(new Event('change'));}if(serial)serial.value=saved[`sales_device_${number}_serial`]||'';}}catch(_){}
  if(!deviceDetails?.hidden)showFirstDevice();
  addDeviceButton?.addEventListener('click',()=>{setProductType('İşitme Cihazı');if(deviceDetails?.hidden)showFirstDevice();else for(let number=2;number<=4;number++){if(!detailsModal?.querySelector(`#hearing-device-details-${number}`)){addExtraDevice(number);break;}}arrangeProductSections();});
  if(!consumableDetails.hidden)showConsumableDetails();
  addConsumableButton.addEventListener('click',()=>{setProductType('Sarf Malzeme');showConsumableDetails();arrangeProductSections();});
  let chargerCancel=null;
  if(!chargerDetails.hidden)chargerCancel=addProductCancel(chargerDetails,['sales_charger_brand','sales_charger_model','sales_charger_price','sales_charger_serial','sales_charger_sgk','sales_charger_discount_rate','sales_charger_net_price'],()=>{toggleChargerDetails(false);detailsModal.dataset.chargerAdded='';if(detailsModal?.dataset.productType==='Şarj Cihazı')setProductType('');});
  addChargerButton.addEventListener('click',()=>{setProductType('Şarj Cihazı');toggleChargerDetails(true);detailsModal.dataset.chargerAdded='1';chargerCancel??=addProductCancel(chargerDetails,['sales_charger_brand','sales_charger_model','sales_charger_price','sales_charger_serial','sales_charger_sgk','sales_charger_discount_rate','sales_charger_net_price'],()=>{toggleChargerDetails(false);detailsModal.dataset.chargerAdded='';if(detailsModal?.dataset.productType==='Şarj Cihazı')setProductType('');});arrangeProductSections();});
  arrangeProductSections();
  refreshProductDeleteButtons();
  updateTotalAmount();
  service.addEventListener('change',()=>{if(isSales()){close();openDetails();}else{value.value='';close();closeDetails()}});
  modal.querySelectorAll('[data-sales-close]').forEach(x=>x.addEventListener('click',close));items.forEach(item=>item.addEventListener('click',()=>{value.value=item.dataset.id||'';close();openDetails()}));search?.addEventListener('input',()=>{const q=search.value.trim().toLocaleLowerCase('tr-TR');items.forEach(item=>item.hidden=!!q&&!(item.dataset.search||'').includes(q))});detailsModal?.querySelectorAll('[data-sales-details-close]').forEach(x=>x.addEventListener('click',()=>{persistDetails();closeDetails()}));detailsModal?.querySelector('#sales-details-save')?.addEventListener('click',async event=>{event.preventDefault();if(service.value.trim()!=='Satış'){service.value='Satış';service.dispatchEvent(new Event('change',{bubbles:true}));}syncInvoice();let returnToSales=form.querySelector('[name="return_to_sales_details"]');if(!productWasRemoved){if(!returnToSales){returnToSales=document.createElement('input');returnToSales.type='hidden';returnToSales.name='return_to_sales_details';form.append(returnToSales);}returnToSales.value='1';}else{returnToSales?.remove();}const button=event.currentTarget;button.disabled=true;try{const response=await fetch(form.action||location.href,{method:'POST',body:new FormData(form),credentials:'same-origin'});if(!response.ok)throw new Error('Kayıt işlemi tamamlanamadı.');const responseUrl=new URL(response.url);const savedEditId=responseUrl.searchParams.get('edit');if(savedEditId){const editInput=form.querySelector('[name="edit_id"]');if(editInput)editInput.value=savedEditId;history.replaceState(null,'',responseUrl.pathname+'?'+responseUrl.searchParams.toString());}returnToSales?.remove();updateTotalAmount();if(productWasRemoved){closeDetails();const cleanUrl=new URL(location.href);cleanUrl.searchParams.delete('open_sales_details');history.replaceState(null,'',cleanUrl.pathname+'?'+cleanUrl.searchParams.toString());productWasRemoved=false;}alert('Kaydedildi');}catch(error){alert(error.message||'Kayıt işlemi tamamlanamadı.');}finally{button.disabled=false;}});form.addEventListener('submit',persistDetails);if(<?=json_encode($openSalesDetails)?>)setTimeout(openDetails,0);
};
if('requestIdleCallback' in window)window.requestIdleCallback(initializeSalesScreen,{timeout:300});else setTimeout(initializeSalesScreen,0);
</script>
<script>document.addEventListener('DOMContentLoaded',()=>{const serviceName=document.querySelector('#service-card-form [name="service_name"]');if(serviceName?.value.trim().toLocaleLowerCase('tr-TR')==='tamir')serviceName.dispatchEvent(new Event('change'));});</script>
<script>
document.addEventListener('DOMContentLoaded',()=>{const salesSave=document.getElementById('sales-details-save');if(!salesSave)return;salesSave.addEventListener('click',()=>{const nativeAlert=window.alert;let restored=false;window.alert=message=>{if(message==='Kaydedildi'){if(!restored){window.alert=nativeAlert;restored=true;}return;}return nativeAlert(message);};setTimeout(()=>{if(!restored){window.alert=nativeAlert;restored=true;}},15000);},true);});
</script>
<style>#sales-details-link[hidden]{display:none!important}</style>
<style>#sales-details-modal #sales_total_sgk,#sales-details-modal [name="sales_payment_amount"]{color:#e0444c!important;font-weight:700!important}</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{const service=document.querySelector('#service-card-form [name="service_name"]'),removeSaleActions=()=>{if(service?.value.trim()==='Satış')return;document.getElementById('sales-details-link')?.remove();document.querySelector('.sales-income-link')?.remove();};removeSaleActions();service?.addEventListener('change',removeSaleActions);const pageUrl=new URL(location.href);if(pageUrl.searchParams.has('open_sales_details')){setTimeout(()=>{removeSaleActions();document.getElementById('sales-details-modal')?.setAttribute('hidden','');pageUrl.searchParams.delete('open_sales_details');history.replaceState(null,'',pageUrl.pathname+(pageUrl.search||''));},0);}});
</script>
<script>
document.addEventListener('DOMContentLoaded',()=>{const renderIncomeSummary=()=>{const form=document.querySelector('form[action*="cash.php"]'),header=form?.querySelector('header');if(!form||!header)return;const amounts=[...form.querySelectorAll('[data-primary-term-amount]')],paid=[...form.querySelectorAll('[name="term_paid[]"]')],scheduled=amounts.reduce((sum,input)=>sum+(Number(String(input.value||'').replace(/[^0-9,.-]/g,'').replaceAll('.','').replace(',','.'))||0),0),total=scheduled||(Number(String(form.querySelector('[name="amount"]')?.value||'').replace(/[^0-9,.-]/g,'').replaceAll('.','').replace(',','.'))||0),paidTotal=amounts.reduce((sum,input,index)=>sum+(paid[index]?.checked?(Number(String(input.value||'').replace(/[^0-9,.-]/g,'').replaceAll('.','').replace(',','.'))||0):0),0),balance=Math.max(0,total-paidTotal),money=value=>value.toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' ₺';let summary=header.querySelector('[data-income-header-total]');if(!summary){summary=document.createElement('span');summary.dataset.incomeHeaderTotal='1';summary.style.cssText='margin-left:auto;font-size:13px;font-weight:700;white-space:normal;line-height:1.4;text-align:right';header.append(summary);}summary.innerHTML='<span style="display:block;color:#19a94b">Ödenen: '+money(paidTotal)+'</span>'+(balance>0?'<span style="display:block;color:#e6525d">Bakiye: '+money(balance)+'</span>':'');};[100,350,800].forEach(delay=>setTimeout(renderIncomeSummary,delay));});
document.addEventListener('DOMContentLoaded',()=>{const markOverdue=()=>{const form=document.querySelector('form[action*="cash.php"]');if(!form)return;const today=new Date();today.setHours(0,0,0,0);[...form.querySelectorAll('[name="term_date[]"]')].forEach((date,index)=>{const payment=date.closest('label')?.nextElementSibling,amount=payment?.querySelector('[data-primary-term-amount]'),paid=form.querySelectorAll('[name="term_paid[]"]')[index],due=date.value?new Date(date.value+'T00:00:00'):null,overdue=!!due&&due<today&&!paid?.checked;[date,amount].forEach(field=>{if(!field)return;field.style.borderColor=overdue?'#e0444c':'';field.style.color=overdue?'#e0444c':'';});});};document.addEventListener('input',event=>{if(event.target.matches('[name="term_date[]"],[name="term_paid[]"]'))markOverdue();});document.addEventListener('change',event=>{if(event.target.matches('[name="term_date[]"],[name="term_paid[]"]'))markOverdue();});[100,350,800].forEach(delay=>setTimeout(markOverdue,delay));});
document.addEventListener('DOMContentLoaded',()=>{const cashId=<?=json_encode((int)($savedCashRecord['id'] ?? 0))?>;if(!cashId)return;document.addEventListener('change',event=>{if(!event.target.matches('[name="term_paid[]"]'))return;const form=event.target.closest('form[action*="cash.php"]');if(!form)return;const dates=[...form.querySelectorAll('[name="term_date[]"]')],amounts=[...form.querySelectorAll('[data-primary-term-amount]')],paid=[...form.querySelectorAll('[name="term_paid[]"]')],plan=dates.map((date,index)=>({date:date.value,amount:amounts[index]?.value||'',paid:!!paid[index]?.checked}));const data=new FormData();data.set('csrf',<?=json_encode(csrf())?>);data.set('action','cash_term_schedule_only');data.set('cash_id',String(cashId));data.set('term_schedule',JSON.stringify(plan));fetch(location.href,{method:'POST',body:data,credentials:'same-origin'});});});
document.addEventListener('DOMContentLoaded',()=>{const formatTotal=()=>{const field=document.querySelector('form[action*="cash.php"] [name="amount"]');if(!field||!field.value.trim())return;const raw=field.value.replace(/[^0-9,.-]/g,''),amount=Number(raw.includes(',')?raw.replaceAll('.','').replace(',','.'):raw);if(Number.isFinite(amount))field.value=amount.toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' ₺';};document.addEventListener('change',event=>{if(event.target.matches('[name="installment_count"]'))setTimeout(formatTotal,50);});[100,350,800].forEach(delay=>setTimeout(formatTotal,delay));});
document.addEventListener('DOMContentLoaded',()=>{const form=document.querySelector('form[action*="cash.php"]');if(!form)return;form.addEventListener('submit',async event=>{if(event.defaultPrevented)return;event.preventDefault();const data=new FormData(form),amount=data.get('amount');if(typeof amount==='string'){const raw=amount.replace(/[^0-9,.-]/g,''),parsed=Number(raw.includes(',')?raw.replaceAll('.','').replace(',','.'):raw);if(Number.isFinite(parsed))data.set('amount',String(parsed));}data.set('ajax','1');const button=form.querySelector('footer button:not([data-cash-close])');if(button)button.disabled=true;try{const response=await fetch(new URL(form.getAttribute('action')||'cash.php',location.href),{method:'POST',body:data,credentials:'same-origin'}),result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Kayıt işlemi tamamlanamadı.');}catch(error){alert(error.message||'Kayıt işlemi tamamlanamadı.');}finally{if(button)button.disabled=false;}},false);});
window.addEventListener('click',event=>{const button=event.target.closest('form[action*="cash.php"] footer button');if(!button||button.dataset.incomeUpdate==='1'||button.matches('[data-cash-close]')||button.matches('[aria-label="Bir gelir kaydı daha ekle"]'))return;const form=button.closest('form');if(!form)return;event.preventDefault();event.stopImmediatePropagation();form.requestSubmit();},true);
</script>
<style>
form[action*="cash.php"]>footer{display:flex!important;align-items:center!important;justify-content:flex-end!important;gap:10px!important}
form[action*="cash.php"].repair-dialog>footer{padding:16px 24px 20px!important;min-height:0!important}
form[action*="cash.php"].repair-dialog>footer button{display:inline-grid!important;place-items:center!important;box-sizing:border-box!important;flex:0 0 44px!important;width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important}
form[action*="cash.php"].repair-dialog>footer button .ti{font-size:21px!important;line-height:1!important;font-weight:700!important}
form[action*="cash.php"].repair-dialog>footer [aria-label="Bir gelir kaydı daha ekle"]{font-size:26px!important;font-weight:600!important;line-height:1!important}
form[action*="cash.php"]>footer [data-cash-close]{display:inline-grid!important;place-items:center!important;box-sizing:border-box!important;width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important;border:0!important;border-radius:6px!important;background:#e6525d!important;color:#fff!important}
</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const normalizeIncomeFooter=()=>{
    const form=document.querySelector('form[action*="cash.php"]'),footer=form?.querySelector(':scope > footer');
    if(!footer)return;
    const footerButtons=[...footer.querySelectorAll('button')],addButton=footer.querySelector('[aria-label="Bir gelir kaydı daha ekle"]'),actionButtons=footerButtons.filter(button=>button!==addButton);
    const cancel=footer.querySelector('[data-cash-close]')||actionButtons.find(button=>button!==actionButtons.at(-1));
    if(cancel){
      cancel.title='İptal';
      cancel.setAttribute('aria-label','İptal');
      Object.entries({width:'44px',minWidth:'44px',maxWidth:'44px',height:'44px',minHeight:'44px',maxHeight:'44px',padding:'0',boxSizing:'border-box'}).forEach(([property,value])=>cancel.style.setProperty(property.replace(/[A-Z]/g,letter=>'-'+letter.toLowerCase()),value,'important'));
      cancel.style.setProperty('background','#e6525d','important');
      cancel.style.setProperty('color','#fff','important');
      cancel.style.setProperty('border','0','important');
      if(!cancel.dataset.iconReady){cancel.innerHTML='<i class="ti tabler-arrow-back-up" aria-hidden="true"></i>';cancel.dataset.iconReady='1';}
    }
    const addIncome=footer.querySelector('[aria-label="Bir gelir kaydı daha ekle"]');
    if(addIncome)Object.entries({width:'44px',minWidth:'44px',maxWidth:'44px',height:'44px',minHeight:'44px',maxHeight:'44px',padding:'0',boxSizing:'border-box'}).forEach(([property,value])=>addIncome.style.setProperty(property.replace(/[A-Z]/g,letter=>'-'+letter.toLowerCase()),value,'important'));
    [...footer.querySelectorAll('button')].forEach(button=>{
      if(button.matches('[data-cash-close],[aria-label="Bir gelir kaydı daha ekle"]'))return;
      if(form.dataset.saved==='1'&&!button.dataset.updateGuard){
        button.dataset.updateGuard='1';
        const bypassCashSubmit=()=>{button.dataset.incomeUpdate='1';};
        button.addEventListener('pointerdown',bypassCashSubmit);
        button.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' ')bypassCashSubmit();});
      }
      button.title='Kaydet';
      button.setAttribute('aria-label','Kaydet');
      button.style.cssText='display:inline-grid!important;place-items:center!important;box-sizing:border-box!important;width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important;border:0!important;border-radius:6px!important;background:#19a94b!important;color:#fff!important';
      if(!button.dataset.iconReady){button.innerHTML='<i class="ti tabler-device-floppy" aria-hidden="true"></i>';button.dataset.iconReady='1';}
    });
  };
  normalizeIncomeFooter();
  new MutationObserver(normalizeIncomeFooter).observe(document.body,{childList:true,subtree:true});
  const lockIncomeFooterSize=()=>document.querySelectorAll('form[action*="cash.php"].repair-dialog>footer button').forEach(button=>{
    if(button.style.getPropertyValue('width')==='44px'&&button.style.getPropertyValue('height')==='44px')return;
    ['width','min-width','max-width','height','min-height','max-height'].forEach(property=>button.style.setProperty(property,'44px','important'));
    button.style.setProperty('padding','0','important');
    button.style.setProperty('box-sizing','border-box','important');
  });
  new MutationObserver(lockIncomeFooterSize).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['style']});
  lockIncomeFooterSize();
});
</script>
<script>
window.addEventListener('click',event=>{
  // Güncelle düğmesi formun action değerini değiştirse bile doğrulamayı modal üzerinde yap.
  const button=event.target.closest('form.repair-dialog footer button');
  if(!button||button.matches('[data-cash-close],[aria-label="Bir gelir kaydı daha ekle"]'))return;
  const form=button.closest('form'),toNumber=value=>{
    const source=String(value||'').replace(/[^0-9,.-]/g,'');
    const normalized=source.includes(',')?source.replaceAll('.','').replace(',','.'):source;
    return Number(normalized)||0;
  };
  if(!form)return;
  const saleTotal=toNumber(document.querySelector('#sales-details-modal [name="sales_payment_amount"]')?.value);
  if(saleTotal<=0)return;
  const totalSchedule=[...form.querySelectorAll('[data-primary-term-amount]')].reduce((sum,input)=>sum+toNumber(input.value),0);
  const primary=totalSchedule||toNumber(form.querySelector('[name="amount"]')?.value);
  const extra=form.querySelector('[data-extra-income]');
  const extraSchedule=[...extra?.querySelectorAll('[data-term-amount]')||[]].reduce((sum,input)=>sum+toNumber(input.value),0);
  const extraAmount=extraSchedule||toNumber(extra?.querySelector('[name="extra_amount"]')?.value);
  if(Math.abs((primary+extraAmount)-saleTotal)<=0.009)return;
  event.preventDefault();
  event.stopImmediatePropagation();
  form.dataset.incomeValidationFailed='1';
  const totalText=saleTotal.toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' ₺';
  alert('Gelir kayıtları toplamı, Satış Bilgileri ekranındaki '+totalText+' toplam tutara eşit olmalıdır.\nLütfen düzeltin ve yeniden kaydedin.');
},true);
</script>
<script>
document.addEventListener('click',event=>{
  const cancel=event.target.closest('form.repair-dialog [data-cash-close]');
  const form=cancel?.closest('form.repair-dialog');
  if(!form||form.dataset.incomeValidationFailed!=='1')return;
  event.preventDefault();
  event.stopImmediatePropagation();
  const request=document.createElement('form');
  request.method='post';
  request.action=<?=json_encode(url('patient-followup.php?id='.$id))?>;
  const values={csrf:<?=json_encode(csrf())?>,action:'cash_cancel_income',edit_id:<?=json_encode((string)$editId)?>};
  Object.entries(values).forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;request.append(input);});
  document.body.append(request);
  request.submit();
},true);
</script>
<script>
(()=>{
  const originalAlert=window.alert.bind(window);
  window.alert=message=>{
    if(message!=='Gelir kayıtları toplamı, Satış Bilgileri ekranındaki toplam tutara eşit olmalıdır.\nLütfen düzeltin ve yeniden kaydedin.')return originalAlert(message);
    const value=String(document.querySelector('#sales-details-modal [name="sales_payment_amount"]')?.value||'');
    const raw=value.replace(/[^0-9,.-]/g,''),amount=Number(raw.includes(',')?raw.replaceAll('.','').replace(',','.'):raw);
    const total=Number.isFinite(amount)?amount.toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' ₺':value;
    originalAlert('Gelir kayıtları toplamı, Satış Bilgileri ekranındaki '+total+' toplam tutara eşit olmalıdır.\nLütfen düzeltin ve yeniden kaydedin.');
  };
})();
</script>
<?php if ($incomeValidationDraft): ?><script>
window.addEventListener('DOMContentLoaded',()=>{
  const draft=<?=json_encode($incomeValidationDraft, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const restoreDraft=()=>{
    const form=document.querySelector('form[action*="cash.php"]'),add=form?.querySelector('[aria-label="Bir gelir kaydı daha ekle"]');
    if(!form||!add){setTimeout(restoreDraft,60);return;}
    if(!form.querySelector('[data-extra-income]'))add.click();
    setTimeout(()=>{
      const extra=form.querySelector('[data-extra-income]');
      if(!extra)return;
      const set=(name,value)=>{const field=extra.querySelector(`[name="${name}"]`);if(field)field.value=value??'';};
      set('extra_payment_type',draft.payment_type);set('extra_bank_name',draft.bank_name);set('extra_current_account_id',draft.current_account_id);set('extra_installment_count',draft.installment_count||'1');set('extra_commission_rate',draft.commission_rate);set('extra_amount',draft.amount);set('extra_description',draft.description);
      extra.querySelector('[name="extra_payment_type"]')?.dispatchEvent(new Event('change',{bubbles:true}));
      if(draft.payment_type==='term'&&draft.term_schedule){
        let plan=[];try{plan=JSON.parse(draft.term_schedule)||[]}catch(_){}
        setTimeout(()=>plan.forEach((item,index)=>{const date=extra.querySelectorAll('[name="extra_term_date[]"]')[index],amount=extra.querySelectorAll('[data-term-amount]')[index];if(date)date.value=item.date||'';if(amount)amount.value=item.amount||'';}),120);
      }
    },100);
  };
  setTimeout(restoreDraft,420);
});
</script><?php endif; ?>
<script>
window.addEventListener('click',async event=>{
  const button=event.target.closest('form.repair-dialog footer button[data-income-update="1"]');
  if(!button)return;
  const form=button.closest('form');
  if(!form)return;
  event.preventDefault();
  event.stopImmediatePropagation();
  const money=value=>{const text=String(value||'').replace(/[^0-9,.-]/g,'');return text.includes(',')?text.replaceAll('.','').replace(',','.'):text;};
  const schedule=(scope,dateName,amountSelector,paidName)=>[...scope.querySelectorAll(`[name="${dateName}"]`)].map((date,index)=>({date:date.value,amount:scope.querySelectorAll(amountSelector)[index]?.value||'',paid:!!scope.querySelectorAll(`[name="${paidName}"]`)[index]?.checked}));
  const extra=form.querySelector('[data-extra-income]');
  const records=window.__savedCashRecords||[];
  const data=new FormData();
  const set=(name,value)=>data.set(name,value??'');
  set('csrf',<?=json_encode(csrf())?>);
  set('action','cash_update_only');
  set('ajax','1');
  set('edit_id',new URLSearchParams(location.search).get('edit')||'');
  set('cash_update_id',records[0]?.id||form.querySelector('[name="id"]')?.value||'');
  set('cash_update_date',form.querySelector('[name="transaction_date"]')?.value||'');
  set('cash_update_description',form.querySelector('[name="description"]')?.value||'');
  set('cash_update_amount',money(form.querySelector('[name="amount"]')?.value));
  set('cash_update_payment_type',form.querySelector('[name="payment_type"]')?.value||'');
  set('cash_update_installment_count',form.querySelector('[name="installment_count"]')?.value||'1');
  set('cash_update_bank_name',form.querySelector('[name="bank_name"]')?.value||'');
  set('cash_update_commission_rate',form.querySelector('[name="commission_rate"]')?.value||'');
  set('cash_update_term_schedule',JSON.stringify(schedule(form,'term_date[]','[data-primary-term-amount]','term_paid[]')));
  set('cash_update_extra_id',records[1]?.id||'');
  set('cash_update_extra_description',extra?.querySelector('[name="extra_description"]')?.value||'');
  set('cash_update_extra_amount',money(extra?.querySelector('[name="extra_amount"]')?.value));
  set('cash_update_extra_payment_type',extra?.querySelector('[name="extra_payment_type"]')?.value||'');
  set('cash_update_extra_installment_count',extra?.querySelector('[name="extra_installment_count"]')?.value||'1');
  set('cash_update_extra_bank_name',extra?.querySelector('[name="extra_bank_name"]')?.value||'');
  set('cash_update_extra_commission_rate',extra?.querySelector('[name="extra_commission_rate"]')?.value||'');
  set('cash_update_extra_current_account_id',extra?.querySelector('[name="extra_current_account_id"]')?.value||'');
  set('cash_update_extra_term_schedule',JSON.stringify(extra?schedule(extra,'extra_term_date[]','[data-term-amount]','extra_term_paid[]'):[]));
  button.disabled=true;
  try{
    const response=await fetch(location.href,{method:'POST',body:data,credentials:'same-origin'});
    const result=await response.json();
    if(!response.ok||!result.success)throw new Error(result.message||'Kayıt işlemi tamamlanamadı.');
    if(Array.isArray(result.records))window.__savedCashRecords=result.records;
    form.dataset.incomeValidationFailed='';
    let notice=document.getElementById('income-save-notice');
    if(!notice){notice=document.createElement('div');notice.id='income-save-notice';notice.style.cssText='position:fixed;right:24px;bottom:24px;z-index:2000;padding:11px 16px;border-radius:6px;background:#19a94b;color:#fff;font-size:14px;font-weight:700;box-shadow:0 8px 22px rgba(25,169,75,.28)';document.body.append(notice);}
    notice.textContent='Kaydedildi';
    notice.hidden=false;
    clearTimeout(window.__incomeSaveNoticeTimer);
    window.__incomeSaveNoticeTimer=setTimeout(()=>{notice.hidden=true;},2200);
  }catch(error){alert(error.message||'Kayıt işlemi tamamlanamadı.');}
  finally{button.disabled=false;}
},true);
</script>
<?php if ($fromSgkList): ?>
<style>#sales-details-modal input[name$="_sgk"]{border:2px solid #e04f55!important;box-shadow:0 0 0 2px rgba(224,79,85,.12)}</style>
<?php endif; ?>
<style>#vox-alert{position:fixed;inset:0;z-index:5000;display:grid;place-items:center;padding:24px;background:rgba(26,28,36,.62)}#vox-alert[hidden]{display:none}#vox-alert-panel{width:min(440px,calc(100vw - 48px));padding:28px;border-radius:12px;background:#fff;box-shadow:0 20px 50px rgba(0,0,0,.28);color:#2d2f43}#vox-alert-message{margin:0;white-space:pre-line;line-height:1.55}#vox-alert-actions{display:flex;justify-content:center;margin-top:24px}#vox-alert-actions button{min-width:96px;height:42px;border:0;border-radius:7px;background:#19a94b;color:#fff;font:inherit;font-weight:700;cursor:pointer}</style>
<script>(()=>{const nativeAlert=window.alert.bind(window);window.alert=message=>{try{let dialog=document.getElementById('vox-alert');if(!dialog){dialog=document.createElement('div');dialog.id='vox-alert';dialog.hidden=true;dialog.innerHTML='<section id="vox-alert-panel" role="alertdialog" aria-modal="true"><p id="vox-alert-message"></p><div id="vox-alert-actions"><button type="button">Tamam</button></div></section>';document.body.append(dialog);dialog.querySelector('button').addEventListener('click',()=>{dialog.hidden=true;});}dialog.querySelector('#vox-alert-message').textContent=String(message||'');dialog.hidden=false;dialog.querySelector('button').focus();}catch(_){nativeAlert(message);}};})();</script>
<style>#vox-confirm{position:fixed;inset:0;z-index:5001;display:grid;place-items:center;padding:24px;background:rgba(26,28,36,.62)}#vox-confirm[hidden]{display:none}#vox-confirm-panel{width:min(440px,calc(100vw - 48px));padding:28px;border-radius:12px;background:#fff;box-shadow:0 20px 50px rgba(0,0,0,.28);color:#2d2f43}#vox-confirm-message{margin:0;line-height:1.55}#vox-confirm-actions{display:flex;justify-content:center;gap:10px;margin-top:24px}#vox-confirm-actions button{min-width:96px;height:42px;border:0;border-radius:7px;font:inherit;font-weight:700;cursor:pointer}#vox-confirm-ok{background:#e04f55;color:#fff}#vox-confirm-cancel{background:#eef0f5;color:#2d2f43}</style>
<script>(()=>{window.voxConfirm=message=>new Promise(resolve=>{let dialog=document.getElementById('vox-confirm');if(!dialog){dialog=document.createElement('div');dialog.id='vox-confirm';dialog.hidden=true;dialog.innerHTML='<section id="vox-confirm-panel" role="dialog" aria-modal="true"><p id="vox-confirm-message"></p><div id="vox-confirm-actions"><button type="button" id="vox-confirm-cancel">İptal</button><button type="button" id="vox-confirm-ok">Sil</button></div></section>';document.body.append(dialog);dialog.querySelector('#vox-confirm-cancel').addEventListener('click',()=>{dialog.hidden=true;dialog._resolve?.(false);});dialog.querySelector('#vox-confirm-ok').addEventListener('click',()=>{dialog.hidden=true;dialog._resolve?.(true);});}dialog.querySelector('#vox-confirm-message').textContent=String(message||'');dialog._resolve=resolve;dialog.hidden=false;dialog.querySelector('#vox-confirm-cancel').focus();});document.addEventListener('click',event=>{const button=event.target.closest('[aria-label="İkinci gelir kaydını sil"]');if(!button)return;event.preventDefault();event.stopImmediatePropagation();window.voxConfirm('Bu ikinci gelir kaydını silmek istiyor musunuz?').then(approved=>{const record=(window.__savedCashRecords||[])[1];if(!approved||!record?.id)return;const form=document.createElement('form');form.method='post';form.action=<?=json_encode(url('patient-followup.php?id='.$id))?>;for(const [name,value] of Object.entries({csrf:<?=json_encode(csrf())?>,action:'cash_delete_only',edit_id:<?=json_encode((string)$editId)?>,cash_delete_id:record.id})){const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.append(input);}document.body.append(form);form.submit();});},true);})();</script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const form=document.getElementById('service-card-form'),service=form?.querySelector('[name="service_name"]');
  if(!service)return;
  const button=()=>{
    let value=document.getElementById('sales-details-link');
    if(value)return value;
    const slot=service.closest('.service-name-income-slot');
    if(!slot)return null;
    value=document.createElement('button');value.type='button';value.id='sales-details-link';value.className='sales-details-link';value.title='Satış Kartını Aç';value.setAttribute('aria-label','Satış Kartını Aç');value.innerHTML='<i class="ti tabler-file-search"></i>';slot.append(value);return value;
  };
  const sync=()=>{const value=button();if(value)value.hidden=service.value.trim()!=='Satış';};
  document.addEventListener('click',event=>{const value=event.target.closest('#sales-details-link');if(!value||service.value.trim()!=='Satış')return;event.preventDefault();const modal=document.getElementById('sales-details-modal');if(modal){modal.hidden=false;modal.setAttribute('aria-hidden','false');}},true);
  document.addEventListener('click',event=>{if(!event.target.closest('[data-sales-details-close]'))return;event.preventDefault();const modal=document.getElementById('sales-details-modal');if(modal){modal.hidden=true;modal.setAttribute('aria-hidden','true');}},true);
  service.addEventListener('change',sync,true);
  sync();
});
</script>
<?php patient_footer(); ?>
