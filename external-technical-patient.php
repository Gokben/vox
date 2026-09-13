<?php
declare(strict_types=1);
require_once __DIR__.'/patient-identity.php';
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS external_technical_patients (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NULL, record_date TEXT NOT NULL, full_name TEXT NOT NULL, national_id TEXT NULL, birth_date TEXT NULL, phone_primary TEXT NULL, phone_secondary TEXT NULL, address TEXT NULL, rating INTEGER NULL, comment TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS external_technical_patients (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, branch_id INT UNSIGNED NULL, record_date DATE NOT NULL, full_name VARCHAR(190) NOT NULL, national_id VARCHAR(30) NULL, birth_date DATE NULL, phone_primary VARCHAR(50) NULL, phone_secondary VARCHAR(50) NULL, address TEXT NULL, rating TINYINT NULL, comment TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$branches = [];
try { $branches = $pdo->query('SELECT id,name FROM branches ORDER BY name')->fetchAll(); } catch (Throwable $exception) {}
$prefillName = trim((string)($_GET['external_name'] ?? ''));
$externalPatientId = max(0, (int)($_GET['id'] ?? 0));
$editingExternalPatient = false;
$existingExternalPatient = [];
if ($externalPatientId) {
    $existingStatement = $pdo->prepare('SELECT * FROM external_technical_patients WHERE id=?');
    $existingStatement->execute([$externalPatientId]);
    $existingExternalPatient = $existingStatement->fetch() ?: [];
    if (!$existingExternalPatient) { http_response_code(404); exit('Dış hasta kaydı bulunamadı.'); }
    $editingExternalPatient = true;
}
ensure_patient_passport_schema($pdo);
$data = ['branch_id'=>'','record_date'=>date('Y-m-d'),'full_name'=>$prefillName,'national_id'=>'','passport_no'=>'','birth_date'=>'','phone_primary'=>'','phone_secondary'=>'','address'=>'','rating'=>'','comment'=>''];
if ($editingExternalPatient) foreach ($data as $key => $value) $data[$key] = (string)($existingExternalPatient[$key] ?? $value);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete_external_patient') {
    verify_csrf();
    if (!$editingExternalPatient) $error = 'Silinecek dış hasta kaydı bulunamadı.';
    else {
        $paymentCheck = $pdo->prepare('SELECT repair_details FROM external_technical_services WHERE external_patient_id=?');
        $paymentCheck->execute([$externalPatientId]);
        $hasPayment = false;
        foreach ($paymentCheck->fetchAll(PDO::FETCH_COLUMN) as $repairDetailsJson) {
            $repairDetails = json_decode((string)$repairDetailsJson, true);
            if (!is_array($repairDetails)) continue;
            $amount = trim((string)($repairDetails['repair_service_fee'] ?? ''));
            $paymentType = trim((string)($repairDetails['repair_service_fee_payment_type'] ?? ''));
            $normalizedAmount = (float)str_replace(',', '.', preg_replace('/[^0-9,.-]/', '', $amount) ?? '0');
            if ($paymentType !== '' && $normalizedAmount > 0) { $hasPayment = true; break; }
        }
        if ($hasPayment) $error = 'Bu dış hasta kartında ödeme bilgisi bulunduğu için silme işlemi yapılamaz.';
        else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM external_technical_services WHERE external_patient_id=?')->execute([$externalPatientId]);
                $pdo->prepare('DELETE FROM external_technical_patients WHERE id=?')->execute([$externalPatientId]);
                $pdo->commit();
                redirect('technical-service.php');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') !== 'delete_external_patient') {
    verify_csrf();
    foreach ($data as $key => $value) $data[$key] = trim((string)($_POST[$key] ?? ''));
    $error=patient_identity_validate($pdo,$data['national_id'],'external_technical_patients',$externalPatientId,$data['passport_no']);
    if ($error==='') $error=patient_passport_error($data['passport_no']);
    if ($data['full_name'] === '') $error = 'Ad Soyad zorunludur.';
    elseif ($error === '') {
        if ($editingExternalPatient) {
            $stmt = $pdo->prepare('UPDATE external_technical_patients SET branch_id=?,record_date=?,full_name=?,national_id=?,passport_no=?,phone_primary=?,phone_secondary=?,address=?,rating=?,comment=? WHERE id=?');
            $stmt->execute([$data['branch_id'] !== '' ? (int)$data['branch_id'] : null,$data['record_date'] ?: date('Y-m-d'),$data['full_name'],$data['national_id'],$data['passport_no'],$data['phone_primary'],$data['phone_secondary'],$data['address'],$data['rating'] !== '' ? (int)$data['rating'] : null,$data['comment'],$externalPatientId]);
            if ((string)($_GET['_identity_audit']??'')==='1') {
                echo '<!doctype html><html><meta charset="utf-8"><title>Kaydedildi</title><script>window.parent.postMessage({type:"vox-patient-saved"},location.origin);</script></html>';
                exit;
            }
            redirect('technical-service.php');
        }
        $stmt = $pdo->prepare('INSERT INTO external_technical_patients(branch_id,record_date,full_name,national_id,passport_no,birth_date,phone_primary,phone_secondary,address,rating,comment) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$data['branch_id'] !== '' ? (int)$data['branch_id'] : null,$data['record_date'] ?: date('Y-m-d'),$data['full_name'],$data['national_id'],$data['passport_no'],null,$data['phone_primary'],$data['phone_secondary'],$data['address'],$data['rating'] !== '' ? (int)$data['rating'] : null,$data['comment']]);
        redirect('external-technical-repair.php?id=' . (int)$pdo->lastInsertId());
    }
}
patient_header('Yeni Dış Hasta Kaydı', 'stock');
echo '<script src="'.url('assets/patient-identity.js?v=5').'" defer></script>';
?>
<link rel="stylesheet" href="<?=url('assets/classic-technical-service.css?v=20260823-4')?>">
<main class="patient-container external-patient-page"><section class="external-patient-card"><header><h1><i class="ti tabler-user-plus"></i> Yeni Dış Hasta Kaydı</h1><p>Bu kayıtlar Hasta Kartları listesinden bağımsız olarak yalnızca Teknik Servis için tutulur.</p></header><?php if (!empty($error)): ?><p class="form-error"><?=e($error)?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><h2>Temel Bilgiler</h2><div class="external-form-grid"><label><span>Şube</span><div class="icon-input"><i class="ti tabler-building"></i><select name="branch_id"><option value="">Şube seçiniz</option><?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>"<?= (string)$data['branch_id']===(string)$branch['id']?' selected':'' ?>><?=e((string)$branch['name'])?></option><?php endforeach; ?></select></div></label><label><span>Kayıt Tarihi</span><div class="icon-input"><i class="ti tabler-calendar"></i><input type="date" name="record_date" value="<?=e($data['record_date'])?>"></div></label><label><span>Ad Soyad <b>*</b></span><div class="icon-input"><i class="ti tabler-user"></i><input required name="full_name" value="<?=e($data['full_name'])?>"></div></label><label><span>T.C. Kimlik No</span><div class="icon-input"><i class="ti tabler-id"></i><input name="national_id" maxlength="11" minlength="11" pattern="[0-9]{11}" inputmode="numeric" value="<?=e($data['national_id'])?>"><input name="passport_no" aria-label="Pasaport No" maxlength="64" value="<?=e($data['passport_no'])?>" hidden></div></label><label><span>Doğum Tarihi</span><div class="icon-input"><i class="ti tabler-cake"></i><input type="date" name="birth_date" value="<?=e($data['birth_date'])?>"></div></label><label><span>Telefon 1</span><div class="icon-input"><i class="ti tabler-phone"></i><input name="phone_primary" value="<?=e($data['phone_primary'])?>"></div></label><label><span>Telefon 2</span><div class="icon-input"><i class="ti tabler-phone"></i><input name="phone_secondary" value="<?=e($data['phone_secondary'])?>"></div></label><label class="wide"><span>Adres</span><div class="icon-input textarea"><i class="ti tabler-map-pin"></i><textarea name="address"><?=e($data['address'])?></textarea></div></label><label><span>Değerlendirme</span><div class="rating-input"><?php for($i=1;$i<=5;$i++): ?><label><input type="radio" name="rating" value="<?=$i?>"<?= (string)$data['rating']===(string)$i?' checked':'' ?>><i class="ti tabler-star-filled"></i></label><?php endfor; ?></div></label><label class="wide"><span>Yorum</span><div class="icon-input"><i class="ti tabler-message"></i><input name="comment" value="<?=e($data['comment'])?>"></div></label></div><footer><button class="button"><i class="ti tabler-tools"></i> Tamir Formuna Devam Et</button></footer></form></section></main>

<script>document.addEventListener('DOMContentLoaded',()=>{const labels=[...document.querySelectorAll('.rating-input label')];const sync=()=>{const selected=labels.findIndex(label=>label.querySelector('input')?.checked);labels.forEach((label,index)=>label.classList.toggle('rating-selected',selected>=0&&index<=selected));};labels.forEach(label=>label.querySelector('input')?.addEventListener('change',sync));sync();});</script>
<script>document.addEventListener('DOMContentLoaded',()=>document.querySelector('input[name="birth_date"]')?.closest('label')?.remove());</script>
<?php if (!$editingExternalPatient): ?><script>document.addEventListener('DOMContentLoaded',()=>{const button=document.querySelector('.external-patient-card footer .button');if(button){button.innerHTML='<i class="ti tabler-device-floppy"></i>';button.title='Kaydet';button.setAttribute('aria-label','Kaydet');button.style.cssText+='width:42px;height:42px;min-width:42px;padding:0;display:inline-grid;place-items:center;';button.querySelector('i').style.fontSize='20px';}});</script><?php endif; ?>
<?php if (!$editingExternalPatient): ?><script>window.addEventListener('load',()=>{const footer=document.querySelector('.external-patient-card footer'),back=footer?.querySelector('.cancel'),save=footer?.querySelector('.button');if(!back||!save)return;const box=back.getBoundingClientRect();save.style.cssText+='width:'+Math.round(box.width)+'px!important;height:'+Math.round(box.height)+'px!important;min-width:'+Math.round(box.width)+'px!important;min-height:'+Math.round(box.height)+'px!important;padding:0!important;';});</script><?php endif; ?>
<?php if ($editingExternalPatient): ?><script>document.addEventListener('DOMContentLoaded',()=>{const title=document.querySelector('.external-patient-card h1'),description=document.querySelector('.external-patient-card header p'),button=document.querySelector('.external-patient-card footer .button');if(title)title.innerHTML='<i class="ti tabler-user-circle"></i> Dış Hasta Kartı';if(description)description.textContent='Dış hasta kartı bilgilerini güncelleyebilirsiniz.';if(button){button.innerHTML='<i class="ti tabler-device-floppy"></i>';button.title='Hasta Kartını Kaydet';button.setAttribute('aria-label','Hasta Kartını Kaydet');button.style.cssText+='width:42px;height:42px;min-width:42px;padding:0;display:inline-grid;place-items:center;';button.querySelector('i').style.fontSize='20px';}});</script><?php endif; ?>
<?php if ($editingExternalPatient): ?><script>window.addEventListener('load',()=>{const footer=document.querySelector('.external-patient-card footer'),back=footer?.querySelector('.cancel');if(!footer||!back)return;const box=back.getBoundingClientRect();footer.querySelectorAll('button').forEach(button=>button.style.cssText+='width:'+Math.round(box.width)+'px!important;height:'+Math.round(box.height)+'px!important;min-width:'+Math.round(box.width)+'px!important;min-height:'+Math.round(box.height)+'px!important;padding:0!important;');});</script><?php endif; ?>
<?php patient_footer(); ?>
