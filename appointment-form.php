<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/appointment-bootstrap.php';
require __DIR__ . '/employee-patient-link.php';

$pdo = db();
ensure_appointment_schema($pdo);
$branches = $pdo->query('SELECT id,name FROM branches WHERE active=1 ORDER BY name')->fetchAll();
$staffNames = active_employee_names();
$appointmentId = (int)($_GET['id'] ?? 0);
$requestedType = (string)($_GET['type'] ?? 'appointment');
if (!in_array($requestedType, ['appointment', 'daily_event'], true)) $requestedType = 'appointment';
$form = ['event_type'=>$requestedType,'full_name'=>'','phone'=>'','phone_secondary'=>'','proximity_relation'=>'','proximity_relation_secondary'=>'','appointment_date'=>(string)($_GET['date'] ?? date('Y-m-d')),'appointment_time'=>'09:00','branch_id'=>'','contact_person'=>'','communication_method'=>'','result'=>'','note'=>''];
function appointment_phone(string $value): ?string {
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if ($digits === '') return '';
    if (strlen($digits) === 10 && str_starts_with($digits, '5')) $digits = '0' . $digits;
    if (!preg_match('/^0\d{10}$/', $digits)) return null;
    return substr($digits,0,4).' '.substr($digits,4,3).' '.substr($digits,7,2).' '.substr($digits,9,2);
}
if ($requestedType === 'daily_event') $form['appointment_date'] = date('Y-m-d');
if ($appointmentId > 0) {
    $existingStatement = $pdo->prepare('SELECT * FROM appointments WHERE id = ?');
    $existingStatement->execute([$appointmentId]);
    $existing = $existingStatement->fetch();
    if ($existing) {
        foreach (array_keys($form) as $field) $form[$field] = (string)($existing[$field] ?? $form[$field]);
    } else $appointmentId = 0;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($form as $field => $default) $form[$field] = trim((string)($_POST[$field] ?? $default));
    if (!in_array($form['event_type'], ['appointment', 'daily_event'], true)) $form['event_type'] = 'appointment';
    if ($form['event_type'] === 'daily_event' && $appointmentId === 0) $form['appointment_date'] = date('Y-m-d');
    foreach (['phone','phone_secondary'] as $phoneField) {
        $formattedPhone = appointment_phone($form[$phoneField]);
        if ($formattedPhone === null) $error = 'Telefon numaralarını 0554 597 26 93 biçiminde girin.';
        else $form[$phoneField] = $formattedPhone;
    }
    if ($error === '' && ($form['full_name'] === '' || $form['appointment_date'] === '' || $form['appointment_time'] === '')) $error = 'Ad Soyad, randevu tarihi ve saati zorunludur.';
    if ($error === '') {
        if ($appointmentId > 0) {
            $pdo->prepare('UPDATE appointments SET event_type=?,full_name=?,phone=?,phone_secondary=?,proximity_relation=?,proximity_relation_secondary=?,appointment_date=?,appointment_time=?,branch_id=?,contact_person=?,communication_method=?,result=?,note=? WHERE id=?')->execute([$form['event_type'],$form['full_name'],$form['phone'] ?: null,$form['phone_secondary'] ?: null,$form['proximity_relation'] ?: null,$form['proximity_relation_secondary'] ?: null,$form['appointment_date'],$form['appointment_time'],$form['branch_id'] !== '' ? (int)$form['branch_id'] : null,$form['contact_person'] ?: null,$form['communication_method'] ?: null,$form['result'] ?: null,$form['note'] ?: null,$appointmentId]);
        } else {
            $pdo->prepare('INSERT INTO appointments(event_type,full_name,phone,phone_secondary,proximity_relation,proximity_relation_secondary,appointment_date,appointment_time,branch_id,contact_person,communication_method,result,note,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$form['event_type'],$form['full_name'],$form['phone'] ?: null,$form['phone_secondary'] ?: null,$form['proximity_relation'] ?: null,$form['proximity_relation_secondary'] ?: null,$form['appointment_date'],$form['appointment_time'],$form['branch_id'] !== '' ? (int)$form['branch_id'] : null,$form['contact_person'] ?: null,$form['communication_method'] ?: null,$form['result'] ?: null,$form['note'] ?: null,(int)($_SESSION['user']['id'] ?? 0)]);
        }
        $listPage = $form['event_type'] === 'daily_event' ? 'daily-events-list.php' : 'appointment-list.php';
        header('Location: ' . url($listPage . '?month=' . substr($form['appointment_date'], 0, 7) . '&appointment_saved=1'));
        exit;
    }
}

require __DIR__ . '/patient-layout.php';
patient_header($form['event_type'] === 'daily_event' ? 'Günlük Olay Ekle' : 'Randevu Ekle', 'calendar');
?>
<main class="appointment-form-page vox-form-page"><section class="appointment-form-card"><header><h1><i class="ti tabler-calendar-plus" aria-hidden="true"></i> Randevu Bilgileri</h1></header><?php if ($error): ?><div class="appointment-error"><?=e($error)?></div><?php endif ?><form method="post" class="appointment-form vox-compact-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><label>Ad Soyad <span class="required-mark">*</span><input name="full_name" value="<?=e($form['full_name'])?>" required autocomplete="name"></label><label class="appointment-phone-row">Telefon 1<span class="appointment-phone-control"><input id="appointment-phone-primary" name="phone" value="<?=e($form['phone'])?>" inputmode="tel" maxlength="14" autocomplete="tel"><button class="appointment-proximity-toggle" type="button" title="Yakınlık derecesini aç/kapat" aria-controls="appointment-proximity-primary" aria-expanded="false"><i class="ti tabler-users"></i></button></span></label><label id="appointment-proximity-primary" class="appointment-proximity-row">Yakınlık Derecesi<input name="proximity_relation" value="<?=e($form['proximity_relation'])?>"></label><label class="appointment-phone-row">Telefon 2<span class="appointment-phone-control"><input id="appointment-phone-secondary" name="phone_secondary" value="<?=e($form['phone_secondary'])?>" inputmode="tel" maxlength="14" autocomplete="tel-national"><button class="appointment-proximity-toggle" type="button" title="Yakınlık derecesini aç/kapat" aria-controls="appointment-proximity-secondary" aria-expanded="false"><i class="ti tabler-users"></i></button></span></label><label id="appointment-proximity-secondary" class="appointment-proximity-row">Yakınlık Derecesi<input name="proximity_relation_secondary" value="<?=e($form['proximity_relation_secondary'])?>"></label><label>Randevu Tarihi <span class="required-mark">*</span><input type="date" name="appointment_date" value="<?=e($form['appointment_date'])?>" required></label><label>Randevu Saati <span class="required-mark">*</span><input type="time" name="appointment_time" value="<?=e($form['appointment_time'])?>" required></label><label>Şube<select name="branch_id"><option value="">Seçiniz</option><?php foreach ($branches as $branch): ?><option value="<?=(int)$branch['id']?>" <?=$form['branch_id']===(string)$branch['id']?'selected':''?>><?=e($branch['name'])?></option><?php endforeach ?></select></label><label>İlgilenen Kişi<select name="contact_person"><option value="">Seçiniz</option><?php foreach ($staffNames as $person): ?><option value="<?=e($person)?>" <?=$form['contact_person']===$person?'selected':''?>><?=e($person)?></option><?php endforeach ?></select></label><label class="appointment-note">Not<textarea name="note" rows="3"><?=e($form['note'])?></textarea></label><footer><span>Zorunlu alanlar <b>*</b> ile gösterilmiştir.</span><button class="vox-classic-save" type="submit">▣ Kaydet (F2)</button></footer></form></section></main>
<style>
html:has(body.vox-embedded-window) body#vox-app.vox-embedded-window{background:#dcebf8!important}
.appointment-form-page{box-sizing:border-box;width:100%!important;max-width:none!important;margin:0!important;padding:5px!important;background:#dcebf8!important;font:12px Tahoma,"Segoe UI",sans-serif!important}
.appointment-form-card{width:100%!important;margin:0!important;padding:0!important;overflow:hidden;border:1px solid #79aee0!important;border-radius:0!important;background:#dcebf8!important;box-shadow:none!important}
.appointment-form-card>header{display:flex!important;align-items:center!important;min-height:22px!important;margin:0!important;padding:0 9px!important;border:0!important;border-bottom:1px solid #79aee0!important;background:linear-gradient(#f8fcff,#c6e2fa)!important}
.appointment-form-card>header h1{margin:0!important;color:#075083!important;font:700 13px/21px Tahoma,"Segoe UI",sans-serif!important}
.appointment-form-card>header h1 i{margin-right:6px;font-size:13px!important}
.appointment-error{margin:4px!important;padding:7px 9px!important;border:1px solid #d98181!important;border-radius:0!important;background:#ffe7e7!important;color:#8e1f1f!important;font-weight:700!important}
.appointment-form{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:6px 8px!important;padding:8px!important;background:#dcebf8!important}
.appointment-form label{display:block!important;min-width:0!important;margin:0!important;padding:0!important;color:#102f45!important;font:700 11px/14px Tahoma,"Segoe UI",sans-serif!important}
.appointment-form label>.required-mark{color:#e32929!important}
.appointment-form input,.appointment-form select,.appointment-form textarea{box-sizing:border-box!important;width:100%!important;height:24px!important;min-height:24px!important;margin-top:2px!important;padding:2px 5px!important;border:1px solid #79aee0!important;border-radius:0!important;background:#fff!important;color:#102f45!important;font:12px Tahoma,"Segoe UI",sans-serif!important;box-shadow:inset 1px 1px 2px rgba(0,0,0,.05)!important}
.appointment-form input:focus,.appointment-form select:focus,.appointment-form textarea:focus{border-color:#2286cf!important;outline:1px solid #9bd0f6!important;outline-offset:0!important}
.appointment-form textarea{height:48px!important;min-height:48px!important;resize:vertical!important}
.appointment-note{grid-column:1/-1!important}
.appointment-field-control{position:relative!important;display:block!important}
.appointment-field-control i{display:none!important}
.appointment-phone-control{display:flex!important;align-items:flex-end!important;min-width:0!important}
.appointment-phone-control>.appointment-field-control{flex:1 1 auto!important;min-width:0!important}
.appointment-phone-control input{border-right:1px solid #79aee0!important}
.appointment-proximity-toggle{display:none!important}
.appointment-proximity-toggle i{font-size:16px!important}
.appointment-proximity-toggle:disabled{opacity:.45!important;cursor:not-allowed!important}
.appointment-proximity-row.is-hidden{display:none!important}
.appointment-form footer{grid-column:1/-1!important;display:flex!important;align-items:center!important;justify-content:space-between!important;min-height:38px!important;margin:2px -8px -8px!important;padding:4px 8px!important;border-top:1px solid #79aee0!important;background:linear-gradient(#e9f5ff,#cfe5f7)!important}
.appointment-form footer>span{color:#52697a!important;font:11px Tahoma,"Segoe UI",sans-serif!important}
.appointment-form footer>span b{color:#df2929!important}
.appointment-form footer .vox-classic-save{margin-left:auto!important}
@media(max-width:850px){.appointment-form{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media(max-width:560px){.appointment-form{grid-template-columns:1fr!important}.appointment-note,.appointment-form footer{grid-column:1!important}}
</style>
<script>(()=>{const form=document.querySelector('.appointment-form'),title=document.querySelector('.appointment-form-card h1');if(!form)return;if(title)title.textContent=<?=json_encode($form['event_type'] === 'daily_event' ? 'Günlük Olay' : 'Randevu', JSON_UNESCAPED_UNICODE)?>;const icons={full_name:'tabler-user',phone:'tabler-phone',phone_secondary:'tabler-phone',proximity_relation:'tabler-users',proximity_relation_secondary:'tabler-users',appointment_date:'tabler-calendar',appointment_time:'tabler-clock',branch_id:'tabler-building',contact_person:'tabler-user-check',note:'tabler-notes'};form.querySelectorAll('input[name],select[name],textarea[name]').forEach(field=>{if(field.type==='hidden'||field.parentElement?.classList.contains('appointment-field-control'))return;const control=document.createElement('span');control.className='appointment-field-control';field.parentNode.insertBefore(control,field);control.append(field);const icon=document.createElement('i');icon.className='ti '+(icons[field.name]||'tabler-pencil');icon.setAttribute('aria-hidden','true');control.append(icon);});})();</script>
<script>(()=>{const form=document.querySelector('.appointment-form');if(!form)return;const format=value=>{let digits=String(value||'').replace(/\D/g,'').slice(0,11);if(digits.length===10&&digits.startsWith('5'))digits='0'+digits;return [digits.slice(0,4),digits.slice(4,7),digits.slice(7,9),digits.slice(9,11)].filter(Boolean).join(' ')},valid=value=>value===''||/^0\d{3} \d{3} \d{2} \d{2}$/.test(value);['appointment-phone-primary','appointment-phone-secondary'].forEach(id=>{const input=document.getElementById(id);if(!input)return;input.value=format(input.value);input.addEventListener('input',()=>{input.value=format(input.value);input.setCustomValidity(valid(input.value)?'':'Telefon numarasını 0554 597 26 93 biçiminde girin.')});input.dispatchEvent(new Event('input'))});form.addEventListener('submit',event=>{for(const input of form.querySelectorAll('#appointment-phone-primary,#appointment-phone-secondary'))if(!valid(input.value)){event.preventDefault();input.reportValidity();break}});form.querySelectorAll('.appointment-proximity-toggle').forEach(button=>{const row=document.getElementById(button.getAttribute('aria-controls')),phone=button.closest('.appointment-phone-control')?.querySelector('input'),relation=row?.querySelector('input');if(!row||!phone||!relation)return;const refresh=()=>{const available=phone.value.trim()!=='';button.disabled=!available;if(!available){row.classList.add('is-hidden');relation.value='';button.setAttribute('aria-expanded','false')}else if(relation.value.trim()!==''){row.classList.remove('is-hidden');button.setAttribute('aria-expanded','true')}else row.classList.add('is-hidden')};button.addEventListener('click',()=>{const hidden=row.classList.toggle('is-hidden');button.setAttribute('aria-expanded',String(!hidden));if(!hidden)relation.focus()});phone.addEventListener('input',refresh);refresh()})})();</script>
<script>(()=>{const title=document.querySelector('.appointment-form-card h1');if(title)title.innerHTML=<?=json_encode('<i class="ti '.($form['event_type'] === 'daily_event' ? 'tabler-calendar-event' : 'tabler-calendar-plus').'" aria-hidden="true"></i> '.($form['event_type'] === 'daily_event' ? 'Günlük Olay Bilgileri' : 'Randevu Bilgileri'), JSON_UNESCAPED_UNICODE)?>;})();</script>
<?php if ($form['event_type'] === 'daily_event'): ?>
<script>(()=>{document.querySelector('.appointment-form [name="appointment_date"]')?.closest('label')?.remove();const label=document.querySelector('.appointment-form [name="appointment_time"]')?.closest('label');const text=[...(label?.childNodes||[])].find(node=>node.nodeType===Node.TEXT_NODE);if(text)text.nodeValue='Saat';})();</script>
<script>(()=>{const contactLabel=document.querySelector('.appointment-form [name="contact_person"]')?.closest('label');if(!contactLabel)return;const label=document.createElement('label');label.append(document.createTextNode('İletişim Şekli'));const select=document.createElement('select');select.name='communication_method';[['','Seçiniz'],['Telefon','Telefon'],['Ziyaret','Ziyaret']].forEach(([value,text])=>{const option=document.createElement('option');option.value=value;option.textContent=text;select.append(option);});select.value=<?=json_encode($form['communication_method'], JSON_UNESCAPED_UNICODE)?>;label.append(select);contactLabel.after(label);})();</script>
<?php endif ?>
<script>
(() => {
  if (<?=json_encode($form['event_type'] === 'daily_event')?>) return;
  const contactLabel = document.querySelector('.appointment-form [name="contact_person"]')?.closest('label');
  if (!contactLabel || document.querySelector('.appointment-form [name="result"]')) return;
  const label = document.createElement('label');
  label.append(document.createTextNode('Sonuç'));
  const select = document.createElement('select');
  select.name = 'result';
  [['', 'Seçiniz'], ['Gerçekleşti', 'Gerçekleşti'], ['Yeni Randevu', 'Yeni Randevu'], ['Takip edilecek', 'Takip edilecek'], ['İptal', 'İptal']].forEach(([value, text]) => {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = text;
    select.append(option);
  });
  select.value = <?=json_encode($form['result'], JSON_UNESCAPED_UNICODE)?>;
  label.append(select);
  contactLabel.after(label);
})();
</script>
<?php patient_footer(); ?>
