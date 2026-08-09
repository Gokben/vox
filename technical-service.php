<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
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
    $repairDeviceData[] = ['device' => $device ?: '—', 'quantity' => $quantity ?: '—'];
}
$patients=$pdo->query('SELECT id,full_name,phone_primary FROM patients ORDER BY full_name')->fetchAll();
function repair_value(string $details,string $key): string {$data=json_decode($details,true);if($key==='repair_delivery_date')$key='repair_branch_delivery_date';$value=is_array($data)?($data[$key]??''):'';return is_array($value)?implode(', ', array_values(array_unique(array_filter(array_map('trim', $value), static fn(string $item): bool => $item !== '')))):(string)$value;}
if (($_GET['delete_error'] ?? '') === 'repair_payment') echo '<script>window.addEventListener("DOMContentLoaded",()=>alert("Bu Tamir kartına bağlı tahsilat var. Önce tahsilatı iptal etmeden kayıt silinemez."));</script>';
patient_header('Teknik Servis','stock');
?>
<main class="patient-container technical-service-page"><section class="technical-card"><header><h1><i class="ti tabler-tools"></i> Teknik Servis</h1><p>Hizmet kartlarında kaydedilmiş tamir kabul formları.</p></header><div class="technical-table-wrap"><table><thead><tr><th>KAYIT NO</th><th>TARİH</th><th>HASTA</th><th>CİHAZ</th><th>ARIZA / ŞİKAYET</th><th>TESLİM TARİHİ</th><th>İŞLEMLER</th></tr></thead><tbody><?php foreach($services as $service):$details=(string)($service['repair_details']??'');?><tr><td><?=e($service['record_no'])?></td><td><?=e(format_date_tr($service['service_date']))?></td><td><?=e($service['full_name'])?></td><td><?=e(repair_value($details,'repair_device'))?:'—'?></td><td><?=e(repair_value($details,'repair_customer_issues[]'))?:e(repair_value($details,'repair_note'))?:'—'?></td><td><?=e(format_date_tr(repair_value($details,'repair_delivery_date')))?></td><td><div class="technical-actions"><a href="<?=e(url('patient-followup.php?id='.(int)$service['patient_id'].'&edit='.(int)$service['id']))?>" title="Düzenle"><i class="ti tabler-edit"></i></a><form method="post" onsubmit="return confirm('Bu teknik servis kaydı silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="service_id" value="<?=(int)$service['id']?>"><button title="Sil"><i class="ti tabler-trash"></i></button></form></div></td></tr><?php endforeach;if(!$services):?><tr><td class="empty" colspan="7">Henüz teknik servis kaydı bulunmuyor.</td></tr><?php endif?></tbody></table></div></section></main>
<style>.technical-service-page{width:100%!important;max-width:1180px!important;min-height:100vh;margin:0 auto!important;padding:46px 20px 48px!important}.technical-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.technical-card>header{padding:22px 24px;border-bottom:1px solid #e1e2e8}.technical-card h1{margin:0 0 5px;color:#2f2b3d;font-size:21px;line-height:1.25}.technical-card h1 .ti{vertical-align:-3px;margin-right:7px}.technical-card p{margin:0;color:#7b7b8d}.technical-table-wrap{overflow:auto}.technical-card table{width:100%;min-width:900px;border-collapse:collapse}.technical-card th,.technical-card td{padding:14px 18px;border-bottom:1px solid #e1e2e8;text-align:left;white-space:nowrap}.technical-card th{font-size:12px;color:#5d5b6d}.technical-card tbody tr:hover{background:#f8fcf9}.technical-actions{display:flex;gap:8px}.technical-actions a,.technical-actions button{display:grid;place-items:center;width:40px;height:42px;padding:0;border:0;border-radius:7px;background:#19a94b;color:#fff;text-decoration:none;cursor:pointer}.technical-actions button{background:#e04f55}.empty{text-align:center;color:#7b7b8d}@media(max-width:720px){.technical-service-page{max-width:none!important;padding:92px 14px 30px!important}}</style>
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
  table.querySelector('thead th:nth-child(6)')?.replaceChildren('ŞUBEYE TESLİM');
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
  });
})();
</script>
<?php patient_footer(); ?>
