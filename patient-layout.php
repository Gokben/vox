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
    if (!headers_sent()) header('Cache-Control: no-store');
    $embeddedWindow = isset($_GET['_vox_window']) && (string)$_GET['_vox_window'] === '1';
    $isPatientList = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? ''))) === 'patients.php';
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId > 0) {
        try {
            $userStatement = db()->prepare('SELECT name,email,role FROM users WHERE id=? AND active=1');
            $userStatement->execute([$userId]);
            $currentUser = $userStatement->fetch();
            if ($currentUser) {
                $_SESSION['user']['name'] = (string)$currentUser['name'];
                $_SESSION['user']['email'] = (string)$currentUser['email'];
                $_SESSION['user']['role'] = (string)$currentUser['role'];
            }
        } catch (Throwable $e) {
            // Oturumdaki mevcut bilgi, veritabanına geçici olarak erişilemezse kullanılmaya devam eder.
        }
    }
    $rawName = (string)($_SESSION['user']['name'] ?? 'Kullanıcı');
    $name = e($rawName);
    $role = e(role_label(current_role()));
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
<meta name="vox-build" content="<?=e((string)(vox_app_release()['build'] ?? ''))?>"><meta name="vox-version" content="<?=e(vox_app_version())?>"><link rel="stylesheet" href="<?=e(url('assets/app-version.css'))?>"><script src="<?=e(url('assets/app-version.js'))?>" data-release-url="<?=e(url('app-release.php'))?>" defer></script>
<title><?=e($title)?> | <?=APP_NAME?></title><?php
$compactFormFile = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
if (in_array($compactFormFile, ['stock-entry','bulk-battery-entry','task-form','external-technical-patient','external-technical-repair','price-lists'], true)): ?>
<link rel="stylesheet" href="<?=e(url('assets/'.$compactFormFile.'-base.css'))?>">
<link rel="stylesheet" href="<?=e(url('assets/remaining-forms-compact.css'))?>">
<?php endif ?><?php if (in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['invoice-entry.php','invoice-entry-v2.php'], true)): ?><link rel="stylesheet" href="<?=e(url('assets/invoice-entry.css'))?>"><?php endif ?><?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'stocks.php'): ?><link rel="stylesheet" href="<?=e(url('assets/stocks.css'))?>"><?php endif ?><?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'stock-card.php'): ?><link rel="stylesheet" href="<?=e(url('assets/stock-card-compact.css'))?>"><?php endif ?><?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'stock-prices.php'): ?><link rel="stylesheet" href="<?=e(url('assets/stock-prices.css'))?>"><?php endif ?><link rel="icon" type="image/png" href="<?=url('assets/favicon.png?v=20260713')?>">
<link rel="stylesheet" href="<?=url('assets/amerce/fonts/fonts.css')?>"><link rel="stylesheet" href="<?=url('assets/patients.css?v=20260823-gold-menu-icons')?>"><link rel="stylesheet" href="<?=url('assets/classic-lists.css?v=20260823-10')?>"><link rel="stylesheet" href="<?=url('assets/multi-window.css?v=20260824-6')?>"><link rel="stylesheet" href="<?=url('assets/classic-forms.css?v=20260823-4')?>"><link rel="stylesheet" href="<?=url('assets/classic-settings.css?v=20260825-unified')?>"><link rel="stylesheet" href="<?=url('assets/classic-setup.css?v=20260825-9')?>"><link rel="stylesheet" href="<?=url('assets/vendor/fonts/iconify-icons.css?v=10.11.1')?>"><link rel="stylesheet" href="<?=url('assets/employees-buttons.css?v=20260725-6')?>"><link id="vuexy-layout-fixes" rel="stylesheet" href="<?=url('assets/vuexy-layout-fixes.css?v=20')?>"><link rel="stylesheet" href="<?=url('assets/design-system.css?v=2')?>"><script src="<?=url('assets/theme.js?v=20260815-1')?>" defer></script><script src="<?=url('assets/classic-lists.js?v=20260825-15')?>" defer></script><script src="<?=url('assets/multi-window.js?v=20260825-44') ?>" defer></script><script src="<?=url('assets/classic-forms.js?v=20260823-4')?>" defer></script><script src="<?=url('assets/session-timeout.js?v=20260825-1')?>" data-timeout="<?=SESSION_IDLE_TIMEOUT?>" data-logout-url="<?=e(url('logout.php?timeout=1'))?>" data-heartbeat-url="<?=e(url('session-heartbeat.php'))?>" defer></script>
<link rel="stylesheet" href="<?=url('assets/accordion-indicators.css?v=20260824-1')?>">
<style>.help-page-link{display:grid;place-items:center;width:30px;height:30px;color:inherit;text-decoration:none}.help-page-link i{font-size:20px}.stock-entry-filter>a{display:none!important}.patient-brand{transition:none!important}.patient-nav .vox-menu-logout{margin-top:10px!important}.patient-nav .vox-menu-logout .ti{color:#d6b45e!important}</style><?php if($isPatientList):?><script>document.documentElement.classList.add('vox-patient-list')</script><?php endif?>
<link rel="stylesheet" href="<?=url('assets/date-format.css?v=1')?>"><script src="<?=url('assets/date-format.js?v=4')?>" defer></script><script src="<?=url('assets/form-tab-navigation.js')?>" defer></script></head><body id="vox-app"<?=$embeddedWindow?' class="vox-embedded-window"':''?>><?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'stocks.php'): ?><script>if(window.matchMedia('(min-width:901px)').matches)document.body.classList.add('vox-classic-list-page');</script><?php endif ?><script>try{if(window.matchMedia('(min-width:901px)').matches&&localStorage.getItem('vox-sidebar-collapsed')==='true')document.body.classList.add('menu-collapsed','layout-menu-collapsed')}catch(error){}</script><header class="patient-header"><div class="patient-topbar"><a class="patient-brand" href="<?=url('index.php')?>"><img src="<?=url('assets/vox-logo-02.png?v=20260823-green-pillow-transparent')?>" alt="VOX"><b>VOX ERP</b></a><div class="page-context"><span>İŞLEM MERKEZİ</span><strong><?=e($title)?></strong></div><div class="header-tools"><div class="account"><button id="account-toggle" class="account-button" type="button"><span class="avatar"><?php if($avatar):?><img src="<?=url($avatar)?>" alt="<?=e($rawName)?> profil fotoğrafı"><?php else:?><?=$initial?><?php endif?></span><span class="account-name"><?=$name?><small><?=$role?></small></span><span>⌄</span></button><div id="account-menu" class="account-menu"><a href="<?=url('profile.php')?>">Profilim</a><?php if(is_admin()):?><a href="<?=url('brands.php')?>">Kurulum</a><?php endif?><a class="logout" href="<?=url('logout.php')?>">Çıkış yap</a></div></div></div></div><nav id="vox-main-menu" class="patient-nav"><a class="<?=$active==='home'?'active':''?>" href="<?=url('index.php')?>"><span><i class="icon-base ti tabler-smart-home"></i></span> Ana Sayfa</a><a class="<?=$active==='patients'?'active':''?>" href="<?=url('patients.php')?>"><span><i class="icon-base ti tabler-layout-sidebar"></i></span> Hasta Kartları</a><a class="<?=$active==='new'?'active':''?>" href="<?=url('patient-form.php')?>"><span><i class="icon-base ti tabler-user-plus"></i></span> Yeni Hasta</a><a class="<?=$active==='kanban'?'active':''?>" href="<?=url('kanban.php')?>"><span><i class="icon-base ti tabler-layout-kanban"></i></span> Kanban</a><a href="#"><span><i class="icon-base ti tabler-refresh"></i></span> Takipler</a><a href="#"><i class="icon-base ti tabler-shopping-cart"></i></span> Satışlar</a><a href="#"><span><i class="icon-base ti tabler-file-report"></i></span> Raporlar</a><?php if(is_admin()):?><a href="<?=url('brands.php')?>"><span><i class="icon-base ti tabler-tools"></i></span> Kurulum</a><?php endif?></nav></header><div class="desktop-taskbar"><button class="desktop-start" type="button" aria-label="Menüyü aç">Başlat</button><span class="desktop-task-title"><?=e($title)?></span><span class="desktop-clock"><?=date('H:i')?></span></div>
<?php
}

function patient_footer(): void
{
    ?>
<script>
document.querySelector('.patient-nav a[href*="index.php"]')?.remove();
const stockMenuLink = document.createElement('a');
stockMenuLink.href = <?= json_encode(url('stocks.php')) ?>;
stockMenuLink.innerHTML = '<span><i class="icon-base ti tabler-package"></i></span> Stoklar';
const setupAnchorForModules = document.querySelector('.patient-nav a[href*="brands.php"]');
if (setupAnchorForModules) setupAnchorForModules.before(stockMenuLink);
else document.querySelector('.patient-nav')?.append(stockMenuLink);
const stockGroup = document.createElement('div');
stockGroup.className = 'report-menu-group';
const stockSubmenu = document.createElement('div');
stockSubmenu.className = 'report-submenu';
const stockExitLink = document.createElement('a');
stockExitLink.href = <?= json_encode(url('stock-exit.php')) ?>;
stockExitLink.textContent = 'Stok Çıkış';
const stockCardLink = document.createElement('a');
stockCardLink.href = <?= json_encode(url('stocks.php')) ?>;
stockCardLink.textContent = 'Stok Kartı Listesi';
const stockPricesLink = document.createElement('a');
stockPricesLink.href = <?= json_encode(url('stock-prices.php')) ?>;
stockPricesLink.textContent = 'Liste Fiyatları';
const priceListsLink = document.createElement('a');
priceListsLink.href = <?= json_encode(url('price-lists.php')) ?>;
priceListsLink.textContent = 'Liste Fiyatları';
const invoiceListLink = document.createElement('a');
invoiceListLink.href = <?= json_encode(url('invoice-list.php')) ?>;
invoiceListLink.textContent = 'Fatura Listesi';
stockSubmenu.append(stockExitLink, stockCardLink, priceListsLink, invoiceListLink);
stockMenuLink.setAttribute('aria-haspopup', 'true');
stockMenuLink.setAttribute('aria-expanded', 'false');
stockMenuLink.addEventListener('click', event => {
  event.preventDefault();
  const open = stockGroup.classList.toggle('open');
  stockMenuLink.setAttribute('aria-expanded', String(open));
  sessionStorage.setItem('vox.stockMenuOpen', open ? '1' : '0');
});
stockSubmenu.addEventListener('click', event => { if (event.target.closest('a')) sessionStorage.setItem('vox.stockMenuOpen', '1'); });
stockMenuLink.before(stockGroup);
stockGroup.append(stockMenuLink, stockSubmenu);
if (location.pathname.endsWith('/stock-card.php') || location.pathname.endsWith('/stocks.php') || location.pathname.endsWith('/stock-entry.php') || location.pathname.endsWith('/stock-exit.php') || location.pathname.endsWith('/price-lists.php') || location.pathname.endsWith('/stock-prices.php') || location.pathname.endsWith('/invoice-list.php') || sessionStorage.getItem('vox.stockMenuOpen') === '1') { stockMenuLink.classList.add('active'); stockGroup.classList.add('open'); stockMenuLink.setAttribute('aria-expanded', 'true'); }
if (location.pathname.endsWith('/stock-exit.php')) stockExitLink.classList.add('active');
if (location.pathname.endsWith('/stocks.php')) stockCardLink.classList.add('active');
if (location.pathname.endsWith('/price-lists.php')) priceListsLink.classList.add('active');
if (location.pathname.endsWith('/invoice-list.php')) invoiceListLink.classList.add('active');
const technicalServiceMenuLink = document.createElement('a');
technicalServiceMenuLink.href = <?= json_encode(url('technical-service.php')) ?>;
technicalServiceMenuLink.innerHTML = '<span><i class="icon-base ti tabler-tools"></i></span> Teknik Servis';
stockGroup.after(technicalServiceMenuLink);
if (location.pathname.endsWith('/technical-service.php')) technicalServiceMenuLink.classList.add('active');
const preCashMenuLink = document.createElement('a');
preCashMenuLink.href = <?= json_encode(url('cash-pre.php')) ?>;
preCashMenuLink.innerHTML = '<span><i class="icon-base ti tabler-cash"></i></span> Ön Kasa';
preCashMenuLink.style.paddingLeft = '24px';
technicalServiceMenuLink.after(preCashMenuLink);
if (location.pathname.endsWith('/cash-pre.php')) preCashMenuLink.classList.add('active');
const currentAccountsMenuLink = document.createElement('a');
currentAccountsMenuLink.href = <?= json_encode(url('current-accounts.php')) ?>;
currentAccountsMenuLink.innerHTML = '<span><i class="icon-base ti tabler-address-book"></i></span> Cari Kartlar';
preCashMenuLink.after(currentAccountsMenuLink);
if (location.pathname.endsWith('/current-accounts.php')) currentAccountsMenuLink.classList.add('active');
const unitsMenuLink = document.createElement('a');
unitsMenuLink.href = <?= json_encode(url('units.php')) ?>;
unitsMenuLink.innerHTML = '<span><i class="icon-base ti tabler-building"></i></span> Ünite';
currentAccountsMenuLink.after(unitsMenuLink);
if (location.pathname.endsWith('/units.php')) unitsMenuLink.classList.add('active');
const unitsGroup = document.createElement('div');
unitsGroup.className = 'report-menu-group';
const unitsSubmenu = document.createElement('div');
unitsSubmenu.className = 'report-submenu';
const companiesMenuLink = document.createElement('a');
companiesMenuLink.href = <?= json_encode(url('companies.php')) ?>;
companiesMenuLink.innerHTML = '<span><i class="icon-base ti tabler-building-community"></i></span> Saha Aksiyonları';
unitsSubmenu.append(companiesMenuLink);
unitsMenuLink.setAttribute('aria-haspopup', 'true');
unitsMenuLink.setAttribute('aria-expanded', 'false');
unitsMenuLink.addEventListener('click', event => { event.preventDefault(); const open = unitsGroup.classList.toggle('open'); unitsMenuLink.setAttribute('aria-expanded', String(open)); });
document.addEventListener('click', event => { if (!unitsGroup.contains(event.target)) { unitsGroup.classList.remove('open'); unitsMenuLink.setAttribute('aria-expanded', 'false'); } });
unitsMenuLink.before(unitsGroup);
unitsGroup.append(unitsMenuLink, unitsSubmenu);
if (location.pathname.endsWith('/companies.php')) { companiesMenuLink.classList.add('active'); }
const standaloneUnitsMenuLink = unitsMenuLink.cloneNode(true);
standaloneUnitsMenuLink.href = <?= json_encode(url('units.php')) ?>;
standaloneUnitsMenuLink.removeAttribute('aria-haspopup');
standaloneUnitsMenuLink.removeAttribute('aria-expanded');
unitsGroup.replaceWith(standaloneUnitsMenuLink);
standaloneUnitsMenuLink.after(companiesMenuLink);
if (location.pathname.endsWith('/units.php')) standaloneUnitsMenuLink.classList.add('active');
if (location.pathname.endsWith('/companies.php')) standaloneUnitsMenuLink.classList.remove('active');
const setupMenuLink = document.querySelector('.patient-nav a[href*="brands.php"]');
const setupPages = ['brands.php','cash-categories.php','service-names.php','service-types.php','admin.php','branches.php','employees.php','social-securities.php','sources.php','complaints.php','banks.php','anamnesis-questions.php'];
const isSetupPage = setupPages.includes(location.pathname.split('/').pop());
try { sessionStorage.removeItem('vox.setupMenuOpen'); } catch (_) {}
document.querySelectorAll('.patient-nav > .report-menu-group').forEach(group => {
  const trigger = group.querySelector(':scope > a');
  if (!trigger || !trigger.textContent.toLocaleLowerCase('tr-TR').includes('kurulum')) return;
  const directLink = setupMenuLink || trigger;
  directLink.href = <?= json_encode(url('brands.php')) ?>;
  directLink.removeAttribute('aria-haspopup');
  directLink.removeAttribute('aria-expanded');
  group.replaceWith(directLink);
});
setupMenuLink?.setAttribute('href', <?= json_encode(url('brands.php')) ?>);
setupMenuLink?.removeAttribute('aria-haspopup');
setupMenuLink?.removeAttribute('aria-expanded');
if (isSetupPage) setupMenuLink?.classList.add('active');
const logoutMenuLink = document.createElement('a');
logoutMenuLink.href = <?= json_encode(url('logout.php')) ?>;
logoutMenuLink.className = 'vox-menu-logout';
logoutMenuLink.innerHTML = '<span><i class="icon-base ti tabler-logout"></i></span> Çıkış';
logoutMenuLink.title = 'Yazılımdan çıkış yap';
if (setupMenuLink?.isConnected) setupMenuLink.after(logoutMenuLink);
else document.querySelector('.patient-nav')?.append(logoutMenuLink);
const reportMenuLink = [...document.querySelectorAll('.patient-nav > a')].find(link => link.textContent.includes('Raporlar'));
const followUpMenuLink = [...document.querySelectorAll('.patient-nav > a')].find(link => link.textContent.includes('Takipler'));
const salesMenuLink = [...document.querySelectorAll('.patient-nav > a')].find(link => link.textContent.includes('Satışlar'));
if (reportMenuLink && followUpMenuLink && salesMenuLink) {
  if (reportMenuLink.lastChild && reportMenuLink.lastChild.nodeType === Node.TEXT_NODE) reportMenuLink.lastChild.nodeValue = ' Listeler';
  if (followUpMenuLink.lastChild && followUpMenuLink.lastChild.nodeType === Node.TEXT_NODE) followUpMenuLink.lastChild.nodeValue = ' İşitme Cihazları';
  followUpMenuLink.href = <?= json_encode(url('hearing-devices.php')) ?>;
  salesMenuLink.href = <?= json_encode(url('sales.php')) ?>;
  const reportGroup = document.createElement('div');
  reportGroup.className = 'report-menu-group';
  const reportSubmenu = document.createElement('div');
  reportSubmenu.className = 'report-submenu';
  const resultListLink = document.createElement('a');
  resultListLink.href = <?= json_encode(url('result-list.php')) ?>;
  resultListLink.textContent = 'Sonuç Listesi';
  const sgkListLink = document.createElement('a');
  sgkListLink.href = <?= json_encode(url('sgk-list.php')) ?>;
  sgkListLink.textContent = 'SGK Listesi';
  const listPages = ['result-list.php', 'patient-results.php', 'hearing-devices.php', 'sales.php', 'sgk-list.php'];
  const shouldOpenListsMenu = listPages.includes(location.pathname.split('/').pop()) || sessionStorage.getItem('vox.listsMenuOpen') === '1';
  if (shouldOpenListsMenu) {
    reportGroup.classList.add('open');
  }
  if (listPages.includes(location.pathname.split('/').pop())) reportMenuLink.classList.add('active');
  if (location.pathname.endsWith('/result-list.php') || location.pathname.endsWith('/patient-results.php')) resultListLink.classList.add('active');
  if (location.pathname.endsWith('/hearing-devices.php')) { resultListLink.classList.remove('active'); followUpMenuLink.classList.add('active'); }
  if (location.pathname.endsWith('/sales.php')) { resultListLink.classList.remove('active'); salesMenuLink.classList.add('active'); }
  if (location.pathname.endsWith('/sgk-list.php')) { resultListLink.classList.remove('active'); sgkListLink.classList.add('active'); }
  reportSubmenu.append(followUpMenuLink, salesMenuLink, resultListLink, sgkListLink);
  const activeReportLink = [...reportSubmenu.querySelectorAll('a')].find(link => {
    try { return new URL(link.href, location.href).pathname === location.pathname; }
    catch (_) { return false; }
  });
  if (activeReportLink) {
    activeReportLink.classList.add('active');
    activeReportLink.style.setProperty('background', '#eef9f1', 'important');
    activeReportLink.style.setProperty('color', '#168c3d', 'important');
    activeReportLink.style.setProperty('font-weight', '700', 'important');
  }
  reportMenuLink.setAttribute('aria-haspopup', 'true');
  reportMenuLink.setAttribute('aria-expanded', 'false');
  if (shouldOpenListsMenu) reportMenuLink.setAttribute('aria-expanded', 'true');
  reportMenuLink.addEventListener('click', event => {
    event.preventDefault();
    const isOpen = reportGroup.classList.toggle('open');
    reportMenuLink.setAttribute('aria-expanded', String(isOpen));
    sessionStorage.setItem('vox.listsMenuOpen', isOpen ? '1' : '0');
  });
  reportSubmenu.addEventListener('click', event => { if (event.target.closest('a')) sessionStorage.setItem('vox.listsMenuOpen', '1'); });
  reportMenuLink.before(reportGroup);
  reportGroup.append(reportMenuLink, reportSubmenu);
  const reportMenuStyle = document.createElement('style');
  reportMenuStyle.textContent = '.patient-nav{overflow:visible!important}.report-menu-group{position:relative;flex:0 0 auto}.report-submenu{display:none;position:absolute;z-index:20;top:calc(100% + 3px);left:0;min-width:170px;padding:6px;border:1px solid #e1e2e8;border-radius:8px;background:#fff;box-shadow:0 8px 18px rgba(47,43,61,.16)}.report-menu-group.open .report-submenu{display:grid;gap:2px}.report-lists-group{position:relative}.report-menu-group.open .report-lists-submenu{display:none}.report-menu-group.open .report-lists-group.open .report-lists-submenu{display:grid;top:0;left:calc(100% + 3px)}.report-submenu a{font-size:14px!important;padding:9px 10px!important}[data-theme=dark] .report-submenu{background:#30334d;border-color:#454a63}';
  document.head.append(reportMenuStyle);
}
const calendarMenuLink = [...document.querySelectorAll('.patient-nav a')].find(link => link.getAttribute('href')?.includes('patient-form.php'));
if (calendarMenuLink) {
  calendarMenuLink.href = <?= json_encode(url('calendar.php')) ?>;
  const calendarIcon = calendarMenuLink.querySelector(':scope > span');
  if (calendarIcon) calendarIcon.innerHTML = '<i class="icon-base ti tabler-calendar"></i>';
  if (calendarMenuLink.lastChild?.nodeType === Node.TEXT_NODE) calendarMenuLink.lastChild.textContent = ' Takvim';
  const appointmentListLink = document.createElement('a');
  appointmentListLink.href = <?= json_encode(url('appointment-list.php')) ?>;
  appointmentListLink.textContent = 'Randevu Listesi';
  const dailyEventsListLink = document.createElement('a');
  dailyEventsListLink.href = <?= json_encode(url('daily-events-list.php')) ?>;
  dailyEventsListLink.textContent = 'Günlük Aksiyon';
  const calendarGroup = document.createElement('div');
  calendarGroup.className = 'report-menu-group';
  const calendarSubmenu = document.createElement('div');
  calendarSubmenu.className = 'calendar-menu-submenu report-submenu';
  const onCalendarPage = location.pathname.endsWith('/calendar.php');
  const onAppointmentListPage = location.pathname.endsWith('/appointment-list.php');
  const onDailyEventsListPage = location.pathname.endsWith('/daily-events-list.php');
  if (onCalendarPage) calendarMenuLink.classList.add('active');
  if (onAppointmentListPage) { calendarMenuLink.classList.add('active'); appointmentListLink.classList.add('active'); }
  if (onDailyEventsListPage) { calendarMenuLink.classList.add('active'); dailyEventsListLink.classList.add('active'); }
  calendarSubmenu.append(appointmentListLink, dailyEventsListLink);
  calendarMenuLink.setAttribute('aria-haspopup', 'true');
  calendarMenuLink.setAttribute('aria-expanded', 'false');
  calendarMenuLink.before(calendarGroup);
  calendarGroup.append(calendarMenuLink, calendarSubmenu);
  if (onCalendarPage || onAppointmentListPage || onDailyEventsListPage || sessionStorage.getItem('vox.calendarMenuOpen') === '1') {
    calendarGroup.classList.add('open');
    calendarMenuLink.setAttribute('aria-expanded', 'true');
  }
  const taskMenuLink = [...document.querySelectorAll('.patient-nav > a')].find(link => link.getAttribute('href')?.includes('kanban.php'));
  if (taskMenuLink) {
    if (taskMenuLink.lastChild?.nodeType === Node.TEXT_NODE) taskMenuLink.lastChild.textContent = ' Görev Takip';
    calendarGroup.after(taskMenuLink);
    if (location.pathname.endsWith('/kanban.php')) taskMenuLink.classList.add('active');
  }
  const calendarMenuStyle = document.createElement('style');
  calendarMenuStyle.textContent = '.patient-nav .calendar-menu-submenu{display:grid!important;gap:1px!important;margin:3px 12px 7px 20px!important;padding:0!important}.patient-nav .calendar-menu-submenu>a{position:relative!important;display:flex!important;justify-content:flex-start!important;align-items:center!important;width:100%!important;min-height:38px!important;margin:0!important;padding:9px 10px 9px 34px!important;border-radius:6px!important;background:transparent!important;color:var(--text)!important;text-align:left!important;text-decoration:none!important;font-size:14px!important;line-height:1.35!important}.patient-nav .calendar-menu-submenu>a::before{position:absolute!important;top:50%!important;left:12px!important;width:8px!important;height:8px!important;border:1.5px solid currentColor!important;border-radius:50%!important;content:""!important;opacity:.9!important;transform:translateY(-50%)!important}.patient-nav .calendar-menu-submenu a.active{background:#eef9f1!important;color:#168c3d!important;font-weight:600!important}';
  calendarMenuStyle.textContent = '.patient-nav .calendar-menu-submenu,.patient-nav .report-submenu{display:grid!important;gap:1px!important;margin:3px 12px 7px 20px!important;padding:0!important}.patient-nav .calendar-menu-submenu>a,.patient-nav .report-submenu>a{position:relative!important;display:flex!important;justify-content:flex-start!important;align-items:center!important;width:100%!important;min-height:38px!important;margin:0!important;padding:9px 10px 9px 34px!important;border-radius:6px!important;background:transparent!important;color:var(--text)!important;text-align:left!important;text-decoration:none!important;font-size:14px!important;line-height:1.35!important}.patient-nav .calendar-menu-submenu>a::before,.patient-nav .report-submenu>a::before{position:absolute!important;top:50%!important;left:12px!important;width:8px!important;height:8px!important;border:1.5px solid currentColor!important;border-radius:50%!important;content:""!important;opacity:.9!important;transform:translateY(-50%)!important}.patient-nav .calendar-menu-submenu a.active,.patient-nav .report-submenu a.active{background:#eef9f1!important;color:#168c3d!important;font-weight:700!important}.patient-nav>a.active,.patient-nav>.report-menu-group>a.active{font-weight:700!important}body.menu-collapsed .patient-nav .calendar-menu-submenu,body.layout-menu-collapsed .patient-nav .calendar-menu-submenu{display:none!important}body.menu-collapsed .patient-nav:hover .calendar-menu-submenu,body.layout-menu-collapsed .patient-nav:hover .calendar-menu-submenu{display:grid!important}body.menu-collapsed .patient-nav:hover .report-menu-group.open .report-submenu,body.layout-menu-collapsed .patient-nav:hover .report-menu-group.open .report-submenu{display:grid!important}body.menu-collapsed .patient-nav:hover .calendar-menu-submenu>a,body.menu-collapsed .patient-nav:hover .report-submenu>a,body.layout-menu-collapsed .patient-nav:hover .calendar-menu-submenu>a,body.layout-menu-collapsed .patient-nav:hover .report-submenu>a{display:flex!important;justify-content:flex-start!important;font-size:14px!important;padding-left:34px!important}';
  document.head.append(calendarMenuStyle);
}
// Vuexy dikey menüdeki gibi: tek açık grup, kayarak açılan alt menü ve dönen ok.
const menuAccordionStyle = document.createElement('style');
menuAccordionStyle.textContent = `
  .patient-nav .report-menu-group{position:static!important;overflow:hidden}
  .patient-nav .report-menu-group>a{position:relative!important}
  .patient-nav .report-menu-group>a::after{content:'›';position:absolute;right:16px;top:50%;font-size:22px;font-weight:400;line-height:1;transform:translateY(-50%) rotate(0deg);transition:transform .25s ease;color:var(--muted)}
  .patient-nav .report-menu-group.open>a::after{transform:translateY(-50%) rotate(90deg)}
  .patient-nav .report-submenu{display:block!important;max-height:0!important;opacity:0!important;overflow:hidden!important;pointer-events:none;transition:max-height .28s ease,opacity .2s ease!important}
  .patient-nav .report-menu-group.open>.report-submenu{max-height:520px!important;opacity:1!important;pointer-events:auto}
`;
document.head.append(menuAccordionStyle);
const menuGroups = () => [...document.querySelectorAll('.patient-nav > .report-menu-group')].filter(group => group.querySelector(':scope > .report-submenu'));
const syncMenuGroup = group => {
  const submenu = group.querySelector(':scope > .report-submenu');
  const trigger = group.querySelector(':scope > a');
  if (!submenu || !trigger) return;
  const open = group.classList.contains('open');
  // Açılış ve kapanış aynı CSS geçişini kullanır; eski satır içi yüksekliği temizle.
  submenu.style.removeProperty('max-height');
  trigger.setAttribute('aria-expanded', String(open));
};
const closeOtherMenuGroups = current => menuGroups().forEach(group => {
  if (group === current) return;
  group.classList.remove('open');
  syncMenuGroup(group);
});
menuGroups().forEach(syncMenuGroup);
document.querySelector('.patient-nav')?.addEventListener('click', event => {
  const trigger = event.target.closest('.report-menu-group > a');
  const group = trigger?.parentElement;
  if (!trigger || !group?.classList.contains('report-menu-group')) return;
  return;
  event.preventDefault();
  event.stopImmediatePropagation();
  const willOpen = !group.classList.contains('open');
  closeOtherMenuGroups(group);
  group.classList.toggle('open', willOpen);
  syncMenuGroup(group);
  const name = trigger.textContent.trim().toLocaleLowerCase('tr-TR');
  if (name.includes('stok')) sessionStorage.setItem('vox.stockMenuOpen', willOpen ? '1' : '0');
  if (name.includes('kurulum')) sessionStorage.setItem('vox.setupMenuOpen', willOpen ? '1' : '0');
  if (name.includes('listeler')) sessionStorage.setItem('vox.listsMenuOpen', willOpen ? '1' : '0');
}, true);
window.addEventListener('resize', () => menuGroups().forEach(syncMenuGroup));
// Açılış eski menü davranışına döner; yalnızca kapanış kayan bir geçiş kullanır.
const restoreMenuOpeningStyle = document.createElement('style');
restoreMenuOpeningStyle.textContent = `
  .patient-nav .report-menu-group{position:relative!important;overflow:visible!important}
  .patient-nav .report-menu-group>a::after{content:none!important}
  .patient-nav .report-submenu{display:none!important;max-height:none!important;opacity:1!important;overflow:visible!important;pointer-events:auto!important;transition:none!important}
  .patient-nav .report-menu-group.open>.report-submenu{display:grid!important}
  .patient-nav .report-menu-group.menu-closing>.report-submenu{display:grid!important;max-height:0!important;overflow:hidden!important;opacity:0!important;transition:max-height .24s ease,opacity .18s ease!important}
`;
document.head.append(restoreMenuOpeningStyle);
document.querySelector('.patient-nav')?.addEventListener('click', event => {
  const trigger = event.target.closest('.report-menu-group > a');
  const group = trigger?.parentElement;
  if (!trigger || !group?.classList.contains('open') || group.classList.contains('menu-closing')) return;
  return;
  const submenu = group.querySelector(':scope > .report-submenu');
  if (!submenu) return;
  event.preventDefault();
  event.stopImmediatePropagation();
  submenu.style.setProperty('max-height', submenu.scrollHeight + 'px', 'important');
  group.classList.add('menu-closing');
  requestAnimationFrame(() => submenu.style.setProperty('max-height', '0px', 'important'));
  const name = trigger.textContent.trim().toLocaleLowerCase('tr-TR');
  setTimeout(() => {
    group.classList.remove('menu-closing', 'open');
    submenu.style.removeProperty('max-height');
    trigger.setAttribute('aria-expanded', 'false');
    if (name.includes('stok')) sessionStorage.setItem('vox.stockMenuOpen', '0');
    if (name.includes('kurulum')) sessionStorage.setItem('vox.setupMenuOpen', '0');
    if (name.includes('listeler')) sessionStorage.setItem('vox.listsMenuOpen', '0');
  }, 250);
}, true);
// Vuexy menu.js _toggleAnimation davranışı: grup yüksekliği başlık ile alt menü arasında geçiş yapar.
const vuexyMenuAnimationStyle = document.createElement('style');
vuexyMenuAnimationStyle.textContent = '.patient-nav .report-menu-group.menu-item-animating{overflow:hidden!important;transition:height .3s ease-in-out!important}';
document.head.append(vuexyMenuAnimationStyle);
const updateAccordionSession = (trigger, open) => {
  const name = trigger.textContent.trim().toLocaleLowerCase('tr-TR');
  if (name.includes('takvim')) sessionStorage.setItem('vox.calendarMenuOpen', open ? '1' : '0');
  if (name.includes('stok')) sessionStorage.setItem('vox.stockMenuOpen', open ? '1' : '0');
  if (name.includes('kurulum')) sessionStorage.setItem('vox.setupMenuOpen', open ? '1' : '0');
  if (name.includes('listeler')) sessionStorage.setItem('vox.listsMenuOpen', open ? '1' : '0');
};
const vuexyToggleGroup = (group, open) => {
  const trigger = group.querySelector(':scope > a');
  const submenu = group.querySelector(':scope > .report-submenu');
  if (!trigger || !submenu || group.dataset.menuAnimating === '1') return;
  const linkHeight = Math.round(trigger.getBoundingClientRect().height);
  group.dataset.menuAnimating = '1';
  group.style.overflow = 'hidden';
  group.classList.add('menu-item-animating');
  trigger.setAttribute('aria-expanded', String(open));
  updateAccordionSession(trigger, open);
  const clear = () => {
    group.removeEventListener('transitionend', onEnd);
    group.classList.remove('menu-item-animating', 'menu-closing');
    group.style.height = '';
    group.style.overflow = '';
    group.dataset.menuAnimating = '';
  };
  const onEnd = event => {
    if (event.target !== group || event.propertyName !== 'height') return;
    if (!open) group.classList.remove('open');
    clear();
  };
  group.addEventListener('transitionend', onEnd);
  if (open) {
    group.style.height = linkHeight + 'px';
    group.classList.add('open');
    setTimeout(() => {
      group.style.height = (linkHeight + Math.round(submenu.getBoundingClientRect().height)) + 'px';
    }, 50);
  } else {
    group.style.height = (linkHeight + Math.round(submenu.getBoundingClientRect().height)) + 'px';
    group.classList.add('menu-closing');
    setTimeout(() => { group.style.height = linkHeight + 'px'; }, 50);
  }
  setTimeout(() => {
    if (group.dataset.menuAnimating !== '1') return;
    if (!open) group.classList.remove('open');
    clear();
  }, 450);
};
document.querySelector('.patient-nav')?.addEventListener('click', event => {
  const trigger = event.target.closest('.report-menu-group > a');
  const group = trigger?.parentElement;
  if (!trigger || !group?.classList.contains('report-menu-group')) return;
  // Takvim ana öğesi bir sayfa bağlantısıdır; menü animasyonu URL geçişini engellememeli.
  if (trigger.getAttribute('href')?.includes('calendar.php')) return;
  event.preventDefault();
  event.stopImmediatePropagation();
  const willOpen = !group.classList.contains('open');
  if (willOpen) [...document.querySelectorAll('.patient-nav > .report-menu-group.open')].forEach(other => { if (other !== group) vuexyToggleGroup(other, false); });
  vuexyToggleGroup(group, willOpen);
}, true);
</script>
<script>
const root=document.documentElement,theme=document.getElementById('theme-toggle');
function setTheme(value){root.dataset.theme=value;localStorage.setItem('vox-theme',value);if(theme)theme.textContent=value==='dark'?'☾':'☼'}
setTheme(localStorage.getItem('vox-theme')||'light');if(theme)theme.addEventListener('click',()=>setTheme(root.dataset.theme==='dark'?'light':'dark'));
const accountButton=document.getElementById('account-toggle'),accountMenu=document.getElementById('account-menu');
if(accountButton){accountButton.addEventListener('click',e=>{e.stopPropagation();accountMenu.classList.toggle('open')});document.addEventListener('click',()=>accountMenu.classList.remove('open'));}
const setupTabPages={
  'brands.php':['<?=url('brands.php')?>','Markalar / Modeller'],
  'cash-categories.php':['<?=url('cash-categories.php')?>','Kasa Kategorileri'],
  'service-names.php':['<?=url('service-names.php')?>','Hizmet Adı'],
  'service-types.php':['<?=url('service-types.php')?>','Hizmet Yerleri'],
  'admin.php':['<?=url('admin.php')?>','Kullanıcı Yönetimi'],
  'branches.php':['<?=url('branches.php')?>','Şubeler'],
  'employees.php':['<?=url('employees.php')?>','Çalışanlar'],
  'social-securities.php':['<?=url('social-securities.php')?>','Sosyal Güvence'],
  'sources.php':['<?=url('sources.php')?>','Başvuru Kaynağı'],
  'complaints.php':['<?=url('complaints.php')?>','Şikayet / Arıza'],
  'banks.php':['<?=url('banks.php')?>','Bankalar'],
  'anamnesis-questions.php':['<?=url('anamnesis-questions.php')?>','Anamnez']
};
const currentSettingsPage=location.pathname.split('/').pop()||'index.php';
if(setupTabPages[currentSettingsPage]){
  const setupContainer=document.querySelector('main');
  let setupTabs=document.querySelector('main > .setup-tabs');
  if(!setupTabs){
    setupTabs=document.querySelector('main > .settings-tabs:not(.brand-page-tabs)');
    if(setupTabs) setupTabs.classList.add('setup-tabs');
  }
  if(!setupTabs&&setupContainer){
    setupTabs=document.createElement('nav');
    setupTabs.className='settings-tabs setup-tabs';
    setupContainer.prepend(setupTabs);
  }
  if(setupTabs){
    setupTabs.setAttribute('aria-label','Kurulum ekranları');
    setupTabs.replaceChildren(...Object.entries(setupTabPages).map(([page,data])=>{
      const link=document.createElement('a');link.href=data[0];link.textContent=data[1];link.dataset.voxSameWindow='setup';
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
</script>
<style>
/* Sol menüde alt menü genişlikleri, iç boşluklar dahil hesaplanır; yatay kaydırma oluşmaz. */
.patient-nav,.patient-nav .report-menu-group,.patient-nav .report-submenu,.patient-nav .calendar-menu-submenu{box-sizing:border-box!important;max-width:100%!important;overflow-x:hidden!important}.patient-nav .report-submenu>a,.patient-nav .calendar-menu-submenu>a{box-sizing:border-box!important;max-width:100%!important}
</style>
<script>
(()=>{
  const setupNewRecordAccordion=card=>{
    if(card.dataset.newRecordAccordionReady||card.hasAttribute('data-static-form'))return;
    const header=card.querySelector(':scope > header');
    const form=card.querySelector(':scope > form');
    const title=header?.querySelector('h1,h2');
    if(!header||!form||!title||!/^Yeni\b/i.test(title.textContent.trim()))return;
    card.dataset.newRecordAccordionReady='true';
    header.classList.add('new-record-toggle');
    header.setAttribute('role','button');
    header.setAttribute('tabindex','0');
    card.classList.add('new-record-collapsed');
    header.setAttribute('aria-expanded','false');
    const chevron=document.createElement('span');
    chevron.className='new-record-chevron';
    chevron.setAttribute('aria-hidden','true');
    chevron.textContent='+';
    header.appendChild(chevron);
    const toggle=()=>{
      const collapsed=card.classList.toggle('new-record-collapsed');
      header.setAttribute('aria-expanded',String(!collapsed));
      chevron.textContent=collapsed?'+':'−';
    };
    header.addEventListener('click',toggle);
    header.addEventListener('keydown',event=>{
      if(event.key==='Enter'||event.key===' '){event.preventDefault();toggle();}
    });
  };
  document.querySelectorAll('.vuexy-form-card,.branch-card').forEach(setupNewRecordAccordion);
  const style=document.createElement('style');
  style.textContent='body#vox-app main .new-record-toggle{position:relative;display:block!important;cursor:pointer!important;user-select:none!important}body#vox-app main .new-record-toggle:focus-visible{outline:2px solid #19a94b!important;outline-offset:-3px!important}body#vox-app main .new-record-chevron{position:absolute!important;right:8px!important;top:50%!important;display:grid!important;place-items:center!important;width:32px!important;height:27px!important;margin:0!important;padding:0!important;transform:translateY(-50%)!important;color:#e00000!important;font:700 25px/25px Arial,sans-serif!important}body#vox-app main .new-record-collapsed>:not(header){display:none!important}';
  document.head.appendChild(style);
})();
</script>
<script>
(() => {
  document.querySelectorAll('a,button').forEach(element => {
    const text = element.textContent.trim();
    const currentTitle = element.getAttribute('title')?.trim() || '';
    const isEdit = currentTitle === 'Düzenle' || /^Düzenle(?:\s*\/\s*Şifre)?$/i.test(text);
    const isDelete = currentTitle === 'Sil' || text === 'Sil';
    if (!isEdit && !isDelete) return;

    const actionLabel = isEdit ? (text || 'Düzenle') : 'Sil';
    if (!currentTitle) element.setAttribute('title', actionLabel);
    if (!element.hasAttribute('aria-label')) element.setAttribute('aria-label', actionLabel);
    element.classList.add('vox-icon-action', isEdit ? 'vox-icon-edit' : 'vox-icon-delete');
    element.innerHTML = isEdit
      ? '<i class="ti tabler-edit" aria-hidden="true"></i>'
      : '<i class="ti tabler-trash" aria-hidden="true"></i>';
  });

  const style = document.createElement('style');
  style.textContent = 'body .vox-icon-action.vox-icon-edit,body .vox-icon-action.vox-icon-delete{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:40px!important;height:42px!important;min-width:40px!important;max-width:40px!important;min-height:42px!important;padding:0!important;border:0!important;border-radius:7px!important;color:#fff!important;line-height:1!important;vertical-align:middle!important;box-sizing:border-box!important;box-shadow:none!important}body .vox-icon-action.vox-icon-edit{background:#19a94b!important}body .vox-icon-action.vox-icon-delete{margin-left:8px!important;background:#e04f55!important}body .vox-icon-action.vox-icon-edit:hover{background:#148d3e!important}body .vox-icon-action.vox-icon-delete:hover{background:#c83f46!important}body .vox-icon-action>.ti{width:18px!important;height:18px!important;font-size:18px!important;flex:0 0 18px!important}';
  document.head.appendChild(style);
})();
</script>
<script>
(() => {
  document.querySelectorAll('button').forEach(button => {
    const text = button.textContent.trim();
    const title = button.getAttribute('title')?.trim() || '';
    const ariaLabel = button.getAttribute('aria-label')?.trim() || '';
    const actionLabel = /^(Kaydet|Güncelle|Kaydı Güncelle|Değişiklikleri Kaydet)$/i.test(text) ? text
      : (/^(Kaydet|Güncelle|Kaydı Güncelle|Değişiklikleri Kaydet)$/i.test(title) ? title
        : (/^(Kaydet|Güncelle|Kaydı Güncelle|Değişiklikleri Kaydet)$/i.test(ariaLabel) ? ariaLabel : ''));
    if (!actionLabel) return;
    button.classList.add('vox-save-icon');
    button.setAttribute('title', actionLabel);
    button.setAttribute('aria-label', actionLabel);
    button.innerHTML = '<i class="icon-base ti tabler-device-floppy" aria-hidden="true"></i>';
  });
  const style = document.createElement('style');
  style.textContent = 'body .vox-save-icon{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:42px!important;height:42px!important;min-width:42px!important;padding:0!important;border:0!important;border-radius:7px!important;background:#19a94b!important;color:#fff!important;line-height:1!important;box-sizing:border-box!important}body .vox-save-icon:hover{background:#148d3e!important}body .vox-save-icon>.ti{display:block!important;flex:0 0 20px!important;width:20px!important;height:20px!important;min-width:20px!important;min-height:20px!important;margin:0!important;padding:0!important;background-color:currentColor!important;font-size:20px!important;line-height:20px!important;-webkit-mask-size:100% 100%!important;mask-size:100% 100%!important}';
  document.head.appendChild(style);
})();
 </script>
<script>
(() => {
  const currentPath = location.pathname.split('/').pop();
  const nav = document.querySelector('.patient-nav');
  if (!nav || !currentPath) return;
  nav.querySelectorAll('a.active').forEach(link => link.classList.remove('active'));
  const currentLink = [...nav.querySelectorAll('a')].find(link => {
    try { return new URL(link.href, location.href).pathname.endsWith('/' + currentPath); }
    catch (_) { return false; }
  });
  if (!currentLink) return;
  currentLink.classList.add('active');
  if (currentLink.closest('.report-submenu, .calendar-menu-submenu')) {
    currentLink.style.setProperty('background', '#eef9f1', 'important');
    currentLink.style.setProperty('color', '#168c3d', 'important');
    currentLink.style.setProperty('font-weight', '700', 'important');
  }
  const groupedParent = currentLink.closest('.report-menu-group')?.querySelector(':scope > a');
  const calendarParent = currentLink.closest('.calendar-menu-submenu')?.previousElementSibling;
  (groupedParent || calendarParent)?.classList.add('active');
})();
</script>
<script>
(() => {
  const applyActiveSubmenuStyle = () => {
    const nav = document.querySelector('.patient-nav');
    if (!nav) return;
    nav.querySelectorAll('a.active').forEach(link => {
      link.classList.remove('active');
      link.style.removeProperty('background');
      link.style.removeProperty('color');
      link.style.removeProperty('font-weight');
    });
    const activeSubmenu = [...nav.querySelectorAll('.report-submenu a,.calendar-menu-submenu a')].find(link => {
      try { return new URL(link.href, location.href).pathname === location.pathname; }
      catch (_) { return false; }
    });
    const directMenu = [...nav.querySelectorAll(':scope > a')].find(link => {
      try { return new URL(link.href, location.href).pathname === location.pathname; }
      catch (_) { return false; }
    });
    const currentLink = activeSubmenu || directMenu;
    if (!currentLink) return;
    currentLink.classList.add('active');
    if (activeSubmenu) {
      activeSubmenu.style.setProperty('background', '#eef9f1', 'important');
      activeSubmenu.style.setProperty('color', '#168c3d', 'important');
      activeSubmenu.style.setProperty('font-weight', '700', 'important');
    }
    const groupedParent = currentLink.closest('.report-menu-group')?.querySelector(':scope > a');
    const calendarParent = currentLink.closest('.calendar-menu-submenu')?.previousElementSibling;
    const parentLink = groupedParent || calendarParent;
    if (parentLink) {
      parentLink.classList.add('active');
      parentLink.style.setProperty('background', '#2eaf3b', 'important');
      parentLink.style.setProperty('color', '#fff', 'important');
      parentLink.style.setProperty('font-weight', '700', 'important');
    }
  };
  applyActiveSubmenuStyle();
  setTimeout(applyActiveSubmenuStyle, 0);
  window.addEventListener('load', applyActiveSubmenuStyle, {once:true});
})();
</script>
<script>
/* Parasal girişleri yazarken Türkçe binlik ayıracıyla gösterir. */
(() => {
  const moneyName = /(price|amount|cost|tutar|sgk|payment|gross|unit_price|purchase)/i;
  const excludedName = /(quantity|vat|rate|oran|discount)/i;
  const isMoneyField = field => field instanceof HTMLInputElement
    && field.type !== 'hidden' && field.type !== 'date' && field.type !== 'time'
    && !excludedName.test(field.name || '')
    && (moneyName.test(field.name || '') || field.classList.contains('bulk-money') || field.dataset.money === 'true');
  const formatMoney = value => {
    const raw = String(value ?? '').replace(/[^0-9,\.]/g, '');
    if (raw === '') return '';
    const comma = raw.indexOf(',');
    const integerPart = (comma === -1 ? raw : raw.slice(0, comma)).replace(/\./g, '');
    const fractionPart = comma === -1 ? '' : raw.slice(comma + 1).replace(/[^0-9]/g, '');
    const integer = (integerPart.replace(/^0+(?=\d)/, '') || '0').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return comma === -1 ? integer : integer + ',' + fractionPart;
  };
  const prepare = field => {
    if (!isMoneyField(field) || field.dataset.moneyFormatReady === '1') return;
    field.dataset.moneyFormatReady = '1';
    if (field.type === 'number') field.type = 'text';
    field.inputMode = 'decimal';
    field.value = formatMoney(field.value);
    field.addEventListener('input', () => {
      const formatted = formatMoney(field.value);
      if (field.value !== formatted) field.value = formatted;
    });
    field.addEventListener('blur', () => { field.value = formatMoney(field.value); });
  };
  const prepareAll = root => root.querySelectorAll?.('input').forEach(prepare);
  prepareAll(document);
  new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
    if (!(node instanceof Element)) return;
    if (node.matches?.('input')) prepare(node);
    prepareAll(node);
  }))).observe(document.documentElement, {childList:true, subtree:true});
})();
</script>
<script>
(() => {
  const addEftOption = select => {
    if (!(select instanceof HTMLSelectElement) || select.dataset.eftOptionReady === '1') return;
    const cashOption = [...select.options].find(option => option.textContent.trim() === 'Nakit');
    if (!cashOption || [...select.options].some(option => option.textContent.trim() === 'EFT / Havale')) return;
    const value = /^(extra_)?payment_type$/.test(select.name) ? 'eft_transfer' : 'EFT / Havale';
    cashOption.after(new Option('EFT / Havale', value));
    select.dataset.eftOptionReady = '1';
  };
  const prepare = root => {
    if (root instanceof HTMLSelectElement) addEftOption(root);
    root.querySelectorAll?.('select').forEach(addEftOption);
  };
  prepare(document);
  new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
    if (node instanceof Element) prepare(node);
  }))).observe(document.documentElement, {childList:true, subtree:true});
})();
</script>
<script>document.querySelectorAll('.cash-table-wrap tbody tr td:nth-child(5)').forEach(cell=>{if(cell.textContent.trim()==='—')cell.textContent='EFT / Havale';});</script><script>
(() => {
  const toggle = () => {
    const open = document.body.classList.toggle('desktop-menu-open');
    document.getElementById('desktop-menu-toggle')?.setAttribute('aria-expanded', String(open));
  };
  document.getElementById('desktop-menu-toggle')?.addEventListener('click', toggle);
  document.querySelector('.desktop-start')?.addEventListener('click', toggle);
  document.addEventListener('keydown', event => { if (event.key === 'Escape') document.body.classList.remove('desktop-menu-open'); });
})();
</script>
<script>
/* Liste dışındaki klasik iş pencerelerini masaüstüne kapatıp görev çubuğundan geri açar. */
(() => {
  if (!window.matchMedia('(min-width:901px)').matches) return;
  const main = document.querySelector('body#vox-app > main');
  const topbar = document.querySelector('.patient-topbar');
  const task = document.querySelector('.desktop-task-title');
  if (!main || !topbar || !task) return;

  const path = location.pathname.toLowerCase();
  const mainClasses = String(main.className || '').toLowerCase();
  const listPath = /\/(?:patients|appointment-list|daily-events-list|invoice-list(?:-v\d+)?|result-list|sgk-list|stocks|stock-movements|stock-prices|price-lists|technical-service|current-accounts|current-account-movements|current-account-documents|hearing-devices|unit-patients|company-patients)\.php$/.test(path);
  const listClass = /(?:^|\s)[a-z0-9_-]*list(?:-page)?(?:\s|$)/.test(mainClasses) || main.matches('.datatable-page');
  if (listPath || listClass || document.querySelector('.vox-list-window-controls')) return;

  const closeButton = document.createElement('button');
  closeButton.type = 'button';
  closeButton.className = 'vox-work-window-close';
  closeButton.title = 'Pencereyi kapat';
  closeButton.setAttribute('aria-label', 'Pencereyi kapat');
  closeButton.textContent = '×';
  topbar.append(closeButton);

  const closeWindow = () => {
    document.body.classList.add('vox-work-window-closed');
    task.classList.add('vox-window-can-restore');
    task.title = 'Pencereyi geri aç';
  };
  const restoreWindow = () => {
    if (!document.body.classList.contains('vox-work-window-closed')) return;
    document.body.classList.remove('vox-work-window-closed');
    task.classList.remove('vox-window-can-restore');
    task.removeAttribute('title');
  };
  closeButton.addEventListener('click', closeWindow);
  task.addEventListener('click', restoreWindow);

  const style = document.createElement('style');
  style.textContent = `
    @media (min-width:901px){
      body#vox-app .vox-work-window-close{position:absolute!important;top:4px!important;right:5px!important;width:22px!important;min-width:22px!important;height:22px!important;min-height:22px!important;margin:0!important;padding:0!important;display:grid!important;place-items:center!important;border:1px solid rgba(255,255,255,.72)!important;border-radius:2px!important;background:linear-gradient(#4b8f7d,#155445)!important;color:#fff!important;font:700 16px/18px Tahoma,"Segoe UI",sans-serif!important;text-shadow:1px 1px #06382e!important;box-shadow:inset 1px 1px rgba(255,255,255,.28)!important;cursor:pointer!important}
      body#vox-app .vox-work-window-close:hover{background:linear-gradient(#e88470,#a52d20)!important}
      body#vox-app.vox-work-window-closed .patient-topbar,body#vox-app.vox-work-window-closed>main{display:none!important}
      body#vox-app .desktop-task-title.vox-window-can-restore{cursor:pointer!important;box-shadow:inset 0 0 0 1px rgba(255,255,255,.32)!important}
    }`;
  document.head.append(style);
})();
</script><link rel="stylesheet" href="<?=e(url('assets/price-list-help.css'))?>"><script src="<?=e(url('assets/price-list-help.js'))?>" data-endpoint="<?=e(url('price-list-help.php'))?>" defer></script></body></html>
<?php
}
