<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/service-type-bootstrap.php';
require_login();

$extendedSchemaReady = true;
try {
    ensure_patient_service_type_schema();
} catch (Throwable $exception) {
    $extendedSchemaReady = false;
    error_log('patient-results.php schema: ' . $exception->getMessage());
}
require __DIR__ . '/patient-layout.php';

$result = $_GET['result'] ?? 'all';
if (!in_array($result, ['all', 'approved', 'considering', 'rejected', 'none'], true)) $result = 'all';
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$resultConditions = [
    'approved' => 'COALESCE(patients.approval,0)=1',
    'considering' => 'COALESCE(patients.approval,0)=0 AND COALESCE(patients.considering,0)=1',
    'rejected' => 'COALESCE(patients.approval,0)=0 AND COALESCE(patients.considering,0)=0 AND COALESCE(patients.rejected,0)=1',
    'none' => 'COALESCE(patients.approval,0)=0 AND COALESCE(patients.considering,0)=0 AND COALESCE(patients.rejected,0)=0',
];
$where = [];
$args = [];
if ($result !== 'all') $where[] = $resultConditions[$result];
if ($q !== '') {
    $where[] = '(patients.full_name LIKE ? OR patients.national_id LIKE ? OR patients.phone_primary LIKE ? OR patients.phone_secondary LIKE ? OR patients.notes LIKE ?)';
    for ($index = 0; $index < 5; $index++) $args[] = '%' . $q . '%';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$counts = ['all' => (int)db()->query('SELECT COUNT(*) FROM patients')->fetchColumn()];
foreach ($resultConditions as $key => $condition) {
    $counts[$key] = (int)db()->query('SELECT COUNT(*) FROM patients WHERE ' . $condition)->fetchColumn();
}

$countStatement = db()->prepare('SELECT COUNT(*) FROM patients' . $whereSql);
$countStatement->execute($args);
$total = (int)$countStatement->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = $extendedSchemaReady
    ? 'SELECT patients.*,service_type_definitions.name AS service_type_name
       FROM patients LEFT JOIN service_type_definitions ON service_type_definitions.id=patients.service_type_id'
    : 'SELECT patients.*,patients.service_type AS service_type_name FROM patients';
$sql .= $whereSql . ' ORDER BY patients.record_date DESC,patients.import_order ASC,patients.id ASC LIMIT ' . $perPage . ' OFFSET ' . $offset;
try {
    $statement = db()->prepare($sql);
    $statement->execute($args);
    $rows = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log('patient-results.php query: ' . $exception->getMessage());
    $fallback = db()->prepare(
        'SELECT patients.*,patients.service_type AS service_type_name FROM patients' .
        $whereSql . ' ORDER BY patients.record_date DESC,patients.import_order ASC,patients.id ASC LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $fallback->execute($args);
    $rows = $fallback->fetchAll();
}

function patient_result_label(array $patient): string
{
    if (!empty($patient['approval'])) return 'Onay';
    if (!empty($patient['considering'])) return 'Düşünecek';
    if (!empty($patient['rejected'])) return 'Red';
    return 'Sonuç Yok';
}

function patient_result_url(string $targetResult, int $targetPage = 1): string
{
    global $q;
    return url('patient-results.php?' . http_build_query([
        'result' => $targetResult,
        'q' => $q,
        'page' => $targetPage,
    ]));
}

patient_header('Hasta Görüşmeleri Sonuç Raporu', 'results');
$tabs = [
    'all' => 'Tümü',
    'approved' => 'Onay',
    'considering' => 'Düşünecek',
    'rejected' => 'Red',
    'none' => 'Sonuç Yok',
];
$from = $total ? $offset + 1 : 0;
$to = min($offset + $perPage, $total);
?>
<style>
.results-page{max-width:1500px;margin:0 auto;padding:28px 20px 48px}.results-card{overflow:hidden;border:1px solid var(--line);border-radius:9px;background:var(--card);box-shadow:0 .25rem 1.125rem rgba(47,43,61,.1)}.results-head{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:22px 24px;border-bottom:1px solid var(--line)}.results-head h1{margin:0 0 5px;font-size:21px}.results-head p{margin:0;color:var(--muted)}.results-search{display:flex;gap:8px}.results-search input{width:250px;height:39px;padding:0 12px;border:1px solid #d5d3de;border-radius:6px;background:var(--card);color:var(--text);font:inherit}.results-search button{height:39px;border:0;border-radius:6px;padding:0 16px;background:#20a447;color:#fff;font-weight:700}.result-tabs{display:flex;gap:8px;padding:16px 24px 0;overflow-x:auto;border-bottom:1px solid var(--line)}.result-tab{display:inline-flex;align-items:center;gap:7px;min-height:39px;margin-bottom:-1px;padding:0 14px;border:1px solid #dedde5;border-radius:7px 7px 0 0;background:#f7f7f9;color:#6e6b7b;text-decoration:none;white-space:nowrap}.result-tab.active{border-color:#20a447;border-bottom-color:var(--card);background:var(--card);color:#16883d}.result-count{display:grid;place-items:center;min-width:22px;height:22px;padding:0 6px;border-radius:11px;background:#e5e5ea;font-size:11px}.result-tab.active .result-count{background:#d8f3e1;color:#126d31}.results-scroll{overflow:auto}.results-table{width:100%;min-width:1100px;border-collapse:collapse}.results-table th,.results-table td{padding:14px 18px;border-bottom:1px solid var(--line);text-align:left}.results-table th{font-size:12px;color:var(--text);text-transform:uppercase}.results-table td{font-size:13px;color:var(--muted)}.result-badge{display:inline-flex;padding:5px 9px;border-radius:12px;font-weight:700}.result-badge.approved{background:#d8f3e1;color:#126d31}.result-badge.considering{background:#fff0cf;color:#986800}.result-badge.rejected{background:#ffe0e0;color:#a32626}.result-badge.none{background:#ececf1;color:#686576}.result-edit{color:#16883d;font-weight:700;text-decoration:none}.results-footer{display:flex;align-items:center;justify-content:space-between;gap:15px;min-height:68px;padding:12px 24px;color:var(--muted)}.results-pagination{display:flex;gap:6px}.results-pagination a,.results-pagination span{display:grid;place-items:center;min-width:36px;height:36px;padding:0 9px;border-radius:6px;background:#f0f0f3;color:#5d596c;text-decoration:none}.results-pagination a.active{background:#20a447;color:#fff}.empty-result{text-align:center!important;padding:38px!important}@media(max-width:720px){.results-head,.results-footer{align-items:flex-start;flex-direction:column}.results-search,.results-search input{width:100%}}
[data-theme=dark] .result-tab{background:#292c43;color:#c7c8d1;border-color:#454a63}[data-theme=dark] .result-tab.active{background:var(--card);color:#75d392;border-color:#20a447}
</style>
<main class="results-page">
  <section class="results-card">
    <header class="results-head">
      <div><h1>Hasta Görüşmeleri Sonuç Raporu</h1><p>Hastaları kayıt sonuçlarına göre ayrı ayrı görüntüleyin.</p></div>
      <form class="results-search" method="get">
        <input type="hidden" name="result" value="<?=e($result)?>">
        <input name="q" value="<?=e($q)?>" placeholder="Hasta ara" autocomplete="off">
        <button>Ara</button>
      </form>
    </header>
    <nav class="result-tabs">
      <?php foreach ($tabs as $key => $label): ?>
        <a class="result-tab <?=$result===$key?'active':''?>" href="<?=e(patient_result_url($key))?>">
          <?=e($label)?> <span class="result-count"><?=(int)$counts[$key]?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="results-scroll">
      <table class="results-table">
        <thead><tr><th>No</th><th>Tarih</th><th>Ad Soyad</th><th>T.C. Kimlik No</th><th>Telefon</th><th>Hizmet Yeri</th><th>Sonuç</th><th>Açıklama</th><th>İşlem</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $patient): $label = patient_result_label($patient); $class = $label==='Onay'?'approved':($label==='Düşünecek'?'considering':($label==='Red'?'rejected':'none')); ?>
          <tr>
            <td><?=e((string)$patient['import_order'])?></td>
            <td><?=e(format_date_tr($patient['record_date']))?></td>
            <td><strong><?=e($patient['full_name'])?></strong></td>
            <td><?=e($patient['national_id'])?></td>
            <td><?=e($patient['phone_primary'])?></td>
            <td><?=e(patient_service_type_name($patient))?></td>
            <td><span class="result-badge <?=$class?>"><?=e($label)?></span></td>
            <td><?=e($patient['notes'])?></td>
            <td><a class="result-edit" href="<?=url('patient-form.php?id='.(int)$patient['id'])?>">Düzenle</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td class="empty-result" colspan="9">Bu sonuçta hasta kaydı bulunamadı.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <footer class="results-footer">
      <span><?=$from?> - <?=$to?> / <?=$total?> kayıt gösteriliyor</span>
      <nav class="results-pagination">
        <?php if ($page > 1): ?><a href="<?=e(patient_result_url($result,$page-1))?>">‹</a><?php else: ?><span>‹</span><?php endif; ?>
        <?php for ($number=max(1,$page-2); $number<=min($totalPages,$page+2); $number++): ?><a class="<?=$number===$page?'active':''?>" href="<?=e(patient_result_url($result,$number))?>"><?=$number?></a><?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="<?=e(patient_result_url($result,$page+1))?>">›</a><?php else: ?><span>›</span><?php endif; ?>
      </nav>
    </footer>
  </section>
</main>
<?php patient_footer(); ?>
