<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

function ensure_kanban_schema(): void
{
    static $ready = false;
    if ($ready) return;
    $ready = true;
    $pdo = db();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS kanban_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(190) NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'todo',
            priority VARCHAR(20) NOT NULL DEFAULT 'medium',
            color VARCHAR(20) NULL,
            due_date DATE NULL,
            created_by INTEGER NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS kanban_tasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'todo',
            priority VARCHAR(20) NOT NULL DEFAULT 'medium',
            color VARCHAR(20) NULL,
            due_date DATE NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

ensure_kanban_schema();
$pdo = db();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$columnsInTable = $driver === 'sqlite'
    ? array_column($pdo->query('PRAGMA table_info(kanban_tasks)')->fetchAll(), 'name')
    : $pdo->query("SHOW COLUMNS FROM kanban_tasks LIKE 'color'")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('color', $columnsInTable, true)) $pdo->exec('ALTER TABLE kanban_tasks ADD COLUMN color VARCHAR(20) NULL');
if ($driver === 'sqlite') {
    $hasActiveColumn = in_array('is_active', $columnsInTable, true);
} else {
    $hasActiveColumn = (bool)$pdo->query("SHOW COLUMNS FROM kanban_tasks LIKE 'is_active'")->fetch();
}
if (!$hasActiveColumn) {
    $pdo->exec($driver === 'sqlite'
        ? 'ALTER TABLE kanban_tasks ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1'
        : 'ALTER TABLE kanban_tasks ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
}
$columns = ['todo' => 'Yapılacak', 'progress' => 'Devam Ediyor', 'review' => 'Kontrol', 'done' => 'Tamamlandı'];
$priorities = ['low' => 'Düşük', 'medium' => 'Orta', 'high' => 'Yüksek'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add') {
        $title = trim((string)($_POST['title'] ?? ''));
        $status = (string)($_POST['status'] ?? 'todo');
        $priority = (string)($_POST['priority'] ?? 'medium');
        if ($title !== '' && isset($columns[$status]) && isset($priorities[$priority])) {
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? '')) ? (string)$_POST['color'] : null;
            $stmt = db()->prepare('INSERT INTO kanban_tasks(title,description,status,priority,color,due_date,created_by) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$title, trim((string)($_POST['description'] ?? '')), $status, $priority, $color, $_POST['due_date'] ?: null, (int)($_SESSION['user']['id'] ?? 0)]);
        }
    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $status = (string)($_POST['status'] ?? 'todo');
        $priority = (string)($_POST['priority'] ?? 'medium');
        if ($id > 0 && $title !== '' && isset($columns[$status]) && isset($priorities[$priority])) {
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? '')) ? (string)$_POST['color'] : null;
            $stmt = db()->prepare('UPDATE kanban_tasks SET title=?,description=?,status=?,priority=?,color=?,due_date=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $stmt->execute([$title, trim((string)($_POST['description'] ?? '')), $status, $priority, $color, $_POST['due_date'] ?: null, $id]);
        }
    } elseif ($action === 'move') {
        $status = (string)($_POST['status'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && isset($columns[$status])) {
            db()->prepare('UPDATE kanban_tasks SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status, $id]);
        }
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) { http_response_code(204); exit; }
    } elseif ($action === 'archive') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare("UPDATE kanban_tasks SET status='archive', is_active=0, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
        }
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) { http_response_code(204); exit; }
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM kanban_tasks WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
    }
    redirect('kanban.php');
}

$tasksByColumn = array_fill_keys(array_keys($columns), []);
foreach (db()->query('SELECT * FROM kanban_tasks WHERE is_active=1 ORDER BY CASE priority WHEN "high" THEN 1 WHEN "medium" THEN 2 ELSE 3 END, due_date IS NULL, due_date, id DESC')->fetchAll() as $task) {
    if (isset($tasksByColumn[$task['status']])) $tasksByColumn[$task['status']][] = $task;
}

patient_header('Kanban', 'kanban');
?>
<link rel="stylesheet" href="<?=url('assets/kanban.css?v=20260719-1')?>">
<link rel="stylesheet" href="<?=url('assets/kanban-colors.css?v=20260719-1')?>">
<link rel="stylesheet" href="<?=url('assets/kanban-vuexy.css?v=20260719-1')?>">
<main class="kanban-page">
  <div class="kanban-page-head">
    <div><h1>Kanban</h1><p>Görevlerinizi sürükleyerek süreçte ilerletin.</p></div>
    <button class="button kanban-new-button" type="button" id="kanban-open-new">+ Yeni görev</button>
  </div>
  <section class="kanban-board" aria-label="Görev panosu">
    <?php foreach ($columns as $key => $label): $tasks = $tasksByColumn[$key]; ?>
      <div class="kanban-column" data-status="<?=e($key)?>">
        <header><span class="kanban-dot <?=e($key)?>"></span><h2><?=e($label)?></h2><span class="kanban-count"><?=count($tasks)?></span></header>
        <div class="kanban-task-list" data-status="<?=e($key)?>">
          <?php foreach ($tasks as $task): ?>
            <article class="kanban-task priority-<?=e($task['priority'])?>" draggable="true" data-id="<?=(int)$task['id']?>" data-task="<?=e(json_encode(['id'=>(int)$task['id'],'title'=>(string)$task['title'],'description'=>(string)$task['description'],'status'=>(string)$task['status'],'priority'=>(string)$task['priority'],'due_date'=>(string)($task['due_date'] ?? '')], JSON_UNESCAPED_UNICODE))?>">
              <div class="kanban-task-top"><span class="priority-label <?=e($task['priority'])?>"><?=e($priorities[$task['priority']] ?? 'Orta')?></span><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$task['id']?>"><button class="kanban-delete" title="Görevi sil" onclick="return confirm('Bu görev silinsin mi?')">×</button></form></div>
              <h3><?=e($task['title'])?></h3>
              <?php if (trim((string)$task['description']) !== ''): ?><p><?=nl2br(e($task['description']))?></p><?php endif; ?>
              <footer><?php if (!empty($task['due_date'])): ?><span>◷ <?=e(format_date_tr($task['due_date']))?></span><?php endif; ?><span>#<?=(int)$task['id']?></span></footer>
            </article>
          <?php endforeach; ?>
        </div>
        <button class="kanban-add-inline" type="button" data-status="<?=e($key)?>">+ Görev ekle</button>
      </div>
    <?php endforeach; ?>
    <div class="kanban-column kanban-archive-column" data-status="archive">
      <header><span class="kanban-dot archive"></span><h2>Arşiv</h2><span class="kanban-count">0</span></header>
      <div class="kanban-task-list" data-status="archive" aria-label="Görev arşivi"></div>
    </div>
  </section>
</main>
<dialog class="kanban-dialog" id="kanban-dialog"><form method="post" class="kanban-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="add"><header><h2>Yeni görev</h2><button type="button" id="kanban-close" aria-label="Kapat">×</button></header><label>Görev başlığı<input name="title" required maxlength="190" autofocus></label><label>Açıklama<textarea name="description" rows="4"></textarea></label><div class="kanban-form-grid"><label>Liste<select name="status" id="kanban-status"><?php foreach($columns as $key=>$label):?><option value="<?=e($key)?>"><?=e($label)?></option><?php endforeach?></select></label><label>Öncelik<select name="priority"><?php foreach($priorities as $key=>$label):?><option value="<?=e($key)?>" <?=$key==='medium'?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label></div><label>Teslim tarihi<input type="date" name="due_date"></label><footer><button class="button" type="submit">Görevi ekle</button><button class="button secondary" type="button" id="kanban-cancel">Vazgeç</button></footer></form></dialog>
<script>
(() => {
  const dialog=document.getElementById('kanban-dialog'), status=document.getElementById('kanban-status'), open=document.getElementById('kanban-open-new');
  const form=dialog.querySelector('form'), action=form.querySelector('[name="action"]'), title= form.querySelector('[name="title"]'), description=form.querySelector('[name="description"]'), priority=form.querySelector('[name="priority"]'), dueDate=form.querySelector('[name="due_date"]');
  const idInput=document.createElement('input');idInput.type='hidden';idInput.name='id';form.appendChild(idInput);
  const colorLabel=document.createElement('label');colorLabel.className='kanban-color-control';colorLabel.textContent='Kart rengi';const color=document.createElement('input');color.type='color';color.name='color';color.value='#20a447';colorLabel.appendChild(color);form.querySelector('.kanban-form-grid').after(colorLabel);
  const show=(value, task)=>{form.reset();status.value=value||'todo';action.value=task?'edit':'add';idInput.value=task?.id||'';color.value=task?.color||'#20a447';dialog.querySelector('h2').textContent=task?'Görevi düzenle':'Yeni görev';form.querySelector('[type="submit"]').textContent=task?'Kaydet':'Görevi ekle';if(task){title.value=task.title||'';description.value=task.description||'';status.value=task.status||'todo';priority.value=task.priority||'medium';dueDate.value=task.due_date||''}dialog.showModal()};
  open.addEventListener('click',()=>show()); document.querySelectorAll('.kanban-add-inline').forEach(btn=>btn.addEventListener('click',()=>show(btn.dataset.status)));
  ['kanban-close','kanban-cancel'].forEach(id=>document.getElementById(id).addEventListener('click',()=>dialog.close()));
  const csrf=<?=json_encode(csrf())?>,taskColors=<?=json_encode(db()->query('SELECT id,color FROM kanban_tasks')->fetchAll(PDO::FETCH_KEY_PAIR))?>;
  document.querySelectorAll('.kanban-task').forEach(card=>{
    card.style.setProperty('--task-color',taskColors[card.dataset.id]||'#20a447');
    card.addEventListener('dragstart',()=>card.classList.add('dragging'));
    card.addEventListener('dragend',()=>card.classList.remove('dragging'));
    card.addEventListener('dblclick',event=>{if(event.target.closest('button,form'))return;const task=JSON.parse(card.dataset.task||'{}');task.color=taskColors[card.dataset.id]||'#20a447';show('',task)});
  });
  document.querySelectorAll('.kanban-task-list').forEach(list=>{
    list.addEventListener('dragover',event=>{event.preventDefault();list.closest('.kanban-column').classList.add('drag-over')});
    list.addEventListener('dragleave',()=>list.closest('.kanban-column').classList.remove('drag-over'));
    list.addEventListener('drop',async event=>{event.preventDefault();const card=document.querySelector('.kanban-task.dragging'),column=list.closest('.kanban-column');column.classList.remove('drag-over');if(!card||card.parentElement===list)return;if(list.dataset.status==='archive'){if(!confirm('Görev Arşive gönderilecek emin misin?'))return;const response=await fetch(location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:new URLSearchParams({csrf,action:'archive',id:card.dataset.id})});if(response.ok){card.remove();document.querySelectorAll('.kanban-column:not(.kanban-archive-column)').forEach(item=>item.querySelector('.kanban-count').textContent=item.querySelectorAll('.kanban-task').length)}return;}list.appendChild(card);await fetch(location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:new URLSearchParams({csrf,action:'move',id:card.dataset.id,status:list.dataset.status})});document.querySelectorAll('.kanban-column:not(.kanban-archive-column)').forEach(item=>item.querySelector('.kanban-count').textContent=item.querySelectorAll('.kanban-task').length)});
  });
})();
</script>
<?php patient_footer();
