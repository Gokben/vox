<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_admin();
require __DIR__ . '/anamnesis-bootstrap.php';
require __DIR__ . '/patient-layout.php';

$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $project = (string)($_POST['grapesjs_project'] ?? '');
    if (strlen($project) > 60000) $error = 'Tasarım kaydı çok büyük. Daha az görsel veya blok kullanın.';
    elseif ($project === '' || json_decode($project, true) === null) $error = 'Tasarım verisi geçerli değil.';
    else { save_anamnesis_print_settings(['grapesjs_project' => $project]); $message = 'Görsel yazdırma tasarımı kaydedildi.'; }
}
$settings = anamnesis_print_settings();
patient_header('Anamnez Görsel Tasarımcı', 'settings');
?>
<main class="designer-page">
  <header class="designer-head"><div><h1>Anamnez Görsel Tasarımcı</h1><p>A4 çıktıyı sürükle-bırak ile tasarlayın. Değişkenler hasta çıktısında gerçek bilgilerle doldurulur.</p></div><a class="designer-back" href="<?=url('anamnesis-questions.php')?>">Anamnez ayarlarına dön</a></header>
  <?php if($message):?><p class="vox-message success"><?=e($message)?></p><?php endif?><?php if($error):?><p class="vox-message error"><?=e($error)?></p><?php endif?>
  <form id="visual-designer-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input id="grapesjs-project" type="hidden" name="grapesjs_project"><div class="designer-workspace"><aside class="designer-tools"><h2>Eklenebilir Alanlar</h2><p>Bir alanı tutup A4 sayfaya bırakın.</p><div id="gjs-blocks"></div><hr><h2>Çıktı değişkenleri</h2><code>{{patient_name}}</code><code>{{date}}</code><code>{{question}}</code><code>{{answer}}</code><code>{{detail}}</code><code>{{text_field}}</code></aside><div id="gjs"></div></div><footer><button type="button" id="paged-preview">A4 Ön Görüntü</button><button type="submit">Tasarımı Kaydet</button></footer></form>
</main>
<link rel="stylesheet" href="<?=url('assets/vendor/grapesjs/grapes.min.css')?>">
<style>
.designer-page{max-width:1440px;margin:0 auto;padding:28px 20px}.designer-head{display:flex;justify-content:space-between;gap:18px;align-items:start;margin-bottom:18px}.designer-head h1{margin:0 0 6px;font-size:24px}.designer-head p{margin:0;color:#6d6875}.designer-back,#visual-designer-form footer button{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:7px;padding:11px 16px;background:#19a94b;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.designer-workspace{display:grid;grid-template-columns:245px minmax(0,1fr);border:1px solid #d9dde5;background:#fff;min-height:650px}.designer-tools{padding:18px 14px;border-right:1px solid #d9dde5;background:#fafbfc;overflow:auto}.designer-tools h2{margin:0 0 8px;font-size:15px}.designer-tools p{margin:0 0 16px;color:#6d6875;font-size:13px}.designer-tools hr{border:0;border-top:1px solid #d9dde5;margin:18px 0}.designer-tools code{display:block;margin:7px 0;padding:7px;border-radius:4px;background:#edf7f0;color:#14743a;font-size:12px}#visual-designer-form footer{display:flex;justify-content:flex-end;gap:10px;padding:14px 0}#paged-preview{background:#30435d!important}#gjs{height:calc(100vh - 220px);min-height:650px}.gjs-block{width:100%;margin:0 0 9px;border:1px solid #bfe0c8;border-radius:5px;background:#fff;color:#14743a;font-size:13px}.gjs-pn-panels,.gjs-pn-commands{display:none!important}.vox-message{padding:12px 16px;border-radius:7px}.vox-message.success{background:#daf5e3;color:#0d7130}.vox-message.error{background:#ffe3e3;color:#a21d1d}@media(max-width:700px){.designer-page{padding:18px 10px}.designer-head{flex-direction:column}.designer-workspace{grid-template-columns:1fr}.designer-tools{border-right:0;border-bottom:1px solid #d9dde5}#gjs{height:70vh;min-height:500px}}
</style>
<script src="<?=url('assets/vendor/grapesjs/grapes.min.js')?>"></script>
<script src="<?=url('assets/vendor/pagedjs/paged.polyfill.js')?>"></script>
<script>
(() => {
  const stored = <?=json_encode((string)$settings['grapesjs_project'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const baseCss = `@page { size:A4 portrait; margin:10mm; } body{font-family:Arial,sans-serif;color:#182438}.a4-sheet{width:190mm;min-height:277mm;box-sizing:border-box;margin:auto}.title{color:#14843c;font-size:22px;font-weight:bold}.field{border:1px solid #222;padding:7px}.question-table{width:100%;border-collapse:collapse}.question-table td{border:1px solid #222;padding:6px;font-size:11px}`;
  const initialContent = '<section class="a4-sheet"><div class="title">VOX İ.M. - HASTA KARTI</div><div class="field">{{patient_name}} <span style="float:right">{{date}}</span></div><div class="field">ŞİKAYETİNİZ NEDİR?</div><table class="question-table"><tr><td>GÜRÜLTÜLÜ ORTAMLARDA ÇALIŞTINIZ MI?</td><td style="width:12%;text-align:center">{{answer}}</td><td style="width:24%">NE KADAR SÜRE?</td><td></td></tr></table><div class="field" style="min-height:60px">ODY GÖRÜŞ VE TAVSİYESİ</div><div style="text-align:center;margin-top:15px"><img src="<?=url('assets/vox-logo-02.png')?>" style="max-width:110px"></div></section>';
  const editor = grapesjs.init({container:'#gjs',height:'100%',storageManager:false,fromElement:false,components:initialContent,blockManager:{appendTo:'#gjs-blocks',blocks:[
    {id:'a4',label:'A4 Sayfa',category:'Anamnez',content:'<section class="a4-sheet"><div class="title">VOX İ.M. - HASTA KARTI</div></section>'},
    {id:'patient',label:'Hasta / Tarih',category:'Anamnez',content:'<div class="field">{{patient_name}} <span style="float:right">{{date}}</span></div>'},
    {id:'question',label:'Soru Satırı',category:'Anamnez',content:'<table class="question-table"><tr><td>{{question}}</td><td style="width:12%;text-align:center">{{answer}}</td><td style="width:24%">{{detail}}</td><td></td></tr></table>'},
    {id:'text',label:'Metin Alanı',category:'Anamnez',content:'<div class="field">{{text_field}}</div>'},
    {id:'logo',label:'Şirket Logosu',category:'Anamnez',content:'<div style="text-align:center"><img src="<?=url('assets/vox-logo-02.png')?>" style="max-width:110px"></div>'}
  ]}});
  editor.setStyle(baseCss);
  if (stored) { try { editor.loadProjectData(JSON.parse(stored)); } catch (_) {} }
  const form = document.getElementById('visual-designer-form');
  form.addEventListener('submit', () => document.getElementById('grapesjs-project').value = JSON.stringify(editor.getProjectData()));
  document.getElementById('paged-preview').addEventListener('click', () => {
    const win = window.open('', '_blank', 'noopener'); if (!win) return;
    const html = editor.getHtml(), css = editor.getCss();
    win.document.write('<!doctype html><html><head><meta charset="utf-8"><style>'+baseCss+'\n'+css+'</style><script src="<?=url('assets/vendor/pagedjs/paged.polyfill.js')?>"><\\/script></head><body>'+html+'</body></html>'); win.document.close();
  });
})();
</script>
<?php patient_footer(); ?>
