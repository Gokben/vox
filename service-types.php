<?php
require __DIR__ . '/config.php';
require_admin();
require __DIR__ . '/service-type-bootstrap.php';
require __DIR__ . '/patient-layout.php';

service_type_definitions();
ensure_patient_service_type_schema();
$message = '';
$error = '';
$editId = (int)($_GET['edit'] ?? $_POST['edit_id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'save';
    // edit_id, düzenleme formunda değiştirilemeyen gerçek kayıt kimliğidir.
    // Eski formlar için id alanını da geriye dönük destekliyoruz.
    $id = (int)($_POST['edit_id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) $id = (int)($_GET['edit'] ?? 0);
    $editMode = (string)($_GET['mode'] ?? '') === 'edit' || (string)($_POST['edit_mode'] ?? '') === '1';

    if ($action === 'delete') {
        $type = db()->prepare('SELECT name FROM service_type_definitions WHERE id=?');
        $type->execute([$id]);
        $typeName = (string)($type->fetchColumn() ?: '');
        $usage = db()->prepare('SELECT COUNT(*) FROM patients WHERE service_type_id=? OR service_type=?');
        $usage->execute([$id, $typeName]);
        $usageCount = (int)$usage->fetchColumn();
        if ($usageCount > 0) {
            $error = 'Bu hizmet yeri ' . $usageCount . ' hasta kaydında kullanılıyor. Silmek yerine pasif hale getirebilirsiniz.';
        } else {
            db()->prepare('DELETE FROM service_type_definitions WHERE id=?')->execute([$id]);
            $message = 'Hizmet yeri silindi.';
        }
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;
        $sort = (int)($_POST['sort_order'] ?? 0);
        if ($name === '') {
            $error = 'Hizmet yeri adı zorunludur.';
        } else {
            try {
                if ($id > 0) {
                    $existing = db()->prepare('SELECT id FROM service_type_definitions WHERE id=?');
                    $existing->execute([$id]);
                    if (!$existing->fetchColumn()) {
                        $error = 'Düzenlenecek hizmet yeri bulunamadı. Yeni kayıt oluşturulmadı.';
                    } else {
                        // Aynı kayıt kendi adıyla güncellenirken benzersiz ad kuralına takılmamalıdır.
                        $duplicate = db()->prepare('SELECT id FROM service_type_definitions WHERE name=? AND id<>? LIMIT 1');
                        $duplicate->execute([$name, $id]);
                        if ($duplicate->fetchColumn()) {
                            $error = 'Bu hizmet yeri adı başka bir kayıtta kullanılıyor.';
                        } else {
                            db()->prepare('UPDATE service_type_definitions SET name=?,active=?,sort_order=? WHERE id=?')->execute([$name, $active, $sort, $id]);
                            $editId = $id;
                            $message = 'Hizmet yeri güncellendi.';
                        }
                    }
                } elseif ($editMode) {
                    $error = 'Düzenlenecek hizmet yeri kimliği bulunamadı. Yeni kayıt oluşturulmadı.';
                } else {
                    db()->prepare('INSERT INTO service_type_definitions(name,active,sort_order) VALUES(?,?,?)')->execute([$name, $active, $sort]);
                    $message = 'Yeni hizmet yeri eklendi.';
                }
            } catch (PDOException $e) {
                error_log('Hizmet yeri güncelleme hatası (id ' . $id . '): ' . $e->getMessage());
                $error = $id > 0 ? 'Hizmet yeri güncellenirken bir veritabanı hatası oluştu.' : 'Bu hizmet yeri zaten kayıtlı.';
            }
        }
    }
}

$edit = ['id' => 0, 'name' => '', 'active' => 1, 'sort_order' => 0];
if ($editId) {
    $statement = db()->prepare('SELECT * FROM service_type_definitions WHERE id=?');
    $statement->execute([$editId]);
    $edit = $statement->fetch() ?: $edit;
}
$rows = service_type_definitions();
$usageById = [];
foreach (db()->query('SELECT service_type_id, COUNT(*) AS total FROM patients WHERE service_type_id IS NOT NULL GROUP BY service_type_id')->fetchAll() as $usageRow) {
    $usageById[(int)$usageRow['service_type_id']] = (int)$usageRow['total'];
}
patient_header('Ayarlar - Hizmet Yerleri', 'settings');
?>
<main class="definition-settings">
  <nav class="settings-tabs"></nav>
  <section class="vuexy-form-card">
    <header class="form-card-title"><h1><?= (int)$edit['id'] ? 'Hizmet Yerini Düzenle' : 'Yeni Hizmet Yeri' ?></h1><p><?= (int)$edit['id'] ? 'Mevcut hizmet yeri kaydını güncelliyorsunuz.' : 'Hasta kayıtlarında kullanılacak hizmet yerini tanımlayın.' ?></p></header>
    <?php if ($message): ?><p class="vox-message success"><?= e($message) ?></p><?php endif ?>
    <?php if ($error): ?><p class="vox-message error"><?= e($error) ?></p><?php endif ?>
    <form method="post" action="<?= url('service-types.php' . ((int)$edit['id'] ? '?edit=' . (int)$edit['id'] . '&mode=edit' : '')) ?>" class="definition-form">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="edit_id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <label>Hizmet Yeri<input name="name" value="<?= e($edit['name']) ?>" required></label>
      <label>Sıra<input type="number" name="sort_order" value="<?= (int)$edit['sort_order'] ?>"></label>
      <label class="check"><input type="checkbox" name="active" <?= $edit['active'] ? 'checked' : '' ?>> Aktif</label>
      <button><?= (int)$edit['id'] ? 'Güncelle' : 'Kaydet' ?></button><?php if ((int)$edit['id']): ?> <a class="cancel-edit" href="<?=url('service-types.php')?>">İptal</a><?php endif; ?>
    </form>
  </section>
  <section class="vuexy-form-card">
    <header class="form-card-title"><h2>Hizmet Yeri Listesi</h2><p><?= count($rows) ?> kayıt</p></header>
    <div class="table-responsive"><table class="definition-table"><thead><tr><th>Hizmet Yeri</th><th>Sıra</th><th>Durum</th><th>İşlemler</th></tr></thead><tbody>
      <?php foreach ($rows as $row): $usageCount = $usageById[(int)$row['id']] ?? 0; ?><tr><td><?= e($row['name']) ?></td><td><?= (int)$row['sort_order'] ?></td><td><span class="status-pill <?= $row['active'] ? 'active' : 'passive' ?>"><?= $row['active'] ? 'Aktif' : 'Pasif' ?></span></td><td><a class="edit-definition" href="<?= url('service-types.php?edit=' . (int)$row['id']) ?>">Düzenle</a><?php if ($usageCount): ?><button class="delete-definition disabled" type="button" disabled title="<?= $usageCount ?> hasta kaydında kullanılıyor">Sil</button><?php else: ?><form method="post" class="inline" onsubmit="return confirm('Bu hizmet yeri silinsin mi?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="delete-definition">Sil</button></form><?php endif ?></td></tr><?php endforeach ?>
    </tbody></table></div>
  </section>
</main>
<style>.definition-settings{max-width:1180px;margin:0 auto;padding:28px 20px 48px}.settings-tabs{display:flex;gap:8px;margin-bottom:20px}.vuexy-form-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;margin-bottom:24px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.form-card-title{padding:22px 24px;border-bottom:1px solid #e1e2e8}.form-card-title h1,.form-card-title h2{margin:0 0 5px;font-size:21px}.form-card-title p{margin:0;color:#7b7b8d}.definition-form{display:flex;gap:16px;align-items:end;padding:24px;flex-wrap:wrap}.definition-form label{display:flex;flex-direction:column;gap:7px}.definition-form input:not([type=checkbox]){height:43px;min-width:240px;border:1px solid #d2d2dc;border-radius:7px;padding:0 12px;background:transparent;color:inherit}.definition-form .check{flex-direction:row;align-items:center;height:43px}.definition-form button,.edit-definition,.delete-definition{border:0;border-radius:7px;padding:11px 18px;text-decoration:none;font-weight:700;background:#19a94b;color:#fff}.delete-definition{background:#df4a4a;margin-left:8px}.delete-definition.disabled{background:#a9adb8;opacity:.65;cursor:not-allowed}.inline{display:inline}.definition-table{width:100%;border-collapse:collapse;min-width:680px}.definition-table th,.definition-table td{padding:14px 18px;border-bottom:1px solid #e1e2e8;text-align:left;white-space:nowrap}.table-responsive{overflow:auto}.vox-message{padding:13px 16px;margin:16px 24px;border-radius:7px}.vox-message.success{background:#daf5e3;color:#0d7130}.vox-message.error{background:#ffe3e3;color:#a21d1d}[data-theme=dark] .vuexy-form-card{background:#2f3349;color:#fff;border-color:#454a63}[data-theme=dark] .definition-form input:not([type=checkbox]){border-color:#5a607b;color:#fff}[data-theme=dark] .form-card-title p{color:#c4c7d6}@media(max-width:720px){.definition-settings{padding:20px 12px}.definition-form label{width:100%}.definition-form input:not([type=checkbox]){min-width:0;width:100%}}</style>
<?php patient_footer(); ?>
