<?php
require __DIR__ . '/config.php';
require __DIR__ . '/patient-report-schema.php';
require __DIR__ . '/service-type-bootstrap.php';
require __DIR__ . '/source-bootstrap.php';
require_login();
$extendedSchemaReady = true;
try {
    if (function_exists('ensure_branch_schema')) ensure_branch_schema();
    ensure_patient_service_type_schema();
    ensure_patient_source_schema();
} catch (Throwable $exception) {
    $extendedSchemaReady = false;
    error_log('patients.php extended schema: ' . $exception->getMessage());
}
require __DIR__ . '/patient-layout.php';
require __DIR__ . '/employee-patient-link.php';
$staffNames = ['staff_cansu'=>'Cansu','staff_busra'=>'Büşra','staff_belma'=>'Belma Baysan'];
$staffOrders = [];
try {
    ensure_patient_staff_yeliz_schema();
    $staffNames = patient_staff_names(true);
    foreach (['staff_yeliz','staff_gunes','staff_erva','staff_merve','staff_seyma'] as $staffColumn) {
        $staffOrders[$staffColumn] = db()->query("SELECT import_order FROM patients WHERE COALESCE({$staffColumn},0)=1")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $exception) {
    error_log('patients.php staff schema: ' . $exception->getMessage());
}
start_patient_staff_ui_link($staffNames, [], $staffOrders);

$q = trim($_GET['q'] ?? '');
$databaseDriver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
$isRestrictedPatientList = in_array(current_role(), [ROLE_AUDIOMETRIST, ROLE_SECRETARY, ROLE_ACCOUNTING], true);
$showAll = ($_GET['all'] ?? '') === '1';
$year = (int)($_GET['year'] ?? 2023);
if (!in_array($year, [2023, 2024, 2025, 2026], true)) $year = 2023;
$dateSort = $_GET['sort'] ?? '';
if (!in_array($dateSort, ['date_asc', 'date_desc'], true)) $dateSort = '';
$perPage = (int)($_GET['length'] ?? 50);
if (!in_array($perPage, [10,25,50,100], true)) $perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
if ($isRestrictedPatientList) {
    $showAll = true;
    $dateSort = 'date_desc';
    $perPage = 2;
    $page = 1;
}
$searchColumnKeys = ['no','date','name','national_id','phone_primary','phone_secondary','birth_date','address','social_security','report','service_location','application_detail','source','notes','result','contact'];
$requestedSearchColumns = array_values(array_unique(array_filter(explode(',', (string)($_GET['search_columns'] ?? '')), static fn(string $key): bool => in_array($key, $searchColumnKeys, true))));
$hasSearchColumnSelection = array_key_exists('search_columns', $_GET);
$activeSearchColumns = $isRestrictedPatientList ? $searchColumnKeys : ($hasSearchColumnSelection ? $requestedSearchColumns : $searchColumnKeys);
$searchColumnsParam = implode(',', $activeSearchColumns);
$where = [];
$args = [];
// Arama da kayıt seçimindeki kapsamda yapılır: seçili yıl veya Tüm Kayıtlar.
if (!$showAll) $where[] = $year === 2025 ? "(record_date LIKE '2025%' OR record_date IS NULL OR record_date='')" : "record_date LIKE '".$year."%'";
if ($q !== '') {
    $searchExpressions = [];
    $addSearch = static function (string $expression, int $argumentCount = 1) use (&$searchExpressions, &$args, $q): void {
        $searchExpressions[] = $expression;
        for ($i = 0; $i < $argumentCount; $i++) $args[] = '%' . $q . '%';
    };
    foreach ($activeSearchColumns as $column) {
        if ($column === 'no') $addSearch('patients.import_order LIKE ?');
        elseif ($column === 'date') $addSearch('patients.record_date LIKE ?');
        elseif ($column === 'name') $addSearch($databaseDriver === 'mysql' ? 'patients.full_name COLLATE utf8mb4_turkish_ci LIKE ?' : 'patients.full_name LIKE ?');
        elseif ($column === 'national_id') $addSearch('patients.national_id LIKE ?');
        elseif ($column === 'phone_primary') $addSearch('patients.phone_primary LIKE ?');
        elseif ($column === 'phone_secondary') $addSearch('patients.phone_secondary LIKE ?');
        elseif ($column === 'birth_date') $addSearch('patients.birth_date LIKE ?');
        elseif ($column === 'address') $addSearch('patients.address LIKE ?');
        elseif ($column === 'social_security') $addSearch('patients.social_security LIKE ?');
        elseif ($column === 'report') $addSearch('(patients.report_status LIKE ? OR patients.report_info LIKE ?)', 2);
        elseif ($column === 'application_detail') $addSearch('(patients.source_primary LIKE ? OR patients.source_marketing LIKE ? OR patients.source_detail LIKE ?)', 3);
        elseif ($column === 'notes') $addSearch('patients.notes LIKE ?');
        elseif ($column === 'result') $addSearch("(CASE WHEN patients.approval=1 THEN 'Onay' WHEN patients.considering=1 THEN 'Düşünecek' WHEN patients.rejected=1 THEN 'Ret' ELSE '' END) LIKE ?");
        elseif ($extendedSchemaReady && $column === 'service_location') $addSearch('EXISTS (SELECT 1 FROM patient_services AS search_services WHERE search_services.patient_id=patients.id AND search_services.service_location LIKE ?)');
        elseif ($extendedSchemaReady && $column === 'source') $addSearch('EXISTS (SELECT 1 FROM source_definitions AS search_sources WHERE search_sources.id=patients.source_id AND search_sources.name LIKE ?)');
        elseif ($extendedSchemaReady && $column === 'contact') $addSearch('EXISTS (SELECT 1 FROM patient_services AS search_services WHERE search_services.patient_id=patients.id AND (search_services.contact_person LIKE ? OR search_services.related_personnel LIKE ?))', 2);
    }
    $where[] = $searchExpressions ? '(' . implode(' OR ', $searchExpressions) . ')' : '1=0';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$yearCounts = [];
foreach (db()->query("SELECT CASE WHEN record_date LIKE '2023%' THEN 2023 WHEN record_date LIKE '2024%' THEN 2024 WHEN record_date LIKE '2026%' THEN 2026 ELSE 2025 END AS y, COUNT(*) AS total FROM patients GROUP BY y") as $countRow) $yearCounts[(int)$countRow['y']] = (int)$countRow['total'];
$allPatientCount = (int)db()->query('SELECT COUNT(*) FROM patients')->fetchColumn();
$countStmt = db()->prepare('SELECT COUNT(*) FROM patients' . $whereSql);
$countStmt->execute($args);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$sql = $extendedSchemaReady
    ? 'SELECT patients.*,branches.name AS branch_name,service_type_definitions.name AS service_type_name,source_definitions.name AS source_name,(SELECT contact_person FROM patient_services WHERE patient_services.patient_id=patients.id ORDER BY patient_services.id DESC LIMIT 1) AS service_contact_person,(SELECT service_location FROM patient_services WHERE patient_services.patient_id=patients.id ORDER BY patient_services.id DESC LIMIT 1) AS service_card_location FROM patients LEFT JOIN branches ON branches.id=patients.branch_id LEFT JOIN service_type_definitions ON service_type_definitions.id=patients.service_type_id LEFT JOIN source_definitions ON source_definitions.id=patients.source_id'
    : 'SELECT patients.*,NULL AS branch_name,patients.service_type AS service_type_name,NULL AS source_name,NULL AS service_contact_person,NULL AS service_card_location FROM patients';
$orderSql = $isRestrictedPatientList
    ? 'patients.record_date DESC,patients.id DESC'
    : ($dateSort === 'date_asc'
    ? 'patients.record_date ASC,patients.import_order ASC,patients.id ASC'
    : ($dateSort === 'date_desc'
        ? 'patients.record_date DESC,patients.import_order ASC,patients.id ASC'
        : 'patients.import_order ASC,patients.id ASC'));
$sql .= $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
try {
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
} catch (Throwable $exception) {
    error_log('patients.php extended query: ' . $exception->getMessage());
    $fallbackSql = 'SELECT patients.*,NULL AS branch_name,patients.service_type AS service_type_name,NULL AS source_name,NULL AS service_contact_person,NULL AS service_card_location FROM patients'
        . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $stmt = db()->prepare($fallbackSql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
}
foreach ($rows as &$row) {
    if (in_array(mb_strtolower(str_replace(['İ','I'], ['i','i'], trim((string)$row['social_security'])), 'UTF-8'), ['emek','emekli'], true)) $row['social_security'] = 'Emekli';
    if (in_array(mb_strtolower(str_replace(['İ','I'], ['i','ı'], trim((string)$row['social_security'])), 'UTF-8'), ['yeşil kartlı','yeşilkart','yeşil kart'], true)) $row['social_security'] = 'Yeşil Kart';
    if (in_array(mb_strtolower(trim((string)$row['social_security']), 'UTF-8'), ['çocuk','cocuk'], true)) $row['social_security'] = 'Çocuk';
}
unset($row);
foreach ($rows as &$row) $row['report_info'] = (string)($row['report_status'] ?? '');
unset($row);
foreach ($rows as &$row) {
    if (trim((string)($row['source_name'] ?? '')) === '') {
        foreach (['source_primary', 'source_marketing', 'source_detail'] as $sourceField) {
            $sourceValue = trim((string)($row[$sourceField] ?? ''));
            if ($sourceValue !== '') {
                $row['source_name'] = $sourceValue;
                break;
            }
        }
    }
}
unset($row);
$from = $total ? $offset + 1 : 0; $to = min($offset + $perPage, $total);
$patientListReturn = 'patients.php?' . http_build_query([
    'year' => $year,
    'q' => $q,
    'all' => $showAll ? '1' : '',
    'sort' => $dateSort,
    'search_columns' => $searchColumnsParam,
    'length' => $perPage,
    'page' => $page,
]);
function patient_page_url(int $target, string $q, int $length): string { global $year,$showAll,$dateSort,$searchColumnsParam; return url('patients.php?' . http_build_query(['year'=>$year,'q'=>$q,'all'=>$showAll?'1':'','sort'=>$dateSort,'search_columns'=>$searchColumnsParam,'length'=>$length,'page'=>$target])); }
function patient_date_sort_url(): string { global $year,$showAll,$dateSort,$q,$perPage,$searchColumnsParam; return url('patients.php?' . http_build_query(['year'=>$year,'q'=>$q,'all'=>$showAll?'1':'','sort'=>$dateSort==='date_asc'?'date_desc':'date_asc','search_columns'=>$searchColumnsParam,'length'=>$perPage,'page'=>1])); }
patient_header('Hasta Kartları');
?>
<style>
.date-sort-link{display:inline-flex;align-items:center;gap:6px;color:inherit;text-decoration:none}.date-sort-link:hover{color:#16883d}.date-sort-icon{font-size:14px;line-height:1;color:#20a447}
body .vox-patient-list-actions{gap:6px!important}body .vox-patient-list-actions>a,body .vox-patient-list-actions>form>button{display:grid!important;place-items:center!important;box-sizing:border-box!important;margin:0!important;padding:0!important;width:32px!important;height:32px!important;min-width:32px!important;min-height:32px!important;max-width:32px!important;max-height:32px!important}body .vox-patient-list-actions>form{display:block!important;margin:0!important}body .vox-patient-list-actions .vox-icon-delete{margin-left:0!important}body .vox-patient-list-actions>a[href*="patient-followup.php?id="]{box-sizing:border-box!important;flex:0 0 32px!important;width:32px!important;height:32px!important;min-width:32px!important;min-height:32px!important;max-width:32px!important;max-height:32px!important;border:1px solid #f3a64a!important;background:#f3a64a!important;color:#fff!important}body .vox-patient-list-actions>a[href*="patient-followup.php?id="] .ti{width:16px!important;height:16px!important;font-size:16px!important}body .vox-patient-list-actions>a[href*="patient-followup.php?id="]:hover{background:#df8f2b!important;color:#fff!important}
body .vox-patient-list-actions .patient-info-action{border:1px solid #6f42c1!important;color:#fff!important;background:#6f42c1!important}body .vox-patient-list-actions .patient-info-action:hover{background:#59359d!important;border-color:#59359d!important;color:#fff!important}
body .vox-patient-list-actions>a,body .vox-patient-list-actions>form>button{flex:0 0 32px!important;width:32px!important;height:32px!important;min-width:32px!important;min-height:32px!important;max-width:32px!important;max-height:32px!important}
body .vox-patient-list-actions>a,body .vox-patient-list-actions>form>button{font-size:17px!important;line-height:1!important}body .vox-patient-list-actions .icon-base,body .vox-patient-list-actions .ti{display:block!important;width:17px!important;min-width:17px!important;max-width:17px!important;height:17px!important;min-height:17px!important;max-height:17px!important;font-size:17px!important;line-height:17px!important}
.vox-patient-auto-width{display:inline-grid;place-items:center;box-sizing:border-box;width:30px;height:30px;min-width:30px;padding:0;border:0;background:transparent;color:#087a55;box-shadow:none;cursor:pointer}.vox-patient-auto-width:hover{background:rgba(8,122,85,.08);color:#045c40}.vox-patient-auto-width:active{transform:translateY(1px)}.vox-patient-auto-width svg{display:block;width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.vox-patient-auto-width.is-done{background:rgba(23,97,141,.1);color:#17618d}
</style>
<main class="vox-patient-list-page vox-patient-classic-list<?=$isRestrictedPatientList?' patient-list-restricted':''?>"><?php if (($_GET['delete_error'] ?? '') === 'service_card'): ?><div class="patient-delete-alert">Bu hasta kartına bağlı hizmet kartı bulunduğu için silinemez.</div><?php endif; ?><section class="vox-patient-list-card"><form id="vox-patient-table-filter" class="vox-patient-list-toolbar" method="get"><a class="vox-patient-new-button" href="<?=url('patient-form.php?'.http_build_query(['return'=>$patientListReturn]))?>">+ Yeni Hasta</a><input type="hidden" name="year" value="<?=$year?>"><input type="hidden" name="sort" value="<?=e($dateSort)?>"><input type="hidden" name="search_columns" value="<?=e($searchColumnsParam)?>"><label class="vox-patient-list-length">Göster <select name="length" onchange="this.form.submit()"><?php foreach([10,25,50,100] as $length):?><option value="<?=$length?>" <?=$perPage===$length?'selected':''?>><?=$length?></option><?php endforeach?></select> kayıt</label><label class="vox-patient-list-search">Ara: <input name="q" value="<?=e($q)?>" placeholder="Seçili sütunlarda ara" autocomplete="off"></label></form><div class="vox-patient-list-scroll"><table class="vox-patient-table"><thead><tr><th>No</th><th><a class="date-sort-link" href="<?=e(patient_date_sort_url())?>" title="Tarihe göre sırala">Tarih <span class="date-sort-icon" aria-hidden="true"><?=$dateSort==='date_asc'?'↑':($dateSort==='date_desc'?'↓':'↕')?></span></a></th><th>Ad Soyad</th><th>T.C. Kimlik No</th><th>Telefon 1</th><th>Telefon 2</th><th>Doğum Tarihi</th><th>Adres</th><th>Sosyal Güvence</th><th>Rapor</th><th>Hizmet Yeri</th><th>Başvuru Detayı</th><th>Kaynak</th><th>Açıklama</th><th>Sonuç</th><th>İlgili</th><th>Eylemler</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=e((string)$r['import_order'])?></td><td><?=e($r['record_date'])?></td><td><b><?=e($r['full_name'])?></b></td><td><?=e($r['national_id'])?></td><td><?=e($r['phone_primary'])?></td><td><?=e($r['phone_secondary'])?></td><td><?=e($r['birth_date'])?></td><td class="address"><?=e($r['address'])?></td><td><?=e($r['social_security'])?></td><td><?=e($r['report_info'])?></td><td><?=e($r['service_card_location'] ?? '')?></td><td><?=e(trim($r['source_primary'].' '.$r['source_marketing'].' '.$r['source_detail']))?></td><td><?=e($r['source_name'] ?? '')?></td><td class="note"><?=e($r['notes'])?></td><td><?=$r['approval']?'Onay':($r['considering']?'Düşünecek':($r['rejected']?'Ret':'—'))?></td><td><?=e($r['service_contact_person'] ?? '')?></td><td><div class="vox-patient-list-actions"><a href="<?=url('patient-followup.php?id='.(int)$r['id'])?>" title="Hizmetler" aria-label="Hizmetler"><i class="icon-base ti tabler-heart-handshake"></i></a><a href="<?=url('patient-form.php?'.http_build_query(['id'=>(int)$r['id'],'return'=>$patientListReturn]))?>" title="Düzenle" aria-label="Düzenle"><i class="icon-base ti tabler-edit" aria-hidden="true"></i></a><form method="post" action="<?=url('patient-delete.php')?>" onsubmit="return confirm('Bu hasta kaydı silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button title="Sil" aria-label="Sil"><i class="icon-base ti tabler-trash" aria-hidden="true"></i></button></form></div></td></tr><?php endforeach?>
<?php if(!$rows):?><tr><td colspan="17" class="empty">Kayıt bulunamadı.</td></tr><?php endif?></tbody></table></div><footer class="vox-patient-list-footer"><span><?=$from?> - <?=$to?> / <?=$total?> kayıt gösteriliyor</span><nav class="vox-patient-list-pagination"><?php if($page>1):?><a href="<?=patient_page_url(1,$q,$perPage)?>">«</a><a href="<?=patient_page_url($page-1,$q,$perPage)?>">‹</a><?php else:?><span class="disabled">«</span><span class="disabled">‹</span><?php endif?><?php $start=max(1,$page-2);$end=min($totalPages,$page+2);if($start>1):?><a href="<?=patient_page_url(1,$q,$perPage)?>">1</a><?php if($start>2):?><span>…</span><?php endif?><?php endif?><?php for($i=$start;$i<=$end;$i++):?><a class="<?=$i===$page?'active':''?>" href="<?=patient_page_url($i,$q,$perPage)?>"><?=$i?></a><?php endfor?><?php if($end<$totalPages):?><?php if($end<$totalPages-1):?><span>…</span><?php endif?><a href="<?=patient_page_url($totalPages,$q,$perPage)?>"><?=$totalPages?></a><?php endif?><?php if($page<$totalPages):?><a href="<?=patient_page_url($page+1,$q,$perPage)?>">›</a><a href="<?=patient_page_url($totalPages,$q,$perPage)?>">»</a><?php else:?><span class="disabled">›</span><span class="disabled">»</span><?php endif?></nav></footer></section></main>
<style>.year-select{height:39px;border:1px solid #d5d3de;border-radius:6px;background:var(--card);color:var(--text);padding:0 12px;font:inherit}.patient-list-restricted .vox-patient-list-length,.patient-list-restricted .vox-patient-list-footer{display:none!important}</style>
<script>
(()=>{
 const restricted=<?=json_encode($isRestrictedPatientList)?>;
 const searchKeys=['no','date','name','national_id','phone_primary','phone_secondary','birth_date','address','social_security','report','service_location','application_detail','source','notes','result','contact',null];
 const toolbar=document.querySelector('.vox-patient-list-toolbar'),table=document.querySelector('.vox-patient-list-scroll .vox-patient-table');
 if(!toolbar||!table)return;
 const openIndependent=(rawUrl,title)=>{
   const url=new URL(rawUrl,location.href).href;
   if(window.parent!==window){window.parent.postMessage({type:'vox-open-window',url,title},location.origin);return}
   if(typeof window.voxOpenWindow==='function'){window.voxOpenWindow(url,title);return}
   window.location.assign(url);
 };
 window.voxOpenPatientListWindow=openIndependent;
 table.querySelectorAll('tbody tr').forEach(row=>{row.style.cursor='default';row.addEventListener('dblclick',event=>{if(event.target.closest('a,button,input,form'))return;const edit=row.querySelector('a[href*="patient-form.php?id="]');if(edit)openIndependent(edit.href,'Hasta Kartı - '+(row.cells[2]?.textContent||'Hasta').trim())});});
 const yearSelect=document.createElement('select');yearSelect.className='year-select';yearSelect.setAttribute('aria-label','Hasta kayıt yılı');
 yearSelect.innerHTML='<option value="all" <?=$showAll?'selected':''?>>Tüm Kayıtlar (<?=$allPatientCount?> kayıt)</option><option value="2023" <?=!$showAll&&$year===2023?'selected':''?>>2023 (<?=(int)($yearCounts[2023]??0)?> kayıt)</option><option value="2024" <?=!$showAll&&$year===2024?'selected':''?>>2024 (<?=(int)($yearCounts[2024]??0)?> kayıt)</option><option value="2025" <?=!$showAll&&$year===2025?'selected':''?>>2025 (<?=(int)($yearCounts[2025]??0)?> kayıt)</option><option value="2026" <?=!$showAll&&$year===2026?'selected':''?>>2026 (<?=(int)($yearCounts[2026]??0)?> kayıt)</option>';
 yearSelect.addEventListener('change',()=>{const url=new URL(window.location.href);url.searchParams.delete('page');if(yearSelect.value==='all'){url.searchParams.set('all','1');url.searchParams.delete('q')}else{url.searchParams.set('year',yearSelect.value);url.searchParams.delete('all');if(!url.searchParams.get('q'))url.searchParams.delete('q')}window.location.href=url.toString()});
 if(!restricted)toolbar.insertBefore(yearSelect,toolbar.querySelector('.vox-patient-list-search'));
 document.getElementById('vox-patient-table-filter')?.addEventListener('submit',event=>{const form=event.currentTarget;const visibleHeaders=[...table.tHead.rows[0].cells].map((cell,index)=>!cell.classList.contains('vox-column-hidden')&&cell.style.display!=='none'?searchKeys[index]:null).filter(Boolean);form.querySelector('input[name="search_columns"]').value=visibleHeaders.join(',');let all=form.querySelector('input[name="all"]');if(!all){all=document.createElement('input');all.type='hidden';all.name='all';form.appendChild(all)}all.value=yearSelect.value==='all'?'1':''});
 const excelLink=document.createElement('a');excelLink.className='excel-export-button';excelLink.href='<?=url('patients-export.php?year='.$year)?>';excelLink.title='<?=$year?> yılı hasta kayıtlarını Excel’e aktar';excelLink.setAttribute('aria-label',excelLink.title);excelLink.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 12l4 6m0-6-4 6m7-6h2m-2 3h2m-2 3h2"/></svg>';if(!restricted)toolbar.insertBefore(excelLink,toolbar.querySelector('.vox-patient-list-search'));
 const autoWidthButton=document.createElement('button');autoWidthButton.type='button';autoWidthButton.className='vox-patient-auto-width';autoWidthButton.title='Tüm sütunları otomatik sabit genişliğe ayarla';autoWidthButton.setAttribute('aria-label',autoWidthButton.title);autoWidthButton.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4zM9 5v14M15 5v14"/><path d="M6.5 12h-1.5m14 0h-1.5M5 12l2-2m-2 2 2 2m12-2-2-2m2 2-2 2"/></svg>';
 const widthLimits=[[54,70],[82,102],[135,220],[108,145],[94,130],[94,130],[92,122],[220,380],[105,170],[105,190],[95,155],[135,260],[105,190],[155,320],[82,125],[125,220],[158,158]];
 const measureCanvas=document.createElement('canvas'),measureContext=measureCanvas.getContext('2d');if(measureContext)measureContext.font='11px Tahoma, Arial, sans-serif';
 const measureText=value=>measureContext?Math.ceil(measureContext.measureText(String(value||'').replace(/\s+/g,' ').trim()).width):String(value||'').length*7;
 autoWidthButton.addEventListener('click',()=>{
   const headers=[...table.tHead.rows[0].cells],bodyRows=[...table.tBodies[0].rows].slice(0,50);
   const widths=headers.map((header,index)=>{
     const limits=widthLimits[index]||[70,220];
     if(index===headers.length-1)return limits[0];
     let contentWidth=measureText(header.textContent)+30;
     bodyRows.forEach(row=>{const cell=row.cells[index];if(cell)contentWidth=Math.max(contentWidth,measureText(cell.textContent)+22)});
     return Math.max(limits[0],Math.min(limits[1],contentWidth));
   });
   table.style.setProperty('table-layout','fixed','important');
   headers.forEach((header,index)=>{const width=widths[index]+'px';header.style.setProperty('width',width,'important');header.style.setProperty('min-width',width,'important');header.style.setProperty('max-width',width,'important')});
   const visibleTotal=widths.reduce((sum,width,index)=>sum+(headers[index].classList.contains('vox-column-hidden')||headers[index].style.display==='none'?0:width),0);
   const scrollHost=table.closest('.vox-patient-list-scroll');table.style.setProperty('width',Math.max(scrollHost?.clientWidth||0,visibleTotal)+'px','important');
   try{localStorage.setItem('vox.classic.widths.patients.php.0',JSON.stringify(widths))}catch(_){ }
   if(scrollHost)scrollHost.scrollLeft=0;
   autoWidthButton.classList.add('is-done');autoWidthButton.title='Sütun genişlikleri sabitlendi';setTimeout(()=>{autoWidthButton.classList.remove('is-done');autoWidthButton.title='Tüm sütunları otomatik sabit genişliğe ayarla'},1200);
 });
 if(!restricted)toolbar.insertBefore(autoWidthButton,toolbar.querySelector('.vox-patient-list-search'));
})();
</script>
<script>
document.addEventListener('click', event => {
  if(event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
  const link=event.target.closest('a[href]');
  if(!link||!link.matches('.vox-patient-new-button,.vox-patient-list-actions a[href*="patient-form.php"],.vox-patient-list-actions a[href*="patient-info.php"],.vox-patient-list-actions a[href*="patient-followup.php"]'))return;
  event.preventDefault();
  event.stopPropagation();
  const row=link.closest('tr');
  const patientName=(row?.cells[2]?.textContent||'').replace(/\s+/g,' ').trim();
  let title='Yeni Hasta';
  let targetUrl=link.href;
  if(link.href.includes('patient-followup.php')){
    const serviceUrl=new URL(link.href,location.href);
    serviceUrl.searchParams.set('from_patient_list','1');
    targetUrl=serviceUrl.href;
    title='Hizmetler'+(patientName?' - '+patientName:'');
  }
  else if(link.href.includes('patient-info.php'))title='Hasta Bilgi Formu'+(patientName?' - '+patientName:'');
  else if(link.href.includes('patient-form.php')&&patientName)title='Hasta Kartı - '+patientName;
  window.voxOpenPatientListWindow?.(targetUrl,title);
},true);
</script>
<style>.patient-delete-alert{margin:0 0 14px;padding:13px 16px;border:1px solid #f2b8b8;border-radius:7px;background:#fff0f0;color:#b42318}</style>
<script>
document.querySelectorAll('.vox-patient-list-actions').forEach(actions => {
  const editLink = actions.querySelector('a[href*="patient-form.php"]');
  if (!editLink || actions.querySelector('.patient-info-action')) return;
  const info = document.createElement('a');
  info.className = 'patient-info-action';
  info.href = editLink.href.replace('patient-form.php', 'patient-info.php'); info.title = 'Hasta Bilgi Formu'; info.setAttribute('aria-label', info.title);
  info.innerHTML = '<i class="icon-base ti tabler-user-circle" aria-hidden="true"></i>';
  actions.append(info);
});
requestAnimationFrame(() => document.querySelectorAll('.vox-patient-list-actions > a,.vox-patient-list-actions > form > button').forEach(button => {
  ['width','height','min-width','min-height','max-width','max-height','flex-basis'].forEach(property => button.style.setProperty(property, '32px', 'important'));
  button.style.setProperty('padding', '0', 'important');
  button.style.setProperty('margin', '0', 'important');
  const icon=button.querySelector('.icon-base,.ti');
  if(icon){['width','height','min-width','min-height','max-width','max-height','flex-basis','font-size','line-height'].forEach(property=>icon.style.setProperty(property,'17px','important'));}
}));
</script>
<script>
(() => {
  const form = document.getElementById('vox-patient-table-filter');
  const input = form?.querySelector('input[name="q"]');
  if (!form || !input) return;
  let timer;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const query = input.value.trim();
    if (query !== '' && query.length < 3) return;
    timer = window.setTimeout(() => form.requestSubmit(), 350);
  });
})();
</script>
<?php patient_footer();
