<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_admin();
require __DIR__ . '/complaint-bootstrap.php';
require __DIR__ . '/patient-layout.php';

$pdo = db();
$message = ''; $error = '';
$editId = (int)($_GET['edit'] ?? $_POST['edit_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['edit_id'] ?? $_POST['id'] ?? 0);
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM complaint_definitions WHERE id=?')->execute([$id]);
        $message = 'Şikayet / arıza kalemi silindi.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;
        $sort = (int)($_POST['sort_order'] ?? 0);
        if ($name === '') $error = 'Şikayet / arıza kalemi zorunludur.';
        else {
            $duplicate = $pdo->prepare('SELECT id FROM complaint_definitions WHERE name=? AND id<>?');
            $duplicate->execute([$name, $id]);
            if ($duplicate->fetchColumn()) $error = 'Bu kalem zaten kayıtlı.';
            elseif ($id) { $pdo->prepare('UPDATE complaint_definitions SET name=?,active=?,sort_order=? WHERE id=?')->execute([$name,$active,$sort,$id]); $message='Şikayet / arıza kalemi güncellendi.'; }
            else { $pdo->prepare('INSERT INTO complaint_definitions(name,active,sort_order) VALUES(?,?,?)')->execute([$name,$active,$sort]); $message='Şikayet / arıza kalemi eklendi.'; }
        }
    }
}
$edit = ['id'=>0,'name'=>'','active'=>1,'sort_order'=>0];
if ($editId) { $statement=$pdo->prepare('SELECT * FROM complaint_definitions WHERE id=?'); $statement->execute([$editId]); $edit=$statement->fetch() ?: $edit; }
$rows = complaint_definitions();
patient_header('Ayarlar - Şikayet / Arıza', 'settings');
?>
<main class="patient-container personnel-page"><nav class="settings-tabs"></nav>
  <details class="vuexy-form-card definition-accordion"<?=($editId||$error)?' open':''?>><summary class="form-card-title"><span><h1><?=$editId?'Şikayet / Arıza Kalemini Düzenle':'Yeni Şikayet / Arıza Kalemi'?></h1><p>Tamir kabul formunda kullanılacak şikayet ve arıza kalemlerini yönetin.</p></span><i class="definition-chevron" aria-hidden="true"></i></summary>
    <?php if($message):?><p class="vox-message success"><?=e($message)?></p><?php endif?><?php if($error):?><p class="vox-message error"><?=e($error)?></p><?php endif?>
    <form method="post" class="personnel-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="edit_id" value="<?=(int)$edit['id']?>"><label>Şikayet / Arıza<input name="name" value="<?=e($edit['name'])?>" required></label><label>Sıra<input type="number" name="sort_order" value="<?=(int)$edit['sort_order']?>"></label><label class="personnel-check"><span>Durum</span><span><input type="checkbox" name="active" <?=$edit['active']?'checked':''?>> Aktif</span></label><div class="personnel-actions"><button><?=$editId?'Güncelle':'Kaydet'?></button><?php if($editId):?><a class="edit-personnel" href="<?=url('complaints.php')?>">İptal</a><?php endif?></div></form>
  </details>
  <section class="vuexy-form-card"><header class="form-card-title"><h2>Şikayet / Arıza Listesi</h2><p><?=count($rows)?> kayıt</p></header><div class="table-responsive"><table class="personnel-table"><thead><tr><th>Şikayet / Arıza</th><th>Sıra</th><th>Durum</th><th>İşlemler</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><?=e($row['name'])?></td><td><?=(int)$row['sort_order']?></td><td><span class="status-pill <?=$row['active']?'active':'passive'?>"><?=$row['active']?'Aktif':'Pasif'?></span></td><td><a class="edit-definition" href="<?=url('complaints.php?edit='.(int)$row['id'])?>">Düzenle</a><form method="post" class="inline" onsubmit="return confirm('Bu kalem silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="delete-definition">Sil</button></form></td></tr><?php endforeach?></tbody></table></div></section>
</main>
<style>.personnel-page{width:100%!important;max-width:1000px!important;min-height:100vh;margin:0 auto!important;padding:46px 20px 48px!important}.settings-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}.vuexy-form-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;margin-bottom:24px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.form-card-title{display:block!important;padding:22px 24px!important;border-bottom:1px solid #e1e2e8!important}.form-card-title h1,.form-card-title h2{margin:0 0 5px!important;color:#2f2b3d!important;font-size:21px!important;line-height:1.25!important}.form-card-title p{margin:0!important;color:#7b7b8d!important}.personnel-form{display:flex;gap:16px;align-items:end;padding:24px;flex-wrap:wrap}.personnel-form label{display:flex;flex-direction:column;gap:7px}.personnel-form input:not([type=checkbox]){height:43px;min-width:240px;border:1px solid #d2d2dc;border-radius:7px;padding:0 12px;background:transparent}.personnel-check{flex-direction:row!important;align-items:center;height:43px}.personnel-actions button,.edit-definition,.delete-definition{border:0;border-radius:7px;padding:11px 18px;text-decoration:none;font-weight:700;background:#19a94b;color:#fff}.delete-definition{background:#df4a4a;margin-left:8px}.inline{display:inline}.personnel-table{width:100%;border-collapse:collapse;min-width:680px}.personnel-table th,.personnel-table td{padding:14px 18px;border-bottom:1px solid #e1e2e8;text-align:left;white-space:nowrap}.table-responsive{overflow:auto}.vox-message{padding:13px 16px;margin:16px 24px;border-radius:7px}.vox-message.success{background:#daf5e3;color:#0d7130}.vox-message.error{background:#ffe3e3;color:#a21d1d}.definition-accordion>summary{position:relative;display:flex!important;align-items:center;padding-right:64px!important;cursor:pointer;list-style:none}.definition-accordion>summary::-webkit-details-marker{display:none}.definition-chevron{position:absolute;right:24px;top:50%;font-style:normal;font-size:20px;transform:translateY(-50%)}.definition-chevron::before{content:'>'}.definition-accordion[open] .definition-chevron{transform:translateY(-50%) rotate(90deg)}@media(max-width:720px){.personnel-page{max-width:none!important;padding:92px 14px 30px!important}.personnel-form label{width:100%}.personnel-form input:not([type=checkbox]){min-width:0;width:100%}}</style>
<?php patient_footer(); ?>
