<?php
declare(strict_types=1);

const APP_NAME = 'Vox';
$configuredBasePath = getenv('APP_BASE_PATH');
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocalHost = str_starts_with($host, '127.0.0.1') || str_starts_with($host, 'localhost');
define('BASE_PATH', $configuredBasePath !== false ? $configuredBasePath : ($isLocalHost ? '' : '/vox'));

const DB_HOST = 'localhost';
const DB_NAME = 'vox';
const DB_USER = 'vox_user';
const DB_PASS = 'BURAYA_GUCLU_SIFRE_YAZIN';

ini_set('session.use_strict_mode', '1');
if (getenv('APP_ENV') === 'local') {
    if (!is_dir(__DIR__ . '/storage')) mkdir(__DIR__ . '/storage', 0775, true);
    session_save_path(__DIR__ . '/storage');
}
session_name('krp_vox');
session_start();

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        if (getenv('APP_ENV') === 'local') {
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
function redirect(string $path): never { header('Location: ' . url($path)); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Oturum doğrulanamadı.'); }
}
function require_login(): void { if (empty($_SESSION['user'])) redirect('login.php'); }
function is_admin(): bool { return ($_SESSION['user']['role'] ?? '') === 'Admin'; }
function require_admin(): void { require_login(); if (!is_admin()) { http_response_code(403); exit('Bu sayfaya erişim yetkiniz yok.'); } }
