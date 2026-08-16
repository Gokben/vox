<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/source-bootstrap.php';
require __DIR__ . '/appointment-bootstrap.php';
ensure_patient_source_schema();
require __DIR__ . '/patient-layout.php';

$pdo = db();
ensure_appointment_schema($pdo);

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
$appointmentStatement = $pdo->prepare('SELECT a.*,b.name AS branch_name FROM appointments a LEFT JOIN branches b ON b.id=a.branch_id WHERE a.appointment_date>=? AND a.appointment_date<? ORDER BY a.appointment_date,a.appointment_time,a.id');
$appointmentStatement->execute([$firstDay->format('Y-m-d'), $nextMonth->format('Y-m-d')]);
$appointments = $appointmentStatement->fetchAll();
$patientsForAppointment = $pdo->query('SELECT id,full_name,phone_primary FROM patients ORDER BY full_name,id')->fetchAll();
$patientAppointmentStatement = $pdo->prepare('SELECT s.id,s.patient_id,s.appointment_date,s.start_time,s.appointment_status,COALESCE(NULLIF(s.contact_person,\'\'),(SELECT previous_service.contact_person FROM patient_services previous_service WHERE previous_service.patient_id=s.patient_id AND NULLIF(previous_service.contact_person,\'\') IS NOT NULL ORDER BY previous_service.id DESC LIMIT 1),\'\') AS contact_person,COALESCE(b.name,NULLIF(s.branch_name,\'\'),pb.name,\'\') AS branch_name,p.full_name FROM patient_services s INNER JOIN patients p ON p.id=s.patient_id LEFT JOIN branches b ON b.id=s.branch_id LEFT JOIN branches pb ON pb.id=p.branch_id WHERE s.appointment_date>=? AND s.appointment_date<? ORDER BY s.appointment_date,s.start_time,s.id');
$patientAppointmentStatement->execute([$firstDay->format('Y-m-d'), $nextMonth->format('Y-m-d')]);
$patientAppointments = $patientAppointmentStatement->fetchAll();
$appointmentsByDate = [];
foreach ($appointments as $appointment) {
    $appointmentsByDate[(string)$appointment['appointment_date']][] = [
        'id' => (int)$appointment['id'],
        'name' => (string)$appointment['full_name'],
        'time' => substr((string)$appointment['appointment_time'], 0, 5),
        'type' => (string)($appointment['event_type'] ?? 'appointment'),
        'record_type' => 'appointment',
        'branch' => (string)($appointment['branch_name'] ?? ''),
        'contact_person' => (string)($appointment['contact_person'] ?? ''),
        'url' => url('appointment-form.php?id=' . (int)$appointment['id']),
    ];
}
foreach ($patientAppointments as $patientAppointment) {
    $appointmentsByDate[(string)$patientAppointment['appointment_date']][] = [
        'id' => (int)$patientAppointment['id'],
        'name' => (string)$patientAppointment['full_name'],
        'time' => substr((string)$patientAppointment['start_time'], 0, 5),
        'type' => 'patient_appointment',
        'record_type' => 'patient_service',
        'branch' => (string)($patientAppointment['branch_name'] ?? ''),
        'contact_person' => (string)($patientAppointment['contact_person'] ?? ''),
        'url' => url('patient-followup.php?id=' . (int)$patientAppointment['patient_id'] . '&edit=' . (int)$patientAppointment['id']),
    ];
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
      <a class="calendar-add" href="<?= url('patient-form.php?date='.date('Y-m-d')) ?>" title="Randevu Ekle" aria-label="Randevu Ekle"><i class="ti tabler-calendar-plus" aria-hidden="true"></i></a>
      <a class="calendar-add calendar-add-daily" href="<?= url('appointment-form.php?type=daily_event&date='.date('Y-m-d')) ?>" title="Günlük Olay Ekle" aria-label="Günlük Olay Ekle"><i class="ti tabler-tools" aria-hidden="true"></i></a>
      <button class="calendar-add calendar-add-patient" type="button" id="patient-appointment-open" title="Hasta Randevu" aria-label="Hasta Randevu"><i class="ti tabler-stethoscope" aria-hidden="true"></i></button>
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
        <div class="calendar-view-tabs" role="tablist" aria-label="Takvim görünümü"><button type="button" class="active" data-calendar-view="month">Ay</button><button type="button" data-calendar-view="week">Hafta</button><button type="button" data-calendar-view="day">Gün</button><button type="button" data-calendar-view="list">Liste</button></div>
      </header>
      <div class="calendar-grid">
        <?php foreach(['Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar'] as $weekDay):?><div class="calendar-weekday"><?=$weekDay?></div><?php endforeach; ?>
        <?php for($blank=0;$blank<$leadingDays;$blank++):?><div class="calendar-day muted"></div><?php endfor; ?>
        <?php for($day=1;$day<=$daysInMonth;$day++):$date=$month.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT);$events=$eventsByDate[$date]??[];?><div class="calendar-day <?= $date===date('Y-m-d')?'is-today':'' ?>" id="day-<?=$day?>" data-date="<?=$date?>"><div class="day-head"><span class="day-number"><?=$day?></span><a class="calendar-new" href="<?=url('patient-form.php?date='.$date)?>" title="Bu tarihe hasta kaydı ekle">＋</a></div><div class="day-events"><?php foreach($events as $event):?><a class="calendar-event <?=$event['category']?>" data-category="<?=$event['category']?>" href="<?=url('patient-form.php?id='.$event['id'])?>"><b><?=e($event['name'])?></b><?php if($event['source']!==''):?><small><?=e($event['source'])?></small><?php endif?></a><?php endforeach; ?></div></div><?php endfor; ?>
      </div>
    </section>
  </section>
  <section class="appointment-list"><header><h2>Randevu Listesi</h2><span><?=count($appointments)?> randevu</span></header><?php if (($_GET['appointment_saved'] ?? '') === '1'): ?><div class="appointment-saved">Randevu kaydedildi.</div><?php endif ?><div class="appointment-list-wrap"><table><thead><tr><th>Tarih</th><th>Saat</th><th>Ad Soyad</th><th>Telefon</th><th>Şube</th><th>İlgilenen Kişi</th><th>Not</th></tr></thead><tbody><?php foreach ($appointments as $appointment): ?><tr><td><?=e(format_date_tr($appointment['appointment_date']))?></td><td><?=e(substr((string)$appointment['appointment_time'],0,5))?></td><td><?=e($appointment['full_name'])?></td><td><?=e($appointment['phone'] ?: '—')?></td><td><?=e($appointment['branch_name'] ?: '—')?></td><td><?=e($appointment['contact_person'] ?: '—')?></td><td><?=e($appointment['note'] ?: '—')?></td></tr><?php endforeach; if (!$appointments): ?><tr><td colspan="7" class="appointment-empty">Bu ay için randevu bulunmuyor.</td></tr><?php endif ?></tbody></table></div></section>
</main>
<div class="patient-appointment-modal" id="patient-appointment-modal" hidden aria-labelledby="patient-appointment-title" role="dialog" aria-modal="true">
  <div class="patient-appointment-backdrop" data-patient-appointment-close></div>
  <section class="patient-appointment-dialog">
    <header><h2 id="patient-appointment-title"><i class="ti tabler-stethoscope" aria-hidden="true"></i> Hasta Randevu</h2><button type="button" class="patient-appointment-close" data-patient-appointment-close aria-label="Kapat">×</button></header>
    <form method="get" action="<?=e(url('patient-followup.php'))?>">
      <input type="hidden" name="new" value="1">
      <label>Hasta seçin
        <input type="search" id="patient-appointment-search" placeholder="Ad veya telefon ile ara" autocomplete="off">
        <select name="id" id="patient-appointment-select" required hidden>
          <option value="">Hasta seçiniz</option>
          <?php foreach ($patientsForAppointment as $patientOption): ?><option value="<?=(int)$patientOption['id']?>"><?=e($patientOption['full_name'])?><?=trim((string)$patientOption['phone_primary']) !== '' ? ' — '.e($patientOption['phone_primary']) : ''?></option><?php endforeach; ?>
        </select>
        <div class="patient-appointment-results" id="patient-appointment-results" role="listbox" aria-label="Hasta arama sonuçları" hidden></div>
      </label>
      <footer><button type="button" class="patient-appointment-cancel" data-patient-appointment-close>İptal</button><button class="patient-appointment-submit" type="submit" title="Yeni Hizmet Kartını Aç" aria-label="Yeni Hizmet Kartını Aç"><i class="ti tabler-calendar-plus" aria-hidden="true"></i></button></footer>
    </form>
  </section>
</div>
<style>
.calendar-page{padding:28px 24px 48px}.calendar-shell{display:grid;grid-template-columns:268px minmax(0,1fr);background:var(--card);border:1px solid var(--line);border-radius:10px;box-shadow:0 .25rem 1.125rem rgba(47,43,61,.1);overflow:hidden;min-height:690px}.calendar-sidebar{padding:24px 20px;border-right:1px solid var(--line);background:var(--card)}.calendar-add{display:block;padding:12px 14px;border-radius:6px;background:#20a447;color:#fff;text-decoration:none;text-align:center;font-weight:700}.mini-calendar{padding:22px 4px 18px}.mini-calendar-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:13px}.mini-calendar-head a{color:var(--text);text-decoration:none;font-size:22px;line-height:1}.mini-week{word-spacing:9px;font-size:11px;color:var(--muted);white-space:nowrap}.mini-days{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;margin-top:7px;text-align:center}.mini-days a,.mini-days span{display:grid;place-items:center;height:28px;border-radius:50%;font-size:12px;text-decoration:none;color:var(--text)}.mini-days a.today{background:#20a447;color:#fff}.mini-days a.has-event:not(.today){font-weight:700;color:#20a447}.calendar-sidebar hr{border:0;border-top:1px solid var(--line);margin:0 -20px 22px}.calendar-sidebar h3{margin:0 0 16px;font-size:15px}.calendar-filter{display:flex;align-items:center;gap:10px;margin:13px 0;font-size:14px;color:var(--text);cursor:pointer}.calendar-filter input{display:none}.calendar-filter span{width:15px;height:15px;border-radius:3px;background:#9b9baa;box-shadow:inset 0 0 0 2px #fff}.calendar-filter input:checked+span{box-shadow:inset 0 0 0 3px var(--card)}.calendar-filter.approval span{background:#20a447}.calendar-filter.considering span{background:#f3a64a}.calendar-filter.rejected span{background:#e44b4b}.calendar-filter.other span{background:#7467f0}.calendar-count{margin:28px 0 0;color:var(--muted);font-size:13px}.calendar-count b{color:#20a447}.calendar-content{min-width:0}.calendar-toolbar{min-height:82px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line)}.calendar-toolbar h1{margin:0;font-size:22px;font-weight:600}.calendar-nav{display:flex;gap:8px}.calendar-nav a{display:grid;place-items:center;min-width:36px;height:34px;padding:0 11px;border:1px solid var(--line);border-radius:6px;text-decoration:none;color:var(--text);font-size:14px;background:var(--card)}.calendar-nav a:nth-child(2){font-weight:600}.calendar-view-tabs{display:flex;overflow:hidden;border-radius:6px;background:#f0efff}.calendar-view-tabs button{min-width:66px;height:38px;padding:0 16px;border:0;border-right:1px solid #d6d3fc;background:#e9e7ff;color:#7367f0;font:inherit;font-weight:600;cursor:pointer}.calendar-view-tabs button:last-child{border-right:0}.calendar-view-tabs button.active{background:#dcd9ff;color:#6559ed}.calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));overflow:auto}.calendar-weekday{padding:15px 12px;border-bottom:1px solid var(--line);border-right:1px solid var(--line);font-size:12px;font-weight:700;text-transform:uppercase;color:var(--muted)}.calendar-day{min-height:125px;padding:9px;border-bottom:1px solid var(--line);border-right:1px solid var(--line);background:var(--card)}.calendar-day.muted{background:rgba(31,31,48,.018)}.calendar-day.is-today .day-number{display:grid;place-items:center;width:28px;height:28px;border-radius:50%;background:#20a447;color:#fff}.day-number{font-size:13px;color:var(--text)}.day-events{display:grid;gap:5px;margin-top:7px}.calendar-event{display:block;padding:5px 7px;border-left:3px solid;text-decoration:none;border-radius:3px;font-size:11px;line-height:1.25;background:#eff9f2;color:#176433}.calendar-event b,.calendar-event small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.calendar-event small{font-size:10px;opacity:.75;margin-top:2px}.calendar-event.approval{background:#e7f8ed;border-color:#20a447;color:#126a30}.calendar-event.considering{background:#fff3df;border-color:#f3a64a;color:#9b5b06}.calendar-event.rejected{background:#ffe9e9;border-color:#e44b4b;color:#9f2727}.calendar-event.other{background:#eeecff;border-color:#7467f0;color:#4f43b0}.calendar-event-list{display:none;padding:20px 24px}.calendar-event-list.visible{display:grid;gap:8px}.calendar-event-list a{padding:11px 13px;border-radius:6px;text-decoration:none}.calendar-event-list b{display:block}.calendar-event-list small{display:block;margin-top:3px;color:inherit;opacity:.75}[data-theme=dark] .calendar-shell,[data-theme=dark] .calendar-sidebar,[data-theme=dark] .calendar-day,[data-theme=dark] .calendar-nav a{background:#30334d}[data-theme=dark] .calendar-day.muted{background:#292c43}[data-theme=dark] .calendar-event.approval{background:#1b4930;color:#d7f9e1}[data-theme=dark] .calendar-event.considering{background:#59401e;color:#ffe6b5}[data-theme=dark] .calendar-event.rejected{background:#5d2d34;color:#ffd9dc}[data-theme=dark] .calendar-event.other{background:#423b72;color:#e0dcff}@media(max-width:900px){.calendar-page{padding:20px 12px}.calendar-shell{grid-template-columns:1fr}.calendar-sidebar{border-right:0;border-bottom:1px solid var(--line)}.calendar-grid{grid-template-columns:repeat(7,minmax(108px,1fr))}.calendar-sidebar{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 24px}.calendar-add{align-self:start;margin-top:8px}.mini-calendar{grid-row:span 2}.calendar-sidebar hr{display:none}.calendar-sidebar h3{margin-top:18px}}@media(max-width:600px){.calendar-sidebar{display:block}.calendar-toolbar{padding:0 12px}.calendar-toolbar h1{font-size:17px}.calendar-nav{gap:3px}.calendar-nav a{padding:0 8px}.calendar-view-tabs button{min-width:auto;padding:0 10px}.calendar-grid{grid-template-columns:repeat(7,minmax(92px,1fr))}.calendar-day{min-height:108px}}
</style>
<style>.calendar-event-list{padding:0!important;background:var(--card)}.calendar-event-list.visible{display:block!important}.calendar-list-group{border-bottom:1px solid var(--line)}.calendar-list-date{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;background:rgba(47,43,61,.045);color:#2f2b3d;font-weight:600;font-size:14px}.calendar-list-date span:last-child{font-weight:500}.calendar-list-row{display:grid;grid-template-columns:150px 18px minmax(0,1fr);align-items:center;min-height:38px;padding:0 16px;border-top:1px solid var(--line);color:var(--text);text-decoration:none;font-size:14px}.calendar-list-row:hover{background:#f7f7fb}.calendar-list-time{color:var(--muted)}.calendar-list-dot{width:10px;height:10px;border-radius:50%}.calendar-list-empty{padding:24px;color:var(--muted)}</style>
<style>.appointment-list{margin-top:24px;padding:22px 24px;border:1px solid var(--line);border-radius:10px;background:var(--card);box-shadow:0 3px 12px #1e283c0f}.appointment-list header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}.appointment-list h2{margin:0;font-size:18px}.appointment-list header span{color:var(--muted);font-size:13px}.appointment-list-wrap{overflow:auto}.appointment-list table{width:100%;min-width:820px;border-collapse:collapse}.appointment-list th,.appointment-list td{padding:12px 10px;border-bottom:1px solid var(--line);text-align:left;font-size:13px}.appointment-list th{font-size:12px;color:var(--muted)}.appointment-empty{text-align:center;color:var(--muted)}.appointment-saved{margin:-4px 0 14px;padding:10px 12px;border-radius:6px;background:#e7f8ed;color:#126a30;font-weight:700;font-size:13px}</style>
<style>.day-head{display:flex;align-items:center;justify-content:space-between}.calendar-new{display:grid;place-items:center;width:24px;height:24px;border-radius:5px;text-decoration:none;color:#20a447;font-size:18px;line-height:1}.calendar-new:hover{background:#e8f7ed}[data-theme=dark] .calendar-new:hover{background:#3e4b50}.calendar-patient-appointment{background:#f3ecff!important;border-color:#7b55c7!important;color:#51318f!important}</style>
<script>
(()=>{
 const filters=[...document.querySelectorAll('.calendar-filter input')];
 const events=[...document.querySelectorAll('.calendar-event')];
 const apply=()=>{const enabled=new Set(filters.filter(input=>input.checked&&input.dataset.filter!=='all').map(input=>input.dataset.filter));const all=filters.find(input=>input.dataset.filter==='all');if(all?.checked||enabled.size===4){events.forEach(event=>event.hidden=false);return}events.forEach(event=>event.hidden=!enabled.has(event.dataset.category));};
 filters.forEach(input=>input.addEventListener('change',()=>{if(input.dataset.filter==='all')filters.filter(item=>item.dataset.filter!=='all').forEach(item=>item.checked=input.checked);else{const all=filters.find(item=>item.dataset.filter==='all');if(all)all.checked=filters.filter(item=>item.dataset.filter!=='all').every(item=>item.checked)}apply()}));
})();
</script>
<style>.appointment-list{display:none}</style>
<style>.calendar-add-actions{display:grid;gap:10px}.calendar-add-daily{margin-top:0!important;background:#596273!important}.calendar-add-daily:hover{background:#424958!important}.calendar-appointment{background:#e9f7ff!important;border-color:#168cbe!important;color:#125a7a!important}.calendar-daily-event{background:#fff4df!important;border-color:#e6a12d!important;color:#8a5700!important}.calendar-appointment small,.calendar-daily-event small{font-weight:700}</style>
<script>
const calendarAppointments = <?=json_encode($appointmentsByDate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
Object.entries(calendarAppointments).forEach(([date, appointments]) => {
  const day = document.getElementById('day-' + Number(date.slice(-2)));
  const events = day?.querySelector('.day-events');
  if (!events) return;
  appointments.forEach(appointment => {
    const link = document.createElement('a');
    const dailyEvent = appointment.type === 'daily_event';
    const patientAppointment = appointment.type === 'patient_appointment';
    link.className = 'calendar-event ' + (dailyEvent ? 'calendar-daily-event' : (patientAppointment ? 'calendar-patient-appointment' : 'calendar-appointment'));
    link.href = appointment.url;
    if (appointment.record_type) {
      link.draggable = true;
      link.dataset.calendarRecordId = String(appointment.id);
      link.dataset.calendarRecordType = appointment.record_type;
      link.dataset.calendarEventType = appointment.type;
      link.title = 'Randevuyu başka bir güne taşımak için sürükleyin';
    }
    const name = document.createElement('b');
    name.textContent = appointment.name;
    const time = document.createElement('small');
    time.textContent = 'Randevu · ' + appointment.time;
    time.textContent = (dailyEvent ? 'Günlük olay' : (patientAppointment ? 'Hasta randevusu' : 'Randevu')) + ' · ' + appointment.time;
    link.append(name, time);
    if (appointment.branch) {
      const branch = document.createElement('small');
      branch.className = 'calendar-event-branch';
      branch.textContent = 'Şube · ' + appointment.branch;
      link.append(branch);
    }
    if (appointment.contact_person) {
      const contactPerson = document.createElement('small');
      contactPerson.className = 'calendar-event-contact';
      contactPerson.textContent = 'İlgilenen · ' + appointment.contact_person;
      link.append(contactPerson);
    }
    events.append(link);
  });
});
</script>
<script>
(() => {
  const grid = document.querySelector('.calendar-grid');
  const tabs = [...document.querySelectorAll('[data-calendar-view]')];
  const shell = document.querySelector('.calendar-shell');
  if (!grid || !tabs.length) return;
  const month = <?=json_encode($month)?>;
  const days = [...grid.querySelectorAll('.calendar-day:not(.muted)')];
  const list = document.createElement('div');
  list.className = 'calendar-event-list';
  const timeView = document.createElement('section');
  timeView.className = 'calendar-time-view';
  grid.after(timeView, list);
  const toolbarTitle = document.querySelector('.calendar-toolbar h1');
  const monthTitle = toolbarTitle?.textContent || '';
  const reference = new Date(month + '-01T12:00:00');
  const today = new Date();
  let selected = today.getFullYear() === reference.getFullYear() && today.getMonth() === reference.getMonth() ? today : reference;
  const dayNumber = date => date.getDate();
  const dayNames = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
  const shortDayNames = ['Paz','Pzt','Sal','Çar','Per','Cum','Cmt'];
  const monthNames = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
  const dateKey = date => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
  const eventsFor = date => {
    const day = document.getElementById('day-' + dayNumber(date));
    if (!day || date.getMonth() !== reference.getMonth()) return [];
    return [...day.querySelectorAll('.calendar-event')].filter(event => !event.hidden).map(event => {
      const meta = event.querySelector('small')?.textContent || '';
      const time = meta.match(/(\d{1,2}:\d{2})/)?.[1] || '';
      return {name:event.querySelector('b')?.textContent || event.textContent.trim(),time,href:event.href,color:getComputedStyle(event).borderLeftColor,allDay:!time};
    });
  };
  const renderTimeView = view => {
    const dates = view === 'day' ? [selected] : Array.from({length:7},(_,index)=>{const date=new Date(selected);date.setDate(selected.getDate()-((selected.getDay()+6)%7)+index);return date;});
    timeView.replaceChildren();
    timeView.style.setProperty('--calendar-columns', String(dates.length));
    const header = document.createElement('div'); header.className='calendar-time-head';
    header.append(document.createElement('div'));
    dates.forEach(date=>{const cell=document.createElement('div');cell.className='calendar-time-day-head';if(dateKey(date)===dateKey(today))cell.classList.add('is-today');cell.innerHTML=`<b>${view==='day'?dayNames[date.getDay()]:shortDayNames[date.getDay()]}</b><span>${date.getDate()}</span>`;header.append(cell);});
    const allDay = document.createElement('div');allDay.className='calendar-time-allday';allDay.innerHTML='<div class="calendar-time-label">Tüm gün</div>';
    dates.forEach(date=>{const cell=document.createElement('div');cell.className='calendar-time-all-cell';eventsFor(date).filter(event=>event.allDay).forEach(event=>{const item=document.createElement('a');item.href=event.href;item.className='calendar-time-event';item.style.setProperty('--event-color',event.color);item.textContent=event.name;cell.append(item);});allDay.append(cell);});
    const body=document.createElement('div');body.className='calendar-time-body';
    for(let hour=8;hour<=19;hour++){const row=document.createElement('div');row.className='calendar-time-row';const label=document.createElement('div');label.className='calendar-time-label';label.textContent=String(hour).padStart(2,'0')+':00';row.append(label);dates.forEach(date=>{const cell=document.createElement('div');cell.className='calendar-time-cell';eventsFor(date).filter(event=>event.time&&Number(event.time.slice(0,2))===hour).forEach(event=>{const item=document.createElement('a');item.href=event.href;item.className='calendar-time-event timed';item.style.setProperty('--event-color',event.color);item.innerHTML=`<b>${event.time}</b> ${event.name}`;cell.append(item);});row.append(cell);});body.append(row);}
    timeView.append(header,allDay,body);
  };
  const setView = view => {
    tabs.forEach(tab => tab.classList.toggle('active', tab.dataset.calendarView === view));
    shell?.classList.toggle('list-mode', view === 'list');
    const timeMode = view === 'week' || view === 'day';
    if (toolbarTitle) {
      if (view === 'day') toolbarTitle.textContent = `${selected.getDate()} ${monthNames[selected.getMonth()]} ${selected.getFullYear()}`;
      else if (view === 'week') { const first = new Date(selected); first.setDate(selected.getDate()-((selected.getDay()+6)%7)); const last = new Date(first); last.setDate(first.getDate()+6); toolbarTitle.textContent = `${first.getDate()} - ${last.getDate()} ${monthNames[last.getMonth()]} ${last.getFullYear()}`; }
      else toolbarTitle.textContent = monthTitle;
    }
    grid.hidden = view === 'list' || timeMode;
    list.classList.toggle('visible', view === 'list');
    timeView.hidden = !timeMode;
    days.forEach(day => day.hidden = false);
    if (timeMode) renderTimeView(view);
    if (view === 'list') {
      list.replaceChildren();
      days.forEach(day => day.querySelectorAll('.calendar-event').forEach(event => {
        const item = event.cloneNode(true);
        const date = document.createElement('small');
        date.textContent = String(day.querySelector('.day-number')?.textContent || '') + '.' + month.slice(5) + '.' + month.slice(0,4);
        item.append(date); list.append(item);
      }));
      if (!list.children.length) list.textContent = 'Bu görünüm için kayıt bulunmuyor.';
    }
  };
  tabs.forEach(tab => tab.addEventListener('click', () => setView(tab.dataset.calendarView)));
})();
</script>
<script>
(() => {
  const listTab = document.querySelector('[data-calendar-view="list"]');
  const list = document.querySelector('.calendar-event-list');
  const grid = document.querySelector('.calendar-grid');
  const shell = document.querySelector('.calendar-shell');
  if (!listTab || !list || !grid) return;
  const month = <?=json_encode($month)?>;
  const monthNames = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
  const weekDays = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
  const renderList = () => {
    list.replaceChildren();
    [...document.querySelectorAll('.calendar-day:not(.muted)')].forEach(day => {
      const events = [...day.querySelectorAll('.calendar-event')];
      if (!events.length) return;
      const dayNumber = Number(day.id.replace('day-', ''));
      const date = new Date(month + '-' + String(dayNumber).padStart(2, '0') + 'T12:00:00');
      const group = document.createElement('section'); group.className = 'calendar-list-group';
      const heading = document.createElement('header'); heading.className = 'calendar-list-date';
      const title = document.createElement('span'); title.textContent = dayNumber + ' ' + monthNames[date.getMonth()] + ' ' + date.getFullYear();
      const weekDay = document.createElement('span'); weekDay.textContent = weekDays[date.getDay()];
      heading.append(title, weekDay); group.append(heading);
      events.forEach(event => {
        const row = document.createElement('a'); row.className = 'calendar-list-row'; row.href = event.href;
        const time = document.createElement('span'); time.className = 'calendar-list-time'; time.textContent = event.querySelector('small')?.textContent || 'Tüm gün';
        const dot = document.createElement('span'); dot.className = 'calendar-list-dot'; dot.style.background = getComputedStyle(event).borderLeftColor;
        const name = document.createElement('span'); name.textContent = event.querySelector('b')?.textContent || event.textContent;
        row.append(time, dot, name); group.append(row);
      });
      list.append(group);
    });
    if (!list.children.length) { const empty = document.createElement('div'); empty.className = 'calendar-list-empty'; empty.textContent = 'Bu görünüm için kayıt bulunmuyor.'; list.append(empty); }
  };
  listTab.addEventListener('click', () => { list.classList.add('visible'); grid.style.setProperty('display', 'none', 'important'); shell?.classList.add('list-mode'); setTimeout(renderList, 0); });
  document.querySelectorAll('[data-calendar-view]:not([data-calendar-view="list"])').forEach(tab => tab.addEventListener('click', () => { list.classList.remove('visible'); grid.style.removeProperty('display'); shell?.classList.remove('list-mode'); }));
})();
</script>
<style>.calendar-event-list .calendar-list-row{display:grid;padding:0 16px;border-radius:0}.calendar-event-list .calendar-list-row:hover{background:#f7f7fb}.calendar-event-list .calendar-list-date{padding:10px 16px;border-radius:0}</style>
<style>.calendar-grid[hidden]{display:none!important}</style>
<style>
.calendar-time-view{background:#fff;min-width:0;overflow:auto}.calendar-time-view[hidden]{display:none!important}.calendar-time-head,.calendar-time-allday,.calendar-time-row{display:grid;grid-template-columns:70px repeat(var(--calendar-columns,7),minmax(130px,1fr))}.calendar-time-head{position:sticky;top:0;z-index:3;background:#fff;border-bottom:1px solid #e7e6ed}.calendar-time-head>div:first-child{border-right:1px solid #e7e6ed}.calendar-time-day-head{height:58px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;border-right:1px solid #e7e6ed;color:#5f5b6e;font-size:13px}.calendar-time-day-head b{font-weight:500}.calendar-time-day-head span{display:grid;place-items:center;width:25px;height:25px;border-radius:50%;font-size:13px}.calendar-time-day-head.is-today span{background:#7367f0;color:#fff}.calendar-time-allday{min-height:58px;border-bottom:1px solid #e7e6ed;background:#fbfbfc}.calendar-time-label{padding:12px 10px;text-align:right;border-right:1px solid #e7e6ed;color:#9693a0;font-size:12px}.calendar-time-all-cell,.calendar-time-cell{position:relative;min-height:58px;padding:5px;border-right:1px solid #e7e6ed}.calendar-time-body{max-height:620px;overflow-y:auto}.calendar-time-row{min-height:58px;border-bottom:1px solid #e7e6ed}.calendar-time-cell{background:repeating-linear-gradient(to bottom,#fff 0,#fff 28px,#f3f2f5 29px,#fff 30px)}.calendar-time-event{display:block;min-height:22px;margin:1px 0;padding:4px 6px;border-left:3px solid var(--event-color,#7367f0);border-radius:3px;background:#f1efff;color:#4b427f;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px}.calendar-time-event.timed{background:#e9f7ff;border-left-color:#168cbe;color:#125a7a}.calendar-time-event b{font-weight:700}@media(max-width:900px){.calendar-time-head,.calendar-time-allday,.calendar-time-row{grid-template-columns:58px repeat(var(--calendar-columns,7),minmax(110px,1fr))}.calendar-time-label{font-size:11px;padding-left:5px;padding-right:5px}}
</style>
<style>
.calendar-shell.list-mode{grid-template-columns:300px minmax(0,1fr);min-height:700px;border-color:#e7e6ed;border-radius:8px;box-shadow:0 5px 18px rgba(47,43,61,.08)}
.calendar-shell.list-mode .calendar-sidebar{padding:24px;background:#fff;border-right-color:#e7e6ed}.calendar-shell.list-mode .calendar-add-actions{gap:0}.calendar-shell.list-mode .calendar-add{min-height:39px;padding:0 14px;display:flex;align-items:center;justify-content:center;background:#7367f0!important;border-radius:6px;box-shadow:0 3px 7px rgba(115,103,240,.32)}.calendar-shell.list-mode .calendar-add-daily{display:none}.calendar-shell.list-mode .mini-calendar{padding:31px 4px 20px}.calendar-shell.list-mode .mini-calendar-head{font-size:15px;font-weight:500}.calendar-shell.list-mode .mini-calendar-head a{display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:#f0f0f3;color:#5f5b6e;font-size:20px}.calendar-shell.list-mode .mini-week{word-spacing:7px;font-size:12px;color:#5e5b6b}.calendar-shell.list-mode .mini-days{gap:5px;margin-top:13px}.calendar-shell.list-mode .mini-days a,.calendar-shell.list-mode .mini-days span{height:29px;font-size:14px}.calendar-shell.list-mode .mini-days a.today{background:#e9e6ff;color:#7367f0}.calendar-shell.list-mode .calendar-sidebar hr{margin:0 -24px 24px}.calendar-shell.list-mode .calendar-sidebar h3{font-size:18px;font-weight:500}.calendar-shell.list-mode .calendar-filter{margin:15px 0;font-size:15px}.calendar-shell.list-mode .calendar-filter span{width:18px;height:18px;border-radius:4px}.calendar-shell.list-mode .calendar-toolbar{min-height:86px;padding:0 36px;border-bottom-color:#e7e6ed}.calendar-shell.list-mode .calendar-toolbar h1{font-size:25px;font-weight:500}.calendar-shell.list-mode .calendar-nav{gap:20px}.calendar-shell.list-mode .calendar-nav a{min-width:auto;padding:0;border:0;background:transparent;font-size:30px;color:#46435a}.calendar-shell.list-mode .calendar-nav a:nth-child(2){display:none}.calendar-shell.list-mode .calendar-view-tabs{border-radius:6px;background:#e9e7ff}.calendar-shell.list-mode .calendar-view-tabs button{height:38px;min-width:74px;background:#e9e7ff;border-color:#d6d3fc;color:#7367f0;font-weight:500}.calendar-shell.list-mode .calendar-view-tabs button.active{background:#dcd9ff}.calendar-shell.list-mode .calendar-event-list{font-size:15px}.calendar-shell.list-mode .calendar-list-date{height:38px;padding:0 16px;background:#f5f5f6;color:#28253d;font-size:14px;font-weight:500}.calendar-shell.list-mode .calendar-list-date span:last-child{font-weight:500}.calendar-shell.list-mode .calendar-list-row{grid-template-columns:150px 18px minmax(0,1fr);min-height:38px;padding:0 16px;border-top-color:#e7e6ed;color:#6e6b7b;font-size:15px}.calendar-shell.list-mode .calendar-list-time{color:#777484}.calendar-shell.list-mode .calendar-list-dot{width:10px;height:10px}.calendar-shell.list-mode .calendar-list-group{border-bottom:0}.calendar-shell.list-mode .calendar-list-row:hover{background:#fafafe}@media(max-width:900px){.calendar-shell.list-mode{grid-template-columns:1fr}.calendar-shell.list-mode .calendar-toolbar{padding:0 14px}.calendar-shell.list-mode .calendar-view-tabs button{min-width:auto;padding:0 10px}}
</style>
<style>
.calendar-add-actions{display:grid;gap:10px}.calendar-add{display:flex!important;align-items:center;justify-content:center;gap:9px;width:100%;box-sizing:border-box;border:0;cursor:pointer}.calendar-add i{font-size:20px;line-height:1}.calendar-add-patient{background:#2c75c9!important}.calendar-add-patient:hover{background:#225f9f!important}.patient-appointment-modal[hidden]{display:none!important}.patient-appointment-modal{position:fixed;z-index:1100;inset:0;display:grid;place-items:center;padding:20px}.patient-appointment-backdrop{position:absolute;inset:0;background:rgba(32,33,45,.55)}.patient-appointment-dialog{position:relative;width:min(520px,100%);overflow:hidden;border-radius:10px;background:var(--card);box-shadow:0 18px 46px rgba(0,0,0,.3)}.patient-appointment-dialog header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--line)}.patient-appointment-dialog h2{margin:0;font-size:19px}.patient-appointment-dialog h2 i{margin-right:8px;color:#2c75c9}.patient-appointment-close{border:0;background:transparent;color:var(--muted);font-size:28px;cursor:pointer}.patient-appointment-dialog form{padding:22px 24px}.patient-appointment-dialog label{display:grid;gap:8px;font-size:14px;font-weight:600}.patient-appointment-dialog input,.patient-appointment-dialog select{width:100%;height:42px;box-sizing:border-box;padding:0 12px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--text);font:inherit}.patient-appointment-results{max-height:220px;overflow:auto;border:1px solid var(--line);border-radius:6px;background:var(--card);box-shadow:0 8px 20px rgba(47,43,61,.12)}.patient-appointment-results[hidden]{display:none}.patient-appointment-result{display:block;width:100%;padding:11px 12px;border:0;border-bottom:1px solid var(--line);background:transparent;color:var(--text);font:inherit;text-align:left;cursor:pointer}.patient-appointment-result:last-child{border-bottom:0}.patient-appointment-result:hover,.patient-appointment-result:focus{background:#edf7ff;outline:0}.patient-appointment-no-result{padding:11px 12px;color:var(--muted);font-weight:400}.patient-appointment-dialog footer{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}.patient-appointment-dialog footer button{min-height:42px;padding:0 16px;border:0;border-radius:6px;background:#2c75c9;color:#fff;font:inherit;font-weight:700;cursor:pointer}.patient-appointment-dialog footer .patient-appointment-cancel{background:#eef0f5;color:#2f2b3d}.patient-appointment-dialog footer .patient-appointment-cancel,.patient-appointment-dialog footer .patient-appointment-submit{display:grid!important;place-items:center!important;box-sizing:border-box!important;flex:0 0 36px!important;width:36px!important;min-width:36px!important;max-width:36px!important;height:36px!important;min-height:36px!important;max-height:36px!important;padding:0!important}.patient-appointment-dialog footer .patient-appointment-submit{background:#ff7800}.patient-appointment-dialog footer .patient-appointment-submit:hover{background:#e86500}.patient-appointment-submit i{font-size:22px}
</style>
<script>
const calendarAddButtons=[...document.querySelectorAll('.calendar-sidebar>.calendar-add')];
if(calendarAddButtons.length){const wrapper=document.createElement('div');wrapper.className='calendar-add-actions';calendarAddButtons[0].before(wrapper);calendarAddButtons.forEach(button=>wrapper.append(button));}
document.querySelectorAll('a.calendar-add:not(.calendar-add-daily),a.calendar-new').forEach(link=>{link.href=link.href.replace('patient-form.php','appointment-form.php');if(link.classList.contains('calendar-new'))link.title='Bu tarihe randevu ekle';});
(()=>{const modal=document.getElementById('patient-appointment-modal'),open=document.getElementById('patient-appointment-open'),search=document.getElementById('patient-appointment-search'),select=document.getElementById('patient-appointment-select'),results=document.getElementById('patient-appointment-results');if(!modal||!open||!search||!select||!results)return;const options=[...select.options].slice(1);const close=()=>{modal.hidden=true;results.hidden=true;open.focus();};const showResults=()=>{const query=search.value.trim().toLocaleLowerCase('tr-TR');results.replaceChildren();if(query.length<3){results.hidden=true;return;}const matches=options.filter(option=>option.textContent.toLocaleLowerCase('tr-TR').includes(query)).slice(0,4);if(!matches.length){const empty=document.createElement('div');empty.className='patient-appointment-no-result';empty.textContent='Hasta bulunamadı.';results.append(empty);}matches.forEach(option=>{const item=document.createElement('button');item.type='button';item.className='patient-appointment-result';item.textContent=option.textContent;item.addEventListener('click',()=>{select.value=option.value;search.value=option.textContent;results.hidden=true;});results.append(item);});results.hidden=false;};open.addEventListener('click',()=>{modal.hidden=false;search.value='';select.value='';results.hidden=true;search.focus();});modal.querySelectorAll('[data-patient-appointment-close]').forEach(button=>button.addEventListener('click',close));search.addEventListener('input',()=>{select.value='';showResults();});select.addEventListener('change',()=>{if(select.value)search.value=select.selectedOptions[0].textContent;results.hidden=true;});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)close();});})();
</script>
<style>.calendar-day.drag-target{background:#fff5e8!important;box-shadow:inset 0 0 0 2px #ff7800}.calendar-event[draggable="true"]{cursor:grab}.calendar-event[draggable="true"]:active{cursor:grabbing}.calendar-event.is-dragging{opacity:.45}</style>
<script>
(()=>{let dragged=null;const csrf=<?=json_encode(csrf())?>;document.addEventListener('dragstart',event=>{const item=event.target.closest('.calendar-event[data-calendar-record-id]');if(!item)return;dragged=item;item.classList.add('is-dragging');event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',item.dataset.calendarRecordId);});document.addEventListener('dragend',()=>{dragged?.classList.remove('is-dragging');dragged=null;document.querySelectorAll('.calendar-day.drag-target').forEach(day=>day.classList.remove('drag-target'));});document.querySelectorAll('.calendar-day[data-date]').forEach(day=>{day.addEventListener('dragover',event=>{if(!dragged)return;event.preventDefault();event.dataTransfer.dropEffect='move';day.classList.add('drag-target');});day.addEventListener('dragleave',event=>{if(!day.contains(event.relatedTarget))day.classList.remove('drag-target');});day.addEventListener('drop',async event=>{event.preventDefault();day.classList.remove('drag-target');const item=dragged;if(!item||!item.dataset.calendarRecordId||!item.dataset.calendarRecordType)return;const targetDate=day.dataset.date;if(!targetDate)return;const sourceDay=item.closest('.calendar-day');if(sourceDay?.dataset.date===targetDate)return;const body=new URLSearchParams({csrf,record_id:item.dataset.calendarRecordId,record_type:item.dataset.calendarRecordType,appointment_date:targetDate});try{const response=await fetch(<?=json_encode(url('calendar-move.php'))?>,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Randevu taşınamadı.');day.querySelector('.day-events')?.append(item);}catch(error){alert(error.message||'Randevu taşınamadı.');}});});})();
</script>
<div class="calendar-context-menu" id="calendar-context-menu" hidden><button type="button" id="calendar-context-delete"><i class="ti tabler-trash" aria-hidden="true"></i> Kaydı Sil</button></div>
<style>.calendar-context-menu[hidden]{display:none!important}.calendar-context-menu{position:fixed;z-index:1200;min-width:155px;padding:5px;border:1px solid var(--line);border-radius:7px;background:var(--card);box-shadow:0 8px 24px rgba(32,33,45,.22)}.calendar-context-menu button{display:flex;align-items:center;gap:8px;width:100%;padding:9px 10px;border:0;border-radius:5px;background:transparent;color:#dd3f49;font:inherit;font-weight:700;text-align:left;cursor:pointer}.calendar-context-menu button:hover{background:#fff0f1}</style>
<script>
(()=>{const menu=document.getElementById('calendar-context-menu'),remove=document.getElementById('calendar-context-delete');if(!menu||!remove)return;let target=null;const close=()=>{menu.hidden=true;target=null;};document.addEventListener('contextmenu',event=>{const item=event.target.closest('.calendar-event[data-calendar-record-type]');if(!item||!['appointment','patient_service'].includes(item.dataset.calendarRecordType))return;if(item.dataset.calendarRecordType==='appointment'&&!['appointment','daily_event'].includes(item.dataset.calendarEventType))return;event.preventDefault();target=item;const label=item.dataset.calendarRecordType==='patient_service'?'Hasta Randevusunu Sil':(item.dataset.calendarEventType==='daily_event'?'Günlük Olayı Sil':'Randevuyu Sil');remove.lastChild.textContent=' '+label;menu.hidden=false;menu.style.left=Math.min(event.clientX,window.innerWidth-menu.offsetWidth-8)+'px';menu.style.top=Math.min(event.clientY,window.innerHeight-menu.offsetHeight-8)+'px';});document.addEventListener('click',event=>{if(!menu.contains(event.target))close();});document.addEventListener('keydown',event=>{if(event.key==='Escape')close();});remove.addEventListener('click',async()=>{const item=target;if(!item)return;close();const patientService=item.dataset.calendarRecordType==='patient_service';const daily=item.dataset.calendarEventType==='daily_event';const message=patientService?'Bu hasta randevusu silinsin mi? Hizmet kartı ve bağlı stok hareketleri geri alınacak.':(daily?'Bu günlük olay silinsin mi? Günlük Aksiyon Listesi kaydı da kaldırılacak.':'Bu randevu silinsin mi? Randevu Listesi kaydı da kaldırılacak.');if(!confirm(message))return;const body=new URLSearchParams({csrf:<?=json_encode(csrf())?>,record_id:item.dataset.calendarRecordId,record_type:item.dataset.calendarRecordType,event_type:item.dataset.calendarEventType});try{const response=await fetch(<?=json_encode(url('calendar-delete.php'))?>,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Kayıt silinemedi.');item.remove();}catch(error){alert(error.message||'Kayıt silinemedi.');}});})();
</script>
<?php patient_footer(); ?>
