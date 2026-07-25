<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_admin();
require __DIR__ . '/social-security-bootstrap.php';
social_security_definitions();
require __DIR__ . '/patient-layout.php';

$message = '';
$error = '';
$editId = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        db()->prepare('DELETE FROM social_security_definitions WHERE id=?')->execute([$id]);
        $message = 'Tanım silindi.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;
        $sort = (int)($_POST['sort_order'] ?? 0);
        if ($name === '') {
            $error = 'Tanım adı zorunludur.';
        } else {
            try {
                if ($id) {
                    db()->prepare('UPDATE social_security_definitions SET name=?,active=?,sort_order=? WHERE id=?')->execute([$name, $active, $sort, $id]);
                    $editId = $id;
                } else {
                    db()->prepare('INSERT INTO social_security_definitions(name,active,sort_order) VALUES(?,?,?)')->execute([$name, $active, $sort]);
                }
                $message = 'Tanım kaydedildi.';
            } catch (PDOException $exception) {
                $error = 'Bu tanım zaten mevcut.';
            }
        }
    }
}

$edit = ['id' => 0, 'name' => '', 'active' => 1, 'sort_order' => 0];
if ($editId) {
    $statement = db()->prepare('SELECT * FROM social_security_definitions WHERE id=?');
    $statement->execute([$editId]);
    $edit = $statement->fetch() ?: $edit;
}
$rows = db()->query('SELECT * FROM social_security_definitions ORDER BY sort_order,name')->fetchAll();

patient_header('Ayarlar - Sosyal Güvence', 'settings');
?>
<main class="patient-container personnel-page">
  <nav class="settings-tabs"></nav>
  <?php if ($message): ?><div class="vox-message success"><?= e($message) ?></div><?php endif ?>
  <?php if ($error): ?><div class="vox-message error"><?= e($error) ?></div><?php endif ?>

  <details class="vuexy-form-card social-security-accordion"<?=((int)$edit['id'] || $error !== '') ? ' open' : ''?>>
    <summary class="form-card-title">
      <span><h1><?= (int)$edit['id'] ? 'Sosyal Güvenceyi Düzenle' : 'Yeni Sosyal Güvence' ?></h1>
      <p>Hasta kayıtlarında seçilerek kullanılacak sosyal güvence tanımlarını yönetin.</p></span>
      <i class="social-security-chevron" aria-hidden="true"></i>
    </summary>
    <form method="post" class="personnel-form">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <label><span>Tanım</span><input name="name" value="<?= e($edit['name']) ?>" required></label>
      <label><span>Sıra</span><input type="number" name="sort_order" value="<?= (int)$edit['sort_order'] ?>"></label>
      <label class="personnel-check"><span>Durum</span><span><input type="checkbox" name="active" <?= $edit['active'] ? 'checked' : '' ?>> Aktif</span></label>
      <div class="personnel-actions"><button><?= (int)$edit['id'] ? 'Güncelle' : 'Kaydet' ?></button><?php if ((int)$edit['id']): ?><a class="edit-personnel" href="<?= url('social-securities.php') ?>">İptal</a><?php endif ?></div>
    </form>
  </details>

  <section class="vuexy-form-card">
    <header class="form-card-title"><h2>Sosyal Güvence Listesi</h2><p><?= count($rows) ?> kayıt</p></header>
    <div class="table-responsive">
      <table class="personnel-table">
        <thead><tr><th>Tanım</th><th>Sıra</th><th>Durum</th><th>İşlemler</th></tr></thead>
        <tbody><?php foreach ($rows as $row): ?>
          <tr>
            <td><?= e($row['name']) ?></td>
            <td><?= (int)$row['sort_order'] ?></td>
            <td><span class="status-pill <?= $row['active'] ? 'active' : 'passive' ?>"><?= $row['active'] ? 'Aktif' : 'Pasif' ?></span></td>
            <td><a class="edit-personnel" href="<?= url('social-securities.php?edit=' . (int)$row['id']) ?>">Düzenle</a><form method="post" class="social-delete" onsubmit="return confirm('Bu tanım silinsin mi?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button>Sil</button></form></td>
          </tr>
        <?php endforeach ?></tbody>
      </table>
    </div>
  </section>
</main>
<style>
.social-delete{display:inline}.social-delete button{display:inline-flex;align-items:center;justify-content:center;margin-left:8px;padding:11px 18px;border:0;border-radius:7px;background:#dc4c4c;color:#fff;font-weight:700;cursor:pointer}.social-delete button:hover{background:#bd3838}
.social-security-accordion>summary{position:relative;display:flex!important;align-items:center;padding-right:64px;cursor:pointer;list-style:none;user-select:none}
.social-security-accordion>summary::-webkit-details-marker{display:none}
.social-security-accordion>summary::marker{display:none;content:""}
.social-security-chevron{position:absolute;right:24px;top:50%;display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;font-style:normal;font-size:20px;font-weight:500;line-height:1;transform:translateY(-50%);transition:transform .2s ease}
.social-security-chevron::before{content:">"}
.social-security-accordion[open] .social-security-chevron{transform:translateY(-50%) rotate(90deg)}
.social-security-accordion:not([open])>summary{border-bottom:0}
</style>
<?php patient_footer(); ?>
