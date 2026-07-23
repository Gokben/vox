<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_admin();
require __DIR__ . '/patient-layout.php';

function ensure_model_schema(): void
{
    $pdo = db();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS brands (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(190) NOT NULL UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            brand_id INTEGER NOT NULL,
            name VARCHAR(190) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (name),
            FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE RESTRICT
        )');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS models_name_unique ON models(name COLLATE NOCASE)');
    } else {
        $pdo->exec('CREATE TABLE IF NOT EXISTS brands (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS models (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            brand_id INT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY models_name_unique (name),
            CONSTRAINT models_brand_fk FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $indexStatement = $pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='models' AND index_name='models_name_unique' LIMIT 1");
        if (!$indexStatement->fetchColumn()) {
            $pdo->exec('ALTER TABLE models ADD UNIQUE KEY models_name_unique (name)');
        }
    }
}

ensure_model_schema();
$pdo = db();
$message = '';
$error = '';
$editId = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM models WHERE id=?')->execute([$id]);
        $message = 'Model silindi.';
    } elseif ($action === 'save') {
        $brandId = (int)($_POST['brand_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $brandExists = false;
        if ($brandId > 0) {
            $brandStatement = $pdo->prepare('SELECT 1 FROM brands WHERE id=?');
            $brandStatement->execute([$brandId]);
            $brandExists = (bool)$brandStatement->fetchColumn();
        }

        if (!$brandExists) {
            $error = 'Lütfen geçerli bir marka seçin.';
        } elseif ($name === '') {
            $error = 'Model adı zorunludur.';
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare('UPDATE models SET brand_id=?, name=? WHERE id=?')->execute([$brandId, $name, $id]);
                    $message = 'Model güncellendi.';
                    $editId = $id;
                } else {
                    $pdo->prepare('INSERT INTO models(brand_id,name) VALUES(?,?)')->execute([$brandId, $name]);
                    $message = 'Model eklendi.';
                }
            } catch (PDOException $exception) {
                $error = 'Bu model adı zaten kayıtlı.';
            }
        }
    }
}

$edit = ['id' => 0, 'brand_id' => 0, 'name' => ''];
if ($editId > 0) {
    $statement = $pdo->prepare('SELECT id,brand_id,name FROM models WHERE id=?');
    $statement->execute([$editId]);
    $edit = $statement->fetch() ?: $edit;
}

$brands = $pdo->query('SELECT id,name FROM brands ORDER BY name')->fetchAll();
$models = $pdo->query('SELECT models.id,models.name,brands.name AS brand_name
    FROM models INNER JOIN brands ON brands.id=models.brand_id
    ORDER BY models.id ASC')->fetchAll();

patient_header('Kurulum - Modeller', 'settings');
?>
<main class="models-page">
  <section class="models-card">
    <header class="models-head">
      <div><h1>Modeller</h1><p>Markalara bağlı ürün modellerini yönetin.</p></div>
      <button class="model-add" type="button" id="model-form-open">+ Model ekle</button>
    </header>

    <?php if ($message): ?><p class="model-message success"><?=e($message)?></p><?php endif; ?>
    <?php if ($error): ?><p class="model-message error"><?=e($error)?></p><?php endif; ?>

    <form class="model-form <?=$edit['id'] || $error ? 'is-open' : ''?>" id="model-form" method="post">
      <input type="hidden" name="csrf" value="<?=csrf()?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?=(int)$edit['id']?>">
      <label>Marka
        <select name="brand_id" required>
          <option value="">Marka Ara</option>
          <?php foreach ($brands as $brand): ?>
            <option value="<?=(int)$brand['id']?>" <?=(int)$edit['brand_id'] === (int)$brand['id'] ? 'selected' : ''?>><?=e($brand['name'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Model Adı<input name="name" maxlength="190" required placeholder="Model Adı" value="<?=e($edit['name'])?>"></label>
      <div class="model-form-actions"><button type="submit">Kaydet</button><a href="<?=url('models.php')?>">İptal</a></div>
    </form>

    <?php if (!$brands): ?><p class="model-message error">Model ekleyebilmek için önce bir marka ekleyin.</p><?php endif; ?>

    <div class="models-table-wrap">
      <table class="models-table">
        <thead><tr><th>ID</th><th>MARKA</th><th>MODEL ADI</th><th>İŞLEMLER</th></tr></thead>
        <tbody>
          <?php foreach ($models as $model): ?>
            <tr>
              <td><?=(int)$model['id']?></td>
              <td><?=e($model['brand_name'])?></td>
              <td><?=e($model['name'])?></td>
              <td>
                <a class="model-action edit" href="<?=url('models.php?edit='.(int)$model['id'])?>" title="Düzenle" aria-label="<?=e($model['name'])?> modelini düzenle">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4Zm10-13 4 4"/></svg>
                </a>
                <form method="post" onsubmit="return confirm('Bu model silinsin mi?')">
                  <input type="hidden" name="csrf" value="<?=csrf()?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?=(int)$model['id']?>">
                  <button class="model-action delete" title="Sil" aria-label="<?=e($model['name'])?> modelini sil">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5"/></svg>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$models): ?><tr class="empty-row"><td colspan="4">Henüz model kaydı bulunmuyor.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<style>
.models-page{max-width:1180px;margin:0 auto;padding:28px 20px 48px}.models-card{background:var(--card,#fff);border:1px solid var(--line,#e1e2e8);border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.models-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:22px 24px;border-bottom:1px solid var(--line,#e1e2e8)}.models-head h1{margin:0 0 5px;font-size:21px}.models-head p{margin:0;color:var(--muted,#7b7b8d)}.model-add,.model-form button{border:0;border-radius:6px;padding:10px 15px;background:#20a447;color:#fff;font-weight:700;cursor:pointer}.model-form{display:none;grid-template-columns:minmax(240px,1fr) minmax(240px,1fr) auto;align-items:end;gap:12px;padding:18px 24px;border-bottom:1px solid var(--line,#e1e2e8)}.model-form.is-open{display:grid}.model-form label{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:700}.model-form input,.model-form select{height:38px;border:1px solid #d2d2dc;border-radius:6px;padding:0 10px;background:#fff;color:inherit;font:inherit}.model-form-actions{display:flex;align-items:center;gap:12px}.model-form-actions a{padding:10px;color:#ea5455;text-decoration:none}.models-table-wrap{overflow:auto}.models-table{width:100%;border-collapse:collapse;min-width:760px}.models-table th,.models-table td{padding:14px 24px;border-bottom:1px solid var(--line,#e1e2e8);text-align:left;color:var(--muted,#6e6b7b)}.models-table th{font-size:12px;font-weight:700;color:#5d596c}.models-table td:last-child{display:flex;align-items:center}.models-table td:last-child form{display:flex;height:30px;margin:0}.model-action{box-sizing:border-box;display:grid;place-items:center;width:49px;height:30px;margin:0;padding:0;border:0;color:#fff;text-decoration:none;cursor:pointer}.model-action svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.model-action.edit{background:#ff9f43;border-radius:5px 0 0 5px}.model-action.delete{background:#ff4d5a;border-radius:0 5px 5px 0}.model-message{margin:16px 24px;padding:12px 15px;border-radius:6px}.model-message.success{background:#daf5e3;color:#0d7130}.model-message.error{background:#ffe3e3;color:#9d2020}.empty-row td{text-align:center!important;padding:34px!important}.empty-row td:last-child{display:table-cell!important}[data-theme=dark] .models-card{background:#2f3349;border-color:#454a63}[data-theme=dark] .models-head,[data-theme=dark] .models-table th,[data-theme=dark] .models-table td,[data-theme=dark] .model-form{border-color:#454a63}[data-theme=dark] .models-table th{color:#fff}[data-theme=dark] .model-form input,[data-theme=dark] .model-form select{background:#30334d;color:#fff;border-color:#5a607b}@media(max-width:760px){.models-page{padding:20px 12px}.models-head{align-items:flex-start;flex-direction:column}.model-form{grid-template-columns:1fr}.model-form-actions{justify-content:flex-start}}
</style>
<script>
document.getElementById('model-form-open').addEventListener('click', () => document.getElementById('model-form').classList.add('is-open'));
</script>
<?php patient_footer();
