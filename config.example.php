<?php
// Bu dosyayı sunucuda config.php olarak kopyalayın ve veritabanı bilgilerini girin.
// Git dağıtımı gerçek config.php dosyasının üzerine yazmaz.
declare(strict_types=1);

const APP_NAME = 'KRP Lost & Found';
define('BASE_PATH', '/lf');
const DB_HOST = 'localhost';
const DB_NAME = 'CPANEL_VERITABANI_ADI';
const DB_USER = 'CPANEL_VERITABANI_KULLANICISI';
const DB_PASS = 'GUCLU_VERITABANI_SIFRESI';

ini_set('session.use_strict_mode', '1');
session_name('krp_lostfound');
session_start();

function db(): PDO { static $pdo; return $pdo ??= new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
function url(string $path=''):string{return BASE_PATH.($path?'/'.ltrim($path,'/'):'');}
function e(?string $value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function redirect(string $path):never{header('Location: '.url($path));exit;}
function csrf():string{return $_SESSION['csrf']??=bin2hex(random_bytes(32));}
function verify_csrf():void{if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(419);exit('Oturum doğrulanamadı.');}}
function require_login():void{if(empty($_SESSION['user']))redirect('login.php');}
function is_admin():bool{return($_SESSION['user']['role']??'')==='Admin';}
function require_admin():void{require_login();if(!is_admin()){http_response_code(403);exit('Bu sayfaya erişim yetkiniz yok.');}}
