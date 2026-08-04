<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/source-bootstrap.php';
require_login();
ensure_patient_source_schema();
require __DIR__ . '/patient-layout.php';

function unit_patient_parse_money(string $value): float
{
    $value = trim(str_replace(' ', '', $value));
    if ($value === '') return 0.0;
    return str_contains($value, ',')
        ? (float)str_replace(',', '.', str_replace('.', '', $value))
        : (float)str_replace('.', '', $value);
}

$unitId = max(0, (int)($_GET['unit_id'] ?? 0));
$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS unit_patient_financials (unit_id INTEGER NOT NULL, patient_id INTEGER NOT NULL, entitlement_amount REAL NOT NULL DEFAULT 0, payment_amount REAL NOT NULL DEFAULT 0, PRIMARY KEY(unit_id,patient_id))'
    : 'CREATE TABLE IF NOT EXISTS unit_patient_financials (unit_id INT UNSIGNED NOT NULL, patient_id INT UNSIGNED NOT NULL, entitlement_amount DECIMAL(12,2) NOT NULL DEFAULT 0, payment_amount DECIMAL(12,2) NOT NULL DEFAULT 0, PRIMARY KEY(unit_id,patient_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$unitStatement = $pdo->prepare('SELECT id,code,unit_no,name,last_name FROM units WHERE id=?');
$unitStatement->execute([$unitId]);
$unit = $unitStatement->fetch();
if (!$unit) { http_response_code(404); exit('Ünite kaydı bulunamadı.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_financial') {
    verify_csrf();
    $patientId = max(0, (int)($_POST['patient_id'] ?? 0));
    $patientCheck = $pdo->prepare('SELECT 1 FROM patients WHERE id=? AND source_unit_id=?');
    $patientCheck->execute([$patientId, $unitId]);
    if (!$patientCheck->fetchColumn()) { http_response_code(404); exit('Hasta kaydı bulunamadı.'); }
    $entitlement = max(0, unit_patient_parse_money((string)($_POST['entitlement_amount'] ?? '')));
    if (array_key_exists('payment_amount', $_POST)) {
        $payment = max(0, unit_patient_parse_money((string)$_POST['payment_amount']));
    } else {
        $paymentStatement = $pdo->prepare('SELECT payment_amount FROM unit_patient_financials WHERE unit_id=? AND patient_id=?');
        $paymentStatement->execute([$unitId, $patientId]);
        $payment = (float)($paymentStatement->fetchColumn() ?: 0);
    }
    $saveFinancial = $pdo->prepare($sqlite
        ? 'INSERT INTO unit_patient_financials(unit_id,patient_id,entitlement_amount,payment_amount) VALUES(?,?,?,?) ON CONFLICT(unit_id,patient_id) DO UPDATE SET entitlement_amount=excluded.entitlement_amount,payment_amount=excluded.payment_amount'
        : 'INSERT INTO unit_patient_financials(unit_id,patient_id,entitlement_amount,payment_amount) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE entitlement_amount=VALUES(entitlement_amount),payment_amount=VALUES(payment_amount)');
    $saveFinancial->execute([$unitId, $patientId, $entitlement, $payment]);
    redirect('unit-patients.php?unit_id=' . $unitId . '&financial_saved=1');
}

$patientsStatement = $pdo->prepare("SELECT p.id,p.full_name,b.name AS branch_name,(SELECT contact_person FROM patient_services WHERE patient_id=p.id ORDER BY id DESC LIMIT 1) AS contact_person,(SELECT service_name FROM patient_services WHERE patient_id=p.id ORDER BY id DESC LIMIT 1) AS service_name,(SELECT result_name FROM patient_services WHERE patient_id=p.id ORDER BY id DESC LIMIT 1) AS result_name,(SELECT service_date FROM patient_services WHERE patient_id=p.id AND service_name='Satış' ORDER BY id DESC LIMIT 1) AS sales_date FROM patients p LEFT JOIN branches b ON b.id=p.branch_id WHERE p.source_unit_id=? ORDER BY p.full_name,p.id");
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
$financialsStatement = $pdo->prepare('SELECT patient_id,entitlement_amount,payment_amount FROM unit_patient_financials WHERE unit_id=?');
$financialsStatement->execute([$unitId]);
$financials = [];
foreach ($financialsStatement as $financial) {
    $financials[(int)$financial['patient_id']] = $financial;
}
$unitPaid = 0.0;
try {
    $unitPaidStatement = $pdo->prepare('SELECT COALESCE(SUM(payment_amount),0) FROM unit_visits WHERE unit_id=?');
    $unitPaidStatement->execute([$unitId]);
    $unitPaid = (float)$unitPaidStatement->fetchColumn();
} catch (Throwable $e) {
    // Ziyaret tablosu henüz oluşmamış eski kurulumlarda ödenen tutar sıfır gösterilir.
}

$unitName = trim((string)$unit['name'] . ' ' . (string)($unit['last_name'] ?? ''));
patient_header('Ünite Hastaları', 'patients');
?>
<style>
.unit-patients-page{width:100%;max-width:1100px;margin:0 auto;padding:46px 20px 48px}.unit-patients-card{overflow:hidden;border:1px solid var(--line);border-radius:10px;background:var(--card);box-shadow:0 3px 12px #1e283c0f}.unit-patients-head{display:flex;align-items:center;justify-content:space-between;padding:22px 24px;border-bottom:1px solid var(--line)}.unit-patients-head h2{margin:0;font-size:21px}.unit-patients-head p{margin:5px 0 0;color:var(--muted)}.unit-patients-head a{color:#16883d;text-decoration:none;font-weight:700}.unit-patients-table{width:100%;border-collapse:collapse}.unit-patients-table th,.unit-patients-table td{padding:14px 18px;border-bottom:1px solid var(--line);text-align:left}.unit-patients-table th{font-size:12px;color:var(--muted)}.unit-patients-table tbody tr[data-patient-url]{cursor:pointer}.unit-patients-table tbody tr[data-patient-url]:hover td{background:rgba(32,164,71,.06)}.unit-patient-edit{display:inline-grid;place-items:center;width:36px;height:36px;border-radius:6px;background:#20a447;color:#fff;text-decoration:none}.unit-patient-edit:hover{background:#16883d;color:#fff}.unit-patients-empty{text-align:center;color:var(--muted);padding:30px!important}@media(max-width:720px){.unit-patients-page{padding:20px 12px 30px}.unit-patients-head{padding:18px}.unit-patients-table th,.unit-patients-table td{padding:12px}.unit-patients-card{overflow:auto}.unit-patients-table{min-width:780px}}
</style>
<style>
.unit-patient-financial-input{width:118px;box-sizing:border-box;border:1px solid #d5d3de;border-radius:6px;padding:9px 10px;font:inherit}.unit-patient-financial-form{display:inline;margin:0}.unit-patient-financial-save{display:inline-grid;place-items:center;width:36px;height:36px;padding:0;border:0;border-radius:6px;background:#20a447;color:#fff;cursor:pointer}.unit-patient-financial-save:hover{background:#16883d}.unit-patient-financial-form+.unit-patient-edit{margin-left:6px}.unit-patients-success{margin:16px 24px 0;padding:12px;border-radius:6px;background:#e8f7ed;color:#16883d}
.unit-patients-page{max-width:1500px;padding-left:16px;padding-right:16px}.unit-patients-table{font-size:13px}.unit-patients-table th,.unit-patients-table td{padding:11px 12px;white-space:nowrap}.unit-patients-table th{font-size:11px}.unit-patients-table th:nth-child(6),.unit-patients-table td:nth-child(6){display:none}
</style>
<main class="patient-container unit-patients-page"><section class="unit-patients-card"><header class="unit-patients-head"><div><h2>Hasta Bilgileri</h2><p><?=e((string)$unit['code'])?> — <?=e($unitName)?></p></div><a href="<?=e(url('units.php'))?>">Ünitelere Dön</a></header><?php if (isset($_GET['financial_saved'])): ?><div class="unit-patients-success">Hakediş ve ödeme bilgileri kaydedildi.</div><?php endif; ?><table class="unit-patients-table"><thead><tr><th>SIRA</th><th>SATIŞ TARİHİ</th><th>AD SOYAD</th><th>SATIŞ FİYATI</th><th>HAKEDİŞ</th><th>ÖDEME</th><th>HİZMET ADI</th><th>SONUÇ</th><th>ŞUBE</th><th>İLGİLENEN KİŞİ</th><th>İŞLEMLER</th></tr></thead><tbody><?php foreach ($patients as $index => $patient): $patientUrl = url('patient-form.php?id=' . (int)$patient['id'] . '&return=' . rawurlencode('unit-patients.php?unit_id=' . $unitId)); $financial = $financials[(int)$patient['id']] ?? ['entitlement_amount' => 0, 'payment_amount' => 0]; $financialFormId = 'unit-financial-' . (int)$patient['id']; ?><tr data-patient-url="<?=e($patientUrl)?>"><td><?=$index + 1?></td><td><?=!empty($patient['sales_date']) ? e(format_date_tr((string)$patient['sales_date'])) : '—'?></td><td><b><?=e((string)$patient['full_name'])?></b></td><td><?=($salesTotals[(int)$patient['id']] ?? 0) > 0 ? e(number_format($salesTotals[(int)$patient['id']], 2, ',', '.')) . ' TL' : '—'?></td><td><input class="unit-patient-financial-input" form="<?=$financialFormId?>" type="text" name="entitlement_amount" data-money="true" inputmode="decimal" value="<?=e((float)$financial['entitlement_amount'] > 0 ? number_format((float)$financial['entitlement_amount'], 2, ',', '.') : '')?>" placeholder="0,00 TL" aria-label="Hakediş"></td><td><input class="unit-patient-financial-input" form="<?=$financialFormId?>" type="text" name="payment_amount" data-money="true" inputmode="decimal" value="<?=e((float)$financial['payment_amount'] > 0 ? number_format((float)$financial['payment_amount'], 2, ',', '.') : '')?>" placeholder="0,00 TL" aria-label="Ödeme"></td><td><?=e((string)($patient['service_name'] ?? '')) ?: '—'?></td><td><?=e((string)($patient['result_name'] ?? '')) ?: '—'?></td><td><?=e((string)($patient['branch_name'] ?? ''))?></td><td><?=e((string)($patient['contact_person'] ?? ''))?></td><td><form id="<?=$financialFormId?>" method="post" class="unit-patient-financial-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_financial"><input type="hidden" name="patient_id" value="<?=(int)$patient['id']?>"><button class="unit-patient-financial-save" title="Hakediş ve ödeme bilgilerini kaydet" aria-label="Hakediş ve ödeme bilgilerini kaydet"><i class="icon-base ti tabler-device-floppy"></i></button></form><a class="unit-patient-edit" href="<?=e($patientUrl)?>" title="Hasta kartını düzenle" aria-label="Hasta kartını düzenle"><i class="icon-base ti tabler-edit"></i></a></td></tr><?php endforeach; if (!$patients): ?><tr><td colspan="11" class="unit-patients-empty">Bu üniteye atanmış hasta kaydı bulunmuyor.</td></tr><?php endif; ?></tbody></table></section></main>
<script>document.querySelectorAll('tr[data-patient-url]').forEach(row=>row.addEventListener('dblclick',()=>location.href=row.dataset.patientUrl));(()=>{const table=document.querySelector('.unit-patients-table');if(!table)return;const header=table.tHead?.rows[0]?.children[5];if(header)header.textContent='ÖDENEN';table.querySelectorAll('tbody tr[data-patient-url]').forEach(row=>{const cell=row.children[5],input=cell?.querySelector('input[name="payment_amount"]');if(!cell||!input)return;const value=input.value.trim();cell.textContent=value?value+' TL':'—';});const style=document.createElement('style');style.textContent='.unit-patients-table th:nth-child(6),.unit-patients-table td:nth-child(6){display:table-cell!important}';document.head.append(style)})();</script>
<script>(()=>{const amount=<?=json_encode($unitPaid)?>,formatted=amount>0?new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(amount)+' TL':'—';document.querySelectorAll('.unit-patients-table tbody tr[data-patient-url]').forEach(row=>{const cell=row.children[5];if(cell)cell.textContent=formatted;});})();</script>
<?php patient_footer(); ?>
