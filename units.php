<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS units (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, description TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS units (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) NOT NULL UNIQUE, name VARCHAR(190) NOT NULL, description TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$extraColumns = [
    'record_date TEXT NULL', 'company_id INTEGER NULL', 'company_name VARCHAR(190) NULL', 'show_company INTEGER NOT NULL DEFAULT 0', 'show_name INTEGER NOT NULL DEFAULT 0', 'show_last_name INTEGER NOT NULL DEFAULT 0', 'unit_no VARCHAR(50) NULL', 'last_name VARCHAR(150) NULL', 'birth_date TEXT NULL', 'age INTEGER NULL',
    'marriage_date TEXT NULL', 'title VARCHAR(150) NULL', 'branch VARCHAR(150) NULL', 'rating INTEGER NULL',
    'email VARCHAR(190) NULL', 'phone1 VARCHAR(50) NULL', 'phone2 VARCHAR(50) NULL', 'gender VARCHAR(20) NULL',
    'special_day TEXT NULL', 'action_name VARCHAR(150) NULL', 'action_date TEXT NULL', 'city VARCHAR(100) NULL',
    'district VARCHAR(100) NULL', 'related_cards TEXT NULL', 'address TEXT NULL', 'note TEXT NULL'
];
foreach ($extraColumns as $definition) {
    $column = explode(' ', $definition, 2)[0];
    try {
        if ($sqlite) {
            $columns = array_column($pdo->query('PRAGMA table_info(units)')->fetchAll(), 'name');
            if (!in_array($column, $columns, true)) $pdo->exec('ALTER TABLE units ADD COLUMN ' . $definition);
        } else {
            $exists = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
            $exists->execute(['units', $column]);
            if (!$exists->fetchColumn()) $pdo->exec('ALTER TABLE units ADD COLUMN ' . $definition);
        }
    } catch (Throwable $exception) {
        error_log('units.php schema: ' . $exception->getMessage());
    }
}

/* Eski rastgele UNIT kodlarını, ünite kayıt sırasını koruyarak VOX formatına dönüştür. */
try {
    $unitCodes = $pdo->query('SELECT id, code FROM units ORDER BY id')->fetchAll();
    $hasLegacyCode = (bool)array_filter($unitCodes, static fn(array $row): bool => !preg_match('/^VOX-\d+$/', (string)$row['code']));
    if ($hasLegacyCode) {
        $pdo->beginTransaction();
        $temporary = $pdo->prepare('UPDATE units SET code=? WHERE id=?');
        foreach ($unitCodes as $row) $temporary->execute(['__VOX_UNIT_TMP_' . (int)$row['id'], (int)$row['id']]);
        foreach ($unitCodes as $index => $row) {
            $temporary->execute(['VOX-' . (127 + $index), (int)$row['id']]);
        }
        $pdo->commit();
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('units.php code migration: ' . $exception->getMessage());
}

$fields = ['record_date', 'company_name', 'show_company', 'unit_no', 'name', 'show_name', 'last_name', 'show_last_name', 'birth_date', 'age', 'marriage_date', 'title', 'branch', 'rating', 'email', 'phone1', 'phone2', 'gender', 'special_day', 'action_name', 'action_date', 'city', 'district', 'related_cards', 'address', 'note'];
$editId = (int)($_GET['edit'] ?? 0);
$showForm = $editId > 0 || isset($_GET['new']);
$unit = array_fill_keys($fields, '');
$unit['record_date'] = date('Y-m-d');
$unit['rating'] = 0;
$unit['gender'] = '';
$unit['show_company'] = 0;
$unit['show_name'] = 1;
$unit['show_last_name'] = 1;
$message = '';
$error = '';

if ($editId) {
    $statement = $pdo->prepare('SELECT * FROM units WHERE id=?');
    $statement->execute([$editId]);
    $found = $statement->fetch();
    if (!$found) {
        http_response_code(404);
        exit('Ünite kaydı bulunamadı.');
    }
    $unit = array_merge($unit, $found);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM units WHERE id=?')->execute([$id]);
            redirect('units.php');
        }
        foreach ($fields as $field) $unit[$field] = trim((string)($_POST[$field] ?? ''));
        foreach (['show_company', 'show_name', 'show_last_name'] as $field) $unit[$field] = isset($_POST[$field]) ? 1 : 0;
        $unit['rating'] = max(0, min(5, (int)$unit['rating']));
        if ($unit['name'] === '') throw new RuntimeException('Ad alanı zorunludur.');

        if ($action === 'update') {
            $set = implode(',', array_map(static fn(string $field): string => $field . '=?', $fields));
            $pdo->prepare('UPDATE units SET ' . $set . ' WHERE id=?')->execute([...array_map(static fn(string $field): mixed => $unit[$field], $fields), $id]);
            redirect('units.php?edit=' . $id . '&saved=1');
        }
        $existingCodes = $pdo->query("SELECT code FROM units WHERE code LIKE 'VOX-%'")->fetchAll(PDO::FETCH_COLUMN);
        $lastNumber = 126;
        foreach ($existingCodes as $existingCode) {
            if (preg_match('/^VOX-(\d+)$/', (string)$existingCode, $matches)) {
                $lastNumber = max($lastNumber, (int)$matches[1]);
            }
        }
        do {
            $code = 'VOX-' . (++$lastNumber);
            $check = $pdo->prepare('SELECT 1 FROM units WHERE code=?');
            $check->execute([$code]);
        } while ($check->fetchColumn());
        $columns = array_merge(['code'], $fields);
        $pdo->prepare('INSERT INTO units (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute([$code, ...array_map(static fn(string $field): mixed => $unit[$field], $fields)]);
        redirect('units.php?saved=1');
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('units.php save: ' . $exception->getMessage());
        $error = 'Kayıt işlemi tamamlanamadı.';
    }
}

if (isset($_GET['saved'])) $message = 'Ünite kaydedildi.';
function unit_list_display(array $unit): string {
    $parts = [];
    if (!empty($unit['show_company']) && !empty($unit['company_name'])) $parts[] = (string)$unit['company_name'];
    if (!empty($unit['show_name']) && !empty($unit['name'])) $parts[] = (string)$unit['name'];
    if (!empty($unit['show_last_name']) && !empty($unit['last_name'])) $parts[] = (string)$unit['last_name'];
    return trim(implode(' ', $parts)) ?: trim((string)($unit['name'] ?? '') . ' ' . (string)($unit['last_name'] ?? ''));
}
$units = $pdo->query('SELECT * FROM units ORDER BY name, last_name')->fetchAll();
patient_header($editId ? 'Ünite Düzenle' : ($showForm ? 'Yeni Ünite' : 'Üniteler'), 'cash');
?>
<style>
.unit-form-page{width:100%!important;max-width:1100px!important;margin:0 auto;padding:28px 20px 48px!important}.vuexy-form-card{background:var(--card);border:1px solid var(--line);border-radius:8px;box-shadow:0 .25rem 1.125rem rgba(47,43,61,.1);overflow:hidden}.vuexy-form-header{display:flex;align-items:center;justify-content:space-between;min-height:70px;padding:0 24px;border-bottom:1px solid var(--line)}.vuexy-form-header h2{margin:0;font-size:20px;font-weight:500}.vuexy-icon-form{padding:10px 24px 24px}.form-section-title{margin:16px 0 8px;padding-bottom:10px;border-bottom:1px solid var(--line);font-size:14px;color:#20a447}.unit-edit-row{display:grid;grid-template-columns:150px minmax(0,1fr);align-items:start;margin:14px 0}.unit-edit-label{padding:11px 15px 0 0;color:var(--text);font-size:14px}.required-mark{color:#e44747}.unit-edit-control{display:flex;align-items:stretch;min-height:40px;border:1px solid #d5d3de;border-radius:6px;background:var(--card);overflow:hidden}.unit-edit-control:focus-within{border-color:#20a447;box-shadow:0 0 0 3px rgba(32,164,71,.12)}.merged-icon{display:grid;place-items:center;flex:0 0 46px;color:#686574;font-size:18px}.unit-edit-control input,.unit-edit-control select,.unit-edit-control textarea{width:100%;height:38px;min-height:38px;margin:0;padding:8px 12px 8px 0;border:0;outline:0;background:transparent;color:var(--text);font:inherit}.unit-edit-control textarea{height:76px;resize:vertical;padding-top:10px}.unit-rating{display:flex;align-items:center;gap:5px;min-height:40px}.unit-rating input{position:absolute;opacity:0}.unit-rating label{font-size:29px;line-height:1;color:#d7d6de;cursor:pointer}.unit-rating label.is-selected{color:#f3a64a}.vuexy-form-actions{display:flex;align-items:center;gap:12px;margin:22px 0 0 150px}.vuexy-form-actions .button{min-width:100px}.cancel-link{color:var(--muted);text-decoration:none}.form-alert{margin:18px 24px 0;padding:12px 14px;border-radius:6px;background:#fde8e8;color:#a62c2c}.unit-list{width:calc(100% - 64px);max-width:none;margin:28px 32px 48px;border:1px solid var(--line);border-radius:8px;background:var(--card);overflow:hidden}.unit-list-toolbar{display:flex!important;align-items:center!important;justify-content:space-between!important;min-height:70px!important;padding:0 24px!important;border-bottom:1px solid var(--line)!important;background:var(--card)!important}.unit-list-toolbar h2{display:block!important;margin:0!important;padding:0!important;border:0!important;font-size:20px!important;color:var(--text)!important}.unit-list-toolbar .button{display:inline-flex!important;min-height:40px!important;padding:0 14px!important}.unit-list table{width:100%;border-collapse:collapse}.unit-list th,.unit-list td{padding:13px 18px;border-bottom:1px solid var(--line);text-align:left}.unit-list th{font-size:12px;color:var(--muted)}.unit-actions{display:flex;gap:8px}.unit-actions a,.unit-actions button{display:inline-grid;place-items:center;width:36px;height:36px;border:0;border-radius:6px;background:#20a447;color:#fff;cursor:pointer}.unit-actions button{background:#e04f55}.empty{text-align:center;color:var(--muted)}
.unit-field-icon{display:grid!important;place-items:center!important;flex:0 0 46px!important;width:46px!important;min-height:38px!important;border-right:1px solid var(--line)!important;color:#686574!important;font-size:16px!important;line-height:1!important}.unit-list-page{padding-top:96px!important}
.unit-list tbody tr[data-edit-url]{cursor:pointer}.unit-list tbody tr[data-edit-url]:hover{background:#f7fcf8}
.unit-actions .unit-visit-action{background:#f3a64a!important}.unit-actions .unit-visit-action:hover{background:#df8f2b!important}
.unit-actions .unit-patients-action{background:#7367f0!important}.unit-actions .unit-patients-action:hover{background:#5e52d8!important}
/* Tüm küçük işlem düğmelerini aynı kutu, hizalama ve aralıkta tut. */
.unit-actions{align-items:center!important;gap:8px!important}.unit-actions>a,.unit-actions>form,.unit-actions>form>button{box-sizing:border-box!important;width:36px!important;height:36px!important;min-width:36px!important;min-height:36px!important;max-width:36px!important;max-height:36px!important;margin:0!important;padding:0!important}.unit-actions>a,.unit-actions>form>button{display:grid!important;place-items:center!important;line-height:1!important}.unit-actions>form{display:block!important}.unit-actions>a .icon-base,.unit-actions>form>button .icon-base{font-size:17px!important;line-height:1!important}
/* Layout CSS from the admin shell applies broad form rules; lock this form to the patient-form grid. */
body .unit-form-page .vuexy-icon-form{display:block!important;width:100%!important;max-width:none!important;box-sizing:border-box!important}body .unit-form-page .unit-edit-row{display:flex!important;align-items:flex-start!important;gap:0!important;width:100%!important;min-width:0!important;margin:14px 0!important}body .unit-form-page .unit-edit-label{display:block!important;flex:0 0 150px!important;width:150px!important;min-width:150px!important;max-width:150px!important;padding:11px 15px 0 0!important;white-space:normal!important;overflow:visible!important}body .unit-form-page .unit-edit-control{display:flex!important;flex:1 1 0!important;align-items:stretch!important;width:auto!important;min-width:0!important;min-height:40px!important}body .unit-form-page .unit-edit-control input,body .unit-form-page .unit-edit-control select,body .unit-form-page .unit-edit-control textarea{display:block!important;position:static!important;flex:1 1 auto!important;width:100%!important;min-width:0!important;max-width:none!important;height:38px!important;min-height:38px!important;margin:0!important;opacity:1!important;visibility:visible!important}body .unit-form-page .unit-edit-control textarea{height:76px!important}body .unit-form-page .merged-icon{display:grid!important;position:static!important;flex:0 0 46px!important;width:46px!important;height:auto!important;opacity:1!important}body .unit-form-page .unit-rating{display:flex!important;flex:1 1 0!important;min-width:0!important}body .unit-form-page .vuexy-form-actions{display:flex!important;margin-left:150px!important}@media(max-width:720px){.unit-form-page{padding:20px 12px 30px!important}.vuexy-form-header,.vuexy-icon-form{padding-left:16px;padding-right:16px}body .unit-form-page .unit-edit-row{display:block!important}body .unit-form-page .unit-edit-label{width:auto!important;min-width:0!important;max-width:none!important;padding:0 0 7px!important}body .unit-form-page .vuexy-form-actions{margin-left:0!important}.unit-list{margin:0 12px 30px;width:auto;overflow:auto}.unit-list table{min-width:620px}}
</style>
<style>
/* Hizmet Yerleri ekranıyla ortak kart, yazı ve tablo ölçüleri. */
.unit-form-page,.unit-list-page{width:100%!important;max-width:1000px!important;min-height:100vh!important;margin:0 auto!important;padding:46px 20px 48px!important}
.unit-form-page .vuexy-form-card,.unit-list{background:#fff!important;border:1px solid #e1e2e8!important;border-radius:10px!important;box-shadow:0 3px 12px #1e283c0f!important}
.unit-form-page .vuexy-form-header{min-height:0!important;padding:22px 24px!important;border-bottom:1px solid #e1e2e8!important}.unit-form-page .vuexy-form-header h2{margin:0!important;color:#2f2b3d!important;font-size:21px!important;line-height:1.25!important;font-weight:600!important}
.unit-list{position:relative!important;width:100%!important;max-width:none!important;margin:0!important;overflow:hidden!important}.unit-list-toolbar{display:block!important;min-height:0!important;padding:22px 24px!important;border-bottom:1px solid #e1e2e8!important}.unit-list-toolbar h2{margin:0!important;color:#2f2b3d!important;font-size:21px!important;line-height:1.25!important;font-weight:600!important}.unit-list-toolbar .button{position:absolute!important;right:24px!important;top:14px!important;min-height:40px!important}
.unit-list th,.unit-list td{padding:14px 18px!important;border-bottom:1px solid #e1e2e8!important}.unit-list th{font-size:12px!important;color:#5d5b6d!important}.unit-list tbody tr:last-child td{border-bottom:0!important}
@media(max-width:720px){.unit-form-page,.unit-list-page{max-width:none!important;padding:92px 14px 30px!important}.unit-list-toolbar{padding-right:155px!important}.unit-list-toolbar .button{right:16px!important}.unit-list{overflow:auto!important}.unit-list table{min-width:620px!important}}
.unit-list-default{display:flex!important;align-items:center!important;gap:6px!important;flex:0 0 auto!important;white-space:nowrap!important;margin:0 0 0 10px!important;padding-top:10px!important;font-size:13px!important;color:#5d5b6d!important}.unit-list-default input{width:16px!important;height:16px!important;margin:0!important;accent-color:#20a447!important}@media(max-width:720px){.unit-list-default{margin:7px 0 0!important;padding-top:0!important}}
</style>
<?php if ($showForm): ?><main class="patient-container unit-form-page"><section class="vuexy-form-card"><header class="vuexy-form-header"><h2><?=$editId ? 'Ünite Düzenle' : 'Yeni Ünite Kaydı'?></h2><a class="cancel-link" href="<?=e(url('units.php'))?>">Listeye dön</a></header><?php if($error):?><div class="form-alert"><?=e($error)?></div><?php endif?><form class="vuexy-icon-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="<?=$editId?'update':'save'?>"><?php if($editId):?><input type="hidden" name="id" value="<?=$editId?>"><?php endif?>
<h3 class="form-section-title">Temel Bilgiler</h3>
<?php function unit_field(string $label, string $name, array $unit, string $icon, string $type='text', bool $required=false): void { ?><div class="unit-edit-row" style="display:flex!important;align-items:flex-start!important;width:100%!important;margin:14px 0!important"><label class="unit-edit-label" style="display:block!important;flex:0 0 150px!important;width:150px!important;padding:11px 15px 0 0!important" for="unit_<?=e($name)?>"><?=e($label)?><?=$required?' <span class="required-mark">*</span>':''?></label><div class="unit-edit-control" style="display:flex!important;flex:1 1 auto!important;min-width:0!important;height:40px!important;border:1px solid #d5d3de!important;border-radius:6px!important"><span class="merged-icon" aria-hidden="true"><i class="icon-base ti <?=e($icon)?>"></i></span><input style="display:block!important;position:static!important;flex:1 1 auto!important;width:100%!important;min-width:0!important;height:38px!important;margin:0!important;padding:8px 12px!important;border:0!important;background:#fff!important;opacity:1!important;visibility:visible!important" id="unit_<?=e($name)?>" type="<?=e($type)?>" name="<?=e($name)?>" value="<?=e((string)$unit[$name])?>" <?=$required?'required':''?><?=$name==='code_display'?' readonly':''?>></div></div><?php } ?>
<?php unit_field('Kayıt No', 'code_display', ['code_display'=>$editId ? $unit['code'] : 'Otomatik oluşturulur'], 'tabler-hash', 'text'); ?>
<?php unit_field('Ünite No', 'unit_no', $unit, 'tabler-building', 'text'); ?>
<?php unit_field('Kayıt Tarihi', 'record_date', $unit, 'tabler-calendar', 'date'); unit_field('Ad', 'name', $unit, 'tabler-user', 'text', true); unit_field('Soyad', 'last_name', $unit, 'tabler-user', 'text'); unit_field('Doğum Tarihi', 'birth_date', $unit, 'tabler-cake', 'date'); unit_field('Yaş', 'age', $unit, 'tabler-123', 'number'); unit_field('Evlilik Tarihi', 'marriage_date', $unit, 'tabler-heart', 'date'); unit_field('Ünvan', 'title', $unit, 'tabler-id-badge', 'text'); unit_field('Branş', 'branch', $unit, 'tabler-stethoscope', 'text'); unit_field('E-posta', 'email', $unit, 'tabler-mail', 'email'); unit_field('Telefon 1', 'phone1', $unit, 'tabler-phone', 'tel'); unit_field('Telefon 2', 'phone2', $unit, 'tabler-phone', 'tel'); ?>
<div class="unit-edit-row"><label class="unit-edit-label" for="unit_gender">Cinsiyet</label><div class="unit-edit-control"><span class="merged-icon"><i class="icon-base ti tabler-gender-bigender"></i></span><select id="unit_gender" name="gender"><option value="">Seçiniz</option><option value="Erkek" <?=$unit['gender']==='Erkek'?'selected':''?>>Erkek</option><option value="Kadın" <?=$unit['gender']==='Kadın'?'selected':''?>>Kadın</option></select></div></div>
<div class="unit-edit-row"><span class="unit-edit-label">Değerlendirme</span><div class="unit-rating" role="radiogroup" aria-label="Değerlendirme"><?php for($star=1;$star<=5;$star++):?><input id="rating_<?=$star?>" type="radio" name="rating" value="<?=$star?>" <?=((int)$unit['rating']===$star)?'checked':''?>><label class="<?=((int)$unit['rating'] >= $star)?'is-selected':''?>" for="rating_<?=$star?>">★</label><?php endfor;?></div></div>
<h3 class="form-section-title">İletişim ve Takip</h3>
<?php unit_field('Özel Gün', 'special_day', $unit, 'tabler-calendar-heart', 'date'); unit_field('Aksiyon', 'action_name', $unit, 'tabler-bolt', 'text'); unit_field('Aksiyon Tarihi', 'action_date', $unit, 'tabler-calendar-event', 'date'); unit_field('Şehir', 'city', $unit, 'tabler-building-community', 'text'); unit_field('İlçe', 'district', $unit, 'tabler-map-pin', 'text'); unit_field('İlişkili Kartlar', 'related_cards', $unit, 'tabler-cards', 'text'); ?>
<div class="unit-edit-row"><label class="unit-edit-label" for="unit_address">Adres</label><div class="unit-edit-control"><span class="merged-icon"><i class="icon-base ti tabler-map-pin"></i></span><textarea id="unit_address" name="address"><?=e((string)$unit['address'])?></textarea></div></div><div class="unit-edit-row"><label class="unit-edit-label" for="unit_note">Not</label><div class="unit-edit-control"><span class="merged-icon"><i class="icon-base ti tabler-notes"></i></span><textarea id="unit_note" name="note"><?=e((string)$unit['note'])?></textarea></div></div>
<div class="vuexy-form-actions"><button class="button">Kaydet</button><a class="cancel-link" href="<?=e(url('units.php'))?>">İptal</a></div></form></section></main><?php endif; ?>
<main class="patient-container unit-list-page"><section class="unit-list"><div class="unit-list-toolbar"><h2>Ünite Listesi</h2><a class="button" href="<?=e(url('units.php?new=1'))?>">+ Yeni Ünite</a></div><table><thead><tr><th>KAYIT NO</th><th>AD SOYAD</th><th>TELEFON</th><th>İŞLEMLER</th></tr></thead><tbody><?php foreach($units as $row):?><tr><td><?=e($row['code'])?></td><td><?=e(unit_list_display($row))?></td><td><?=e($row['phone1']??'')?></td><td><div class="unit-actions"><a href="<?=e(url('units.php?edit='.(int)$row['id']))?>" title="Düzenle"><i class="icon-base ti tabler-edit"></i></a><form method="post" onsubmit="return confirm('Bu ünite silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button title="Sil"><i class="icon-base ti tabler-trash"></i></button></form></div></td></tr><?php endforeach;if(!$units):?><tr><td colspan="4" class="empty">Henüz ünite bulunmuyor.</td></tr><?php endif?></tbody></table></section></main>
<script>
(() => {
  const recordDate = document.querySelector('input[name="record_date"]');
  if (!recordDate || document.querySelector('#unit_company_name')) return;
  const row = document.createElement('div');
  row.className = 'unit-edit-row';
  const label = document.createElement('label');
  label.className = 'unit-edit-label';
  label.htmlFor = 'unit_company_name';
  label.textContent = 'Firma';
  const control = document.createElement('div');
  control.className = 'unit-edit-control';
  control.innerHTML = '<span class="merged-icon" aria-hidden="true"><i class="icon-base ti tabler-building-community"></i></span>';
  const input = document.createElement('input');
  input.id = 'unit_company_name';
  input.name = 'company_name';
  input.type = 'text';
  input.value = <?=json_encode((string)($unit['company_name'] ?? ''))?>;
  input.placeholder = 'Firma adı giriniz';
  control.append(input);
  row.append(label, control);
  recordDate.closest('.unit-edit-row')?.after(row);
})();
(() => {
  const selected = { company_name: <?=json_encode(!empty($unit['show_company']))?>, name: <?=json_encode(!empty($unit['show_name']))?>, last_name: <?=json_encode(!empty($unit['show_last_name']))?> };
  ['company_name', 'name', 'last_name'].forEach(field => {
    const input = document.querySelector('[name="' + field + '"]');
    const row = input?.closest('.unit-edit-row');
    const suffix = field === 'company_name' ? 'company' : field === 'name' ? 'name' : 'last_name';
    if (!input || !row || row.querySelector('[name="show_' + suffix + '"]')) return;
    const option = document.createElement('label');
    option.className = 'unit-list-default';
    option.innerHTML = '<input type="checkbox" name="show_' + suffix + '" value="1"> Listede göster';
    option.querySelector('input').checked = !!selected[field];
    row.append(option);
  });
})();
document.querySelectorAll('.unit-edit-row').forEach(row=>{const label=row.querySelector('.unit-edit-label'),control=row.querySelector('.unit-edit-control,.unit-rating');row.style.setProperty('display','flex','important');row.style.setProperty('width','100%','important');row.style.setProperty('align-items','flex-start','important');if(label){label.style.setProperty('display','block','important');label.style.setProperty('flex','0 0 150px','important');label.style.setProperty('width','150px','important')}if(control){control.style.setProperty('display','flex','important');control.style.setProperty('flex','1 1 auto','important');control.style.setProperty('min-width','0','important');control.querySelectorAll('input:not([type=radio]),select,textarea').forEach(field=>{field.style.setProperty('display','block','important');field.style.setProperty('position','static','important');field.style.setProperty('width','100%','important');field.style.setProperty('height',field.tagName==='TEXTAREA'?'76px':'38px','important');field.style.setProperty('opacity','1','important');field.style.setProperty('visibility','visible','important')})}});
document.querySelectorAll('.unit-rating input').forEach(input=>input.addEventListener('change',()=>document.querySelectorAll('.unit-rating label').forEach((label,index)=>label.classList.toggle('is-selected',index<Number(input.value)))));
document.querySelectorAll('.unit-list tbody tr').forEach(row=>{const editLink=row.querySelector('.unit-actions a[href*="edit="]');if(!editLink)return;row.dataset.editUrl=editLink.href;row.addEventListener('dblclick',event=>{if(event.target.closest('a,button,form,input'))return;window.location.href=row.dataset.editUrl;});});
document.querySelectorAll('.unit-actions').forEach(actions=>{if(actions.querySelector('.unit-visit-action'))return;const editLink=actions.querySelector('a[href*="edit="]'),match=editLink?.href.match(/[?&]edit=(\d+)/);if(!match)return;const visit=document.createElement('a');visit.href=<?=json_encode(url('unit-visits.php'))?>+'?unit_id='+match[1];visit.className='unit-visit-action';visit.title='Ziyaret';visit.setAttribute('aria-label','Ziyaret');visit.innerHTML='<i class="icon-base ti tabler-map-pin"></i>';actions.prepend(visit);});
document.querySelectorAll('.unit-actions').forEach(actions=>{if(actions.querySelector('.unit-patients-action'))return;const editLink=actions.querySelector('a[href*="edit="]'),match=editLink?.href.match(/[?&]edit=(\d+)/);if(!match)return;const patients=document.createElement('a');patients.href=<?=json_encode(url('unit-patients.php'))?>+'?unit_id='+match[1];patients.className='unit-patients-action';patients.title='Hastalar';patients.setAttribute('aria-label','Hastalar');patients.innerHTML='<i class="icon-base ti tabler-users"></i>';actions.prepend(patients);});
document.querySelectorAll('.unit-actions').forEach(actions=>{actions.style.setProperty('display','grid','important');actions.style.setProperty('grid-template-columns','repeat(4, 36px)','important');actions.style.setProperty('gap','8px','important');actions.querySelectorAll(':scope > a,:scope > form,:scope > form > button').forEach(button=>{button.style.setProperty('box-sizing','border-box','important');button.style.setProperty('width','36px','important');button.style.setProperty('height','36px','important');button.style.setProperty('min-width','36px','important');button.style.setProperty('min-height','36px','important');button.style.setProperty('max-width','36px','important');button.style.setProperty('max-height','36px','important');button.style.setProperty('margin','0','important');button.style.setProperty('padding','0','important');});});
(()=>{const format=value=>{let digits=value.replace(/\D/g,'');if(digits.length===10&&digits.startsWith('5'))digits='0'+digits;return [digits.slice(0,4),digits.slice(4,7),digits.slice(7,9),digits.slice(9,11)].filter(Boolean).join(' ')};document.querySelectorAll('input[name="phone1"],input[name="phone2"]').forEach(input=>{input.value=format(input.value);input.addEventListener('input',()=>input.value=format(input.value));});})();
</script>
<?php patient_footer(); ?>
