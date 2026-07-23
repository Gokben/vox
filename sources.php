<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_admin();
require __DIR__ . '/source-bootstrap.php';
source_definitions();
require __DIR__ . '/patient-layout.php';

$message = '';
$error = '';
$editId = (int)($_GET['edit'] ?? $_POST['edit_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['edit_id'] ?? $_POST['id'] ?? 0);

    if ($action === 'delete') {
        $used = db()->prepare('SELECT COUNT(*) FROM patients WHERE source_id=?');
        $used->execute([$id]);
        $usage = (int)$used->fetchColumn();
        if ($usage > 0) {
            $error = 'Bu kaynak ' . $usage . ' hasta kaydında kullanılıyor. Silmek yerine pasif hale getirebilirsiniz.';
        } else {
            db()->prepare('DELETE FROM source_definitions WHERE id=?')->execute([$id]);
            $message = 'Başvuru kaynağı silindi.';
        }
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;
        $sort = (int)($_POST['sort_order'] ?? 0);
        if ($name === '') {
            $error = 'Kaynak adı zorunludur.';
        } else {
            $duplicate = db()->prepare('SELECT id FROM source_definitions WHERE name=? AND id<>? LIMIT 1');
            $duplicate->execute([$name, $id]);
            if ($duplicate->fetchColumn()) {
                $error = 'Bu başvuru kaynağı zaten kayıtlı.';
            } elseif ($id > 0) {
                $exists = db()->prepare('SELECT id FROM source_definitions WHERE id=?');
                $exists->execute([$id]);
                if (!$exists->fetchColumn()) {
                    $error = 'Düzenlenecek kaynak bulunamadı.';
                } else {
                    db()->prepare('UPDATE source_definitions SET name=?,active=?,sort_order=? WHERE id=?')->execute([$name, $active, $sort, $id]);
                    $editId = $id;
                    $message = 'Başvuru kaynağı güncellendi.';
                }
            } else {
                db()->prepare('INSERT INTO source_definitions(name,active,sort_order) VALUES(?,?,?)')->execute([$name, $active, $sort]);
                $message = 'Başvuru kaynağı eklendi.';
            }
        }
    }
}

$edit = ['id' => 0, 'name' => '', 'active' => 1, 'sort_order' => 0];
if ($editId) {
    $statement = db()->prepare('SELECT * FROM source_definitions WHERE id=?');
    $statement->execute([$editId]);
    $edit = $statement->fetch() ?: $edit;
}
$rows = source_definitions();
$usageById = [];
foreach (db()->query('SELECT source_id, COUNT(*) AS total FROM patients WHERE source_id IS NOT NULL GROUP BY source_id')->fetchAll() as $usageRow) {
    $usageById[(int)$usageRow['source_id']] = (int)$usageRow['total'];
}

patient_header('Ayarlar - Başvuru Kaynağı', 'settings');
?>
<main class="definition-settings">
  <nav class="settings-tabs"></nav>
  <section class="vuexy-form-card">
    <header class="form-card-title"><h1><?= (int)$edit['id'] ? 'Başvuru Kaynağını Düzenle' : 'Yeni Başvuru Kaynağı' ?></h1><p>Hasta kartlarında seçimli olarak kullanılacak kaynakları yönetin.</p></header>
    <?php if ($message): ?><p class="vox-message success"><?= e($message) ?></p><?php endif ?>
    <?php if ($error): ?><p class="vox-message error"><?= e($error) ?></p><?php endif ?>
    <form method="post" action="<?= url('sources.php' . ((int)$edit['id'] ? '?edit=' . (int)$edit['id'] : '')) ?>" class="definition-form">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="edit_id" value="<?= (int)$edit['id'] ?>">
      <label>Kaynak<input name="name" value="<?= e($edit['name']) ?>" required></label>
      <label>Sıra<input type="number" name="sort_order" value="<?= (int)$edit['sort_order'] ?>"></label>
      <label class="check"><input type="checkbox" name="active" <?= $edit['active'] ? 'checked' : '' ?>> Aktif</label>
      <button><?= (int)$edit['id'] ? 'Güncelle' : 'Kaydet' ?></button><?php if ((int)$edit['id']): ?> <a class="cancel-edit" href="<?=url('sources.php')?>">İptal</a><?php endif; ?>
    </form>
  </section>
  <section class="vuexy-form-card">
    <header class="form-card-title"><h2>Başvuru Kaynağı Listesi</h2><p><?= count($rows) ?> kayıt</p></header>
    <div class="table-responsive"><table class="definition-table"><thead><tr><th>Kaynak</th><th>Sıra</th><th>Durum</th><th>İşlemler</th></tr></thead><tbody>
      <?php foreach ($rows as $row): $usageCount = $usageById[(int)$row['id']] ?? 0; ?><tr><td><?= e($row['name']) ?></td><td><?= (int)$row['sort_order'] ?></td><td><span class="status-pill <?= $row['active'] ? 'active' : 'passive' ?>"><?= $row['active'] ? 'Aktif' : 'Pasif' ?></span></td><td><a class="edit-definition" href="<?= url('sources.php?edit=' . (int)$row['id']) ?>">Düzenle</a><?php if ($usageCount): ?><button class="delete-definition disabled" type="button" disabled title="<?= $usageCount ?> hasta kaydında kullanılıyor">Sil</button><?php else: ?><form method="post" class="inline" onsubmit="return confirm('Bu başvuru kaynağı silinsin mi?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="delete-definition">Sil</button></form><?php endif ?></td></tr><?php endforeach ?>
    </tbody></table></div>
  </section>
</main>
<style>.definition-settings{max-width:1180px;margin:0 auto;padding:28px 20px 48px}.settings-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}.vuexy-form-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;margin-bottom:24px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.form-card-title{padding:22px 24px;border-bottom:1px solid #e1e2e8}.form-card-title h1,.form-card-title h2{margin:0 0 5px;font-size:21px}.form-card-title p{margin:0;color:#7b7b8d}.definition-form{display:flex;gap:16px;align-items:end;padding:24px;flex-wrap:wrap}.definition-form label{display:flex;flex-direction:column;gap:7px}.definition-form input:not([type=checkbox]){height:43px;min-width:240px;border:1px solid #d2d2dc;border-radius:7px;padding:0 12px;background:transparent;color:inherit}.definition-form .check{flex-direction:row;align-items:center;height:43px}.definition-form button,.edit-definition,.delete-definition{border:0;border-radius:7px;padding:11px 18px;text-decoration:none;font-weight:700;background:#19a94b;color:#fff}.delete-definition{background:#df4a4a;margin-left:8px}.delete-definition.disabled{background:#a9adb8;opacity:.65;cursor:not-allowed}.inline{display:inline}.definition-table{width:100%;border-collapse:collapse;min-width:680px}.definition-table th,.definition-table td{padding:14px 18px;border-bottom:1px solid #e1e2e8;text-align:left;white-space:nowrap}.table-responsive{overflow:auto}.vox-message{padding:13px 16px;margin:16px 24px;border-radius:7px}.vox-message.success{background:#daf5e3;color:#0d7130}.vox-message.error{background:#ffe3e3;color:#a21d1d}[data-theme=dark] .vuexy-form-card{background:#2f3349;color:#fff;border-color:#454a63}[data-theme=dark] .definition-form input:not([type=checkbox]){border-color:#5a607b;color:#fff}[data-theme=dark] .form-card-title p{color:#c4c7d6}@media(max-width:720px){.definition-settings{padding:20px 12px}.definition-form label{width:100%}.definition-form input:not([type=checkbox]){min-width:0;width:100%}}</style>
<?php patient_footer(); ?>
