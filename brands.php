<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_admin();
require __DIR__ . '/brand-model-bootstrap.php';
require __DIR__ . '/patient-layout.php';

function ensure_brand_and_model_schema(): void
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

    if ((int)$pdo->query('SELECT COUNT(*) FROM brands')->fetchColumn() === 0) {
        $insert = $pdo->prepare('INSERT INTO brands(name) VALUES(?)');
        foreach (['Resound', 'Beltone', 'Signia', 'Widex', 'Coselgi', 'Phonak', 'Philips', 'Duracell'] as $name) {
            $insert->execute([$name]);
        }
    }
}

ensure_brand_and_model_schema();
$pdo = db();
seed_brand_models_once($pdo);
$message = '';
$error = '';
$editBrandId = (int)($_GET['edit_brand'] ?? $_GET['edit'] ?? 0);
$editModelId = (int)($_GET['edit_model'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $entity = (string)($_POST['entity'] ?? 'brand');
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($entity === 'brand') {
        if ($action === 'delete' && $id > 0) {
            $modelCountStatement = $pdo->prepare('SELECT COUNT(*) FROM models WHERE brand_id=?');
            $modelCountStatement->execute([$id]);
            $modelCount = (int)$modelCountStatement->fetchColumn();
            if ($modelCount > 0) {
                $error = "Bu markaya bağlı {$modelCount} model bulunduğu için marka silinemez.";
            } else {
                $pdo->prepare('DELETE FROM brands WHERE id=?')->execute([$id]);
                $message = 'Marka silindi.';
            }
        } elseif ($action === 'save') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $error = 'Marka adı zorunludur.';
            } else {
                try {
                    if ($id > 0) {
                        $pdo->prepare('UPDATE brands SET name=? WHERE id=?')->execute([$name, $id]);
                        $message = 'Marka güncellendi.';
                        $editBrandId = $id;
                    } else {
                        $pdo->prepare('INSERT INTO brands(name) VALUES(?)')->execute([$name]);
                        $message = 'Marka eklendi.';
                    }
                } catch (PDOException $exception) {
                    $error = 'Bu marka zaten kayıtlı.';
                }
            }
        }
    } elseif ($entity === 'model') {
        if ($action === 'delete' && $id > 0) {
            $pdo->prepare('DELETE FROM models WHERE id=?')->execute([$id]);
            $message = 'Model silindi.';
        } elseif ($action === 'save') {
            $brandId = (int)($_POST['brand_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $brandStatement = $pdo->prepare('SELECT 1 FROM brands WHERE id=?');
            $brandStatement->execute([$brandId]);
            if (!$brandStatement->fetchColumn()) {
                $error = 'Lütfen geçerli bir marka seçin.';
                $editModelId = $id;
            } elseif ($name === '') {
                $error = 'Model adı zorunludur.';
                $editModelId = $id;
            } else {
                try {
                    if ($id > 0) {
                        $pdo->prepare('UPDATE models SET brand_id=?, name=? WHERE id=?')->execute([$brandId, $name, $id]);
                        $message = 'Model güncellendi.';
                        $editModelId = $id;
                    } else {
                        $pdo->prepare('INSERT INTO models(brand_id,name) VALUES(?,?)')->execute([$brandId, $name]);
                        $message = 'Model eklendi.';
                    }
                } catch (PDOException $exception) {
                    $error = 'Bu model adı zaten kayıtlı.';
                    $editModelId = $id;
                }
            }
        }
    }
}

$editBrand = ['id' => 0, 'name' => ''];
if ($editBrandId > 0) {
    $statement = $pdo->prepare('SELECT id,name FROM brands WHERE id=?');
    $statement->execute([$editBrandId]);
    $editBrand = $statement->fetch() ?: $editBrand;
} elseif ($error && ($_POST['entity'] ?? '') === 'brand') {
    $editBrand = ['id' => 0, 'name' => trim((string)($_POST['name'] ?? ''))];
}

$editModel = ['id' => 0, 'brand_id' => 0, 'name' => ''];
if ($editModelId > 0) {
    $statement = $pdo->prepare('SELECT id,brand_id,name FROM models WHERE id=?');
    $statement->execute([$editModelId]);
    $editModel = $statement->fetch() ?: $editModel;
} elseif ($error && ($_POST['entity'] ?? '') === 'model') {
    $editModel = [
        'id' => 0,
        'brand_id' => (int)($_POST['brand_id'] ?? 0),
        'name' => trim((string)($_POST['name'] ?? '')),
    ];
}

$brands = $pdo->query('SELECT id,name FROM brands ORDER BY id ASC')->fetchAll();
$brandOptions = $pdo->query('SELECT id,name FROM brands ORDER BY name')->fetchAll();
$firstBrandId = (int)($brandOptions[0]['id'] ?? 0);
$models = $pdo->query('SELECT models.id,models.brand_id,models.name,brands.name AS brand_name
    FROM models INNER JOIN brands ON brands.id=models.brand_id
    ORDER BY models.id ASC')->fetchAll();
$activeSection = (
    ($_GET['tab'] ?? '') === 'models'
    || $editModelId > 0
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['entity'] ?? '') === 'model')
) ? 'models' : 'brands';

patient_header('Kurulum - Markalar', 'settings');
?>
<main class="patient-container personnel-page brands-page">
  <nav class="settings-tabs brand-page-tabs" aria-label="Marka ve model yönetimi">
    <a class="<?=$activeSection === 'brands' ? 'active' : ''?>" href="<?=url('brands.php?tab=brands')?>">Markalar</a>
    <a class="<?=$activeSection === 'models' ? 'active' : ''?>" href="<?=url('brands.php?tab=models')?>">Modeller</a>
  </nav>
  <?php if ($message): ?><p class="manage-message success"><?=e($message)?></p><?php endif; ?>
  <?php if ($error): ?><p class="manage-message error"><?=e($error)?></p><?php endif; ?>

  <div class="brand-tab-panel" <?=$activeSection !== 'brands' ? 'hidden' : ''?>>
  <details class="vuexy-form-card manage-card admin-new-card"<?=((int)$editBrand['id'] || ($error && ($_POST['entity'] ?? '') === 'brand')) ? ' open' : ''?>>
    <summary class="form-card-title manage-head">
      <div><h1><?= (int)$editBrand['id'] ? 'Markayı Düzenle' : 'Yeni Marka' ?></h1><p>Ürün kartlarında kullanılacak markaları yönetin.</p></div>
      <i class="admin-card-chevron" aria-hidden="true"></i>
    </summary>
    <form class="personnel-form manage-form brand-form <?=$editBrand['id'] ? 'is-open' : ''?>" id="brand-form" method="post">
      <input type="hidden" name="csrf" value="<?=csrf()?>">
      <input type="hidden" name="entity" value="brand">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?=(int)$editBrand['id']?>">
      <label>Marka adı<input name="name" maxlength="190" required placeholder="Marka adı" value="<?=e($editBrand['name'])?>"></label>
      <div class="form-actions"><button type="submit">Kaydet</button><a href="<?=url('brands.php?tab=brands')?>">İptal</a></div>
    </form>
  </details>
  <section class="vuexy-form-card manage-card list-admin-card">
    <header class="form-card-title manage-head"><div><h2>Marka Listesi</h2><p><?=count($brands)?> kayıt</p></div></header>
    <div class="table-responsive manage-table-wrap">
      <table class="personnel-table manage-table brands-table">
        <thead><tr><th>ID</th><th>MARKA ADI</th><th>İŞLEMLER</th></tr></thead>
        <tbody>
          <?php foreach ($brands as $brand): ?>
            <tr><td><?=(int)$brand['id']?></td><td><?=e($brand['name'])?></td><td>
              <a class="row-action edit" href="<?=url('brands.php?tab=brands&amp;edit_brand='.(int)$brand['id'])?>" title="Düzenle" aria-label="<?=e($brand['name'])?> markasını düzenle"><?=action_icon('edit')?></a>
              <form method="post" onsubmit="return confirm('Bu marka silinsin mi?')">
                <input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="entity" value="brand"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$brand['id']?>">
                <button class="row-action delete" title="Sil" aria-label="<?=e($brand['name'])?> markasını sil"><?=action_icon('delete')?></button>
              </form>
            </td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  </div>

  <div class="brand-tab-panel models-section" id="models" <?=$activeSection !== 'models' ? 'hidden' : ''?>>
  <details class="vuexy-form-card manage-card admin-new-card"<?=((int)$editModel['id'] || ($error && ($_POST['entity'] ?? '') === 'model')) ? ' open' : ''?>>
    <summary class="form-card-title manage-head models-head">
      <div><h2><?= (int)$editModel['id'] ? 'Modeli Düzenle' : 'Yeni Model' ?></h2><p>Markalara bağlı ürün modellerini yönetin.</p></div>
      <i class="admin-card-chevron" aria-hidden="true"></i>
    </summary>
    <form class="personnel-form manage-form model-form <?=$editModel['id'] || ($error && ($_POST['entity'] ?? '') === 'model') ? 'is-open' : ''?>" id="model-form" method="post">
      <input type="hidden" name="csrf" value="<?=csrf()?>">
      <input type="hidden" name="entity" value="model">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?=(int)$editModel['id']?>">
      <label>Marka
        <select name="brand_id" required>
          <option value="">Marka Ara</option>
          <?php foreach ($brandOptions as $brand): ?>
            <option value="<?=(int)$brand['id']?>" <?=(int)$editModel['brand_id'] === (int)$brand['id'] ? 'selected' : ''?>><?=e($brand['name'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Model Adı<input name="name" maxlength="190" required placeholder="Model Adı" value="<?=e($editModel['name'])?>"></label>
      <div class="form-actions"><button type="submit">Kaydet</button><a href="<?=url('brands.php?tab=models')?>">İptal</a></div>
    </form>
  </details>
  <section class="vuexy-form-card manage-card list-admin-card">
    <header class="form-card-title manage-head models-list-head">
      <div><h2>Model Listesi</h2><p><?=count($models)?> kayıt</p></div>
      <label class="model-search">
        <span class="model-search-icon" aria-hidden="true">⌕</span>
        <input type="search" id="model-search" placeholder="Tüm modellerde ara" autocomplete="off" aria-label="Tüm modellerde ara">
      </label>
    </header>
    <nav class="brand-tabs" aria-label="Model markaları">
      <?php foreach ($brandOptions as $brand): ?>
        <?php $isFirstBrand = (int)$brand['id'] === $firstBrandId; ?>
        <button class="brand-tab <?=$isFirstBrand ? 'active' : ''?>" type="button" data-brand-filter="<?=(int)$brand['id']?>" aria-pressed="<?=$isFirstBrand ? 'true' : 'false'?>"><?=e($brand['name'])?></button>
      <?php endforeach; ?>
    </nav>
    <div class="table-responsive manage-table-wrap">
      <table class="personnel-table manage-table models-table">
        <thead><tr><th>ID</th><th>MARKA</th><th>MODEL ADI</th><th>İŞLEMLER</th></tr></thead>
        <tbody>
          <?php foreach ($models as $model): ?>
            <tr data-model-brand="<?=(int)$model['brand_id']?>" <?=(int)$model['brand_id'] !== $firstBrandId ? 'hidden' : ''?>><td><?=(int)$model['id']?></td><td><?=e($model['brand_name'])?></td><td><?=e($model['name'])?></td><td>
              <a class="row-action edit" href="<?=url('brands.php?tab=models&amp;edit_model='.(int)$model['id'].'#models')?>" title="Düzenle" aria-label="<?=e($model['name'])?> modelini düzenle"><?=action_icon('edit')?></a>
              <form method="post" onsubmit="return confirm('Bu model silinsin mi?')">
                <input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="entity" value="model"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$model['id']?>">
                <button class="row-action delete" title="Sil" aria-label="<?=e($model['name'])?> modelini sil"><?=action_icon('delete')?></button>
              </form>
            </td></tr>
          <?php endforeach; ?>
          <?php if (!$models): ?><tr class="empty-row"><td colspan="4">Henüz model kaydı bulunmuyor.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
  </div>
</main>
<?php
function action_icon(string $type): string
{
    if ($type === 'edit') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4Zm10-13 4 4"/></svg>';
    }
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5"/></svg>';
}
?>
<style>
.brands-page{max-width:1180px;margin:0 auto;padding:28px 20px 48px}.brand-page-tabs{display:flex;align-items:center;gap:8px;margin-bottom:20px}.brand-page-tabs a{padding:12px 18px;border-radius:8px;background:#e8f7ed;color:#16883d;text-decoration:none;font-weight:700}.brand-page-tabs a.active{background:#19a94b;color:#fff}.manage-card[hidden]{display:none!important}.manage-card{background:var(--card,#fff);border:1px solid var(--line,#e1e2e8);border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.models-section{margin-top:0;scroll-margin-top:24px}.manage-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:22px 24px;border-bottom:1px solid var(--line,#e1e2e8)}.models-head{display:grid;grid-template-columns:minmax(220px,1fr) minmax(260px,440px) minmax(220px,1fr)}.models-head>.manage-add{justify-self:end}.manage-head h1,.manage-head h2{margin:0 0 5px;font-size:21px}.manage-head p{margin:0;color:var(--muted,#7b7b8d)}.model-search{position:relative;display:block;width:100%;font-weight:400}.model-search-icon{position:absolute;z-index:1;left:13px;top:50%;transform:translateY(-52%);color:#8b8995;font-size:20px;pointer-events:none}.model-search input{width:100%;height:42px;padding:0 38px;border:1px solid #d7d6e0;border-radius:8px;background:var(--card,#fff);color:var(--text,#2f2b3d);font:inherit;outline:0}.model-search input:focus{border-color:#20a447;box-shadow:0 0 0 3px rgba(32,164,71,.13)}.model-search input::-webkit-search-cancel-button{cursor:pointer}.manage-add,.manage-form button{border:0;border-radius:6px;padding:10px 15px;background:#20a447;color:#fff;font-weight:700;cursor:pointer}.manage-form{display:none;align-items:end;gap:12px;padding:18px 24px;border-bottom:1px solid var(--line,#e1e2e8)}.manage-form.is-open{display:flex}.manage-form label{display:flex;flex:1;flex-direction:column;gap:6px;min-width:230px;font-size:13px;font-weight:700}.brand-form label{max-width:430px}.manage-form input,.manage-form select{height:38px;border:1px solid #d2d2dc;border-radius:6px;padding:0 10px;background:#fff;color:inherit;font:inherit}.form-actions{display:flex;align-items:center;gap:10px}.form-actions a{padding:10px;color:#ea5455;text-decoration:none}.brand-tabs{display:flex;align-items:center;gap:8px;width:100%;padding:16px 24px 0;overflow:hidden;border-bottom:1px solid var(--line,#e1e2e8)}.brand-tab{flex:1 1 0;min-width:0;min-height:38px!important;margin:0 0 -1px;padding:0 6px!important;overflow:hidden;border:1px solid #dedde5!important;border-bottom-color:var(--line,#e1e2e8)!important;border-radius:7px 7px 0 0!important;background:#f7f7f9!important;color:#6e6b7b!important;box-shadow:none!important;font-size:13px;text-overflow:ellipsis;white-space:nowrap}.brand-tab.active{border-color:#20a447!important;border-bottom-color:#fff!important;background:#fff!important;color:#16883d!important}.manage-table-wrap{overflow:auto}.manage-table{width:100%;border-collapse:collapse;min-width:650px}.models-table{min-width:760px}.manage-table th,.manage-table td{padding:14px 24px;border-bottom:1px solid var(--line,#e1e2e8);text-align:left;color:var(--muted,#6e6b7b)}.manage-table th{font-size:12px;font-weight:700;color:#5d596c}.manage-table td:last-child{display:flex;align-items:center}.manage-table td:last-child form{display:flex;width:49px;height:30px;margin:0}.row-action{box-sizing:border-box;display:grid;place-items:center;width:49px;min-width:49px;height:30px;min-height:30px;max-height:30px;margin:0;padding:0;border:0;color:#fff;text-decoration:none;line-height:1;cursor:pointer;transition:background .18s ease}.row-action svg{display:block;width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.row-action.edit{background:#35b85d;border-radius:5px 0 0 5px}.row-action.edit:hover{background:#20a447}.row-action.delete{background:#16883d;border-radius:0 5px 5px 0}.row-action.delete:hover{background:#0d7130}.manage-message{max-width:1180px;margin:0 0 16px;padding:12px 15px;border-radius:6px}.manage-message.success{background:#daf5e3;color:#0d7130}.manage-message.error{background:#ffe3e3;color:#9d2020}.empty-row td{text-align:center!important;padding:34px!important}.empty-row td:last-child{display:table-cell!important}[data-theme=dark] .manage-card{background:#2f3349;border-color:#454a63}[data-theme=dark] .manage-head,[data-theme=dark] .manage-table th,[data-theme=dark] .manage-table td,[data-theme=dark] .manage-form,[data-theme=dark] .brand-tabs{border-color:#454a63}[data-theme=dark] .manage-table th{color:#fff}[data-theme=dark] .manage-form input,[data-theme=dark] .manage-form select,[data-theme=dark] .model-search input{background:#30334d;color:#fff;border-color:#5a607b}[data-theme=dark] .brand-tab{background:#292c43!important;color:#c7c8d1!important;border-color:#454a63!important}[data-theme=dark] .brand-tab.active{background:#30334d!important;color:#75d392!important;border-color:#20a447!important;border-bottom-color:#30334d!important}@media(max-width:900px){.models-head{grid-template-columns:1fr auto}.model-search{grid-column:1/-1;grid-row:2}.brand-tabs{flex-wrap:wrap;padding-bottom:10px}.brand-tab{flex:1 1 calc(25% - 8px);margin-bottom:0}}@media(max-width:760px){.brands-page{padding:20px 12px}.brand-page-tabs{overflow-x:auto}.brand-page-tabs a{white-space:nowrap}.manage-head{align-items:flex-start;flex-direction:column}.models-head{display:flex}.models-head>.manage-add{align-self:flex-start}.manage-form{align-items:stretch;flex-direction:column}.manage-form label{width:100%;min-width:0}.form-actions{justify-content:flex-start}.brand-tabs{padding-left:14px;padding-right:14px}.brand-tab{flex-basis:calc(33.333% - 8px)}}
</style>
<style>
.patient-container.personnel-page.brands-page{max-width:1180px!important;margin:0 auto!important;padding:96px 32px 48px!important}
.brand-tab-panel[hidden]{display:none!important}
.admin-new-card>summary{position:relative;display:flex!important;align-items:center!important;padding-right:64px!important;cursor:pointer;list-style:none;user-select:none}
.admin-new-card>summary::-webkit-details-marker{display:none}.admin-new-card>summary::marker{display:none;content:""}
.admin-card-chevron{position:absolute;right:24px;top:50%;display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;font-style:normal;font-size:20px;font-weight:500;line-height:1;transform:translateY(-50%);transition:transform .2s ease}
.admin-card-chevron::before{content:">"}.admin-new-card[open] .admin-card-chevron{transform:translateY(-50%) rotate(90deg)}
.admin-new-card:not([open])>summary{border-bottom:0}
.brands-page .admin-new-card .personnel-form{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:18px 24px!important;padding:24px!important}
.brands-page .admin-new-card .personnel-form label{display:flex!important;flex-direction:column!important;gap:7px!important;min-width:0!important;max-width:none!important;font-size:14px!important;font-weight:400!important}
.brands-page .admin-new-card .personnel-form input,.brands-page .admin-new-card .personnel-form select{width:100%!important;height:43px!important;border:1px solid #d2d2dc!important;border-radius:7px!important;padding:0 12px!important}
.brands-page .admin-new-card .form-actions{grid-column:1/-1}
.list-admin-card{margin-top:24px}
.brands-page .manage-table td:last-child{display:flex!important;align-items:center!important;gap:8px!important}
.brands-page .manage-table td:last-child form{display:inline-flex!important;align-items:center!important;width:40px!important;height:42px!important;margin:0!important;padding:0!important}
.brands-page .manage-table .vox-icon-action.vox-icon-delete{margin:0!important}
.brands-page .manage-table .vox-icon-action{vertical-align:top!important}
.models-list-head{display:grid;grid-template-columns:minmax(220px,1fr) minmax(280px,440px)}
.models-list-head .model-search{justify-self:end}
@media(max-width:900px){.patient-container.personnel-page.brands-page{padding:92px 14px 30px!important}}
@media(max-width:760px){.brands-page .admin-new-card .personnel-form{grid-template-columns:1fr!important}.models-list-head{display:flex}.models-list-head .model-search{width:100%}}
</style>
<script>
document.querySelectorAll('[data-form-open]').forEach(button => {
  button.addEventListener('click', () => {
    const form = document.getElementById(button.dataset.formOpen);
    form?.classList.add('is-open');
    if (button.dataset.formOpen === 'model-form') {
      const activeBrand = document.querySelector('[data-brand-filter].active')?.dataset.brandFilter;
      const brandSelect = form?.querySelector('select[name="brand_id"]');
      if (activeBrand && brandSelect) {
        brandSelect.value = activeBrand;
      }
    }
  });
});
const brandTabs = document.querySelectorAll('[data-brand-filter]');
const modelRows = document.querySelectorAll('[data-model-brand]');
const modelBrandSelect = document.querySelector('#model-form select[name="brand_id"]');
const modelSearch = document.getElementById('model-search');
const applyModelFilter = () => {
  const query = modelSearch?.value.trim().toLocaleLowerCase('tr-TR') || '';
  const selectedBrand = document.querySelector('[data-brand-filter].active')?.dataset.brandFilter || '';
  modelRows.forEach(row => {
    const matchesSearch = query !== '' && row.textContent.toLocaleLowerCase('tr-TR').includes(query);
    row.hidden = query !== '' ? !matchesSearch : row.dataset.modelBrand !== selectedBrand;
  });
};
brandTabs.forEach(tab => {
  tab.addEventListener('click', () => {
    const selectedBrand = tab.dataset.brandFilter;
    brandTabs.forEach(item => {
      const isActive = item === tab;
      item.classList.toggle('active', isActive);
      item.setAttribute('aria-pressed', String(isActive));
    });
    applyModelFilter();
    if (modelBrandSelect) {
      modelBrandSelect.value = selectedBrand;
    }
  });
});
modelSearch?.addEventListener('input', applyModelFilter);
</script>
<?php patient_footer();
