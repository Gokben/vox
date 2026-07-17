<?php
require __DIR__ . '/config.php'; require_login(); require __DIR__ . '/patient-layout.php';
$total=(int)db()->query('SELECT COUNT(*) FROM patients')->fetchColumn();
$approved=(int)db()->query('SELECT COUNT(*) FROM patients WHERE approval=1')->fetchColumn();
$considering=(int)db()->query('SELECT COUNT(*) FROM patients WHERE considering=1')->fetchColumn();
$rejected=(int)db()->query('SELECT COUNT(*) FROM patients WHERE rejected=1')->fetchColumn();
$pdo = db();
$yearExpression = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'substr(record_date,1,4)' : 'YEAR(record_date)';
$yearlyRows = $pdo->query("SELECT {$yearExpression} AS year_key, COUNT(*) AS total FROM patients WHERE record_date IS NOT NULL AND record_date <> '' GROUP BY {$yearExpression} ORDER BY year_key")->fetchAll();
$yearlyChart = array_map(static fn(array $row): array => ['label' => (string)$row['year_key'], 'total' => (int)$row['total']], $yearlyRows);
if (!$yearlyChart) $yearlyChart = [['label' => date('Y'), 'total' => 0]];
$yearlyMax = max(1, ...array_column($yearlyChart, 'total'));
$availableYears = array_map(static fn(array $year): int => (int)$year['label'], $yearlyChart);
$selectedChartYear = (int)($_GET['chart_year'] ?? end($availableYears));
if (!in_array($selectedChartYear, $availableYears, true)) $selectedChartYear = (int)end($availableYears);
$monthExpression = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'substr(record_date,6,2)' : "DATE_FORMAT(record_date,'%m')";
$monthlyStatement = $pdo->prepare("SELECT {$monthExpression} AS month_key, COUNT(*) AS total FROM patients WHERE record_date LIKE ? GROUP BY {$monthExpression}");
$monthlyStatement->execute([$selectedChartYear . '-%']);
$selectedYearTotals = [];
foreach ($monthlyStatement->fetchAll() as $monthRow) $selectedYearTotals[(int)$monthRow['month_key']] = (int)$monthRow['total'];
$monthLabels = ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];
$selectedYearMonths = [];
for ($month = 1; $month <= 12; $month++) $selectedYearMonths[] = ['label' => $monthLabels[$month - 1], 'total' => $selectedYearTotals[$month] ?? 0];
$selectedYearMax = max(1, ...array_column($selectedYearMonths, 'total'));
$comparisonRows = $pdo->query("SELECT {$yearExpression} AS year_key, {$monthExpression} AS month_key, COUNT(*) AS total FROM patients WHERE record_date IS NOT NULL AND record_date <> '' GROUP BY {$yearExpression}, {$monthExpression}")->fetchAll();
$monthYearTotals = [];
foreach ($comparisonRows as $comparisonRow) $monthYearTotals[(int)$comparisonRow['month_key']][(int)$comparisonRow['year_key']] = (int)$comparisonRow['total'];
$comparisonMax = 1;
foreach ($monthYearTotals as $monthTotals) foreach ($monthTotals as $monthTotal) $comparisonMax = max($comparisonMax, $monthTotal);
$recent=db()->query('SELECT * FROM patients ORDER BY import_order,id LIMIT 10')->fetchAll();
patient_header('Ana Sayfa','home');
?>
<style>.dashboard .list-card{display:none}</style>
<main class="patient-container dashboard">
  <section class="page-head"><div><h1>Hasta Kayıtları</h1><p>Hasta bilgilerini kaydedin, takip edin ve süreçleri yönetin.</p></div><div class="page-actions"><a class="icon-button" href="<?=url('patients.php')?>" title="Tüm kayıtları aç">⤢</a><a class="button" href="<?=url('patient-form.php')?>">+ Yeni Hasta Kaydı</a></div></section>
  <section class="stats"><article class="card stat purple"><span>Toplam Kayıt</span><strong><?=$total?></strong></article><article class="card stat cyan"><span>Onaylanan</span><strong><?=$approved?></strong></article><article class="card stat orange"><span>Düşünecek</span><strong><?=$considering?></strong></article><article class="card stat green"><span>Reddedilen</span><strong><?=$rejected?></strong></article></section>
  <section class="card monthly-chart-card"><header><div><h2>Yıllık Hasta Dağılımı</h2><p>Hasta kayıt tarihine göre yıllara göre toplam kayıtlar</p></div><span class="chart-total"><?=$total?> toplam kayıt</span></header><div class="monthly-chart yearly-chart" role="img" aria-label="Yıllık hasta dağılım grafiği"><?php foreach($yearlyChart as $year):?><div class="month-bar"><span class="month-value"><?=(int)$year['total']?></span><div class="month-track"><span style="height:<?=max(2, ($year['total'] / $yearlyMax) * 100)?>%"></span></div><span class="month-label"><?=e($year['label'])?></span></div><?php endforeach?></div></section>
  <section class="card monthly-chart-card"><header><div><h2>Aylık Hasta Dağılımı</h2><p>Seçilen yılın aylara göre hasta kayıtları</p></div><form class="chart-year-form" method="get"><label for="chart_year">Yıl</label><select id="chart_year" name="chart_year" onchange="this.form.submit()"><?php foreach($availableYears as $year):?><option value="<?=$year?>" <?=$year===$selectedChartYear?'selected':''?>><?=$year?></option><?php endforeach?></select></form></header><div class="monthly-chart" role="img" aria-label="<?=$selectedChartYear?> yılı aylık hasta dağılım grafiği"><?php foreach($selectedYearMonths as $month):?><div class="month-bar"><span class="month-value"><?=(int)$month['total']?></span><div class="month-track"><span style="height:<?=max(2, ($month['total'] / $selectedYearMax) * 100)?>%"></span></div><span class="month-label"><?=e($month['label'])?></span></div><?php endforeach?></div></section>
  <section class="card monthly-chart-card"><header><div><h2>Yıllara Göre Aylık Karşılaştırma</h2><p>Aynı ayların yıllara göre hasta kayıt sayıları</p></div><div class="chart-legend"><?php foreach($availableYears as $yearIndex=>$year):?><span class="legend-year legend-<?=$yearIndex?>"><i></i><?=$year?></span><?php endforeach?></div></header><div class="year-month-chart" role="img" aria-label="Yıllara göre aylık hasta karşılaştırma grafiği"><?php for($month=1;$month<=12;$month++):?><div class="compare-month"><div class="compare-bars"><?php foreach($availableYears as $yearIndex=>$year):$monthTotal=$monthYearTotals[$month][$year]??0;?><span class="compare-bar compare-<?=$yearIndex?>" title="<?=$year?> <?=e($monthLabels[$month-1])?>: <?=$monthTotal?> hasta" style="height:<?=max(2, ($monthTotal / $comparisonMax) * 100)?>%"></span><?php endforeach?></div><span><?=e($monthLabels[$month-1])?></span></div><?php endfor?></div></section>
  <section class="card list-card"><div class="quick-filter"><input placeholder="Ad soyad, T.C. kimlik no veya telefon ile arayın"><a class="button" href="<?=url('patients.php')?>">Ara</a></div><div class="table-wrap"><table class="patient-table"><thead><tr><th>No</th><th>Tarih</th><th>Ad Soyad</th><th>T.C. Kimlik No</th><th>Telefon 1</th><th>Telefon 2</th><th>Doğum Tarihi</th><th>Adres</th><th>Sosyal Güvence</th><th>Rapor</th><th>Şube / Hizmet</th><th>Sonuç</th><th>Eylemler</th></tr></thead><tbody>
  <?php foreach($recent as $r):?><tr><td><?=(int)$r['import_order']?></td><td><?=e($r['record_date'])?></td><td><b><?=e($r['full_name'])?></b></td><td><?=e($r['national_id'])?></td><td><?=e($r['phone_primary'])?></td><td><?=e($r['phone_secondary'])?></td><td><?=e($r['birth_date'])?></td><td class="address"><?=e($r['address'])?></td><td><?=e($r['social_security'])?></td><td><?=e($r['report_info'])?></td><td><?=e($r['service_location'])?><br><small><?=e($r['service_type'])?></small></td><td><?=$r['approval']?'Onay':($r['considering']?'Düşünecek':($r['rejected']?'Red':'—'))?></td><td><div class="actions"><a class="edit" href="<?=url('patient-form.php?id='.(int)$r['id'])?>">✎</a><form method="post" action="<?=url('patient-delete.php')?>" onsubmit="return confirm('Bu hasta kaydı silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="delete">×</button></form></div></td></tr><?php endforeach?>
  </tbody></table></div><div class="list-footer"><a href="<?=url('patients.php')?>">Tüm hasta kayıtlarını görüntüle →</a></div></section>
</main>
<?php patient_footer();
