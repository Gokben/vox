<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/patient-report-schema.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
$isRestrictedResultList = in_array(current_role(), [ROLE_AUDIOMETRIST, ROLE_SECRETARY, ROLE_ACCOUNTING], true);
$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$yearFrom = (int)($_GET['year_from'] ?? 0);
$selectedResults = array_values(array_intersect(['approved', 'considering', 'rejected', 'none'], array_map('strval', (array)($_GET['results'] ?? []))));
$resultOptions = ['approved' => 'Onay', 'considering' => 'Düşünecek', 'rejected' => 'Ret', 'none' => 'Sonuç Yok'];
$resultExpressions = ['approved' => 'COALESCE(p.approval,0)=1', 'considering' => 'COALESCE(p.approval,0)=0 AND COALESCE(p.considering,0)=1', 'rejected' => 'COALESCE(p.approval,0)=0 AND COALESCE(p.considering,0)=0 AND COALESCE(p.rejected,0)=1', 'none' => 'COALESCE(p.approval,0)=0 AND COALESCE(p.considering,0)=0 AND COALESCE(p.rejected,0)=0'];
$yearsSql = 'SELECT DISTINCT YEAR(record_date) FROM patients WHERE record_date IS NOT NULL';
$yearsParams = [];
if ($isRestrictedResultList) {
    $yearsSql .= ' AND created_by = ?';
    $yearsParams[] = $currentUserId;
}
$yearsSql .= ' ORDER BY YEAR(record_date) DESC';
$yearsStatement = $pdo->prepare($yearsSql);
$yearsStatement->execute($yearsParams);
$years = array_map('intval', $yearsStatement->fetchAll(PDO::FETCH_COLUMN));
$where = [];
$params = [];
if ($isRestrictedResultList) {
    $where[] = 'p.created_by = ?';
    $params[] = $currentUserId;
}
if ($dateFrom !== '' || $dateTo !== '') {
    if ($dateFrom !== '') { $where[] = 'p.record_date >= ?'; $params[] = $dateFrom; }
    if ($dateTo !== '') { $where[] = 'p.record_date <= ?'; $params[] = $dateTo; }
} elseif ($yearFrom) {
    if ($yearFrom) { $where[] = 'p.record_date >= ?'; $params[] = sprintf('%04d-01-01', $yearFrom); }
    $where[] = 'p.record_date < ?';
    $params[] = sprintf('%04d-01-01', $yearFrom + 1);
}
if ($selectedResults) $where[] = '(' . implode(' OR ', array_map(static fn(string $key): string => '(' . $resultExpressions[$key] . ')', $selectedResults)) . ')';
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$statement = $pdo->prepare('SELECT p.* FROM patients p' . $whereSql . ' ORDER BY p.record_date DESC, p.import_order ASC, p.id ASC');
$statement->execute($params);
$patients = $statement->fetchAll();
function result_list_label(array $patient): string { if (!empty($patient['approval'])) return 'Onay'; if (!empty($patient['considering'])) return 'Düşünecek'; if (!empty($patient['rejected'])) return 'Ret'; return 'Sonuç Yok'; }
function result_list_class(string $result): string { return match ($result) { 'Onay' => 'approved', 'Düşünecek' => 'considering', 'Ret' => 'rejected', default => 'none' }; }
$returnTo = 'result-list.php?' . http_build_query($_GET);
patient_header('Sonuç Listesi', 'results');
?>
<script>document.documentElement.classList.add('result-list-js');window.addEventListener('load',()=>document.querySelector('.result-list-page')?.classList.add('result-list-ready'));</script>
<style>
.result-list-js .result-list-page{visibility:hidden}.result-list-js .result-list-page.result-list-ready{visibility:visible}.result-list-page{max-width:1500px;margin:0 auto;padding:96px 20px 48px}.result-list-card{overflow:hidden;border:1px solid var(--line);border-radius:9px;background:var(--card);box-shadow:0 .25rem 1.125rem rgba(47,43,61,.1)}.result-list-head{padding:22px 24px;border-bottom:1px solid var(--line)}.result-list-head h1{margin:0 0 5px;font-size:21px}.result-list-head p{margin:0;color:var(--muted)}.result-list-filter{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:16px;padding:20px 24px;border-bottom:1px solid var(--line)}.result-list-filter label{display:grid;gap:7px;font-size:13px;font-weight:700}.result-list-filter input,.result-list-filter select{height:39px;padding:0 11px;border:1px solid #d5d3de;border-radius:6px;background:var(--card);color:var(--text);font:inherit}.result-choice{grid-column:span 2;display:flex!important;flex-wrap:wrap;align-content:start;gap:8px}.result-choice>span{width:100%}.result-choice label{display:flex;align-items:center;gap:6px;font-weight:400}.result-list-actions{display:flex;align-items:end;gap:8px}.result-list-actions button,.result-list-actions a{display:grid;place-items:center;height:39px;padding:0 16px;border:0;border-radius:6px;background:#20a447;color:#fff;text-decoration:none;font:inherit;font-weight:700}.result-list-actions a{background:#f0f0f3;color:#5d596c}.result-list-scroll{overflow:auto}.result-list-table{width:100%;min-width:1040px;border-collapse:collapse}.result-list-table th,.result-list-table td{padding:14px 18px;border-bottom:1px solid var(--line);text-align:left}.result-list-table th{font-size:12px;text-transform:uppercase}.result-list-table td{font-size:13px;color:var(--muted)}.result-badge{display:inline-flex;padding:5px 9px;border-radius:12px;font-weight:700}.result-badge.approved{background:#d8f3e1;color:#126d31}.result-badge.considering{background:#fff0cf;color:#986800}.result-badge.rejected{background:#ffe0e0;color:#a32626}.result-badge.none{background:#ececf1;color:#686576}.result-list-empty{text-align:center!important;padding:38px!important}.result-list-foot{padding:15px 24px;color:var(--muted)}@media(max-width:900px){.result-list-filter{grid-template-columns:repeat(2,minmax(150px,1fr))}.result-choice{grid-column:span 2}}@media(max-width:560px){.result-list-page{padding:92px 14px 30px}.result-list-filter{grid-template-columns:1fr}.result-choice{grid-column:span 1}.result-list-actions{align-items:stretch}}
</style>
<style>
.result-list-head{padding:24px 24px 22px;min-height:88px;box-sizing:border-box}.result-list-head h1{line-height:1.35}.result-list-filter{grid-template-columns:repeat(3,minmax(200px,1fr));align-items:end}.result-choice{grid-column:span 2;min-height:58px}.result-list-actions{grid-column:3;justify-content:flex-end;align-items:center;min-height:58px}.result-list-actions button,.result-list-actions a{min-width:74px}@media(max-width:900px){.result-list-filter{grid-template-columns:repeat(2,minmax(150px,1fr))}.result-list-actions{grid-column:2}.result-choice{grid-column:span 1}}@media(max-width:560px){.result-list-filter{grid-template-columns:1fr}.result-choice,.result-list-actions{grid-column:span 1}.result-list-actions{justify-content:flex-start}}
</style>
<main class="result-list-page"><section class="result-list-card"><header class="result-list-head"><h1>Sonuç Listesi</h1><p>Hastaları tarih aralığı veya seçilen yılın sonuçlarına göre listeleyin.</p></header><form class="result-list-filter" method="get"><label>Başlangıç Tarihi<input type="date" name="date_from" value="<?=e($dateFrom)?>"></label><label>Bitiş Tarihi<input type="date" name="date_to" value="<?=e($dateTo)?>"></label><label>Yıl<select name="year_from"><option value="">Seçiniz</option><?php foreach ($years as $year): ?><option value="<?=$year?>" <?=$yearFrom===$year?'selected':''?>><?=$year?></option><?php endforeach; ?></select></label><div class="result-choice"><span>Sonuç</span><?php foreach ($resultOptions as $key => $label): ?><label><input type="checkbox" name="results[]" value="<?=e($key)?>" <?=in_array($key, $selectedResults, true)?'checked':''?>> <?=e($label)?></label><?php endforeach; ?></div><div class="result-list-actions"><button type="submit">Listele</button><a href="<?=url('result-list.php')?>">Temizle</a></div></form><div class="result-list-scroll"><table class="result-list-table"><thead><tr><th>No</th><th>Kayıt Tarihi</th><th>Ad Soyad</th><th>T.C. Kimlik No</th><th>Telefon</th><th>Hizmet Yeri</th><th>Sonuç</th><th>Açıklama</th><th>İşlem</th></tr></thead><tbody><?php foreach ($patients as $patient): $label = result_list_label($patient); ?><tr><td><?=e((string)$patient['import_order'])?></td><td><?=e(format_date_tr($patient['record_date']))?></td><td><strong><?=e($patient['full_name'])?></strong></td><td><?=e($patient['national_id'])?></td><td><?=e($patient['phone_primary'])?></td><td><?=e($patient['service_location'])?></td><td><span class="result-badge <?=result_list_class($label)?>"><?=e($label)?></span></td><td><?=e($patient['notes'])?></td><td><a href="<?=url('patient-form.php?'.http_build_query(['id'=>(int)$patient['id'],'return'=>$returnTo]))?>">Düzenle</a></td></tr><?php endforeach; ?><?php if (!$patients): ?><tr><td class="result-list-empty" colspan="9">Seçilen ölçütlerde hasta kaydı bulunamadı.</td></tr><?php endif; ?></tbody></table></div><footer class="result-list-foot"><?=count($patients)?> kayıt listeleniyor.</footer></section></main>
<style>
.result-list-filter{grid-template-columns:repeat(5,minmax(150px,1fr))}.result-choice,.result-list-actions{display:none!important}.result-list-card.column-menu-open{overflow:visible}.result-date-control{position:relative}.result-date-control input{width:100%;min-width:0;padding-right:62px}.result-date-clear{position:absolute;right:31px;top:50%;display:grid;place-items:center;width:20px;height:20px;min-height:20px;padding:0;transform:translateY(-50%);border:0;border-radius:4px;background:transparent;color:#5d596c;font-size:18px;line-height:1;cursor:pointer}.result-date-clear:hover{background:#ffe0e0;color:#a32626}.result-list-tools{display:flex;justify-content:flex-end;gap:10px;align-items:center}.result-list-column-picker{position:relative}.result-list-column-button,.result-list-excel{display:grid;place-items:center;height:39px;min-width:39px;padding:0 13px;border:0;border-radius:6px;background:#20a447;color:#fff;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.result-list-excel{padding:0;width:39px}.result-list-excel svg{width:21px;height:21px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}.result-list-column-menu{display:none;position:absolute;right:0;top:46px;z-index:25;width:220px;max-height:340px;overflow:auto;padding:10px;background:var(--card);border:1px solid var(--line);border-radius:7px;box-shadow:0 8px 28px rgba(34,35,61,.18)}.result-list-column-menu.open{display:block}.result-list-column-menu label{display:flex;align-items:center;gap:8px;padding:7px;cursor:pointer}.result-list-column-menu input{width:16px;height:16px;accent-color:#20a447}.result-list-column-actions{display:flex;gap:6px;padding:4px 2px 9px;margin-bottom:4px;border-bottom:1px solid var(--line)}.result-list-column-actions button{flex:1;min-height:30px;padding:0 7px;border:0;border-radius:5px;background:#20a447;color:#fff;font-size:11px;font-weight:700;cursor:pointer}@media(max-width:1100px){.result-list-filter{grid-template-columns:repeat(3,minmax(150px,1fr))}}@media(max-width:700px){.result-list-filter{grid-template-columns:repeat(2,minmax(150px,1fr))}}@media(max-width:560px){.result-list-filter{grid-template-columns:1fr}.result-list-tools{justify-content:flex-start}}
</style>
<style>
.result-list-table tbody tr td{border-top:6px solid #fff;border-bottom:6px solid #fff}.result-list-table tbody tr.result-row-approved td{background:#edf9f0}.result-list-table tbody tr.result-row-considering td{background:#fff8e7}.result-list-table tbody tr.result-row-rejected td{background:#fff0f0}.result-list-table tbody tr.result-row-none td{background:#f7f7f9}[data-theme=dark] .result-list-table tbody tr.result-row-approved td{background:#173d26}[data-theme=dark] .result-list-table tbody tr.result-row-considering td{background:#4b3c18}[data-theme=dark] .result-list-table tbody tr.result-row-rejected td{background:#4b2020}[data-theme=dark] .result-list-table tbody tr.result-row-none td{background:#353746}.result-list-js .result-list-page{visibility:hidden}.result-list-js .result-list-page.result-list-ready{visibility:visible}
</style>
<script>
const resultListYear = document.querySelector('select[name="year_from"]');
if (resultListYear?.parentElement) {
  resultListYear.parentElement.setAttribute('aria-label', 'Yıl');
  if (resultListYear.parentElement.firstChild?.nodeType === Node.TEXT_NODE) resultListYear.parentElement.firstChild.nodeValue = '';
  if (resultListYear.options[0]?.value === '') resultListYear.options[0].textContent = 'Yıl seçiniz';
}
const resultChoice = document.querySelector('.result-choice');
if (resultListYear?.parentElement && resultChoice) {
  const resultSelectLabel = document.createElement('label');
  resultSelectLabel.setAttribute('aria-label', 'Sonuç');
  const resultSelect = document.createElement('select');
  resultSelect.name = 'results[]';
  const allResultsOption = new Option('Sonuç seçiniz', '');
  resultSelect.add(allResultsOption);
  resultChoice.querySelectorAll('input[type="checkbox"]').forEach(choice => {
    const option = new Option(choice.closest('label')?.textContent.trim() || choice.value, choice.value, false, choice.checked);
    resultSelect.add(option);
    choice.disabled = true;
  });
  resultSelectLabel.append(resultSelect);
  resultListYear.parentElement.parentElement.prepend(resultSelectLabel);
}
const resultListTable = document.querySelector('.result-list-table');
if (resultListTable) {
  const resultHeader = [...resultListTable.tHead.rows[0].cells].find(cell => cell.textContent.trim() === 'Sonuç');
  const dateHeader = [...resultListTable.tHead.rows[0].cells].find(cell => cell.textContent.trim() === 'Kayıt Tarihi');
  if (resultHeader && dateHeader) dateHeader.after(resultHeader);
  resultListTable.tBodies[0]?.querySelectorAll('tr').forEach(row => {
    const resultCell = row.querySelector('.result-badge')?.closest('td');
    const dateCell = row.cells[1];
    if (resultCell && dateCell) dateCell.after(resultCell);
    const badge = row.querySelector('.result-badge');
    if (badge) {
      const resultClass = ['approved', 'considering', 'rejected', 'none'].find(name => badge.classList.contains(name));
      if (resultClass) row.classList.add('result-row-' + resultClass);
    }
  });
  const actionColumnIndex = [...resultListTable.tHead.rows[0].cells].findIndex(cell => cell.textContent.trim() === 'İşlem');
  if (actionColumnIndex >= 0) {
    resultListTable.tHead.rows[0].cells[actionColumnIndex].remove();
    resultListTable.tBodies[0]?.querySelectorAll('tr').forEach(row => {
      const patientLink = row.querySelector('a[href*="patient-form.php"]');
      if (patientLink) row.dataset.patientUrl = patientLink.href;
      if (row.cells.length === 1 && row.cells[0].colSpan > 1) row.cells[0].colSpan -= 1;
      else row.cells[actionColumnIndex]?.remove();
    });
  }
  const serviceLocationColumnIndex = [...resultListTable.tHead.rows[0].cells].findIndex(cell => cell.textContent.trim() === 'Hizmet Yeri');
  if (serviceLocationColumnIndex >= 0) {
    resultListTable.tHead.rows[0].cells[serviceLocationColumnIndex].remove();
    resultListTable.tBodies[0]?.querySelectorAll('tr').forEach(row => {
      if (row.cells.length === 1 && row.cells[0].colSpan > 1) row.cells[0].colSpan -= 1;
      else row.cells[serviceLocationColumnIndex]?.remove();
    });
  }
}
if (resultListYear?.form) {
  const resultListForm = resultListYear.form;
  resultListForm.querySelector('.result-list-actions')?.remove();
  resultListYear.addEventListener('change', () => {
    resultListForm.querySelectorAll('input[type="date"]').forEach(dateField => { dateField.value = ''; });
    resultListForm.requestSubmit();
  });
  resultListForm.querySelector('select[name="results[]"]')?.addEventListener('change', () => resultListForm.requestSubmit());
  const dateFields = [...resultListForm.querySelectorAll('input[type="date"]')];
  const syncYearSelection = () => {
    const hasDateRange = dateFields.length === 2 && dateFields.every(dateField => dateField.value !== '');
    if (hasDateRange) resultListYear.value = '';
    resultListYear.disabled = hasDateRange;
  };
  syncYearSelection();
  dateFields.forEach(dateField => {
    const control = document.createElement('div');
    control.className = 'result-date-control';
    const clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.className = 'result-date-clear';
    clearButton.title = 'Tarihi temizle';
    clearButton.setAttribute('aria-label', clearButton.title);
    clearButton.textContent = '×';
    dateField.replaceWith(control);
    control.append(dateField, clearButton);
    clearButton.addEventListener('click', () => {
      if (dateField.value === '') return;
      dateField.value = '';
      syncYearSelection();
      resultListForm.requestSubmit();
    });
  });
  dateFields.forEach(field => field.addEventListener('change', () => {
    syncYearSelection();
    if (dateFields.every(dateField => dateField.value !== '') || dateFields.every(dateField => dateField.value === '')) resultListForm.requestSubmit();
  }));
}
if (resultListTable && resultListYear?.parentElement) {
  const tableHeaders = [...resultListTable.tHead.rows[0].cells].map(cell => cell.textContent.trim());
  const columnVisibilityKey = 'vox-result-list-columns';
  let visibleColumns;
  try { visibleColumns = JSON.parse(localStorage.getItem(columnVisibilityKey) || 'null'); } catch (_) { visibleColumns = null; }
  if (!Array.isArray(visibleColumns) || visibleColumns.length !== tableHeaders.length) visibleColumns = tableHeaders.map(() => true);
  const tools = document.createElement('div');
  tools.className = 'result-list-tools';
  const picker = document.createElement('div');
  picker.className = 'result-list-column-picker';
  picker.innerHTML = '<button type="button" class="result-list-column-button">☷ Sütunlar</button><div class="result-list-column-menu"><div class="result-list-column-actions"><button type="button" data-toggle-all>Tümünü Seç</button></div><div class="result-list-column-options"></div></div>';
  const excel = document.createElement('button');
  excel.type = 'button';
  excel.className = 'result-list-excel';
  excel.title = 'Excel’e aktar';
  excel.setAttribute('aria-label', excel.title);
  excel.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 12l4 6m0-6-4 6m7-6h2m-2 3h2m-2 3h2"/></svg>';
  tools.append(picker, excel);
  resultListYear.parentElement.after(tools);
  const menu = picker.querySelector('.result-list-column-menu');
  const options = picker.querySelector('.result-list-column-options');
  const applyColumns = () => {
    resultListTable.querySelectorAll('thead tr,tbody tr').forEach(row => {
      [...row.cells].forEach((cell, index) => {
        if (row.cells.length === 1 && cell.colSpan > 1) return;
        cell.style.display = visibleColumns[index] ? '' : 'none';
      });
    });
    options.querySelectorAll('input').forEach((input, index) => input.checked = visibleColumns[index]);
    picker.querySelector('[data-toggle-all]').textContent = visibleColumns.every(Boolean) ? 'Tümünü Kaldır' : 'Tümünü Seç';
    localStorage.setItem(columnVisibilityKey, JSON.stringify(visibleColumns));
  };
  tableHeaders.forEach((name, index) => {
    const label = document.createElement('label');
    label.innerHTML = '<input type="checkbox"><span></span>';
    label.querySelector('span').textContent = name;
    label.querySelector('input').addEventListener('change', event => { visibleColumns[index] = event.target.checked; applyColumns(); });
    options.append(label);
  });
  const resultListCard = resultListTable.closest('.result-list-card');
  picker.querySelector('.result-list-column-button').addEventListener('click', event => {
    event.stopPropagation();
    menu.classList.toggle('open');
    resultListCard?.classList.toggle('column-menu-open', menu.classList.contains('open'));
  });
  picker.querySelector('[data-toggle-all]').addEventListener('click', () => { visibleColumns = tableHeaders.map(() => !visibleColumns.every(Boolean)); applyColumns(); });
  menu.addEventListener('click', event => event.stopPropagation());
  document.addEventListener('click', () => { menu.classList.remove('open'); resultListCard?.classList.remove('column-menu-open'); });
  excel.addEventListener('click', () => {
    const exportUrl = new URL(window.location.href);
    exportUrl.pathname = exportUrl.pathname.replace(/[^/]+$/, 'result-list-export.php');
    window.location.assign(exportUrl.toString());
  });
  applyColumns();
}
document.querySelectorAll('.result-list-table tbody tr').forEach(row => {
  const patientUrl = row.dataset.patientUrl || row.querySelector('a[href*="patient-form.php"]')?.href;
  if (!patientUrl) return;
  row.style.cursor = 'pointer';
  row.addEventListener('dblclick', event => {
    if (event.target.closest('a,button,input,label,select')) return;
    window.location.href = patientUrl;
  });
});
document.querySelector('.result-list-page')?.classList.add('result-list-ready');
</script>
<?php patient_footer(); ?>
