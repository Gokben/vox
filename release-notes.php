<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

patient_header('Sürüm Notları', 'settings');
?>
<main class="patient-container release-notes-page">
  <section class="release-notes-card">
    <h1>Sürüm Notları</h1>
    <p class="release-version">Sürüm 1.12.08.26</p>
    <p>Yeni model ekleme ve model düzenleme seçilen markaya bağlı çalışır.</p>
    <p>Eski genel model listesi kaldırıldı.</p>
  </section>
</main>
<style>
.release-notes-page{max-width:900px;margin:0 auto;padding:96px 32px 48px}.release-notes-card{padding:28px 30px;border:1px solid var(--line,#e1e2e8);border-radius:10px;background:var(--card,#fff);box-shadow:0 3px 12px #1e283c0f}.release-notes-card h1{margin:0 0 6px;font-size:24px}.release-version{margin:0 0 24px!important;color:#16883d!important;font-size:15px!important;font-weight:700}.release-notes-card p{margin:0 0 14px;color:var(--text,#4b465c);line-height:1.55}@media(max-width:760px){.release-notes-page{padding:88px 14px 30px}.release-notes-card{padding:22px 20px}}
</style>
<?php patient_footer(); ?>
