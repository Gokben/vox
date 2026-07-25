<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_admin();
require __DIR__ . '/cash-bootstrap.php';
require __DIR__ . '/patient-layout.php';

$pdo = db();
ensure_cash_schema($pdo);
$message = '';
$error = '';
$editId = (int)($_GET['edit'] ?? 0);
$selectedParentId = (int)($_GET['parent'] ?? 0);
$editingCategory = null;
if ($editId > 0) {
    $statement = $pdo->prepare('SELECT id,name,parent_id FROM cash_categories WHERE id=?');
    $statement->execute([$editId]);
    $editingCategory = $statement->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'save') {
            $name = trim((string)($_POST['name'] ?? ''));
            $parentId = (int)($_POST['parent_id'] ?? 0);
            if ($name === '') throw new RuntimeException('Kategori adı zorunludur.');
            if ($parentId > 0) {
                $parent = $pdo->prepare('SELECT id FROM cash_categories WHERE id=? AND parent_id IS NULL');
                $parent->execute([$parentId]);
                if (!$parent->fetchColumn()) throw new RuntimeException('Geçerli bir ana kategori seçin.');
            }
            $pdo->prepare('INSERT INTO cash_categories(name,parent_id,active) VALUES(?,?,1)')->execute([$name, $parentId ?: null]);
            $message = $parentId ? 'Alt kategori eklendi.' : 'Ana kategori eklendi.';
        } elseif ($action === 'update' && $id > 0) {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Kategori adı zorunludur.');
            $category = $pdo->prepare('SELECT id FROM cash_categories WHERE id=?');
            $category->execute([$id]);
            if (!$category->fetchColumn()) throw new RuntimeException('Kategori bulunamadı.');
            $pdo->prepare('UPDATE cash_categories SET name=? WHERE id=?')->execute([$name, $id]);
            $message = 'Kategori adı güncellendi.';
        } elseif ($action === 'toggle' && $id > 0) {
            $pdo->prepare('UPDATE cash_categories SET active=CASE WHEN active=1 THEN 0 ELSE 1 END WHERE id=? OR parent_id=?')->execute([$id, $id]);
            $message = 'Kategori durumu güncellendi.';
        } elseif ($action === 'delete' && $id > 0) {
            $children = $pdo->prepare('SELECT COUNT(*) FROM cash_categories WHERE parent_id=?');
            $children->execute([$id]);
            if ((int)$children->fetchColumn() > 0) throw new RuntimeException('Önce bu kategorinin alt kategorilerini silin.');
            $usage = $pdo->prepare('SELECT COUNT(*) FROM cash_transactions WHERE category_id=?');
            $usage->execute([$id]);
            if ((int)$usage->fetchColumn() > 0) throw new RuntimeException('Kullanılan kategori silinemez; pasifleştirebilirsiniz.');
            $pdo->prepare('DELETE FROM cash_categories WHERE id=?')->execute([$id]);
            $message = 'Kategori silindi.';
        }
    } catch (PDOException $exception) {
        $error = 'Bu kategori adı zaten kayıtlı.';
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

$categories = $pdo->query('SELECT c.*,p.name parent_name,(SELECT COUNT(*) FROM cash_categories child WHERE child.parent_id=c.id) child_count FROM cash_categories c LEFT JOIN cash_categories p ON p.id=c.parent_id ORDER BY COALESCE(c.parent_id,c.id), c.parent_id IS NOT NULL, c.name')->fetchAll();
$parents = array_values(array_filter($categories, static fn(array $category): bool => $category['parent_id'] === null));
if (!in_array($selectedParentId, array_map(static fn(array $category): int => (int)$category['id'], $parents), true)) $selectedParentId = 0;
$selectedParent = null;
foreach ($parents as $parent) {
    if ((int)$parent['id'] === $selectedParentId) { $selectedParent = $parent; break; }
}
$listedCategories = $selectedParent
    ? array_values(array_filter($categories, static fn(array $category): bool => (int)$category['parent_id'] === (int)$selectedParent['id']))
    : $parents;

patient_header('Kurulum - Kasa Kategorileri', 'settings');
?>
<main class="patient-container category-page">
  <div class="category-page-head"><h1><?=$selectedParent ? e($selectedParent['name']) . ' Alt Kategorileri' : 'Kasa Kategorileri'?></h1><p><?=$selectedParent ? 'Bu ana kategoriye bağlı alt kategorileri yönetin.' : 'Gelir ve gider kayıtları için ana kategori ve alt kategori tanımlayın.'?></p><?php if ($selectedParent): ?><a class="back-link" href="<?=url('cash-categories.php')?>">← Ana kategorilere dön</a><?php endif ?></div>
  <?php if ($message): ?><div class="category-notice success"><?=e($message)?></div><?php endif ?>
  <?php if ($error): ?><div class="category-notice error"><?=e($error)?></div><?php endif ?>
  <section class="category-card">
    <header><div><h2><?=$editingCategory ? 'Kategoriyi Düzenle' : ($selectedParent ? 'Yeni Alt Kategori' : 'Yeni Kategori')?></h2><p><?=$editingCategory ? 'Bu değişiklik mevcut kasa kayıtlarını etkilemez.' : ($selectedParent ? e($selectedParent['name']) . ' altında yeni bir alt kategori oluşturun.' : 'Alt kategori eklemek için önce ana kategoriyi seçin.')?></p></div></header>
    <form class="category-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="<?=$editingCategory ? 'update' : 'save'?>"><?php if ($editingCategory): ?><input type="hidden" name="id" value="<?=(int)$editingCategory['id']?>"><?php endif ?>
      <?php if ($editingCategory): ?><label>Kategori adı<input name="name" maxlength="150" required value="<?=e($editingCategory['name'])?>"></label><div class="category-form-note"><?=$editingCategory['parent_id'] === null ? 'Ana kategori' : 'Alt kategori'?></div><div class="category-form-actions"><button>Güncelle</button><a href="<?=url('cash-categories.php' . ($editingCategory['parent_id'] ? '?parent=' . (int)$editingCategory['parent_id'] : ''))?>">İptal</a></div><?php else: ?>
      <label>Kategori adı<input name="name" maxlength="150" required></label>
      <?php if ($selectedParent): ?><input type="hidden" name="parent_id" value="<?=(int)$selectedParent['id']?>"><div class="category-form-note">Ana kategori: <?=e($selectedParent['name'])?></div><?php else: ?><label>Bağlı ana kategori<select name="parent_id"><option value="">Ana kategori</option><?php foreach ($parents as $parent): ?><option value="<?=(int)$parent['id']?>" <?=$selectedParentId === (int)$parent['id'] ? 'selected' : ''?>><?=e($parent['name'])?></option><?php endforeach ?></select></label><?php endif ?>
      <button>Kaydet</button>
      <?php endif ?>
    </form>
  </section>
  <section class="category-card"><header><div><h2><?=$selectedParent ? e($selectedParent['name']) . ' Alt Kategori Listesi' : 'Kategori Listesi'?></h2><p><?=count($listedCategories)?> kayıt</p></div></header>
    <div class="category-table-wrap"><table><thead><tr><th>Kategori</th><th>Tür</th><th>Alt Kategori</th><th>Durum</th><th>İşlemler</th></tr></thead><tbody>
      <?php foreach ($listedCategories as $category): ?><tr class="<?=$category['parent_id'] === null ? 'parent' : 'child'?>"><td><?=e($category['name'])?></td><td><?=$category['parent_id'] === null ? 'Ana kategori' : e($category['parent_name'])?></td><td><?=$category['parent_id'] === null ? (int)$category['child_count'] : '—'?></td><td><span class="category-status <?=$category['active'] ? 'active' : 'passive'?>"><?=$category['active'] ? 'Aktif' : 'Pasif'?></span></td><td class="category-actions"><?php if ($category['parent_id'] === null): ?><a class="children" href="<?=url('cash-categories.php?parent=' . (int)$category['id'])?>" title="Alt Kategoriler" aria-label="Alt Kategoriler"><i class="ti tabler-git-branch"></i></a><?php endif ?><a class="edit" href="<?=url('cash-categories.php?edit=' . (int)$category['id'] . ($category['parent_id'] ? '&parent=' . (int)$category['parent_id'] : ''))?>" title="Düzenle" aria-label="Düzenle"><i class="ti tabler-edit"></i></a><form method="post" onsubmit="return confirm('Bu kategori silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$category['id']?>"><button class="delete" title="Sil"><i class="ti tabler-trash"></i></button></form></td></tr><?php endforeach ?>
      <?php if (!$listedCategories): ?><tr><td colspan="5" class="empty">Henüz kategori bulunmuyor.</td></tr><?php endif ?></tbody></table></div>
  </section>
</main>
<style>
.category-page{max-width:1100px!important;margin:0 auto!important;padding:96px 32px 48px!important}.category-page-head{margin-bottom:22px}.category-page-head h1,.category-card h2{margin:0 0 6px}.category-page-head p,.category-card header p{margin:0;color:var(--muted)}.back-link{display:inline-block;margin-top:10px;color:#16883d;text-decoration:none;font-weight:700}.category-card{margin-bottom:24px;overflow:hidden;border:1px solid var(--line);border-radius:10px;background:var(--card);box-shadow:0 3px 12px #1e283c0f}.category-card header{padding:20px 24px;border-bottom:1px solid var(--line)}.category-form{display:grid;grid-template-columns:1fr 1fr auto;gap:18px;padding:24px;align-items:end}.category-form label{display:flex;flex-direction:column;gap:7px}.category-form input,.category-form select{height:43px;padding:0 12px;border:1px solid #d2d2dc;border-radius:7px;background:transparent;color:inherit;font:inherit}.category-form button{height:43px;padding:0 22px;border:0;border-radius:7px;background:#19a94b;color:#fff;font-weight:700}.category-form-note{height:43px;display:flex;align-items:center;color:var(--muted)}.category-form-actions{display:flex;gap:10px;align-items:center}.category-form-actions a{color:var(--muted);text-decoration:none}.category-notice{margin-bottom:18px;padding:13px 16px;border-radius:8px}.category-notice.success{background:#daf5e3;color:#0d7130}.category-notice.error{background:#ffe3e3;color:#a21d1d}.category-table-wrap{overflow:auto}.category-table-wrap table{width:100%;border-collapse:collapse}.category-table-wrap th,.category-table-wrap td{padding:14px 18px;border-bottom:1px solid var(--line);text-align:left}.category-table-wrap th{font-size:12px}.category-table-wrap .parent td:first-child{font-weight:700}.category-table-wrap .child td:first-child{padding-left:38px}.category-status{display:inline-block;padding:6px 9px;border-radius:999px;font-size:12px;font-weight:700}.category-status.active{background:#e2f7e9;color:#12883c}.category-status.passive{background:#f1f1f4;color:#777}.category-actions{display:flex;gap:8px}.category-actions form{display:inline-flex}.category-actions button,.category-actions .edit,.category-actions .children{display:inline-flex;align-items:center;justify-content:center;width:40px;height:42px;border:0;border-radius:7px;color:#fff}.edit{background:#4685df;text-decoration:none}.children{background:#19a94b;text-decoration:none;font-weight:700}.deactivate,.delete{background:#e04f55}.activate{background:#19a94b}.empty{text-align:center;color:var(--muted)}[data-theme=dark] .category-card{background:#2f3349;border-color:#454a63}[data-theme=dark] .category-form input,[data-theme=dark] .category-form select{border-color:#5a607b;color:#fff}@media(max-width:700px){.category-page{padding:92px 14px 30px!important}.category-form{grid-template-columns:1fr}.category-form button{width:100%}}
</style>
<?php patient_footer(); ?>
