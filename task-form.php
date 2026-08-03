<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();

function ensure_task_form_schema(): void
{
    $pdo = db();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS kanban_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT,title VARCHAR(190) NOT NULL,description TEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'todo',priority VARCHAR(20) NOT NULL DEFAULT 'medium',color VARCHAR(20) NULL,due_date DATE NULL,created_by INTEGER NULL,is_active INTEGER NOT NULL DEFAULT 1,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS kanban_tasks (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(190) NOT NULL,description TEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'todo',priority VARCHAR(20) NOT NULL DEFAULT 'medium',color VARCHAR(20) NULL,due_date DATE NULL,created_by INT UNSIGNED NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

ensure_task_form_schema();
$columns = ['todo' => 'Yapılacak', 'progress' => 'Devam Ediyor', 'review' => 'Kontrol', 'done' => 'Tamamlandı'];
$priorities = ['low' => 'Düşük', 'medium' => 'Orta', 'high' => 'Yüksek'];
$taskId = (int)($_GET['id'] ?? 0);
$form = ['title' => '', 'description' => '', 'status' => (string)($_GET['status'] ?? 'todo'), 'priority' => 'medium', 'color' => '#20a447', 'due_date' => ''];
if (!isset($columns[$form['status']])) $form['status'] = 'todo';
if ($taskId > 0) {
    $stmt = db()->prepare('SELECT * FROM kanban_tasks WHERE id=? AND is_active=1');
    $stmt->execute([$taskId]);
    if ($task = $stmt->fetch()) foreach (array_keys($form) as $field) $form[$field] = (string)($task[$field] ?? $form[$field]);
    else $taskId = 0;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($form as $field => $value) $form[$field] = trim((string)($_POST[$field] ?? $value));
    if ($form['title'] === '') $error = 'Görev başlığı zorunludur.';
    elseif (!isset($columns[$form['status']]) || !isset($priorities[$form['priority']])) $error = 'Liste veya öncelik bilgisi geçersiz.';
    else {
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $form['color']) ? $form['color'] : '#20a447';
        if ($taskId > 0) db()->prepare('UPDATE kanban_tasks SET title=?,description=?,status=?,priority=?,color=?,due_date=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$form['title'], $form['description'], $form['status'], $form['priority'], $color, $form['due_date'] ?: null, $taskId]);
        else db()->prepare('INSERT INTO kanban_tasks(title,description,status,priority,color,due_date,created_by) VALUES(?,?,?,?,?,?,?)')->execute([$form['title'], $form['description'], $form['status'], $form['priority'], $color, $form['due_date'] ?: null, (int)($_SESSION['user']['id'] ?? 0)]);
        redirect('kanban.php');
    }
}

require __DIR__ . '/patient-layout.php';
patient_header($taskId > 0 ? 'Görevi Düzenle' : 'Yeni Görev', 'kanban');
?>
<main class="task-form-page patient-container"><section class="task-form-card">
  <header><h1><?= $taskId > 0 ? 'Görevi Düzenle' : 'Yeni Görev' ?></h1><a href="<?=e(url('kanban.php'))?>">Görev Takibe Dön</a></header>
  <?php if ($error): ?><div class="task-form-error"><?=e($error)?></div><?php endif; ?>
  <form method="post" class="task-form"><input type="hidden" name="csrf" value="<?=csrf()?>">
    <label>Görev Başlığı<input name="title" value="<?=e($form['title'])?>" required maxlength="190" autofocus></label>
    <label class="task-description">Açıklama<textarea name="description" rows="4"><?=e($form['description'])?></textarea></label>
    <label>Liste<select name="status"><?php foreach ($columns as $key => $label): ?><option value="<?=e($key)?>" <?=$form['status']===$key?'selected':''?>><?=e($label)?></option><?php endforeach; ?></select></label>
    <label>Öncelik<select name="priority"><?php foreach ($priorities as $key => $label): ?><option value="<?=e($key)?>" <?=$form['priority']===$key?'selected':''?>><?=e($label)?></option><?php endforeach; ?></select></label>
    <label>Kart Rengi<input type="color" name="color" value="<?=e($form['color'])?>"></label>
    <label>Teslim Tarihi<input type="date" name="due_date" value="<?=e($form['due_date'])?>"></label>
    <footer><a href="<?=e(url('kanban.php'))?>">İptal</a><button type="submit"><?= $taskId > 0 ? 'Kaydet' : 'Görevi Ekle' ?></button></footer>
  </form>
</section></main>
<style>
.task-form-page{max-width:920px;margin:0 auto;padding:96px 32px 48px}.task-form-card{padding:20px;border:1px solid var(--line);background:var(--card)}.task-form-card header{display:flex;align-items:center;justify-content:space-between;margin:0 0 12px;padding:0 0 10px;border-bottom:1px solid var(--line)}.task-form-card h1{margin:0;color:#19a94b;font-size:14px;font-weight:700}.task-form-card header a{color:#20a447;text-decoration:none;font-size:13px;font-weight:700}.task-form{display:block}.task-form label{display:grid;grid-template-columns:135px minmax(0,1fr);align-items:center;margin:0;padding:6px 0;color:#2f2b3d;font-size:14px}.task-form input,.task-form select,.task-form textarea{box-sizing:border-box;width:100%;min-height:38px;padding:8px 12px;border:1px solid #d8d8e2;border-radius:6px;background:transparent;color:var(--text);font:inherit}.task-form input[type=color]{padding:3px;width:58px;min-height:36px}.task-form textarea{min-height:90px;resize:vertical}.task-description{align-items:start!important}.task-form footer{display:flex;justify-content:flex-end;gap:10px;margin:18px 0 0 135px;padding-top:14px;border-top:1px solid var(--line)}.task-form footer a,.task-form button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 18px;border:0;border-radius:7px;text-decoration:none;font:inherit;font-weight:700;cursor:pointer}.task-form footer a{background:#eef0f5;color:#2f2b3d}.task-form button{background:#20a447;color:#fff}.task-form-error{margin:0 0 12px;padding:12px;border-radius:7px;background:#ffe7e7;color:#a31d1d}@media(max-width:640px){.task-form-page{padding:92px 14px 30px}.task-form label{grid-template-columns:1fr;gap:6px}.task-form footer{margin-left:0}}
</style>
<?php patient_footer(); ?>
