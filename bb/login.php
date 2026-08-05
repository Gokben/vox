<?php
declare(strict_types=1);
require __DIR__ . '/../config.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $statement = db()->prepare('SELECT id,name,email,password_hash,role,active FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1');
    $statement->execute([trim((string)($_POST['email'] ?? ''))]);
    $user = $statement->fetch();
    if ($user && (int)$user['active'] === 1 && password_verify((string)($_POST['password'] ?? ''), (string)$user['password_hash'])) {
        if (normalize_role((string)$user['role']) !== ROLE_COMPANY_MANAGER) {
            $error = 'Bu alana yalnız Firma Yöneticisi erişebilir.';
        } else {
            session_regenerate_id(true);
            $_SESSION['bb_user_id'] = (int)$user['id'];
            redirect('bb/index.php');
        }
    } else {
        $error = 'E-posta veya şifre hatalı.';
    }
}
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Yönetim Paneli Girişi | <?=e(APP_NAME)?></title><link rel="icon" href="<?=e(url('assets/favicon.png'))?>"><link rel="stylesheet" href="<?=e(url('bb/style.css'))?>"></head>
<body class="bb-login-page"><main class="bb-login-card"><img src="<?=e(url('assets/vox-logo-02.png'))?>" alt="VOX" class="bb-logo"><p class="bb-eyebrow">YÖNETİM PANELİ</p><h1>Firma Yöneticisi Girişi</h1><p class="bb-muted">Bu alan yalnızca Firma Yöneticisi rolündeki kullanıcılar içindir.</p><?php if($error):?><p class="bb-alert"><?=e($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><label>E-posta<input type="email" name="email" autocomplete="username" required></label><label>Şifre<input type="password" name="password" autocomplete="current-password" required></label><button type="submit">Panele giriş yap</button></form><a class="bb-back" href="<?=e(url('login.php'))?>">Ana uygulama girişine dön</a></main></body></html>
