<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/source-bootstrap.php';
require_login();
ensure_patient_source_schema();
require __DIR__ . '/patient-layout.php';

// Saha Aksiyonları hasta kartlarından bağımsız bir fihristtir. Eski hasta
// bağlantılarının doğrudan URL ile de açılmasını engelle.
redirect('companies.php');

$companyId = max(0, (int)($_GET['company_id'] ?? 0));
$pdo = db();
$companyStatement = $pdo->prepare('SELECT id,company_name FROM companies WHERE id=?');
$companyStatement->execute([$companyId]);
$company = $companyStatement->fetch();
if (!$company) { http_response_code(404); exit('Kurum/Firma kaydı bulunamadı.'); }

$patientsStatement = $pdo->prepare("SELECT p.*, (SELECT contact_person FROM patient_services WHERE patient_id=p.id ORDER BY id DESC LIMIT 1) AS contact_person, (SELECT action_name FROM patient_services WHERE patient_id=p.id AND COALESCE(action_name,'')<>'' ORDER BY id DESC LIMIT 1) AS performed_action FROM patients p WHERE p.source_company_id=? ORDER BY p.record_date DESC,p.id DESC");
$patientsStatement->execute([$companyId]);
$patients = $patientsStatement->fetchAll();
$salesTotals = [];
if ($patients) {
    $ids = array_map(static fn(array $patient): int => (int)$patient['id'], $patients);
    $salesStatement = $pdo->prepare('SELECT patient_id,sales_details FROM patient_services WHERE patient_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ") AND service_name='Satış'");
    $salesStatement->execute($ids);
    foreach ($salesStatement as $sale) {
        $details = json_decode((string)$sale['sales_details'], true);
        $value = (string)(is_array($details) ? ($details['sales_payment_amount'] ?? '') : '');
        $value = preg_replace('/[^0-9,.-]/u', '', $value) ?? '';
        $amount = str_contains($value, ',') ? (float)str_replace(',', '.', str_replace('.', '', $value)) : (float)$value;
        $salesTotals[(int)$sale['patient_id']] = ($salesTotals[(int)$sale['patient_id']] ?? 0) + $amount;
    }
}
patient_header((string)$company['company_name'] . ' — Hasta Bilgileri', 'patients');
?>
<style>
.company-patients-page{width:100%!important;max-width:1500px!important;margin:0 auto!important;padding:28px 20px 48px!important}.company-patients-card{overflow:hidden;border:1px solid var(--line);border-radius:8px;background:var(--card);box-shadow:0 .25rem 1.125rem rgba(47,43,61,.1)}.company-patients-head{display:flex;align-items:center;justify-content:space-between;min-height:70px;padding:0 24px;border-bottom:1px solid var(--line)}.company-patients-head h2{margin:0;font-size:20px;font-weight:500}.company-patients-head a{color:#20a447;text-decoration:none;font-size:14px;font-weight:600}.company-patients-table{width:100%;min-width:1260px;border-collapse:collapse}.company-patients-table th,.company-patients-table td{padding:14px 18px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}.company-patients-table th{font-size:12px;color:var(--muted);white-space:nowrap}.company-patients-table td{font-size:13px;color:var(--text)}.company-patients-table tbody tr[data-patient-url]{cursor:pointer}.company-patients-table tbody tr[data-patient-url]:hover td{background:rgba(32,164,71,.06)}.company-patients-empty{text-align:center!important;color:var(--muted)!important;padding:28px!important}.company-patients-scroll{overflow-x:auto}@media(max-width:720px){.company-patients-page{padding:24px 12px 32px!important}.company-patients-head{padding:0 16px}.company-patients-table{min-width:900px}.company-patients-table th,.company-patients-table td{padding:12px 14px}}
.company-patients-head-actions{display:flex;align-items:center;gap:12px}.company-patients-head .company-patients-home{display:inline-grid;place-items:center;width:36px;height:36px;border-radius:6px;background:#20a447;color:#fff;font-size:19px}.company-patients-head .company-patients-home:hover{background:#16883d;color:#fff}
</style>
<main class="patient-container company-patients-page"><section class="company-patients-card"><header class="company-patients-head"><h2><?=e((string)$company['company_name'])?> — Hasta Bilgileri</h2><a href="<?=e(url('companies.php'))?>">Kurumlar/Firmalara Dön</a></header><div class="company-patients-scroll"><table class="company-patients-table"><thead><tr><th>SIRA</th><th>NO</th><th>KAYIT TARİHİ</th><th>AD SOYAD</th><th>T.C. KİMLİK NO</th><th>TELEFON</th><th>SOSYAL GÜVENCE</th><th>RAPOR</th><th>YAPILAN İŞLEM</th><th>İLGİLİ</th><th>TOPLAM SATIŞ</th></tr></thead><tbody><?php foreach($patients as $sequence => $patient):?><tr data-patient-url="<?=e(url('patient-form.php?id='.(int)$patient['id'].'&return='.rawurlencode('company-patients.php?company_id='.$companyId)))?>"><td><?=e((string)($sequence + 1))?></td><td><?=e((string)$patient['import_order'])?></td><td><?=e((string)$patient['record_date'])?></td><td><b><?=e((string)$patient['full_name'])?></b></td><td><?=e((string)$patient['national_id'])?></td><td><?=e((string)$patient['phone_primary'])?></td><td><?=e((string)$patient['social_security'])?></td><td><?=e((string)($patient['report_status'] ?? ''))?></td><td><?=e((string)($patient['performed_action'] ?? ''))?></td><td><?=e((string)($patient['contact_person'] ?? ''))?></td><td><?=($salesTotals[(int)$patient['id']] ?? 0) > 0 ? e(number_format($salesTotals[(int)$patient['id']], 2, ',', '.')) . ' TL' : '—'?></td></tr><?php endforeach;if(!$patients):?><tr><td colspan="11" class="company-patients-empty">Bu kurum/firma için hasta kaydı bulunmuyor.</td></tr><?php endif?></tbody></table></div></section></main>
<script>document.querySelectorAll('tr[data-patient-url]').forEach(row=>row.addEventListener('dblclick',()=>location.href=row.dataset.patientUrl));(()=>{const back=document.querySelector('.company-patients-head>a');if(!back)return;const actions=document.createElement('div');actions.className='company-patients-head-actions';back.before(actions);actions.append(back);const home=document.createElement('a');home.className='company-patients-home';home.href=<?=json_encode(url('index.php'))?>;home.title='Ana Sayfa';home.setAttribute('aria-label','Ana Sayfa');home.innerHTML='<i class="icon-base ti tabler-home"></i>';actions.prepend(home)})();</script>
<script>document.querySelector('.company-patients-head-actions>a:not(.company-patients-home)')?.remove();</script>
<?php patient_footer();
