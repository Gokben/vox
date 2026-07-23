<?php
declare(strict_types=1);

const APP_NAME = 'Vox';
$configuredBasePath = getenv('APP_BASE_PATH');
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocalHost = str_starts_with($host, '127.0.0.1') || str_starts_with($host, 'localhost');
$isLocalEnvironment = getenv('APP_ENV') === 'local' || $isLocalHost;
define('BASE_PATH', $configuredBasePath !== false ? $configuredBasePath : ($isLocalHost ? '' : '/vox'));

$privateConfig = __DIR__ . '/config.local.php';
if (is_file($privateConfig)) require $privateConfig;
defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: 'vox');
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: 'vox_user');
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') ?: '');

ini_set('session.use_strict_mode', '1');
if ($isLocalEnvironment) {
    if (!is_dir(__DIR__ . '/storage')) mkdir(__DIR__ . '/storage', 0775, true);
    session_save_path(__DIR__ . '/storage');
}
session_name('krp_vox');
session_start();

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        global $isLocalEnvironment;
        if ($isLocalEnvironment) {
            $pdo = new PDO('sqlite:' . __DIR__ . '/storage/vox.sqlite');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        }
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function url(string $path = ''): string { return BASE_PATH . ($path ? '/' . ltrim($path, '/') : ''); }
function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function format_date_tr(?string $value, bool $withTime = false): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    try { $date = new DateTime($value); return $date->format($withTime ? 'd.m.Y H:i' : 'd.m.Y'); }
    catch (Throwable $e) { return $value; }
}
function redirect(string $path): never { header('Location: ' . url($path)); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Oturum doğrulanamadı.'); }
}
function require_login(): void { if (empty($_SESSION['user'])) redirect('login.php'); }
function is_admin(): bool { return ($_SESSION['user']['role'] ?? '') === 'Admin'; }
function ensure_branch_schema(): void {
 static $done=false;if($done)return;$done=true;$pdo=db();$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
 if($driver==='sqlite'){
  $pdo->exec("CREATE TABLE IF NOT EXISTS branches(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,code TEXT,phone TEXT,address TEXT,active INTEGER NOT NULL DEFAULT 1,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
  $userCols=array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(),'name');$patientCols=array_column($pdo->query('PRAGMA table_info(patients)')->fetchAll(),'name');
  if(!in_array('branch_id',$userCols,true))$pdo->exec('ALTER TABLE users ADD COLUMN branch_id INTEGER NULL');if(!in_array('branch_id',$patientCols,true))$pdo->exec('ALTER TABLE patients ADD COLUMN branch_id INTEGER NULL');
 }else{
  $pdo->exec("CREATE TABLE IF NOT EXISTS branches(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(150) NOT NULL UNIQUE,code VARCHAR(50),phone VARCHAR(50),address TEXT,active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  if(!$pdo->query("SHOW COLUMNS FROM users LIKE 'branch_id'")->fetch())$pdo->exec('ALTER TABLE users ADD COLUMN branch_id INT UNSIGNED NULL');if(!$pdo->query("SHOW COLUMNS FROM patients LIKE 'branch_id'")->fetch())$pdo->exec('ALTER TABLE patients ADD COLUMN branch_id INT UNSIGNED NULL');
 }
 if((int)$pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn()===0)$pdo->exec("INSERT INTO branches(name,code,active) VALUES('Merkez Şube','MRK',1)");$default=(int)$pdo->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();$pdo->exec('UPDATE users SET branch_id='.$default.' WHERE branch_id IS NULL');$pdo->exec('UPDATE patients SET branch_id='.$default.' WHERE branch_id IS NULL');
}
function require_admin(): void { require_login(); if (!is_admin()) { http_response_code(403); exit('Bu sayfaya erişim yetkiniz yok.'); } }
