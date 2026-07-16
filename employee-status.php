<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require_admin();

function ensure_employee_active_schema(): void
{
    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $columns = array_column($pdo->query('PRAGMA table_info(employees)')->fetchAll(), 'name');
        if (!in_array('active', $columns, true)) $pdo->exec('ALTER TABLE employees ADD COLUMN active INTEGER NOT NULL DEFAULT 1');
    } elseif (!$pdo->query("SHOW COLUMNS FROM employees LIKE 'active'")->fetch()) {
        $pdo->exec('ALTER TABLE employees ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1');
    }
}

ensure_employee_active_schema();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'toggle' && $id > 0) {
        $pdo->prepare('UPDATE employees SET active = CASE WHEN active=1 THEN 0 ELSE 1 END WHERE id=?')->execute([$id]);
    }
    header('Location: ' . url('employees.php'));
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($pdo->query('SELECT id,full_name,active FROM employees ORDER BY id')->fetchAll(), JSON_UNESCAPED_UNICODE);
