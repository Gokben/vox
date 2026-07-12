<?php
require __DIR__ . '/config.php';
if (!empty($_SESSION['user'])) redirect('index.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $stmt = db()->prepare('SELECT id, name, email, password_hash, role, active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([trim(strtolower($_POST['email'] ?? ''))]);
    $user = $stmt->fetch();
    if ($user && $user['active'] && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        session_regenerate_id(true);
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        redirect('index.php');
    }
    $error = 'E-posta veya şifre hatalı.';
}
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Giriş | <?= APP_NAME ?></title><link rel="icon" type="image/png" href="<?= url('assets/favicon.png') ?>"><link rel="stylesheet" href="<?= url('assets/style.css') ?>"><link rel="stylesheet" href="<?= url('assets/login.css') ?>"><link rel="stylesheet" href="<?=url('assets/amerce/fonts/fonts.css')?>"><link rel="stylesheet" href="<?=url('assets/amerce/icon/icomoon/style.css')?>"><link rel="stylesheet" href="<?=url('assets/amerce/css/bootstrap.min.css')?>"><link rel="stylesheet" href="<?=url('assets/amerce/css/styles.css')?>"><link rel="stylesheet" href="<?=url('assets/amerce-lf.css')?>"><link rel="stylesheet" href="<?=url('assets/green-buttons.css?v=20260712-3')?>"><script src="<?=url('assets/theme.js?v=20260712-2')?>"></script></head>
<body class="login-page">
<main class="login-card">
  <div class="sofitel-brand">
    <img src="<?= url('assets/sofitel-logo.png') ?>" alt="Sofitel">
    <span>SOFITEL</span>
    <small>ISTANBUL TAKSIM</small>
  </div>
  <div class="login-heading">
    <h1>Lost &amp; Found</h1>
  </div>
  <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <label>E-posta adresi
      <input type="email" name="email" autocomplete="username" placeholder="ornek@sofitel.com" required>
    </label>
    <label>Şifre
      <span class="password-field">
        <input id="password" type="password" name="password" autocomplete="current-password" placeholder="Şifrenizi girin" required>
        <button class="password-toggle" type="button" aria-label="Şifreyi göster" onclick="togglePassword(this)"><span class="eye-icon" aria-hidden="true"></span></button>
      </span>
    </label>
    <label class="remember"><input type="checkbox" name="remember" value="1"> <span>Beni hatırla</span></label>
    <button class="login-submit" type="submit">Giriş yap</button>
  </form>
  <small class="secure-note">Yalnızca yetkili personel erişebilir</small>
</main>
<script>function togglePassword(button){const input=document.getElementById('password');const visible=input.type==='text';input.type=visible?'password':'text';button.classList.toggle('is-visible',!visible);button.setAttribute('aria-label',visible?'Şifreyi göster':'Şifreyi gizle')}</script>
</body></html>
