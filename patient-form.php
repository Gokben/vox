<?php
require __DIR__ . '/config.php';
require __DIR__ . '/patient-report-schema.php';
require __DIR__ . '/social-security-bootstrap.php';
require __DIR__ . '/service-type-bootstrap.php';
require __DIR__ . '/source-bootstrap.php';
require_login();
$formSetupErrors = [];
try {
    if (function_exists('ensure_branch_schema')) ensure_branch_schema();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'branch';
    error_log('patient-form.php branch schema: ' . $exception->getMessage());
}
try {
    ensure_patient_report_schema();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'report';
    error_log('patient-form.php report schema: ' . $exception->getMessage());
}
try {
    ensure_patient_service_type_schema();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'service';
    error_log('patient-form.php service schema: ' . $exception->getMessage());
}
try {
    ensure_patient_source_schema();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'source';
    error_log('patient-form.php source schema: ' . $exception->getMessage());
}
require __DIR__ . '/patient-layout.php';
require __DIR__ . '/employee-patient-link.php';
$staffNames = ['staff_cansu'=>'Cansu','staff_busra'=>'Büşra','staff_belma'=>'Belma Baysan'];
try {
    ensure_patient_staff_yeliz_schema();
    $staffNames = patient_staff_names();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'staff';
    error_log('patient-form.php staff schema: ' . $exception->getMessage());
}

$id = (int)($_GET['id'] ?? 0);
$fields = ['branch_id','record_date','full_name','national_id','phone_primary','phone_secondary','birth_date','address','social_security','report_status','service_type','service_type_id','source_id','source_detail','anamnesis','notes'];
$patient = array_fill_keys($fields, '');
$defaultRecordDate = (string)($_GET['date'] ?? '');
if (preg_match('/^20\d{2}-\d{2}-\d{2}$/', $defaultRecordDate)) $patient['record_date'] = $defaultRecordDate;
$patient['report_info'] = '';
$patient['service_location'] = '';
$hideServiceLocation = true;
$patient += ['approval'=>0,'considering'=>0,'rejected'=>0,'staff_cansu'=>0,'staff_busra'=>0,'staff_belma'=>0];
$error = '';
$patient['staff_yeliz'] = (int)($patient['staff_yeliz'] ?? 0);
$patient['staff_gunes'] = (int)($patient['staff_gunes'] ?? 0);
$patient['staff_erva'] = (int)($patient['staff_erva'] ?? 0);
$patient['staff_merve'] = (int)($patient['staff_merve'] ?? 0);
$patient['staff_seyma'] = (int)($patient['staff_seyma'] ?? 0);
$branches = [];
try {
    $branches=db()->query('SELECT id,name FROM branches WHERE active=1 ORDER BY name')->fetchAll();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'branch-options';
    error_log('patient-form.php branch options: ' . $exception->getMessage());
}
$socialSecurityOptions = [];
try {
    $socialSecurityOptions=social_security_definitions();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'social-security';
    error_log('patient-form.php social security options: ' . $exception->getMessage());
}
$serviceTypeDefinitions = [];
try {
    $serviceTypeDefinitions=service_type_definitions();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'service-options';
    error_log('patient-form.php service options: ' . $exception->getMessage());
}
$serviceTypeOptions=array_filter($serviceTypeDefinitions, static fn(array $row): bool => (int)$row['active'] === 1);
$sourceDefinitions = [];
try {
    $sourceDefinitions=source_definitions();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'source-options';
    error_log('patient-form.php source options: ' . $exception->getMessage());
}
if ($id) {
    $stmt=db()->prepare('SELECT * FROM patients WHERE id=?'); $stmt->execute([$id]); $found=$stmt->fetch();
    if (!$found) { http_response_code(404); exit('Hasta kaydı bulunamadı.'); }
    $patient=array_merge($patient,$found);
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    foreach($fields as $field) {
        $patient[$field]=trim((string)($_POST[$field]??''));
    }
    if (!in_array($patient['report_status'], ['', 'Rapor getirdi', 'Rapor getirecek', 'Rapor gerekmedi', 'Özel reçete getirdi', 'Özel reçete getirecek'], true)) {
        $error = 'Rapor alanı geçerli bir seçenek olmalıdır.';
    }
    $patient['service_type_id'] = (int)$patient['service_type_id'];
    $patient['source_id'] = (int)$patient['source_id'];
    $patient['service_type'] = '';
    if ($patient['service_type_id']) {
        $typeStatement = db()->prepare('SELECT name FROM service_type_definitions WHERE id=?');
        $typeStatement->execute([$patient['service_type_id']]);
        $patient['service_type'] = (string)($typeStatement->fetchColumn() ?: '');
        if ($patient['service_type'] === '') $error = 'Seçilen hizmet tipi bulunamadı.';
    }
    if ($patient['source_id']) {
        $sourceStatement = db()->prepare('SELECT id FROM source_definitions WHERE id=?');
        $sourceStatement->execute([$patient['source_id']]);
        if (!$sourceStatement->fetchColumn()) $error = 'Seçilen kaynak bulunamadı.';
    }
    foreach(['approval','considering','rejected','staff_cansu','staff_busra','staff_belma'] as $field) $patient[$field]=isset($_POST[$field])?1:0;
    $patient['staff_yeliz']=isset($_POST['staff_yeliz'])?1:0;
    $patient['staff_gunes']=isset($_POST['staff_gunes'])?1:0;
    $patient['staff_erva']=isset($_POST['staff_erva'])?1:0;
    $patient['staff_merve']=isset($_POST['staff_merve'])?1:0;
    $patient['staff_seyma']=isset($_POST['staff_seyma'])?1:0;
    if ($patient['full_name']==='') $error='Ad soyad alanı zorunludur.';
    elseif ($error === '') {
        $values=[]; foreach(array_merge($fields,['approval','considering','rejected','staff_cansu','staff_busra','staff_belma']) as $field) $values[$field]=$patient[$field];
        $values['staff_yeliz']=$patient['staff_yeliz'];
        $values['staff_gunes']=$patient['staff_gunes'];
        $values['staff_erva']=$patient['staff_erva'];
        $values['staff_merve']=$patient['staff_merve'];
        $values['staff_seyma']=$patient['staff_seyma'];
        if ($id) {
            $set=implode(',',array_map(fn($field)=>$field.'=?',array_keys($values)));
            $stmt=db()->prepare("UPDATE patients SET $set,updated_at=CURRENT_TIMESTAMP WHERE id=?"); $stmt->execute([...array_values($values),$id]);
        } else {
            $values['import_order']=(int)db()->query('SELECT COALESCE(MAX(import_order),0)+1 FROM patients')->fetchColumn();
            $columns=array_keys($values); $stmt=db()->prepare('INSERT INTO patients ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')'); $stmt->execute(array_values($values));
        }
        redirect('patients.php');
    }
}
start_patient_staff_ui_link($staffNames, ['staff_yeliz'=>!empty($patient['staff_yeliz']),'staff_gunes'=>!empty($patient['staff_gunes']),'staff_erva'=>!empty($patient['staff_erva']),'staff_merve'=>!empty($patient['staff_merve']),'staff_seyma'=>!empty($patient['staff_seyma'])]);
patient_header($id?'Hasta Düzenle':'Yeni Hasta', 'new');
?>
<style>
.patient-form-page{padding-top:28px!important}.vuexy-form-card{background:var(--card);border:1px solid var(--line);border-radius:8px;box-shadow:0 .25rem 1.125rem rgba(47,43,61,.1);overflow:hidden}.vuexy-form-header{display:flex;align-items:center;justify-content:space-between;min-height:70px;padding:0 24px;border-bottom:1px solid var(--line)}.vuexy-form-header h2{margin:0;font-size:20px;font-weight:500}.vuexy-icon-form{padding:10px 24px 24px}.form-section-title{margin:16px 0 8px;padding-bottom:10px;border-bottom:1px solid var(--line);font-size:14px;color:#20a447}.icon-form-row{display:grid;grid-template-columns:150px minmax(0,1fr);align-items:start;gap:0 0;margin:14px 0}.icon-form-label{padding:11px 15px 0 0;color:var(--text);font-size:14px}.required-mark{color:#e44747}.merged-input{display:flex;align-items:stretch;min-height:40px;border:1px solid #d5d3de;border-radius:6px;background:var(--card);overflow:hidden;transition:border-color .18s,box-shadow .18s}.merged-input:focus-within{border-color:#20a447;box-shadow:0 0 0 3px rgba(32,164,71,.12)}.merged-icon{display:grid;place-items:center;flex:0 0 46px;color:#686574;font-size:18px}.merged-input input,.merged-input select,.merged-input textarea{width:100%!important;height:38px!important;min-height:38px!important;margin:0!important;padding:8px 12px 8px 0!important;border:0!important;border-radius:0!important;outline:0!important;background:transparent!important;color:var(--text)!important;font:inherit!important;box-shadow:none!important}.merged-input textarea{height:76px!important;resize:vertical!important;padding-top:10px!important}.check-row{display:flex;flex-wrap:wrap;gap:10px 24px;padding:8px 0}.check-row label{display:flex!important;flex-direction:row!important;align-items:center;gap:8px;color:var(--text);font-weight:400!important}.check-row input{width:17px!important;height:17px!important;margin:0!important;accent-color:#20a447}.vuexy-form-actions{display:flex;align-items:center;gap:12px;margin:22px 0 0 150px;padding-left:0}.vuexy-form-actions .button{min-width:100px}.cancel-link{color:var(--muted);text-decoration:none}.form-alert{margin:18px 24px 0;padding:12px 14px;border-radius:6px;background:#fde8e8;color:#a62c2c}[data-theme=dark] .merged-input{background:#30334d;border-color:#565a78}[data-theme=dark] .merged-icon,[data-theme=dark] .icon-form-label{color:#fff}@media(max-width:720px){.vuexy-icon-form{padding:10px 16px 22px}.icon-form-row{grid-template-columns:1fr;gap:7px}.icon-form-label{padding:0}.vuexy-form-actions{margin-left:0}.vuexy-form-header{padding:0 16px}}
.patient-container.patient-form-page{width:100%!important;max-width:1100px!important;margin-left:auto!important;margin-right:auto!important;padding:28px 20px 48px!important}.patient-form-page .vuexy-form-card{width:100%!important}.icon-form-row:has(input[name="service_location"]),.icon-form-row:has(input[name="report_info"]),.icon-form-row:has(input[name="source_marketing"]){display:none!important}
</style>
<main class="patient-container patient-form-page"><section class="vuexy-form-card"><header class="vuexy-form-header"><h2><?=$id?'Hasta Düzenle':'Yeni Hasta Kaydı'?></h2><a class="cancel-link" href="<?=url('patients.php')?>">Listeye dön</a></header><?php if($error):?><div class="form-alert"><?=e($error)?></div><?php endif?><form class="vuexy-icon-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>">
<h3 class="form-section-title">Temel Bilgiler</h3>
<div class="icon-form-row"><label class="icon-form-label">Şube <span class="required-mark">*</span></label><div class="merged-input"><span class="merged-icon">⌂</span><select name="branch_id" required><option value="">Şube seçin</option><?php foreach($branches as $branch):?><option value="<?=(int)$branch['id']?>" <?=(int)$patient['branch_id']===(int)$branch['id']?'selected':''?>><?=e($branch['name'])?></option><?php endforeach?></select></div></div>
<div class="icon-form-row"><label class="icon-form-label">Kayıt Tarihi</label><div class="merged-input"><span class="merged-icon">▣</span><input type="date" name="record_date" value="<?=e($patient['record_date'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Ad Soyad <span class="required-mark">*</span></label><div class="merged-input"><span class="merged-icon">♙</span><input name="full_name" value="<?=e($patient['full_name'])?>" required></div></div>
<div class="icon-form-row"><label class="icon-form-label">T.C. Kimlik No</label><div class="merged-input"><span class="merged-icon">▤</span><input name="national_id" maxlength="20" value="<?=e($patient['national_id'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Doğum Tarihi</label><div class="merged-input"><span class="merged-icon">◷</span><input type="date" name="birth_date" value="<?=e($patient['birth_date'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Telefon 1</label><div class="merged-input"><span class="merged-icon">⌕</span><input name="phone_primary" value="<?=e($patient['phone_primary'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Telefon 2</label><div class="merged-input"><span class="merged-icon">⌕</span><input name="phone_secondary" value="<?=e($patient['phone_secondary'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Adres</label><div class="merged-input"><span class="merged-icon">⌂</span><textarea name="address"><?=e($patient['address'])?></textarea></div></div>
<h3 class="form-section-title">Hizmet Bilgileri</h3>
<div class="icon-form-row"><label class="icon-form-label">Sosyal Güvence</label><div class="merged-input"><span class="merged-icon">◇</span><input name="social_security" value="<?=e($patient['social_security'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Rapor Bilgisi</label><div class="merged-input"><span class="merged-icon">▧</span><input name="report_info" value="<?=e($patient['report_info'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Rapor</label><div class="merged-input"><span class="merged-icon">✓</span><select name="report_status"><option value="">Seçiniz</option><option value="Rapor getirdi" <?=$patient['report_status']==='Rapor getirdi'?'selected':''?>>Rapor getirdi</option><option value="Rapor getirecek" <?=$patient['report_status']==='Rapor getirecek'?'selected':''?>>Rapor getirecek</option><option value="Rapor gerekmedi" <?=$patient['report_status']==='Rapor gerekmedi'?'selected':''?>>Rapor gerekmedi</option><option value="Özel reçete getirdi" <?=$patient['report_status']==='Özel reçete getirdi'?'selected':''?>>Özel reçete getirdi</option><option value="Özel reçete getirecek" <?=$patient['report_status']==='Özel reçete getirecek'?'selected':''?>>Özel reçete getirecek</option></select></div></div>
<?php if (!$hideServiceLocation):?><div class="icon-form-row"><label class="icon-form-label">Hizmet Yeri / Şube</label><div class="merged-input"><span class="merged-icon">▦</span><input name="service_location" value="<?=e($patient['service_location'])?>"></div></div><?php endif?>
<div class="icon-form-row"><label class="icon-form-label">Hizmet Yeri</label><div class="merged-input"><span class="merged-icon">◎</span><select name="service_type_id"><option value="">Seçiniz</option><?php foreach($serviceTypeDefinitions as $serviceType):$isCurrent=(int)$patient['service_type_id']===(int)$serviceType['id'];if(!(int)$serviceType['active']&&!$isCurrent)continue;?><option value="<?=(int)$serviceType['id']?>" <?=$isCurrent?'selected':''?>><?=e($serviceType['name'])?><?=!(int)$serviceType['active']?' (Pasif)':''?></option><?php endforeach?></select></div></div>
<h3 class="form-section-title">Başvuru ve Açıklamalar</h3>
<div class="icon-form-row"><label class="icon-form-label">Kaynak</label><div class="merged-input"><span class="merged-icon">◉</span><select name="source_id"><option value="">Seçiniz</option><?php foreach($sourceDefinitions as $source):$isCurrent=(int)$patient['source_id']===(int)$source['id'];if(!(int)$source['active']&&!$isCurrent)continue;?><option value="<?=(int)$source['id']?>" <?=$isCurrent?'selected':''?>><?=e($source['name'])?><?=!(int)$source['active']?' (Pasif)':''?></option><?php endforeach?></select></div></div>
<div class="icon-form-row"><label class="icon-form-label">Başvuru Detayı</label><div class="merged-input"><span class="merged-icon">⋯</span><input name="source_detail" value="<?=e($patient['source_detail'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Anamnez</label><div class="merged-input"><span class="merged-icon">✚</span><textarea name="anamnesis"><?=e($patient['anamnesis'])?></textarea></div></div>
<div class="icon-form-row"><label class="icon-form-label">Açıklama</label><div class="merged-input"><span class="merged-icon">▱</span><textarea name="notes"><?=e($patient['notes'])?></textarea></div></div>
<h3 class="form-section-title">Sonuç ve İlgili Personel</h3>
<div class="icon-form-row"><span class="icon-form-label">Sonuç</span><div class="check-row"><label><input type="checkbox" name="approval" value="1" <?=$patient['approval']?'checked':''?>> Onay</label><label><input type="checkbox" name="considering" value="1" <?=$patient['considering']?'checked':''?>> Düşünecek</label><label><input type="checkbox" name="rejected" value="1" <?=$patient['rejected']?'checked':''?>> Red</label></div></div>
<div class="icon-form-row"><span class="icon-form-label">İlgili Personel</span><div class="check-row"><?php foreach($staffNames as $field=>$staffName):?><label><input type="checkbox" name="<?=e($field)?>" value="1" <?=!empty($patient[$field])?'checked':''?>> <?=e($staffName)?></label><?php endforeach?></div></div>
<div class="vuexy-form-actions"><button class="button">Kaydet</button><a class="cancel-link" href="<?=url('patients.php')?>">İptal</a></div></form></section></main>
<script>
(()=>{const input=document.querySelector('input[name="social_security"]');if(!input)return;const select=document.createElement('select');select.name='social_security';select.innerHTML='<option value="">Seçiniz</option>'+<?=json_encode(array_map(fn($item)=>['name'=>$item['name']],$socialSecurityOptions),JSON_UNESCAPED_UNICODE)?>.map(item=>'<option value="'+item.name.replace(/"/g,'&quot;')+'">'+item.name+'</option>').join('');select.value=input.value;input.replaceWith(select)})();
</script>
<?php patient_footer();
