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

$categories = $pdo->query('SELECT c.*,p.name parent_name FROM cash_categories c LEFT JOIN cash_categories p ON p.id=c.parent_id ORDER BY COALESCE(c.parent_id,c.id), c.parent_id IS NOT NULL, c.name')->fetchAll();
$parents = array_values(array_filter($categories, static fn(array $category): bool => $category['parent_id'] === null));

patient_header('Kurulum - Kasa Kategorileri', 'settings');
?>
<main class="patient-container category-page">
  <div class="category-page-head"><h1>Kasa Kategorileri</h1><p>Gelir ve gider kayıtları için ana kategori ve alt kategori tanımlayın.</p></div>
  <?php if ($message): ?><div class="category-notice success"><?=e($message)?></div><?php endif ?>
  <?php if ($error): ?><div class="category-notice error"><?=e($error)?></div><?php endif ?>
  <section class="category-card">
    <header><div><h2>Yeni Kategori</h2><p>Alt kategori eklemek için önce ana kategoriyi seçin.</p></div></header>
    <form class="category-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save">
      <label>Kategori adı<input name="name" maxlength="150" required></label>
      <label>Bağlı ana kategori<select name="parent_id"><option value="">Ana kategori</option><?php foreach ($parents as $parent): ?><option value="<?=(int)$parent['id']?>"><?=e($parent['name'])?></option><?php endforeach ?></select></label>
      <button>Kaydet</button>
    </form>
  </section>
  <section class="category-card"><header><div><h2>Kategori Listesi</h2><p><?=count($categories)?> kayıt</p></div></header>
    <div class="category-table-wrap"><table><thead><tr><th>Kategori</th><th>Tür</th><th>Durum</th><th>İşlemler</th></tr></thead><tbody>
      <?php foreach ($categories as $category): ?><tr class="<?=$category['parent_id'] === null ? 'parent' : 'child'?>"><td><?= $category['parent_id'] === null ? '' : '↳ ' ?><?=e($category['name'])?></td><td><?=$category['parent_id'] === null ? 'Ana kategori' : e($category['parent_name'])?></td><td><span class="category-status <?=$category['active'] ? 'active' : 'passive'?>"><?=$category['active'] ? 'Aktif' : 'Pasif'?></span></td><td class="category-actions"><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=(int)$category['id']?>"><button class="<?=$category['active'] ? 'deactivate' : 'activate'?>" title="<?=$category['active'] ? 'Pasifleştir' : 'Aktifleştir'?>"><i class="ti <?=$category['active'] ? 'tabler-home-x' : 'tabler-check'?>"></i></button></form><form method="post" onsubmit="return confirm('Bu kategori silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$category['id']?>"><button class="delete" title="Sil"><i class="ti tabler-trash"></i></button></form></td></tr><?php endforeach ?>
      <?php if (!$categories): ?><tr><td colspan="4" class="empty">Henüz kategori bulunmuyor.</td></tr><?php endif ?></tbody></table></div>
  </section>
</main>
<style>
.category-page{max-width:1100px!important;margin:0 auto!important;padding:96px 32px 48px!important}.category-page-head{margin-bottom:22px}.category-page-head h1,.category-card h2{margin:0 0 6px}.category-page-head p,.category-card header p{margin:0;color:var(--muted)}.category-card{margin-bottom:24px;overflow:hidden;border:1px solid var(--line);border-radius:10px;background:var(--card);box-shadow:0 3px 12px #1e283c0f}.category-card header{padding:20px 24px;border-bottom:1px solid var(--line)}.category-form{display:grid;grid-template-columns:1fr 1fr auto;gap:18px;padding:24px;align-items:end}.category-form label{display:flex;flex-direction:column;gap:7px}.category-form input,.category-form select{height:43px;padding:0 12px;border:1px solid #d2d2dc;border-radius:7px;background:transparent;color:inherit;font:inherit}.category-form button{height:43px;padding:0 22px;border:0;border-radius:7px;background:#19a94b;color:#fff;font-weight:700}.category-notice{margin-bottom:18px;padding:13px 16px;border-radius:8px}.category-notice.success{background:#daf5e3;color:#0d7130}.category-notice.error{background:#ffe3e3;color:#a21d1d}.category-table-wrap{overflow:auto}.category-table-wrap table{width:100%;border-collapse:collapse}.category-table-wrap th,.category-table-wrap td{padding:14px 18px;border-bottom:1px solid var(--line);text-align:left}.category-table-wrap th{font-size:12px}.category-table-wrap .parent td:first-child{font-weight:700}.category-table-wrap .child td:first-child{padding-left:38px}.category-status{display:inline-block;padding:6px 9px;border-radius:999px;font-size:12px;font-weight:700}.category-status.active{background:#e2f7e9;color:#12883c}.category-status.passive{background:#f1f1f4;color:#777}.category-actions{display:flex;gap:8px}.category-actions form{display:inline-flex}.category-actions button{display:inline-flex;align-items:center;justify-content:center;width:40px;height:42px;border:0;border-radius:7px;color:#fff}.deactivate,.delete{background:#e04f55}.activate{background:#19a94b}.empty{text-align:center;color:var(--muted)}[data-theme=dark] .category-card{background:#2f3349;border-color:#454a63}[data-theme=dark] .category-form input,[data-theme=dark] .category-form select{border-color:#5a607b;color:#fff}@media(max-width:700px){.category-page{padding:92px 14px 30px!important}.category-form{grid-template-columns:1fr}.category-form button{width:100%}}
</style>
<?php patient_footer(); ?>
