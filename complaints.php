<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_admin();
require __DIR__ . '/complaint-bootstrap.php';
require __DIR__ . '/patient-layout.php';

$pdo = db();
$message = ''; $error = '';

function complaint_is_used_in_service_forms(PDO $pdo, string $name): bool
{
    $containsName = static function (mixed $value) use (&$containsName, $name): bool {
        if (is_array($value)) {
            foreach ($value as $item) if ($containsName($item)) return true;
            return false;
        }
        return is_string($value) && trim($value) === $name;
    };
    foreach (['patient_services', 'external_technical_services'] as $table) {
        try {
            $records = $pdo->query("SELECT complaint, repair_details FROM {$table}")->fetchAll();
        } catch (Throwable) {
            continue;
        }
        foreach ($records as $record) {
            $details = json_decode((string)($record['repair_details'] ?? ''), true);
            $hasSavedIssueSelections = false;
            if (is_array($details)) {
                foreach (['repair_customer_issues', 'repair_customer_issues[]', 'repair_technician_issues', 'repair_technician_issues[]'] as $key) {
                    if (!array_key_exists($key, $details)) continue;
                    $hasSavedIssueSelections = true;
                    if ($containsName($details[$key])) return true;
                }
            }
            // Eski servis formlarında seçim dizileri bulunmaz; bu kayıtlarda
            // şikayet metni kaynak veridir. Yeni formlarda ise metin alanı
            // eski değer taşıyabileceğinden seçim dizileri önceliklidir.
            if (!$hasSavedIssueSelections) {
                $complaints = preg_split('/\\s*,\\s*/u', (string)($record['complaint'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                if (in_array($name, $complaints, true)) return true;
            }
        }
    }
    return false;
}

function complaint_move_to_position(PDO $pdo, int $id, int $position): void
{
    $statement = $pdo->prepare('SELECT id FROM complaint_definitions WHERE id<>? ORDER BY sort_order,id');
    $statement->execute([$id]);
    $orderedIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    $position = max(1, min(count($orderedIds) + 1, $position));
    array_splice($orderedIds, $position - 1, 0, [$id]);
    $update = $pdo->prepare('UPDATE complaint_definitions SET sort_order=? WHERE id=?');
    foreach ($orderedIds as $index => $definitionId) {
        $update->execute([$index + 1, $definitionId]);
    }
}

$editId = (int)($_GET['edit'] ?? $_POST['edit_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    // Düzenleme sayfasında kimlik URL'de de bulunur. Gizli alan boş gelse
    // bile mevcut satırı güncelle; yeni bir satır oluşturma.
    $postedEditId = (int)($_POST['edit_id'] ?? 0);
    $id = $postedEditId ?: (int)($_POST['id'] ?? $editId);
    if ($action === 'delete') {
        $definition = $pdo->prepare('SELECT name FROM complaint_definitions WHERE id=?');
        $definition->execute([$id]);
        $definitionName = (string)$definition->fetchColumn();
        if ($definitionName === '') {
            $error = 'Şikayet / arıza kalemi bulunamadı.';
        } else {
        if (complaint_is_used_in_service_forms($pdo, $definitionName)) {
            $error = 'Bu şikayet / arıza kalemi en az bir servis formunda seçildiği için silinemez.';
        } else {
            $pdo->prepare('DELETE FROM complaint_definitions WHERE id=?')->execute([$id]);
        $message = 'Şikayet / arıza kalemi silindi.';
        }
        }
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;
        $sort = (int)($_POST['sort_order'] ?? 0);
        if ($sort < 1) $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM complaint_definitions')->fetchColumn();
        if ($name === '') $error = 'Şikayet / arıza kalemi zorunludur.';
        else {
            $duplicate = $pdo->prepare('SELECT id FROM complaint_definitions WHERE name=? AND id<>?');
            $duplicate->execute([$name, $id]);
            if ($duplicate->fetchColumn()) $error = 'Bu kalem zaten kayıtlı.';
            elseif ($id) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE complaint_definitions SET name=?,active=? WHERE id=?')->execute([$name,$active,$id]);
                    complaint_move_to_position($pdo, $id, $sort);
                    $pdo->commit();
                    $message='Şikayet / arıza kalemi güncellendi.';
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $exception;
                }
            }
            else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO complaint_definitions(name,active,sort_order) VALUES(?,?,?)')->execute([$name,$active,$sort]);
                    complaint_move_to_position($pdo, (int)$pdo->lastInsertId(), $sort);
                    $pdo->commit();
                    $message='Şikayet / arıza kalemi eklendi.';
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $exception;
                }
            }
        }
    }
}
$nextSortOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM complaint_definitions')->fetchColumn();
$edit = ['id'=>0,'name'=>'','active'=>1,'sort_order'=>$nextSortOrder];
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
<style>.personnel-page{width:100%!important;max-width:1000px!important;min-height:100vh;margin:0 auto!important;padding:46px 20px 48px!important}.settings-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}.vuexy-form-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;margin-bottom:24px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.form-card-title{display:block!important;padding:22px 24px!important;border-bottom:1px solid #e1e2e8!important}.form-card-title h1,.form-card-title h2{margin:0 0 5px!important;color:#2f2b3d!important;font-size:21px!important;line-height:1.25!important}.form-card-title p{margin:0!important;color:#7b7b8d!important}.personnel-form{display:flex;gap:16px;align-items:end;padding:24px;flex-wrap:wrap}.personnel-form label{display:flex;flex-direction:column;gap:7px}.personnel-form input:not([type=checkbox]){height:43px;min-width:240px;border:1px solid #d2d2dc;border-radius:7px;padding:0 12px;background:transparent}.personnel-check{flex-direction:row!important;align-items:center;height:43px}.personnel-actions{display:flex;align-items:center;gap:8px}.personnel-actions button,.personnel-actions .edit-personnel{box-sizing:border-box;width:42px;height:42px;min-width:42px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:7px;text-decoration:none;font-weight:700}.personnel-actions button{background:#19a94b;color:#fff}.personnel-actions .edit-personnel{background:#df4a4a;color:#fff}.edit-definition,.delete-definition{border:0;border-radius:7px;padding:11px 18px;text-decoration:none;font-weight:700;background:#19a94b;color:#fff}.delete-definition{background:#df4a4a;margin-left:8px}.inline{display:inline}.personnel-table{width:100%;border-collapse:collapse;min-width:680px}.personnel-table th,.personnel-table td{padding:14px 18px;border-bottom:1px solid #e1e2e8;text-align:left;white-space:nowrap}.table-responsive{overflow:auto}.vox-message{padding:13px 16px;margin:16px 24px;border-radius:7px}.vox-message.success{background:#daf5e3;color:#0d7130}.vox-message.error{background:#ffe3e3;color:#a21d1d}.definition-accordion>summary{position:relative;display:flex!important;align-items:center;padding-right:64px!important;cursor:pointer;list-style:none}.definition-accordion>summary::-webkit-details-marker{display:none}.definition-chevron{position:absolute;right:24px;top:50%;font-style:normal;font-size:20px;transform:translateY(-50%)}.definition-chevron::before{content:'>'}.definition-accordion[open] .definition-chevron{transform:translateY(-50%) rotate(90deg)}@media(max-width:720px){.personnel-page{max-width:none!important;padding:92px 14px 30px!important}.personnel-form label{width:100%}.personnel-form input:not([type=checkbox]){min-width:0;width:100%}}</style>
<style>
body .personnel-page .personnel-actions>button,
body .personnel-page .personnel-actions>.edit-personnel{
  box-sizing:border-box!important;
  width:42px!important;
  min-width:42px!important;
  max-width:42px!important;
  height:42px!important;
  min-height:42px!important;
  max-height:42px!important;
  padding:0!important;
  margin:0!important;
  display:inline-flex!important;
  align-items:center!important;
  justify-content:center!important;
}
</style>
<?php patient_footer(); ?>
