<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/appointment-bootstrap.php';
require __DIR__ . '/patient-layout.php';

$pdo = db();
ensure_appointment_schema($pdo);
$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$firstDay = new DateTimeImmutable($month . '-01');
$nextMonth = $firstDay->modify('+1 month');
$previousMonth = $firstDay->modify('-1 month');
$monthNames = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
$monthTitle = $monthNames[(int)$firstDay->format('n')] . ' ' . $firstDay->format('Y');
$statement = $pdo->prepare("SELECT a.*, b.name AS branch_name FROM appointments a LEFT JOIN branches b ON b.id = a.branch_id WHERE a.appointment_date >= ? AND a.appointment_date < ? AND COALESCE(a.event_type, 'appointment') = 'appointment' ORDER BY a.appointment_date, a.appointment_time, a.id");
$statement->execute([$firstDay->format('Y-m-d'), $nextMonth->format('Y-m-d')]);
$appointments = $statement->fetchAll();
$today = date('Y-m-d');
$dailyStatement = $pdo->prepare("SELECT a.*, b.name AS branch_name FROM appointments a LEFT JOIN branches b ON b.id = a.branch_id WHERE a.appointment_date = ? AND a.event_type = 'daily_event' ORDER BY a.appointment_time, a.id");
$dailyStatement->execute([$today]);
$dailyAppointments = $dailyStatement->fetchAll();

patient_header('Randevu Listesi', 'calendar');
?>
<main class="patient-container appointment-list-page">
  <section class="appointment-list-card">
    <header>
      <div><h1>Randevu Listesi</h1><p><?=e($monthTitle)?> için <?=count($appointments)?> randevu</p></div>
      <div class="appointment-actions"><a href="<?=e(url('appointment-list.php?month='.$previousMonth->format('Y-m')))?>">‹</a><a href="<?=e(url('calendar.php?month='.$month))?>">Takvim</a><a href="<?=e(url('appointment-list.php?month='.$nextMonth->format('Y-m')))?>">›</a><a class="appointment-add" href="<?=e(url('appointment-form.php?date='.date('Y-m-d')))?>">＋ Randevu Ekle</a></div>
    </header>
    <?php if (($_GET['appointment_saved'] ?? '') === '1'): ?><div class="appointment-saved">Randevu kaydedildi.</div><?php endif ?>
    <div class="appointment-list-wrap"><table><thead><tr><th>Tarih</th><th>Saat</th><th>Ad Soyad</th><th>Telefon</th><th>Şube</th><th>İlgilenen Kişi</th><th>Not</th></tr></thead><tbody>
      <?php foreach ($appointments as $appointment): ?><tr data-appointment-id="<?=(int)$appointment['id']?>"><td><?=e(format_date_tr($appointment['appointment_date']))?></td><td><?=e(substr((string)$appointment['appointment_time'], 0, 5))?></td><td><?=e($appointment['full_name'])?></td><td><?=e(implode(' / ', array_filter([(string)($appointment['phone'] ?? ''),(string)($appointment['phone_secondary'] ?? '')])) ?: '—')?></td><td><?=e($appointment['branch_name'] ?: '—')?></td><td><?=e($appointment['contact_person'] ?: '—')?></td><td><?=e($appointment['note'] ?: '—')?></td></tr><?php endforeach ?>
      <?php if (!$appointments): ?><tr><td colspan="8" class="appointment-empty">Bu ay için randevu bulunmuyor.</td></tr><?php endif ?>
    </tbody></table></div>
  </section>
  <section id="gunluk-olaylar" class="appointment-list-card daily-events-card">
    <header><div><h2>Günlük Olaylar</h2><p><?=e(format_date_tr($today))?> için <?=count($dailyAppointments)?> randevu</p></div></header>
    <div class="appointment-list-wrap"><table><thead><tr><th>Tarih</th><th>Saat</th><th>Ad Soyad</th><th>Telefon</th><th>Şube</th><th>İlgilenen Kişi</th><th>Not</th></tr></thead><tbody>
      <?php foreach ($dailyAppointments as $appointment): ?><tr data-appointment-id="<?=(int)$appointment['id']?>"><td><?=e(format_date_tr($appointment['appointment_date']))?></td><td><?=e(substr((string)$appointment['appointment_time'], 0, 5))?></td><td><?=e($appointment['full_name'])?></td><td><?=e($appointment['phone'] ?: '—')?></td><td><?=e($appointment['branch_name'] ?: '—')?></td><td><?=e($appointment['contact_person'] ?: '—')?></td><td><?=e($appointment['note'] ?: '—')?></td></tr><?php endforeach ?>
      <?php if (!$dailyAppointments): ?><tr><td colspan="8" class="appointment-empty">Bugün için randevu bulunmuyor.</td></tr><?php endif ?>
    </tbody></table></div>
  </section>
</main>
<style>
.appointment-list-page{max-width:1240px!important;margin:0 auto!important;padding:96px 32px 48px!important}.appointment-list-card{padding:22px 24px;border:1px solid var(--line);border-radius:10px;background:var(--card);box-shadow:0 3px 12px #1e283c0f}.daily-events-card{display:none}.appointment-list-card header{display:flex;justify-content:space-between;gap:20px;align-items:center;margin-bottom:20px}.appointment-list-card h1,.appointment-list-card h2{margin:0;font-size:21px}.appointment-list-card p{margin:5px 0 0;color:var(--muted);font-size:13px}.appointment-actions{display:flex;gap:8px;align-items:center}.appointment-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 11px;border:1px solid var(--line);border-radius:6px;color:var(--text);text-decoration:none;font-size:14px}.appointment-actions .appointment-add{border-color:#20a447;background:#20a447;color:#fff;font-weight:700}.appointment-list-wrap{overflow:auto}.appointment-list-card table{width:100%;min-width:820px;border-collapse:collapse}.appointment-list-card th,.appointment-list-card td{padding:12px 10px;border-bottom:1px solid var(--line);text-align:left;font-size:13px}.appointment-list-card th{font-size:12px;color:var(--muted)}.appointment-empty{text-align:center;color:var(--muted)}.appointment-saved{margin:-4px 0 14px;padding:10px 12px;border-radius:6px;background:#e7f8ed;color:#126a30;font-weight:700;font-size:13px}@media(max-width:700px){.appointment-list-page{padding:92px 14px 30px!important}.appointment-list-card{padding:18px}.appointment-list-card header{align-items:flex-start;flex-direction:column}.appointment-actions{flex-wrap:wrap}}
.appointment-list-card th:nth-child(4),.appointment-list-card td:nth-child(4){min-width:112px;white-space:nowrap}
</style>
<script>
document.querySelectorAll('.appointment-list-card table').forEach(table => {
  const headerRow = table.querySelector('thead tr');
  if (headerRow) headerRow.insertAdjacentHTML('beforeend', '<th>İşlem</th>');
  table.querySelectorAll('tbody tr[data-appointment-id]').forEach(row => {
    const id = row.dataset.appointmentId;
    row.insertAdjacentHTML('beforeend', '<td><a class="appointment-edit" href="appointment-form.php?id=' + encodeURIComponent(id) + '">Düzenle</a></td>');
  });
});
</script>
<style>.appointment-edit{display:inline-flex;align-items:center;min-height:32px;padding:0 10px;border-radius:6px;background:#eef9f1;color:#168c3d!important;font-weight:700;text-decoration:none}</style>
<?php patient_footer(); ?>
