<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/complaint-bootstrap.php';
require __DIR__ . '/bank-bootstrap.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS external_technical_services (id INTEGER PRIMARY KEY AUTOINCREMENT, external_patient_id INTEGER NOT NULL, record_no TEXT NOT NULL, service_date TEXT NOT NULL, device TEXT NULL, serial_no TEXT NULL, complaint TEXT NULL, technician_note TEXT NULL, delivery_date TEXT NULL, repair_details TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS external_technical_services (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, external_patient_id INT UNSIGNED NOT NULL, record_no VARCHAR(60) NOT NULL, service_date DATE NOT NULL, device VARCHAR(190) NULL, serial_no VARCHAR(190) NULL, complaint TEXT NULL, technician_note TEXT NULL, delivery_date DATE NULL, repair_details TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
try {
    $columns = $sqlite ? array_column($pdo->query('PRAGMA table_info(external_technical_services)')->fetchAll(), 'name') : array_column($pdo->query('SHOW COLUMNS FROM external_technical_services')->fetchAll(), 'Field');
    if (!in_array('repair_details', $columns, true)) $pdo->exec('ALTER TABLE external_technical_services ADD COLUMN repair_details TEXT NULL');
} catch (Throwable $exception) {
    error_log('external technical service schema: ' . $exception->getMessage());
}

$id = max(0, (int)($_GET['id'] ?? 0));
$patientStatement = $pdo->prepare('SELECT * FROM external_technical_patients WHERE id=?');
$patientStatement->execute([$id]);
$patient = $patientStatement->fetch();
if (!$patient) {
    http_response_code(404);
    exit('Dış hasta kaydı bulunamadı.');
}

$editId = max(0, (int)($_GET['edit'] ?? 0));
$repair = ['device'=>'', 'serial_no'=>'', 'complaint'=>'', 'technician_note'=>'', 'delivery_date'=>'', 'repair_details'=>''];
if ($editId) {
    $repairStatement = $pdo->prepare('SELECT * FROM external_technical_services WHERE id=? AND external_patient_id=?');
    $repairStatement->execute([$editId, $id]);
    $repair = $repairStatement->fetch() ?: [];
    if (!$repair) {
        http_response_code(404);
        exit('Teknik servis kaydı bulunamadı.');
    }
}
$savedDetails = json_decode((string)($repair['repair_details'] ?? ''), true);
if (!is_array($savedDetails)) $savedDetails = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $customerIssues = array_values(array_unique(array_filter(array_map('trim', (array)($_POST['repair_customer_issues'] ?? [])))));
    $technicianIssues = array_values(array_unique(array_filter(array_map('trim', (array)($_POST['repair_technician_issues'] ?? [])))));
    $accessories = array_values(array_unique(array_filter(array_map('trim', (array)($_POST['repair_accessories'] ?? [])))));
    $quantity = max(1, min(2, (int)($_POST['quantity'] ?? 1)));
    $details = [
        'repair_technician' => trim((string)($_POST['repair_technician'] ?? '')),
        'repair_warranty' => isset($_POST['repair_warranty']),
        'repair_accessories' => $accessories,
        'repair_quantity' => $quantity,
        'purchase_date' => trim((string)($_POST['purchase_date'] ?? '')),
        'serial_no_2' => trim((string)($_POST['serial_no_2'] ?? '')),
        'repair_customer_issues' => $customerIssues,
        'repair_technician_issues' => $technicianIssues,
        'branch_delivery_date' => trim((string)($_POST['branch_delivery_date'] ?? '')),
        'return_date' => trim((string)($_POST['return_date'] ?? '')),
        'patient_delivery_date' => trim((string)($_POST['patient_delivery_date'] ?? '')),
        'repair_service_fee_date' => trim((string)($_POST['repair_service_fee_date'] ?? '')),
        'repair_service_fee' => trim((string)($_POST['repair_service_fee'] ?? '')),
        'repair_service_fee_payment_type' => trim((string)($_POST['repair_service_fee_payment_type'] ?? '')),
        'repair_fee_bank' => trim((string)($_POST['repair_fee_bank'] ?? '')),
        'repair_fee_installment_count' => trim((string)($_POST['repair_fee_installment_count'] ?? '')),
        'repair_fee_commission_rate' => trim((string)($_POST['repair_fee_commission_rate'] ?? '')),
        'repair_fee_current_account' => trim((string)($_POST['repair_fee_current_account'] ?? '')),
        'repair_fee_term_count' => trim((string)($_POST['repair_fee_term_count'] ?? '')),
    ];
    $device = trim((string)($_POST['device'] ?? ''));
    $serial = trim((string)($_POST['serial_no'] ?? ''));
    $complaint = $customerIssues ? implode(', ', $customerIssues) : trim((string)($_POST['complaint'] ?? ''));
    $note = trim((string)($_POST['technician_note'] ?? ''));
    $deliveryDate = trim((string)($_POST['delivery_date'] ?? '')) ?: null;
    $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);
    if ($editId) {
        $save = $pdo->prepare('UPDATE external_technical_services SET device=?,serial_no=?,complaint=?,technician_note=?,delivery_date=?,repair_details=? WHERE id=? AND external_patient_id=?');
        $save->execute([$device, $serial, $complaint, $note, $deliveryDate, $detailsJson, $editId, $id]);
    } else {
        $numbers = $pdo->query("SELECT record_no FROM external_technical_services WHERE record_no LIKE 'DS-%'")->fetchAll(PDO::FETCH_COLUMN);
        $last = 1452;
        foreach ($numbers as $number) $last = max($last, (int)substr((string)$number, 3));
        $save = $pdo->prepare('INSERT INTO external_technical_services(external_patient_id,record_no,service_date,device,serial_no,complaint,technician_note,delivery_date,repair_details) VALUES(?,?,?,?,?,?,?,?,?)');
        $save->execute([$id, 'DS-' . ($last + 1), date('Y-m-d'), $device, $serial, $complaint, $note, $deliveryDate, $detailsJson]);
    }
    redirect('technical-service.php');
}

$issues = array_values(array_filter(complaint_definitions(), static fn(array $issue): bool => (int)($issue['active'] ?? 0) === 1));
$banks = array_values(bank_definitions());
$serviceAccounts = $pdo->query('SELECT title,short_name FROM current_accounts WHERE technical_service=1 ORDER BY title')->fetchAll();
$today = date('Y-m-d');
$customerIssues = (array)($savedDetails['repair_customer_issues'] ?? preg_split('/\s*,\s*/', (string)($repair['complaint'] ?? ''), -1, PREG_SPLIT_NO_EMPTY));
$technicianIssues = (array)($savedDetails['repair_technician_issues'] ?? []);

patient_header('Teknik Servis / Tamir Formu', 'stock');
?>
<main class="patient-container external-repair-page">
  <section class="external-repair-card">
    <header>
      <h1><i class="ti tabler-tools"></i> Teknik Servis / Tamir Formu</h1>
      <p><?=e((string)$patient['full_name'])?> · Dış Hasta</p>
    </header>
    <form method="post" class="external-tabs-form">
      <input type="hidden" name="csrf" value="<?=csrf()?>">
      <div class="card form-tabs-card">
        <div class="card-header px-0 pt-0">
          <div class="nav-align-top">
            <ul class="nav nav-tabs" role="tablist">
              <li class="nav-item"><button class="nav-link active" type="button" data-tab="general"><i class="ti tabler-settings"></i><span>Genel</span></button></li>
              <li class="nav-item"><button class="nav-link" type="button" data-tab="complaint"><i class="ti tabler-message-report"></i><span>Şikayet / Arıza</span></button></li>
              <li class="nav-item"><button class="nav-link" type="button" data-tab="delivery"><i class="ti tabler-truck-delivery"></i><span>Teslim</span></button></li>
              <li class="nav-item"><button class="nav-link" type="button" data-tab="fee"><i class="ti tabler-cash"></i><span>Hizmet bedeli</span></button></li>
            </ul>
          </div>
        </div>
        <div class="card-body">
          <div class="tab-content p-0">
            <section class="tab-pane active show" data-panel="general">
              <div class="form-row two">
                <label class="service-select-line">Servis Seç<select class="form-select" name="repair_technician"><option value="">Servis seçiniz</option><?php foreach($serviceAccounts as $service):$label=(string)($service['short_name'] ?: $service['title']);?><option value="<?=e((string)$service['title'])?>" <?=((string)($savedDetails['repair_technician'] ?? '')===(string)$service['title'])?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label>
                <label class="checkbox-line"><input type="checkbox" name="repair_warranty" <?=!empty($savedDetails['repair_warranty'])?'checked':''?>> Garanti kapsamında</label>
              </div>
              <h3>Aksesuarlar</h3>
              <div class="checkbox-group"><?php foreach(['Cihaz Kutusu','Şarj Cihazı','Kulak Kalıbı','Receiver','Dome','Hortum','Drop'] as $accessory):?><label><input type="checkbox" name="repair_accessories[]" value="<?=e($accessory)?>" <?=in_array($accessory,(array)($savedDetails['repair_accessories'] ?? []),true)?'checked':''?>> <?=e($accessory)?></label><?php endforeach?></div>
              <div class="form-row device-row">
                <label>Hastanın Cihazı<input class="form-control" name="device" required value="<?=e((string)($repair['device'] ?? ''))?>"></label>
                <label>Adet<input class="form-control" type="number" name="quantity" min="1" max="2" value="<?=e((string)($savedDetails['repair_quantity'] ?? 1))?>"></label>
                <label>Alım Tarihi<input class="form-control" type="date" name="purchase_date" value="<?=e((string)($savedDetails['purchase_date'] ?? ($repair['service_date'] ?? '')))?>"></label>
              </div>
              <div class="form-row two serial-row">
                <label>Seri No 1<input class="form-control" name="serial_no" value="<?=e((string)($repair['serial_no'] ?? ''))?>"></label>
                <label>Seri No 2<input class="form-control" name="serial_no_2" value="<?=e((string)($savedDetails['serial_no_2'] ?? ''))?>"></label>
              </div>
            </section>
            <section class="tab-pane" data-panel="complaint">
              <div class="issue-table">
                <div class="issue-head"><span></span><span>Müşteri</span><span>Teknisyen</span></div>
                <?php foreach($issues as $issue):$name=(string)$issue['name'];?><label class="issue-row"><span><?=e($name)?></span><input type="checkbox" name="repair_customer_issues[]" value="<?=e($name)?>" <?=in_array($name,$customerIssues,true)?'checked':''?>><input type="checkbox" name="repair_technician_issues[]" value="<?=e($name)?>" <?=in_array($name,$technicianIssues,true)?'checked':''?>></label><?php endforeach?>
              </div>
              <label class="note-field">Açıklama<textarea class="form-control" name="technician_note"><?=e((string)($repair['technician_note'] ?? ''))?></textarea></label>
              <textarea name="complaint" hidden><?=e((string)($repair['complaint'] ?? ''))?></textarea>
            </section>
            <section class="tab-pane" data-panel="delivery">
              <div class="form-row two">
                <label>Şubeye Teslim<input class="form-control" type="date" name="branch_delivery_date" value="<?=e((string)($savedDetails['branch_delivery_date'] ?? $today))?>"></label>
                <label>Servise Teslim<input class="form-control" type="date" name="delivery_date" value="<?=e((string)($repair['delivery_date'] ?? ''))?>"></label>
                <label>Servisten Dönüş<input class="form-control" type="date" name="return_date" value="<?=e((string)($savedDetails['return_date'] ?? ''))?>"></label>
                <label>Hastaya Teslim<input class="form-control" type="date" name="patient_delivery_date" value="<?=e((string)($savedDetails['patient_delivery_date'] ?? ''))?>"></label>
              </div>
            </section>
            <section class="tab-pane" data-panel="fee">
              <div class="form-row two">
                <label>Tarih<input class="form-control" type="date" name="repair_service_fee_date" value="<?=e((string)($savedDetails['repair_service_fee_date'] ?? $today))?>"></label>
                <label>Ücret<input class="form-control" name="repair_service_fee" inputmode="decimal" value="<?=e((string)($savedDetails['repair_service_fee'] ?? ''))?>"></label>
              </div>
              <label class="payment-field">Ödeme Şekli<select class="form-select" name="repair_service_fee_payment_type"><option value="">Seçiniz</option><?php foreach(['Nakit','EFT / Havale','Kredi Kartı','Mail Order','Vadeli'] as $payment):?><option <?=((string)($savedDetails['repair_service_fee_payment_type'] ?? '')===$payment)?'selected':''?>><?=e($payment)?></option><?php endforeach?></select></label>
              <div id="payment-extra" class="form-row two"></div>
            </section>
          </div>
        </div>
      </div>
      <footer><a href="<?=e(url('technical-service.php'))?>" title="İptal"><i class="ti tabler-arrow-back-up"></i></a><button class="button" title="Tamir Formunu Kaydet" aria-label="Tamir Formunu Kaydet"><i class="ti tabler-device-floppy"></i></button></footer>
    </form>
  </section>
</main>
<style>
.external-repair-page{max-width:1000px!important;padding:38px 20px}.external-repair-card{background:#fff;border:1px solid #e2e1e8;border-radius:10px;box-shadow:0 3px 14px #20202c13;overflow:hidden}.external-repair-card>header{padding:23px 28px;border-bottom:1px solid #e6e4ea}.external-repair-card h1{margin:0;font-size:21px}.external-repair-card h1 i{color:#19a94b}.external-repair-card header p{margin:6px 0 0;color:#777487}.external-tabs-form{padding:26px 28px}.form-tabs-card{margin:0;border:1px solid #e6e5eb;border-radius:8px;overflow:hidden;box-shadow:0 3px 10px #20202c0c}.form-tabs-card .card-header{border-bottom:1px solid #dbdae3}.form-tabs-card .nav-tabs{display:flex;margin:0;padding:0 18px;border:0;gap:0}.form-tabs-card .nav-item{list-style:none}.form-tabs-card .nav-link{display:block;border:0;border-bottom:2px solid transparent;background:transparent;padding:15px 18px 13px;color:#777487;font:500 14px inherit;cursor:pointer}.form-tabs-card .nav-link i{margin-right:6px;font-size:18px;vertical-align:-3px}.form-tabs-card .nav-link.active{color:#19a94b;border-color:#19a94b}.form-tabs-card .card-body{padding:26px 28px}.tab-pane{display:none}.tab-pane.active{display:block}.form-row{display:grid;gap:18px}.form-row.two{grid-template-columns:repeat(2,minmax(0,1fr))}.form-row.device-row{grid-template-columns:2fr .6fr 1.15fr}.form-tabs-card label{display:flex;flex-direction:column;gap:7px;color:#4b495c;font-size:14px}.form-tabs-card .form-control,.form-tabs-card .form-select{box-sizing:border-box;width:100%;min-height:42px;padding:9px 12px;border:1px solid #d9d7e1;border-radius:7px;background:#fff;color:#2f2b3d;font:inherit}.form-tabs-card textarea.form-control{min-height:110px;resize:vertical}.form-tabs-card .checkbox-line{flex-direction:row;align-items:center;padding-top:28px;gap:7px}.form-tabs-card input[type=checkbox]{width:17px;min-height:17px;margin:0;padding:0}.form-tabs-card h3{margin:20px 0 14px;font-size:15px;color:#3c394d}.checkbox-group{display:flex;justify-content:center;flex-wrap:wrap;gap:12px 28px;margin-bottom:22px}.checkbox-group label{flex-direction:row;align-items:center;gap:7px}.issue-table{margin:0;border:0}.issue-head,.issue-row{display:grid;grid-template-columns:minmax(0,1fr) 92px 92px;align-items:center;gap:12px;padding:10px 12px;border-bottom:1px solid #ecebf0}.issue-head{padding-top:0;color:#777487;font-size:13px;text-align:center}.issue-head span:first-child,.issue-row span{text-align:left}.issue-row input{justify-self:center}.note-field{display:flex;margin-top:20px}.payment-field{display:flex;max-width:420px;margin-top:18px}.external-tabs-form footer{display:flex;justify-content:flex-end;gap:10px;margin-top:26px}.external-tabs-form footer a,.external-tabs-form footer button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:0;border-radius:7px;text-decoration:none}.external-tabs-form footer a{width:42px;background:#ea5455;color:#fff}.external-tabs-form footer button{gap:7px;padding:0 16px}.external-tabs-form footer button i{font-size:18px}@media(max-width:700px){.external-repair-page{padding:22px 12px}.external-tabs-form{padding:18px}.form-tabs-card .nav-tabs{overflow-x:auto;padding:0 8px}.form-tabs-card .nav-link{white-space:nowrap;padding-left:12px;padding-right:12px}.form-tabs-card .card-body{padding:20px 16px}.form-row.two,.form-row.device-row{grid-template-columns:1fr}.issue-head,.issue-row{grid-template-columns:minmax(0,1fr) 58px 58px}.checkbox-group{justify-content:flex-start;gap:12px 18px}}
</style>
<style>
.form-tabs-card .issue-head,.form-tabs-card .issue-row{display:grid!important;grid-template-columns:minmax(0,1fr) 92px 92px!important;align-items:center!important;gap:12px!important;text-align:left!important}.form-tabs-card .issue-head{color:#777487!important;font-size:13px!important;text-align:center!important}.form-tabs-card .issue-head span:first-child,.form-tabs-card .issue-row span{text-align:left!important}.form-tabs-card .issue-row input{justify-self:center!important;width:17px!important;min-width:17px!important;height:17px!important;min-height:17px!important;margin:0!important;padding:0!important}@media(max-width:700px){.form-tabs-card .issue-head,.form-tabs-card .issue-row{grid-template-columns:minmax(0,1fr) 58px 58px!important}}
</style>
<style>
.form-tabs-card .service-select-line{flex-direction:row!important;align-items:center!important;gap:12px!important;padding-top:28px!important;white-space:nowrap}.form-tabs-card .service-select-line select{flex:1!important}@media(max-width:700px){.form-tabs-card .service-select-line{padding-top:0!important}}
</style>
<style>.form-tabs-card .serial-row{margin-top:16px!important}.form-tabs-card .serial-control{display:flex!important;align-items:center!important;gap:8px!important}.form-tabs-card .serial-control input[type=checkbox]{flex:0 0 17px!important;width:17px!important;height:17px!important;min-height:17px!important}.form-tabs-card .serial-control .form-control{flex:1!important}.external-tabs-form footer a,.external-tabs-form footer button{width:42px!important;padding:0!important}.external-tabs-form footer a i,.external-tabs-form footer button i{font-size:20px!important;line-height:1!important}</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const form=document.querySelector('.external-tabs-form');
  form?.addEventListener('submit',event=>{const quantity=Math.max(1,Math.min(2,Number(form.querySelector('[name="quantity"]')?.value)||1));const first=form.querySelector('[name="serial_no"]')?.value.trim()||'',second=form.querySelector('[name="serial_no_2"]')?.value.trim()||'',serialCount=[first,second].filter(Boolean).length;if(serialCount===quantity)return;event.preventDefault();alert('Adet bilgisi ile doldurulmuş seri numarası sayısı aynı olmalıdır. Adet: '+quantity+', girilen seri numarası: '+serialCount+'.');(serialCount<quantity?(!first?form.querySelector('[name="serial_no"]'):form.querySelector('[name="serial_no_2"]')):form.querySelector('[name="serial_no_2"]'))?.focus();});
  form?.querySelectorAll('[data-tab]').forEach(button=>button.addEventListener('click',()=>{
    const tab=button.dataset.tab;
    form.querySelectorAll('[data-tab],.tab-pane').forEach(item=>item.classList.remove('active'));
    button.classList.add('active');
    form.querySelector('.tab-pane[data-panel="'+tab+'"]').classList.add('active');
  }));
  const payment=form?.querySelector('[name="repair_service_fee_payment_type"]');
  const extra=document.getElementById('payment-extra');
  const saved=<?=json_encode($savedDetails, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const banks=<?=json_encode($banks, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const field=(label,name,type='text',value='')=>'<label>'+label+'<input class="form-control" type="'+type+'" name="'+name+'" value="'+String(value).replace(/"/g,'&quot;')+'"></label>';
  const renderPaymentExtra=()=>{if(!payment||!extra)return;extra.innerHTML='';if(payment.value==='Kredi Kartı'){const label=document.createElement('label'),select=document.createElement('select');label.textContent='Banka';select.name='repair_fee_bank';select.className='form-select';select.append(new Option('Banka seçiniz',''));banks.forEach(bank=>select.append(new Option(bank.name,bank.name)));select.value=saved.repair_fee_bank||'';label.append(select);extra.append(label);extra.insertAdjacentHTML('beforeend',field('Taksit Sayısı','repair_fee_installment_count','number',saved.repair_fee_installment_count||'')+field('Komisyon Oranı','repair_fee_commission_rate','text',saved.repair_fee_commission_rate||''));}if(payment.value==='Mail Order')extra.innerHTML=field('Cari Hesap','repair_fee_current_account','text',saved.repair_fee_current_account||'');if(payment.value==='Vadeli')extra.innerHTML=field('Vade Sayısı','repair_fee_term_count','number',saved.repair_fee_term_count||'');};
  payment?.addEventListener('change',renderPaymentExtra);renderPaymentExtra();
});
</script>
<?php patient_footer(); ?>
