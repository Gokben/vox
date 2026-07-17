<?php
require __DIR__ . '/config.php';
require __DIR__ . '/social-security-bootstrap.php';
require_login();
if (function_exists('ensure_branch_schema')) ensure_branch_schema();
require __DIR__ . '/patient-layout.php';
require __DIR__ . '/employee-patient-link.php';
ensure_patient_staff_yeliz_schema();
$staffNames = patient_staff_names();

$id = (int)($_GET['id'] ?? 0);
$fields = ['branch_id','record_date','full_name','national_id','phone_primary','phone_secondary','birth_date','address','social_security','report_info','service_location','service_type','source_primary','source_marketing','source_detail','anamnesis','notes'];
$patient = array_fill_keys($fields, '');
$patient += ['approval'=>0,'considering'=>0,'rejected'=>0,'staff_cansu'=>0,'staff_busra'=>0,'staff_belma'=>0];
$error = '';
$patient['staff_yeliz'] = (int)($patient['staff_yeliz'] ?? 0);
$patient['staff_gunes'] = (int)($patient['staff_gunes'] ?? 0);
$patient['staff_erva'] = (int)($patient['staff_erva'] ?? 0);
$patient['staff_merve'] = (int)($patient['staff_merve'] ?? 0);
$patient['staff_seyma'] = (int)($patient['staff_seyma'] ?? 0);
$branches=db()->query('SELECT id,name FROM branches WHERE active=1 ORDER BY name')->fetchAll();
$socialSecurityOptions=social_security_definitions();
if ($id) {
    $stmt=db()->prepare('SELECT * FROM patients WHERE id=?'); $stmt->execute([$id]); $found=$stmt->fetch();
    if (!$found) { http_response_code(404); exit('Hasta kaydı bulunamadı.'); }
    $patient=array_merge($patient,$found);
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    foreach($fields as $field) $patient[$field]=trim((string)($_POST[$field]??''));
    foreach(['approval','considering','rejected','staff_cansu','staff_busra','staff_belma'] as $field) $patient[$field]=isset($_POST[$field])?1:0;
    $patient['staff_yeliz']=isset($_POST['staff_yeliz'])?1:0;
    $patient['staff_gunes']=isset($_POST['staff_gunes'])?1:0;
    $patient['staff_erva']=isset($_POST['staff_erva'])?1:0;
    $patient['staff_merve']=isset($_POST['staff_merve'])?1:0;
    $patient['staff_seyma']=isset($_POST['staff_seyma'])?1:0;
    if ($patient['full_name']==='') $error='Ad soyad alanı zorunludur.';
    else {
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
.patient-container.patient-form-page{width:100%!important;max-width:1100px!important;margin-left:auto!important;margin-right:auto!important;padding:28px 20px 48px!important}.patient-form-page .vuexy-form-card{width:100%!important}
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
<div class="icon-form-row"><label class="icon-form-label">Hizmet Yeri / Şube</label><div class="merged-input"><span class="merged-icon">▦</span><input name="service_location" value="<?=e($patient['service_location'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Hizmet Türü</label><div class="merged-input"><span class="merged-icon">◎</span><input name="service_type" value="<?=e($patient['service_type'])?>"></div></div>
<h3 class="form-section-title">Başvuru ve Açıklamalar</h3>
<div class="icon-form-row"><label class="icon-form-label">Başvuru Kaynağı</label><div class="merged-input"><span class="merged-icon">↗</span><input name="source_primary" value="<?=e($patient['source_primary'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Pazarlama</label><div class="merged-input"><span class="merged-icon">◉</span><input name="source_marketing" value="<?=e($patient['source_marketing'])?>"></div></div>
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
