<?php
require __DIR__.'/config.php';
require_login();
require __DIR__.'/patient-identity.php';
require __DIR__.'/patient-layout.php';
$issues=patient_identity_audit(patient_identity_rows(db()));
$invalid=$duplicate=0;
foreach($issues as $row){
    $number=trim((string)$row['national_id']);
    if(patient_identity_error((string)$row['national_id'])!=='')$invalid++;
    if(str_contains(implode(' ', $row['reasons']),'Mükerrer'))$duplicate++;
}
patient_header('T.C. Kimlik Kontrol Listesi','patients');
?>
<main class="patient-container"><section class="identity-audit">
<h1>T.C. Kimlik Kontrol Listesi</h1>
<p>Tam 11 rakam olmayan: <strong><?=$invalid?></strong> · Mükerrer numaraya sahip kayıt: <strong><?=$duplicate?></strong></p>
<p>Toplam <?=count($issues)?> kayıt listeleniyor. T.C. numarası boş olan veya pasaport numarası girilmiş kartlar bu listeye alınmaz. Bir kayıt birden fazla nedenle listelenebilir.</p>
<p><a href="<?=e(url('patients.php'))?>">Hasta kartlarına dön</a></p>
<table><thead><tr><th>Kayıt</th><th>Hasta</th><th>T.C. Kimlik No</th><th>Hane / karakter</th><th>Kontrol sonucu</th><th></th></tr></thead><tbody>
<?php foreach($issues as $row): $external=$row['source']==='external_technical_patients'; ?>
<tr><td><?=$external?'Dış hasta':'Hasta'?> #<?=(int)$row['id']?></td><td><?=e($row['full_name'])?></td><td><?=e((string)$row['national_id'])?:'Boş'?></td><td><?=strlen((string)$row['national_id'])?></td><td><?=e(implode(' · ',$row['reasons']))?></td><td><a href="<?=e(url(($external?'external-technical-patient.php':'patient-form.php').'?id='.(int)$row['id']))?>">Düzenle</a></td></tr>
<?php endforeach; ?>
<?php if(!$issues):?><tr><td colspan="6">Kontrol gerektiren kayıt yok.</td></tr><?php endif; ?>
</tbody></table></section></main>
<style>.identity-audit{padding:24px;background:var(--card,#fff);overflow:auto}.identity-audit table{border-collapse:collapse;width:100%}.identity-audit th,.identity-audit td{padding:10px;border-bottom:1px solid #cbd5e1;text-align:left}.identity-audit th{background:#edf4fa}.identity-audit td:nth-child(3){font-variant-numeric:tabular-nums;white-space:pre-wrap}</style>
<script src="<?=e(url('assets/identity-audit-editor.js?v=1'))?>" defer></script>
<?php patient_footer();
