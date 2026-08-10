<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS current_account_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, current_account_id INTEGER NOT NULL, original_name TEXT NOT NULL, stored_path TEXT NOT NULL, mime_type TEXT NOT NULL, file_size INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS current_account_documents (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, current_account_id INT UNSIGNED NOT NULL, original_name VARCHAR(255) NOT NULL, stored_path VARCHAR(500) NOT NULL, mime_type VARCHAR(120) NOT NULL, file_size INT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_current_account_documents_account (current_account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$accountId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: (int)($_POST['account_id'] ?? 0);
$accountStatement = $pdo->prepare('SELECT id, code, title, short_name FROM current_accounts WHERE id=?');
$accountStatement->execute([$accountId]);
$account = $accountStatement->fetch();
if (!$account) {
    http_response_code(404);
    exit('Cari kart bulunamadı.');
}

$message = '';
$error = '';
$documentsStatement = $pdo->prepare('SELECT * FROM current_account_documents WHERE current_account_id=? ORDER BY id');
$documentsStatement->execute([$accountId]);
$documents = $documentsStatement->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $names = (array)($_FILES['documents']['name'] ?? []);
    $temporaryFiles = (array)($_FILES['documents']['tmp_name'] ?? []);
    $errors = (array)($_FILES['documents']['error'] ?? []);
    $sizes = (array)($_FILES['documents']['size'] ?? []);
    $selectedIndexes = array_values(array_filter(array_keys($names), static fn($index): bool => (int)($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    $remainingSlots = max(0, 3 - count($documents));
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
    ];
    $validated = [];
    if (!$selectedIndexes) $error = 'Yüklenecek en az bir dosya seçin.';
    elseif (count($selectedIndexes) > $remainingSlots) $error = 'Bu cari karta en fazla 3 evrak yükleyebilirsiniz.';
    else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        foreach ($selectedIndexes as $index) {
            if ((int)($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $error = 'Dosyalardan biri yüklenemedi.'; break; }
            if ((int)($sizes[$index] ?? 0) > 10485760) { $error = 'Her dosya en fazla 10 MB olabilir.'; break; }
            $temporaryFile = (string)($temporaryFiles[$index] ?? '');
            $mime = $temporaryFile !== '' ? (string)$finfo->file($temporaryFile) : '';
            if (!isset($allowedTypes[$mime])) { $error = 'JPG, PNG, GIF, WEBP, PDF, Word, Excel veya TXT dosyası yükleyebilirsiniz.'; break; }
            $validated[] = [
                'temporary_file' => $temporaryFile,
                'original_name' => mb_substr(basename((string)$names[$index]), 0, 255),
                'mime' => $mime,
                'size' => (int)$sizes[$index],
                'extension' => $allowedTypes[$mime],
            ];
        }
    }
    if ($error === '') {
        $directory = __DIR__ . '/assets/uploads/current-accounts/' . $accountId;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) $error = 'Evrak klasörü oluşturulamadı.';
        else {
            $savedFiles = [];
            try {
                $pdo->beginTransaction();
                $insert = $pdo->prepare('INSERT INTO current_account_documents(current_account_id,original_name,stored_path,mime_type,file_size) VALUES(?,?,?,?,?)');
                foreach ($validated as $file) {
                    $filename = bin2hex(random_bytes(16)) . '.' . $file['extension'];
                    $absolutePath = $directory . '/' . $filename;
                    if (!move_uploaded_file($file['temporary_file'], $absolutePath)) throw new RuntimeException('Evrak dosyası kaydedilemedi.');
                    $savedFiles[] = $absolutePath;
                    $relativePath = 'assets/uploads/current-accounts/' . $accountId . '/' . $filename;
                    $insert->execute([$accountId, $file['original_name'], $relativePath, $file['mime'], $file['size']]);
                }
                $pdo->commit();
                redirect('current-account-documents.php?id=' . $accountId . '&uploaded=1');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($savedFiles as $savedFile) if (is_file($savedFile)) unlink($savedFile);
                $error = $exception->getMessage();
            }
        }
    }
}

$documentsStatement->execute([$accountId]);
$documents = $documentsStatement->fetchAll();
if (isset($_GET['uploaded'])) $message = 'Evraklar yüklendi.';
$remainingSlots = max(0, 3 - count($documents));
$accountName = trim((string)($account['short_name'] ?? '')) ?: (string)$account['title'];

patient_header('Cari Evrakları', 'cash');
?>
<main class="patient-container account-documents-page">
  <section class="account-documents-card">
    <header>
      <div><h1><i class="ti tabler-files"></i> Cari Evrakları</h1><p><?=e((string)$account['code'])?> · <?=e($accountName)?></p></div>
      <a class="documents-back" href="<?=url('current-accounts.php?edit='.$accountId)?>" title="Geri" aria-label="Geri"><i class="ti tabler-arrow-back-up"></i></a>
    </header>
    <?php if ($message): ?><div class="documents-notice success"><?=e($message)?></div><?php endif ?>
    <?php if ($error): ?><div class="documents-notice error"><?=e($error)?></div><?php endif ?>
    <form method="post" enctype="multipart/form-data" class="documents-upload-form">
      <input type="hidden" name="csrf" value="<?=csrf()?>">
      <input type="hidden" name="account_id" value="<?=$accountId?>">
      <label><span>Evrak veya resim seçin</span><input type="file" name="documents[]" multiple accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,.txt" <?=$remainingSlots===0?'disabled':''?>></label>
      <small>En fazla 3 dosya · Her dosya en fazla 10 MB · Kalan yükleme hakkı: <?=$remainingSlots?></small>
      <button title="Yükle" aria-label="Evrakları yükle" <?=$remainingSlots===0?'disabled':''?>><i class="ti tabler-upload"></i></button>
    </form>
    <div class="documents-grid">
      <?php foreach ($documents as $document): ?>
        <?php $isImage = str_starts_with((string)$document['mime_type'], 'image/'); ?>
        <a class="document-item" href="<?=url((string)$document['stored_path'])?>" target="_blank" rel="noopener" title="Evrakı aç">
          <span class="document-preview"><?php if ($isImage): ?><img src="<?=url((string)$document['stored_path'])?>" alt=""><?php else: ?><i class="ti <?=((string)$document['mime_type']==='application/pdf')?'tabler-file-type-pdf':'tabler-file-description'?>"></i><?php endif ?></span>
          <strong><?=e((string)$document['original_name'])?></strong>
          <small><?=e(number_format((int)$document['file_size']/1024, 0, ',', '.'))?> KB</small>
        </a>
      <?php endforeach ?>
      <?php if (!$documents): ?><p class="documents-empty">Henüz evrak yüklenmemiş.</p><?php endif ?>
    </div>
  </section>
</main>
<style>
.account-documents-page{max-width:1100px!important;margin:auto;padding:96px 32px 48px!important}.account-documents-card{padding:24px;border:1px solid var(--line);border-radius:10px;background:var(--card);box-shadow:0 3px 12px #1e283c0f}.account-documents-card>header{display:flex;align-items:center;justify-content:space-between;padding-bottom:20px;border-bottom:1px solid var(--line)}.account-documents-card h1{display:flex;align-items:center;gap:9px;margin:0 0 6px;font-size:24px}.account-documents-card h1 .ti{color:#19a94b}.account-documents-card p{margin:0;color:var(--muted)}.documents-back,.documents-upload-form button{display:inline-flex;align-items:center;justify-content:center;width:43px;height:43px;padding:0;border:0;border-radius:7px;color:#fff;text-decoration:none}.documents-back{background:#e04f55}.documents-upload-form{display:flex;align-items:end;gap:12px;flex-wrap:wrap;padding:24px 0}.documents-upload-form label{display:grid;gap:7px;min-width:min(100%,520px)}.documents-upload-form input{height:43px;padding:8px 10px;border:1px solid #d2d2dc;border-radius:7px;background:transparent;color:inherit}.documents-upload-form small{width:100%;color:var(--muted)}.documents-upload-form button{background:#19a94b;cursor:pointer}.documents-upload-form button:disabled{cursor:not-allowed;opacity:.5}.documents-notice{margin-top:18px;padding:13px 16px;border-radius:7px}.documents-notice.success{background:#daf5e3;color:#0d7130}.documents-notice.error{background:#ffe3e3;color:#a21d1d}.documents-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.document-item{display:grid;gap:9px;padding:14px;border:1px solid var(--line);border-radius:9px;color:inherit;text-decoration:none}.document-item:hover{border-color:#7367f0}.document-preview{display:grid;place-items:center;height:150px;overflow:hidden;border-radius:7px;background:#f5f4f7}.document-preview img{width:100%;height:100%;object-fit:cover}.document-preview .ti{font-size:52px;color:#7367f0}.document-item strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.document-item small{color:var(--muted)}.documents-empty{grid-column:1/-1;padding:30px;text-align:center;color:var(--muted)}[data-theme=dark] .account-documents-card{background:#2f3349;border-color:#454a63}[data-theme=dark] .document-preview{background:#25283b}@media(max-width:700px){.account-documents-page{padding:92px 14px 30px!important}.documents-grid{grid-template-columns:1fr}}
</style>
<?php patient_footer(); ?>
