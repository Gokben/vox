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
<main class="appointment-form-page"><section class="appointment-form-card"><header><h1>Randevu Ekle</h1><a href="<?=e(url('calendar.php?month='.substr($form['appointment_date'],0,7)))?>">Takvime Dön</a></header><?php if ($error): ?><div class="appointment-error"><?=e($error)?></div><?php endif ?><form method="post" class="appointment-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><label>Ad Soyad<input name="full_name" value="<?=e($form['full_name'])?>" required autocomplete="name"></label><label class="appointment-phone-row">Telefon 1<span class="appointment-phone-control"><input id="appointment-phone-primary" name="phone" value="<?=e($form['phone'])?>" inputmode="tel" maxlength="14" autocomplete="tel"><button class="appointment-proximity-toggle" type="button" title="Yakınlık derecesini aç/kapat" aria-controls="appointment-proximity-primary" aria-expanded="false"><i class="ti tabler-users"></i></button></span></label><label id="appointment-proximity-primary" class="appointment-proximity-row">Yakınlık Derecesi<input name="proximity_relation" value="<?=e($form['proximity_relation'])?>"></label><label class="appointment-phone-row">Telefon 2<span class="appointment-phone-control"><input id="appointment-phone-secondary" name="phone_secondary" value="<?=e($form['phone_secondary'])?>" inputmode="tel" maxlength="14" autocomplete="tel-national"><button class="appointment-proximity-toggle" type="button" title="Yakınlık derecesini aç/kapat" aria-controls="appointment-proximity-secondary" aria-expanded="false"><i class="ti tabler-users"></i></button></span></label><label id="appointment-proximity-secondary" class="appointment-proximity-row">Yakınlık Derecesi<input name="proximity_relation_secondary" value="<?=e($form['proximity_relation_secondary'])?>"></label><label>Randevu Tarihi<input type="date" name="appointment_date" value="<?=e($form['appointment_date'])?>" required></label><label>Randevu Saati<input type="time" name="appointment_time" value="<?=e($form['appointment_time'])?>" required></label><label>Şube<select name="branch_id"><option value="">Seçiniz</option><?php foreach ($branches as $branch): ?><option value="<?=(int)$branch['id']?>" <?=$form['branch_id']===(string)$branch['id']?'selected':''?>><?=e($branch['name'])?></option><?php endforeach ?></select></label><label>İlgilenen Kişi<select name="contact_person"><option value="">Seçiniz</option><?php foreach ($staffNames as $person): ?><option value="<?=e($person)?>" <?=$form['contact_person']===$person?'selected':''?>><?=e($person)?></option><?php endforeach ?></select></label><label class="appointment-note">Not<textarea name="note" rows="4"><?=e($form['note'])?></textarea></label><footer><a href="<?=e(url('calendar.php?month='.substr($form['appointment_date'],0,7)))?>">İptal</a><button type="submit">Kaydet</button></footer></form></section></main>
<style>.appointment-form-page{max-width:820px;margin:0 auto;padding:48px 24px}.appointment-form-card{padding:28px;border:1px solid var(--line);border-radius:10px;background:var(--card);box-shadow:0 3px 12px #1e283c0f}.appointment-form-card header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}.appointment-form-card h1{margin:0;font-size:22px}.appointment-form-card header a{color:#20a447;text-decoration:none;font-weight:700}.appointment-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.appointment-form label{display:flex;flex-direction:column;gap:7px;color:var(--text);font-size:14px}.appointment-form input,.appointment-form select,.appointment-form textarea{min-height:43px;padding:10px 12px;border:1px solid var(--line);border-radius:7px;background:transparent;color:var(--text);font:inherit}.appointment-form textarea{min-height:96px;resize:vertical}.appointment-note,.appointment-form footer{grid-column:1/-1}.appointment-form footer{display:flex;justify-content:flex-end;gap:10px;margin-top:6px}.appointment-form footer a,.appointment-form button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 18px;border:0;border-radius:7px;text-decoration:none;font:inherit;font-weight:700;cursor:pointer}.appointment-form footer a{background:#eef0f5;color:#2f2b3d}.appointment-form button{background:#20a447;color:#fff}.appointment-error{margin:-8px 0 18px;padding:12px;border-radius:7px;background:#ffe7e7;color:#a31d1d}@media(max-width:640px){.appointment-form-page{padding:28px 14px}.appointment-form{grid-template-columns:1fr}.appointment-form-card{padding:20px}}</style>
<style>
.appointment-form-page{max-width:none!important;margin:0!important;padding:0 20px 40px!important}.appointment-form-card{padding:20px!important;border-radius:0!important;border-width:1px 0!important;box-shadow:none!important}.appointment-form-card header{margin:0 0 12px!important;padding:0 0 10px!important;border-bottom:1px solid var(--line)}.appointment-form-card header h1{color:#19a94b!important;font-size:14px!important;font-weight:700!important}.appointment-form-card header a{font-size:13px}.appointment-form{display:block!important}.appointment-form label{display:grid!important;grid-template-columns:135px minmax(0,1fr);align-items:center;gap:0 0!important;margin:0!important;padding:6px 0!important;font-size:14px!important}.appointment-form label::first-line{color:#2f2b3d}.appointment-form input,.appointment-form select,.appointment-form textarea{width:100%;min-height:38px!important;box-sizing:border-box;border-color:#d8d8e2!important;border-radius:6px!important}.appointment-form textarea{min-height:70px!important}.appointment-form .appointment-note{align-items:start}.appointment-form .appointment-note textarea{margin-top:0}.appointment-form footer{display:flex!important;justify-content:flex-end!important;gap:10px!important;margin:18px 0 0 135px!important;padding-top:14px!important;border-top:1px solid var(--line)}.appointment-field-control{position:relative;display:block}.appointment-field-control i{position:absolute;top:50%;left:13px;transform:translateY(-50%);color:#737586;font-size:17px;pointer-events:none}.appointment-field-control input,.appointment-field-control select{padding-left:40px!important}.appointment-note .appointment-field-control i{top:16px;transform:none}.appointment-note .appointment-field-control textarea{padding-left:40px!important}.appointment-phone-control{display:flex;align-items:stretch;min-width:0}.appointment-phone-control .appointment-field-control{flex:1}.appointment-phone-control input{border-radius:6px 0 0 6px!important}.appointment-proximity-toggle{flex:0 0 48px;min-height:38px!important;padding:0!important;border:1px solid #d8d8e2!important;border-left:0!important;border-radius:0 6px 6px 0!important;background:#eef8f1!important;color:#19a94b!important}.appointment-proximity-toggle:disabled{opacity:.45;cursor:not-allowed}.appointment-proximity-toggle i{font-size:19px}.appointment-proximity-row.is-hidden{display:none!important}@media(max-width:640px){.appointment-form label{grid-template-columns:1fr!important;gap:6px!important}.appointment-form footer{margin-left:0!important}}
</style>
<script>(()=>{const form=document.querySelector('.appointment-form'),title=document.querySelector('.appointment-form-card h1');if(!form)return;if(title)title.textContent=<?=json_encode($form['event_type'] === 'daily_event' ? 'Günlük Olay' : 'Randevu', JSON_UNESCAPED_UNICODE)?>;const icons={full_name:'tabler-user',phone:'tabler-phone',phone_secondary:'tabler-phone',proximity_relation:'tabler-users',proximity_relation_secondary:'tabler-users',appointment_date:'tabler-calendar',appointment_time:'tabler-clock',branch_id:'tabler-building',contact_person:'tabler-user-check',note:'tabler-notes'};form.querySelectorAll('input[name],select[name],textarea[name]').forEach(field=>{if(field.type==='hidden'||field.parentElement?.classList.contains('appointment-field-control'))return;const control=document.createElement('span');control.className='appointment-field-control';field.parentNode.insertBefore(control,field);control.append(field);const icon=document.createElement('i');icon.className='ti '+(icons[field.name]||'tabler-pencil');icon.setAttribute('aria-hidden','true');control.append(icon);});})();</script>
<script>(()=>{const form=document.querySelector('.appointment-form');if(!form)return;const format=value=>{let digits=String(value||'').replace(/\D/g,'').slice(0,11);if(digits.length===10&&digits.startsWith('5'))digits='0'+digits;return [digits.slice(0,4),digits.slice(4,7),digits.slice(7,9),digits.slice(9,11)].filter(Boolean).join(' ')},valid=value=>value===''||/^0\d{3} \d{3} \d{2} \d{2}$/.test(value);['appointment-phone-primary','appointment-phone-secondary'].forEach(id=>{const input=document.getElementById(id);if(!input)return;input.value=format(input.value);input.addEventListener('input',()=>{input.value=format(input.value);input.setCustomValidity(valid(input.value)?'':'Telefon numarasını 0554 597 26 93 biçiminde girin.')});input.dispatchEvent(new Event('input'))});form.addEventListener('submit',event=>{for(const input of form.querySelectorAll('#appointment-phone-primary,#appointment-phone-secondary'))if(!valid(input.value)){event.preventDefault();input.reportValidity();break}});form.querySelectorAll('.appointment-proximity-toggle').forEach(button=>{const row=document.getElementById(button.getAttribute('aria-controls')),phone=button.closest('.appointment-phone-control')?.querySelector('input'),relation=row?.querySelector('input');if(!row||!phone||!relation)return;const refresh=()=>{const available=phone.value.trim()!=='';button.disabled=!available;if(!available){row.classList.add('is-hidden');relation.value='';button.setAttribute('aria-expanded','false')}else if(relation.value.trim()!==''){row.classList.remove('is-hidden');button.setAttribute('aria-expanded','true')}else row.classList.add('is-hidden')};button.addEventListener('click',()=>{const hidden=row.classList.toggle('is-hidden');button.setAttribute('aria-expanded',String(!hidden));if(!hidden)relation.focus()});phone.addEventListener('input',refresh);refresh()})})();</script>
<style>
.appointment-form-page{max-width:920px!important;margin:0 auto!important;padding:96px 32px 48px!important}
@media(max-width:640px){.appointment-form-page{padding:92px 14px 30px!important}}
</style>
<script>document.querySelector('.appointment-form-page')?.classList.add('patient-container');</script>
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
