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
$statement = $pdo->prepare("SELECT a.*, b.name AS branch_name FROM appointments a LEFT JOIN branches b ON b.id = a.branch_id WHERE a.appointment_date >= ? AND a.appointment_date < ? AND a.event_type = 'daily_event' ORDER BY a.appointment_date, a.appointment_time, a.id");
$statement->execute([$firstDay->format('Y-m-d'), $nextMonth->format('Y-m-d')]);
$events = $statement->fetchAll();

patient_header('Günlük Aksiyon', 'calendar');
?>
<main class="patient-container daily-events-page"><section class="daily-events-card"><header><div><h1>Günlük Olaylar Listesi</h1><p><?=e($monthTitle)?> için <?=count($events)?> günlük olay</p></div><div class="daily-actions"><a href="<?=e(url('daily-events-list.php?month='.$previousMonth->format('Y-m')))?>">‹</a><a href="<?=e(url('calendar.php?month='.$month))?>">Takvim</a><a href="<?=e(url('daily-events-list.php?month='.$nextMonth->format('Y-m')))?>">›</a><a class="daily-add" href="<?=e(url('appointment-form.php?type=daily_event&date='.date('Y-m-d')))?>">＋ Günlük Olay Ekle</a></div></header><div class="daily-table-wrap"><table><thead><tr><th>Tarih</th><th>Saat</th><th>Ad Soyad</th><th>Telefon</th><th>Şube</th><th>İlgilenen Kişi</th><th>Not</th><th>İşlem</th></tr></thead><tbody><?php foreach($events as $event): ?><tr><td><?=e(format_date_tr($event['appointment_date']))?></td><td><?=e(substr((string)$event['appointment_time'],0,5))?></td><td><?=e($event['full_name'])?></td><td><?=e($event['phone'] ?: '—')?></td><td><?=e($event['branch_name'] ?: '—')?></td><td><?=e($event['contact_person'] ?: '—')?></td><td><?=e($event['note'] ?: '—')?></td><td><a class="daily-edit" href="<?=e(url('appointment-form.php?id='.(int)$event['id']))?>">Düzenle</a></td></tr><?php endforeach; if(!$events): ?><tr><td colspan="8" class="daily-empty">Bu ay için günlük olay bulunmuyor.</td></tr><?php endif ?></tbody></table></div></section></main>
<style>.daily-events-page{max-width:1240px!important;margin:0 auto!important;padding:96px 32px 48px!important}.daily-events-card{padding:22px 24px;border:1px solid var(--line);border-radius:10px;background:var(--card);box-shadow:0 3px 12px #1e283c0f}.daily-events-card header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:20px}.daily-events-card h1{margin:0;font-size:21px}.daily-events-card p{margin:5px 0 0;color:var(--muted);font-size:13px}.daily-actions{display:flex;gap:8px;align-items:center}.daily-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 11px;border:1px solid var(--line);border-radius:6px;color:var(--text);text-decoration:none;font-size:14px}.daily-actions .daily-add,.daily-edit{border-color:#596273;background:#596273;color:#fff;font-weight:700}.daily-table-wrap{overflow:auto}.daily-events-card table{width:100%;min-width:820px;border-collapse:collapse}.daily-events-card th,.daily-events-card td{padding:12px 10px;border-bottom:1px solid var(--line);text-align:left;font-size:13px}.daily-events-card th{font-size:12px;color:var(--muted)}.daily-empty{text-align:center;color:var(--muted)}.daily-edit{display:inline-flex;align-items:center;min-height:32px;padding:0 10px;border-radius:6px;text-decoration:none}@media(max-width:700px){.daily-events-page{padding:92px 14px 30px!important}.daily-events-card{padding:18px}.daily-events-card header{align-items:flex-start;flex-direction:column}.daily-actions{flex-wrap:wrap}}</style>
<script>document.querySelector('.daily-events-card h1').textContent = 'Günlük Aksiyon';</script>
<?php patient_footer(); ?>
