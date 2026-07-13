<?php
declare(strict_types=1);

function patient_header(string $title, string $active = 'patients'): void
{
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    $rawName = (string)($_SESSION['user']['name'] ?? 'Kullanıcı');
    $name = e($rawName);
    $role = e((string)($_SESSION['user']['role'] ?? 'User'));
    $initial = e(mb_strtoupper(mb_substr($rawName, 0, 1)));
    $avatar = '';
    if ($userId > 0) {
        try {
            $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $stmt->execute(['profile_' . $userId]);
            $profile = json_decode((string)$stmt->fetchColumn(), true) ?: [];
            $candidate = ltrim((string)($profile['avatar'] ?? ''), '/');
            if ($candidate !== '' && is_file(__DIR__ . '/' . $candidate)) $avatar = $candidate;
        } catch (Throwable $e) { $avatar = ''; }
    }
    ?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?> | <?=APP_NAME?></title><link rel="icon" type="image/png" href="<?=url('assets/favicon.png?v=20260713')?>">
<link rel="stylesheet" href="<?=url('assets/amerce/fonts/fonts.css')?>"><link rel="stylesheet" href="<?=url('assets/patients.css?v=20260713-13')?>"><script src="<?=url('assets/theme.js?v=20260713-2')?>" defer></script>
</head><body><header class="patient-header"><div class="patient-topbar"><a class="patient-brand" href="<?=url('index.php')?>"><img src="<?=url('assets/vox-logo-02.png?v=20260713-9')?>" alt="VOX"><b>VOX</b></a><div class="header-tools"><button class="plain-tool" type="button" title="Arama">⌕</button><span class="language">TR</span><button id="theme-toggle" class="plain-tool" type="button" title="Görünümü değiştir">☼</button><div class="account"><button id="account-toggle" class="account-button" type="button"><span class="avatar"><?php if($avatar):?><img src="<?=url($avatar)?>" alt="<?=e($rawName)?> profil fotoğrafı"><?php else:?><?=$initial?><?php endif?></span><span class="account-name"><?=$name?><small><?=$role?></small></span><span>⌄</span></button><div id="account-menu" class="account-menu"><a href="<?=url('profile.php')?>">Profilim</a><?php if(is_admin()):?><a href="<?=url('admin.php')?>">Ayarlar</a><?php endif?><a class="logout" href="<?=url('logout.php')?>">Çıkış yap</a></div></div></div></div><nav class="patient-nav"><a class="<?=$active==='home'?'active':''?>" href="<?=url('index.php')?>"><span>⌂</span> Ana Sayfa</a><a class="<?=$active==='patients'?'active':''?>" href="<?=url('patients.php')?>"><span>▣</span> Hasta Kayıtları</a><a class="<?=$active==='new'?'active':''?>" href="<?=url('patient-form.php')?>"><span>＋</span> Yeni Hasta</a><a href="#"><span>◎</span> Randevular</a><a href="#"><span>⇄</span> Takipler</a><a href="#"><span>▥</span> Satışlar</a><a href="#"><span>▤</span> Raporlar</a><?php if(is_admin()):?><a href="<?=url('admin.php')?>"><span>⚙</span> Ayarlar</a><?php endif?></nav></header>
<?php
}

function patient_footer(): void
{
    ?>
<script>
const root=document.documentElement,theme=document.getElementById('theme-toggle');
function setTheme(value){root.dataset.theme=value;localStorage.setItem('vox-theme',value);if(theme)theme.textContent=value==='dark'?'☾':'☼'}
setTheme(localStorage.getItem('vox-theme')||'light');if(theme)theme.addEventListener('click',()=>setTheme(root.dataset.theme==='dark'?'light':'dark'));
const accountButton=document.getElementById('account-toggle'),accountMenu=document.getElementById('account-menu');
if(accountButton){accountButton.addEventListener('click',e=>{e.stopPropagation();accountMenu.classList.toggle('open')});document.addEventListener('click',()=>accountMenu.classList.remove('open'));}
</script></body></html>
<?php
}
