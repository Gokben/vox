<?php
declare(strict_types=1);
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
$data = ['branch_id'=>'','record_date'=>date('Y-m-d'),'full_name'=>$prefillName,'national_id'=>'','birth_date'=>'','phone_primary'=>'','phone_secondary'=>'','address'=>'','rating'=>'','comment'=>''];
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
    if ($data['full_name'] === '') $error = 'Ad Soyad zorunludur.';
    else {
        if ($editingExternalPatient) {
            $stmt = $pdo->prepare('UPDATE external_technical_patients SET branch_id=?,record_date=?,full_name=?,national_id=?,phone_primary=?,phone_secondary=?,address=?,rating=?,comment=? WHERE id=?');
            $stmt->execute([$data['branch_id'] !== '' ? (int)$data['branch_id'] : null,$data['record_date'] ?: date('Y-m-d'),$data['full_name'],$data['national_id'],$data['phone_primary'],$data['phone_secondary'],$data['address'],$data['rating'] !== '' ? (int)$data['rating'] : null,$data['comment'],$externalPatientId]);
            redirect('technical-service.php');
        }
        $stmt = $pdo->prepare('INSERT INTO external_technical_patients(branch_id,record_date,full_name,national_id,birth_date,phone_primary,phone_secondary,address,rating,comment) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$data['branch_id'] !== '' ? (int)$data['branch_id'] : null,$data['record_date'] ?: date('Y-m-d'),$data['full_name'],$data['national_id'],null,$data['phone_primary'],$data['phone_secondary'],$data['address'],$data['rating'] !== '' ? (int)$data['rating'] : null,$data['comment']]);
        redirect('external-technical-repair.php?id=' . (int)$pdo->lastInsertId());
    }
}
patient_header('Yeni Dış Hasta Kaydı', 'stock');
?>
<main class="patient-container external-patient-page"><section class="external-patient-card"><header><h1><i class="ti tabler-user-plus"></i> Yeni Dış Hasta Kaydı</h1><p>Bu kayıtlar Hasta Kartları listesinden bağımsız olarak yalnızca Teknik Servis için tutulur.</p></header><?php if (!empty($error)): ?><p class="form-error"><?=e($error)?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><h2>Temel Bilgiler</h2><div class="external-form-grid"><label><span>Şube</span><div class="icon-input"><i class="ti tabler-building"></i><select name="branch_id"><option value="">Şube seçiniz</option><?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>"<?= (string)$data['branch_id']===(string)$branch['id']?' selected':'' ?>><?=e((string)$branch['name'])?></option><?php endforeach; ?></select></div></label><label><span>Kayıt Tarihi</span><div class="icon-input"><i class="ti tabler-calendar"></i><input type="date" name="record_date" value="<?=e($data['record_date'])?>"></div></label><label><span>Ad Soyad <b>*</b></span><div class="icon-input"><i class="ti tabler-user"></i><input required name="full_name" value="<?=e($data['full_name'])?>"></div></label><label><span>T.C. Kimlik No</span><div class="icon-input"><i class="ti tabler-id"></i><input name="national_id" value="<?=e($data['national_id'])?>"></div></label><label><span>Doğum Tarihi</span><div class="icon-input"><i class="ti tabler-cake"></i><input type="date" name="birth_date" value="<?=e($data['birth_date'])?>"></div></label><label><span>Telefon 1</span><div class="icon-input"><i class="ti tabler-phone"></i><input name="phone_primary" value="<?=e($data['phone_primary'])?>"></div></label><label><span>Telefon 2</span><div class="icon-input"><i class="ti tabler-phone"></i><input name="phone_secondary" value="<?=e($data['phone_secondary'])?>"></div></label><label class="wide"><span>Adres</span><div class="icon-input textarea"><i class="ti tabler-map-pin"></i><textarea name="address"><?=e($data['address'])?></textarea></div></label><label><span>Değerlendirme</span><div class="rating-input"><?php for($i=1;$i<=5;$i++): ?><label><input type="radio" name="rating" value="<?=$i?>"<?= (string)$data['rating']===(string)$i?' checked':'' ?>><i class="ti tabler-star-filled"></i></label><?php endfor; ?></div></label><label class="wide"><span>Yorum</span><div class="icon-input"><i class="ti tabler-message"></i><input name="comment" value="<?=e($data['comment'])?>"></div></label></div><footer><a class="cancel" href="<?=e(url('technical-service.php'))?>">İptal</a><button class="button"><i class="ti tabler-tools"></i> Tamir Formuna Devam Et</button></footer></form></section></main>
<style>.external-patient-page{max-width:980px!important;padding:45px 20px}.external-patient-card{background:#fff;border:1px solid #e2e1e8;border-radius:10px;box-shadow:0 3px 14px #20202c13;overflow:hidden}.external-patient-card header{padding:23px 28px;border-bottom:1px solid #e6e4ea}.external-patient-card h1{margin:0;color:#2f2b3d;font-size:21px}.external-patient-card h1 i{color:#19a94b}.external-patient-card header p{margin:6px 0 0;color:#777487}.external-patient-card form{padding:25px 28px}.external-patient-card h2{margin:0 0 20px;color:#16833d;font-size:15px;border-bottom:1px solid #e5e3ea;padding-bottom:12px}.external-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:17px 22px}.external-form-grid label>span{display:block;margin-bottom:7px;color:#4b495c;font-size:14px}.external-form-grid b{color:#e04f55}.icon-input{position:relative}.icon-input>i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#716f82;font-size:18px}.icon-input input,.icon-input select,.icon-input textarea{box-sizing:border-box;width:100%;min-height:43px;border:1px solid #d9d7e1;border-radius:7px;padding:0 12px 0 42px;font:inherit;color:#2f2b3d}.icon-input:focus-within input,.icon-input:focus-within select,.icon-input:focus-within textarea{border-color:#19a94b;box-shadow:0 0 0 2px #19a94b22;outline:0}.wide{grid-column:1/-1}.textarea i{top:16px;transform:none}.icon-input textarea{min-height:76px;padding-top:11px;resize:vertical}.rating-input{display:flex;gap:8px}.rating-input label{cursor:pointer}.rating-input input{position:absolute;opacity:0}.rating-input i{font-size:24px;color:#d8d7df}.rating-input input:checked+i{color:#f4b328}.external-patient-card footer{display:flex;justify-content:flex-end;gap:10px;margin-top:26px}.external-patient-card footer .button,.external-patient-card .cancel{display:inline-flex;align-items:center;gap:7px;border-radius:7px;padding:11px 16px;text-decoration:none}.external-patient-card .cancel{border:1px solid #d9d7e1;color:#555264}.form-error{margin:16px 28px 0;color:#d63945}@media(max-width:700px){.external-form-grid{grid-template-columns:1fr}.external-patient-page{padding:25px 12px}.external-patient-card form,.external-patient-card header{padding-left:18px;padding-right:18px}}</style>
<script>document.addEventListener('DOMContentLoaded',()=>document.querySelector('input[name="birth_date"]')?.closest('label')?.remove());</script>
<?php if (!$editingExternalPatient): ?><script>document.addEventListener('DOMContentLoaded',()=>{const button=document.querySelector('.external-patient-card footer .button');if(button){button.innerHTML='<i class="ti tabler-device-floppy"></i>';button.title='Kaydet';button.setAttribute('aria-label','Kaydet');button.style.cssText+='width:42px;height:42px;min-width:42px;padding:0;display:inline-grid;place-items:center;';button.querySelector('i').style.fontSize='20px';}});</script><?php endif; ?>
<?php if (!$editingExternalPatient): ?><script>window.addEventListener('load',()=>{const footer=document.querySelector('.external-patient-card footer'),back=footer?.querySelector('.cancel'),save=footer?.querySelector('.button');if(!back||!save)return;const box=back.getBoundingClientRect();save.style.cssText+='width:'+Math.round(box.width)+'px!important;height:'+Math.round(box.height)+'px!important;min-width:'+Math.round(box.width)+'px!important;min-height:'+Math.round(box.height)+'px!important;padding:0!important;';});</script><?php endif; ?>
<?php if ($editingExternalPatient): ?><script>document.addEventListener('DOMContentLoaded',()=>{const title=document.querySelector('.external-patient-card h1'),description=document.querySelector('.external-patient-card header p'),button=document.querySelector('.external-patient-card footer .button'),footer=document.querySelector('.external-patient-card footer');if(title)title.innerHTML='<i class="ti tabler-user-circle"></i> Dış Hasta Kartı';if(description)description.textContent='Dış hasta kartı bilgilerini güncelleyebilirsiniz.';if(button){button.innerHTML='<i class="ti tabler-device-floppy"></i>';button.title='Hasta Kartını Kaydet';button.setAttribute('aria-label','Hasta Kartını Kaydet');button.style.cssText+='width:42px;height:42px;min-width:42px;padding:0;display:inline-grid;place-items:center;';button.querySelector('i').style.fontSize='20px';}if(footer&&!footer.querySelector('.external-patient-delete')){const remove=document.createElement('button');remove.type='submit';remove.name='action';remove.value='delete_external_patient';remove.className='external-patient-delete';remove.title='Dış Hasta Kartını Sil';remove.setAttribute('aria-label','Dış Hasta Kartını Sil');remove.innerHTML='<i class="ti tabler-trash"></i>';remove.style.cssText='width:42px;height:42px;min-width:42px;padding:0;border:0;border-radius:7px;background:#ea5455;color:#fff;display:inline-grid;place-items:center;cursor:pointer;';remove.querySelector('i').style.fontSize='20px';remove.addEventListener('click',event=>{if(!confirm('Dış hasta kartı ve bağlı teknik servis kayıtları silinsin mi?'))event.preventDefault();});footer.insertBefore(remove,button);}});</script><?php endif; ?>
<?php if ($editingExternalPatient): ?><script>window.addEventListener('load',()=>{const footer=document.querySelector('.external-patient-card footer'),back=footer?.querySelector('.cancel');if(!footer||!back)return;const box=back.getBoundingClientRect();footer.querySelectorAll('button').forEach(button=>button.style.cssText+='width:'+Math.round(box.width)+'px!important;height:'+Math.round(box.height)+'px!important;min-width:'+Math.round(box.width)+'px!important;min-height:'+Math.round(box.height)+'px!important;padding:0!important;');});</script><?php endif; ?>
<?php patient_footer(); ?>
