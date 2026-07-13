<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_admin();
ensure_branch_schema();
require __DIR__ . '/patient-layout.php';
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$name=trim((string)($_POST['name']??''));$email=mb_strtolower(trim((string)($_POST['email']??'')));$password=(string)($_POST['password']??'');$confirm=(string)($_POST['password_confirm']??'');$role=in_array($_POST['role']??'', ['Admin','User'],true)?$_POST['role']:'User';
 if($name==='')$error='Ad soyad alanı zorunludur.';elseif(!filter_var($email,FILTER_VALIDATE_EMAIL))$error='Geçerli bir e-posta adresi girin.';elseif(strlen($password)<6)$error='Şifre en az 6 karakter olmalıdır.';elseif($password!==$confirm)$error='Şifre ve şifre tekrarı aynı olmalıdır.';else{$check=db()->prepare('SELECT id FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1');$check->execute([$email]);if($check->fetch())$error='Bu e-posta adresi zaten kullanılıyor. Aynı e-posta ile ikinci personel oluşturulamaz.';else try{$insert=db()->prepare('INSERT INTO users(name,email,password_hash,role,active) VALUES(?,?,?,?,1)');$insert->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role]);$message='Personel başarıyla eklendi.';}catch(PDOException $e){$error='Personel kaydedilemedi. E-posta adresini kontrol edin.';}}
}
$personnel=db()->query('SELECT id,name,email,role,active,created_at FROM users ORDER BY id DESC')->fetchAll();
patient_header('Ayarlar - Personel Yönetimi','settings');
?>
<main class="patient-container personnel-page">
 <nav class="settings-tabs"><a class="active" href="<?=url('admin.php')?>">Personel Yönetimi</a><a href="<?=url('branches.php')?>">Şubeler</a></nav>
 <?php if($message):?><div class="vox-message success"><?=e($message)?></div><?php endif?><?php if($error):?><div class="vox-message error"><?=e($error)?></div><?php endif?>
 <section class="vuexy-form-card"><header class="form-card-title"><h1>Yeni Personel</h1><p>Uygulamaya erişecek personelin giriş bilgilerini ve rolünü tanımlayın.</p></header>
 <form method="post" class="personnel-form"><input type="hidden" name="csrf" value="<?=csrf()?>">
  <label><span>Ad Soyad</span><input name="name" value="<?=e($_POST['name']??'')?>" required></label><label><span>E-posta</span><input type="email" name="email" value="<?=e($_POST['email']??'')?>" required></label>
  <label><span>Şifre</span><input type="password" name="password" minlength="6" required></label><label><span>Şifre Tekrar</span><input type="password" name="password_confirm" minlength="6" required></label>
  <label><span>Rol</span><select name="role"><option>User</option><option>Admin</option></select></label><div class="personnel-actions"><button>Personel Ekle</button></div>
 </form></section>
 <section class="vuexy-form-card"><header class="form-card-title"><h2>Personel Listesi</h2><p><?=count($personnel)?> kayıt</p></header><div class="table-responsive"><table class="personnel-table"><thead><tr><th>Ad Soyad</th><th>E-posta</th><th>Rol</th><th>Durum</th><th>Kayıt Tarihi</th><th>İşlemler</th></tr></thead><tbody>
 <?php foreach($personnel as $person):?><tr><td><?=e($person['name'])?></td><td><?=e($person['email'])?></td><td><span class="role-pill"><?=e($person['role'])?></span></td><td><span class="status-pill <?=$person['active']?'active':'passive'?>"><?=$person['active']?'Aktif':'Pasif'?></span></td><td><?=format_date_tr($person['created_at'],true)?></td><td><?php if((int)$person['id']===(int)$_SESSION['user']['id']):?><a class="edit-personnel" href="<?=url('profile.php')?>">Profilim</a><?php elseif($person['role']==='User'):?><a class="edit-personnel" href="<?=url('user-edit.php?id='.(int)$person['id'])?>">Düzenle / Şifre</a><?php else:?><span>Yönetici</span><?php endif?></td></tr><?php endforeach?>
 </tbody></table></div></section>
</main>
<style>
.personnel-page{max-width:1180px;margin:0 auto;padding:28px 20px 48px}.settings-tabs{display:flex;margin-bottom:20px}.settings-tabs a{background:#19a94b;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:700}.vuexy-form-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;margin-bottom:24px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.form-card-title{display:block;padding:22px 24px;border-bottom:1px solid #e1e2e8}.form-card-title h1,.form-card-title h2{margin:0 0 5px;font-size:21px}.form-card-title p{margin:0;color:#7b7b8d}.personnel-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 24px;padding:24px}.personnel-form label{display:flex;flex-direction:column;gap:7px}.personnel-form input,.personnel-form select{height:43px;border:1px solid #d2d2dc;border-radius:7px;padding:0 12px;background:transparent;color:inherit}.personnel-actions{grid-column:1/-1}.personnel-actions button,.edit-personnel{display:inline-flex;align-items:center;justify-content:center;background:#19a94b;color:#fff;border:0;border-radius:7px;padding:11px 18px;text-decoration:none;font-weight:700}.table-responsive{overflow:auto}.personnel-table{width:100%;border-collapse:collapse;min-width:850px}.personnel-table th,.personnel-table td{padding:14px 18px;border-bottom:1px solid #e1e2e8;text-align:left;white-space:nowrap}.role-pill,.status-pill{display:inline-block;padding:5px 9px;border-radius:6px;background:#e8f7ed;color:#12883c;font-weight:700;font-size:12px}.status-pill.passive{background:#f3f3f5;color:#777}.vox-message{padding:13px 16px;border-radius:7px;margin-bottom:18px}.vox-message.success{background:#daf5e3;color:#0d7130}.vox-message.error{background:#ffe3e3;color:#a21d1d}[data-theme=dark] .vuexy-form-card{background:#2f3349;color:#fff;border-color:#454a63}[data-theme=dark] .personnel-form input,[data-theme=dark] .personnel-form select{border-color:#5a607b;color:#fff}[data-theme=dark] .form-card-title p{color:#c4c7d6}@media(max-width:720px){.personnel-form{grid-template-columns:1fr}.personnel-page{padding:20px 12px}}
</style>
<?php patient_footer();?>
