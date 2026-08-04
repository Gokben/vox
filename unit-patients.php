<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/source-bootstrap.php';
require_login();
ensure_patient_source_schema();
require __DIR__ . '/patient-layout.php';

$unitId = max(0, (int)($_GET['unit_id'] ?? 0));
$pdo = db();
$unitStatement = $pdo->prepare('SELECT id,code,unit_no,name,last_name FROM units WHERE id=?');
$unitStatement->execute([$unitId]);
$unit = $unitStatement->fetch();
if (!$unit) { http_response_code(404); exit('Ünite kaydı bulunamadı.'); }

$patientsStatement = $pdo->prepare("SELECT p.id,p.full_name,b.name AS branch_name,(SELECT contact_person FROM patient_services WHERE patient_id=p.id ORDER BY id DESC LIMIT 1) AS contact_person,(SELECT service_name FROM patient_services WHERE patient_id=p.id ORDER BY id DESC LIMIT 1) AS service_name,(SELECT result_name FROM patient_services WHERE patient_id=p.id ORDER BY id DESC LIMIT 1) AS result_name FROM patients p LEFT JOIN branches b ON b.id=p.branch_id WHERE p.source_unit_id=? ORDER BY p.full_name,p.id");
$patientsStatement->execute([$unitId]);
$patients = $patientsStatement->fetchAll();
$salesTotals = [];
if ($patients) {
    $patientIds = array_map(static fn(array $patient): int => (int)$patient['id'], $patients);
    $salesStatement = $pdo->prepare('SELECT patient_id,sales_details FROM patient_services WHERE patient_id IN (' . implode(',', array_fill(0, count($patientIds), '?')) . ") AND service_name='Satış'");
    $salesStatement->execute($patientIds);
    foreach ($salesStatement as $sale) {
        $details = json_decode((string)$sale['sales_details'], true);
        $value = (string)(is_array($details) ? ($details['sales_payment_amount'] ?? '') : '');
        $value = preg_replace('/[^0-9,.-]/u', '', $value) ?? '';
        $amount = str_contains($value, ',') ? (float)str_replace(',', '.', str_replace('.', '', $value)) : (float)$value;
        $salesTotals[(int)$sale['patient_id']] = ($salesTotals[(int)$sale['patient_id']] ?? 0) + $amount;
    }
}

$unitName = trim((string)$unit['name'] . ' ' . (string)($unit['last_name'] ?? ''));
patient_header('Ünite Hastaları', 'patients');
?>
<style>
.unit-patients-page{width:100%;max-width:1100px;margin:0 auto;padding:46px 20px 48px}.unit-patients-card{overflow:hidden;border:1px solid var(--line);border-radius:10px;background:var(--card);box-shadow:0 3px 12px #1e283c0f}.unit-patients-head{display:flex;align-items:center;justify-content:space-between;padding:22px 24px;border-bottom:1px solid var(--line)}.unit-patients-head h2{margin:0;font-size:21px}.unit-patients-head p{margin:5px 0 0;color:var(--muted)}.unit-patients-head a{color:#16883d;text-decoration:none;font-weight:700}.unit-patients-table{width:100%;border-collapse:collapse}.unit-patients-table th,.unit-patients-table td{padding:14px 18px;border-bottom:1px solid var(--line);text-align:left}.unit-patients-table th{font-size:12px;color:var(--muted)}.unit-patients-table tbody tr[data-patient-url]{cursor:pointer}.unit-patients-table tbody tr[data-patient-url]:hover td{background:rgba(32,164,71,.06)}.unit-patients-empty{text-align:center;color:var(--muted);padding:30px!important}@media(max-width:720px){.unit-patients-page{padding:20px 12px 30px}.unit-patients-head{padding:18px}.unit-patients-table th,.unit-patients-table td{padding:12px}.unit-patients-card{overflow:auto}.unit-patients-table{min-width:700px}}
</style>
<main class="patient-container unit-patients-page"><section class="unit-patients-card"><header class="unit-patients-head"><div><h2>Hasta Bilgileri</h2><p><?=e((string)$unit['code'])?> — <?=e($unitName)?></p></div><a href="<?=e(url('units.php'))?>">Ünitelere Dön</a></header><table class="unit-patients-table"><thead><tr><th>SIRA NO</th><th>AD SOYAD</th><th>SATIŞ FİYATI</th><th>HİZMET ADI</th><th>SONUÇ</th><th>ŞUBE</th><th>İLGİLENEN KİŞİ</th></tr></thead><tbody><?php foreach ($patients as $index => $patient): ?><tr data-patient-url="<?=e(url('patient-form.php?id=' . (int)$patient['id'] . '&return=' . rawurlencode('unit-patients.php?unit_id=' . $unitId)))?>"><td><?=$index + 1?></td><td><b><?=e((string)$patient['full_name'])?></b></td><td><?=($salesTotals[(int)$patient['id']] ?? 0) > 0 ? e(number_format($salesTotals[(int)$patient['id']], 2, ',', '.')) . ' TL' : '—'?></td><td><?=e((string)($patient['service_name'] ?? '')) ?: '—'?></td><td><?=e((string)($patient['result_name'] ?? '')) ?: '—'?></td><td><?=e((string)($patient['branch_name'] ?? ''))?></td><td><?=e((string)($patient['contact_person'] ?? ''))?></td></tr><?php endforeach; if (!$patients): ?><tr><td colspan="7" class="unit-patients-empty">Bu üniteye atanmış hasta kaydı bulunmuyor.</td></tr><?php endif; ?></tbody></table></section></main>
<script>document.querySelectorAll('tr[data-patient-url]').forEach(row=>row.addEventListener('dblclick',()=>location.href=row.dataset.patientUrl));</script>
<?php patient_footer(); ?>
