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
$taskPdo = db();
$taskDriver = $taskPdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$taskColumns = $taskDriver === 'sqlite'
    ? array_column($taskPdo->query('PRAGMA table_info(kanban_tasks)')->fetchAll(), 'name')
    : $taskPdo->query('SHOW COLUMNS FROM kanban_tasks')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('color', $taskColumns, true)) $taskPdo->exec('ALTER TABLE kanban_tasks ADD COLUMN color VARCHAR(20) NULL');
if (!in_array('is_active', $taskColumns, true)) $taskPdo->exec($taskDriver === 'sqlite' ? 'ALTER TABLE kanban_tasks ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1' : 'ALTER TABLE kanban_tasks ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
$taskPdo->exec('UPDATE kanban_tasks SET is_active=1 WHERE is_active IS NULL');
$columns = ['todo' => 'Yapılacak', 'progress' => 'Devam Ediyor', 'review' => 'Kontrol', 'done' => 'Tamamlandı'];
$priorities = ['low' => 'Düşük', 'medium' => 'Orta', 'high' => 'Yüksek'];
$taskId = (int)($_GET['id'] ?? 0);
$form = ['title' => '', 'description' => '', 'status' => (string)($_GET['status'] ?? 'todo'), 'priority' => 'medium', 'color' => '#20a447', 'due_date' => ''];
if (!isset($columns[$form['status']])) $form['status'] = 'todo';
if ($taskId > 0) {
    $stmt = db()->prepare('SELECT * FROM kanban_tasks WHERE id=? AND COALESCE(is_active,1)=1');
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
        else db()->prepare('INSERT INTO kanban_tasks(title,description,status,priority,color,due_date,created_by,is_active) VALUES(?,?,?,?,?,?,?,1)')->execute([$form['title'], $form['description'], $form['status'], $form['priority'], $color, $form['due_date'] ?: null, (int)($_SESSION['user']['id'] ?? 0)]);
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

<?php patient_footer(); ?>
