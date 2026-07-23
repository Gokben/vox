<?php
declare(strict_types=1);

if (!function_exists('format_date_tr')) {
    function format_date_tr(?string $value, bool $withTime = false): string
    {
        $value = trim((string)$value);
        if ($value === '') return '';
        try { return (new DateTime($value))->format($withTime ? 'd.m.Y H:i' : 'd.m.Y'); }
        catch (Throwable $e) { return $value; }
    }
}

function patient_header(string $title, string $active = 'patients'): void
{
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    $rawName = (string)($_SESSION['user']['name'] ?? 'Kullanıcı');
    $name = e($rawName);
    $role = e((string)($_SESSION['user']['role'] ?? 'User'));
    $initial = e(function_exists('mb_substr') ? mb_strtoupper(mb_substr($rawName, 0, 1)) : strtoupper(substr($rawName, 0, 1)));
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
<link rel="stylesheet" href="<?=url('assets/amerce/fonts/fonts.css')?>"><link rel="stylesheet" href="<?=url('assets/patients.css?v=20260713-13')?>"><link rel="stylesheet" href="<?=url('assets/employees-buttons.css?v=20260716-5')?>"><script src="<?=url('assets/theme.js?v=20260717-1')?>" defer></script>
</head><body><header class="patient-header"><div class="patient-topbar"><a class="patient-brand" href="<?=url('index.php')?>"><img src="<?=url('assets/vox-logo-02.png?v=20260713-9')?>" alt="VOX"><b>VOX</b></a><div class="header-tools"><button class="plain-tool" type="button" title="Arama">⌕</button><span class="language">TR</span><button id="theme-toggle" class="plain-tool" type="button" title="Görünümü değiştir">☼</button><div class="account"><button id="account-toggle" class="account-button" type="button"><span class="avatar"><?php if($avatar):?><img src="<?=url($avatar)?>" alt="<?=e($rawName)?> profil fotoğrafı"><?php else:?><?=$initial?><?php endif?></span><span class="account-name"><?=$name?><small><?=$role?></small></span><span>⌄</span></button><div id="account-menu" class="account-menu"><a href="<?=url('profile.php')?>">Profilim</a><?php if(is_admin()):?><a href="<?=url('admin.php')?>">Ayarlar</a><?php endif?><a class="logout" href="<?=url('logout.php')?>">Çıkış yap</a></div></div></div></div><nav class="patient-nav"><a class="<?=$active==='home'?'active':''?>" href="<?=url('index.php')?>"><span>⌂</span> Ana Sayfa</a><a class="<?=$active==='patients'?'active':''?>" href="<?=url('patients.php')?>"><span>▣</span> Hasta Kayıtları</a><a class="<?=$active==='new'?'active':''?>" href="<?=url('patient-form.php')?>"><span>＋</span> Yeni Hasta</a><a class="<?=$active==='kanban'?'active':''?>" href="<?=url('kanban.php')?>"><span>▦</span> Kanban</a><a href="#"><span>⇄</span> Takipler</a><a href="#"><span>▥</span> Satışlar</a><a href="#"><span>▤</span> Raporlar</a><?php if(is_admin()):?><a href="<?=url('admin.php')?>"><span>⚙</span> Ayarlar</a><?php endif?></nav></header>
<?php
}

function patient_footer(): void
{
    ?>
<script>
const stockMenuLink = document.createElement('a');
stockMenuLink.href = '#';
stockMenuLink.innerHTML = '<span>▤</span> Stoklar';
document.querySelector('.patient-nav a[href*="admin.php"]')?.before(stockMenuLink);
const setupMenuLink = document.createElement('a');
setupMenuLink.href = '#';
setupMenuLink.innerHTML = '<span>⚙</span> Kurulum';
document.querySelector('.patient-nav a[href*="admin.php"]')?.after(setupMenuLink);
const setupGroup = document.createElement('div');
setupGroup.className = 'report-menu-group';
const setupSubmenu = document.createElement('div');
setupSubmenu.className = 'report-submenu';
const brandsMenuLink = document.createElement('a');
brandsMenuLink.href = <?= json_encode(url('brands.php')) ?>;
brandsMenuLink.textContent = 'Markalar';
setupSubmenu.append(brandsMenuLink);
setupMenuLink.setAttribute('aria-haspopup', 'true');
setupMenuLink.setAttribute('aria-expanded', 'false');
setupMenuLink.addEventListener('click', event => {
  event.preventDefault();
  const isOpen = setupGroup.classList.toggle('open');
  setupMenuLink.setAttribute('aria-expanded', String(isOpen));
});
document.addEventListener('click', event => {
  if (!setupGroup.contains(event.target)) {
    setupGroup.classList.remove('open');
    setupMenuLink.setAttribute('aria-expanded', 'false');
  }
});
setupMenuLink.before(setupGroup);
setupGroup.append(setupMenuLink, setupSubmenu);
const reportMenuLink = [...document.querySelectorAll('.patient-nav > a')].find(link => link.textContent.includes('Raporlar'));
const followUpMenuLink = [...document.querySelectorAll('.patient-nav > a')].find(link => link.textContent.includes('Takipler'));
const salesMenuLink = [...document.querySelectorAll('.patient-nav > a')].find(link => link.textContent.includes('Satışlar'));
if (reportMenuLink && followUpMenuLink && salesMenuLink) {
  const reportGroup = document.createElement('div');
  reportGroup.className = 'report-menu-group';
  const reportSubmenu = document.createElement('div');
  reportSubmenu.className = 'report-submenu';
  const resultMenuLink = document.createElement('a');
  resultMenuLink.href = <?= json_encode(url('patient-results.php')) ?>;
  resultMenuLink.textContent = 'Hasta Görüşmeleri Sonuç Raporu';
  if (location.pathname.endsWith('/patient-results.php')) {
    resultMenuLink.classList.add('active');
    reportMenuLink.classList.add('active');
  }
  reportSubmenu.append(followUpMenuLink, salesMenuLink, resultMenuLink);
  reportMenuLink.setAttribute('aria-haspopup', 'true');
  reportMenuLink.setAttribute('aria-expanded', 'false');
  reportMenuLink.addEventListener('click', event => {
    event.preventDefault();
    const isOpen = reportGroup.classList.toggle('open');
    reportMenuLink.setAttribute('aria-expanded', String(isOpen));
  });
  document.addEventListener('click', event => {
    if (!reportGroup.contains(event.target)) {
      reportGroup.classList.remove('open');
      reportMenuLink.setAttribute('aria-expanded', 'false');
    }
  });
  reportMenuLink.before(reportGroup);
  reportGroup.append(reportMenuLink, reportSubmenu);
  const reportMenuStyle = document.createElement('style');
  reportMenuStyle.textContent = '.patient-nav{overflow:visible!important}.report-menu-group{position:relative;flex:0 0 auto}.report-submenu{display:none;position:absolute;z-index:20;top:calc(100% + 3px);left:0;min-width:170px;padding:6px;border:1px solid #e1e2e8;border-radius:8px;background:#fff;box-shadow:0 8px 18px rgba(47,43,61,.16)}.report-menu-group.open .report-submenu{display:grid;gap:2px}.report-submenu a{font-size:14px!important;padding:9px 10px!important}[data-theme=dark] .report-submenu{background:#30334d;border-color:#454a63}';
  document.head.append(reportMenuStyle);
}
const calendarMenuLink = [...document.querySelectorAll('.patient-nav a')].find(link => link.getAttribute('href')?.includes('patient-form.php'));
if (calendarMenuLink) {
  calendarMenuLink.href = <?= json_encode(url('calendar.php')) ?>;
  if (calendarMenuLink.lastChild?.nodeType === Node.TEXT_NODE) calendarMenuLink.lastChild.textContent = ' Takvim';
  if (location.pathname.endsWith('/calendar.php')) calendarMenuLink.classList.add('active');
}
</script>
<script>
const root=document.documentElement,theme=document.getElementById('theme-toggle');
function setTheme(value){root.dataset.theme=value;localStorage.setItem('vox-theme',value);if(theme)theme.textContent=value==='dark'?'☾':'☼'}
setTheme(localStorage.getItem('vox-theme')||'light');if(theme)theme.addEventListener('click',()=>setTheme(root.dataset.theme==='dark'?'light':'dark'));
const accountButton=document.getElementById('account-toggle'),accountMenu=document.getElementById('account-menu');
if(accountButton){accountButton.addEventListener('click',e=>{e.stopPropagation();accountMenu.classList.toggle('open')});document.addEventListener('click',()=>accountMenu.classList.remove('open'));}
const settingsPages={
  'admin.php':['<?=url('admin.php')?>','Kullanıcı Yönetimi'],
  'branches.php':['<?=url('branches.php')?>','Şubeler'],
  'employees.php':['<?=url('employees.php')?>','Çalışanlar'],
  'social-securities.php':['<?=url('social-securities.php')?>','Sosyal Güvence'],
  'service-types.php':['<?=url('service-types.php')?>','Hizmet Yerleri'],
  'sources.php':['<?=url('sources.php')?>','Başvuru Kaynağı']
};
const currentSettingsPage=location.pathname.split('/').pop()||'index.php';
if(settingsPages[currentSettingsPage]){
  let settingsTabs=document.querySelector('.settings-tabs');
  if(!settingsTabs){
    const settingsContainer=document.querySelector('.social-settings');
    if(settingsContainer){settingsTabs=document.createElement('nav');settingsTabs.className='settings-tabs';settingsContainer.prepend(settingsTabs);}
  }
  if(settingsTabs){
    settingsTabs.replaceChildren(...Object.entries(settingsPages).map(([page,data])=>{
      const link=document.createElement('a');link.href=data[0];link.textContent=data[1];
      if(page===currentSettingsPage)link.classList.add('active');return link;
    }));
  }
  if(currentSettingsPage==='employees.php'){
    const employeeHeader=document.querySelector('.employee-card > header');
    if(employeeHeader&&!employeeHeader.querySelector('p')){
      const description=document.createElement('p');
      description.textContent='Çalışan bilgilerini ve görev durumlarını yönetin.';
      employeeHeader.appendChild(description);
    }
  }
}
</script></body></html>
<?php
}
