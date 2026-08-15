<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/source-bootstrap.php';
require __DIR__ . '/service-type-bootstrap.php';
require __DIR__ . '/service-name-bootstrap.php';
require __DIR__ . '/service-action-bootstrap.php';
require __DIR__ . '/complaint-bootstrap.php';
require __DIR__ . '/anamnesis-bootstrap.php';
require __DIR__ . '/cash-bootstrap.php';
require __DIR__ . '/bank-bootstrap.php';
require __DIR__ . '/employee-patient-link.php';
require __DIR__ . '/patient-layout.php';

function patient_parse_money(mixed $value): float
{
    $text = preg_replace('/[^0-9,.-]/u', '', (string)$value) ?? '';
    if (str_contains($text, ',')) return (float)str_replace(',', '.', str_replace('.', '', $text));
    return (float)str_replace('.', '', $text);
}

function next_service_record_no(PDO $pdo): string
{
    $highest = 1452;
    foreach ($pdo->query('SELECT record_no FROM patient_services WHERE record_no IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $recordNo) {
        if (preg_match('/^VX-(\d+)$/', (string)$recordNo, $matches)) $highest = max($highest, (int)$matches[1]);
    }
    return 'VX-' . ($highest + 1);
}

$pdo = db();
ensure_cash_schema($pdo);
$bankDefinitions = array_values(array_filter(bank_definitions(), static fn(array $bank): bool => (int)$bank['active'] === 1));
$mailOrderAccounts = [];
try {
    $mailOrderAccounts = $pdo->query("SELECT id,code,title,COALESCE(short_name,'') AS short_name FROM current_accounts ORDER BY title")->fetchAll();
} catch (Throwable $exception) {
}
$technicalServiceAccounts = [];
try {
    $technicalServiceAccounts = $pdo->query("SELECT id,title,COALESCE(short_name,'') AS short_name,'' AS technical_service_type FROM current_accounts WHERE COALESCE(technical_service,0)=1 ORDER BY title")->fetchAll();
} catch (Throwable $exception) {
}
ensure_patient_source_schema();
ensure_patient_staff_yeliz_schema();
$staffNames = patient_staff_names(true);
$anamnesisQuestions = array_values(array_filter(anamnesis_question_definitions(), static fn(array $question): bool => (int)$question['active'] === 1));
$anamnesisTextFields = array_values(array_filter(anamnesis_text_field_definitions(), static fn(array $field): bool => (int)$field['active'] === 1));
$anamnesisPrintSettings = anamnesis_print_settings();
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

$extraColumns = ['record_no VARCHAR(60) NULL','appointment_date DATE NULL','start_time VARCHAR(10) NULL','end_time VARCHAR(10) NULL','service_type VARCHAR(150) NULL','service_location VARCHAR(150) NULL','branch_id INT NULL','contact_person VARCHAR(190) NULL','appointment_status VARCHAR(100) NULL','complaint TEXT NULL','anamnesis_form TEXT NULL','observation TEXT NULL','service_name VARCHAR(150) NULL','stock_id BIGINT NULL','sales_details TEXT NULL','sales_locked TINYINT(1) NOT NULL DEFAULT 0','result_name VARCHAR(100) NULL','related_personnel TEXT NULL','satisfaction TINYINT NULL','action_name VARCHAR(150) NULL','repair_details TEXT NULL','description TEXT NULL'];
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

// 2023 aktarımında ödeme kaydı bulunan Ayşe Kürün'ün satış hizmet kartı
// eksik kaldıysa, kaydı bir kez ve mevcut bir karta dokunmadan geri oluşturur.
$ayseKurunRestoreKey = '20260808_restore_ayse_kurun_sales_service_v1';
$ayseKurunRestoreCheck = $pdo->prepare('SELECT 1 FROM app_migrations WHERE migration_key=?');
$ayseKurunRestoreCheck->execute([$ayseKurunRestoreKey]);
if (!$ayseKurunRestoreCheck->fetchColumn()) {
    $ayseKurunPatient = $pdo->prepare('SELECT p.id,p.branch_id,b.name AS branch_name FROM patients p LEFT JOIN branches b ON b.id=p.branch_id WHERE p.national_id=? LIMIT 1');
    $ayseKurunPatient->execute(['34738959750']);
    $ayseKurun = $ayseKurunPatient->fetch();
    if ($ayseKurun) {
        $ayseKurunServiceCheck = $pdo->prepare('SELECT 1 FROM patient_services WHERE patient_id=? LIMIT 1');
        $ayseKurunServiceCheck->execute([(int)$ayseKurun['id']]);
        if (!$ayseKurunServiceCheck->fetchColumn()) {
            $historicalRecordNo = 'VK-1453';
            $recordNoCheck = $pdo->prepare('SELECT 1 FROM patient_services WHERE record_no=? LIMIT 1');
            $recordNoCheck->execute([$historicalRecordNo]);
            if ($recordNoCheck->fetchColumn()) $historicalRecordNo = next_service_record_no($pdo);
            $salesDetails = json_encode(['sales_sale_date'=>'2023-09-23','sales_payment_type'=>'Mail Order','sales_payment_amount'=>'18.500,00 ₺'], JSON_UNESCAPED_UNICODE);
            $restoreService = $pdo->prepare('INSERT INTO patient_services(patient_id,service_date,service_status,opened_by,branch_name,record_no,appointment_date,start_time,end_time,service_type,branch_id,contact_person,appointment_status,service_name,result_name,satisfaction,related_personnel,sales_details) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $restoreService->execute([(int)$ayseKurun['id'], '2023-09-23', 'Onay', 'Sistem', (string)($ayseKurun['branch_name'] ?? ''), $historicalRecordNo, '2023-09-24', '09:00', '12:00', 'Ev Hizmeti', (int)($ayseKurun['branch_id'] ?? 0), 'Yeliz', 'Beklemede', 'Satış', 'Onay', 0, 'Yeliz', $salesDetails]);
        }
    }
    $pdo->prepare('INSERT INTO app_migrations(migration_key) VALUES(?)')->execute([$ayseKurunRestoreKey]);
}

// Hasta Kartındaki eski Hizmet Yeri bilgisini bir kez Hizmet Kartlarına taşır.
// İşlem tekrarlanabilir: kartı olan hastaya ikinci kart açılmaz.
// Geri yuklenen satis kartinin kaynak kayittaki urun ve garanti bilgilerini bir kez tamamlar.
$ayseKurunSalesDetailsKey = '20260808_restore_ayse_kurun_sales_details_v2';
$ayseKurunSalesDetailsCheck = $pdo->prepare('SELECT 1 FROM app_migrations WHERE migration_key=?');
$ayseKurunSalesDetailsCheck->execute([$ayseKurunSalesDetailsKey]);
if (!$ayseKurunSalesDetailsCheck->fetchColumn()) {
    $ayseKurunPatientId = $pdo->prepare('SELECT id FROM patients WHERE national_id=? LIMIT 1');
    $ayseKurunPatientId->execute(['34738959750']);
    $ayseKurunId = (int)$ayseKurunPatientId->fetchColumn();
    if ($ayseKurunId > 0) {
        $ayseKurunServiceId = $pdo->prepare('SELECT id FROM patient_services WHERE patient_id=? AND service_date=? ORDER BY id LIMIT 1');
        $ayseKurunServiceId->execute([$ayseKurunId, '2023-09-23']);
        $serviceId = (int)$ayseKurunServiceId->fetchColumn();
        if ($serviceId > 0) {
            $salesDetails = json_encode([
                'sales_brand'=>'Resound', 'sales_model'=>'KE488', 'sales_device_serial'=>'2356902633',
                'sales_device_sgk'=>'3.028,00 TL', 'sales_device_discount_rate'=>'',
                'sales_device_net_price'=>'15.472,00 TL',
                'sales_device_2_brand'=>'Resound', 'sales_device_2_model'=>'KE488',
                'sales_device_2_serial'=>'2356902622', 'sales_device_2_sgk'=>'3.028,00 TL',
                'sales_device_2_discount_rate'=>'', 'sales_device_2_net_price'=>'15.472,00 TL',
                'sales_sale_date'=>'2023-09-23', 'sales_warranty_start'=>'2023-10-03',
                'sales_warranty_end'=>'2028-10-03', 'sales_invoice_no'=>'1991',
                'sales_payment_type'=>'Mail Order', 'sales_total_discount_rate'=>'12.444,00 TL',
                'sales_payment_amount'=>'18.500,00 TL'
            ], JSON_UNESCAPED_UNICODE);
            $pdo->prepare('UPDATE patient_services SET service_name=?,sales_details=? WHERE id=?')
                ->execute(['Satis', $salesDetails, $serviceId]);
        }
    }
    $pdo->prepare('INSERT INTO app_migrations(migration_key) VALUES(?)')->execute([$ayseKurunSalesDetailsKey]);
}

// Ayşe Kürün'ün aktarılmış satışında hizmet-kasa-stok bağlarını bir kez düzeltir.
$ayseKurunSalesRelationKey = '20260808_restore_ayse_kurun_sales_relations_v3';
$ayseKurunSalesRelationCheck = $pdo->prepare('SELECT 1 FROM app_migrations WHERE migration_key=?');
$ayseKurunSalesRelationCheck->execute([$ayseKurunSalesRelationKey]);
if (!$ayseKurunSalesRelationCheck->fetchColumn()) {
    try {
        $patientStatement = $pdo->prepare('SELECT id,full_name FROM patients WHERE national_id=? LIMIT 1');
        $patientStatement->execute(['34738959750']);
        $ayseKurunPatient = $patientStatement->fetch() ?: [];
        $patientId = (int)($ayseKurunPatient['id'] ?? 0);
        if ($patientId > 0) {
            $serviceStatement = $pdo->prepare('SELECT id,record_no FROM patient_services WHERE patient_id=? AND service_date=? ORDER BY id LIMIT 1');
            $serviceStatement->execute([$patientId, '2023-09-23']);
            $ayseKurunSale = $serviceStatement->fetch() ?: [];
            $serviceId = (int)($ayseKurunSale['id'] ?? 0);
            $recordNo = trim((string)($ayseKurunSale['record_no'] ?? ''));
            if ($serviceId > 0 && $recordNo !== '') {
                $salesDetails = [
                    'sales_brand'=>'Resound', 'sales_model'=>'KE488', 'sales_device_serial'=>'2356902633',
                    'sales_device_sgk'=>'3.028,00 ₺', 'sales_device_discount_rate'=>'', 'sales_device_net_price'=>'15.472,00 ₺',
                    'sales_device_2_brand'=>'Resound', 'sales_device_2_model'=>'KE488', 'sales_device_2_serial'=>'2356902622',
                    'sales_device_2_sgk'=>'3.028,00 ₺', 'sales_device_2_discount_rate'=>'', 'sales_device_2_net_price'=>'15.472,00 ₺',
                    'sales_sale_date'=>'2023-09-23', 'sales_warranty_start'=>'2023-10-03', 'sales_warranty_end'=>'2028-10-03',
                    'sales_invoice_no'=>'1991', 'sales_payment_type'=>'Mail Order',
                    'sales_total_discount_rate'=>'12.444,00 ₺', 'sales_payment_amount'=>'18.500,00 ₺'
                ];
                $stockStatement = $pdo->prepare('SELECT id FROM stock_cards WHERE stock_type=? AND brand=? AND model=? ORDER BY id LIMIT 1');
                $stockStatement->execute(['İşitme Cihazı', 'Resound', 'KE488']);
                $stockId = (int)$stockStatement->fetchColumn();
                $pdo->prepare('UPDATE patient_services SET service_name=?,sales_details=?,stock_id=? WHERE id=? AND patient_id=?')
                    ->execute(['Satış', json_encode($salesDetails, JSON_UNESCAPED_UNICODE), $stockId ?: null, $serviceId, $patientId]);

                $sourceUrl = url('patient-followup.php?id=' . $patientId);
                $cashStatement = $pdo->prepare("SELECT id FROM cash_transactions WHERE source_url=? AND transaction_type='income' LIMIT 1");
                $cashStatement->execute([$sourceUrl]);
                if (!$cashStatement->fetchColumn()) {
                    $cashLinkStatement = $pdo->prepare("SELECT id FROM cash_transactions WHERE transaction_type='income' AND payment_type='mail_order' AND amount=18500 AND description LIKE ? LIMIT 1");
                    $cashLinkStatement->execute(['%' . (string)$ayseKurunPatient['full_name'] . '%']);
                    $cashId = (int)$cashLinkStatement->fetchColumn();
                    if ($cashId > 0) $pdo->prepare('UPDATE cash_transactions SET source_url=? WHERE id=?')->execute([$sourceUrl, $cashId]);
                    else $pdo->prepare('INSERT INTO cash_transactions(transaction_date,description,transaction_type,amount,payment_type,installment_count,source_url,cash_register) VALUES(?,?,?,?,?,?,?,?)')
                        ->execute(['2023-09-23', (string)$ayseKurunPatient['full_name'] . ' — Satış tahsilatı', 'income', 18500, 'mail_order', 1, $sourceUrl, 'pre']);
                }

                if ($stockId > 0) {
                    $description = 'Hizmet kartı satışı: ' . $recordNo;
                    $existsExit = $pdo->prepare("SELECT 1 FROM stock_movements WHERE movement_type='Çıkış' AND invoice_no=? AND serial_numbers LIKE ? LIMIT 1");
                    $insertExit = $pdo->prepare('INSERT INTO stock_movements(stock_id,movement_type,quantity,movement_date,description,invoice_no,serial_numbers) VALUES(?,?,?,?,?,?,?)');
                    foreach (['2356902633', '2356902622'] as $serial) {
                        $existsExit->execute(['1991', '%"' . $serial . '"%']);
                        if (!$existsExit->fetchColumn()) $insertExit->execute([$stockId, 'Çıkış', 1, '2023-09-23', $description, '1991', json_encode([$serial], JSON_UNESCAPED_UNICODE)]);
                    }
                }
            }
        }
    } catch (Throwable $exception) {
        // Kasa veya stok altyapısı henüz yoksa hizmet kartı yine de açılabilir kalır.
    }
    $pdo->prepare('INSERT INTO app_migrations(migration_key) VALUES(?)')->execute([$ayseKurunSalesRelationKey]);
}

// Eski aktarimlarda ASCII olarak kalmis Satis hizmet adini standartlastirir.
$salesServiceNameFixKey = '20260808_normalize_ascii_sales_service_name_v4';
$salesServiceNameFixCheck = $pdo->prepare('SELECT 1 FROM app_migrations WHERE migration_key=?');
$salesServiceNameFixCheck->execute([$salesServiceNameFixKey]);
if (!$salesServiceNameFixCheck->fetchColumn()) {
    $pdo->prepare("UPDATE patient_services SET service_name=? WHERE service_name='Satis' AND COALESCE(sales_details,'')<>''")
        ->execute(['Satış']);
    $pdo->prepare('INSERT INTO app_migrations(migration_key) VALUES(?)')->execute([$salesServiceNameFixKey]);
}

// Geri yukleme sonrasinda eklenen kisaltilmis personel adlarini da tam adla korur.
$servicePersonnelPostRestoreFixKey = '20260808_normalize_restored_service_personnel_v5';
$servicePersonnelPostRestoreFixCheck = $pdo->prepare('SELECT 1 FROM app_migrations WHERE migration_key=?');
$servicePersonnelPostRestoreFixCheck->execute([$servicePersonnelPostRestoreFixKey]);
if (!$servicePersonnelPostRestoreFixCheck->fetchColumn()) {
    $pdo->prepare('UPDATE patient_services SET contact_person=? WHERE contact_person=?')
        ->execute(['Yeliz Girgin Özkan', 'Yeliz']);
    $pdo->prepare('UPDATE patient_services SET related_personnel=? WHERE related_personnel=?')
        ->execute(['Yeliz Girgin Özkan', 'Yeliz']);
    $pdo->prepare('INSERT INTO app_migrations(migration_key) VALUES(?)')->execute([$servicePersonnelPostRestoreFixKey]);
}

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
$branchNamesById = [];
foreach ($branches as $branch) $branchNamesById[(int)$branch['id']] = (string)$branch['name'];
$serviceLocations = array_filter(service_type_definitions(), static fn(array $location): bool => (int)$location['active'] === 1);
$serviceNames = array_filter(service_name_definitions(), static fn(array $name): bool => (int)$name['active'] === 1);
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS stock_price_lists (id INTEGER PRIMARY KEY AUTOINCREMENT, brand VARCHAR(190) NOT NULL, valid_from DATE NOT NULL, valid_until DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS stock_price_lists (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, brand VARCHAR(190) NOT NULL, valid_from DATE NOT NULL, valid_until DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS stock_price_list_items (price_list_id INTEGER NOT NULL, stock_id INTEGER NOT NULL, list_price DECIMAL(12,2) NOT NULL DEFAULT 0, PRIMARY KEY(price_list_id,stock_id))'
    : 'CREATE TABLE IF NOT EXISTS stock_price_list_items (price_list_id INT UNSIGNED NOT NULL, stock_id INT UNSIGNED NOT NULL, list_price DECIMAL(12,2) NOT NULL DEFAULT 0, PRIMARY KEY(price_list_id,stock_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$stockMovementColumns = $sqlite
    ? array_column($pdo->query('PRAGMA table_info(stock_movements)')->fetchAll(), 'name')
    : array_column($pdo->query('SHOW COLUMNS FROM stock_movements')->fetchAll(), 'Field');
if (!in_array('service_id', $stockMovementColumns, true)) {
    $pdo->exec('ALTER TABLE stock_movements ADD COLUMN service_id ' . ($sqlite ? 'INTEGER NULL' : 'BIGINT NULL'));
}
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
$consumableStatement = $pdo->prepare("SELECT s.id,s.stock_code,s.stock_name,s.stock_type,s.sale_price,COALESCE((SELECT NULLIF(m.unit,'') FROM stock_movements m WHERE m.stock_id=s.id AND m.movement_type='Giriş' ORDER BY m.movement_date DESC,m.id DESC LIMIT 1),'Adet') AS unit FROM stock_cards s INNER JOIN (SELECT stock_id,SUM(CASE WHEN movement_type='Giriş' THEN quantity WHEN movement_type='Çıkış' THEN -quantity ELSE 0 END) AS stock_quantity FROM stock_movements GROUP BY stock_id) q ON q.stock_id=s.id AND q.stock_quantity>=1 WHERE s.stock_type IN (?,?) ORDER BY s.stock_type,s.stock_name,s.stock_code");
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
    // Eski satış kayıtlarında fatura no boş kalmışsa, aynı satışın stok çıkışındaki
    // seri no ile eşleşen fatura noyu ekranda geri yükle.
    if (trim((string)($serviceCard['service_name'] ?? '')) === 'Satış') {
        $savedDetails = json_decode((string)($serviceCard['sales_details'] ?? ''), true);
        if (is_array($savedDetails) && trim((string)($savedDetails['sales_invoice_no'] ?? '')) === '') {
            $serials = [];
            foreach ($savedDetails as $key => $savedValue) {
                if (preg_match('/^sales_(?:device(?:_[2-4])?_serial|charger_serial)$/', (string)$key) && trim((string)$savedValue) !== '') $serials[] = trim((string)$savedValue);
            }
            if ($serials) {
                $movementStatement = $pdo->prepare("SELECT invoice_no,serial_numbers FROM stock_movements WHERE movement_type='Çıkış' AND description LIKE ? AND COALESCE(invoice_no,'')<>'' ORDER BY id DESC");
                $movementStatement->execute(['Hizmet kartı satışı: ' . trim((string)$serviceCard['record_no']) . '%']);
                foreach ($movementStatement as $movement) {
                    $movementSerials = json_decode((string)$movement['serial_numbers'], true);
                    if (is_array($movementSerials) && array_intersect($serials, array_map('strval', $movementSerials))) {
                        $savedDetails['sales_invoice_no'] = (string)$movement['invoice_no'];
                        $serviceCard['sales_details'] = json_encode($savedDetails, JSON_UNESCAPED_UNICODE);
                        break;
                    }
                }
            }
        }
    }
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
    $stockExitStatement = $pdo->prepare("SELECT 1 FROM stock_movements WHERE movement_type='Çıkış' AND description LIKE ? LIMIT 1");
    $stockExitStatement->execute(['Hizmet kartı satışı: ' . trim((string)$serviceCard['record_no']) . '%']);
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
$savedRepairFeeCash = false;
if ($editId > 0 && trim((string)($serviceCard['service_name'] ?? '')) === 'Tamir') {
    try {
        $repairCashStatement = $pdo->prepare("SELECT 1 FROM cash_transactions WHERE source_url=? AND transaction_type='income' LIMIT 1");
        $repairCashStatement->execute([url('patient-followup.php?id=' . $id) . '&repair=' . $editId]);
        $savedRepairFeeCash = (bool)$repairCashStatement->fetchColumn();
    } catch (Throwable $exception) {
        $savedRepairFeeCash = false;
    }
}
if (trim((string)($serviceCard['service_name'] ?? '')) === 'Satış') {
    try {
        $cashPaymentStatement = $pdo->prepare("SELECT id,transaction_date,amount,description,payment_type,installment_count,bank_name,commission_rate,current_account_id,term_schedule FROM cash_transactions WHERE source_url=? AND transaction_type='income' ORDER BY transaction_date,id");
        $cashPaymentStatement->execute([url('patient-followup.php?id=' . $id)]);
        $savedCashRecords = $cashPaymentStatement->fetchAll();
        $savedCashRecord = $savedCashRecords[0] ?? [];
        $savedCashPaymentType = match ((string)($savedCashRecord['payment_type'] ?? '')) {
            'cash' => 'Nakit', 'eft_transfer' => 'EFT / Havale', 'credit_card' => 'Kredi Kartı', 'mail_order' => 'Mail Order', 'term' => 'Vadeli', default => '',
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
$salesDetailsLocked = (bool)($serviceCard['sales_locked'] ?? false);
$canManageSalesLock = is_admin();
$saleLinkState = static function (int $serviceId) use ($pdo, $id): array {
    $state = ['sale' => false, 'cash' => false, 'stock' => false, 'record_no' => ''];
    if ($serviceId < 1) return $state;
    $serviceStatement = $pdo->prepare('SELECT service_name,record_no FROM patient_services WHERE id=? AND patient_id=?');
    $serviceStatement->execute([$serviceId, $id]);
    $sale = $serviceStatement->fetch() ?: [];
    if (trim((string)($sale['service_name'] ?? '')) !== 'Satış') return $state;
    $state['sale'] = true;
    $state['record_no'] = trim((string)($sale['record_no'] ?? ''));
    try {
        $cashStatement = $pdo->prepare("SELECT 1 FROM cash_transactions WHERE source_url=? AND transaction_type='income' LIMIT 1");
        $cashStatement->execute([url('patient-followup.php?id=' . $id)]);
        $state['cash'] = (bool)$cashStatement->fetchColumn();
    } catch (Throwable $exception) { }
    if ($state['record_no'] !== '') {
        $stockStatement = $pdo->prepare("SELECT 1 FROM stock_movements WHERE movement_type='Çıkış' AND description LIKE ? LIMIT 1");
        $stockStatement->execute(['Hizmet kartı satışı: ' . $state['record_no'] . '%']);
        $state['stock'] = (bool)$stockStatement->fetchColumn();
    }
    return $state;
};
$saleEditLinks = $editId > 0 ? $saleLinkState($editId) : ['sale'=>false, 'cash'=>false, 'stock'=>false, 'record_no'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    $postedEditId = (int)($_POST['edit_id'] ?? 0);
    $cashDeleteId = (int)($_POST['cash_delete_id'] ?? 0);
    $linkedSaleState = $postedEditId > 0 ? $saleLinkState($postedEditId) : ['sale'=>false, 'cash'=>false, 'stock'=>false, 'record_no'=>''];
    if ($action === 'sales_toggle_lock' && $postedEditId > 0) {
        $lockStatement = $pdo->prepare("SELECT service_name,sales_locked FROM patient_services WHERE id=? AND patient_id=?");
        $lockStatement->execute([$postedEditId, $id]);
        $lockCard = $lockStatement->fetch() ?: [];
        $lockRequested = (string)($_POST['sales_locked'] ?? '') === '1';
        $completedPayment = $hasCompletedCashTransaction();
        $response = ['success' => false, 'message' => 'Kilit işlemi tamamlanamadı.'];
        if (trim((string)($lockCard['service_name'] ?? '')) !== 'Satış' || !$completedPayment) $response['message'] = 'Ödemesi tamamlanmış satış bulunamadı.';
        elseif (!$lockRequested && !is_admin()) { http_response_code(403); $response['message'] = 'Kilidi yalnız yönetici açabilir.'; }
        else {
            $pdo->prepare('UPDATE patient_services SET sales_locked=? WHERE id=? AND patient_id=?')->execute([$lockRequested ? 1 : 0, $postedEditId, $id]);
            $response = ['success' => true, 'locked' => $lockRequested];
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'save_anamnesis') {
        if ($postedEditId <= 0) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Önce hizmet kartını kaydedin; ardından anamnezi ayrı olarak kaydedebilirsiniz.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $statement = $pdo->prepare('UPDATE patient_services SET anamnesis_form=? WHERE id=? AND patient_id=?');
        $statement->execute([(string)($_POST['anamnesis_form'] ?? ''), $postedEditId, $id]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $statement->rowCount() >= 0, 'message' => 'Anamnez kaydedildi.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
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
        $moneyValue = static fn(mixed $value): float => patient_parse_money($value);
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
        $cashUpdateAmount = patient_parse_money($_POST['cash_update_amount'] ?? '0');
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
                $cashUpdateAmount += patient_parse_money($installment['amount'] ?? '');
            }
        }
        if ($cashUpdateDate !== '' && $cashUpdateDescription !== '' && ($cashUpdateAmount > 0 || $cashUpdatePayment === 'term') && in_array($cashUpdatePayment, ['cash','eft_transfer','credit_card','mail_order','term'], true)) {
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
        $cashUpdateExtraAmount = patient_parse_money($_POST['cash_update_extra_amount'] ?? '0');
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
                $installmentAmount = patient_parse_money($installment['amount'] ?? '0');
                $cashUpdateExtraValidationAmount += $installmentAmount;
                if (!empty($installment['paid'])) $cashUpdateExtraAmount += $installmentAmount;
            }
        }
        if ($cashUpdateExtraDescription !== '' && $cashUpdateExtraValidationAmount > 0 && in_array($cashUpdateExtraPayment, ['cash','eft_transfer','credit_card','mail_order','term'], true)) {
            $cashUpdateExtraStatement = $pdo->prepare("UPDATE cash_transactions SET description=?,amount=?,payment_type=?,installment_count=?,bank_name=?,commission_rate=?,current_account_id=?,term_schedule=? WHERE id=? AND transaction_type='income'");
            $cashUpdateExtraStatement->execute([$cashUpdateExtraDescription, $cashUpdateExtraAmount, $cashUpdateExtraPayment, $cashUpdateExtraInstallments, $cashUpdateExtraBank ?: null, $cashUpdateExtraRate ?: null, $cashUpdateExtraAccountId ?: null, $cashUpdateExtraTermSchedule ?: null, $cashUpdateExtraId]);
        }
    } elseif (trim((string)($_POST['cash_update_extra_payment_type'] ?? '')) !== '') {
        $cashUpdateExtraDescription = trim((string)($_POST['cash_update_extra_description'] ?? ''));
        $cashUpdateExtraAmount = patient_parse_money($_POST['cash_update_extra_amount'] ?? '0');
        $cashUpdateExtraPayment = (string)($_POST['cash_update_extra_payment_type'] ?? '');
        $cashUpdateExtraInstallments = max(1, (int)($_POST['cash_update_extra_installment_count'] ?? 1));
        $cashUpdateExtraBank = trim((string)($_POST['cash_update_extra_bank_name'] ?? ''));
        $cashUpdateExtraRate = (float)str_replace(',', '.', (string)($_POST['cash_update_extra_commission_rate'] ?? '0'));
        $cashUpdateExtraAccountId = (int)($_POST['cash_update_extra_current_account_id'] ?? 0);
        $cashUpdateDate = trim((string)($_POST['cash_update_date'] ?? ''));
        if ($cashUpdateDate !== '' && $cashUpdateExtraDescription !== '' && $cashUpdateExtraAmount > 0 && in_array($cashUpdateExtraPayment, ['cash','eft_transfer','credit_card','mail_order','term'], true)) {
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
    if ($postedEditId && $salesDetailsLocked && $savedServiceName === 'Satış') {
        http_response_code(423);
        exit('Satış bilgileri kilitli. Yalnız yönetici kilidi açabilir.');
    }
    if ($action === 'delete' && $postedEditId) {
        if ($linkedSaleState['sale'] && $linkedSaleState['cash']) {
            $_SESSION['service_integrity_error'] = 'Bu satışa bağlı kasa tahsilatı bulunuyor. Önce tahsilatı iptal etmeden satış kartı silinemez.';
            redirect('patient-followup.php?id=' . $id . '&edit=' . $postedEditId);
        }
        if ($savedServiceName === 'Tamir') {
            try {
                $repairPayment = $pdo->prepare("SELECT 1 FROM cash_transactions WHERE source_url=? AND transaction_type='income' LIMIT 1");
                $repairPayment->execute([url('patient-followup.php?id=' . $id) . '&repair=' . $postedEditId]);
                if ($repairPayment->fetchColumn()) {
                    $_SESSION['service_integrity_error'] = 'Bu Tamir kartı için hastadan tahsilat yapılmış. Önce tahsilatı iptal etmeden kart silinemez.';
                    redirect('patient-followup.php?id=' . $id . '&edit=' . $postedEditId);
                }
            } catch (Throwable $exception) {
                error_log('repair delete payment check: ' . $exception->getMessage());
            }
        }
        if ($savedServiceName === 'Satış') {
            $recordNoStatement = $pdo->prepare('SELECT record_no FROM patient_services WHERE id=? AND patient_id=?');
            $recordNoStatement->execute([$postedEditId, $id]);
            $recordNo = trim((string)$recordNoStatement->fetchColumn());
            $pdo->prepare("DELETE FROM stock_movements WHERE movement_type='Çıkış' AND (service_id=? OR (service_id IS NULL AND description LIKE ?))")
                ->execute([$postedEditId, 'Hizmet kartı satışı: ' . $recordNo . '%']);
        }
        $pdo->prepare('DELETE FROM patient_services WHERE id=? AND patient_id=?')->execute([$postedEditId, $id]);
        redirect('patient-followup.php?id=' . $id);
    }
    $postedServiceName = trim((string)($_POST['service_name'] ?? ''));
    // Satış penceresinin Kaydet düğmesi bu işareti gönderir; hizmet türü
    // tarayıcıdaki seçim durumundan bağımsız olarak Satış olarak korunur.
    if (isset($_POST['return_to_sales_details']) || isset($_POST['save_sales_details'])) $postedServiceName = 'Satış';
    $postedSalesDetails = json_decode((string)($_POST['sales_details'] ?? ''), true);
    if (!is_array($postedSalesDetails)) $postedSalesDetails = [];
    foreach ($_POST as $key => $value) {
        if ($key === 'sales_details' || !str_starts_with((string)$key, 'sales_')) continue;
        $postedSalesDetails[(string)$key] = is_scalar($value) ? (string)$value : '';
    }
    $postedSalesDetailsJson = json_encode($postedSalesDetails, JSON_UNESCAPED_UNICODE);
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
    $postedRepairDetails = json_decode((string)($_POST['repair_details'] ?? ''), true);
    $repairReceivedBy = '';
    if (is_array($postedRepairDetails)) {
        foreach (['repair_accessories[]', 'repair_customer_issues[]', 'repair_technician_issues[]', 'repair_selected_device_serials[]'] as $repairListKey) {
            if (!isset($postedRepairDetails[$repairListKey]) || !is_array($postedRepairDetails[$repairListKey])) continue;
            $postedRepairDetails[$repairListKey] = array_values(array_unique(array_filter(array_map('trim', $postedRepairDetails[$repairListKey]), static fn(string $item): bool => $item !== '')));
        }
        $repairReceivedBy = trim((string)($postedRepairDetails['repair_received_by'] ?? ''));
        if ($repairReceivedBy !== '' && !in_array($repairReceivedBy, patient_staff_names(), true)) {
            $postedRepairDetails['repair_received_by'] = '';
            $repairReceivedBy = '';
        }
        if (array_key_exists('repair_patient_device_quantity', $postedRepairDetails)) {
            $postedRepairDetails['repair_patient_device_quantity'] = (string)max(1, min(2, (int)$postedRepairDetails['repair_patient_device_quantity']));
        }
        if (isset($postedRepairDetails['repair_selected_device_serials[]'])) {
            $maximumSelectedSerials = (int)($postedRepairDetails['repair_patient_device_quantity'] ?? 1);
            $postedRepairDetails['repair_selected_device_serials[]'] = array_slice($postedRepairDetails['repair_selected_device_serials[]'], 0, max(1, min(2, $maximumSelectedSerials)));
        }
        if ($postedServiceName === 'Tamir') {
            $requiredSerialCount = max(1, min(2, (int)($postedRepairDetails['repair_patient_device_quantity'] ?? 1)));
            $selectedSerialCount = count((array)($postedRepairDetails['repair_selected_device_serials[]'] ?? []));
            if ($selectedSerialCount !== $requiredSerialCount) {
                $_SESSION['service_integrity_error'] = 'Tamir kaydını kaydetmek için adet bilgisine uygun seri numarası seçmelisiniz.';
                redirect('patient-followup.php?id=' . $id . ($postedEditId ? '&edit=' . $postedEditId : '&new=1'));
            }
        }
    }
    $isRepair = $postedServiceName === 'Tamir';
    $recordDate = (string)($_POST['record_date'] ?? date('Y-m-d'));
    if ($isRepair) {
        if (!is_array($postedRepairDetails)) $postedRepairDetails = [];
        $branchDeliveryDate = trim((string)($postedRepairDetails['repair_branch_delivery_date'] ?? ''));
        if ($branchDeliveryDate !== '') $recordDate = $branchDeliveryDate;
        else $postedRepairDetails['repair_branch_delivery_date'] = $recordDate;
    }
    $postedRepairDetailsJson = is_array($postedRepairDetails)
        ? json_encode($postedRepairDetails, JSON_UNESCAPED_UNICODE)
        : (string)($_POST['repair_details'] ?? '');
    $contactPerson = trim((string)($_POST['contact_person'] ?? ''));
    if ($postedServiceName === 'Tamir' && $repairReceivedBy !== '') $contactPerson = $repairReceivedBy;
    $appointmentDate = $isRepair ? $recordDate : (string)($_POST['appointment_date'] ?? '');
    $serviceType = $isRepair ? 'Yüz yüze' : trim((string)($_POST['service_type'] ?? ''));
    $values = [
        'record_no'=>trim((string)($_POST['record_no'] ?? '')),
        'service_date'=>(string)($_POST['record_date'] ?? date('Y-m-d')),
        'service_status'=>trim((string)($_POST['result_name'] ?? 'Beklemede')) === 'Red' ? 'Ret' : trim((string)($_POST['result_name'] ?? 'Beklemede')),
        'performed_action'=>trim((string)($_POST['action_name'] ?? '')),
        'action_date'=>(string)($_POST['action_date'] ?? ''),
        'opened_by'=>(string)($_SESSION['user']['name'] ?? ''),
        'branch_name'=>$branchNamesById[(int)($_POST['branch_id'] ?? 0)] ?? '',
        'appointment_date'=>$appointmentDate,
        'start_time'=>(string)($_POST['start_time'] ?? ''), 'end_time'=>(string)($_POST['end_time'] ?? ''),
        'service_type'=>$serviceType, 'service_location'=>trim((string)($_POST['service_location'] ?? '')),
        'branch_id'=>(int)($_POST['branch_id'] ?? 0), 'contact_person'=>$contactPerson,
        'appointment_status'=>trim((string)($_POST['appointment_status'] ?? '')), 'complaint'=>trim((string)($_POST['complaint'] ?? '')), 'anamnesis_form'=>(string)($_POST['anamnesis_form'] ?? ''),
        'observation'=>trim((string)($_POST['observation'] ?? '')), 'service_name'=>$postedServiceName, 'stock_id'=>$postedServiceName === 'Satış' && $postedStockId > 0 ? $postedStockId : null,
        'result_name'=>trim((string)($_POST['result_name'] ?? '')) === 'Red' ? 'Ret' : trim((string)($_POST['result_name'] ?? '')), 'related_personnel'=>trim((string)($_POST['related_personnel'] ?? '')), 'satisfaction'=>(int)($_POST['satisfaction'] ?? 0),
        'action_name'=>trim((string)($_POST['action_name'] ?? '')), 'repair_details'=>$postedRepairDetailsJson, 'sales_details'=>$postedServiceName === 'Satış' ? $postedSalesDetailsJson : null, 'description'=>trim((string)($_POST['description'] ?? '')),
    ];
    if ($postedEditId && $linkedSaleState['sale'] && ($linkedSaleState['cash'] || $linkedSaleState['stock'])) {
        $savedDetailsForInvoice = json_decode((string)($serviceCard['sales_details'] ?? ''), true);
        $postedDetailsForInvoice = json_decode((string)($values['sales_details'] ?? ''), true);
        $invoiceOnlyUpdate = false;
        if (is_array($savedDetailsForInvoice) && is_array($postedDetailsForInvoice)) {
            $savedInvoice = trim((string)($savedDetailsForInvoice['sales_invoice_no'] ?? ''));
            $postedInvoice = trim((string)($postedDetailsForInvoice['sales_invoice_no'] ?? ''));
            unset($savedDetailsForInvoice['sales_invoice_no'], $postedDetailsForInvoice['sales_invoice_no']);
            ksort($savedDetailsForInvoice);
            ksort($postedDetailsForInvoice);
            $invoiceOnlyUpdate = $savedInvoice !== $postedInvoice && $savedDetailsForInvoice === $postedDetailsForInvoice;
        }
        if (!$invoiceOnlyUpdate && (string)($_POST['confirm_linked_sale_change'] ?? '') !== '1') {
            $_SESSION['service_integrity_error'] = 'Bu satış kartı kasa tahsilatı veya stok çıkışı ile bağlıdır. Değişikliğin tüm bağlı kayıtları etkileyebileceğini onaylamalısınız.';
            redirect('patient-followup.php?id=' . $id . '&edit=' . $postedEditId);
        }
        if ($linkedSaleState['cash'] && $postedServiceName === 'Satış') {
            $newDetails = json_decode((string)$values['sales_details'], true);
            $newSaleTotal = patient_parse_money(is_array($newDetails) ? ($newDetails['sales_payment_amount'] ?? 0) : 0);
            $cashTotalStatement = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE source_url=? AND transaction_type='income'");
            $cashTotalStatement->execute([url('patient-followup.php?id=' . $id)]);
            $cashTotal = (float)$cashTotalStatement->fetchColumn();
            if ($newSaleTotal > 0 && abs($newSaleTotal - $cashTotal) > 0.009) {
                $_SESSION['service_integrity_error'] = 'Satış toplamı ile kasa tahsilatı farklı olamaz. Önce Gelir Kayıt ekranından tahsilatı güncelleyin.';
                redirect('patient-followup.php?id=' . $id . '&edit=' . $postedEditId . '&open_income_record=1');
            }
        }
    }
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
    if ($values['record_no'] === '' || preg_match('/^HK\d+$/', $values['record_no'])) $values['record_no'] = next_service_record_no($pdo);
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
    // Cari hareketindeki miktar, satıştaki cihaz adetleri üzerinden oluşan stok çıkışlarının toplamıdır.
    // Satış kartı her kaydedildiğinde yalnız bu hizmet kartına bağlı eski çıkışlar yenilenir.
    $savedServiceWasSale = $savedServiceName === 'Satış';
    $saleCancelled = (string)($values['result_name'] ?? '') === 'İptal';
    if ($savedServiceWasSale || $postedServiceName === 'Satış') {
            $previousRecordNo = trim((string)($serviceCard['record_no'] ?? $values['record_no']));
            $pdo->prepare("DELETE FROM stock_movements WHERE movement_type='Çıkış' AND (service_id=? OR (service_id IS NULL AND description LIKE ?))")
                ->execute([$savedServiceId, 'Hizmet kartı satışı: ' . $previousRecordNo . '%']);
    }
    if ($postedServiceName === 'Satış' && !$saleCancelled) {
            $salesDetails = json_decode((string)$values['sales_details'], true);
            if (!is_array($salesDetails)) $salesDetails = [];
            $accountId = filter_var($salesDetails['sales_current_account'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $invoiceNo = trim((string)($salesDetails['sales_invoice_no'] ?? ''));
            $movementDate = $values['service_date'] ?: date('Y-m-d');
            $description = 'Hizmet kartı satışı: ' . $values['record_no'];
            $findStock = $pdo->prepare('SELECT id FROM stock_cards WHERE stock_type=? AND brand=? AND model=? ORDER BY id LIMIT 1');
            $addExit = $pdo->prepare('INSERT INTO stock_movements(stock_id,movement_type,quantity,movement_date,description,current_account_id,invoice_no,serial_numbers,service_id) VALUES(?,?,?,?,?,?,?,?,?)');
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
                $addExit->execute([$stockId, 'Çıkış', $quantity, $movementDate, $description, $accountId, $invoiceNo ?: null, $serialNumbers, $savedServiceId]);
            };
            $addDeviceExit('İşitme Cihazı', trim((string)($salesDetails['sales_brand'] ?? '')), trim((string)($salesDetails['sales_model'] ?? '')), trim((string)($salesDetails['sales_device_serial'] ?? '')));
            for ($deviceNumber = 2; $deviceNumber <= 4; $deviceNumber++) {
                $addDeviceExit('İşitme Cihazı', trim((string)($salesDetails["sales_device_{$deviceNumber}_brand"] ?? '')), trim((string)($salesDetails["sales_device_{$deviceNumber}_model"] ?? '')), trim((string)($salesDetails["sales_device_{$deviceNumber}_serial"] ?? '')));
            }
            $addDeviceExit('Şarj Cihazı', trim((string)($salesDetails['sales_charger_brand'] ?? '')), trim((string)($salesDetails['sales_charger_model'] ?? '')), trim((string)($salesDetails['sales_charger_serial'] ?? '')));
            $consumableStockId = filter_var($salesDetails['sales_consumable_stock_id'] ?? null, FILTER_VALIDATE_INT);
            $consumableQuantity = max(0, (int)($salesDetails['sales_consumable_quantity'] ?? 0));
            if ($consumableStockId && $consumableQuantity > 0) {
                $consumableDescription = $description . (trim((string)($salesDetails['sales_consumable_promotion'] ?? '')) === 'Evet' ? ' — Promosyonlu sarf malzeme' : '');
                $addExit->execute([$consumableStockId, 'Çıkış', $consumableQuantity, $movementDate, $consumableDescription, $accountId, $invoiceNo ?: null, null, $savedServiceId]);
            }
    }
    if ((string)($_POST['ajax'] ?? '') === 'repair_fee_prepare') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $savedServiceId > 0, 'service_id' => $savedServiceId], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (isset($_POST['return_to_sales_details']) && $savedServiceId > 0) redirect('patient-followup.php?id=' . $id . '&edit=' . $savedServiceId . '&open_sales_details=1');
    redirect('patient-followup.php?id=' . $id);
}

$servicesStatement = $pdo->prepare('SELECT * FROM patient_services WHERE patient_id=? ORDER BY service_date DESC,id DESC');
$servicesStatement->execute([$id]);
$services = $servicesStatement->fetchAll();
$latestDeviceBrandModel = '';
$latestDeviceQuantity = 0;
$latestDeviceSerials = [];
$latestDeviceInvoiceNo = '';
$latestDeviceSaleDate = '';
$latestDeviceWarrantyEnd = '';
foreach ($services as $service) {
    $saleDetails = json_decode((string)($service['sales_details'] ?? ''), true);
    if (!is_array($saleDetails)) continue;
    $latestDeviceModel = trim((string)($saleDetails['sales_model'] ?? ''));
    if ($latestDeviceModel === '') continue;
    $latestDeviceBrandModel = trim((string)($saleDetails['sales_brand'] ?? '') . ' ' . $latestDeviceModel);
    $latestDeviceInvoiceNo = trim((string)($saleDetails['sales_invoice_no'] ?? ''));
    $latestDeviceSaleDate = trim((string)($saleDetails['sales_sale_date'] ?? ''));
    $latestDeviceWarrantyEnd = trim((string)($saleDetails['sales_warranty_end'] ?? ''));
    $latestDeviceQuantity = 1;
    $latestDeviceSerials[] = trim((string)($saleDetails['sales_device_serial'] ?? ''));
    for ($deviceNumber = 2; $deviceNumber <= 4; $deviceNumber++) {
        if (trim((string)($saleDetails["sales_device_{$deviceNumber}_model"] ?? '')) !== '') {
            $latestDeviceQuantity++;
            $latestDeviceSerials[] = trim((string)($saleDetails["sales_device_{$deviceNumber}_serial"] ?? ''));
        }
    }
    break;
}
foreach ($services as &$service) {
    if (trim((string)($service['branch_name'] ?? '')) === '') $service['branch_name'] = $branchNamesById[(int)($service['branch_id'] ?? 0)] ?? '';
}
unset($service);
$incomeValidationError = (string)($_SESSION['income_validation_error'] ?? '');
$incomeValidationDraft = $_SESSION['income_validation_draft'] ?? [];
$serviceIntegrityError = (string)($_SESSION['service_integrity_error'] ?? '');
if (!is_array($incomeValidationDraft)) $incomeValidationDraft = [];
unset($_SESSION['income_validation_error']);
unset($_SESSION['income_validation_draft']);
unset($_SESSION['service_integrity_error']);
patient_header('Hizmetler', 'patients');
if ($serviceIntegrityError !== ''): ?><script>window.addEventListener('DOMContentLoaded',()=>setTimeout(()=>alert(<?=json_encode($serviceIntegrityError, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>),0));</script><?php endif;
if ($incomeValidationError !== ''): ?><script>window.addEventListener('DOMContentLoaded',()=>setTimeout(()=>{const openIncome=()=>{const form=document.querySelector('form[action*="cash.php"]'),modal=form?.parentElement;if(!modal){setTimeout(openIncome,50);return;}modal.hidden=false;modal.style.display='grid';setTimeout(()=>alert(<?=json_encode($incomeValidationError, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>),0);};openIncome();},350));</script><?php endif;
$requestedServiceName = trim((string)($_GET['service_name'] ?? ''));
$form = array_merge(['record_no'=>next_service_record_no($pdo),'service_date'=>date('Y-m-d'),'appointment_date'=>date('Y-m-d'),'start_time'=>'15:00','end_time'=>'17:00','service_type'=>'','service_location'=>(string)($patient['service_location'] ?? ''),'branch_id'=>'','contact_person'=>patient_staff_list($patient, $staffNames),'appointment_status'=>'','complaint'=>(string)($patient['anamnesis'] ?? ''),'anamnesis_form'=>'','observation'=>'','service_name'=>$requestedServiceName,'stock_id'=>null,'sales_details'=>'','result_name'=>$patientOutcome ?: 'Beklemede','related_personnel'=>patient_staff_list($patient, $staffNames),'satisfaction'=>1,'action_name'=>'','action_date'=>date('Y-m-d'),'repair_details'=>'','description'=>''], $serviceCard);
$showRepairDetailsButton = $showForm && trim((string)$form['service_name']) === 'Tamir' && trim((string)$form['repair_details']) !== '';
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
.service-input-with-icon{display:flex!important;align-items:stretch!important;grid-column:2!important;min-height:40px!important;border:1px solid #d5d3de!important;border-radius:6px!important;background:var(--card)!important;overflow:hidden!important}.service-input-icon{display:grid!important;place-items:center!important;flex:0 0 46px!important;width:46px!important;color:#686574!important;font-size:17px!important}.service-input-with-icon:has(textarea[name="complaint"]) .service-input-icon{color:#14843c!important}.service-input-with-icon:has(textarea[name="complaint"]) .service-input-icon i{display:grid!important;place-items:center!important;width:27px!important;height:27px!important;border:2px solid #e04f55!important;border-radius:50%!important}.service-input-with-icon input,.service-input-with-icon select,.service-input-with-icon textarea{width:100%!important;min-width:0!important;height:38px!important;min-height:38px!important;margin:0!important;padding:8px 12px 8px 0!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important}.service-input-with-icon textarea{height:76px!important;padding-top:10px!important}
.service-name-locked{display:flex!important;align-items:center!important;gap:8px!important;grid-column:2!important}.service-name-locked input{grid-column:auto!important;flex:1!important;background:#f4f4f6!important;color:#6d6b78!important;cursor:not-allowed!important}.service-name-income-slot{display:flex!important;align-items:center!important;gap:8px!important;grid-column:2!important;width:100%!important;min-width:0!important}.service-name-income-slot select,.service-name-income-slot>.service-input-with-icon{grid-column:auto!important;flex:1 1 auto!important;width:100%!important;min-width:0!important}.service-detail-button,.sales-details-link{display:inline-grid!important;place-items:center!important;flex:0 0 40px!important;width:40px!important;height:40px!important;padding:0!important;border:0!important;border-radius:6px!important;background:#19a94b!important;color:#fff!important;cursor:pointer!important;font-size:19px!important}.sales-details-link{text-decoration:none!important}.service-detail-button:hover,.sales-details-link:hover{background:#14833d!important}.sales-income-link{display:inline-grid!important;place-items:center!important;flex:0 0 40px!important;width:40px!important;height:40px!important;margin:0!important;padding:0!important;border-radius:6px!important;background:#19a94b!important;color:#fff!important;text-decoration:none!important;font-size:19px!important}.sales-income-link:hover{background:#14833d!important}
.service-detail-button,.sales-details-link{box-sizing:border-box!important;align-self:center!important;flex-basis:36px!important;width:36px!important;min-width:36px!important;max-width:36px!important;height:36px!important;min-height:36px!important;max-height:36px!important;font-size:18px!important}
@media(max-width:720px){.services-page{max-width:none!important;padding:92px 14px 30px!important}.services-head{padding-right:170px!important}.services-head .button{right:16px}.service-form-head{padding-right:16px!important}.action-box .service-field{grid-template-columns:1fr!important}.service-input-with-icon{grid-column:1!important}}
</style>
<style>
/* Hizmet kartı listesinin açık renk zorlamalarını gece temasında geri al. */
[data-theme=dark] .services-card{background:#30334d!important;border-color:#464968!important;box-shadow:0 3px 14px rgba(0,0,0,.24)!important}
[data-theme=dark] .services-head,[data-theme=dark] .services-toolbar{border-color:#464968!important}
[data-theme=dark] .services-head h2{color:#f2f2f7!important}
[data-theme=dark] .services-toolbar,[data-theme=dark] .services-table th{color:#c5c6d3!important}
[data-theme=dark] .services-table th,[data-theme=dark] .services-table td{border-color:#464968!important;color:#d5d6e2!important}
[data-theme=dark] .services-table th{color:#f2f2f7!important}
[data-theme=dark] .services-table tbody tr:hover{background:#393c59!important}
[data-theme=dark] .services-toolbar input[type=search]{background:#3c3f5f!important;border:1px solid #565a7d!important;color:#f4f4f8!important;border-radius:5px}
[data-theme=dark] .service-field input,[data-theme=dark] .service-field select,[data-theme=dark] .service-field textarea,[data-theme=dark] .service-input-with-icon{background:#3c3f5f!important;border-color:#565a7d!important;color:#f4f4f8!important}
[data-theme=dark] .service-name-locked input{background:#393c59!important;color:#c5c6d3!important}
</style>
<style>.service-form footer .button{box-sizing:border-box!important;width:36px!important;min-width:36px!important;max-width:36px!important;height:36px!important;min-height:36px!important;max-height:36px!important;padding:0!important;display:inline-grid!important;place-items:center!important}</style>
<main class="patient-container services-page"><section class="services-card">
<?php if($showForm): ?><header class="services-head service-form-head"><h2><?= $editId ? 'Hizmet Kartı Düzenle' : 'Yeni Hizmet Kartı' ?> - <?=e($patient['full_name'])?></h2><span class="service-form-actions"><a class="service-back-link" href="<?=e(url('patient-followup.php?id='.$id))?>">Listeye dön</a></span></header><form id="service-card-form" class="service-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="edit_id" value="<?=$editId?>"><input type="hidden" id="repair_details" name="repair_details" value="<?=e((string)$form['repair_details'])?>"><input type="hidden" id="sales_stock_id" name="stock_id" value="<?=e((string)($form['stock_id'] ?? ''))?>"><input type="hidden" id="sales_details" name="sales_details" value="<?=e((string)($form['sales_details'] ?? ''))?>">
<label class="service-field">Kayıt No<input name="record_no" value="<?=e((string)$form['record_no'])?>"></label><label class="service-field">Kayıt Tarihi<input type="date" name="record_date" value="<?=e((string)$form['service_date'])?>"></label>
<div class="service-three"><label class="service-field">Randevu Tarihi<input type="date" name="appointment_date" value="<?=e((string)$form['appointment_date'])?>"></label><label class="service-field">Başlangıç Saati<select name="start_time" required><?php for($hour=9;$hour<=19;$hour++):foreach([0,15,30,45] as $minute):if($hour===19&&$minute>0)continue;$time=sprintf('%02d:%02d',$hour,$minute);?><option value="<?=$time?>" <?=((string)$form['start_time']===$time)?'selected':''?>><?=$time?></option><?php endforeach;endfor;?></select></label><label class="service-field">Bitiş Saati<select name="end_time" required><?php for($hour=9;$hour<=19;$hour++):foreach([0,15,30,45] as $minute):if(($hour===9&&$minute<15)||($hour===19&&$minute>0))continue;$time=sprintf('%02d:%02d',$hour,$minute);?><option value="<?=$time?>" <?=((string)$form['end_time']===$time)?'selected':''?>><?=$time?></option><?php endforeach;endfor;?></select></label></div>
<label class="service-field">Hizmet Tipi<select name="service_type"><option value="">Seçiniz</option><?php foreach($serviceCardTypes as $type):?><option value="<?=e($type['name'])?>" <?=((string)$form['service_type']===(string)$type['name'])?'selected':''?>><?=e($type['name'])?></option><?php endforeach?></select></label><label class="service-field">Hizmet Yeri<select name="service_location"><option value="">Seçiniz</option><?php foreach($serviceLocations as $location):?><option value="<?=e($location['name'])?>" <?=((string)$form['service_location']===(string)$location['name'])?'selected':''?>><?=e($location['name'])?></option><?php endforeach?></select></label>
<label class="service-field service-wide">Şube Seçin<select name="branch_id"><option value="">Seçiniz</option><?php foreach($branches as $branch):?><option value="<?=(int)$branch['id']?>" <?=((int)$form['branch_id']===(int)$branch['id'])?'selected':''?>><?=e($branch['name'])?></option><?php endforeach?></select></label>
<label class="service-field">İlgilenen Kişi<select name="contact_person"><option value="">Seçiniz</option><?php foreach($contactPersonOptions as $person):?><option value="<?=e($person)?>" <?=((string)$form['contact_person']===(string)$person)?'selected':''?>><?=e($person)?></option><?php endforeach?></select></label><label class="service-field">Randevu Durumu<select name="appointment_status"><option value="" <?=((string)$form['appointment_status']==='')?'selected':''?>>Seçiniz</option><?php foreach(['Beklemede','Onaylandı','Tamamlandı','İptal'] as $status):?><option <?=((string)$form['appointment_status']===$status)?'selected':''?>><?=$status?></option><?php endforeach?></select></label>
<label class="service-field service-wide">Anamnez<textarea name="complaint" placeholder="Anamnez Girin"><?=e((string)$form['complaint'])?></textarea></label><label class="service-field service-wide">Gözlem<textarea name="observation" placeholder="Gözlem Girin"><?=e((string)$form['observation'])?></textarea></label>
<?php if ($serviceNameLocked): ?><label class="service-field">Hizmet Adı<span class="service-name-locked"><input value="<?=e((string)$form['service_name'])?>" readonly aria-label="Kilitli hizmet adı"><input type="hidden" name="service_name" value="<?=e((string)$form['service_name'])?>"><button type="button" class="service-detail-button" id="service-detail-button" title="Satış detayını aç" aria-label="Satış detayını aç"><i class="ti tabler-file-search"></i></button></span></label><?php else: ?><label class="service-field">Hizmet Adı<span class="service-name-income-slot"><select name="service_name"><option value="">Seçiniz</option><?php foreach($serviceNames as $serviceName):?><option value="<?=e($serviceName['name'])?>" <?=((string)$form['service_name']===(string)$serviceName['name'])?'selected':''?>><?=e($serviceName['name'])?></option><?php endforeach?></select><?php if($showSalesDetailsButton): ?><button type="button" class="sales-details-link" id="sales-details-link" title="Satış Kartını Aç" aria-label="Satış Kartını Aç"><i class="ti tabler-file-search"></i></button><?php endif ?></span></label><?php endif; ?><label class="service-field">Sonuç<select name="result_name"><?php foreach(['Beklemede','Onay','Düşünecek','Ret','Tamamlandı','İptal'] as $result):?><option <?=((string)$form['result_name']===$result)?'selected':''?>><?=$result?></option><?php endforeach?></select></label>
<section class="action-box"><label class="service-field">Aksiyon<select name="action_name"><option value="">Seçiniz</option><?php foreach($serviceActions as $serviceAction):?><option value="<?=e($serviceAction['name'])?>" <?=((string)$form['action_name']===(string)$serviceAction['name'])?'selected':''?>><?=e($serviceAction['name'])?></option><?php endforeach?></select></label><label class="service-field">Aksiyon Tarihi<input type="date" name="action_date" value="<?=e((string)$form['action_date'])?>"></label></section>
<div class="satisfaction"><label>Memnuniyet</label><div class="faces"><?php foreach(['🙂','😐','🙁','😡'] as $score=>$face):?><input id="s<?=$score+1?>" type="radio" name="satisfaction" value="<?=$score+1?>" <?=((int)$form['satisfaction']===$score+1)?'checked':''?>><label for="s<?=$score+1?>"><?=$face?></label><?php endforeach?></div></div>
<label class="service-field service-wide">Açıklama<textarea name="description"><?=e((string)$form['description'])?></textarea></label><footer><button class="button"><?=$editId ? 'Güncelle' : 'Kaydet'?></button><a class="cancel-link" href="<?=e(url('patient-followup.php?id='.$id))?>">İptal</a></footer></form>
<script>(()=>{const iconByField={record_no:'tabler-hash',record_date:'tabler-calendar',appointment_date:'tabler-calendar-event',start_time:'tabler-clock',end_time:'tabler-clock',service_type:'tabler-phone',service_location:'tabler-building',branch_id:'tabler-building-community',contact_person:'tabler-user',appointment_status:'tabler-calendar-check',complaint:'tabler-notes',observation:'tabler-eye',service_name:'tabler-clipboard-list',result_name:'tabler-circle-check',action_name:'tabler-bolt',action_date:'tabler-calendar-event',description:'tabler-file-text'};document.querySelectorAll('.service-form input[name],.service-form select[name],.service-form textarea[name]').forEach(field=>{if(field.type==='hidden'||field.closest('.service-input-with-icon'))return;const icon=iconByField[field.name];if(!icon)return;const wrapper=document.createElement('span');wrapper.className='service-input-with-icon';const iconSlot=document.createElement('span');iconSlot.className='service-input-icon';iconSlot.innerHTML=`<i class="ti ${icon}" aria-hidden="true"></i>`;field.parentNode.insertBefore(wrapper,field);wrapper.append(iconSlot,field);});})();</script>
<script>document.addEventListener('DOMContentLoaded',()=>{const openSalesDetails=()=>{const modal=document.getElementById('sales-details-modal');if(!modal)return;modal.hidden=false;modal.setAttribute('aria-hidden','false');};document.getElementById('service-detail-button')?.addEventListener('click',openSalesDetails);document.getElementById('sales-details-link')?.addEventListener('click',openSalesDetails);if(<?=json_encode($showRepairDetailsButton)?>){const slot=document.querySelector('.service-name-income-slot');if(slot&&!document.getElementById('repair-details-link')){const button=document.createElement('button');button.type='button';button.id='repair-details-link';button.className='sales-details-link';button.title='Tamir detaylarını aç';button.setAttribute('aria-label','Tamir detaylarını aç');button.innerHTML='<i class="ti tabler-tools"></i>';button.addEventListener('click',()=>{const modal=document.getElementById('repair-modal');if(modal){modal.hidden=false;modal.setAttribute('aria-hidden','false');}});slot.append(button);}}});</script>
<?php else: ?><header class="services-head"><h2>Hasta Hizmet Kartı Yönetimi - <?=e($patient['full_name'])?></h2><a class="button" href="<?=e(url('patient-followup.php?id='.$id.'&new=1'))?>">＋ Yeni Hizmet Kartı Ekle</a></header><div class="services-toolbar"><span>Toplam <?=count($services)?> kayıt</span><span>Ara: <input type="search" placeholder="Ara"></span></div><table class="services-table"><thead><tr><th>SIRA</th><th>TARİH</th><th>DURUM</th><th>YAPILAN İŞLEM</th><th>AKSİYON</th><th>İLGİLENEN</th><th>ŞUBE</th><th>İŞLEM</th></tr></thead><tbody><?php foreach($services as $index=>$service):?><tr data-edit-url="<?=e(url('patient-followup.php?id='.$id.'&edit='.(int)$service['id']))?>"><td><?=$index+1?></td><td><?=e(format_date_tr($service['service_date']))?></td><td><?=e($service['service_status'])?></td><td><?=e($service['service_name'] ?? '')?:'—'?></td><td><?=e(format_date_tr($service['action_date']))?></td><td><?=e($service['contact_person'] ?? '')?></td><td><?=e($service['branch_name'])?></td><td><a class="button" href="<?=e(url('patient-followup.php?id='.$id.'&edit='.(int)$service['id']))?>" title="Düzenle"><i class="icon-base ti tabler-edit"></i></a><form method="post" style="display:inline" onsubmit="return confirm('Bu hizmet kartı silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="edit_id" value="<?=(int)$service['id']?>"><button class="button" style="background:#e04f55" title="Sil"><i class="icon-base ti tabler-trash"></i></button></form></td></tr><?php endforeach;if(!$services):?><tr><td colspan="8" class="service-empty">Henüz hizmet kartı bulunmuyor.</td></tr><?php endif?></tbody></table><script>document.querySelectorAll('.services-table tbody tr[data-edit-url]').forEach(row=>{row.style.cursor='pointer';row.addEventListener('dblclick',event=>{if(event.target.closest('a,button,form,input'))return;window.location.href=row.dataset.editUrl;});});</script><?php endif; ?>
</section></main>
<?php if (!$showForm): ?>
<script>
document.querySelectorAll('.services-table form').forEach(form => {
  if (form.querySelector('[name="action"]')?.value !== 'delete') return;
  const serviceName = form.closest('tr')?.children[3]?.textContent.trim();
  if (serviceName !== 'Tamir') return;
  form.onsubmit = () => confirm('Bu Tamir kartını silmek istiyor musunuz? Hastadan tahsilat yapılmadıysa kayıt ve Teknik Servis listesindeki satır silinecektir.');
});
</script>
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
<style>
@import url('https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap');
/* Vuexy Form with Tabs: aynı kart, sekme ve tipografi ölçüleri */
#repair-modal .repair-accordion{display:grid;gap:10px}
#repair-modal .repair-accordion-item{overflow:hidden;border:1px solid #e1e2e8;border-radius:7px;background:#fff}
#repair-modal .repair-accordion-trigger{display:flex;align-items:center;justify-content:space-between;width:100%;padding:14px 16px;border:0;background:#fff;color:#2f2b3d;font:600 15px inherit;text-align:left;cursor:pointer}
#repair-modal .repair-accordion-trigger:hover{background:#f8f8fa}
#repair-modal .repair-accordion-trigger .ti{color:#7a7890;transition:transform .2s ease}
#repair-modal .repair-accordion-item.is-open .repair-accordion-trigger{color:#159447}
#repair-modal .repair-accordion-item.is-open .repair-accordion-trigger .ti{transform:rotate(180deg)}
#repair-modal .repair-accordion-panel{display:none;padding:16px;border-top:1px solid #e1e2e8}
#repair-modal .repair-accordion-item.is-open .repair-accordion-panel{display:grid;gap:14px}
#repair-modal .repair-accessories{display:grid;grid-template-columns:repeat(4,max-content);justify-content:start;gap:12px 28px}
#repair-modal .repair-accessories label{display:flex;flex-direction:row!important;align-items:center;gap:7px;font-size:14px;color:#2f2b3d}
#repair-modal .repair-accordion-panel>label{display:flex;flex-direction:column;gap:7px;color:#2f2b3d;font-size:14px}
@media(max-width:620px){#repair-modal .repair-accessories{grid-template-columns:repeat(2,max-content);justify-content:start;gap:12px 20px}}
#repair-modal .repair-dialog,#repair-modal .repair-dialog button,#repair-modal .repair-dialog input,#repair-modal .repair-dialog select,#repair-modal .repair-dialog textarea{font-family:'Public Sans',sans-serif}
#repair-modal .repair-tabs{overflow:hidden;border:0;border-radius:6px;background:#fff;box-shadow:0 2px 6px 0 rgba(67,89,113,.12)}
#repair-modal .repair-tab-list{display:flex;margin:0;padding:0;border:0;list-style:none;background:#fff}
#repair-modal .repair-tab{position:relative;display:inline-flex;align-items:center;justify-content:center;min-height:48px;margin-right:4px;padding:12px 20px;border:0;border-bottom:2px solid transparent;background:transparent;color:#6d6875;font-size:15px;font-weight:400;line-height:24px;cursor:pointer}
#repair-modal .repair-tab:hover{color:#159447}
#repair-modal .repair-tab.is-active{color:#159447;background:transparent;font-weight:500}
#repair-modal .repair-tab.is-active::after{position:absolute;right:0;bottom:-2px;left:0;height:2px;background:#159447;content:''}
#repair-modal .repair-tab-panel{display:none;padding:24px}
#repair-modal .repair-tab-panel.is-active{display:grid;gap:14px}
#repair-modal .repair-tab-panel>label{display:flex;flex-direction:column;gap:7px;color:#2f2b3d;font-size:14px}
#repair-modal #repair-save{display:inline-grid;place-items:center;width:36px;min-width:36px;height:36px;min-height:36px;padding:0}
#repair-modal #repair-save .ti{font-size:20px;line-height:1}
#repair-modal .repair-dialog{width:min(760px,100%)}
#repair-modal #repair-tab-issues{padding:16px 24px}
#repair-modal .repair-issues{max-height:none!important;overflow:visible!important}
#repair-modal .repair-issues>label,#repair-modal .repair-issue-head{grid-template-columns:minmax(0,400px) 70px 70px!important;padding:3px 0!important}
#repair-modal #repair-tab-delivery{grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 16px}
#repair-modal #repair-tab-delivery .repair-grid{display:contents}
#repair-modal #repair-tab-delivery .repair-switch,#repair-modal #repair-tab-delivery>label:last-child{grid-column:1/-1}
#repair-modal #repair-tab-delivery .repair-switch{grid-column:1/2!important}
#repair-modal #repair-tab-delivery .repair-priority{grid-column:2/3;display:flex;align-items:center;gap:16px;font-size:14px}
#repair-modal #repair-tab-delivery .repair-priority label{display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
#repair-modal #repair-tab-delivery .repair-switch input,#repair-modal #repair-tab-delivery .repair-priority input{width:16px;height:16px;margin:0;accent-color:#19a94b}
@media(max-width:620px){#repair-modal #repair-tab-delivery{grid-template-columns:1fr}}
@media(max-width:620px){#repair-modal .repair-tab-list{overflow-x:auto}#repair-modal .repair-tab{flex:0 0 auto;padding:12px 14px;font-size:14px}#repair-modal .repair-tab-panel{padding:16px}}
</style>
<style>#repair-modal .repair-accessories-title{grid-column:1/-1;margin:0 0 5px;color:#2f2b3d;font-size:15px}#repair-modal .repair-accessories .repair-switch{grid-column:1/3!important;gap:7px!important}#repair-modal .repair-accessories .repair-switch input{width:18px!important;height:18px!important;margin:0!important}#repair-modal .repair-invoice-number{grid-column:3/-1;display:flex!important;flex-direction:row!important;align-items:center!important;gap:8px;white-space:nowrap}#repair-modal .repair-invoice-number input{width:100%;min-width:0!important;min-height:0!important;padding:0!important;border:0!important;background:transparent!important;box-shadow:none!important}#repair-modal .repair-warranty-status{grid-column:1/-1;margin:0;font-size:13px;font-weight:600;color:#dc3545}#repair-modal .repair-warranty-status.is-expired{color:#dc3545}#repair-modal .repair-accessory-options{grid-column:1/-1;display:flex;justify-content:center;align-items:center;flex-wrap:wrap;gap:12px 28px}#repair-modal .repair-accessory-options label{display:inline-flex!important;flex-direction:row!important;align-items:center!important;gap:7px}#repair-modal #repair-tab-delivery .repair-priority{grid-column:1/-1!important;justify-content:center!important}#repair-modal .repair-accessories .repair-patient-device-model{grid-column:1/3;display:flex!important;flex-direction:column!important;align-items:stretch!important}#repair-modal .repair-accessories .repair-patient-device-quantity{grid-column:3;display:flex!important;flex-direction:column!important;align-items:stretch!important}#repair-modal .repair-accessories .repair-patient-device-sale-date{grid-column:4;display:flex!important;flex-direction:column!important;align-items:stretch!important}#repair-modal .repair-accessories .repair-patient-device-model input,#repair-modal .repair-accessories .repair-patient-device-quantity input,#repair-modal .repair-accessories .repair-patient-device-sale-date input{width:100%;box-sizing:border-box}#repair-modal .repair-patient-device-serials{grid-column:1/-1;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}#repair-modal .repair-patient-device-serials>label{display:flex!important;flex-direction:column!important;align-items:stretch!important}#repair-modal .repair-patient-device-serials .repair-serial-select{display:flex!important;flex-direction:row!important;align-items:center!important;gap:8px}#repair-modal .repair-patient-device-serials .repair-serial-select input[type="checkbox"]{width:17px!important;height:17px;margin:0;accent-color:#19a94b;flex:0 0 auto}#repair-modal .repair-patient-device-serials .repair-serial-select input[type="text"]{width:100%;box-sizing:border-box;flex:1}</style>
<div id="repair-modal" class="repair-modal" hidden aria-hidden="true">
  <div class="repair-modal-backdrop" data-repair-close></div>
  <section class="repair-dialog" role="dialog" aria-modal="true" aria-labelledby="repair-modal-title">
    <header><h2 id="repair-modal-title"><i class="ti tabler-tools" aria-hidden="true"></i> Tamir Kabul - Yeni Kayıt</h2><button type="button" class="repair-close" data-repair-close aria-label="Kapat">×</button></header>
    <div class="repair-body"><div class="repair-tabs" id="repair-form-tabs"><div class="repair-tab-list" role="tablist"><button type="button" class="repair-tab is-active" role="tab" aria-selected="true" aria-controls="repair-tab-accessories" data-repair-tab="repair-tab-accessories">Aksesuarlar</button><button type="button" class="repair-tab" role="tab" aria-selected="false" aria-controls="repair-tab-issues" data-repair-tab="repair-tab-issues">&#350;ikayet / Ar&#305;za</button><button type="button" class="repair-tab" role="tab" aria-selected="false" aria-controls="repair-tab-delivery" data-repair-tab="repair-tab-delivery">Teslim ve Garanti</button><button type="button" class="repair-tab" role="tab" aria-selected="false" aria-controls="repair-tab-service-fee" data-repair-tab="repair-tab-service-fee">Hizmet bedeli</button></div>
      <section id="repair-tab-accessories" class="repair-tab-panel repair-accessories is-active" role="tabpanel"><label><input form="service-card-form" type="checkbox" name="repair_accessories[]" value="Pil"> Pil</label><label><input form="service-card-form" type="checkbox" name="repair_accessories[]" value="Garanti Kart&#305;"> Garanti Kart&#305;</label><label><input form="service-card-form" type="checkbox" name="repair_accessories[]" value="Kutu"> Kutu</label><label><input form="service-card-form" type="checkbox" name="repair_accessories[]" value="Kulak Kal&#305;b&#305;"> Kulak Kal&#305;b&#305;</label></section>
      <section id="repair-tab-issues" class="repair-tab-panel" role="tabpanel"><fieldset class="repair-issues"><div class="repair-issue-head"><span></span><span>M&uuml;&#351;teri</span><span>Teknisyen</span></div><?php foreach($repairIssueDefinitions as $issue):?><label><span><?=e($issue['name'])?></span><input form="service-card-form" type="checkbox" name="repair_customer_issues[]" value="<?=e($issue['name'])?>"><input form="service-card-form" type="checkbox" name="repair_technician_issues[]" value="<?=e($issue['name'])?>"></label><?php endforeach?></fieldset></section>
      <section id="repair-tab-delivery" class="repair-tab-panel" role="tabpanel"><label class="repair-switch"><input form="service-card-form" type="checkbox" name="repair_warranty"><span></span> Garanti kapsam&#305;nda</label><label>&#350;ubeye Teslim tarihi<input form="service-card-form" type="date" name="repair_branch_delivery_date" value="<?=date('Y-m-d')?>"></label><label>Tamire teslim tarihi<input form="service-card-form" type="date" name="repair_delivery_date" value="<?=date('Y-m-d')?>"></label><div class="repair-grid"><label>Teknik servise g&ouml;nderilecekse (opsiyonel)<select form="service-card-form" name="repair_target"><option value="">Hedef</option><option>Teknik Servis</option></select></label><label>Teknik Servis<select form="service-card-form" name="repair_technician"><option value="">Teknik servis se&ccedil;in</option><?php foreach($technicalServiceAccounts as $technicalServiceAccount): $technicalServiceLabel=trim((string)($technicalServiceAccount['short_name'] ?: $technicalServiceAccount['title'])); $technicalServiceType=(string)$technicalServiceAccount['technical_service_type']; if($technicalServiceType !== '') $technicalServiceLabel.=' — '.str_replace(['inside','outside'], ['İç Servis','Dış Servis'], $technicalServiceType);?><option value="<?=e((string)$technicalServiceAccount['title'])?>"><?=e($technicalServiceLabel)?></option><?php endforeach?></select></label></div><label>Teslim eden (cihaz&#305; b&#305;rakan ki&#351;i)<input form="service-card-form" name="repair_delivered_by" placeholder="Ad Soyad (opsiyonel)"></label><label>Cihaz&#305; teslim alan ki&#351;i<select form="service-card-form" name="repair_received_by"><option value="">Se&ccedil;iniz</option><?php foreach($activeStaffNames as $staffName):?><option value="<?=e($staffName)?>"><?=e($staffName)?></option><?php endforeach?></select></label><label>A&ccedil;&#305;klama<textarea form="service-card-form" name="repair_note" placeholder="Ek a&ccedil;&#305;klama (opsiyonel)"></textarea></label></section>
      <section id="repair-tab-service-fee" class="repair-tab-panel" role="tabpanel"><div class="repair-grid"><label>Tarih<input form="service-card-form" type="date" name="repair_service_fee_date" value="<?=date('Y-m-d')?>"></label><label>&Uuml;cret<input form="service-card-form" type="text" name="repair_service_fee" inputmode="decimal" autocomplete="off" placeholder="0,00 &#8378;"></label></div><label>&Ouml;deme &#350;ekli<select form="service-card-form" name="repair_service_fee_payment_type"><option value="">Se&ccedil;iniz</option><option>Nakit</option><option>Kredi Kart&#305;</option><option>Mail Order</option><option>Vadeli</option></select></label></section>
    </div></div>
    <footer><button type="button" class="repair-cancel" data-repair-close>İptal</button><button type="button" class="button" id="repair-save" title="Tamir Kaydı Oluştur" aria-label="Tamir Kaydı Oluştur"><i class="ti tabler-device-floppy" aria-hidden="true"></i></button></footer>
  </section>
</div>
<?php endif; ?>
<script>(()=>{document.querySelector('[name="repair_target"]')?.closest('label')?.remove();const technicianLabel=document.querySelector('[name="repair_technician"]')?.closest('label');if(!technicianLabel)return;technicianLabel.setAttribute('aria-label','Teknik Servis');[...technicianLabel.childNodes].filter(node=>node.nodeType===Node.TEXT_NODE).forEach(node=>node.remove());technicianLabel.prepend(document.createTextNode('Teknik Servis'));})();</script>
<?php if($showForm): ?>
<div id="sales-stock-modal" class="repair-modal" hidden aria-hidden="true"><div class="repair-modal-backdrop" data-sales-close></div><section class="repair-dialog" role="dialog" aria-modal="true" aria-labelledby="sales-stock-title"><header><h2 id="sales-stock-title">Satış Stoğu Seç</h2><button type="button" class="repair-close" data-sales-close aria-label="Kapat">×</button></header><div class="repair-body"><input id="sales-stock-search" type="search" placeholder="Stok kodu, adı, marka veya model ara" autocomplete="off"><div id="sales-stock-list"><?php foreach($stockCards as $stock): $label=trim((string)$stock['stock_code'].' — '.(string)$stock['stock_name']); ?><button type="button" class="sales-stock-item" data-id="<?=(int)$stock['id']?>" data-label="<?=e($label)?>" data-search="<?=e(mb_strtolower($label.' '.(string)$stock['brand'].' '.(string)$stock['model'], 'UTF-8'))?>"><?=e($label)?></button><?php endforeach; if(!$stockCards): ?><p>Stok kartı bulunamadı.</p><?php endif; ?></div></div><footer><button type="button" class="repair-cancel" data-sales-close>İptal</button></footer></section></div>
<div id="sales-details-modal" class="repair-modal" hidden aria-hidden="true" data-sales-locked="<?=$salesDetailsLocked?'1':'0'?>"><div class="repair-modal-backdrop" data-sales-details-close></div><section class="repair-dialog sales-details-dialog" role="dialog" aria-modal="true" aria-labelledby="sales-details-title"><header><h2 id="sales-details-title">Satış Bilgileri</h2><button type="button" class="repair-close" data-sales-details-close aria-label="Kapat">×</button></header><div class="repair-body"><button type="button" id="add-hearing-device" class="button sales-device-button">+ İşitme Cihazı Ekle</button><div id="hearing-device-details" class="sales-device-details" hidden><label>Marka<input name="sales_brand" autocomplete="off"></label><label>Model<input name="sales_model" autocomplete="off"></label><label>Seri No<select name="sales_device_serial" disabled><option value="">Önce marka ve model seçiniz</option></select></label><label>SGK<input inputmode="decimal" name="sales_device_sgk" autocomplete="off"></label><label>İskonto % - TL<input inputmode="decimal" name="sales_device_discount_rate" autocomplete="off"></label><label>Net Fiyat<input inputmode="decimal" name="sales_device_net_price" autocomplete="off"></label></div><label>Satış Tarihi<input type="date" name="sales_sale_date" value="<?=date('Y-m-d')?>"></label><label>Garanti Başlangıç<input type="date" name="sales_warranty_start"></label><label>Garanti Bitiş<input type="date" name="sales_warranty_end"></label><label>Fatura No<input name="sales_invoice_no" autocomplete="off"></label><label>Ödeme Şekli<select name="sales_payment_type" disabled><option value="">Seçiniz</option><option>Nakit</option><option>Kredi Kartı</option><option>Mail Order</option><option>Vadeli</option></select></label><label>Toplam Tutar<input inputmode="decimal" name="sales_payment_amount" autocomplete="off" readonly></label></div><footer><button type="button" class="repair-cancel" data-sales-details-close>İptal</button><?php if($serviceNameLocked): ?><button type="button" id="sales-lock-toggle" class="button" data-admin="<?=$canManageSalesLock?'1':'0'?>" title="<?=$salesDetailsLocked?'Satış kilidini aç':'Satış bilgilerini kilitle'?>" aria-label="Satış kilidi"><i class="ti <?=$salesDetailsLocked?'tabler-lock':'tabler-lock-open'?>"></i></button><?php endif; ?><button type="submit" form="service-card-form" name="save_sales_details" value="1" class="button" id="sales-details-save">Tamam</button></footer></section></div>
<?php endif; ?>
<script>
document.addEventListener('click',async event=>{
  const removeButton=event.target.closest('.sales-product-cancel');
  if(removeButton){
    event.preventDefault();
    event.stopImmediatePropagation();
    if(<?=json_encode($serviceNameLocked)?>){alert('Ödemesi tamamlanan satıştaki ürünler silinemez.');return;}
    const modal=document.getElementById('sales-details-modal'),row=removeButton.closest('.sales-device-details, #charger-device-details, #consumable-details'),products=[...modal?.querySelectorAll('.sales-device-details:not([hidden]),#charger-device-details:not([hidden]),#consumable-details:not([hidden])')||[]];
    if(!row)return;
    if(products.length<=1){alert('Son ürün kalemi silinemez.');return;}
    row.querySelectorAll('[name]').forEach(field=>field.value='');
    row.remove();
    setTimeout(()=>modal?.querySelector('[name="sales_device_sgk"]')?.dispatchEvent(new Event('input',{bubbles:true})),0);
    return;
  }
  const saveButton=event.target.closest('#sales-details-save');
  if(!saveButton)return;
  const form=document.getElementById('service-card-form'),modal=document.getElementById('sales-details-modal');
  if(!form||!modal)return;
  if(<?=json_encode((bool)($saleEditLinks['sale'] && ($saleEditLinks['cash'] || $saleEditLinks['stock'])))?>&&!confirm('Bu satış kartı kasa tahsilatı ve/veya stok çıkışı ile bağlıdır. Değişikliği onaylıyor musunuz?'))return;
  if(modal.dataset.salesLocked==='1'){alert('Satış bilgileri kilitli. Yalnız yönetici kilidi açabilir.');return;}
  event.preventDefault();
  event.stopImmediatePropagation();
  const data=new FormData(form),salesDetails={};
  modal.querySelectorAll('[name]').forEach(field=>salesDetails[field.name]=field.value);
  data.set('sales_invoice_no',salesDetails.sales_invoice_no||'');
  data.set('service_name','Satış');
  data.set('save_sales_details','1');
  data.set('return_to_sales_details','1');
  data.set('confirm_linked_sale_change','1');
  data.set('sales_details',JSON.stringify(salesDetails));
  saveButton.disabled=true;
  try{
    const response=await fetch(form.action||location.href,{method:'POST',body:data,credentials:'same-origin'});
    if(!response.ok)throw new Error('Satış bilgileri kaydedilemedi.');
    const responseUrl=new URL(response.url),savedEditId=responseUrl.searchParams.get('edit');
    if(savedEditId){const editInput=form.querySelector('[name="edit_id"]');if(editInput)editInput.value=savedEditId;}
    history.replaceState(null,'',responseUrl.pathname+(responseUrl.search||''));
    const detailsInput=document.getElementById('sales_details');if(detailsInput)detailsInput.value=JSON.stringify(salesDetails);
    alert('Kayıt tamamlandı');
  }catch(error){alert(error.message||'Satış bilgileri kaydedilemedi.');saveButton.disabled=false;}
},true);
</script>
<script>
(() => {
  const modal = document.getElementById('repair-modal');
  modal?.querySelectorAll('.repair-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      const tabs = tab.closest('.repair-tabs');
      if (!tabs) return;
      const target = tab.dataset.repairTab;
      tabs.querySelectorAll('.repair-tab').forEach(button => {
        const active = button === tab;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      tabs.querySelectorAll('.repair-tab-panel').forEach(panel => panel.classList.toggle('is-active', panel.id === target));
    });
  });
  modal?.querySelectorAll('.repair-accordion-trigger').forEach(trigger => {
    trigger.addEventListener('click', () => {
      const item = trigger.closest('.repair-accordion-item');
      const accordion = item?.closest('.repair-accordion');
      if (!item || !accordion) return;
      const willOpen = !item.classList.contains('is-open');
      accordion.querySelectorAll('.repair-accordion-item').forEach(section => {
        const open = willOpen && section === item;
        section.classList.toggle('is-open', open);
        section.querySelector('.repair-accordion-trigger')?.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    });
  });
  const form = document.getElementById('service-card-form');
  const serviceName = form?.querySelector('[name="service_name"]');
  const details = document.getElementById('repair_details');
  if (!modal || !form || !serviceName || !details) return;
  const controls = [...modal.querySelectorAll('[name]')];
  const accessoriesPanel = modal.querySelector('#repair-tab-accessories');
  modal.querySelector('[data-repair-tab="repair-tab-accessories"]')?.replaceChildren('Garanti');
  modal.querySelector('[data-repair-tab="repair-tab-delivery"]')?.replaceChildren('Teslim');
  if (accessoriesPanel && !accessoriesPanel.querySelector('.repair-accessories-title')) {
    const title = document.createElement('h3');
    title.className = 'repair-accessories-title';
    title.textContent = 'Aksesuarlar';
    accessoriesPanel.prepend(title);
  }
  if (accessoriesPanel && !accessoriesPanel.querySelector('.repair-accessory-options')) {
    const options = [...accessoriesPanel.querySelectorAll('label')].filter(label => label.querySelector('[name="repair_accessories[]"]'));
    if (options.length) {
      const wrapper = document.createElement('div');
      wrapper.className = 'repair-accessory-options';
      options[0].before(wrapper);
      options.forEach(option => wrapper.append(option));
    }
  }
  const deliveryTab = modal.querySelector('#repair-tab-delivery');
  const patientDeviceBrandModel = <?=json_encode($latestDeviceBrandModel, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const patientDeviceQuantity = <?=json_encode($latestDeviceQuantity)?>;
  const patientDeviceSerials = <?=json_encode($latestDeviceSerials, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const patientDeviceInvoiceNo = <?=json_encode($latestDeviceInvoiceNo, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const patientDeviceSaleDate = <?=json_encode($latestDeviceSaleDate, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const patientDeviceWarrantyEnd = <?=json_encode($latestDeviceWarrantyEnd, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const repairPaymentLocked = <?=json_encode($savedRepairFeeCash)?>;
  const receivedByLabel = deliveryTab?.querySelector('[name="repair_received_by"]')?.closest('label');
  if (receivedByLabel && !deliveryTab.querySelector('.repair-patient-device-model')) {
    const label = document.createElement('label');
    label.className = 'repair-patient-device-model';
    label.textContent = 'Hastanın Cihazı';
    const input = document.createElement('input');
    input.type = 'text'; input.name = 'repair_patient_device_model'; input.readOnly = true; input.value = patientDeviceBrandModel || 'Cihaz bilgisi bulunamadı';
    input.setAttribute('form', 'service-card-form');
    label.append(input); receivedByLabel.after(label); controls.push(input);
    const quantityLabel = document.createElement('label');
    quantityLabel.className = 'repair-patient-device-quantity';
    quantityLabel.textContent = 'Adet';
    const quantityInput = document.createElement('input');
    quantityInput.type = 'number'; quantityInput.name = 'repair_patient_device_quantity'; quantityInput.min = '1'; quantityInput.max = '2'; quantityInput.step = '1'; quantityInput.value = String(Math.min(2, Math.max(1, patientDeviceQuantity || 1)));
    quantityInput.setAttribute('form', 'service-card-form');
    quantityLabel.append(quantityInput); label.after(quantityLabel); controls.push(quantityInput);
    const saleDateLabel = document.createElement('label');
    saleDateLabel.className = 'repair-patient-device-sale-date';
    saleDateLabel.textContent = 'Alım Tarihi';
    const saleDateInput = document.createElement('input');
    saleDateInput.type = 'text'; saleDateInput.readOnly = true;
    const saleDateParts = patientDeviceSaleDate.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    saleDateInput.value = saleDateParts ? `${saleDateParts[3]}.${saleDateParts[2]}.${saleDateParts[1]}` : (patientDeviceSaleDate || 'Alım tarihi bulunamadı');
    saleDateLabel.append(saleDateInput); quantityLabel.after(saleDateLabel);
    const serialGroup = document.createElement('div'); serialGroup.className = 'repair-patient-device-serials';
    const renderSerials = () => {
      serialGroup.replaceChildren();
      for (let index = 0; index < 2; index++) {
        const serial = patientDeviceSerials[index] || '';
        const serialLabel = document.createElement('label'); serialLabel.textContent = `Seri No ${index + 1}`;
        const serialSelect = document.createElement('label'); serialSelect.className = 'repair-serial-select';
        const serialCheckbox = document.createElement('input'); serialCheckbox.type = 'checkbox'; serialCheckbox.name = 'repair_selected_device_serials[]'; serialCheckbox.value = serial; serialCheckbox.disabled = !serial;
        serialCheckbox.setAttribute('form', 'service-card-form'); controls.push(serialCheckbox);
        serialCheckbox.addEventListener('change', () => {
          const maximum = Math.max(1, Math.min(2, Number(quantityInput.value) || 1));
          const selected = [...serialGroup.querySelectorAll('input[type="checkbox"]:checked')];
          if (selected.length > maximum) { serialCheckbox.checked = false; alert(`Adet bilgisine göre en fazla ${maximum} seri numarası seçebilirsiniz.`); }
        });
        const serialInput = document.createElement('input'); serialInput.type = 'text'; serialInput.readOnly = true; serialInput.value = serial || 'Seri numarası bulunamadı';
        serialSelect.append(serialCheckbox, serialInput); serialLabel.append(serialSelect); serialGroup.append(serialLabel);
      }
    };
    quantityInput.addEventListener('input', () => {
      if (quantityInput.value !== '') quantityInput.value = String(Math.max(1, Math.min(2, Number(quantityInput.value) || 1)));
      const maximum = Math.max(1, Math.min(2, Number(quantityInput.value) || 1));
      [...serialGroup.querySelectorAll('input[type="checkbox"]:checked')].slice(maximum).forEach(checkbox => { checkbox.checked = false; });
    });
    renderSerials();
    if (accessoriesPanel) accessoriesPanel.append(label, quantityLabel, saleDateLabel, serialGroup);
  }
  const repairBranchDeliveryLabel = deliveryTab?.querySelector('[name="repair_branch_delivery_date"]')?.closest('label');
  const repairBranchDeliveryTitle = repairBranchDeliveryLabel && [...repairBranchDeliveryLabel.childNodes].find(node => node.nodeType === Node.TEXT_NODE);
  if (repairBranchDeliveryTitle) repairBranchDeliveryTitle.nodeValue = 'Şubeye Teslim';
  const repairDeliveryDate = deliveryTab?.querySelector('[name="repair_delivery_date"]');
  const repairDeliveryLabel = repairDeliveryDate?.closest('label');
  if (repairDeliveryLabel) {
    const title = [...repairDeliveryLabel.childNodes].find(node => node.nodeType === Node.TEXT_NODE);
    if (title) title.nodeValue = 'Servise Teslim';
    const addDateField = (name, labelText) => {
      if (deliveryTab.querySelector(`[name="${name}"]`)) return;
      const label = document.createElement('label');
      label.textContent = labelText;
      const input = document.createElement('input');
      input.type = 'date'; input.name = name; input.setAttribute('form', 'service-card-form');
      label.append(input); deliveryTab.insertBefore(label, deliveryTab.querySelector('.repair-grid'));
      controls.push(input);
    };
    addDateField('repair_service_return_date', 'Servisten Dönüş');
    addDateField('repair_patient_delivery_date', 'Hasta Teslim');
  }
  const warrantyLabel = deliveryTab?.querySelector('[name="repair_warranty"]')?.closest('label');
  if (warrantyLabel && !deliveryTab.querySelector('.repair-priority')) {
    const priorities = document.createElement('div');
    priorities.className = 'repair-priority';
    ['Çok Acil', 'Acil', 'Normal'].forEach(priority => {
      const label = document.createElement('label');
      const input = document.createElement('input');
      input.type = 'checkbox'; input.name = 'repair_priority[]'; input.value = priority;
      input.setAttribute('form', 'service-card-form'); label.append(input, document.createTextNode(' ' + priority));
      priorities.append(label); controls.push(input);
    });
    warrantyLabel.after(priorities);
  }
  if (warrantyLabel && accessoriesPanel) {
    warrantyLabel.querySelector('span')?.remove();
    const accessoriesTitle = accessoriesPanel.querySelector('.repair-accessories-title');
    if (accessoriesTitle) accessoriesPanel.insertBefore(warrantyLabel, accessoriesTitle);
  }
  if (accessoriesPanel && !accessoriesPanel.querySelector('.repair-invoice-number')) {
    const invoiceLabel = document.createElement('label');
    invoiceLabel.className = 'repair-invoice-number';
    invoiceLabel.textContent = 'Fatura No:';
    const invoiceInput = document.createElement('input');
    invoiceInput.type = 'text'; invoiceInput.readOnly = true; invoiceInput.tabIndex = -1; invoiceInput.style.outline = 'none';
    invoiceInput.value = patientDeviceInvoiceNo || 'Fatura numarası bulunamadı';
    invoiceLabel.append(invoiceInput);
    const accessoriesTitle = accessoriesPanel.querySelector('.repair-accessories-title');
    if (accessoriesTitle) accessoriesPanel.insertBefore(invoiceLabel, accessoriesTitle);
    else accessoriesPanel.prepend(invoiceLabel);
  }
  if (warrantyLabel && accessoriesPanel && !accessoriesPanel.querySelector('.repair-warranty-status')) {
    const warrantyStatus = document.createElement('p');
    warrantyStatus.className = 'repair-warranty-status';
    const formatDate = value => new Date(`${value}T00:00:00`).toLocaleDateString('tr-TR');
    const updateWarrantyStatus = () => {
      if (!warrantyLabel.querySelector('input')?.checked) { warrantyStatus.hidden = true; return; }
      warrantyStatus.hidden = false;
      const warrantyEnd = patientDeviceWarrantyEnd ? new Date(`${patientDeviceWarrantyEnd}T00:00:00`) : null;
      if (!warrantyEnd || Number.isNaN(warrantyEnd.getTime())) { warrantyStatus.classList.add('is-expired'); warrantyStatus.textContent = 'Bu ürün için garanti bitiş tarihi bulunamadı.'; return; }
      const today = new Date(); today.setHours(0, 0, 0, 0);
      if (today > warrantyEnd) { warrantyStatus.classList.add('is-expired'); warrantyStatus.textContent = `Bu ürünün garanti süresi bitmiş. Garanti bitiş tarihi: ${formatDate(patientDeviceWarrantyEnd)}`; return; }
      let months = (warrantyEnd.getFullYear() - today.getFullYear()) * 12 + warrantyEnd.getMonth() - today.getMonth();
      let cursor = new Date(today); cursor.setMonth(cursor.getMonth() + months);
      if (cursor > warrantyEnd) { months--; cursor = new Date(today); cursor.setMonth(cursor.getMonth() + months); }
      const days = Math.round((warrantyEnd - cursor) / 86400000);
      warrantyStatus.classList.remove('is-expired'); warrantyStatus.textContent = `Garanti bitiş tarihi: ${formatDate(patientDeviceWarrantyEnd)} — Kalan süre: ${months} ay ${days} gün`;
    };
    warrantyLabel.querySelector('input')?.addEventListener('change', updateWarrantyStatus);
    const accessoriesTitle = accessoriesPanel.querySelector('.repair-accessories-title');
    if (accessoriesTitle) accessoriesPanel.insertBefore(warrantyStatus, accessoriesTitle);
    else accessoriesPanel.append(warrantyStatus);
    updateWarrantyStatus();
  }
  deliveryTab?.querySelectorAll('[name="repair_priority[]"]').forEach(input => {
    input.addEventListener('change', () => {
      if (input.checked) deliveryTab.querySelectorAll('[name="repair_priority[]"]').forEach(other => {
        if (other !== input) other.checked = false;
      });
    });
  });
  const repairTarget = modal.querySelector('[name="repair_target"]');
  const repairTechnician = modal.querySelector('[name="repair_technician"]');
  const syncRepairTechnician = () => {
    if (!repairTarget || !repairTechnician) return;
    const enabled = repairTarget.value === 'Teknik Servis';
    repairTechnician.disabled = !enabled;
    repairTechnician.setAttribute('aria-disabled', enabled ? 'false' : 'true');
  };
  repairTarget?.addEventListener('change', syncRepairTechnician);
  const serviceFee = modal.querySelector('[name="repair_service_fee"]');
  const formatServiceFee = () => {
    if (!serviceFee || !serviceFee.value.trim()) return;
    const raw = serviceFee.value.replace(/[^0-9,.-]/g, '');
    const amount = Number(raw.includes(',') ? raw.replaceAll('.', '').replace(',', '.') : raw);
    if (Number.isFinite(amount)) serviceFee.value = amount.toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ₺';
  };
  serviceFee?.addEventListener('blur', formatServiceFee);
  const serviceFeeDate = modal.querySelector('[name="repair_service_fee_date"]');
  const serviceFeePayment = modal.querySelector('[name="repair_service_fee_payment_type"]');
  const openRepairFeeIncome = async () => {
    let repairId = Number(form.querySelector('[name="edit_id"]')?.value || 0);
    formatServiceFee();
    const amount = Number(String(serviceFee?.value || '').replace(/[^0-9,.-]/g, '').replaceAll('.', '').replace(',', '.')) || 0;
    if (!serviceFeePayment?.value) { alert('Ödeme şeklini seçiniz.'); serviceFeePayment?.focus(); return; }
    persist();
    try {
      const requestData = new FormData(form);
      requestData.set('ajax', 'repair_fee_prepare');
      const response = await fetch(form.action || location.href, {method:'POST', body:requestData, credentials:'same-origin'});
      if (!response.ok) throw new Error('Hizmet bedeli kaydedilemedi.');
      const result = await response.json();
      if (!result.success) throw new Error('Tamir kartı kaydedilemedi.');
      const savedEditId = Number(result.service_id || 0);
      if (savedEditId) {
        repairId = savedEditId;
        const editInput = form.querySelector('[name="edit_id"]');
        if (editInput) editInput.value = String(savedEditId);
      }
    } catch (error) { alert(error.message || 'Hizmet bedeli kaydedilemedi.'); return; }
    if (!repairId) { alert('Tamir kartı kaydedilemedi. Lütfen tekrar deneyin.'); return; }
    const cashForm = document.querySelector('form[action*="cash.php"]');
    if (!cashForm) { alert('Gelir kayıt ekranı hazırlanamadı.'); return; }
    const paymentMap = {'Nakit':'cash','EFT / Havale':'eft_transfer','Kredi Kartı':'credit_card','Mail Order':'mail_order','Vadeli':'term'};
    const date = cashForm.querySelector('[name="transaction_date"]');
    const payment = cashForm.querySelector('[name="payment_type"]');
    const salesPayment = document.querySelector('[name="sales_payment_type"]');
    const cashAmount = cashForm.querySelector('[name="amount"]');
    const description = cashForm.querySelector('[name="description"]');
    const source = cashForm.querySelector('[name="source_url"]');
    const selectedPaymentType = paymentMap[serviceFeePayment.value] || 'cash';
    // Teknik servis tahsilatı satış formundaki önceki ödeme seçimiyle karışmamalı.
    cashForm.dataset.forcedPaymentType = selectedPaymentType;
    if (date && serviceFeeDate?.value) date.value = serviceFeeDate.value;
    const applyRepairPaymentType = () => {
      // Ortak gelir penceresinin yerleşimi satış seçimini kaynak alır. Teknik
      // servis tahsilatında bu seçimi geçici olarak aynı ödeme türüne eşitle.
      if (salesPayment) {
        salesPayment.dataset.repairOriginalValue ||= salesPayment.value;
        salesPayment.value = serviceFeePayment.value;
        salesPayment.dispatchEvent(new Event('change', {bubbles:true}));
      }
      if (!payment) return;
      payment.value = selectedPaymentType;
      payment.dispatchEvent(new Event('change', {bubbles:true}));
      if (cashAmount) cashAmount.value = amount > 0 ? (serviceFee?.value || '') : '';
      cashForm.dispatchEvent(new CustomEvent('repair-payment-change', {bubbles:true}));
    };
    applyRepairPaymentType();
    // Ortak gelir penceresinin satışa ait yerleşim betikleri çalıştıktan sonra da
    // teknik servis ödeme seçimini koru.
    [0, 80, 250, 500, 800].forEach(delay => setTimeout(applyRepairPaymentType, delay));
    if (cashAmount) cashAmount.value = serviceFee.value;
    if (description) description.value = <?=json_encode($patient['full_name'] . ' — Tamir hizmet bedeli tahsilatı', JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
    if (source) source.value = <?=json_encode(url('patient-followup.php?id=' . $id))?> + '&repair=' + repairId;
    const cashModal = cashForm.parentElement;
    if (cashModal) { cashModal.hidden = false; cashModal.style.display = 'grid'; }
  };
  if (serviceFeePayment && !modal.querySelector('#repair-fee-income')) {
    const button = document.createElement('button');
    button.type = 'button'; button.id = 'repair-fee-income'; button.className = 'button';
    button.title = 'Hizmet bedeli gelir kaydı'; button.setAttribute('aria-label', 'Hizmet bedeli gelir kaydı');
    button.innerHTML = '<i class="ti tabler-cash-register" style="font-size:20px;line-height:1" aria-hidden="true"></i>';
    button.style.cssText = 'width:36px;min-width:36px;height:36px;min-height:36px;margin:0;padding:0;display:inline-grid;place-items:center';
    button.addEventListener('click', openRepairFeeIncome);
    const syncIncomeButton = () => { button.hidden = ['Nakit', 'EFT / Havale'].includes(serviceFeePayment.value); };
    serviceFeePayment.addEventListener('change', () => {
      syncIncomeButton();
      const amount = Number(String(serviceFee?.value || '').replace(/[^0-9,.-]/g, '').replaceAll('.', '').replace(',', '.')) || 0;
      if (['Kredi Kartı', 'Mail Order', 'Vadeli'].includes(serviceFeePayment.value)) {
        setTimeout(openRepairFeeIncome, 0);
      }
    });
    syncIncomeButton();
    const footer = modal.querySelector('footer');
    const saveButton = footer?.querySelector('#repair-save');
    if (saveButton) footer.insertBefore(button, saveButton);
    else footer?.append(button);
    // Tahsilat penceresi ödeme şekliyle otomatik açılır; ayrıca bir düğme gerekmez.
    button.remove();
  }
  const open = () => { modal.hidden = false; modal.setAttribute('aria-hidden', 'false'); };
  const close = () => { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); };
  const restore = () => { try { const values = JSON.parse(details.value || '{}'); controls.forEach(control => { const value = values[control.name]; if (control.type === 'checkbox') control.checked = Array.isArray(value) ? value.includes(control.value) : Boolean(value); else if (value !== undefined) control.value = value; }); } catch (_) {} };
  const persist = () => { const values = {}; controls.forEach(control => { if (control.type === 'checkbox') { if (control.name.endsWith('[]')) { (values[control.name] ||= []); if (control.checked) values[control.name].push(control.value); } else values[control.name] = control.checked; } else values[control.name] = control.value; }); details.value = JSON.stringify(values); };
  restore();
  form.querySelector('[name="repair_patient_device_quantity"]')?.dispatchEvent(new Event('input'));
  if (repairPaymentLocked && serviceName.value.trim().toLocaleLowerCase('tr-TR') === 'tamir') {
    modal.querySelectorAll('.repair-body input,.repair-body select,.repair-body textarea').forEach(field => { field.disabled = true; field.readOnly = true; });
    const saveButton = document.getElementById('repair-save');
    if (saveButton) { saveButton.disabled = true; saveButton.title = 'Tahsilat bulunan tamir kaydı değiştirilemez'; }
    const notice = document.createElement('p');
    notice.className = 'repair-payment-lock-notice';
    notice.textContent = 'Bu tamir kaydına ödeme işlendiği için tamir bilgileri değiştirilemez.';
    modal.querySelector('.repair-body')?.prepend(notice);
  }
  if (!<?=json_encode($savedRepairFeeCash)?>) {
    if (serviceFee) serviceFee.value = '';
    if (serviceFeePayment) serviceFeePayment.value = '';
  }
  syncRepairTechnician();
  formatServiceFee();
  const applyNewRepairDefaults = () => {
    if (serviceName.value.trim().toLocaleLowerCase('tr-TR') !== 'tamir') return;
    const recordDateInput = form.querySelector('[name="record_date"]');
    const recordDate = recordDateInput?.value || '';
    const appointmentDate = form.querySelector('[name="appointment_date"]');
    const branchDeliveryDate = deliveryTab?.querySelector('[name="repair_branch_delivery_date"]');
    const effectiveDate = branchDeliveryDate?.value || recordDate;
    if (effectiveDate) {
      if (recordDateInput) recordDateInput.value = effectiveDate;
      if (appointmentDate) appointmentDate.value = effectiveDate;
      if (branchDeliveryDate && !branchDeliveryDate.value) branchDeliveryDate.value = effectiveDate;
    }
    const serviceType = form.querySelector('[name="service_type"]');
    const faceToFace = [...(serviceType?.options || [])].find(option => option.textContent.trim().toLocaleLowerCase('tr-TR') === 'yüz yüze');
    if (serviceType && faceToFace) serviceType.value = faceToFace.value;
  };
  deliveryTab?.querySelector('[name="repair_branch_delivery_date"]')?.addEventListener('change', applyNewRepairDefaults);
  serviceName.addEventListener('change', () => { if (serviceName.value.trim().toLocaleLowerCase('tr-TR') === 'tamir') { applyNewRepairDefaults(); open(); } });
  document.querySelectorAll('[data-repair-close]').forEach(button => button.addEventListener('click', close));
  const validateRepairSerialSelection = () => {
    if (serviceName.value.trim().toLocaleLowerCase('tr-TR') !== 'tamir') return true;
    const serialCheckboxes = [...modal.querySelectorAll('[name="repair_selected_device_serials[]"]:not(:disabled)')];
    if (!serialCheckboxes.length) return true;
    const quantity = Math.max(1, Math.min(2, Number(form.querySelector('[name="repair_patient_device_quantity"]')?.value) || 1));
    const selected = serialCheckboxes.filter(checkbox => checkbox.checked).length;
    if (selected === quantity) return true;
    alert(`Lütfen ${quantity} adet için ${quantity} seri numarası seçin.`);
    modal.querySelector('[data-repair-tab="repair-tab-accessories"]')?.click();
    return false;
  };
  document.getElementById('repair-save')?.addEventListener('click', () => { if (!validateRepairSerialSelection()) return; persist(); form.requestSubmit(); });
  form.addEventListener('submit', event => { if (!validateRepairSerialSelection()) { event.preventDefault(); return; } formatServiceFee(); persist(); });
  if (serviceName.value.trim().toLocaleLowerCase('tr-TR') === 'tamir') { applyNewRepairDefaults(); open(); }
})();
</script>
<script>
const initializeSalesScreen=()=>{
  const form=document.getElementById('service-card-form'), service=form?.querySelector('[name="service_name"]'), modal=document.getElementById('sales-stock-modal'), value=document.getElementById('sales_stock_id'), search=document.getElementById('sales-stock-search'), details=document.getElementById('sales_details'), detailsModal=document.getElementById('sales-details-modal');
  if(!form||!service||!modal||!value)return;
  const saleStockLocked=<?=json_encode($saleProductDeleteLocked)?>;
  const salePaymentCompleted=<?=json_encode($serviceNameLocked)?>;
  const linkedSaleNeedsConfirmation=<?=json_encode((bool)($saleEditLinks['sale'] && ($saleEditLinks['cash'] || $saleEditLinks['stock'])))?>;
  const confirmLinkedSaleChange=()=>{if(!linkedSaleNeedsConfirmation)return true;let confirmation=form.querySelector('[name="confirm_linked_sale_change"]');if(confirmation?.value==='1')return true;if(!confirm('Bu satış kartı kasa tahsilatı ve/veya stok çıkışı ile bağlıdır. Değişikliği onaylıyor musunuz?'))return false;confirmation=document.createElement('input');confirmation.type='hidden';confirmation.name='confirm_linked_sale_change';confirmation.value='1';form.append(confirmation);return true;};
  form.addEventListener('submit',event=>{if(!confirmLinkedSaleChange())event.preventDefault();},true);
  detailsModal?.querySelector('#sales-details-save')?.addEventListener('click',event=>{if(confirmLinkedSaleChange())return;event.preventDefault();event.stopImmediatePropagation();},true);
  const salesLockButton=detailsModal?.querySelector('#sales-lock-toggle'),applySalesLock=locked=>{if(!detailsModal)return;detailsModal.dataset.salesLocked=locked?'1':'0';detailsModal.querySelectorAll('.repair-body [name]').forEach(field=>{field.disabled=locked;field.readOnly=locked;});detailsModal.querySelectorAll('.sales-product-cancel,#add-hearing-device').forEach(button=>button.disabled=locked);detailsModal.querySelectorAll('#add-hearing-device,.sales-product-actions .button,[data-sales-product-action]').forEach(button=>{button.disabled=locked;button.setAttribute('aria-disabled',locked?'true':'false');button.style.opacity=locked?'.48':'';button.style.cursor=locked?'not-allowed':'';});detailsModal.querySelectorAll('[aria-label="Kasa"]').forEach(link=>{link.style.pointerEvents=locked?'none':'';link.style.opacity=locked?'.38':'';});const saveButton=detailsModal.querySelector('#sales-details-save');if(saveButton)saveButton.disabled=locked;if(salesLockButton){salesLockButton.title=locked?'Satış kilidini aç':'Satış bilgilerini kilitle';salesLockButton.innerHTML=`<i class="ti ${locked?'tabler-lock':'tabler-lock-open'}"></i>`;salesLockButton.style.background=locked?'#e6525d':'#19a94b';}};
  salesLockButton?.addEventListener('click',async()=>{const locked=detailsModal?.dataset.salesLocked==='1',isAdmin=salesLockButton.dataset.admin==='1';if(locked&&!isAdmin){alert('Kilidi yalnız yönetici açabilir.');return;}salesLockButton.disabled=true;try{const data=new FormData();data.set('csrf',form.querySelector('[name="csrf"]')?.value||'');data.set('action','sales_toggle_lock');data.set('edit_id',form.querySelector('[name="edit_id"]')?.value||'');data.set('sales_locked',locked?'0':'1');const response=await fetch(form.action||location.href,{method:'POST',body:data,credentials:'same-origin'}),result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Kilit işlemi tamamlanamadı.');applySalesLock(!!result.locked);updateTotalAmount();alert(result.locked?'Satış bilgileri kilitlendi.':'Satış kilidi açıldı.');}catch(error){alert(error.message||'Kilit işlemi tamamlanamadı.');}finally{salesLockButton.disabled=false;}});
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
  const salesFooter=detailsModal?.querySelector('.repair-dialog>footer'),salesGrandTotal=(()=>{if(!salesFooter)return null;let output=detailsModal.querySelector('#sales-grand-total');if(output)return output;output=document.createElement('span');output.id='sales-grand-total';output.textContent='Toplam Satış: 0,00 ₺';output.style.cssText='margin-right:auto;color:#e0444c;font-weight:700';salesFooter.prepend(output);if(salesLockButton)salesFooter.insertBefore(salesLockButton,salesFooter.querySelector('[data-sales-details-close]'));return output;})();
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
  const hideSelectedSerials=()=>{const claimed=new Set();deviceSerialSelects().forEach(select=>{const current=select.value||'';if(current&&claimed.has(current))select.value='';if(select.value)claimed.add(select.value);});deviceSerialSelects().forEach(select=>{let serials=[];try{serials=JSON.parse(select.dataset.serialOptions||'[]');}catch(_){}const current=select.value||'';if(current&&!serials.includes(current))serials.push(current);select.replaceChildren(new Option(serials.length?'Seri no seçiniz':'Seri numarası bulunamadı',''));serials.filter(serial=>serial===current||!claimed.has(serial)).forEach(serial=>select.add(new Option(serial,serial)));select.value=current;select.disabled=serials.length===0;});};
  const fillSerialOptions=(select,stocks)=>{if(!(select instanceof HTMLSelectElement))return;const current=select.dataset.value||select.value||'',serials=[...new Set(stocks.flatMap(stock=>{try{const values=JSON.parse(stock.serial_numbers||'[]');return Array.isArray(values)?values.map(value=>String(value).trim()).filter(Boolean):[];}catch(_){return [];}}))];if(current&&!serials.includes(current))serials.push(current);select.dataset.serialOptions=JSON.stringify(serials);select.replaceChildren(new Option(serials.length?'Seri no seçiniz':'Seri numarası bulunamadı',''));serials.forEach(serial=>select.add(new Option(serial,serial)));select.value=serials.includes(current)?current:'';delete select.dataset.value;select.disabled=!serials.length;hideSelectedSerials();};
  detailsModal?.addEventListener('change',event=>{if(event.target instanceof HTMLSelectElement&&/^sales_device(?:_[2-4])?_serial$/.test(event.target.name))hideSelectedSerials();});
  const deviceListPriceInput=(()=>{const serialLabel=deviceSerialInput?.closest('label');if(!serialLabel||detailsModal?.querySelector('[data-list-price-for="sales_device_serial"]'))return null;const label=document.createElement('label'),input=document.createElement('input');label.append('Liste Fiyatı');input.type='text';input.readOnly=true;input.className='sales-list-price';input.dataset.listPriceFor='sales_device_serial';label.append(input);serialLabel.after(label);return input;})();
  const totalSgkInput=(()=>{const totalField=detailsModal?.querySelector('[name="sales_payment_amount"]'),totalLabel=totalField?.closest('label');if(!totalField||!totalLabel||detailsModal?.querySelector('#sales_total_sgk'))return null;const label=document.createElement('label'),input=document.createElement('input');label.append('Toplam SGK');input.id='sales_total_sgk';input.type='text';input.readOnly=true;input.className='sales-total-sgk';label.append(input);totalLabel.before(label);return input;})();
  const totalDiscountInput=(()=>{const totalSgkLabel=detailsModal?.querySelector('#sales_total_sgk')?.closest('label');if(!totalSgkLabel||detailsModal?.querySelector('[name="sales_total_discount_rate"]'))return null;const label=document.createElement('label'),input=document.createElement('input');label.append('Toplam İskonto % - TL');input.name='sales_total_discount_rate';input.inputMode='decimal';input.autocomplete='off';label.append(input);totalSgkLabel.after(label);label.hidden=true;return input;})();
  const formatSgkMoneyFields=()=>detailsModal?.querySelectorAll('[name$="_sgk"]').forEach(field=>{const amount=parseTurkishMoney(field.value);if(amount!==null&&amount>0)field.value=formatTurkishMoney(amount);});
  detailsModal?.addEventListener('focusout',event=>{const field=event.target;if(!(field instanceof HTMLInputElement)||!field.name.endsWith('_sgk'))return;const amount=parseTurkishMoney(field.value);if(amount!==null&&amount>0)field.value=formatTurkishMoney(amount);});
  const hasSecondHearingDevice=()=>!!detailsModal?.querySelector('#hearing-device-details-2:not([hidden])');
  let totalDiscountModeActive=false;
  const syncTotalDiscountMode=()=>{const enabled=hasSecondHearingDevice(),label=totalDiscountInput?.closest('label'),deviceDiscountFields=[...detailsModal?.querySelectorAll('[name="sales_device_discount_rate"],[name="sales_device_2_discount_rate"]')||[]];if(detailsModal)detailsModal.dataset.salesLayout=enabled?'dual-hearing':'single-hearing';if(enabled!==totalDiscountModeActive){if(enabled){const previousDiscount=deviceDiscountFields.map(field=>field.value.trim()).find(Boolean)||'';if(totalDiscountInput&&!totalDiscountInput.value.trim())totalDiscountInput.value=previousDiscount;deviceDiscountFields.forEach(field=>{field.value='';const netPriceField=detailsModal?.querySelector(`[name="${field.name.replace(/_discount_rate$/,'_net_price')}"]`),listPrice=parseTurkishMoney(netPriceField?.dataset.listPrice||netPriceField?.value),sgkField=detailsModal?.querySelector(`[name="${field.name.replace(/_discount_rate$/,'_sgk')}"]`),sgk=parseTurkishMoney(sgkField?.value)||0;if(netPriceField&&listPrice!==null)netPriceField.value=formatTurkishMoney(Math.max(0,listPrice-sgk));});}else if(totalDiscountInput)totalDiscountInput.value='';totalDiscountModeActive=enabled;}if(label){label.hidden=!enabled;label.style.cssText=enabled?'display:flex!important;flex-direction:column;gap:7px':'display:none!important';}deviceDiscountFields.forEach(field=>{const fieldLabel=field.closest('label');if(fieldLabel){field.disabled=enabled;fieldLabel.hidden=false;fieldLabel.style.cssText=enabled?'display:none!important':'display:flex!important;visibility:visible;pointer-events:auto;flex-direction:column;gap:7px';}});};
  const updateTotalAmount=()=>{const totalField=detailsModal?.querySelector('[name="sales_payment_amount"]');if(!totalField)return;syncTotalDiscountMode();const netFields=['sales_device_net_price','sales_device_2_net_price','sales_charger_net_price'],sgkFields=['sales_device_sgk','sales_device_2_sgk','sales_charger_sgk'];let total=netFields.reduce((sum,name)=>sum+(parseTurkishMoney(detailsModal?.querySelector(`[name="${name}"]`)?.value)||0),0);const totalSgk=sgkFields.reduce((sum,name)=>sum+(parseTurkishMoney(detailsModal?.querySelector(`[name="${name}"]`)?.value)||0),0);if(totalSgkInput)totalSgkInput.value=totalSgk>0?formatTurkishMoney(totalSgk):'';const consumablePrice=parseTurkishMoney(detailsModal?.querySelector('[name="sales_consumable_price"]')?.value)||0,consumableQuantity=Number(detailsModal?.querySelector('[name="sales_consumable_quantity"]')?.value)||0;total+=consumablePrice*consumableQuantity;if(hasSecondHearingDevice()){const raw=totalDiscountInput?.value.trim()||'',discount=parseTurkishMoney(raw);if(discount!==null&&raw!=='')total=Math.max(0,raw.includes('%')?total*(1-discount/100):total-discount);}totalField.value=total>0?formatTurkishMoney(total):'';if(salesGrandTotal)salesGrandTotal.textContent='Toplam Satış: '+formatTurkishMoney(totalSgk+total);const paymentType=detailsModal?.querySelector('[name="sales_payment_type"]'),paymentLocked=<?=json_encode($savedCashRecord !== [])?>;if(paymentType){if(total<=0)paymentType.value='';paymentType.disabled=paymentLocked||total<=0;paymentType.title=paymentLocked?'Gelir kaydı bulunduğu için ödeme şekli değiştirilemez.':(total<=0?'Ürün ve toplam tutar olmadan ödeme şekli seçilemez.':'');}};
  totalDiscountInput?.addEventListener('input',updateTotalAmount);
  totalDiscountInput?.addEventListener('focusout',()=>{const raw=totalDiscountInput.value.trim();if(raw===''||raw.includes('%'))return;const amount=parseTurkishMoney(raw);if(amount!==null)totalDiscountInput.value=formatTurkishMoney(amount);updateTotalAmount();});
  const salesDetailsBody=detailsModal?.querySelector('.repair-body');if(salesDetailsBody)new MutationObserver(()=>updateTotalAmount()).observe(salesDetailsBody,{childList:true});
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
  consumableDetails.innerHTML='<input type="hidden" name="sales_consumable_promotion" value="Hayır"><input type="hidden" name="sales_consumable_unit" value="Adet"><input type="hidden" name="sales_consumable_unit_description" value=""><label>Sarf Malzeme / Pil<select name="sales_consumable_stock_id"><option value="">Sarf malzeme veya pil seçiniz</option></select></label><label>Adet<input type="number" min="1" step="1" name="sales_consumable_quantity" value="1"></label><label>Fiyat<input inputmode="decimal" name="sales_consumable_price" readonly></label>';
  consumableDetails.querySelectorAll('label').forEach(label=>label.style.cssText='display:flex;flex-direction:column;gap:7px');
  detailsModal?.querySelector('.repair-body')?.prepend(consumableDetails);
  const consumableSelect=consumableDetails.querySelector('[name="sales_consumable_stock_id"]'),consumableQuantityInput=consumableDetails.querySelector('[name="sales_consumable_quantity"]'),consumablePriceInput=consumableDetails.querySelector('[name="sales_consumable_price"]');
  consumableStocks.forEach(stock=>consumableSelect?.add(new Option(`[${stock.stock_type}] ${stock.stock_code} — ${stock.stock_name}`,stock.id)));
  const syncConsumablePrice=()=>{const stock=consumableStocks.find(item=>String(item.id)===String(consumableSelect?.value||''));if(consumablePriceInput)consumablePriceInput.value=listPriceForStock(stock);updateTotalAmount();};
  consumableSelect?.addEventListener('change',syncConsumablePrice);consumableQuantityInput?.addEventListener('input',updateTotalAmount);
  const toggleConsumableDetails=show=>{consumableDetails.hidden=!show;consumableDetails.style.display=show?'grid':'none';};
  const consumableModal=document.createElement('div');
  consumableModal.className='repair-modal';consumableModal.hidden=true;consumableModal.setAttribute('aria-hidden','true');
  consumableModal.innerHTML='<div class="repair-modal-backdrop" data-consumable-close></div><section class="repair-dialog" role="dialog" aria-modal="true" aria-labelledby="consumable-sale-title"><header><h2 id="consumable-sale-title">Sarf Malzeme Satışı</h2><button type="button" class="repair-close" data-consumable-close aria-label="Kapat">×</button></header><div class="repair-body" style="grid-template-columns:repeat(2,minmax(0,1fr))"><label style="grid-column:1/-1">Sarf Malzeme<select name="modal_consumable_stock"></select></label><label>Satış Fiyatı<input name="modal_consumable_price" inputmode="decimal" readonly></label><label>Promosyonlu mu?<select name="modal_consumable_promotion"><option>Hayır</option><option>Evet</option></select></label><label>Birim<input name="modal_consumable_unit" readonly></label><label>Birim Tanımı<input name="modal_consumable_description" readonly></label><label>Adet<input name="modal_consumable_quantity" type="number" min="1" step="1" value="1"></label><label>Toplam Satış Fiyatı<input name="modal_consumable_total" readonly></label></div><footer><button type="button" class="repair-cancel" data-consumable-close>İptal</button><button type="button" class="button" data-consumable-apply>Ürünü Ekle</button></footer></section>';
  document.body.append(consumableModal);
  const consumableForm=consumableModal.querySelector('.repair-body');
  consumableForm.classList.add('consumable-horizontal-form');
  consumableForm.style.cssText='display:block;padding:24px';
  [...consumableForm.querySelectorAll(':scope>label')].forEach((source,index)=>{const field=source.querySelector('input,select'),caption=source.firstChild?.textContent?.trim()||'';if(!field)return;const id=`consumable-form-field-${index}`;field.id=id;const row=document.createElement('div'),label=document.createElement('label'),control=document.createElement('div');row.className='consumable-form-row';row.style.cssText='display:grid;grid-template-columns:150px minmax(0,1fr);align-items:center;gap:16px;margin-bottom:16px';label.htmlFor=id;label.textContent=caption;label.style.cssText='margin:0;font-weight:500;color:#56606f';control.style.cssText='min-width:0';field.style.width='100%';source.replaceWith(row);control.append(field);row.append(label,control);});
  const consumableStyle=document.createElement('style');consumableStyle.textContent='.consumable-horizontal-form .consumable-form-row:last-child{margin-bottom:0}@media(max-width:620px){.consumable-horizontal-form .consumable-form-row{grid-template-columns:1fr!important;gap:6px!important}}';document.head.append(consumableStyle);
  const modalConsumableSelect=consumableModal.querySelector('[name="modal_consumable_stock"]'),modalConsumablePrice=consumableModal.querySelector('[name="modal_consumable_price"]'),modalConsumablePromotion=consumableModal.querySelector('[name="modal_consumable_promotion"]'),modalConsumableUnit=consumableModal.querySelector('[name="modal_consumable_unit"]'),modalConsumableDescription=consumableModal.querySelector('[name="modal_consumable_description"]'),modalConsumableQuantity=consumableModal.querySelector('[name="modal_consumable_quantity"]'),modalConsumableTotal=consumableModal.querySelector('[name="modal_consumable_total"]');
  modalConsumablePromotion.closest('.consumable-form-row')?.querySelector('label').replaceChildren('Promosyon');
  modalConsumableSelect.innerHTML=consumableSelect.innerHTML;
  const syncConsumableModal=()=>{const stock=consumableStocks.find(item=>String(item.id)===String(modalConsumableSelect.value)),isPromotion=modalConsumablePromotion.value==='Evet',price=isPromotion?formatTurkishMoney(0):(listPriceForStock(stock)||formatTurkishMoney(stock?.sale_price||0));if(modalConsumablePrice)modalConsumablePrice.value=price;if(modalConsumableUnit)modalConsumableUnit.value=stock?.unit||'Adet';if(modalConsumableDescription)modalConsumableDescription.value=stock?`${stock.stock_code} — ${stock.stock_name}`:'';const amount=parseTurkishMoney(modalConsumablePrice?.value)||0,quantity=Math.max(1,Number(modalConsumableQuantity?.value)||1);if(modalConsumableTotal)modalConsumableTotal.value=formatTurkishMoney(amount*quantity);};
  const openConsumableModal=()=>{modalConsumableSelect.value=consumableSelect.value||'';modalConsumableQuantity.value=consumableQuantityInput.value||1;modalConsumablePromotion.value=consumableDetails.querySelector('[name="sales_consumable_promotion"]')?.value||'Hayır';modalConsumablePrice.readOnly=true;syncConsumableModal();consumableModal.hidden=false;consumableModal.setAttribute('aria-hidden','false');modalConsumableSelect.focus();};
  modalConsumableSelect.addEventListener('change',syncConsumableModal);modalConsumableQuantity.addEventListener('input',syncConsumableModal);modalConsumablePromotion.addEventListener('change',syncConsumableModal);
  consumableModal.querySelectorAll('[data-consumable-close]').forEach(button=>button.addEventListener('click',()=>{consumableModal.hidden=true;consumableModal.setAttribute('aria-hidden','true');}));
  consumableModal.querySelector('[data-consumable-apply]').addEventListener('click',()=>{if(!modalConsumableSelect.value){alert('Sarf malzeme seçiniz.');modalConsumableSelect.focus();return;}consumableSelect.value=modalConsumableSelect.value;consumableQuantityInput.value=Math.max(1,Number(modalConsumableQuantity.value)||1);consumablePriceInput.value=modalConsumablePrice.value;consumableDetails.querySelector('[name="sales_consumable_promotion"]').value=modalConsumablePromotion.value;consumableDetails.querySelector('[name="sales_consumable_unit"]').value=modalConsumableUnit.value;consumableDetails.querySelector('[name="sales_consumable_unit_description"]').value=modalConsumableDescription.value;setProductType('Sarf Malzeme');showConsumableDetails();updateTotalAmount();consumableModal.hidden=true;consumableModal.setAttribute('aria-hidden','true');});
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
  const salesCashForm=document.querySelector('form[action*="cash.php"]');if(salesCashForm&&!salesCashForm.querySelector('[name="source_url"]')){const source=document.createElement('input');source.type='hidden';source.name='source_url';source.value=<?=json_encode(url('patient-followup.php?id='.$id))?>;salesCashForm.append(source);}
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
  const salesPaymentToCashType=value=>({'Nakit':'cash','EFT / Havale':'eft_transfer','Kredi Kartı':'credit_card','Mail Order':'mail_order','Vadeli':'term'}[value]||'');
  const hidePrimaryTermMonthlyField=()=>{if(paymentSelect?.value!=='Vadeli')return;commissionLabel?.remove();const section=cashSourceForm?.querySelector('section'),totalLabel=cashSourceForm?.querySelector('[name="amount"]')?.closest('label');if(section)section.style.setProperty('grid-template-columns','repeat(2,minmax(0,1fr))','important');if(totalLabel){const textNode=[...totalLabel.childNodes].find(node=>node.nodeType===Node.TEXT_NODE);if(textNode)textNode.nodeValue='Toplam';totalLabel.style.setProperty('display','flex','important');totalLabel.style.setProperty('grid-column','2 / 3','important');totalLabel.style.setProperty('grid-row','2','important');}};paymentSelect?.addEventListener('change',()=>setTimeout(hidePrimaryTermMonthlyField,0));setTimeout(hidePrimaryTermMonthlyField,0);
  const syncPrimaryPaymentFields=()=>{const type=cashSourceForm?.dataset.forcedPaymentType||cashPaymentSelect?.value||salesPaymentToCashType(paymentSelect?.value||'')||'cash';syncPaymentFields(cashSourceForm,type);};
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
  const repairIncomeLayouts=()=>{const primary=cashSourceForm?.querySelector('section'),primaryPaymentType=cashSourceForm?.dataset.forcedPaymentType||cashPaymentSelect?.value||salesPaymentToCashType(paymentSelect?.value||'')||'cash';if(primary?.querySelector('[data-primary-term-schedule]')){primary.style.setProperty('display','grid','important');primary.style.setProperty('grid-template-columns','repeat(2,minmax(0,1fr))','important');forceLabel(primary,'installment_count','1 / 2','2');forceLabel(primary,'amount','2 / 3','2');forceLabel(primary,'commission_rate','1 / 2','3',false);const schedule=primary.querySelector('[data-primary-term-schedule]');schedule.style.setProperty('grid-column','1 / -1','important');schedule.style.setProperty('grid-row','3','important');forceLabel(primary,'description','1 / -1','4');}else if(primary&&primaryPaymentType==='credit_card'){primary.style.setProperty('display','grid','important');primary.style.setProperty('grid-template-columns','repeat(2,minmax(0,1fr))','important');forceLabel(primary,'bank_name','1 / 2','2');forceLabel(primary,'installment_count','2 / 3','2');forceLabel(primary,'amount','1 / 2','3');forceLabel(primary,'commission_rate','2 / 3','3');forceLabel(primary,'description','1 / -1','4');}const extra=cashSourceForm?.querySelector('[data-extra-income]');if(!extra)return;extra.style.setProperty('display','grid','important');extra.style.setProperty('grid-template-columns','repeat(2,minmax(0,1fr))','important');extra.querySelectorAll('input,select,textarea').forEach(field=>field.style.setProperty('box-sizing','border-box','important'));const type=extra.querySelector('[name="extra_payment_type"]')?.value||'cash';if(type!=='term')extra.querySelector('[data-term-schedule]')?.remove();forceLabel(extra,'extra_payment_type','1 / -1','2');if(type==='credit_card'){forceLabel(extra,'extra_bank_name','1 / 2','3');forceLabel(extra,'extra_current_account_id','2 / 3','3',false);forceLabel(extra,'extra_installment_count','2 / 3','3');forceLabel(extra,'extra_commission_rate','2 / 3','4');forceLabel(extra,'extra_amount','1 / 2','4');forceLabel(extra,'extra_description','1 / -1','5');}else if(type==='mail_order'){forceLabel(extra,'extra_bank_name','1 / 2','3');forceLabel(extra,'extra_current_account_id','2 / 3','3');forceLabel(extra,'extra_installment_count','1 / 2','4',false);forceLabel(extra,'extra_commission_rate','2 / 3','4',false);forceLabel(extra,'extra_amount','1 / -1','4');forceLabel(extra,'extra_description','1 / -1','5');}else if(type==='cash'){forceLabel(extra,'extra_bank_name','1 / 2','3',false);forceLabel(extra,'extra_current_account_id','2 / 3','3',false);forceLabel(extra,'extra_installment_count','1 / 2','3',false);forceLabel(extra,'extra_commission_rate','2 / 3','3',false);forceLabel(extra,'extra_amount','1 / -1','3');forceLabel(extra,'extra_description','1 / -1','4');}};new MutationObserver(repairIncomeLayouts).observe(cashSourceForm||document.body,{childList:true,subtree:true});cashSourceForm?.addEventListener('change',()=>setTimeout(repairIncomeLayouts,0));setTimeout(repairIncomeLayouts,0);
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
  productActions.className='sales-product-actions';productActions.style.cssText='grid-column:1/-1;display:flex;align-items:center;gap:8px';
  detailsModal?.querySelector('.repair-body')?.prepend(productActions);
  productActions.append(addDeviceButton,addConsumableButton,addChargerButton);
  if(deviceDetails)productActions.after(deviceDetails);
  const arrangeProductSections=()=>{let anchor=productActions;[deviceDetails,detailsModal?.querySelector('#hearing-device-details-2'),chargerDetails,consumableDetails].filter(Boolean).forEach(section=>{anchor.after(section);anchor=section;});};
  addDeviceButton.textContent='İşitme Cihazı';
  let productWasRemoved=false;
  const clearFields=names=>names.forEach(name=>{const field=detailsModal?.querySelector(`[name="${name}"]`);if(field)field.value='';});
  const activeProductLineCount=()=>{const groups=[['sales_brand','sales_model','sales_device_serial'],['sales_device_2_brand','sales_device_2_model','sales_device_2_serial'],['sales_charger_brand','sales_charger_model','sales_charger_serial'],['sales_consumable_stock_id']];return groups.filter(names=>names.some(name=>String(detailsModal?.querySelector(`[name="${name}"]`)?.value||'').trim()!=='' )).length;};
  const refreshProductDeleteButtons=()=>{const showButtons=!salePaymentCompleted&&(!saleStockLocked||activeProductLineCount()>1);detailsModal?.querySelectorAll('.sales-product-cancel').forEach(button=>button.hidden=!showButtons);};
  const addProductCancel=(container,names,onClear)=>{const button=document.createElement('button');button.type='button';button.className='repair-cancel sales-product-cancel';button.textContent='×';button.title='Ürünü kaldır';button.setAttribute('aria-label','Ürünü kaldır');const removeProduct=()=>{if(salePaymentCompleted){alert('Ödemesi tamamlanan satıştaki ürünler silinemez.');return;}if(saleStockLocked&&activeProductLineCount()<=1){alert('Hasta ödeme yapmış. Son ürün kalemini silemezsiniz.');return;}productWasRemoved=true;clearFields(names);onClear();updateTotalAmount();refreshProductDeleteButtons();};button.addEventListener('click',event=>{event.preventDefault();event.stopImmediatePropagation();removeProduct();},true);container.append(button);refreshProductDeleteButtons();return button;};
  detailsModal?.addEventListener('change',event=>{if(event.target instanceof HTMLInputElement||event.target instanceof HTMLSelectElement)refreshProductDeleteButtons();});
  let consumableCancel=null;
  const showConsumableDetails=()=>{chargerDetails?.after(consumableDetails);toggleConsumableDetails(true);consumableCancel??=addProductCancel(consumableDetails,['sales_consumable_stock_id','sales_consumable_quantity','sales_consumable_price'],()=>{toggleConsumableDetails(false);if(detailsModal?.dataset.productType==='Sarf Malzeme')setProductType('');});};
  let firstDeviceCancel=null;
  const showFirstDevice=()=>{if(!deviceDetails)return;toggleDeviceDetails(true);firstDeviceCancel??=addProductCancel(deviceDetails,['sales_brand','sales_model','sales_device_serial','sales_device_sgk','sales_device_discount_rate','sales_device_net_price'],()=>{toggleDeviceDetails(false);if(detailsModal?.dataset.productType==='İşitme Cihazı')setProductType('');});};
  const updateDeviceAddButton=()=>{if(addDeviceButton)addDeviceButton.hidden=!!detailsModal?.querySelector('#hearing-device-details-2');};
  const addExtraDevice=number=>{
    if(number!==2||detailsModal?.querySelector(`#hearing-device-details-${number}`))return;
    const previous=number===2?deviceDetails:detailsModal?.querySelector(`#hearing-device-details-${number-1}`);if(!previous)return;
    const device=document.createElement('div');device.id=`hearing-device-details-${number}`;device.className='sales-device-details';device.style.cssText='grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px';
    device.innerHTML=`<label>İşitme Cihazı Markası<select name="sales_device_${number}_brand"></select></label><label>Model<select name="sales_device_${number}_model"></select></label><label>Seri No<select name="sales_device_${number}_serial" disabled><option value="">Önce marka ve model seçiniz</option></select></label><label>SGK<input inputmode="decimal" name="sales_device_${number}_sgk" autocomplete="off"></label><label>İskonto % - TL<input inputmode="decimal" name="sales_device_${number}_discount_rate" autocomplete="off"></label><label>Net Fiyat<input inputmode="decimal" name="sales_device_${number}_net_price" autocomplete="off"></label>`;
    device.querySelectorAll('label').forEach(label=>label.style.cssText='display:flex;flex-direction:column;gap:7px');previous.after(device);
    const brand=device.querySelector(`[name="sales_device_${number}_brand"]`),model=device.querySelector(`[name="sales_device_${number}_model"]`),serial=device.querySelector(`[name="sales_device_${number}_serial"]`),sgk=device.querySelector(`[name="sales_device_${number}_sgk"]`),discount=device.querySelector(`[name="sales_device_${number}_discount_rate"]`),netPrice=device.querySelector(`[name="sales_device_${number}_net_price"]`);
    const sync=()=>{if(!brand.value){model.replaceChildren(new Option('Önce marka seçiniz',''));model.disabled=true;return;}const invoice=(salesInvoiceInput?.value||'').trim(),historicalModels=invoice?salesExitSerials.filter(item=>item.brand===brand.value&&String(item.invoice_no||'').trim()===invoice).map(item=>item.model):[];fillSelect(model,[...hearingDeviceStocks.filter(stock=>stock.brand===brand.value).map(stock=>stock.model),...historicalModels],'Model seçiniz');model.disabled=false;};
    fillSelect(brand,hearingDeviceStocks.map(stock=>stock.brand),'Marka seçiniz');sync();
    if(number===2){const firstBrand=detailsModal?.querySelector('[name="sales_brand"]')?.value||'',firstModel=detailsModal?.querySelector('[name="sales_model"]')?.value||'';brand.value=firstBrand;brand.dispatchEvent(new Event('change'));if(firstModel&&![...model.options].some(option=>option.value===firstModel))model.add(new Option(firstModel,firstModel));model.disabled=false;model.value=firstModel;const stocks=hearingDeviceStocks.filter(item=>item.brand===firstBrand&&item.model===firstModel),listPrice=listPriceForStock(stocks[0]);fillSerialOptions(serial,[...stocks,...invoiceMatchedSerials(firstBrand,firstModel)]);if(sgk)sgk.value=detailsModal?.querySelector('[name="sales_device_sgk"]')?.value||'';netPrice.dataset.listPrice=listPrice;netPrice.value=listPrice;applyDiscount(netPrice,discount,netPrice);}
    brand.addEventListener('change',()=>{fillSerialOptions(serial,[]);netPrice.value='';delete netPrice.dataset.listPrice;setListPriceHint([brand,model,serial],null);sync();});model.addEventListener('change',()=>{const stocks=hearingDeviceStocks.filter(item=>item.brand===brand.value&&item.model===model.value),stock=stocks[0],historical=invoiceMatchedSerials(brand.value,model.value),listPrice=listPriceForStock(stock);fillSerialOptions(serial,[...stocks,...historical]);netPrice.dataset.listPrice=listPrice;netPrice.value=listPrice;applyDiscount(netPrice,discount,netPrice);setListPriceHint([brand,model,serial],stock);});discount.addEventListener('input',()=>applyDiscount(netPrice,discount,netPrice));
    addProductCancel(device,[`sales_device_${number}_brand`,`sales_device_${number}_model`,`sales_device_${number}_serial`,`sales_device_${number}_sgk`,`sales_device_${number}_discount_rate`,`sales_device_${number}_net_price`],()=>{device.remove();updateDeviceAddButton();updateTotalAmount();});updateDeviceAddButton();updateTotalAmount();
  };
  const addNextDevice=()=>{setProductType('İşitme Cihazı');if(deviceDetails?.hidden)showFirstDevice();else addExtraDevice(2);arrangeProductSections();};
  detailsModal?.addEventListener('click',event=>{if(!event.target.closest('#add-hearing-device'))return;event.preventDefault();event.stopImmediatePropagation();addNextDevice();},true);
  salesInvoiceInput?.addEventListener('input',()=>{const brand=detailsModal?.querySelector('[name="sales_device_2_brand"]'),model=detailsModal?.querySelector('[name="sales_device_2_model"]'),serial=detailsModal?.querySelector('[name="sales_device_2_serial"]');if(!brand||!model||!serial||!brand.value||!model.value)return;const stocks=hearingDeviceStocks.filter(item=>item.brand===brand.value&&item.model===model.value);fillSerialOptions(serial,[...stocks,...invoiceMatchedSerials(brand.value,model.value)]);});
  salesDateInput?.addEventListener('change',()=>{fillDeviceSerial();fillChargerSerial();syncConsumablePrice();const brand=detailsModal?.querySelector('[name="sales_device_2_brand"]'),model=detailsModal?.querySelector('[name="sales_device_2_model"]'),netPrice=detailsModal?.querySelector('[name="sales_device_2_net_price"]'),discount=detailsModal?.querySelector('[name="sales_device_2_discount_rate"]');if(!brand||!model||!netPrice)return;const stock=hearingDeviceStocks.find(item=>item.brand===brand.value&&item.model===model.value),listPrice=listPriceForStock(stock);netPrice.dataset.listPrice=listPrice;applyDiscount(netPrice,discount,netPrice);});
  try{const saved=JSON.parse(details?.value||'{}');if(saved.sales_device_2_brand||saved.sales_device_2_model||saved.sales_device_2_serial){addExtraDevice(2);const device=detailsModal?.querySelector('#hearing-device-details-2'),brand=device?.querySelector('[name="sales_device_2_brand"]'),model=device?.querySelector('[name="sales_device_2_model"]'),serial=device?.querySelector('[name="sales_device_2_serial"]');if(brand){brand.value=saved.sales_device_2_brand||'';brand.dispatchEvent(new Event('change'));}if(model){model.value=saved.sales_device_2_model||'';model.dispatchEvent(new Event('change'));}if(serial)serial.value=saved.sales_device_2_serial||'';}}catch(_){}
  setTimeout(()=>{try{const saved=JSON.parse(details?.value||'{}');for(let number=1;number<=2;number++){const isFirst=number===1,device=isFirst?deviceDetails:detailsModal?.querySelector('#hearing-device-details-2'),brand=isFirst?brandSelect:device?.querySelector('[name="sales_device_2_brand"]'),model=isFirst?modelSelect:device?.querySelector('[name="sales_device_2_model"]'),serial=isFirst?deviceSerialInput:device?.querySelector('[name="sales_device_2_serial"]'),sgk=isFirst?detailsModal?.querySelector('[name="sales_device_sgk"]'):device?.querySelector('[name="sales_device_2_sgk"]'),savedBrand=saved[isFirst?'sales_brand':'sales_device_2_brand']||'',savedModel=saved[isFirst?'sales_model':'sales_device_2_model']||'',savedSerial=saved[isFirst?'sales_device_serial':'sales_device_2_serial']||'';if(!device||!brand||!model||!serial||!savedBrand||!savedModel)continue;brand.value=savedBrand;if(![...model.options].some(option=>option.value===savedModel))model.add(new Option(savedModel,savedModel));model.disabled=false;model.value=savedModel;const stocks=hearingDeviceStocks.filter(item=>item.brand===savedBrand&&item.model===savedModel);fillSerialOptions(serial,[...stocks,...invoiceMatchedSerials(savedBrand,savedModel)]);if(savedSerial&&![...serial.options].some(option=>option.value===savedSerial))serial.add(new Option(savedSerial,savedSerial));serial.value=savedSerial;if(sgk)sgk.value=saved[isFirst?'sales_device_sgk':'sales_device_2_sgk']||sgk.value;}}catch(_){}},0);
  [80,250,600].forEach(delay=>setTimeout(()=>{try{const saved=JSON.parse(details?.value||'{}');[[deviceSerialInput,saved.sales_device_serial],[detailsModal?.querySelector('[name="sales_device_2_serial"]'),saved.sales_device_2_serial]].forEach(([serial,savedSerial])=>{if(!serial||!savedSerial)return;if(![...serial.options].some(option=>option.value===savedSerial))serial.add(new Option(savedSerial,savedSerial));serial.value=savedSerial;});}catch(_){}updateTotalAmount();},delay));
  if(!deviceDetails?.hidden)showFirstDevice();
  addDeviceButton?.addEventListener('click',()=>{setProductType('İşitme Cihazı');if(deviceDetails?.hidden)showFirstDevice();else addExtraDevice(2);arrangeProductSections();});
  if(!consumableDetails.hidden)showConsumableDetails();
  addConsumableButton.addEventListener('click',openConsumableModal);
  let chargerCancel=null;
  if(!chargerDetails.hidden)chargerCancel=addProductCancel(chargerDetails,['sales_charger_brand','sales_charger_model','sales_charger_price','sales_charger_serial','sales_charger_sgk','sales_charger_discount_rate','sales_charger_net_price'],()=>{toggleChargerDetails(false);detailsModal.dataset.chargerAdded='';if(detailsModal?.dataset.productType==='Şarj Cihazı')setProductType('');});
  addChargerButton.addEventListener('click',()=>{setProductType('Şarj Cihazı');toggleChargerDetails(true);detailsModal.dataset.chargerAdded='1';chargerCancel??=addProductCancel(chargerDetails,['sales_charger_brand','sales_charger_model','sales_charger_price','sales_charger_serial','sales_charger_sgk','sales_charger_discount_rate','sales_charger_net_price'],()=>{toggleChargerDetails(false);detailsModal.dataset.chargerAdded='';if(detailsModal?.dataset.productType==='Şarj Cihazı')setProductType('');});arrangeProductSections();});
  arrangeProductSections();
  refreshProductDeleteButtons();
  updateTotalAmount();
  applySalesLock(detailsModal?.dataset.salesLocked==='1');
  service.addEventListener('change',()=>{if(isSales()){close();openDetails();}else{value.value='';close();closeDetails()}});
  modal.querySelectorAll('[data-sales-close]').forEach(x=>x.addEventListener('click',close));items.forEach(item=>item.addEventListener('click',()=>{value.value=item.dataset.id||'';close();openDetails()}));search?.addEventListener('input',()=>{const q=search.value.trim().toLocaleLowerCase('tr-TR');items.forEach(item=>item.hidden=!!q&&!(item.dataset.search||'').includes(q))});detailsModal?.querySelectorAll('[data-sales-details-close]').forEach(x=>x.addEventListener('click',()=>{persistDetails();closeDetails()}));detailsModal?.querySelector('#sales-details-save')?.addEventListener('click',async event=>{event.preventDefault();if(service.value.trim()!=='Satış'){service.value='Satış';service.dispatchEvent(new Event('change',{bubbles:true}));}syncInvoice();let returnToSales=form.querySelector('[name="return_to_sales_details"]');if(!productWasRemoved){if(!returnToSales){returnToSales=document.createElement('input');returnToSales.type='hidden';returnToSales.name='return_to_sales_details';form.append(returnToSales);}returnToSales.value='1';}else{returnToSales?.remove();}const button=event.currentTarget;button.disabled=true;try{const response=await fetch(form.action||location.href,{method:'POST',body:new FormData(form),credentials:'same-origin'});if(!response.ok)throw new Error('Kayıt işlemi tamamlanamadı.');const responseUrl=new URL(response.url);const savedEditId=responseUrl.searchParams.get('edit');if(savedEditId){const editInput=form.querySelector('[name="edit_id"]');if(editInput)editInput.value=savedEditId;history.replaceState(null,'',responseUrl.pathname+'?'+responseUrl.searchParams.toString());}returnToSales?.remove();updateTotalAmount();if(productWasRemoved){closeDetails();const cleanUrl=new URL(location.href);cleanUrl.searchParams.delete('open_sales_details');history.replaceState(null,'',cleanUrl.pathname+'?'+cleanUrl.searchParams.toString());productWasRemoved=false;}alert('Kaydedildi');}catch(error){alert(error.message||'Kayıt işlemi tamamlanamadı.');}finally{button.disabled=false;}});form.addEventListener('submit',persistDetails);if(<?=json_encode($openSalesDetails)?>)setTimeout(openDetails,0);
};
if('requestIdleCallback' in window)window.requestIdleCallback(initializeSalesScreen,{timeout:300});else setTimeout(initializeSalesScreen,0);
</script>
<script>document.addEventListener('DOMContentLoaded',()=>{const serviceName=document.querySelector('#service-card-form [name="service_name"]');if(serviceName?.value.trim().toLocaleLowerCase('tr-TR')==='tamir')serviceName.dispatchEvent(new Event('change'));});</script>
<script>
document.addEventListener('DOMContentLoaded',()=>{const salesSave=document.getElementById('sales-details-save');if(!salesSave)return;salesSave.addEventListener('click',()=>{const nativeAlert=window.alert;let restored=false;window.alert=message=>{if(message==='Kayıt tamamlandı'){if(!restored){window.alert=nativeAlert;restored=true;}return;}return nativeAlert(message);};setTimeout(()=>{if(!restored){window.alert=nativeAlert;restored=true;}},15000);},true);});
</script>
<style>#sales-details-link[hidden]{display:none!important}#sales-lock-toggle{width:44px!important;min-width:44px!important;height:44px!important;min-height:44px!important;font-size:20px!important}</style>
<style>#sales-details-modal #sales_total_sgk,#sales-details-modal [name="sales_payment_amount"]{color:#e0444c!important;font-weight:700!important}</style>
<script>
(()=>{const setup=()=>{const modal=document.getElementById('sales-details-modal');if(!modal)return;const sync=()=>{if(modal.dataset.salesLocked!=='1')return;modal.querySelectorAll('.repair-body select,.repair-body input,.repair-body textarea').forEach(field=>{field.disabled=true;field.readOnly=true;field.setAttribute('aria-disabled','true');});};new MutationObserver(sync).observe(modal,{childList:true,subtree:true,attributes:true,attributeFilter:['data-sales-locked']});[0,80,250,600,1100].forEach(delay=>setTimeout(sync,delay));modal.addEventListener('pointerdown',event=>{if(modal.dataset.salesLocked==='1'&&event.target.closest('.repair-body select,input,textarea'))event.preventDefault();},true);};if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',setup);else setup();})();
</script>
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
<script>
(() => {
  const cleanCurrentAccountLabels = () => {
    document.querySelectorAll('select[name="current_account_id"] option').forEach(option => {
      const label = option.text.replace(/^\s*[^—]+—\s*/, '');
      if (label !== option.text) option.text = label;
    });
  };
  cleanCurrentAccountLabels();
  new MutationObserver(cleanCurrentAccountLabels).observe(document.body, {childList:true, subtree:true});
})();
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
<style>#vox-alert-message{text-align:center}</style>
<?php if ($fromSgkList): ?>
<style>#sales-details-modal input[name$="_sgk"]{border:2px solid #e04f55!important;box-shadow:0 0 0 2px rgba(224,79,85,.12)}</style>
<?php endif; ?>
<style>#vox-alert{position:fixed;inset:0;z-index:5000;display:grid;place-items:center;padding:24px;background:rgba(26,28,36,.62)}#vox-alert[hidden]{display:none}#vox-alert-panel{width:min(440px,calc(100vw - 48px));margin:auto;padding:28px;border-radius:12px;background:#fff;box-shadow:0 20px 50px rgba(0,0,0,.28);color:#2d2f43}#vox-alert-message{margin:0;white-space:pre-line;line-height:1.55}#vox-alert-actions{display:flex;justify-content:center;align-items:center;margin-top:24px}#vox-alert-actions button{min-width:96px;height:42px;border:0;border-radius:7px;background:#19a94b;color:#fff;font:inherit;font-weight:700;cursor:pointer}</style>
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
<script>
document.addEventListener('click',event=>{
  const cancel=event.target.closest('#sales-details-modal .repair-dialog>footer .repair-cancel[data-sales-details-close]');
  if(!cancel)return;
  event.preventDefault();
  event.stopImmediatePropagation();
  const modal=document.getElementById('sales-details-modal');
  if(!modal)return;
  const hasProduct=names=>names.some(name=>String(modal.querySelector(`[name="${name}"]`)?.value||'').trim()!=='');
  const resetEmptyProduct=(selector,names,remove=false)=>{
    if(hasProduct(names))return;
    const product=modal.querySelector(selector);
    if(!product)return;
    product.querySelectorAll('[name]').forEach(field=>field.value='');
    if(remove)product.remove();else product.hidden=true;
  };
  resetEmptyProduct('#hearing-device-details',['sales_brand','sales_model','sales_device_serial']);
  resetEmptyProduct('#hearing-device-details-2',['sales_device_2_brand','sales_device_2_model','sales_device_2_serial'],true);
  resetEmptyProduct('#charger-device-details',['sales_charger_brand','sales_charger_model','sales_charger_serial']);
  resetEmptyProduct('#consumable-details',['sales_consumable_stock_id','sales_consumable_quantity','sales_consumable_price']);
  if(!modal.querySelector('.sales-device-details:not([hidden]),#charger-device-details:not([hidden]),#consumable-details:not([hidden])'))modal.dataset.productType='';
  modal.hidden=true;
  modal.setAttribute('aria-hidden','true');
},true);
</script>
<script>
// Teknik servis tahsilatında, satış ekranının kredi kartı yerleşimi geçerli değildir.
// Ortak gelir penceresini seçilen tahsilat türüne göre son kez düzenler.
document.addEventListener('DOMContentLoaded', () => {
  const cashForm = document.querySelector('form[action*="cash.php"]');
  if (!cashForm) return;
  const refreshRepairPayment = () => {
    const type = cashForm.dataset.forcedPaymentType;
    if (!type) return;
    cashForm.dataset.repairPaymentLayout = type;
    const payment = cashForm.querySelector('[name="payment_type"]');
    if (payment) payment.value = type;
    const setLabel = (name, visible, column, row) => {
      const label = cashForm.querySelector(`[name="${name}"]`)?.closest('label');
      if (!label) return;
      label.hidden = !visible;
      label.style.setProperty('display', visible ? 'flex' : 'none', 'important');
      if (visible) {
        label.style.setProperty('grid-column', column, 'important');
        label.style.setProperty('grid-row', row, 'important');
      }
    };
    const setTitle = (name, title) => {
      const label = cashForm.querySelector(`[name="${name}"]`)?.closest('label');
      const text = [...(label?.childNodes || [])].find(node => node.nodeType === Node.TEXT_NODE);
      if (text) text.nodeValue = title;
    };
    setTitle('bank_name', 'Banka');
    setTitle('current_account_id', 'Cari Hesap');
    setTitle('installment_count', type === 'term' ? 'Vade Sayısı' : 'KK Taksit Sayısı');
    setTitle('commission_rate', type === 'term' ? 'Aylık Ödeme' : 'Komisyon Oranı');
    setTitle('amount', type === 'term' ? 'Toplam' : 'Tutar');
    setLabel('bank_name', type === 'mail_order' || type === 'credit_card', '1 / 2', '2');
    setLabel('current_account_id', type === 'mail_order', '2 / 3', '2');
    const accountField = cashForm.querySelector('[name="current_account_id"]');
    const accountLabel = accountField?.closest('label');
    if (accountField && accountLabel) {
      [...accountField.options].forEach(option => {
        option.text = option.text.replace(/^\s*[^—]+—\s*/, '');
      });
      accountLabel.style.setProperty('width', '100%', 'important');
      accountLabel.style.setProperty('min-width', '220px', 'important');
      accountField.style.setProperty('display', 'block', 'important');
      accountField.style.setProperty('width', '100%', 'important');
      accountField.style.setProperty('min-width', '220px', 'important');
    }
    setLabel('installment_count', type === 'credit_card' || type === 'term', type === 'term' ? '1 / 2' : '2 / 3', '2');
    setLabel('commission_rate', type === 'credit_card' || type === 'term', '2 / 3', '3');
    setLabel('amount', true, type === 'credit_card' ? '1 / 2' : '1 / -1', '3');
    setLabel('description', true, '1 / -1', '4');
    if (type === 'term') {
      const fee = document.querySelector('[name="repair_service_fee"]')?.value || '';
      const amountField = cashForm.querySelector('[name="amount"]');
      setLabel('amount', true, '2 / 3', '2');
      setTitle('amount', 'Toplam');
      if (fee.trim() !== '') {
        if (amountField) amountField.value = fee;
        const firstInstallment = cashForm.querySelector('[data-primary-term-amount]');
        if (firstInstallment) firstInstallment.value = fee;
      }
    }
  };
  cashForm.addEventListener('repair-payment-change', () => [0, 80, 250, 500, 800].forEach(delay => setTimeout(refreshRepairPayment, delay)));
});
</script>
<style>
form[action*="cash.php"] section label:has([name="current_account_id"]){grid-column:2/3!important;grid-row:2!important;width:100%!important;min-width:0!important}
form[action*="cash.php"] [name="current_account_id"]{display:block!important;width:100%!important;min-width:0!important;height:40px!important;min-height:40px!important;padding:8px 10px!important;box-sizing:border-box!important;visibility:visible!important;opacity:1!important}
form[data-repair-payment-layout="mail_order"] section{grid-template-columns:minmax(0,1fr) minmax(280px,1fr)!important}
</style>
<?php if ($showForm): ?>
<script>
(() => {
  const serviceForm = document.getElementById('service-card-form');
  const complaint = serviceForm?.querySelector('[name="complaint"]');
  const anamnesisIcon = complaint?.closest('.service-input-with-icon')?.querySelector('.service-input-icon');
  if (!serviceForm || !complaint || !anamnesisIcon) return;

  let saved = {};
  try { saved = JSON.parse(<?=json_encode((string)$form['anamnesis_form'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?> || '{}') || {}; } catch (_) {}
  let fields = [
    ['complaint','Şikayetiniz nedir?','text'], ['duration','Kaç yıldır şikayetiniz var?','text'],
    ['profession','Mesleğinizi öğrenebilir miyiz?','text'], ['noise','Gürültülü ortamlarda çalıştınız mı?','yesno'],
    ['loud_noise','Yüksek ses eşiği maruz kalır mısınız?','yesno'], ['childhood','Çocukken ateşli hastalık geçirdiniz mi?','yesno'],
    ['family','Ailede işitme kaybı olan birisi var mı?','yesno'], ['ear_operation','Daha önce kulak ameliyatı oldunuz mu?','yesno'],
    ['chronic_ear','Teşhisi konmuş kronik bir kulak hastalığınız var mı?','yesno'], ['chronic_other','Kronik başka bir hastalığınız var mı?','yesno'],
    ['tremor','Ellerinizde titremesi veya görme bozukluğunuz var mı?','yesno'], ['daily_help','Günlük işlerinizde size yardımcı olan birisi var mı?','yesno'],
    ['doctor_referral','İşitme cihazı için danıştınız mı?','yesno'], ['previous_device','Daha önce işitme cihazı kullandınız mı?','yesno'],
    ['device_user_nearby','Çevrenizde işitme cihazı kullanan birisi var mı?','yesno'], ['device_prejudice','İşitme cihazı ile ilgili ön yargılarınız veya endişeleriniz var mı?','yesno'],
    ['city','Memleketiniz ve yaşadığınız şehir','text'], ['otoscopic','ODY otoskopik inceleme sonucu','area'], ['advice','ODY görüş ve tavsiyesi','area']
  ];
  const editableQuestionLabels = <?=json_encode($anamnesisQuestions, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const fixedFields = fields.filter(([, , type]) => type !== 'yesno');
  fields.splice(0, fields.length,
    fixedFields[0], fixedFields[1], fixedFields[2],
    ...editableQuestionLabels.map(question => ['question_' + question.id, question.name, 'choice', question.detail_label || '', question.answer_options || 'yes_no']),
    ...fixedFields.slice(3)
  );
  const editableTextFields = <?=json_encode($anamnesisTextFields, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  fields = [
    ...editableTextFields.filter(field => Number(field.sort_order) < 50).map(field => [field.field_key, field.name, field.field_type]),
    ...editableQuestionLabels.map(question => ['question_' + question.id, question.name, 'choice', question.detail_label || '', question.answer_options || 'yes_no']),
    ...editableTextFields.filter(field => Number(field.sort_order) >= 50).map(field => [field.field_key, field.name, field.field_type])
  ];
  const modal = document.createElement('div');
  modal.id = 'anamnesis-card-modal'; modal.hidden = true;
  modal.innerHTML = `<div class="anamnesis-backdrop"></div><section class="anamnesis-dialog" role="dialog" aria-modal="true" aria-labelledby="anamnesis-card-title"><header><h2 id="anamnesis-card-title">VOX İ.M. - HASTA KARTI</h2><button type="button" aria-label="Kapat">×</button></header><div class="anamnesis-meta"><strong>${<?=json_encode($patient['full_name'], JSON_UNESCAPED_UNICODE)?>}</strong><span>Tarih: ${new Date().toLocaleDateString('tr-TR')}</span></div><div class="anamnesis-grid"></div><div class="anamnesis-company-logo" hidden><img alt="Şirket logosu"></div><footer><button type="button" class="anamnesis-cancel">İptal</button><button type="button" class="anamnesis-print" title="Yazdır" aria-label="Yazdır"><i class="ti tabler-printer"></i></button><button type="button" class="button anamnesis-apply" title="Anketi kaydet" aria-label="Kaydet"><i class="ti tabler-device-floppy"></i></button></footer></section>`;
  document.body.append(modal);
  modal.querySelectorAll('.anamnesis-meta > strong, .anamnesis-meta > span').forEach(item => {
    item.style.setProperty('font-size', 'calc(1em + 2px)', 'important');
  });
  const printSettings = <?=json_encode($anamnesisPrintSettings, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const visualPrintProject = String(printSettings.grapesjs_project || '');
  modal.querySelector('#anamnesis-card-title').textContent = printSettings.title || 'VOX İ.M. - HASTA KARTI';
  modal.style.setProperty('--anamnesis-header-color', printSettings.header_color || '#14843c');
  modal.style.setProperty('--anamnesis-font-size', String(printSettings.font_size || 11) + 'px');
  modal.style.setProperty('--anamnesis-question-font-size', String(printSettings.question_font_size || 11) + 'px');
  modal.style.setProperty('--anamnesis-page-margin', String(printSettings.page_margin || 20) + 'mm');
  modal.style.setProperty('--anamnesis-question-width', String(printSettings.question_width || 46) + '%');
  modal.style.setProperty('--anamnesis-yes-width', String(printSettings.yes_width || 12) + '%');
  modal.style.setProperty('--anamnesis-detail-width', String(printSettings.detail_width || 20) + '%');
  modal.style.setProperty('--anamnesis-row-height', String(printSettings.row_height || 27) + 'px');
  modal.style.setProperty('--anamnesis-notes-height', String(printSettings.notes_height || 37) + 'px');
  modal.style.setProperty('--anamnesis-line-width', String(printSettings.line_width || 1) + 'px');
  modal.style.setProperty('--anamnesis-company-logo-width', String(printSettings.company_logo_width || 28) + 'mm');
  const companyLogo = modal.querySelector('.anamnesis-company-logo');
  const companyLogoImage = companyLogo.querySelector('img');
  if (String(printSettings.company_logo_enabled) === '1' && String(printSettings.company_logo_path || '').trim() !== '') {
    companyLogoImage.src = <?=json_encode(rtrim(url(''), '/'), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?> + '/' + String(printSettings.company_logo_path).replace(/^\/+/, '');
    companyLogo.hidden = false;
  }
  let printLayout = {};
  try { printLayout = JSON.parse(printSettings.layout || '{}') || {}; } catch (_) {}
  ['header', 'meta', 'questions'].forEach(key => {
    const position = printLayout[key] || {};
    modal.style.setProperty('--anamnesis-' + key + '-x', String(position.x ?? 0) + '%');
    modal.style.setProperty('--anamnesis-' + key + '-y', String(position.y ?? 0) + '%');
  });
  const grid = modal.querySelector('.anamnesis-grid');
  const designerSourceMap = {};
  editableQuestionLabels.forEach(question => {
    const key = 'question_' + question.id;
    designerSourceMap['question-' + question.id] = {key, label: question.name, type: 'choice', answerOptions: question.answer_options || 'yes_no'};
    if (String(question.detail_label || '').trim()) designerSourceMap['question-' + question.id + '-detail'] = {key: key + '_detail', label: question.detail_label, type: 'detail-label', detail: true};
  });
  editableTextFields.forEach(field => {
    designerSourceMap['field-' + field.id] = {key: field.field_key, label: field.name, type: field.field_type || 'text'};
  });
  const normalizeDesignerText = value => String(value || '').toLocaleUpperCase('tr-TR').replace(/[^\p{L}\p{N}]+/gu, '');
  const isVarYok = answerOptions => String(answerOptions || '').trim().toLowerCase() === 'var_yok';
  const legacyDesignerSource = block => {
    const copy = block.cloneNode(true);
    copy.querySelectorAll('i,input,textarea,select').forEach(node => node.remove());
    const text = normalizeDesignerText(copy.textContent);
    return Object.values(designerSourceMap).find(item => normalizeDesignerText(item.label) === text) || null;
  };
  const buildField = (descriptor) => {
    const field = descriptor.type === 'area' ? document.createElement('textarea') : document.createElement('input');
    if (field.tagName === 'INPUT') field.type = 'text';
    field.name = descriptor.key;
    field.value = saved[descriptor.key] || '';
    if (descriptor.key === 'complaint') field.maxLength = 512;
    if (descriptor.key === 'duration') field.maxLength = 2;
    if (descriptor.detail) field.dataset.anamnesisDetail = '1';
    return field;
  };
  const renderDesignerLayout = () => {
    let layout = String(printSettings.designer_layout || '');
    if (!layout) try { layout = localStorage.getItem('vox-anamnesis-layout') || ''; } catch (_) {}
    if (!layout) return false;
    const source = document.createElement('div');
    source.innerHTML = layout;
    const blocks = [...source.querySelectorAll('.design-block')];
    if (!blocks.length) return false;
    const sheet = document.createElement('div');
    sheet.className = 'anamnesis-designer-sheet';
    sheet.innerHTML = source.innerHTML;
    let previousQuestion = null;
    let pendingEntry = null;
    let answerIndex = 0;
    [...sheet.querySelectorAll('.design-block')]
      .sort((a, b) => (parseFloat(a.style.top) || 0) - (parseFloat(b.style.top) || 0) || (parseFloat(a.style.left) || 0) - (parseFloat(b.style.left) || 0))
      .forEach(block => {
        block.classList.remove('selected');
        block.querySelectorAll('i').forEach(handle => handle.remove());
        const sourceKey = block.dataset.sourceKey || '';
        const descriptor = designerSourceMap[sourceKey] || legacyDesignerSource(block);
        const type = block.dataset.type || '';
        if (type === 'title') {
          block.textContent = printSettings.title || block.textContent.trim() || 'VOX İ.M. - HASTA KARTI';
          return;
        }
        if (type === 'patient') {
          block.textContent = '';
          const name = document.createElement('strong'); name.textContent = <?=json_encode($patient['full_name'], JSON_UNESCAPED_UNICODE)?>;
          const date = document.createElement('span'); date.textContent = 'Tarih: ' + new Date().toLocaleDateString('tr-TR');
          name.style.setProperty('font-size', 'calc(1em + 2px)', 'important');
          date.style.setProperty('font-size', 'calc(1em + 2px)', 'important');
          block.append(name, date);
          return;
        }
        if (type === 'logo') {
          block.textContent = '';
          if (!companyLogo.hidden && companyLogoImage.src) {
            const image = companyLogoImage.cloneNode(true); image.alt = 'Şirket logosu'; block.append(image);
          } else block.textContent = 'Şirket Logosu';
          return;
        }
        if (descriptor) {
          block.textContent = '';
          if (descriptor.type === 'choice') {
            block.classList.add('designer-question-label');
            block.textContent = descriptor.label;
            previousQuestion = descriptor;
          } else if (descriptor.type === 'area') {
            // Not alanında başlık sabit kalır; veri, aynı bloğun altındaki textarea'ya yazılır.
            block.classList.add('designer-area-field');
            block.style.setProperty('display', 'flex', 'important');
            block.style.setProperty('flex-direction', 'column', 'important');
            const title = document.createElement('span');
            title.className = 'designer-field-label'; title.textContent = descriptor.label;
            title.style.setProperty('flex', '0 0 auto', 'important');
            title.style.setProperty('float', 'none', 'important');
            const field = buildField(descriptor);
            field.setAttribute('aria-label', descriptor.label);
            field.style.setProperty('display', 'block', 'important');
            field.style.setProperty('flex', '1 1 auto', 'important');
            field.style.setProperty('width', '100%', 'important');
            field.style.setProperty('min-height', '0', 'important');
            field.style.setProperty('margin-top', '4px', 'important');
            field.style.setProperty('border', '0', 'important');
            field.style.setProperty('resize', 'none', 'important');
            block.append(title, field);
            pendingEntry = null;
          } else if (descriptor.type === 'detail-label') {
            // Açıklama alanı, tasarımda bir başlıktır; veri giriş kutusu değildir.
            block.classList.add('designer-detail-label');
            block.textContent = descriptor.label;
            pendingEntry = descriptor;
          } else {
            // Kaynak alan önce başlıktır; onu takip eden "Metin Alanı" veri girişidir.
            block.classList.add('designer-field-label');
            block.textContent = descriptor.label;
            pendingEntry = descriptor;
          }
          return;
        }
        if (type === 'text' && pendingEntry) {
          block.classList.add('designer-entry-field');
          block.textContent = '';
          const field = buildField(pendingEntry);
          field.setAttribute('aria-label', pendingEntry.label);
          block.append(field);
          pendingEntry = null;
          return;
        }
        if (type === 'answer') {
          // Eski şablonlarda soru bloğunun kaynak anahtarı bulunmayabilir.
          // Bu durumda cevap kutusunu soru sırasına göre doğru seçenekle eşleştir.
          const answerQuestion = previousQuestion || editableQuestionLabels[answerIndex];
          answerIndex += 1;
          if (!answerQuestion) { previousQuestion = null; return; }
          const originalAnswerText = block.textContent;
          block.classList.add('designer-answer-field');
          block.textContent = '';
          const answerKey = answerQuestion.key || ('question_' + answerQuestion.id);
          const answerOptions = answerQuestion.answerOptions || answerQuestion.answer_options || 'yes_no';
          const templateUsesVar = normalizeDesignerText(originalAnswerText).includes('VAR');
          const effectiveAnswerOptions = templateUsesVar ? 'var_yok' : answerOptions;
          const positive = isVarYok(effectiveAnswerOptions) ? 'Var' : 'Evet';
          const input = document.createElement('input'); input.type = 'checkbox'; input.name = answerKey;
          input.dataset.answerOptions = effectiveAnswerOptions;
          input.checked = saved[answerKey] === true || saved[answerKey] === positive;
          const answerText = document.createElement('span'); answerText.textContent = positive;
          block.style.setProperty('display', 'flex', 'important');
          block.style.setProperty('align-items', 'center', 'important');
          block.style.setProperty('justify-content', 'center', 'important');
          block.style.setProperty('gap', '4px', 'important');
          block.style.setProperty('white-space', 'nowrap', 'important');
          input.style.setProperty('display', 'block', 'important');
          input.style.setProperty('flex', '0 0 15px', 'important');
          input.style.setProperty('width', '15px', 'important');
          input.style.setProperty('height', '15px', 'important');
          input.style.setProperty('margin', '0', 'important');
          answerText.style.setProperty('display', 'inline-block', 'important');
          answerText.style.setProperty('white-space', 'nowrap', 'important');
          block.append(input, answerText);
          return;
        }
        previousQuestion = null;
      });
    grid.replaceChildren(sheet);
    grid.classList.add('designer-active');
    modal.classList.add('designer-layout-active');
    // Tasarımcıdaki logo bloğu kullanılır; eski sabit logo alanı ikinci kez görünmesin.
    companyLogo.hidden = true;
    return true;
  };
  const usingDesignerLayout = renderDesignerLayout();
  if (!usingDesignerLayout) fields.forEach(([key, label, type, detailLabel = '', answerOptions = 'yes_no']) => {
    const row = document.createElement('label'); row.className = 'anamnesis-row';
    if (key === 'complaint' || key === 'profession') row.classList.add('anamnesis-wide');
    if (type !== 'choice') row.classList.add('anamnesis-free-text');
    if (type === 'area') row.classList.add('anamnesis-note-field');
    const caption = document.createElement('span'); caption.textContent = label;
    let control;
    if (type === 'choice') {
      const positive = isVarYok(answerOptions) ? 'Var' : 'Evet';
      const choice = document.createElement('span'); choice.className = 'anamnesis-yes';
      choice.style.setProperty('display', 'flex', 'important');
      choice.style.setProperty('align-items', 'center', 'important');
      choice.style.setProperty('justify-content', 'center', 'important');
      choice.style.setProperty('gap', '4px', 'important');
      choice.style.setProperty('flex-wrap', 'nowrap', 'important');
      control = document.createElement('input'); control.type = 'checkbox';
      control.dataset.answerOptions = answerOptions;
      control.checked = saved[key] === true || saved[key] === positive;
      const choiceText = document.createElement('span');
      choiceText.className = 'anamnesis-yes-label'; choiceText.textContent = positive;
      choiceText.style.setProperty('display', 'inline-block', 'important');
      choiceText.style.setProperty('white-space', 'nowrap', 'important');
      control.style.setProperty('display', 'block', 'important');
      control.style.setProperty('flex', '0 0 15px', 'important');
      control.style.setProperty('width', '15px', 'important');
      control.style.setProperty('height', '15px', 'important');
      control.style.setProperty('min-height', '15px', 'important');
      control.style.setProperty('margin', '0', 'important');
      choice.append(control, choiceText); control = choice;
    }
    else if (type === 'area') control = document.createElement('textarea');
    else { control = document.createElement('input'); control.type = 'text'; }
    const field = control.matches?.('input,textarea,select') ? control : control.querySelector('input');
    field.name = key;
    if (field.type !== 'checkbox' && type !== 'choice') field.value = saved[key] || '';
    if (key === 'complaint') field.maxLength = 512;
    if (key === 'duration') field.maxLength = 2;
    row.append(caption, control);
    if (type === 'choice' && detailLabel) {
      const detailCaption = document.createElement('span');
      detailCaption.className = 'anamnesis-detail-caption'; detailCaption.textContent = detailLabel;
      const detail = document.createElement('input');
      detail.type = 'text'; detail.name = key + '_detail'; detail.maxLength = 190; detail.placeholder = '';
      detail.value = saved[detail.name] || ''; detail.dataset.anamnesisDetail = '1';
      row.classList.add('anamnesis-with-detail'); row.append(detailCaption, detail);
    }
    grid.append(row);
  });
  const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'anamnesis_form'; hidden.value = JSON.stringify(saved);
  serviceForm.append(hidden);
  const fitToA4 = () => {
    const dialog = modal.querySelector('.anamnesis-dialog'), header = dialog.querySelector('header'), meta = dialog.querySelector('.anamnesis-meta'), footer = dialog.querySelector('footer');
    grid.style.zoom = '1';
    const available = dialog.clientHeight - header.offsetHeight - meta.offsetHeight - footer.offsetHeight;
    if (grid.scrollHeight > available && available > 0) grid.style.zoom = String(Math.max(.68, available / grid.scrollHeight));
  };
  const close = () => { modal.hidden = true; };
  const collect = () => Object.fromEntries(fields.map(([key, , type, , answerOptions = 'yes_no']) => {
    const field = modal.querySelector(`[name="${key}"]`);
    if (field?.type === 'checkbox') {
      const effectiveAnswerOptions = field.dataset.answerOptions || answerOptions;
      return [key, field.checked ? (isVarYok(effectiveAnswerOptions) ? 'Var' : 'Evet') : (isVarYok(effectiveAnswerOptions) ? 'Yok' : 'Hayır')];
    }
    return [key, field?.value.trim() || ''];
  }));
  const baseCollect = collect;
  const collectWithDetails = () => {
    const values = baseCollect();
    modal.querySelectorAll('[data-anamnesis-detail]').forEach(field => { values[field.name] = field.value.trim(); });
    return values;
  };
  const escapePrintHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const openVisualPrint = values => {
    let project;
    try { project = JSON.parse(visualPrintProject); } catch (_) { return false; }
    if (!project || typeof project !== 'object') return false;
    const questionRows = fields.filter(([, , type]) => type === 'choice').map(([key, label, , detailLabel = '', answerOptions = 'yes_no']) => {
      const answer = values[key] || (isVarYok(answerOptions) ? 'Yok' : 'Hayır');
      return '<tr><td>'+escapePrintHtml(label)+'</td><td style="width:9%;text-align:center">'+escapePrintHtml(answer)+'</td><td style="width:20%">'+escapePrintHtml(detailLabel)+'</td><td>'+escapePrintHtml(values[key + '_detail'] || '')+'</td></tr>';
    }).join('');
    const payload = {
      project,
      patientName: <?=json_encode($patient['full_name'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,
      date: new Date().toLocaleDateString('tr-TR'),
      questionsHtml: '<table class="question-table">'+questionRows+'</table>',
      companyLogo: String(printSettings.company_logo_enabled) === '1' && String(printSettings.company_logo_path || '').trim() !== '' ? <?=json_encode(rtrim(url(''), '/'), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?> + '/' + String(printSettings.company_logo_path).replace(/^\/+/, '') : ''
    };
    const popup = window.open('', '_blank'); if (!popup) { alert('Yazdırma penceresi açılamadı. Tarayıcı açılır pencere izni verin.'); return true; }
    const encoded = JSON.stringify(payload).replace(/</g, '\\u003c');
    popup.document.write('<!doctype html><html><head><meta charset="utf-8"><script src="<?=url('assets/vendor/grapesjs/grapes.min.js')?>"><\\/script><script src="<?=url('assets/vendor/pagedjs/paged.polyfill.js')?>"><\\/script><style>@page{size:A4 portrait;margin:10mm}body{font-family:Arial,sans-serif;color:#182438}.question-table{width:100%;border-collapse:collapse}.question-table td{border:1px solid #222;padding:6px;font-size:11px}.a4-sheet{box-sizing:border-box;width:100%}</style></head><body><div id="render"></div><script>window.addEventListener("load",function(){const data='+encoded+';const editor=grapesjs.init({container:"#render",height:"auto",storageManager:false,fromElement:false,panels:{defaults:[]}});editor.loadProjectData(data.project);setTimeout(function(){let html=editor.getHtml(),css=editor.getCss();html=html.replaceAll("{{patient_name}}",data.patientName).replaceAll("{{date}}",data.date).replaceAll("{{anamnesis_questions}}",data.questionsHtml).replaceAll("{{company_logo}}",data.companyLogo);document.head.insertAdjacentHTML("beforeend","<style>"+css+"<\\/style>");document.getElementById("render").innerHTML=html;const print=function(){window.print()};window.PagedPolyfill?window.PagedPolyfill.preview().then(print).catch(print):print()},350)})<\\/script></body></html>');
    popup.document.close(); return true;
  };
  anamnesisIcon.title = 'Anamnez hasta kartını açmak için çift tıklayın';
  anamnesisIcon.addEventListener('dblclick', event => { event.preventDefault(); modal.hidden = false; requestAnimationFrame(fitToA4); });
  modal.querySelectorAll('header button,.anamnesis-cancel,.anamnesis-backdrop').forEach(button => button.addEventListener('click', close));
  modal.querySelector('.anamnesis-apply').addEventListener('click', async event => {
    const button = event.currentTarget;
    hidden.value = JSON.stringify(collectWithDetails());
    const data = new FormData();
    data.set('csrf', serviceForm.querySelector('[name="csrf"]').value);
    data.set('action', 'save_anamnesis');
    data.set('edit_id', serviceForm.querySelector('[name="edit_id"]').value);
    data.set('anamnesis_form', hidden.value);
    button.disabled = true;
    try {
      const response = await fetch(location.href, {method:'POST', body:data, headers:{Accept:'application/json'}});
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Kayıt tamamlanamadı.');
      modal.hidden = true;
    } catch (error) { alert(error.message || 'Anamnez kaydedilemedi.'); }
    finally { button.disabled = false; }
  });
  modal.querySelector('.anamnesis-print').addEventListener('click', () => {
    hidden.value = JSON.stringify(collectWithDetails());
    if (visualPrintProject && openVisualPrint(collectWithDetails())) return;
    const restores = [];
    modal.querySelectorAll('.anamnesis-yes,.designer-answer-field').forEach(answer => {
      const checkbox = answer.querySelector('input[type="checkbox"]');
      if (!checkbox) return;
      const answerOptions = checkbox.dataset.answerOptions || 'yes_no';
      const selectedText = isVarYok(answerOptions) ? 'Var' : 'Evet';
      const emptyText = isVarYok(answerOptions) ? 'Yok' : 'Hayır';
      restores.push([answer, [...answer.childNodes]]);
      answer.replaceChildren(document.createTextNode(checkbox.checked ? selectedText : emptyText));
    });
    modal.querySelectorAll('.anamnesis-free-text').forEach(row => {
      const caption = row.querySelector('span'), field = row.querySelector('input,textarea');
      if (!caption || !field) return;
      restores.push([caption, caption.textContent]);
      caption.textContent = caption.textContent + (field.value.trim() ? ' — ' + field.value.trim() : '');
    });
    window.print();
    setTimeout(() => restores.forEach(([target, content]) => {
      if (Array.isArray(content)) target.replaceChildren(...content);
      else target.textContent = content;
    }), 500);
  });
})();
</script>
<style>
#anamnesis-card-modal[hidden]{display:none!important}#anamnesis-card-modal{position:fixed;inset:0;z-index:2000;display:grid;place-items:center;padding:20px}.anamnesis-backdrop{position:absolute;inset:0;background:rgba(28,30,40,.56)}.anamnesis-dialog{position:relative;width:min(900px,100%);max-height:calc(100vh - 40px);overflow:auto;background:#fff;border-radius:8px;color:#182438;box-shadow:0 18px 46px rgba(0,0,0,.28)}.anamnesis-dialog header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid #d9dde5}.anamnesis-dialog h2{margin:0;color:#14843c;font-size:21px}.anamnesis-dialog header button{border:0;background:transparent;font-size:28px;color:#777;cursor:pointer}.anamnesis-meta{display:flex;justify-content:space-between;padding:14px 24px;background:#f7faf8}.anamnesis-grid{padding:20px 24px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px}.anamnesis-row{display:grid;grid-template-columns:minmax(0,1fr) 125px;gap:8px;align-items:center;font-size:13px}.anamnesis-row span{line-height:1.25}.anamnesis-row input,.anamnesis-row select,.anamnesis-row textarea{box-sizing:border-box;width:100%;min-height:34px;border:1px solid #bfc6d2;border-radius:4px;padding:6px;font:inherit}.anamnesis-row textarea{height:60px;resize:vertical}.anamnesis-row:has(textarea),.anamnesis-row.anamnesis-wide{grid-column:1/-1;grid-template-columns:220px minmax(0,1fr)}.anamnesis-yes{display:inline-flex!important;align-items:center;gap:6px;font-size:14px}.anamnesis-row .anamnesis-yes input{width:16px!important;min-height:16px!important;height:16px!important;margin:0!important;padding:0!important;accent-color:#19a94b}.anamnesis-row:has(input[name="duration"]){grid-template-columns:minmax(0,1fr) 45px}.anamnesis-dialog footer{display:flex;justify-content:flex-end;gap:10px;padding:16px 24px;border-top:1px solid #d9dde5}.anamnesis-dialog footer button{border:0;border-radius:5px;padding:10px 15px;cursor:pointer}.anamnesis-print{display:inline-grid!important;place-items:center!important;width:42px!important;min-width:42px!important;height:42px!important;padding:0!important;background:#30435d;color:#fff}.anamnesis-print i{font-size:19px;line-height:1}.anamnesis-cancel{background:#e6525d;color:#fff}@media(max-width:680px){.anamnesis-grid{grid-template-columns:1fr}.anamnesis-row:has(textarea),.anamnesis-row.anamnesis-wide{grid-column:auto;grid-template-columns:1fr}.anamnesis-meta{gap:8px;flex-direction:column}}@media print{body>*{display:none!important}#anamnesis-card-modal{position:static!important;display:block!important;padding:0!important}#anamnesis-card-modal .anamnesis-backdrop,#anamnesis-card-modal header button,#anamnesis-card-modal footer{display:none!important}.anamnesis-dialog{width:100%!important;max-height:none!important;box-shadow:none!important;border-radius:0!important}.anamnesis-grid{gap:6px 10px!important;padding:12px!important}.anamnesis-row{font-size:11px!important}.anamnesis-row input,.anamnesis-row select,.anamnesis-row textarea{border:1px solid #222!important;border-radius:0!important}}
</style>
<?php endif; ?>
<style>
#anamnesis-card-modal .anamnesis-dialog h2{color:var(--anamnesis-header-color,#14843c)!important}
#anamnesis-card-modal .anamnesis-dialog{font-size:var(--anamnesis-font-size,11px)}
#anamnesis-card-modal .anamnesis-row{font-size:var(--anamnesis-question-font-size,11px)}
#anamnesis-card-modal .anamnesis-row.anamnesis-with-detail{grid-template-columns:minmax(0,1fr) 72px 150px}
#anamnesis-card-modal .anamnesis-row.anamnesis-with-detail>input{min-width:0}
#anamnesis-card-modal .anamnesis-dialog{width:min(900px,100%);border:1px solid #222;border-radius:0;font-family:Arial,sans-serif}
#anamnesis-card-modal .anamnesis-dialog>header{padding:10px;border-bottom:2px solid #222}
#anamnesis-card-modal .anamnesis-meta{padding:8px 10px;border-bottom:1px solid #222;background:#fff}
#anamnesis-card-modal .anamnesis-grid{display:block;padding:0}
#anamnesis-card-modal .anamnesis-row{display:grid;grid-template-columns:48% 12% 20% 20%;gap:0;min-height:34px;border-bottom:1px solid #222;align-items:stretch}
#anamnesis-card-modal .anamnesis-row>span{display:flex;align-items:center;padding:6px 8px;border-right:1px solid #222;font-size:11px;text-transform:uppercase}
#anamnesis-card-modal .anamnesis-row>.anamnesis-yes{grid-column:2;justify-content:center;text-transform:none}
#anamnesis-card-modal .anamnesis-row.anamnesis-with-detail>.anamnesis-detail-caption{grid-column:3;justify-content:center;text-align:center}
#anamnesis-card-modal .anamnesis-row.anamnesis-with-detail>input{grid-column:4;width:100%!important;min-height:0!important;border:0!important;border-radius:0!important;outline:0!important;box-shadow:none!important;background:transparent!important;padding:6px!important}
#anamnesis-card-modal .anamnesis-row:not(.anamnesis-with-detail)>input{grid-column:2/-1;width:100%!important;min-height:0!important;border:0!important;border-radius:0!important;padding:6px!important}
#anamnesis-card-modal .anamnesis-row.anamnesis-wide{grid-template-columns:48% 52%}
#anamnesis-card-modal .anamnesis-row.anamnesis-wide>span{grid-column:1}
#anamnesis-card-modal .anamnesis-row.anamnesis-wide>input{grid-column:2!important}
#anamnesis-card-modal .anamnesis-row:has(textarea){grid-template-columns:48% 52%;min-height:66px}
#anamnesis-card-modal .anamnesis-row:has(textarea)>span{grid-column:1}
#anamnesis-card-modal .anamnesis-row textarea{grid-column:2!important;width:100%!important;height:auto!important;min-height:65px!important;border:0!important;border-radius:0!important;padding:6px!important}
@media print{#anamnesis-card-modal .anamnesis-dialog>header,#anamnesis-card-modal .anamnesis-meta,#anamnesis-card-modal .anamnesis-grid{position:static!important;width:auto!important;margin:0!important}#anamnesis-card-modal .anamnesis-dialog{min-height:0!important;width:100%!important;border:1px solid #222!important}#anamnesis-card-modal .anamnesis-row{font-size:9px!important}#anamnesis-card-modal .anamnesis-row>span{font-size:9px!important}}
@media print{#anamnesis-card-modal .anamnesis-dialog{position:relative!important;min-height:297mm!important}#anamnesis-card-modal .anamnesis-dialog>header,#anamnesis-card-modal .anamnesis-meta,#anamnesis-card-modal .anamnesis-grid{position:absolute!important;width:84%!important;margin:0!important}#anamnesis-card-modal .anamnesis-dialog>header{left:var(--anamnesis-header-x,0)!important;top:var(--anamnesis-header-y,0)!important}#anamnesis-card-modal .anamnesis-meta{left:var(--anamnesis-meta-x,0)!important;top:var(--anamnesis-meta-y,0)!important}#anamnesis-card-modal .anamnesis-grid{left:var(--anamnesis-questions-x,0)!important;top:var(--anamnesis-questions-y,0)!important}}
/* Tablo tasarım panelinden gelen baskı ölçüleri. Eski serbest blok konumları baskıda kullanılmaz. */
#anamnesis-card-modal .anamnesis-dialog{border-width:var(--anamnesis-line-width,1px)!important}
#anamnesis-card-modal .anamnesis-dialog>header{border-bottom-width:calc(var(--anamnesis-line-width,1px) * 2)!important}
#anamnesis-card-modal .anamnesis-meta{border-bottom-width:var(--anamnesis-line-width,1px)!important}
#anamnesis-card-modal .anamnesis-row{grid-template-columns:var(--anamnesis-question-width,48%) var(--anamnesis-yes-width,12%) var(--anamnesis-detail-width,20%) minmax(0,1fr)!important;min-height:var(--anamnesis-row-height,34px)!important;border-bottom-width:var(--anamnesis-line-width,1px)!important}
#anamnesis-card-modal .anamnesis-row>span{border-right-width:var(--anamnesis-line-width,1px)!important}
#anamnesis-card-modal .anamnesis-row:has(textarea){min-height:var(--anamnesis-notes-height,65px)!important}
@media print{#anamnesis-card-modal{padding:var(--anamnesis-page-margin,20mm)!important;box-sizing:border-box!important}#anamnesis-card-modal .anamnesis-dialog{position:static!important;min-height:0!important;width:100%!important}#anamnesis-card-modal .anamnesis-dialog>header,#anamnesis-card-modal .anamnesis-meta,#anamnesis-card-modal .anamnesis-grid{position:static!important;width:auto!important;margin:0!important}}
@media print{#anamnesis-card-modal .anamnesis-row>.anamnesis-yes input{display:none!important}}
@media print{#anamnesis-card-modal .anamnesis-designer-sheet .designer-answer-field input[type="checkbox"]{display:none!important}}
#anamnesis-card-modal .anamnesis-row:has(input[name="complaint"]){grid-column:1/-1!important;width:100%!important;grid-template-columns:35% minmax(0,1fr)!important}
#anamnesis-card-modal .anamnesis-meta>strong,#anamnesis-card-modal .anamnesis-meta>span{font-size:calc(1em + 2px)!important}
/* Tablo düzeninde kutu ve cevap aynı satırda, kutu solda kalır. */
#anamnesis-card-modal .anamnesis-row>.anamnesis-yes{display:grid!important;grid-template-columns:15px max-content!important;place-content:center!important;align-items:center!important;column-gap:4px!important;white-space:nowrap!important;font-size:inherit!important}
#anamnesis-card-modal .anamnesis-row>.anamnesis-yes>input[type="checkbox"]{display:block!important;grid-column:1!important;grid-row:1!important;width:15px!important;height:15px!important;min-width:15px!important;min-height:15px!important;margin:0!important;padding:0!important}
#anamnesis-card-modal .anamnesis-row>.anamnesis-yes>.anamnesis-yes-label{display:block!important;grid-column:2!important;grid-row:1!important;padding:0!important;border:0!important;font-size:inherit!important;line-height:1!important}
#anamnesis-card-modal .anamnesis-row:has(textarea){min-height:36px!important}
#anamnesis-card-modal .anamnesis-row textarea{height:36px!important;min-height:36px!important;resize:none!important}
#anamnesis-card-modal .anamnesis-company-logo{display:flex;justify-content:center;align-items:center;padding:8px 0}
#anamnesis-card-modal .anamnesis-company-logo img{display:block;width:var(--anamnesis-company-logo-width,28mm);max-width:45%;height:auto;max-height:52px;object-fit:contain}
#anamnesis-card-modal .anamnesis-apply{display:inline-grid!important;place-items:center;min-width:42px;padding:10px!important}
#anamnesis-card-modal .anamnesis-apply i{font-size:18px;line-height:1}
#anamnesis-card-modal .anamnesis-dialog footer button{display:inline-grid!important;place-items:center!important;width:36px!important;min-width:36px!important;height:36px!important;min-height:36px!important;padding:0!important}
#anamnesis-card-modal .anamnesis-dialog footer button i{font-size:16px!important;line-height:1!important}
#anamnesis-card-modal.designer-layout-active .anamnesis-dialog>header{position:absolute!important;z-index:3;right:0;top:0;padding:3px 10px;border:0!important;background:transparent}
#anamnesis-card-modal.designer-layout-active .anamnesis-dialog>header h2,#anamnesis-card-modal.designer-layout-active .anamnesis-meta{display:none!important}
#anamnesis-card-modal.designer-layout-active .anamnesis-company-logo{display:none!important}
#anamnesis-card-modal .anamnesis-grid.designer-active{display:block!important;padding:0!important;overflow:auto!important;background:#eef1f4}
#anamnesis-card-modal .anamnesis-designer-sheet{position:relative;box-sizing:border-box;width:100%;aspect-ratio:210/297;margin:0 auto;background:#fff;color:#182438;overflow:hidden}
#anamnesis-card-modal .anamnesis-designer-sheet .design-block{position:absolute;box-sizing:border-box;display:block;border:1px solid #182438;background:#fff;color:#182438;padding:6px;font:11px Arial,sans-serif;overflow:hidden}
#anamnesis-card-modal .anamnesis-designer-sheet .design-block.title{font-size:22px;font-weight:700;color:#14843c}
#anamnesis-card-modal .anamnesis-designer-sheet .design-block span{float:right}
/* Hasta adı ve tarih, tasarımdaki blok yazısından iki punto daha büyük okunur. */
#anamnesis-card-modal .anamnesis-designer-sheet .design-block[data-type="patient"] strong,#anamnesis-card-modal .anamnesis-designer-sheet .design-block[data-type="patient"] span{font-size:calc(1em + 2px)}
#anamnesis-card-modal .anamnesis-designer-sheet .design-block.logo{display:flex;align-items:center;justify-content:center;border:0!important}
#anamnesis-card-modal .anamnesis-designer-sheet .design-block.logo img{width:52%;max-width:100%;max-height:100%;object-fit:contain}
#anamnesis-card-modal .anamnesis-designer-sheet .designer-entry-field{padding:0}
#anamnesis-card-modal .anamnesis-designer-sheet .designer-entry-field{display:block;position:absolute}
#anamnesis-card-modal .anamnesis-designer-sheet .designer-field-label,#anamnesis-card-modal .anamnesis-designer-sheet .designer-detail-label{font-weight:400!important}
#anamnesis-card-modal .anamnesis-designer-sheet .designer-entry-field input,#anamnesis-card-modal .anamnesis-designer-sheet .designer-entry-field textarea{display:block;width:100%;height:100%;min-height:0;border:0!important;border-radius:0!important;outline:0!important;box-shadow:none!important;box-sizing:border-box;padding:5px 6px;font:inherit;resize:none;background:transparent!important}
#anamnesis-card-modal .anamnesis-designer-sheet .designer-answer-field{display:flex;align-items:center;justify-content:center;gap:4px;white-space:nowrap;flex-wrap:nowrap;font-size:11px!important;font-weight:400!important}
#anamnesis-card-modal .anamnesis-designer-sheet .designer-answer-field input{display:block;flex:0 0 15px;width:15px!important;height:15px!important;min-width:15px!important;min-height:15px!important;margin:0!important;accent-color:#19a94b}
#anamnesis-card-modal .anamnesis-dialog{width:min(210mm,96vw,calc(70.7vh - 28px))!important;height:min(297mm,calc(100vh - 40px));max-height:calc(100vh - 40px)!important;display:flex;flex-direction:column;overflow:hidden}
#anamnesis-card-modal.designer-layout-active .anamnesis-dialog{height:calc(100vh - 40px)!important;max-height:calc(100vh - 40px)!important}
#anamnesis-card-modal .anamnesis-grid{flex:1 1 auto;overflow:hidden}
@page{size:A4 portrait;margin:0}
@media print{#anamnesis-card-modal .anamnesis-dialog{width:100%!important;height:auto!important;max-height:none!important;overflow:visible!important;display:block!important}#anamnesis-card-modal .anamnesis-grid{overflow:visible!important;zoom:1!important}}
@media print{#anamnesis-card-modal .anamnesis-row>.anamnesis-yes{white-space:nowrap!important}#anamnesis-card-modal .anamnesis-row.anamnesis-free-text{grid-template-columns:1fr!important;min-height:24px!important}#anamnesis-card-modal .anamnesis-row.anamnesis-free-text>span{grid-column:1!important;border-right:0!important}#anamnesis-card-modal .anamnesis-row.anamnesis-free-text>input,#anamnesis-card-modal .anamnesis-row.anamnesis-free-text>textarea{display:none!important}#anamnesis-card-modal .anamnesis-row.anamnesis-note-field{min-height:78px!important}#anamnesis-card-modal .anamnesis-row.anamnesis-note-field>span{align-items:flex-start!important;padding-top:8px!important}}
/* A4 baskıda kullanılmayan yüksekliği form satırlarına dağıt. */
@media print{#anamnesis-card-modal .anamnesis-dialog{height:auto!important;min-height:0!important;display:block!important;box-sizing:border-box!important;break-inside:auto!important;page-break-inside:auto!important}#anamnesis-card-modal .anamnesis-grid{display:block!important;height:auto!important;min-height:0!important;overflow:visible!important;break-inside:auto!important;page-break-inside:auto!important}#anamnesis-card-modal .anamnesis-row{display:grid!important;flex:none!important;border-bottom:var(--anamnesis-line-width,1px) solid #222!important;box-shadow:inset 0 calc(-1 * var(--anamnesis-line-width,1px)) 0 #222!important;break-inside:avoid!important;page-break-inside:avoid!important}#anamnesis-card-modal .anamnesis-row.anamnesis-free-text{min-height:24px!important}#anamnesis-card-modal .anamnesis-row.anamnesis-note-field{min-height:78px!important}#anamnesis-card-modal .anamnesis-row.anamnesis-note-field>span{padding-top:8px!important}#anamnesis-card-modal .anamnesis-company-logo{position:fixed!important;right:0!important;bottom:4mm!important;left:0!important;display:flex!important;justify-content:center!important;align-items:center!important}#anamnesis-card-modal .anamnesis-company-logo[hidden]{display:none!important}#anamnesis-card-modal .anamnesis-company-logo img{display:block!important;width:var(--anamnesis-company-logo-width,28mm)!important;height:auto!important;max-height:18mm!important;object-fit:contain!important}}
@media print{#anamnesis-card-modal.designer-layout-active{padding:0!important}#anamnesis-card-modal.designer-layout-active .anamnesis-dialog{width:210mm!important;height:297mm!important;min-height:297mm!important;border:0!important;overflow:hidden!important}#anamnesis-card-modal.designer-layout-active .anamnesis-grid.designer-active{display:block!important;overflow:hidden!important;background:#fff!important}#anamnesis-card-modal .anamnesis-designer-sheet{width:210mm!important;height:297mm!important;aspect-ratio:auto!important}.anamnesis-designer-sheet .design-block{font-size:10pt!important}.anamnesis-designer-sheet .design-block.title{font-size:20pt!important}}
</style>
<?php patient_footer(); ?>
