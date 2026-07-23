<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/source-bootstrap.php';
ensure_patient_source_schema();
require __DIR__ . '/patient-layout.php';

$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$firstDay = new DateTimeImmutable($month . '-01');
$nextMonth = $firstDay->modify('+1 month');
$previousMonth = $firstDay->modify('-1 month');
$todayMonth = date('Y-m');

$statement = db()->prepare(
    'SELECT patients.id,patients.record_date,patients.full_name,patients.approval,patients.considering,patients.rejected,source_definitions.name AS source_name
     FROM patients
     LEFT JOIN source_definitions ON source_definitions.id=patients.source_id
     WHERE patients.record_date >= ? AND patients.record_date < ?
     ORDER BY patients.record_date,patients.full_name'
);
$statement->execute([$firstDay->format('Y-m-d'), $nextMonth->format('Y-m-d')]);
$eventsByDate = [];
foreach ($statement->fetchAll() as $row) {
    $day = (string)$row['record_date'];
    $category = $row['approval'] ? 'approval' : ($row['considering'] ? 'considering' : ($row['rejected'] ? 'rejected' : 'other'));
    $eventsByDate[$day][] = ['id'=>(int)$row['id'], 'name'=>(string)$row['full_name'], 'source'=>(string)($row['source_name'] ?? ''), 'category'=>$category];
}

$monthNames = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
$monthTitle = $monthNames[(int)$firstDay->format('n')] . ' ' . $firstDay->format('Y');
$leadingDays = (int)$firstDay->format('N') - 1;
$daysInMonth = (int)$firstDay->format('t');
$totalEvents = array_sum(array_map('count', $eventsByDate));

patient_header('Takvim', 'calendar');
?>
<main class="calendar-page">
  <section class="calendar-shell">
    <aside class="calendar-sidebar">
      <a class="calendar-add" href="<?= url('patient-form.php?date='.date('Y-m-d')) ?>">＋ Yeni Hasta Kaydı</a>
      <div class="mini-calendar">
        <div class="mini-calendar-head"><a href="<?=url('calendar.php?month='.$previousMonth->format('Y-m'))?>" aria-label="Önceki ay">‹</a><b><?=$monthTitle?></b><a href="<?=url('calendar.php?month='.$nextMonth->format('Y-m'))?>" aria-label="Sonraki ay">›</a></div>
        <div class="mini-week">P P S Ç P C C</div>
        <div class="mini-days"><?php for($blank=0;$blank<$leadingDays;$blank++):?><span></span><?php endfor; ?><?php for($day=1;$day<=$daysInMonth;$day++):$date=$month.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT);?><a class="<?= $date===date('Y-m-d')?'today':'' ?> <?=isset($eventsByDate[$date])?'has-event':''?>" href="#day-<?=$day?>"><?=$day?></a><?php endfor; ?></div>
      </div>
      <hr>
      <h3>Hasta Kayıtları</h3>
      <label class="calendar-filter all"><input type="checkbox" data-filter="all" checked> <span></span>Tümünü Göster</label>
      <label class="calendar-filter approval"><input type="checkbox" data-filter="approval" checked> <span></span>Onaylanan</label>
      <label class="calendar-filter considering"><input type="checkbox" data-filter="considering" checked> <span></span>Düşünecek</label>
      <label class="calendar-filter rejected"><input type="checkbox" data-filter="rejected" checked> <span></span>Reddedilen</label>
      <label class="calendar-filter other"><input type="checkbox" data-filter="other" checked> <span></span>Sonuç Bekliyor</label>
      <p class="calendar-count"><b><?=$totalEvents?></b> hasta kaydı</p>
    </aside>
    <section class="calendar-content">
      <header class="calendar-toolbar">
        <div class="calendar-nav"><a href="<?=url('calendar.php?month='.$previousMonth->format('Y-m'))?>">‹</a><a href="<?=url('calendar.php?month='.$todayMonth)?>">Bugün</a><a href="<?=url('calendar.php?month='.$nextMonth->format('Y-m'))?>">›</a></div>
        <h1><?=$monthTitle?></h1>
        <span class="calendar-view">Ay</span>
      </header>
      <div class="calendar-grid">
        <?php foreach(['Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar'] as $weekDay):?><div class="calendar-weekday"><?=$weekDay?></div><?php endforeach; ?>
        <?php for($blank=0;$blank<$leadingDays;$blank++):?><div class="calendar-day muted"></div><?php endfor; ?>
        <?php for($day=1;$day<=$daysInMonth;$day++):$date=$month.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT);$events=$eventsByDate[$date]??[];?><div class="calendar-day <?= $date===date('Y-m-d')?'is-today':'' ?>" id="day-<?=$day?>"><div class="day-head"><span class="day-number"><?=$day?></span><a class="calendar-new" href="<?=url('patient-form.php?date='.$date)?>" title="Bu tarihe hasta kaydı ekle">＋</a></div><div class="day-events"><?php foreach($events as $event):?><a class="calendar-event <?=$event['category']?>" data-category="<?=$event['category']?>" href="<?=url('patient-form.php?id='.$event['id'])?>"><b><?=e($event['name'])?></b><?php if($event['source']!==''):?><small><?=e($event['source'])?></small><?php endif?></a><?php endforeach; ?></div></div><?php endfor; ?>
      </div>
    </section>
  </section>
</main>
<style>
.calendar-page{padding:28px 24px 48px}.calendar-shell{display:grid;grid-template-columns:268px minmax(0,1fr);background:var(--card);border:1px solid var(--line);border-radius:10px;box-shadow:0 .25rem 1.125rem rgba(47,43,61,.1);overflow:hidden;min-height:690px}.calendar-sidebar{padding:24px 20px;border-right:1px solid var(--line);background:var(--card)}.calendar-add{display:block;padding:12px 14px;border-radius:6px;background:#20a447;color:#fff;text-decoration:none;text-align:center;font-weight:700}.mini-calendar{padding:22px 4px 18px}.mini-calendar-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:13px}.mini-calendar-head a{color:var(--text);text-decoration:none;font-size:22px;line-height:1}.mini-week{word-spacing:9px;font-size:11px;color:var(--muted);white-space:nowrap}.mini-days{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;margin-top:7px;text-align:center}.mini-days a,.mini-days span{display:grid;place-items:center;height:28px;border-radius:50%;font-size:12px;text-decoration:none;color:var(--text)}.mini-days a.today{background:#20a447;color:#fff}.mini-days a.has-event:not(.today){font-weight:700;color:#20a447}.calendar-sidebar hr{border:0;border-top:1px solid var(--line);margin:0 -20px 22px}.calendar-sidebar h3{margin:0 0 16px;font-size:15px}.calendar-filter{display:flex;align-items:center;gap:10px;margin:13px 0;font-size:14px;color:var(--text);cursor:pointer}.calendar-filter input{display:none}.calendar-filter span{width:15px;height:15px;border-radius:3px;background:#9b9baa;box-shadow:inset 0 0 0 2px #fff}.calendar-filter input:checked+span{box-shadow:inset 0 0 0 3px var(--card)}.calendar-filter.approval span{background:#20a447}.calendar-filter.considering span{background:#f3a64a}.calendar-filter.rejected span{background:#e44b4b}.calendar-filter.other span{background:#7467f0}.calendar-count{margin:28px 0 0;color:var(--muted);font-size:13px}.calendar-count b{color:#20a447}.calendar-content{min-width:0}.calendar-toolbar{min-height:82px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line)}.calendar-toolbar h1{margin:0;font-size:22px;font-weight:600}.calendar-nav{display:flex;gap:8px}.calendar-nav a,.calendar-view{display:grid;place-items:center;min-width:36px;height:34px;padding:0 11px;border:1px solid var(--line);border-radius:6px;text-decoration:none;color:var(--text);font-size:14px;background:var(--card)}.calendar-nav a:nth-child(2){font-weight:600}.calendar-view{color:#20a447;border-color:#ccebd7}.calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));overflow:auto}.calendar-weekday{padding:15px 12px;border-bottom:1px solid var(--line);border-right:1px solid var(--line);font-size:12px;font-weight:700;text-transform:uppercase;color:var(--muted)}.calendar-day{min-height:125px;padding:9px;border-bottom:1px solid var(--line);border-right:1px solid var(--line);background:var(--card)}.calendar-day.muted{background:rgba(31,31,48,.018)}.calendar-day.is-today .day-number{display:grid;place-items:center;width:28px;height:28px;border-radius:50%;background:#20a447;color:#fff}.day-number{font-size:13px;color:var(--text)}.day-events{display:grid;gap:5px;margin-top:7px}.calendar-event{display:block;padding:5px 7px;border-left:3px solid;text-decoration:none;border-radius:3px;font-size:11px;line-height:1.25;background:#eff9f2;color:#176433}.calendar-event b,.calendar-event small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.calendar-event small{font-size:10px;opacity:.75;margin-top:2px}.calendar-event.approval{background:#e7f8ed;border-color:#20a447;color:#126a30}.calendar-event.considering{background:#fff3df;border-color:#f3a64a;color:#9b5b06}.calendar-event.rejected{background:#ffe9e9;border-color:#e44b4b;color:#9f2727}.calendar-event.other{background:#eeecff;border-color:#7467f0;color:#4f43b0}[data-theme=dark] .calendar-shell,[data-theme=dark] .calendar-sidebar,[data-theme=dark] .calendar-day,[data-theme=dark] .calendar-nav a,[data-theme=dark] .calendar-view{background:#30334d}[data-theme=dark] .calendar-day.muted{background:#292c43}[data-theme=dark] .calendar-event.approval{background:#1b4930;color:#d7f9e1}[data-theme=dark] .calendar-event.considering{background:#59401e;color:#ffe6b5}[data-theme=dark] .calendar-event.rejected{background:#5d2d34;color:#ffd9dc}[data-theme=dark] .calendar-event.other{background:#423b72;color:#e0dcff}@media(max-width:900px){.calendar-page{padding:20px 12px}.calendar-shell{grid-template-columns:1fr}.calendar-sidebar{border-right:0;border-bottom:1px solid var(--line)}.calendar-grid{grid-template-columns:repeat(7,minmax(108px,1fr))}.calendar-sidebar{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 24px}.calendar-add{align-self:start;margin-top:8px}.mini-calendar{grid-row:span 2}.calendar-sidebar hr{display:none}.calendar-sidebar h3{margin-top:18px}}@media(max-width:600px){.calendar-sidebar{display:block}.calendar-toolbar{padding:0 12px}.calendar-toolbar h1{font-size:17px}.calendar-nav{gap:3px}.calendar-nav a{padding:0 8px}.calendar-grid{grid-template-columns:repeat(7,minmax(92px,1fr))}.calendar-day{min-height:108px}}
</style>
<style>.day-head{display:flex;align-items:center;justify-content:space-between}.calendar-new{display:grid;place-items:center;width:24px;height:24px;border-radius:5px;text-decoration:none;color:#20a447;font-size:18px;line-height:1}.calendar-new:hover{background:#e8f7ed}[data-theme=dark] .calendar-new:hover{background:#3e4b50}</style>
<script>
(()=>{
 const filters=[...document.querySelectorAll('.calendar-filter input')];
 const events=[...document.querySelectorAll('.calendar-event')];
 const apply=()=>{const enabled=new Set(filters.filter(input=>input.checked&&input.dataset.filter!=='all').map(input=>input.dataset.filter));const all=filters.find(input=>input.dataset.filter==='all');if(all?.checked||enabled.size===4){events.forEach(event=>event.hidden=false);return}events.forEach(event=>event.hidden=!enabled.has(event.dataset.category));};
 filters.forEach(input=>input.addEventListener('change',()=>{if(input.dataset.filter==='all')filters.filter(item=>item.dataset.filter!=='all').forEach(item=>item.checked=input.checked);else{const all=filters.find(item=>item.dataset.filter==='all');if(all)all.checked=filters.filter(item=>item.dataset.filter!=='all').every(item=>item.checked)}apply()}));
})();
</script>
<?php patient_footer(); ?>
