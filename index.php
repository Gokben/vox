<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

patient_header('VOX Masaüstü', 'desktop');
?>
<script>document.body.classList.add('vox-desktop-only');</script>
<style>
@media (min-width:901px){
  body#vox-app.vox-desktop-only>.patient-header .patient-topbar,
  body#vox-app.vox-desktop-only>.desktop-taskbar .desktop-task-title{display:none!important}
}
</style>
<?php patient_footer(); ?>
