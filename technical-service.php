<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

function technical_service_legal_days_remaining(?string $serviceDeliveryDate): ?int
{
    $serviceDeliveryDate = trim((string)$serviceDeliveryDate);
    if ($serviceDeliveryDate === '') return null;
    try {
        $start = (new DateTimeImmutable($serviceDeliveryDate))->setTime(0, 0);
        $today = (new DateTimeImmutable('today'))->setTime(0, 0);
    } catch (Throwable) {
        return null;
    }
    $elapsedBusinessDays = 0;
    for ($day = $start; $day < $today; $day = $day->modify('+1 day')) {
        if ((int)$day->format('N') <= 5) $elapsedBusinessDays++;
    }
    return max(0, 21 - $elapsedBusinessDays);
}

function technical_service_legal_deadline(?string $serviceDeliveryDate): ?string
{
    $serviceDeliveryDate = trim((string)$serviceDeliveryDate);
    if ($serviceDeliveryDate === '') return null;
    try {
        $day = (new DateTimeImmutable($serviceDeliveryDate))->setTime(0, 0);
    } catch (Throwable) {
        return null;
    }
    $businessDay = 0;
    while ($businessDay < 21) {
        if ((int)$day->format('N') <= 5) $businessDay++;
        if ($businessDay < 21) $day = $day->modify('+1 day');
    }
    return $day->format('d.m.Y');
}

$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS patient_services (id INTEGER PRIMARY KEY AUTOINCREMENT, patient_id INTEGER NOT NULL, service_date TEXT NOT NULL, service_status TEXT NOT NULL, performed_action TEXT, action_date TEXT, opened_by TEXT, branch_name TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS patient_services (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, patient_id INT UNSIGNED NOT NULL, service_date DATE NOT NULL, service_status VARCHAR(80) NOT NULL, performed_action TEXT NULL, action_date DATE NULL, opened_by VARCHAR(190) NULL, branch_name VARCHAR(190) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
try {
    $columns = $sqlite ? array_column($pdo->query('PRAGMA table_info(patient_services)')->fetchAll(), 'name') : array_column($pdo->query('SHOW COLUMNS FROM patient_services')->fetchAll(), 'Field');
    foreach (['service_name VARCHAR(150) NULL', 'repair_details TEXT NULL'] as $definition) {
        $column = explode(' ', $definition, 2)[0];
        if (!in_array($column, $columns, true)) $pdo->exec('ALTER TABLE patient_services ADD COLUMN ' . $definition);
    }
} catch (Throwable $exception) { error_log('technical-service schema: ' . $exception->getMessage()); }
$repairRecordNumbers = $pdo->query("SELECT id, record_no FROM patient_services WHERE service_name='Tamir' AND (record_no LIKE 'VX-%' OR record_no LIKE 'VK-%') ORDER BY id")->fetchAll();
$lastRepairRecordNumber = 1452;
foreach ($repairRecordNumbers as $repairRecord) {
    if (str_starts_with((string)$repairRecord['record_no'], 'VX-')) $lastRepairRecordNumber = max($lastRepairRecordNumber, (int)substr((string)$repairRecord['record_no'], 3));
}
$renameRepairRecord = $pdo->prepare('UPDATE patient_services SET record_no=? WHERE id=?');
foreach ($repairRecordNumbers as $repairRecord) {
    if (!str_starts_with((string)$repairRecord['record_no'], 'VK-')) continue;
    $renameRepairRecord->execute(['VX-' . ++$lastRepairRecordNumber, (int)$repairRecord['id']]);
}
if (isset($_GET['external_patient'])) {
    $externalName = trim((string)($_GET['external_name'] ?? '')) ?: 'Kayıtsız Hasta';
    $nextOrder = (int)$pdo->query('SELECT COALESCE(MAX(import_order),0)+1 FROM patients')->fetchColumn();
    $insert = $pdo->prepare('INSERT INTO patients(import_order,record_date,full_name,patient_rating,patient_status,report_status) VALUES(?,?,?,?,?,?)');
    $insert->execute([$nextOrder, date('Y-m-d'), $externalName, 0, 'active', 'Rapor gerekmedi']);
    redirect('patient-followup.php?id=' . (int)$pdo->lastInsertId() . '&new=1&service_name=Tamir');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $serviceId = (int)($_POST['service_id'] ?? 0);
    if ($serviceId) {
        $serviceStatement = $pdo->prepare("SELECT id,patient_id FROM patient_services WHERE id=? AND service_name='Tamir'");
        $serviceStatement->execute([$serviceId]);
        $service = $serviceStatement->fetch();
        if ($service) {
            $paymentStatement = $pdo->prepare("SELECT 1 FROM cash_transactions WHERE source_url=? AND transaction_type='income' LIMIT 1");
            $paymentStatement->execute([url('patient-followup.php?id=' . (int)$service['patient_id']) . '&repair=' . $serviceId]);
            if ($paymentStatement->fetchColumn()) redirect('technical-service.php?delete_error=repair_payment');
            $pdo->prepare('DELETE FROM patient_services WHERE id=? AND patient_id=?')->execute([$serviceId, (int)$service['patient_id']]);
        }
    }
    redirect('technical-service.php');
}
$services=$pdo->query("SELECT s.*,p.full_name FROM patient_services s JOIN patients p ON p.id=s.patient_id WHERE s.service_name='Tamir' AND COALESCE(s.repair_details,'')<>'' ORDER BY s.service_date DESC,s.id DESC")->fetchAll();
$repairDeviceData = [];
$latestSaleStatement = $pdo->prepare("SELECT sales_details FROM patient_services WHERE patient_id=? AND COALESCE(sales_details,'')<>'' ORDER BY service_date DESC,id DESC LIMIT 1");
foreach ($services as $service) {
    $repair = json_decode((string)($service['repair_details'] ?? ''), true);
    if (!is_array($repair)) $repair = [];
    $device = trim((string)($repair['repair_patient_device_model'] ?? $repair['repair_device'] ?? ''));
    $quantity = trim((string)($repair['repair_patient_device_quantity'] ?? ''));
    $latestSaleStatement->execute([(int)$service['patient_id']]);
    $sale = json_decode((string)$latestSaleStatement->fetchColumn(), true);
    if (!is_array($sale)) $sale = [];
    if ($device === '') $device = trim((string)($sale['sales_brand'] ?? '') . ' ' . (string)($sale['sales_model'] ?? ''));
    if ($quantity === '' && trim((string)($sale['sales_model'] ?? '')) !== '') {
        $quantity = '1';
        for ($number = 2; $number <= 4; $number++) if (trim((string)($sale["sales_device_{$number}_model"] ?? '')) !== '') $quantity = (string)((int)$quantity + 1);
    }
    $repairStatus = '—';
    if (trim((string)($repair['repair_branch_delivery_date'] ?? '')) !== '') $repairStatus = 'Şubede';
    if (trim((string)($repair['repair_delivery_date'] ?? '')) !== '') $repairStatus = 'Serviste';
    if (trim((string)($repair['repair_service_return_date'] ?? '')) !== '') $repairStatus = 'Teslim Bekliyor';
    if (trim((string)($repair['repair_patient_delivery_date'] ?? '')) !== '') $repairStatus = 'Teslim Edildi';
    $repairDeviceData[] = [
        'record_no' => (string)$service['record_no'],
        'device' => $device ?: '—',
        'quantity' => $quantity ?: '—',
        'status' => $repairStatus,
        'legal_remaining' => technical_service_legal_days_remaining((string)($repair['repair_delivery_date'] ?? '')),
        'legal_deadline' => technical_service_legal_deadline((string)($repair['repair_delivery_date'] ?? '')),
    ];
}
$patients=$pdo->query('SELECT id,full_name,phone_primary FROM patients ORDER BY full_name')->fetchAll();
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS external_technical_patients (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NULL, record_date TEXT NOT NULL, full_name TEXT NOT NULL, national_id TEXT NULL, birth_date TEXT NULL, phone_primary TEXT NULL, phone_secondary TEXT NULL, address TEXT NULL, rating INTEGER NULL, comment TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS external_technical_patients (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, branch_id INT UNSIGNED NULL, record_date DATE NOT NULL, full_name VARCHAR(190) NOT NULL, national_id VARCHAR(30) NULL, birth_date DATE NULL, phone_primary VARCHAR(50) NULL, phone_secondary VARCHAR(50) NULL, address TEXT NULL, rating TINYINT NULL, comment TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$externalTechnicalPatients = $pdo->query('SELECT id,full_name,phone_primary,phone_secondary FROM external_technical_patients ORDER BY full_name')->fetchAll();
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS external_technical_services (id INTEGER PRIMARY KEY AUTOINCREMENT, external_patient_id INTEGER NOT NULL, record_no TEXT NOT NULL, service_date TEXT NOT NULL, device TEXT NULL, serial_no TEXT NULL, complaint TEXT NULL, technician_note TEXT NULL, delivery_date TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS external_technical_services (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, external_patient_id INT UNSIGNED NOT NULL, record_no VARCHAR(60) NOT NULL, service_date DATE NOT NULL, device VARCHAR(190) NULL, serial_no VARCHAR(190) NULL, complaint TEXT NULL, technician_note TEXT NULL, delivery_date DATE NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$externalRecordNumbers = $pdo->query("SELECT id, record_no FROM external_technical_services WHERE record_no LIKE 'DS-%' OR record_no LIKE 'DT-%' ORDER BY id")->fetchAll();
$lastExternalRecordNumber = 1452;
foreach ($externalRecordNumbers as $externalRecord) {
    if (str_starts_with((string)$externalRecord['record_no'], 'DS-')) $lastExternalRecordNumber = max($lastExternalRecordNumber, (int)substr((string)$externalRecord['record_no'], 3));
}
$renameExternalRecord = $pdo->prepare('UPDATE external_technical_services SET record_no=? WHERE id=?');
foreach ($externalRecordNumbers as $externalRecord) {
    if (!str_starts_with((string)$externalRecord['record_no'], 'DT-')) continue;
    $renameExternalRecord->execute(['DS-' . ++$lastExternalRecordNumber, (int)$externalRecord['id']]);
}
$externalServices = $pdo->query('SELECT s.*, p.full_name FROM external_technical_services s JOIN external_technical_patients p ON p.id=s.external_patient_id ORDER BY s.service_date DESC, s.id DESC')->fetchAll();
foreach ($externalServices as $externalService) {
    $externalRepairDetails = json_decode((string)($externalService['repair_details'] ?? ''), true);
    if (!is_array($externalRepairDetails)) $externalRepairDetails = [];
    $externalIssues = $externalRepairDetails['repair_customer_issues'] ?? (string)($externalService['complaint'] ?? '');
    $externalStatus = '—';
    if (trim((string)($externalRepairDetails['branch_delivery_date'] ?? '')) !== '') $externalStatus = 'Şubede';
    if (trim((string)($externalService['delivery_date'] ?? '')) !== '') $externalStatus = 'Serviste';
    if (trim((string)($externalRepairDetails['return_date'] ?? '')) !== '') $externalStatus = 'Teslim Bekliyor';
    if (trim((string)($externalRepairDetails['patient_delivery_date'] ?? '')) !== '') $externalStatus = 'Teslim Edildi';
    $services[] = [
        'id' => (int)$externalService['id'],
        'patient_id' => (int)$externalService['external_patient_id'],
        'record_no' => (string)$externalService['record_no'],
        'service_date' => (string)$externalService['service_date'],
        'full_name' => (string)$externalService['full_name'] . ' (Dış Hasta)',
        'repair_details' => json_encode([
            'repair_device' => (string)($externalService['device'] ?? ''),
            'repair_customer_issues[]' => $externalIssues,
            'repair_branch_delivery_date' => (string)($externalService['delivery_date'] ?? ''),
            'external_technical_service' => true,
        ], JSON_UNESCAPED_UNICODE),
    ];
    $repairDeviceData[] = [
        'record_no' => (string)$externalService['record_no'],
        'device' => trim((string)($externalService['device'] ?? '')) ?: '—',
        'quantity' => (string)($externalRepairDetails['repair_quantity'] ?? '1'),
        'status' => $externalStatus,
        'legal_remaining' => technical_service_legal_days_remaining((string)($externalService['delivery_date'] ?? '')),
        'legal_deadline' => technical_service_legal_deadline((string)($externalService['delivery_date'] ?? '')),
    ];
}
$externalPatientsWithoutService = $pdo->query('SELECT p.* FROM external_technical_patients p WHERE NOT EXISTS(SELECT 1 FROM external_technical_services s WHERE s.external_patient_id=p.id) ORDER BY p.record_date DESC,p.id DESC')->fetchAll();
foreach ($externalPatientsWithoutService as $externalPatientWithoutService) {
    $pendingRecordNo = 'DS-BEKLEME-' . (int)$externalPatientWithoutService['id'];
    $services[] = [
        'id' => 0,
        'patient_id' => (int)$externalPatientWithoutService['id'],
        'record_no' => $pendingRecordNo,
        'service_date' => (string)($externalPatientWithoutService['record_date'] ?? ''),
        'full_name' => (string)$externalPatientWithoutService['full_name'] . ' (Dış Hasta)',
        'repair_details' => json_encode(['external_technical_service' => true], JSON_UNESCAPED_UNICODE),
    ];
    $repairDeviceData[] = [
        'record_no' => $pendingRecordNo,
        'device' => '—',
        'quantity' => '—',
        'status' => 'Teknik Servis Formu Bekliyor',
        'legal_remaining' => null,
        'legal_deadline' => null,
    ];
}
$repairStatusByRecord = [];
foreach ($repairDeviceData as $repairDevice) $repairStatusByRecord[(string)($repairDevice['record_no'] ?? '')] = (string)($repairDevice['status'] ?? '—');
$repairLegalDaysByRecord = [];
foreach ($repairDeviceData as $repairDevice) $repairLegalDaysByRecord[(string)($repairDevice['record_no'] ?? '')] = $repairDevice['legal_remaining'] ?? null;
function repair_value(string $details,string $key): string {$data=json_decode($details,true);if($key==='repair_delivery_date')$key='repair_branch_delivery_date';$value=is_array($data)?($data[$key]??''):'';return is_array($value)?implode(', ', array_values(array_unique(array_filter(array_map('trim', $value), static fn(string $item): bool => $item !== '')))):(string)$value;}
if (($_GET['delete_error'] ?? '') === 'repair_payment') echo '<script>window.addEventListener("DOMContentLoaded",()=>alert("Bu Tamir kartına bağlı tahsilat var. Önce tahsilatı iptal etmeden kayıt silinemez."));</script>';
patient_header('Teknik Servis','stock');
?>
<main class="patient-container technical-service-page"><section class="technical-card"><header><h1><i class="ti tabler-tools"></i> Teknik Servis</h1><p>Hizmet kartlarında kaydedilmiş tamir kabul formları.</p></header><div class="technical-table-wrap"><table><thead><tr><th>KAYIT NO</th><th>TARİH</th><th>HASTA</th><th>CİHAZ</th><th>ARIZA / ŞİKAYET</th><th>TESLİM TARİHİ</th><th>İŞLEMLER</th></tr></thead><tbody><?php foreach($services as $service):$details=(string)($service['repair_details']??'');?><tr><td><?=e($service['record_no'])?></td><td><?=e(format_date_tr($service['service_date']))?></td><td><?=e($service['full_name'])?></td><td><?=e(repair_value($details,'repair_device'))?:'—'?></td><td><?=e(repair_value($details,'repair_customer_issues[]'))?:e(repair_value($details,'repair_note'))?:'—'?></td><td><?=e(format_date_tr(repair_value($details,'repair_delivery_date')))?></td><td><div class="technical-actions"><a href="<?=e(url('patient-followup.php?id='.(int)$service['patient_id'].'&edit='.(int)$service['id']))?>" title="Düzenle"><i class="ti tabler-edit"></i></a><form method="post" onsubmit="return confirm('Bu teknik servis kaydı silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="service_id" value="<?=(int)$service['id']?>"><button title="Sil"><i class="ti tabler-trash"></i></button></form></div></td></tr><?php endforeach;if(!$services):?><tr><td class="empty" colspan="7">Henüz teknik servis kaydı bulunmuyor.</td></tr><?php endif?></tbody></table></div></section></main>
<style>.technical-service-page{width:100%!important;max-width:1180px!important;min-height:100vh;margin:0 auto!important;padding:46px 20px 48px!important}.technical-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.technical-card>header{padding:22px 24px;border-bottom:1px solid #e1e2e8}.technical-card h1{margin:0 0 5px;color:#2f2b3d;font-size:21px;line-height:1.25}.technical-card h1 .ti{vertical-align:-3px;margin-right:7px}.technical-card p{margin:0;color:#7b7b8d}.technical-table-wrap{overflow:auto}.technical-card table{width:100%;min-width:900px;border-collapse:collapse}.technical-card th,.technical-card td{padding:14px 18px;border-bottom:1px solid #e1e2e8;text-align:left;white-space:nowrap}.technical-card th{font-size:12px;color:#5d5b6d}.technical-card tbody tr:hover{background:#f8fcf9}.technical-actions{display:flex;align-items:center;gap:8px}.technical-actions form{margin:0!important;padding:0!important;width:40px!important;height:42px!important}.technical-actions a,.technical-actions button{display:grid;place-items:center;width:40px;height:42px;padding:0;border:0;border-radius:7px;background:#19a94b;color:#fff;text-decoration:none;cursor:pointer}.technical-actions button{background:#e04f55;margin:0!important;transform:translateX(-8px)!important}.empty{text-align:center;color:#7b7b8d}@media(max-width:720px){.technical-service-page{max-width:none!important;padding:92px 14px 30px!important}}</style>
<div id="technical-form-modal" class="technical-modal" hidden><div class="technical-modal-backdrop"></div><section><header><h2>Yeni Teknik Servis Formu</h2><button type="button" class="technical-modal-close" aria-label="Kapat">×</button></header><form method="get" action="<?=e(url('patient-followup.php'))?>"><input type="hidden" name="new" value="1"><input type="hidden" name="service_name" value="Tamir"><label>Hasta<select name="id" required><option value="">Hasta seçiniz</option><?php foreach($patients as $patient):?><option value="<?=(int)$patient['id']?>"><?=e($patient['full_name'])?><?=trim((string)$patient['phone_primary'])?' — '.e($patient['phone_primary']):''?></option><?php endforeach?></select></label><footer><button type="button" class="technical-modal-close">İptal</button><button class="button">Tamir Formunu Aç</button></footer></form></section></div>
<style>.technical-service-page{max-width:1400px!important}.technical-card>header{position:relative}.technical-card>header>.new-technical-button{position:absolute;right:24px;top:50%;transform:translateY(-50%)}.technical-modal[hidden]{display:none!important}.technical-modal{position:fixed;z-index:1000;inset:0;display:grid;place-items:center;padding:20px}.technical-modal-backdrop{position:absolute;inset:0;background:rgba(32,33,45,.5)}.technical-modal>section{position:relative;width:min(520px,100%);border-radius:10px;background:#fff;box-shadow:0 18px 46px rgba(0,0,0,.28);overflow:hidden}.technical-modal header{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid #e1e2e8}.technical-modal h2{margin:0;font-size:19px}.technical-modal-close{border:0;background:transparent;color:#7b7b8d;font-size:28px;cursor:pointer}.technical-modal form{padding:22px 24px}.technical-modal label{display:flex;flex-direction:column;gap:7px;font-size:14px}.technical-modal select{height:42px;border:1px solid #d5d3de;border-radius:6px;padding:0 12px;font:inherit}.technical-modal footer{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}.technical-modal footer .technical-modal-close{font-size:14px;border:1px solid #d5d3de;border-radius:6px;padding:10px 16px}@media(max-width:720px){.technical-card>header{padding-right:190px}.technical-card>header>.new-technical-button{right:16px;font-size:12px}.technical-modal{padding:10px}}</style>
<script>(()=>{const modal=document.getElementById('technical-form-modal');const header=document.querySelector('.technical-card>header');if(!modal||!header)return;const open=document.createElement('button');open.type='button';open.id='new-technical-service';open.className='button new-technical-button';open.textContent='+ Yeni Teknik Servis Formu';header.append(open);const patientSelect=modal.querySelector('select[name="id"]');const patientLabel=patientSelect.closest('label');const externalLabel=document.createElement('label');externalLabel.className='technical-external-patient';externalLabel.innerHTML='<input type="checkbox" name="external_patient"> Kayıtsız hasta (dışarıdan geldi)';patientLabel.before(externalLabel);const external=externalLabel.querySelector('input');const search=document.createElement('input');search.type='search';search.className='technical-patient-search';search.placeholder='Hasta adı veya telefon numarasıyla ara';patientSelect.before(search);const normalize=value=>value.toLocaleLowerCase('tr-TR').replace(/[^a-z0-9çğıöşü]/gi,'');search.addEventListener('input',()=>{const query=normalize(search.value);[...patientSelect.options].forEach((option,index)=>{if(index===0){option.hidden=false;return}option.hidden=!!query&&!normalize(option.textContent).includes(query)});patientSelect.selectedIndex=0;});external.addEventListener('change',()=>{const externalPatient=external.checked;search.hidden=externalPatient;patientLabel.hidden=externalPatient;patientSelect.required=!externalPatient;patientSelect.value='';if(!externalPatient)search.focus();});open.addEventListener('click',()=>{modal.hidden=false;if(!external.checked)search.focus();});modal.querySelector('.technical-modal-backdrop').addEventListener('click',()=>modal.hidden=true);modal.querySelectorAll('.technical-modal-close').forEach(button=>button.addEventListener('click',()=>modal.hidden=true));})();</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>.swal2-popup{font-family:'Public Sans',sans-serif!important;border-radius:10px!important}.swal2-actions{gap:10px!important}.technical-swal-confirm,.technical-swal-cancel{margin:0!important;border:0!important;border-radius:6px!important;padding:10px 16px!important;font:600 14px 'Public Sans',sans-serif!important;cursor:pointer!important}.technical-swal-confirm{background:#19a94b!important;color:#fff!important}.technical-swal-cancel{background:#e04f55!important;color:#fff!important}</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const modal=document.getElementById('technical-form-modal');
  const external=modal?.querySelector('input[name="external_patient"]');
  const patientSelect=modal?.querySelector('select[name="id"]');
  const patientLabel=patientSelect?.closest('label');
  const search=modal?.querySelector('.technical-patient-search');
  const externalLabel=external?.closest('label');
  if(!external||!patientSelect||!patientLabel||!search||!externalLabel)return;
  Object.assign(externalLabel.style,{display:'flex',flexDirection:'row',alignItems:'center',gap:'8px',marginBottom:'14px',cursor:'pointer'});
  Object.assign(external.style,{width:'16px',height:'16px',margin:'0',accentColor:'#19a94b'});
  const visibilityStyle=document.createElement('style');
  visibilityStyle.textContent='.technical-modal.external-patient-selected .technical-patient-search,.technical-modal.external-patient-selected label:has(select[name="id"]){display:none!important}';
  document.head.appendChild(visibilityStyle);
  const syncExternal=()=>{const isExternal=external.checked;modal.classList.toggle('external-patient-selected',isExternal);search.hidden=isExternal;patientLabel.hidden=isExternal;patientSelect.required=!isExternal;if(isExternal)patientSelect.value='';};
  external.addEventListener('change',syncExternal);
  syncExternal();
  const form=modal.querySelector('form');
  const regularAction=form.action;
  const externalName=document.createElement('input');
  externalName.type='text'; externalName.name='external_name'; externalName.placeholder='Dışarıdan gelen hasta adı'; externalName.required=true; externalName.hidden=true;
  Object.assign(externalName.style,{boxSizing:'border-box',width:'100%',height:'42px',marginBottom:'12px',padding:'0 12px',border:'1px solid #d5d3de',borderRadius:'6px'});
  externalLabel.after(externalName);
  const syncExternalForm=()=>{const isExternal=external.checked;externalName.hidden=!isExternal;externalName.required=isExternal;form.action=isExternal?<?=json_encode(url('external-technical-patient.php'))?>:regularAction;};
  external.addEventListener('change',syncExternalForm);
  syncExternalForm();
});
</script>
<script>
document.querySelectorAll('.technical-actions form').forEach(form => {
  form.onsubmit = () => confirm('Bu Tamir kartını silmek istiyor musunuz? Hastadan tahsilat yapılmadıysa kayıt ve Teknik Servis listesindeki satır silinecektir.');
});
</script>
<style>
.technical-modal header h2{display:flex;align-items:center;gap:10px}
.technical-icon-field{position:relative;display:flex;align-items:center;margin-top:0}.technical-icon-field>i{position:absolute;left:13px;color:#716f82;font-size:19px;pointer-events:none}.technical-icon-field .technical-patient-search,.technical-icon-field select{width:100%;height:44px!important;padding-left:43px!important;border:1px solid #d9d7e1!important;border-radius:7px!important;background:#fff;font:inherit}.technical-icon-field:focus-within .technical-patient-search,.technical-icon-field:focus-within select{border-color:#19a94b!important;box-shadow:0 0 0 2px rgba(25,169,75,.12)}
.technical-modal form>label{margin-top:16px;color:#4b495c;font-weight:500}.technical-modal .technical-external-patient{padding:11px 12px;border:1px solid #e4e2e9;border-radius:7px;background:#fafafa}.technical-modal footer .button{display:inline-flex;align-items:center;gap:7px}.technical-modal .technical-icon-field:has(select[name="id"]){display:none!important}
</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const modal=document.getElementById('technical-form-modal');
  if(!modal)return;
  const decorate=(field,icon)=>{
    if(!field||field.parentElement?.classList.contains('technical-icon-field'))return;
    const wrapper=document.createElement('div'); wrapper.className='technical-icon-field';
    const iconElement=document.createElement('i'); iconElement.className='ti '+icon;
    field.before(wrapper); wrapper.append(iconElement,field);
  };
  decorate(modal.querySelector('.technical-patient-search'),'tabler-search');
  decorate(modal.querySelector('select[name="id"]'),'tabler-user');
  const search=modal.querySelector('.technical-patient-search');
  const select=modal.querySelector('select[name="id"]');
  if(!search||!select)return;
  const list=document.createElement('datalist'); list.id='technical-patient-options';
  [...select.options].slice(1).forEach(option=>{const item=document.createElement('option');item.value=option.textContent.trim();list.append(item);});
  modal.append(list);
  const patientSearchKey=value=>String(value).toLocaleLowerCase('tr-TR').replace(/\u00e7/g,'c').replace(/\u011f/g,'g').replace(/\u0131/g,'i').replace(/\u00f6/g,'o').replace(/\u015f/g,'s').replace(/\u00fc/g,'u').replace(/[^a-z0-9]/g,'');
  const normalize=value=>String(value).toLocaleLowerCase('tr-TR').replace(/[^a-z0-9çğıöşü]/gi,'');
  search.addEventListener('input',()=>{
    const query=patientSearchKey(search.value);
    search.removeAttribute('list');
    if(query.length<3){select.value='';return;}
    const match=[...select.options].slice(1).find(option=>patientSearchKey(option.textContent)===query);
    select.value=match?.value||'';
  });
  const results=document.createElement('div');
  results.className='technical-patient-results'; results.hidden=true;
  Object.assign(results.style,{maxHeight:'220px',overflowY:'auto',marginTop:'6px',border:'1px solid #d9d7e1',borderRadius:'7px',background:'#fff'});
  search.closest('.technical-icon-field')?.after(results);
  const renderResults=()=>{
    const query=patientSearchKey(search.value);
    results.replaceChildren();
    if(query.length<3){results.hidden=true;return;}
    const matches=[...select.options].slice(1).filter(option=>patientSearchKey(option.textContent).includes(query)).slice(0,3);
    matches.forEach(option=>{const item=document.createElement('button');item.type='button';item.textContent=option.textContent.trim();Object.assign(item.style,{display:'block',width:'100%',padding:'10px 12px',border:'0',borderBottom:'1px solid #ecebf0',background:'#fff',color:'#2f2b3d',font:'inherit',textAlign:'left',cursor:'pointer'});item.addEventListener('click',()=>{select.value=option.value;search.value=option.textContent.trim();results.hidden=true;select.dispatchEvent(new Event('change'));});results.append(item);});
    results.hidden=!matches.length;
  };
  search.addEventListener('input',renderResults);
  const form=modal.querySelector('form');
  const submitButton=form?.querySelector('footer .button');
  const externalPatient=modal.querySelector('input[name="external_patient"]');
  const syncSubmitButton=()=>{if(!submitButton)return;const hidden=!externalPatient?.checked&&!select.value;submitButton.hidden=hidden;submitButton.style.setProperty('display',hidden?'none':'block','important');};
  const clearSaveNotification=()=>{sessionStorage.removeItem('vox-save-notification');document.querySelector('.vox-save-notification')?.remove();};
  search.addEventListener('input',syncSubmitButton);
  select.addEventListener('change',syncSubmitButton);
  externalPatient?.addEventListener('change',syncSubmitButton);
  form?.addEventListener('submit',event=>{
    clearSaveNotification();
    if(externalPatient?.checked||!select.value||form.dataset.repairConfirmed==='1'){delete form.dataset.repairConfirmed;return;}
    event.preventDefault();
    const proceed=()=>{form.dataset.repairConfirmed='1';form.requestSubmit();};
    if(window.Swal){Swal.fire({title:'Emin misiniz?',text:'Hastaya Tamir servisi verilecek emin misiniz.',icon:'warning',showCancelButton:true,confirmButtonText:'Evet',cancelButtonText:'İptal',reverseButtons:true,buttonsStyling:false,customClass:{confirmButton:'technical-swal-confirm',cancelButton:'technical-swal-cancel'}}).then(result=>{if(result.isConfirmed)proceed();});}
    else if(confirm('Hastaya Tamir servisi verilecek emin misiniz.'))proceed();
  });
  syncSubmitButton();
});
</script>
<style>.technical-patient-types{display:flex;justify-content:center;align-items:center;gap:24px;margin:0 0 14px}.technical-modal form .technical-patient-type{display:inline-flex!important;flex-direction:row!important;align-items:center!important;gap:8px!important;width:auto!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important}.technical-patient-type input{width:16px!important;height:16px!important;margin:0!important;accent-color:#19a94b}.technical-modal form .technical-external-patient{margin:0!important}.technical-modal footer .technical-modal-close{display:grid!important;place-items:center!important;width:46px!important;padding:0!important}.technical-modal footer .button{box-sizing:border-box!important;height:37px!important;padding-top:0!important;padding-bottom:0!important}</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const modal=document.getElementById('technical-form-modal');
  const external=modal?.querySelector('input[name="external_patient"]');
  const externalLabel=external?.closest('label');
  if(!modal||!external||!externalLabel)return;

  const title=modal.querySelector('h2');
  if(title&&!title.querySelector('i'))title.insertAdjacentHTML('afterbegin','<i class="ti tabler-tools" aria-hidden="true"></i>');
  const submitButton=modal.querySelector('footer .button');
  if(submitButton)submitButton.lastChild.textContent=' Tamir Formu';
  if(submitButton&&!submitButton.querySelector('i'))submitButton.insertAdjacentHTML('afterbegin','<i class="ti tabler-tool" aria-hidden="true"></i>');
  if(submitButton?.querySelector('i'))submitButton.querySelector('i').className='ti tabler-tool';

  external.type='radio';
  externalLabel.classList.add('technical-patient-type');
  externalLabel.textContent='';
  externalLabel.append(external,' Dış Hasta');
  const voxLabel=document.createElement('label');
  voxLabel.className='technical-patient-type technical-vox-patient';
  const vox=document.createElement('input');
  vox.type='radio'; vox.name='vox_patient'; vox.checked=!external.checked;
  voxLabel.append(vox,' Vox Hastası');
  const typeGroup=document.createElement('div'); typeGroup.className='technical-patient-types';
  externalLabel.before(typeGroup); typeGroup.append(voxLabel,externalLabel);

  vox.addEventListener('change',()=>{
    if(!vox.checked)return;
    external.checked=false;
    external.dispatchEvent(new Event('change'));
  });
  external.addEventListener('change',()=>{
    if(external.checked)vox.checked=false;
  });
});
</script>
<style>.technical-modal footer .button{position:relative!important;display:block!important;width:46px!important;min-width:46px!important;height:37px!important;min-height:37px!important;padding:0!important;font-size:0!important}.technical-modal footer .button i{position:absolute!important;top:50%!important;left:50%!important;display:block!important;margin:0!important;line-height:1!important;font-size:23px!important;transform:translate(-50%,-50%)!important}</style>
<script>
window.addEventListener('load',()=>{
  const modal=document.getElementById('technical-form-modal');
  const cancel=modal?.querySelector('footer .technical-modal-close');
  const submit=modal?.querySelector('footer .button');
  if(!cancel||!submit)return;
  const height=Math.round(cancel.getBoundingClientRect().height);
  const width=Math.round(cancel.getBoundingClientRect().width);
  if(height>0&&width>0){
    submit.style.cssText+='width:'+width+'px!important;height:'+height+'px!important;min-width:'+width+'px!important;min-height:'+height+'px!important;padding:0!important;';
    submit.title='Tamir Formu'; submit.setAttribute('aria-label','Tamir Formu');
    [...submit.childNodes].forEach(node=>{if(node.nodeType===Node.TEXT_NODE)node.remove();});
  }
});
</script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const modal=document.getElementById('technical-form-modal');
  const sync=()=>requestAnimationFrame(()=>{
    const cancel=modal?.querySelector('footer .technical-modal-close');
    const submit=modal?.querySelector('footer .button');
    if(!cancel||!submit)return;
    const {width,height}=cancel.getBoundingClientRect();
    if(width>0&&height>0)submit.style.cssText+='width:'+width+'px!important;height:'+height+'px!important;min-width:'+width+'px!important;min-height:'+height+'px!important;';
  });
  if(modal)new MutationObserver(sync).observe(modal,{attributes:true,attributeFilter:['hidden']});
  sync();
});
</script>
<script>
(() => {
  const deviceData = <?=json_encode($repairDeviceData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const table = document.querySelector('.technical-card table');
  if (!table) return;
  table.querySelector('thead th:nth-child(6)')?.replaceChildren('SÜRE BİTİŞ');
  const deviceHeader = table.querySelector('thead th:nth-child(4)');
  if (deviceHeader && !table.querySelector('.technical-quantity-header')) {
    const quantityHeader = document.createElement('th'); quantityHeader.className = 'technical-quantity-header'; quantityHeader.textContent = 'ADET';
    deviceHeader.after(quantityHeader);
  }
  [...table.querySelectorAll('tbody tr')].forEach((row, index) => {
    const data = deviceData[index];
    if (!data || row.cells.length < 6) return;
    row.cells[3].textContent = data.device;
    if (!row.querySelector('.technical-quantity-cell')) {
      const quantityCell = document.createElement('td'); quantityCell.className = 'technical-quantity-cell'; quantityCell.textContent = data.quantity;
      row.cells[3].after(quantityCell);
    }
    const deadlineCell = row.cells[6];
    if (deadlineCell) deadlineCell.textContent = data.legal_deadline || '—';
  });
})();
</script>
<script>
(() => {
  const legalData = <?=json_encode($repairDeviceData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const table = document.querySelector('.technical-card table');
  const quantityHeader = table?.querySelector('.technical-quantity-header');
  if (!table || !quantityHeader || table.querySelector('.technical-legal-header')) return;
  const legalHeader = document.createElement('th');
  legalHeader.className = 'technical-legal-header';
  legalHeader.textContent = 'YASAL SÜRE';
  quantityHeader.after(legalHeader);
  [...table.tBodies[0].rows].forEach((row, index) => {
    const quantityCell = row.querySelector('.technical-quantity-cell');
    if (!quantityCell || row.querySelector('.technical-legal-cell')) return;
    const remaining = legalData[index]?.legal_remaining;
    const deadline = legalData[index]?.legal_deadline;
    const legalCell = document.createElement('td');
    legalCell.className = 'technical-legal-cell';
    legalCell.textContent = remaining === null || remaining === undefined ? '—' : `${remaining} iş günü`;
    if (remaining !== null && remaining !== undefined) {
      legalCell.classList.add('legal-active');
      if (deadline) legalCell.title = `Süre bitiş tarihi: ${deadline}`;
    }
    if (remaining === 0) legalCell.classList.add('legal-expired');
    else if (typeof remaining === 'number' && remaining <= 5) legalCell.classList.add('legal-warning');
    quantityCell.after(legalCell);
  });
})();
</script>
<style>
.technical-legal-cell{color:#2f2b3d;white-space:nowrap}
.technical-legal-cell.legal-active{color:#dc3545!important;cursor:help}
.technical-legal-cell.legal-warning{color:#d26a00}
.technical-legal-cell.legal-expired{color:#dc3545}
</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const modal=document.getElementById('technical-form-modal');
  const form=modal?.querySelector('form');
  const external=modal?.querySelector('input[name="external_patient"]');
  const voxSearch=modal?.querySelector('.technical-patient-search');
  const patientSelect=modal?.querySelector('select[name="id"]');
  const submit=form?.querySelector('footer .button');
  if(!modal||!form||!external||!voxSearch||!patientSelect||!submit)return;
  const externalPatients=<?=json_encode($externalTechnicalPatients, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const externalSearch=modal.querySelector('[name="external_name"]');
  if(!externalSearch)return;
  const externalId=document.createElement('input'); externalId.type='hidden'; externalId.name='id'; externalId.disabled=true; form.append(externalId);
  const results=document.createElement('div'); results.hidden=true;
  Object.assign(results.style,{maxHeight:'160px',overflowY:'auto',marginTop:'6px',border:'1px solid #d9d7e1',borderRadius:'7px',background:'#fff'});
  externalSearch.after(results);
  const searchKey=value=>String(value).toLocaleLowerCase('tr-TR').replace(/\u00e7/g,'c').replace(/\u011f/g,'g').replace(/\u0131/g,'i').replace(/\u00f6/g,'o').replace(/\u015f/g,'s').replace(/\u00fc/g,'u').replace(/[^a-z0-9]/g,'');
  const setButton=(selected=false)=>{const icon=submit.querySelector('i');if(selected){submit.title='Tamir Formu';submit.setAttribute('aria-label','Tamir Formu');if(icon)icon.className='ti tabler-tool';}else{submit.title='Yeni dış hasta';submit.setAttribute('aria-label','Yeni dış hasta');if(icon)icon.className='ti tabler-user-plus';}};
  const renderExternalResults=()=>{
    const query=searchKey(externalSearch.value); results.replaceChildren(); externalId.value='';
    if(query.length<3){results.hidden=true;setButton(false);return;}
    const matches=externalPatients.filter(patient=>searchKey(`${patient.full_name} ${patient.phone_primary||''} ${patient.phone_secondary||''}`).includes(query)).slice(0,3);
    matches.forEach(patient=>{const item=document.createElement('button');item.type='button';item.textContent=`${patient.full_name}${patient.phone_primary?' — '+patient.phone_primary:''}`;Object.assign(item.style,{display:'block',width:'100%',padding:'10px 12px',border:'0',borderBottom:'1px solid #ecebf0',background:'#fff',color:'#2f2b3d',font:'inherit',textAlign:'left',cursor:'pointer'});item.addEventListener('click',()=>{externalSearch.value=item.textContent;externalId.value=String(patient.id);results.hidden=true;setButton(true);});results.append(item);});
    results.hidden=!matches.length; setButton(false);
  };
  const syncExternalFlow=()=>{
    const isExternal=external.checked;
    externalSearch.hidden=!isExternal; results.hidden=true; externalId.disabled=!isExternal;
    patientSelect.disabled=isExternal; patientSelect.required=!isExternal;
    if(isExternal){voxSearch.hidden=true;externalSearch.required=false;externalSearch.placeholder='Dışarıdan gelen hasta adı veya telefonu ara';form.action=<?=json_encode(url('external-technical-patient.php'))?>;setButton(!!externalId.value);}
    else{externalId.value='';patientSelect.disabled=false;voxSearch.hidden=false;form.action=<?=json_encode(url('patient-followup.php'))?>;}
  };
  externalSearch.type='search'; externalSearch.required=false; externalSearch.addEventListener('input',renderExternalResults);
  external.addEventListener('change',syncExternalFlow); syncExternalFlow();
  form.addEventListener('submit',event=>{
    if(!external.checked)return;
    if(externalId.value){form.action=<?=json_encode(url('external-technical-repair.php'))?>;return;}
    form.action=<?=json_encode(url('external-technical-patient.php'))?>;
  },true);
});
</script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('.technical-card tbody tr').forEach(row=>{
    if (!row.cells[0]?.textContent.trim().startsWith('DS-')) return;
    const actions=row.querySelector('.technical-actions');
    const edit=actions?.querySelector('a');
    if (!edit) return;
    const parameters=new URL(edit.href,window.location.origin).searchParams;
    const patientId=parameters.get('id');
    const editId=parameters.get('edit');
    if (patientId) edit.href='external-technical-repair.php?id='+encodeURIComponent(patientId)+(editId?'&edit='+encodeURIComponent(editId):'');
    actions.querySelector('form')?.remove();
  });
});
</script>
<style>.technical-list-toolbar{display:flex;align-items:center;gap:12px;padding:18px 24px;border-bottom:1px solid #e7e6ec;flex-wrap:wrap}.technical-list-toolbar label{display:flex;align-items:center;gap:8px;color:#6d697d;font-size:14px}.technical-list-toolbar select,.technical-list-toolbar input{height:40px;border:1px solid #d9d7e1;border-radius:7px;padding:0 12px;background:#fff;color:#2f2b3d;font:inherit}.technical-list-toolbar select{min-width:82px}.technical-list-toolbar .technical-year{min-width:196px}.technical-toolbar-spacer{flex:1}.technical-toolbar-button{height:40px;border:0;border-radius:7px;padding:0 14px;background:#19a94b;color:#fff;font:600 13px inherit;cursor:pointer}.technical-export-button{width:42px;padding:0;font-size:19px}.technical-column-panel{position:absolute;z-index:20;display:none;min-width:190px;padding:10px;border:1px solid #dedce5;border-radius:8px;background:#fff;box-shadow:0 9px 25px #1e283c22}.technical-column-panel.visible{display:grid;gap:8px}.technical-column-panel label{display:flex;gap:8px;align-items:center;font-size:13px;color:#4b495c}.technical-column-panel input{width:16px;height:16px;margin:0;padding:0}@media(max-width:720px){.technical-list-toolbar{padding:14px}.technical-toolbar-spacer{display:none}.technical-list-toolbar .technical-search{width:100%}}</style>
<script>document.addEventListener('DOMContentLoaded',()=>{const table=document.querySelector('.technical-card table'),card=document.querySelector('.technical-card');if(!table||!card)return;const rows=[...table.tBodies[0].rows].filter(row=>!row.querySelector('.empty')),headers=[...table.tHead.rows[0].cells];const toolbar=document.createElement('div');toolbar.className='technical-list-toolbar';toolbar.innerHTML='<label>Göster <select class="technical-page-size"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100" selected>100</option></select> kayıt</label><select class="technical-year"></select><span class="technical-toolbar-spacer"></span><button type="button" class="technical-toolbar-button technical-columns"><i class="ti tabler-columns-3"></i> Sütunlar</button><button type="button" class="technical-toolbar-button technical-export-button" title="Excel olarak indir" aria-label="Excel olarak indir"><i class="ti tabler-file-spreadsheet"></i></button><label>Ara: <input class="technical-search" placeholder="Seçili sütunlarda ara"></label>';card.querySelector('header')?.after(toolbar);const yearSelect=toolbar.querySelector('.technical-year'),search=toolbar.querySelector('.technical-search'),pageSize=toolbar.querySelector('.technical-page-size');const years={};rows.forEach(row=>{const match=row.cells[1]?.textContent.match(/(\d{4})/);if(match)years[match[1]]=(years[match[1]]||0)+1;});yearSelect.add(new Option('Tüm Kayıtlar ('+rows.length+' kayıt)','all'));Object.entries(years).sort((a,b)=>b[0].localeCompare(a[0])).forEach(([year,count])=>yearSelect.add(new Option(year+' ('+count+' kayıt)',year)));const panel=document.createElement('div');panel.className='technical-column-panel';headers.forEach((header,index)=>{const label=document.createElement('label'),checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.checked=true;checkbox.addEventListener('change',()=>{table.querySelectorAll('tr').forEach(row=>{if(row.cells[index])row.cells[index].style.display=checkbox.checked?'':'none';});});label.append(checkbox,header.textContent.trim());panel.append(label);});toolbar.querySelector('.technical-columns').after(panel);toolbar.querySelector('.technical-columns').addEventListener('click',()=>panel.classList.toggle('visible'));const update=()=>{const query=search.value.toLocaleLowerCase('tr-TR').trim(),year=yearSelect.value,limit=Number(pageSize.value);let shown=0;rows.forEach(row=>{const rowYear=(row.cells[1]?.textContent.match(/(\d{4})/)||[])[1]||'';const matchesYear=year==='all'||rowYear===year;const matchesQuery=!query||[...row.cells].some(cell=>cell.style.display!=='none'&&cell.textContent.toLocaleLowerCase('tr-TR').includes(query));const visible=matchesYear&&matchesQuery&&shown<limit;if(matchesYear&&matchesQuery)shown++;row.style.display=visible?'':'none';});};[search,yearSelect,pageSize].forEach(input=>input.addEventListener(input===search?'input':'change',update));update();toolbar.querySelector('.technical-export-button').addEventListener('click',()=>{const selected=headers.map((header,index)=>header.style.display!=='none'?index:null).filter(index=>index!==null),csv=[selected.map(index=>headers[index].textContent.trim())];rows.filter(row=>row.style.display!=='none').forEach(row=>csv.push(selected.map(index=>'"'+String(row.cells[index]?.textContent||'').trim().replaceAll('"','""')+'"')));const blob=new Blob(['\uFEFF'+csv.map(line=>line.join(';')).join('\n')],{type:'text/csv;charset=utf-8'}),link=document.createElement('a');link.href=URL.createObjectURL(blob);link.download='teknik-servis-listesi.csv';link.click();URL.revokeObjectURL(link.href);});});</script>
<style>.technical-card{overflow:visible!important}.technical-list-toolbar{position:relative;z-index:60;overflow:visible!important}.technical-column-panel{position:absolute!important;z-index:70!important}.technical-table-wrap{position:relative;z-index:1}</style>
<script>document.addEventListener('DOMContentLoaded',()=>{const toolbar=document.querySelector('.technical-list-toolbar'),button=toolbar?.querySelector('.technical-columns'),panel=toolbar?.querySelector('.technical-column-panel');if(!toolbar||!button||!panel)return;button.addEventListener('click',()=>{const toolbarBox=toolbar.getBoundingClientRect(),buttonBox=button.getBoundingClientRect();panel.style.left=(buttonBox.left-toolbarBox.left)+'px';panel.style.top=(buttonBox.bottom-toolbarBox.top+6)+'px';});});</script>
<script>document.addEventListener('DOMContentLoaded',()=>{const button=document.getElementById('new-technical-service');if(button)button.textContent='+ Teknik Servis';});</script>
<style>.technical-toolbar-button i{font-size:18px!important;line-height:1!important;vertical-align:-3px}.technical-export-button i{font-size:20px!important}</style>
<script>document.addEventListener('DOMContentLoaded',()=>{const columns=document.querySelector('.technical-columns'),exportButton=document.querySelector('.technical-export-button');if(columns)columns.innerHTML='<i class="ti tabler-columns"></i> Sütunlar';if(exportButton)exportButton.innerHTML='<i class="ti tabler-file-spreadsheet"></i>';});</script>
<script>document.addEventListener('DOMContentLoaded',()=>{const data=<?=json_encode($repairDeviceData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,table=document.querySelector('.technical-card table'),quantityHeader=table?.querySelector('.technical-quantity-header');if(!table||!quantityHeader)return;if(!table.querySelector('.technical-status-header')){const header=document.createElement('th');header.className='technical-status-header';header.textContent='DURUM';quantityHeader.after(header);}table.querySelectorAll('tbody tr').forEach((row,index)=>{const quantity=row.querySelector('.technical-quantity-cell');if(!quantity||row.querySelector('.technical-status-cell'))return;const cell=document.createElement('td');cell.className='technical-status-cell';cell.textContent=data[index]?.status||'—';quantity.after(cell);});});</script>
<script>document.addEventListener('DOMContentLoaded',()=>{const table=document.querySelector('.technical-card table'),panel=document.querySelector('.technical-column-panel');if(!table||!panel)return;panel.querySelectorAll('input[type="checkbox"]').forEach(checkbox=>checkbox.addEventListener('change',()=>{const title=checkbox.parentElement?.textContent.trim(),headers=[...table.tHead.rows[0].cells],index=headers.findIndex(header=>header.textContent.trim()===title);if(index<0)return;table.tBodies[0].rows.forEach(row=>{if(row.cells[index])row.cells[index].style.display=checkbox.checked?'':'none';});}));});</script>
<script>document.addEventListener('DOMContentLoaded',()=>{const table=document.querySelector('.technical-card table'),panel=document.querySelector('.technical-column-panel');if(!table||!panel||panel.querySelector('[data-status-column]'))return;const label=document.createElement('label'),checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.checked=true;checkbox.dataset.statusColumn='1';label.append(checkbox,'DURUM');checkbox.addEventListener('change',()=>{const index=[...table.tHead.rows[0].cells].findIndex(header=>header.textContent.trim()==='DURUM');table.tBodies[0].rows.forEach(row=>{if(index>=0&&row.cells[index])row.cells[index].style.display=checkbox.checked?'':'none';});});panel.append(label);});</script>
<script>document.addEventListener('DOMContentLoaded',()=>setTimeout(()=>{const data=<?=json_encode($repairDeviceData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,table=document.querySelector('.technical-card table'),panel=document.querySelector('.technical-column-panel'),quantityHeader=table?.querySelector('.technical-quantity-header');if(!table||!panel||!quantityHeader)return;let index=[...table.tHead.rows[0].cells].findIndex(header=>header.textContent.trim()==='DURUM');if(index<0){const header=document.createElement('th');header.className='technical-status-header';header.textContent='DURUM';quantityHeader.after(header);index=[...table.tHead.rows[0].cells].findIndex(item=>item===header);}table.tBodies[0].rows.forEach((row,rowIndex)=>{let cell=row.cells[index];if(!cell){cell=document.createElement('td');cell.className='technical-status-cell';row.querySelector('.technical-quantity-cell')?.after(cell);}cell.textContent=data[rowIndex]?.status||'—';cell.style.display='';});if(!panel.querySelector('[data-status-column]')){const label=document.createElement('label'),checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.checked=true;checkbox.dataset.statusColumn='1';label.append(checkbox,'DURUM');checkbox.addEventListener('change',()=>table.tBodies[0].rows.forEach(row=>{if(row.cells[index])row.cells[index].style.display=checkbox.checked?'':'none';}));panel.append(label);}},120));</script>
<script>document.addEventListener('DOMContentLoaded',()=>setTimeout(()=>{const panel=document.querySelector('.technical-column-panel');if(!panel)return;const status=[...panel.querySelectorAll('label')].find(label=>label.textContent.trim()==='DURUM'),quantity=[...panel.querySelectorAll('label')].find(label=>label.textContent.trim()==='ADET');if(status&&quantity)quantity.after(status);},180));</script>
<style>.technical-status-cell{font-weight:800}.technical-status-cell.status-branch{color:#1769aa}.technical-status-cell.status-service{color:#8a4b12}.technical-status-cell.status-waiting{color:#d26a00}.technical-status-cell.status-delivered{color:#169447}.technical-status-cell.status-empty{color:#8a8795}</style>
<script>document.addEventListener('DOMContentLoaded',()=>setTimeout(()=>document.querySelectorAll('.technical-status-cell').forEach(cell=>{const value=cell.textContent.trim();cell.classList.remove('status-branch','status-service','status-waiting','status-delivered','status-empty');cell.classList.add(value==='Şubede'?'status-branch':value==='Serviste'?'status-service':value==='Teslim Bekliyor'?'status-waiting':value==='Teslim Edildi'?'status-delivered':'status-empty');}),200));</script>
<style>.technical-status-cell{font-weight:800!important;color:#fff!important}.technical-status-cell.status-branch{background:#1769aa!important}.technical-status-cell.status-service{background:#8a4b12!important}.technical-status-cell.status-waiting{background:#d26a00!important}.technical-status-cell.status-delivered{background:#169447!important}.technical-status-cell.status-empty{background:#8a8795!important}</style>
<style>.technical-status-cell{background:transparent!important;color:inherit!important}.technical-status-badge{display:inline-block;padding:5px 9px;border-radius:6px;color:#fff!important;font-weight:800;white-space:nowrap}.technical-status-cell.status-branch .technical-status-badge{background:#1769aa}.technical-status-cell.status-service .technical-status-badge{background:#8a4b12}.technical-status-cell.status-waiting .technical-status-badge{background:#d26a00}.technical-status-cell.status-delivered .technical-status-badge{background:#169447}.technical-status-cell.status-empty .technical-status-badge{background:#8a8795}</style>
<script>document.addEventListener('DOMContentLoaded',()=>setTimeout(()=>document.querySelectorAll('.technical-status-cell').forEach(cell=>{if(cell.querySelector('.technical-status-badge'))return;const badge=document.createElement('span');badge.className='technical-status-badge';badge.textContent=cell.textContent.trim();cell.replaceChildren(badge);}),240));</script>
<style>.technical-status-cell,.technical-status-badge{font-weight:400!important}</style>
<style>.technical-status-badge{background:transparent!important;border:1px solid currentColor!important}.technical-status-cell.status-branch .technical-status-badge{color:#1769aa!important}.technical-status-cell.status-service .technical-status-badge{color:#dc3545!important}.technical-status-cell.status-waiting .technical-status-badge{color:#d26a00!important}.technical-status-cell.status-delivered .technical-status-badge{color:#169447!important}.technical-status-cell.status-empty .technical-status-badge{color:#8a8795!important}</style>
<style>.technical-status-badge{border:0!important;padding:0!important;border-radius:0!important;background:transparent!important}</style>
<style>
/* Vuexy'deki rounded renkli buton görünümü: durumlar için rozet olarak kullanılır. */
.technical-status-cell{background:transparent!important}
.technical-status-cell.status-branch,.technical-status-cell.status-service,.technical-status-cell.status-waiting,.technical-status-cell.status-delivered,.technical-status-cell.status-empty{background:#fff!important}
.technical-status-badge{display:inline-flex!important;align-items:center!important;min-height:32px!important;padding:7px 13px!important;border:0!important;border-radius:.375rem!important;color:#2f2b3d!important;font-size:13px!important;font-weight:500!important;line-height:1!important;white-space:nowrap!important;box-shadow:0 2px 5px rgba(47,43,61,.16)!important}
.technical-status-cell.status-branch .technical-status-badge{background:#4d8edb!important}
.technical-status-cell.status-service .technical-status-badge{background:#e64255!important}
.technical-status-cell.status-waiting .technical-status-badge{background:#ffc53d!important}
.technical-status-cell.status-delivered .technical-status-badge{background:#8fc544!important}
.technical-status-cell.status-empty .technical-status-badge{background:#82868b!important}
.technical-status-cell.status-branch .technical-status-badge,.technical-status-cell.status-service .technical-status-badge,.technical-status-cell.status-waiting .technical-status-badge,.technical-status-cell.status-delivered .technical-status-badge,.technical-status-cell.status-empty .technical-status-badge{color:#2f2b3d!important}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => setTimeout(() => {
  const table = document.querySelector('.technical-card table');
  if (!table) return;
  const statusByRecord = <?=json_encode($repairStatusByRecord, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const statusClass = {'Şubede':'status-branch','Serviste':'status-service','Teslim Bekliyor':'status-waiting','Teslim Edildi':'status-delivered'};
  [...table.tBodies[0].rows].forEach(row => {
    const recordNo = row.dataset.technicalRecordNo || row.cells[0]?.textContent.trim() || '';
    const status = statusByRecord[recordNo];
    const column = [...table.tHead.rows[0].cells].findIndex(header => header.textContent.trim() === 'DURUM');
    if (!status || column < 0 || !row.cells[column]) return;
    const cell = row.cells[column];
    cell.className = 'technical-status-cell ' + (statusClass[status] || 'status-empty');
    const badge = document.createElement('span');
    badge.className = 'technical-status-badge';
    badge.textContent = status;
    cell.replaceChildren(badge);
  });
}, 350));
</script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('.technical-card tbody tr').forEach(row=>{
    if(!row.cells[0]?.textContent.trim().startsWith('DS-'))return;
    const actions=row.querySelector('.technical-actions');
    const edit=actions?.querySelector('a');
    if(!actions||!edit||actions.querySelector('.external-patient-card-link'))return;
    const patientId=new URL(edit.href,window.location.origin).searchParams.get('id');
    if(!patientId)return;
    const card=document.createElement('a');
    card.className='external-patient-card-link';
    card.href='external-technical-patient.php?id='+encodeURIComponent(patientId);
    card.title='Dış Hasta Kartı';card.setAttribute('aria-label','Dış Hasta Kartı');
    card.innerHTML='<i class="ti tabler-user-circle"></i>';
    actions.insertBefore(card, edit);
  });
});
</script>
<style>.technical-actions .external-patient-card-link{display:grid;place-items:center;width:40px;height:42px;border-radius:7px;background:#6f42c1;color:#fff;text-decoration:none}.technical-actions .external-patient-card-link .ti{font-size:21px}</style>
<script>document.addEventListener('DOMContentLoaded',()=>setTimeout(()=>document.querySelectorAll('.technical-card tbody tr').forEach(row=>{const record=row.cells[0]?.textContent.trim()||'';if(!record.startsWith('DS-BEKLEME-'))return;row.dataset.technicalRecordNo=record;row.cells[0].textContent='—';}),500));</script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const modal=document.getElementById('technical-form-modal'),form=modal?.querySelector('form'),search=modal?.querySelector('.technical-patient-search'),select=modal?.querySelector('select[name="id"]'),external=modal?.querySelector('input[name="external_patient"]'),button=modal?.querySelector('footer .button');
  if(!form||!search||!select||!button)return;
  const key=value=>String(value).toLocaleLowerCase('tr-TR').replace(/ç/g,'c').replace(/ğ/g,'g').replace(/ı/g,'i').replace(/ö/g,'o').replace(/ş/g,'s').replace(/ü/g,'u').replace(/[^a-z0-9]/g,'');
  const resolve=()=>{if(select.value)return true;const query=key(search.value);if(query.length<3)return false;const option=[...select.options].slice(1).find(item=>key(item.textContent)===query);if(option){select.value=option.value;return true;}return false;};
  const sync=()=>{const visible=!!external?.checked||resolve()||key(search.value).length>=3;button.hidden=!visible;button.style.setProperty('display',visible?'block':'none','important');};
  search.addEventListener('input',sync);search.addEventListener('change',sync);select.addEventListener('change',sync);external?.addEventListener('change',sync);
  form.addEventListener('submit',event=>{if(external?.checked||resolve())return;event.preventDefault();event.stopImmediatePropagation();alert('Lütfen listeden bir hasta seçiniz.');search.focus();},true);
  sync();
});
</script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const table=document.querySelector('.technical-card table');
  const panel=document.querySelector('.technical-column-panel');
  if(!table||!panel)return;
  panel.addEventListener('change',event=>{
    const checkbox=event.target.closest('input[type="checkbox"]');
    if(!checkbox)return;
    event.stopImmediatePropagation();
    const title=checkbox.closest('label')?.textContent.trim()||'';
    const headers=[...table.tHead.rows[0].cells];
    const index=headers.findIndex(header=>header.textContent.trim()===title);
    if(index<0)return;
    table.querySelectorAll('tr').forEach(row=>{
      if(row.cells[index])row.cells[index].style.display=checkbox.checked?'':'none';
    });
  },true);
});
</script>
<?php patient_footer(); ?>
