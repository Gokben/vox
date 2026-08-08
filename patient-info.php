<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
if (current_role() !== ROLE_COMPANY_MANAGER) { http_response_code(403); exit('Bu sayfa yalnızca firma yöneticilerine açıktır.'); }
require __DIR__ . '/patient-layout.php';
$id = max(0, (int)($_GET['id'] ?? 0)); $pdo = db();
$statement = $pdo->prepare('SELECT * FROM patients WHERE id=?'); $statement->execute([$id]); $patient = $statement->fetch();
if (!$patient) { http_response_code(404); exit('Hasta bulunamadı.'); }
$services = $pdo->prepare('SELECT * FROM patient_services WHERE patient_id=? ORDER BY id DESC'); $services->execute([$id]); $services = $services->fetchAll();
$latest = $services[0] ?? [];
$anamnesis = trim((string)($latest['complaint'] ?? $patient['anamnesis'] ?? $patient['notes'] ?? ''));
patient_header('Hasta Bilgi Formu');
?>
<main class="patient-info-page"><section class="patient-info-card">
  <header><div><p class="eyebrow">HASTA BİLGİ FORMU</p><h1><?=e((string)$patient['full_name'])?></h1><p><?=e((string)$patient['national_id'])?> · Kayıt: <?=e(format_date_tr((string)$patient['record_date']))?></p></div><a href="<?=e(url('patients.php'))?>" title="Hasta kartlarına dön"><i class="ti tabler-arrow-left"></i></a></header>
  <section class="info-section"><h2>Hasta Özeti</h2><div class="horizontal-form">
    <div><i class="ti tabler-id"></i><label>T.C. Kimlik No</label><strong><?=e((string)$patient['national_id'])?:'—'?></strong></div><div><i class="ti tabler-calendar"></i><label>Doğum Tarihi</label><strong><?=e(format_date_tr((string)$patient['birth_date']))?:'—'?></strong></div>
    <div><i class="ti tabler-phone"></i><label>Telefon</label><strong><?=e((string)$patient['phone_primary'])?:'—'?></strong></div><div><i class="ti tabler-map-pin"></i><label>Adres</label><strong><?=e((string)$patient['address'])?:'—'?></strong></div>
  </div></section>
  <section class="info-section"><h2>Satış Bilgisi</h2><div class="horizontal-form"><div><i class="ti tabler-shopping-cart"></i><label>Son Hizmet / Satış</label><strong><?=e((string)($latest['service_name'] ?? ''))?:'—'?></strong></div><div><i class="ti tabler-cash"></i><label>Tutar</label><strong><?=isset($latest['amount']) && $latest['amount'] !== '' ? e(number_format((float)$latest['amount'],2,',','.')).' TL' : '—'?></strong></div><div><i class="ti tabler-circle-check"></i><label>Sonuç</label><strong><?=e((string)($latest['result_name'] ?? ''))?:'—'?></strong></div><div><i class="ti tabler-user"></i><label>İlgilenen</label><strong><?=e((string)($latest['contact_person'] ?? ''))?:'—'?></strong></div></div></section>
  <section class="info-section"><h2>Cihaz Teslim Bilgisi</h2><div class="horizontal-form"><div><i class="ti tabler-device-hearing-aid"></i><label>Cihaz Bilgisi</label><strong><?=e((string)($latest['service_name'] ?? ''))?:'—'?></strong></div><div><i class="ti tabler-calendar-event"></i><label>İşlem Tarihi</label><strong><?=e(format_date_tr((string)($latest['service_date'] ?? '')))?:'—'?></strong></div></div></section>
  <section class="info-section"><h2>Hasta Anamnezi</h2><article class="anamnesis-summary"><i class="ti tabler-notes"></i><p><?=nl2br(e($anamnesis ?: 'Anamnez bilgisi bulunmuyor.'))?></p></article></section>
</section></main>
<style>.patient-info-page{max-width:1080px;margin:0 auto;padding:52px 20px 30px}.patient-info-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;box-shadow:0 3px 12px #1e283c12;overflow:hidden}.patient-info-card>header{height:auto!important;padding:24px 28px!important;display:flex!important;align-items:start!important;justify-content:space-between!important;background:#f8fbf8!important;border-bottom:4px solid #8fd14f!important}.eyebrow{margin:0 0 7px!important;color:#4c8c1a!important;font-weight:800!important}.patient-info-card h1{margin:0;font-size:25px}.patient-info-card header p{margin:7px 0 0;color:#686574}.patient-info-card header a{display:grid;place-items:center;width:38px;height:38px;border-radius:6px;background:#6f42c1;color:#fff}.info-section{padding:23px 28px;border-bottom:1px solid #e7e6eb}.info-section h2{margin:0 0 16px;color:#376d18;font-size:15px}.horizontal-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 24px}.horizontal-form>div{display:grid;grid-template-columns:32px 130px minmax(0,1fr);align-items:center;min-height:42px;border-bottom:1px solid #ecebf0}.horizontal-form i{color:#6f42c1;font-size:19px}.horizontal-form label{color:#777487;font-size:13px}.horizontal-form strong{font-weight:500;overflow-wrap:anywhere}.anamnesis-summary{display:flex;gap:12px;margin:0;padding:16px;border-radius:7px;background:#f8f8fa}.anamnesis-summary i{font-size:21px;color:#6f42c1}.anamnesis-summary p{margin:0;line-height:1.55}@media(max-width:700px){.horizontal-form{grid-template-columns:1fr}.patient-info-page{padding:40px 10px 18px}.info-section{padding:20px}.horizontal-form>div{grid-template-columns:30px 110px minmax(0,1fr)}}</style>
<?php patient_footer();
