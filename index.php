<?php
require __DIR__ . '/config.php'; require_login(); require __DIR__ . '/patient-layout.php';
$total=(int)db()->query('SELECT COUNT(*) FROM patients')->fetchColumn();
$approved=(int)db()->query('SELECT COUNT(*) FROM patients WHERE approval=1')->fetchColumn();
$considering=(int)db()->query('SELECT COUNT(*) FROM patients WHERE considering=1')->fetchColumn();
$rejected=(int)db()->query('SELECT COUNT(*) FROM patients WHERE rejected=1')->fetchColumn();
$recent=db()->query('SELECT * FROM patients ORDER BY import_order,id LIMIT 10')->fetchAll();
patient_header('Ana Sayfa','home');
?>
<main class="patient-container dashboard">
  <section class="page-head"><div><h1>Hasta Kayıtları</h1><p>Hasta bilgilerini kaydedin, takip edin ve süreçleri yönetin.</p></div><div class="page-actions"><a class="icon-button" href="<?=url('patients.php')?>" title="Tüm kayıtları aç">⤢</a><a class="button" href="<?=url('patient-form.php')?>">+ Yeni Hasta Kaydı</a></div></section>
  <section class="stats"><article class="card stat purple"><span>Toplam Kayıt</span><strong><?=$total?></strong></article><article class="card stat cyan"><span>Onaylanan</span><strong><?=$approved?></strong></article><article class="card stat orange"><span>Düşünecek</span><strong><?=$considering?></strong></article><article class="card stat green"><span>Reddedilen</span><strong><?=$rejected?></strong></article></section>
  <section class="card list-card"><div class="quick-filter"><input placeholder="Ad soyad, T.C. kimlik no veya telefon ile arayın"><a class="button" href="<?=url('patients.php')?>">Ara</a></div><div class="table-wrap"><table class="patient-table"><thead><tr><th>No</th><th>Tarih</th><th>Ad Soyad</th><th>T.C. Kimlik No</th><th>Telefon 1</th><th>Telefon 2</th><th>Doğum Tarihi</th><th>Adres</th><th>Sosyal Güvence</th><th>Rapor</th><th>Şube / Hizmet</th><th>Sonuç</th><th>Eylemler</th></tr></thead><tbody>
  <?php foreach($recent as $r):?><tr><td><?=(int)$r['import_order']?></td><td><?=e($r['record_date'])?></td><td><b><?=e($r['full_name'])?></b></td><td><?=e($r['national_id'])?></td><td><?=e($r['phone_primary'])?></td><td><?=e($r['phone_secondary'])?></td><td><?=e($r['birth_date'])?></td><td class="address"><?=e($r['address'])?></td><td><?=e($r['social_security'])?></td><td><?=e($r['report_info'])?></td><td><?=e($r['service_location'])?><br><small><?=e($r['service_type'])?></small></td><td><?=$r['approval']?'Onay':($r['considering']?'Düşünecek':($r['rejected']?'Red':'—'))?></td><td><div class="actions"><a class="edit" href="<?=url('patient-form.php?id='.(int)$r['id'])?>">✎</a><form method="post" action="<?=url('patient-delete.php')?>" onsubmit="return confirm('Bu hasta kaydı silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="delete">×</button></form></div></td></tr><?php endforeach?>
  </tbody></table></div><div class="list-footer"><a href="<?=url('patients.php')?>">Tüm hasta kayıtlarını görüntüle →</a></div></section>
</main>
<?php patient_footer();
