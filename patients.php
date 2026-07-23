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
$showAll = ($_GET['all'] ?? '') === '1';
$year = (int)($_GET['year'] ?? 2026);
if (!in_array($year, [2023, 2024, 2025, 2026], true)) $year = 2026;
$perPage = (int)($_GET['length'] ?? 100);
if (!in_array($perPage, [10,25,50,100], true)) $perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$where = [];
$args = [];
if ($q === '' && !$showAll) $where[] = $year === 2025 ? "(record_date LIKE '2025%' OR record_date IS NULL OR record_date='')" : "record_date LIKE '".$year."%'";
if ($q !== '') {
    $where[] = '(patients.full_name LIKE ? OR patients.national_id LIKE ? OR patients.phone_primary LIKE ? OR patients.phone_secondary LIKE ? OR patients.address LIKE ? OR patients.social_security LIKE ? OR patients.notes LIKE ?)';
    for ($i=0; $i<7; $i++) $args[] = '%' . $q . '%';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$yearCounts = [];
foreach (db()->query("SELECT CASE WHEN record_date LIKE '2023%' THEN 2023 WHEN record_date LIKE '2024%' THEN 2024 WHEN record_date LIKE '2026%' THEN 2026 ELSE 2025 END AS y, COUNT(*) AS total FROM patients GROUP BY y") as $countRow) $yearCounts[(int)$countRow['y']] = (int)$countRow['total'];
$countStmt = db()->prepare('SELECT COUNT(*) FROM patients' . $whereSql);
$countStmt->execute($args);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$sql = $extendedSchemaReady
    ? 'SELECT patients.*,branches.name AS branch_name,service_type_definitions.name AS service_type_name,source_definitions.name AS source_name FROM patients LEFT JOIN branches ON branches.id=patients.branch_id LEFT JOIN service_type_definitions ON service_type_definitions.id=patients.service_type_id LEFT JOIN source_definitions ON source_definitions.id=patients.source_id'
    : 'SELECT patients.*,NULL AS branch_name,patients.service_type AS service_type_name,NULL AS source_name FROM patients';
$sql .= $whereSql . ' ORDER BY import_order,patients.id LIMIT ' . $perPage . ' OFFSET ' . $offset;
try {
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
} catch (Throwable $exception) {
    error_log('patients.php extended query: ' . $exception->getMessage());
    $fallbackSql = 'SELECT patients.*,NULL AS branch_name,patients.service_type AS service_type_name,NULL AS source_name FROM patients'
        . $whereSql . ' ORDER BY import_order,patients.id LIMIT ' . $perPage . ' OFFSET ' . $offset;
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
$from = $total ? $offset + 1 : 0; $to = min($offset + $perPage, $total);
function patient_page_url(int $target, string $q, int $length): string { global $year,$showAll; return url('patients.php?' . http_build_query(['year'=>$year,'q'=>$q,'all'=>$showAll?'1':'','length'=>$length,'page'=>$target])); }
patient_header('Hasta Kayıtları');
?>
<style>
.patient-container.datatable-page{padding-top:28px!important}.vuexy-dt-card{border:1px solid var(--line);border-radius:8px;background:var(--card);box-shadow:0 .25rem 1.125rem rgba(47,43,61,.1);overflow:hidden}.vuexy-dt-title{display:flex;align-items:center;justify-content:space-between;min-height:70px;padding:0 24px;border-bottom:1px solid var(--line)}.vuexy-dt-title h2{margin:0;font-size:20px;font-weight:500}.vuexy-dt-toolbar{display:flex;align-items:center;justify-content:space-between;gap:20px;min-height:86px;padding:18px 24px;border-bottom:1px solid var(--line)}.vuexy-length,.vuexy-search{display:flex;align-items:center;gap:10px;color:var(--muted);font-size:15px}.vuexy-length select,.vuexy-search input{height:39px;border:1px solid #d5d3de;border-radius:6px;background:var(--card);color:var(--text);padding:0 12px;font:inherit}.vuexy-length select{width:82px}.vuexy-search input{width:212px}.vuexy-scroll{width:100%;max-width:100%;height:360px;overflow:auto!important;scrollbar-gutter:stable}.vuexy-scroll .patient-table{width:max-content!important;min-width:2200px!important;border-collapse:separate!important;border-spacing:0!important}.vuexy-scroll .patient-table th{position:sticky;top:0;z-index:3;height:50px;padding:0 18px!important;background:var(--card)!important;color:var(--text)!important;border-right:0!important;border-bottom:1px solid var(--line)!important;font-size:12px!important;font-weight:600!important;text-transform:uppercase;white-space:nowrap;text-align:left!important;vertical-align:middle!important}.vuexy-scroll .patient-table td{height:64px;padding:12px 18px!important;background:var(--card)!important;border-right:0!important;border-bottom:1px solid var(--line)!important;color:var(--muted);font-size:13px!important;vertical-align:middle!important}.vuexy-scroll .patient-table tbody tr:hover td{background:rgba(32,164,71,.055)!important}.vuexy-scroll::-webkit-scrollbar{width:10px;height:10px}.vuexy-scroll::-webkit-scrollbar-thumb{background:#8d8c94;border-radius:8px}.vuexy-scroll::-webkit-scrollbar-track{background:#f1f1f3}.vuexy-dt-footer{display:flex;align-items:center;justify-content:space-between;gap:20px;min-height:72px;padding:12px 24px;color:#aaa8b3}.vuexy-pagination{display:flex;align-items:center;gap:6px}.vuexy-pagination a,.vuexy-pagination span{display:grid;place-items:center;min-width:38px;height:38px;padding:0 10px;border-radius:6px;background:#f0f0f3;color:#5d596c;text-decoration:none}.vuexy-pagination a.active{background:#20a447;color:#fff}.vuexy-pagination span.disabled{opacity:.45}.vuexy-actions{display:flex;align-items:center;gap:7px}.vuexy-actions a,.vuexy-actions button{display:grid;place-items:center;width:31px;height:31px;min-height:31px;padding:0;border:0;border-radius:5px;background:transparent!important;color:#676574!important;font-size:17px}.vuexy-actions a:hover,.vuexy-actions button:hover{background:#e8f7ed!important;color:#168238!important}[data-theme=dark] .vuexy-length select,[data-theme=dark] .vuexy-search input,[data-theme=dark] .vuexy-scroll .patient-table th,[data-theme=dark] .vuexy-scroll .patient-table td{background:#30334d!important;color:#fff!important}[data-theme=dark] .vuexy-pagination a,[data-theme=dark] .vuexy-pagination span{background:#3b3f59;color:#fff}@media(max-width:700px){.vuexy-dt-toolbar,.vuexy-dt-footer{align-items:flex-start;flex-direction:column}.vuexy-search{width:100%}.vuexy-search input{width:100%}.vuexy-pagination{flex-wrap:wrap}}
.vuexy-dt-card .vuexy-scroll{height:auto!important;max-height:none!important;overflow-x:auto!important;overflow-y:visible!important}
.column-picker{position:relative;margin-left:auto}.column-picker-button{height:39px;min-height:39px;padding:0 14px;background:#20a447!important;color:#fff!important}.column-picker-menu{display:none;position:absolute;right:0;top:46px;z-index:20;width:245px;max-height:360px;overflow:auto;padding:10px;background:var(--card);border:1px solid var(--line);border-radius:7px;box-shadow:0 8px 28px rgba(34,35,58,.18)}.column-picker-menu.open{display:block}.column-picker-menu label{display:flex;align-items:center;gap:9px;padding:8px;border-radius:5px;color:var(--text);cursor:pointer}.column-picker-menu label:hover{background:rgba(32,164,71,.08)}.column-picker-menu input{width:17px;height:17px;accent-color:#20a447}.column-picker-actions{display:flex;flex-wrap:wrap;gap:6px;padding:6px 4px 10px;border-bottom:1px solid var(--line);margin-bottom:4px}.column-picker-actions button{flex:1;height:31px;min-height:31px;padding:0 7px;font-size:11px;white-space:nowrap}.column-picker-actions [data-auto-fit]{flex-basis:100%}.vuexy-dt-toolbar{position:relative}.vuexy-search{margin-left:auto}.column-picker+.vuexy-search{margin-left:0}.patient-table.auto-fit-columns{table-layout:auto!important;width:max-content!important}.patient-table.auto-fit-columns th,.patient-table.auto-fit-columns td{width:auto!important;min-width:max-content!important;max-width:none!important;white-space:nowrap!important}.patient-table.auto-fit-columns td.address,.patient-table.auto-fit-columns td.note{white-space:nowrap!important;max-width:none!important}@media(max-width:700px){.column-picker{margin-left:0}.column-picker-menu{left:0;right:auto}}
</style>
<main class="patient-container datatable-page"><section class="vuexy-dt-card"><header class="vuexy-dt-title"><h2>Hasta Kayıtları</h2><a class="button" href="<?=url('patient-form.php')?>">+ Yeni Hasta</a></header><form id="patient-table-filter" class="vuexy-dt-toolbar" method="get"><label class="vuexy-length">Göster <select name="length" onchange="this.form.submit()"><?php foreach([10,25,50,100] as $length):?><option value="<?=$length?>" <?=$perPage===$length?'selected':''?>><?=$length?></option><?php endforeach?></select> kayıt</label><label class="vuexy-search">Ara: <input name="q" value="<?=e($q)?>" autocomplete="off"></label></form><div class="vuexy-scroll"><table class="patient-table"><thead><tr><th>No</th><th>Tarih</th><th>Ad Soyad</th><th>T.C. Kimlik No</th><th>Telefon 1</th><th>Telefon 2</th><th>Doğum Tarihi</th><th>Adres</th><th>Sosyal Güvence</th><th>Rapor</th><th>Hizmet Yeri</th><th>Başvuru Kaynağı</th><th>Kaynak</th><th>Açıklama</th><th>Sonuç</th><th>İlgili</th><th>Eylemler</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=e((string)$r['import_order'])?></td><td><?=e($r['record_date'])?></td><td><b><?=e($r['full_name'])?></b></td><td><?=e($r['national_id'])?></td><td><?=e($r['phone_primary'])?></td><td><?=e($r['phone_secondary'])?></td><td><?=e($r['birth_date'])?></td><td class="address"><?=e($r['address'])?></td><td><?=e($r['social_security'])?></td><td><?=e($r['report_info'])?></td><td><?=e(patient_service_type_name($r))?></td><td><?=e(trim($r['source_primary'].' '.$r['source_marketing'].' '.$r['source_detail']))?></td><td><?=e($r['source_name'] ?? '')?></td><td class="note"><?=e($r['notes'])?></td><td><?=$r['approval']?'Onay':($r['considering']?'Düşünecek':($r['rejected']?'Red':'—'))?></td><td><?=e(implode(', ',array_filter([$r['staff_cansu']?'Cansu':'',$r['staff_busra']?'Büşra':'',$r['staff_belma']?'Belma Baysan':''])))?></td><td><div class="vuexy-actions"><a href="<?=url('patient-form.php?id='.(int)$r['id'])?>" title="Düzenle">✎</a><form method="post" action="<?=url('patient-delete.php')?>" onsubmit="return confirm('Bu hasta kaydı silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button title="Sil">⋮</button></form></div></td></tr><?php endforeach?>
<?php if(!$rows):?><tr><td colspan="17" class="empty">Kayıt bulunamadı.</td></tr><?php endif?></tbody></table></div><footer class="vuexy-dt-footer"><span><?=$from?> - <?=$to?> / <?=$total?> kayıt gösteriliyor</span><nav class="vuexy-pagination"><?php if($page>1):?><a href="<?=patient_page_url(1,$q,$perPage)?>">«</a><a href="<?=patient_page_url($page-1,$q,$perPage)?>">‹</a><?php else:?><span class="disabled">«</span><span class="disabled">‹</span><?php endif?><?php $start=max(1,$page-2);$end=min($totalPages,$page+2);if($start>1):?><a href="<?=patient_page_url(1,$q,$perPage)?>">1</a><?php if($start>2):?><span>…</span><?php endif?><?php endif?><?php for($i=$start;$i<=$end;$i++):?><a class="<?=$i===$page?'active':''?>" href="<?=patient_page_url($i,$q,$perPage)?>"><?=$i?></a><?php endfor?><?php if($end<$totalPages):?><?php if($end<$totalPages-1):?><span>…</span><?php endif?><a href="<?=patient_page_url($totalPages,$q,$perPage)?>"><?=$totalPages?></a><?php endif?><?php if($page<$totalPages):?><a href="<?=patient_page_url($page+1,$q,$perPage)?>">›</a><a href="<?=patient_page_url($totalPages,$q,$perPage)?>">»</a><?php else:?><span class="disabled">›</span><span class="disabled">»</span><?php endif?></nav></footer></section></main>
<style>.year-select{height:39px;border:1px solid #d5d3de;border-radius:6px;background:var(--card);color:var(--text);padding:0 12px;font:inherit}</style>
<script>
(()=>{
 const columns=['No','Tarih','Ad Soyad','T.C. Kimlik No','Telefon 1','Telefon 2','Doğum Tarihi','Adres','Sosyal Güvence','Rapor','Hizmet Yeri','Başvuru Kaynağı','Kaynak','Açıklama','Sonuç','İlgili','Eylemler'];
 const toolbar=document.querySelector('.vuexy-dt-toolbar'),table=document.querySelector('.vuexy-scroll .patient-table');
 if(!toolbar||!table)return;
 table.querySelectorAll('tbody tr').forEach(row=>row.addEventListener('dblclick',event=>{if(event.target.closest('a,button,input,form'))return;const edit=row.querySelector('a[href*="patient-form.php?id="]');if(edit)window.location.href=edit.href}));
 const yearSelect=document.createElement('select');yearSelect.className='year-select';yearSelect.setAttribute('aria-label','Hasta kayıt yılı');
 yearSelect.innerHTML='<option value="2023" <?=$year===2023?'selected':''?>>2023 (<?=(int)($yearCounts[2023]??0)?> kayıt)</option><option value="2024" <?=$year===2024?'selected':''?>>2024 (<?=(int)($yearCounts[2024]??0)?> kayıt)</option><option value="2025" <?=$year===2025?'selected':''?>>2025 (<?=(int)($yearCounts[2025]??0)?> kayıt)</option><option value="2026" <?=$year===2026?'selected':''?>>2026 (<?=(int)($yearCounts[2026]??0)?> kayıt)</option>';
 yearSelect.addEventListener('change',()=>{const url=new URL(window.location.href);url.searchParams.set('year',yearSelect.value);url.searchParams.delete('page');url.searchParams.delete('all');if(!url.searchParams.get('q'))url.searchParams.delete('q');window.location.href=url.toString()});
 toolbar.insertBefore(yearSelect,toolbar.querySelector('.vuexy-search'));
 document.getElementById('patient-table-filter')?.addEventListener('submit',event=>{const form=event.currentTarget;let all=form.querySelector('input[name="all"]');if(!all){all=document.createElement('input');all.type='hidden';all.name='all';form.appendChild(all)}all.value=(form.querySelector('input[name="q"]')?.value.trim()==='')?'1':''});
 const picker=document.createElement('div');picker.className='column-picker';
 picker.innerHTML='<button type="button" class="column-picker-button">☷ Sütunlar</button><div class="column-picker-menu"><div class="column-picker-actions"><button type="button" data-show-all>Tümünü Göster</button><button type="button" data-hide-optional>Sade Görünüm</button><button type="button" data-auto-fit>↔ Otomatik Genişlet: Açık</button></div><div class="column-picker-options"></div></div>';
 toolbar.insertBefore(picker,toolbar.querySelector('.vuexy-search'));
 const menu=picker.querySelector('.column-picker-menu'),options=picker.querySelector('.column-picker-options');
 let visible;try{visible=JSON.parse(localStorage.getItem('vox-patient-columns')||'null')}catch(e){}
 if(!Array.isArray(visible)||visible.length!==columns.length)visible=columns.map(()=>true);
 let autoFit=localStorage.getItem('vox-patient-auto-fit')!=='0';
 function applyFit(){table.classList.toggle('auto-fit-columns',autoFit);picker.querySelector('[data-auto-fit]').textContent='↔ Otomatik Genişlet: '+(autoFit?'Açık':'Kapalı');localStorage.setItem('vox-patient-auto-fit',autoFit?'1':'0')}
 function apply(){table.querySelectorAll('tr').forEach(row=>[...row.children].forEach((cell,i)=>cell.style.display=visible[i]?'':'none'));localStorage.setItem('vox-patient-columns',JSON.stringify(visible));options.querySelectorAll('input').forEach((box,i)=>box.checked=visible[i]);applyFit();}
 columns.forEach((name,i)=>{const label=document.createElement('label');label.innerHTML='<input type="checkbox"> <span></span>';label.querySelector('span').textContent=name;label.querySelector('input').addEventListener('change',e=>{visible[i]=e.target.checked;apply()});options.appendChild(label)});
 picker.querySelector('.column-picker-button').addEventListener('click',e=>{e.stopPropagation();menu.classList.toggle('open')});
 picker.querySelector('[data-show-all]').addEventListener('click',()=>{visible=columns.map(()=>true);apply()});
 picker.querySelector('[data-hide-optional]').addEventListener('click',()=>{visible=columns.map((_,i)=>[0,2,3,4,5,16].includes(i));apply()});
 picker.querySelector('[data-auto-fit]').addEventListener('click',()=>{autoFit=!autoFit;applyFit()});
 menu.addEventListener('click',e=>e.stopPropagation());document.addEventListener('click',()=>menu.classList.remove('open'));apply();
})();
</script>
<?php patient_footer();
