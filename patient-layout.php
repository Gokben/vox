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
<title><?=e($title)?> | <?=APP_NAME?></title><link rel="icon" type="image/png" href="<?=url('assets/favicon.png?v=20260713')?>">
<link rel="stylesheet" href="<?=url('assets/amerce/fonts/fonts.css')?>"><link rel="stylesheet" href="<?=url('assets/patients.css?v=20260725-1')?>"><link rel="stylesheet" href="<?=url('assets/vendor/fonts/iconify-icons.css?v=10.11.1')?>"><link rel="stylesheet" href="<?=url('assets/employees-buttons.css?v=20260725-6')?>"><script src="<?=url('assets/theme.js?v=20260801-1')?>" defer></script>
</head><body><header class="patient-header"><div class="patient-topbar"><a class="patient-brand" href="<?=url('index.php')?>"><img src="<?=url('assets/vox-logo-02.png?v=20260713-9')?>" alt="VOX"><b>VOX</b></a><div class="header-tools"><button class="plain-tool" type="button" title="Arama">⌕</button><span class="language">TR</span><button id="theme-toggle" class="plain-tool" type="button" title="Görünümü değiştir">☼</button><div class="account"><button id="account-toggle" class="account-button" type="button"><span class="avatar"><?php if($avatar):?><img src="<?=url($avatar)?>" alt="<?=e($rawName)?> profil fotoğrafı"><?php else:?><?=$initial?><?php endif?></span><span class="account-name"><?=$name?><small><?=$role?></small></span><span>⌄</span></button><div id="account-menu" class="account-menu"><a href="<?=url('profile.php')?>">Profilim</a><?php if(is_admin()):?><a href="<?=url('admin.php')?>">Ayarlar</a><?php endif?><a class="logout" href="<?=url('logout.php')?>">Çıkış yap</a></div></div></div></div><nav class="patient-nav"><a class="<?=$active==='home'?'active':''?>" href="<?=url('index.php')?>"><span><i class="icon-base ti tabler-smart-home"></i></span> Ana Sayfa</a><a class="<?=$active==='patients'?'active':''?>" href="<?=url('patients.php')?>"><span><i class="icon-base ti tabler-layout-sidebar"></i></span> Hasta Kartları</a><a class="<?=$active==='new'?'active':''?>" href="<?=url('patient-form.php')?>"><span><i class="icon-base ti tabler-user-plus"></i></span> Yeni Hasta</a><a class="<?=$active==='kanban'?'active':''?>" href="<?=url('kanban.php')?>"><span><i class="icon-base ti tabler-layout-kanban"></i></span> Kanban</a><a href="#"><span><i class="icon-base ti tabler-refresh"></i></span> Takipler</a><a href="#"><span><i class="icon-base ti tabler-shopping-cart"></i></span> Satışlar</a><a href="#"><span><i class="icon-base ti tabler-file-report"></i></span> Raporlar</a><?php if(is_admin()):?><a href="<?=url('admin.php')?>"><span><i class="icon-base ti tabler-settings"></i></span> Ayarlar</a><?php endif?></nav></header>
<?php
}

function patient_footer(): void
{
    ?>
<script>
const stockMenuLink = document.createElement('a');
stockMenuLink.href = <?= json_encode(url('stocks.php')) ?>;
stockMenuLink.innerHTML = '<span><i class="icon-base ti tabler-package"></i></span> Stoklar';
document.querySelector('.patient-nav a[href*="admin.php"]')?.before(stockMenuLink);
const stockGroup = document.createElement('div');
stockGroup.className = 'report-menu-group';
const stockSubmenu = document.createElement('div');
stockSubmenu.className = 'report-submenu';
const stockEntryLink = document.createElement('a');
stockEntryLink.href = <?= json_encode(url('stock-entry.php')) ?>;
stockEntryLink.textContent = 'Stok Giriş';
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
stockSubmenu.append(stockEntryLink, stockExitLink, stockCardLink, priceListsLink);
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
if (location.pathname.endsWith('/stock-card.php') || location.pathname.endsWith('/stocks.php') || location.pathname.endsWith('/stock-entry.php') || location.pathname.endsWith('/stock-exit.php') || location.pathname.endsWith('/price-lists.php') || location.pathname.endsWith('/stock-prices.php') || sessionStorage.getItem('vox.stockMenuOpen') === '1') { stockMenuLink.classList.add('active'); stockGroup.classList.add('open'); stockMenuLink.setAttribute('aria-expanded', 'true'); }
if (location.pathname.endsWith('/stock-entry.php')) stockEntryLink.classList.add('active');
if (location.pathname.endsWith('/stock-exit.php')) stockExitLink.classList.add('active');
if (location.pathname.endsWith('/stocks.php')) stockCardLink.classList.add('active');
if (location.pathname.endsWith('/price-lists.php')) priceListsLink.classList.add('active');
const technicalServiceMenuLink = document.createElement('a');
technicalServiceMenuLink.href = <?= json_encode(url('technical-service.php')) ?>;
technicalServiceMenuLink.innerHTML = '<span><i class="icon-base ti tabler-tools"></i></span> Teknik Servis';
stockGroup.after(technicalServiceMenuLink);
if (location.pathname.endsWith('/technical-service.php')) technicalServiceMenuLink.classList.add('active');
const cashMenuLink = document.createElement('a');
cashMenuLink.href = <?= json_encode(url('cash.php')) ?>;
cashMenuLink.innerHTML = '<span><i class="icon-base ti tabler-briefcase-filled"></i></span> Kasa';
technicalServiceMenuLink.after(cashMenuLink);
if (location.pathname.endsWith('/cash.php')) cashMenuLink.classList.add('active');
const preCashMenuLink = document.createElement('a');
preCashMenuLink.href = <?= json_encode(url('cash-pre.php')) ?>;
preCashMenuLink.innerHTML = '<span><i class="icon-base ti tabler-cash"></i></span> Ön Kasa';
preCashMenuLink.style.paddingLeft = '24px';
cashMenuLink.before(preCashMenuLink);
if (location.pathname.endsWith('/cash-pre.php')) preCashMenuLink.classList.add('active');
const currentAccountsMenuLink = document.createElement('a');
currentAccountsMenuLink.href = <?= json_encode(url('current-accounts.php')) ?>;
currentAccountsMenuLink.innerHTML = '<span><i class="icon-base ti tabler-address-book"></i></span> Cari Kartlar';
cashMenuLink.after(currentAccountsMenuLink);
if (location.pathname.endsWith('/current-accounts.php')) currentAccountsMenuLink.classList.add('active');
const unitsMenuLink = document.createElement('a');
unitsMenuLink.href = <?= json_encode(url('units.php')) ?>;
unitsMenuLink.innerHTML = '<span><i class="icon-base ti tabler-building"></i></span> Üniteler';
currentAccountsMenuLink.after(unitsMenuLink);
if (location.pathname.endsWith('/units.php')) unitsMenuLink.classList.add('active');
const unitsGroup = document.createElement('div');
unitsGroup.className = 'report-menu-group';
const unitsSubmenu = document.createElement('div');
unitsSubmenu.className = 'report-submenu';
const companiesMenuLink = document.createElement('a');
companiesMenuLink.href = <?= json_encode(url('companies.php')) ?>;
companiesMenuLink.innerHTML = '<span><i class="icon-base ti tabler-building-community"></i></span> Kurumlar & Firmalar';
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
const setupMenuLink = document.createElement('a');
setupMenuLink.href = '#';
setupMenuLink.innerHTML = '<span><i class="icon-base ti tabler-tools"></i></span> Kurulum';
document.querySelector('.patient-nav a[href*="admin.php"]')?.after(setupMenuLink);
const setupGroup = document.createElement('div');
setupGroup.className = 'report-menu-group';
const setupSubmenu = document.createElement('div');
setupSubmenu.className = 'report-submenu';
const brandsMenuLink = document.createElement('a');
brandsMenuLink.href = <?= json_encode(url('brands.php')) ?>;
brandsMenuLink.textContent = 'Markalar';
const cashCategoriesMenuLink = document.createElement('a');
cashCategoriesMenuLink.href = <?= json_encode(url('cash-categories.php')) ?>;
cashCategoriesMenuLink.textContent = 'Kasa Kategorileri';
const serviceNamesMenuLink = document.createElement('a');
serviceNamesMenuLink.href = <?= json_encode(url('service-names.php')) ?>;
serviceNamesMenuLink.textContent = 'Hizmet Adı';
const serviceTypesMenuLink = document.createElement('a');
serviceTypesMenuLink.href = <?= json_encode(url('service-types.php')) ?>;
serviceTypesMenuLink.textContent = 'Hizmet Yerleri';
if (location.pathname.endsWith('/brands.php') || location.pathname.endsWith('/cash-categories.php') || location.pathname.endsWith('/service-types.php')) {
  setupMenuLink.classList.add('active');
  if (location.pathname.endsWith('/cash-categories.php')) cashCategoriesMenuLink.classList.add('active');
  if (location.pathname.endsWith('/service-types.php')) serviceTypesMenuLink.classList.add('active');
}
if (location.pathname.endsWith('/service-names.php')) { setupMenuLink.classList.add('active'); serviceNamesMenuLink.classList.add('active'); }
setupSubmenu.append(brandsMenuLink, cashCategoriesMenuLink, serviceNamesMenuLink, serviceTypesMenuLink);
setupMenuLink.setAttribute('aria-haspopup', 'true');
setupMenuLink.setAttribute('aria-expanded', 'false');
setupMenuLink.addEventListener('click', event => {
  event.preventDefault();
  const isOpen = setupGroup.classList.toggle('open');
  setupMenuLink.setAttribute('aria-expanded', String(isOpen));
  sessionStorage.setItem('vox.setupMenuOpen', isOpen ? '1' : '0');
});
setupSubmenu.addEventListener('click', event => {
  if (event.target.closest('a')) sessionStorage.setItem('vox.setupMenuOpen', '1');
});
setupMenuLink.before(setupGroup);
setupGroup.append(setupMenuLink, setupSubmenu);
const setupPages = ['brands.php','cash-categories.php','service-names.php','service-types.php'];
const isSetupPage = setupPages.includes(location.pathname.split('/').pop());
if (isSetupPage || sessionStorage.getItem('vox.setupMenuOpen') === '1') {
  setupGroup.classList.add('open');
  setupMenuLink.setAttribute('aria-expanded', 'true');
}
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
  const calendarSubmenu = document.createElement('div');
  calendarSubmenu.className = 'calendar-menu-submenu';
  const onCalendarPage = location.pathname.endsWith('/calendar.php');
  const onAppointmentListPage = location.pathname.endsWith('/appointment-list.php');
  const onDailyEventsListPage = location.pathname.endsWith('/daily-events-list.php');
  if (onCalendarPage) calendarMenuLink.classList.add('active');
  if (onAppointmentListPage) { calendarMenuLink.classList.add('active'); appointmentListLink.classList.add('active'); }
  if (onDailyEventsListPage) { calendarMenuLink.classList.add('active'); dailyEventsListLink.classList.add('active'); }
  calendarSubmenu.append(appointmentListLink, dailyEventsListLink);
  calendarMenuLink.after(calendarSubmenu);
  const taskMenuLink = [...document.querySelectorAll('.patient-nav > a')].find(link => link.getAttribute('href')?.includes('kanban.php'));
  if (taskMenuLink) {
    taskMenuLink.querySelector(':scope > span')?.remove();
    if (taskMenuLink.lastChild?.nodeType === Node.TEXT_NODE) taskMenuLink.lastChild.textContent = ' Görev Takip';
    dailyEventsListLink.after(taskMenuLink);
    if (location.pathname.endsWith('/kanban.php')) calendarMenuLink.classList.add('active');
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
vuexyMenuAnimationStyle.textContent = '.patient-nav .report-menu-group.menu-item-animating{overflow:hidden!important;transition:height .3s ease!important}';
document.head.append(vuexyMenuAnimationStyle);
const updateAccordionSession = (trigger, open) => {
  const name = trigger.textContent.trim().toLocaleLowerCase('tr-TR');
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
  group.style.height = open ? linkHeight + 'px' : linkHeight + Math.round(submenu.getBoundingClientRect().height) + 'px';
  group.classList.add('menu-item-animating');
  if (open) group.classList.add('open');
  trigger.setAttribute('aria-expanded', String(open));
  updateAccordionSession(trigger, open);
  const clear = () => {
    group.removeEventListener('transitionend', onEnd);
    if (!open) group.classList.remove('open');
    group.classList.remove('menu-item-animating');
    group.style.height = '';
    group.dataset.menuAnimating = '';
  };
  const onEnd = event => { if (event.target === group && event.propertyName === 'height') clear(); };
  group.addEventListener('transitionend', onEnd);
  setTimeout(() => {
    group.style.height = (open ? linkHeight + Math.round(submenu.getBoundingClientRect().height) : linkHeight) + 'px';
  }, 50);
  setTimeout(clear, 400);
};
document.querySelector('.patient-nav')?.addEventListener('click', event => {
  const trigger = event.target.closest('.report-menu-group > a');
  const group = trigger?.parentElement;
  if (!trigger || !group?.classList.contains('report-menu-group')) return;
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
const settingsPages={
  'admin.php':['<?=url('admin.php')?>','Kullanıcı Yönetimi'],
  'branches.php':['<?=url('branches.php')?>','Şubeler'],
  'employees.php':['<?=url('employees.php')?>','Çalışanlar'],
  'social-securities.php':['<?=url('social-securities.php')?>','Sosyal Güvence'],
  'sources.php':['<?=url('sources.php')?>','Başvuru Kaynağı'],
  'complaints.php':['<?=url('complaints.php')?>','Şikayet / Arıza'],
  'banks.php':['<?=url('banks.php')?>','Bankalar']
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
</script>
<style>
/* Sol menüde alt menü genişlikleri, iç boşluklar dahil hesaplanır; yatay kaydırma oluşmaz. */
.patient-nav,.patient-nav .report-menu-group,.patient-nav .report-submenu,.patient-nav .calendar-menu-submenu{box-sizing:border-box!important;max-width:100%!important;overflow-x:hidden!important}.patient-nav .report-submenu>a,.patient-nav .calendar-menu-submenu>a{box-sizing:border-box!important;max-width:100%!important}
</style>
<script>
(()=>{
  const setupNewRecordAccordion=card=>{
    if(card.dataset.newRecordAccordionReady)return;
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
    chevron.textContent='⌄';
    header.appendChild(chevron);
    const toggle=()=>{
      const collapsed=card.classList.toggle('new-record-collapsed');
      header.setAttribute('aria-expanded',String(!collapsed));
    };
    header.addEventListener('click',toggle);
    header.addEventListener('keydown',event=>{
      if(event.key==='Enter'||event.key===' '){event.preventDefault();toggle();}
    });
  };
  document.querySelectorAll('.vuexy-form-card,.branch-card').forEach(setupNewRecordAccordion);
  const style=document.createElement('style');
  style.textContent='.new-record-toggle{position:relative;display:block!important;cursor:pointer;user-select:none}.new-record-toggle:focus-visible{outline:2px solid #19a94b;outline-offset:-3px}.new-record-chevron{position:absolute;right:24px;top:50%;font-size:24px;line-height:1;transform:translateY(-50%) rotate(0);transition:transform .2s ease}.new-record-collapsed .new-record-chevron{transform:translateY(-50%) rotate(-90deg)}.new-record-collapsed>:not(header){display:none!important}';
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
(()=>{
  const notificationKey='vox-save-notification';
  const showSavedNotification=()=>{
    document.querySelector('.vox-save-notification')?.remove();
    const notice=document.createElement('div');
    notice.className='vox-save-notification';
    notice.textContent='Kaydedildi';
    document.body.append(notice);
    setTimeout(()=>notice.classList.add('visible'),0);
    setTimeout(()=>{notice.classList.remove('visible');setTimeout(()=>notice.remove(),220);},2600);
  };
  const isSaveAction=button=>/^(kaydet|güncelle|kaydı güncelle|değişiklikleri kaydet)$/i.test((button?.textContent||'').trim())||/^(kaydet|güncelle|kaydı güncelle|değişiklikleri kaydet)$/i.test(button?.getAttribute('title')||'')||/^(kaydet|güncelle|kaydı güncelle|değişiklikleri kaydet)$/i.test(button?.getAttribute('aria-label')||'');
  if(sessionStorage.getItem(notificationKey)==='1'){sessionStorage.removeItem(notificationKey);showSavedNotification();}
  document.addEventListener('submit',event=>{if(!event.defaultPrevented){sessionStorage.setItem(notificationKey,'1');showSavedNotification();}},true);
  document.addEventListener('click',event=>{const button=event.target.closest('button');if(button&&isSaveAction(button))showSavedNotification();});
  const style=document.createElement('style');
  style.textContent='.vox-save-notification{position:fixed;z-index:3000;right:24px;bottom:24px;padding:12px 18px;border-radius:7px;background:#19a94b;color:#fff;font-weight:700;box-shadow:0 8px 22px rgba(25,169,75,.28);opacity:0;transform:translateY(10px);transition:opacity .2s,transform .2s}.vox-save-notification.visible{opacity:1;transform:translateY(0)}';
  document.head.append(style);
})();
</script>
<style>
/* Vuexy dikey menü açılış/kapanış geçişi. */
.patient-nav .report-menu-group{transition:height .35s ease!important;will-change:height}.patient-nav .report-menu-group.vox-menu-animating{overflow:hidden!important}.patient-nav .report-menu-group.vox-menu-animating>.report-submenu{display:grid!important}.patient-nav .report-menu-group.vox-menu-animating>.report-submenu,.patient-nav .report-menu-group.menu-closing>.report-submenu{animation:voxMenuFade .35s ease both}@keyframes voxMenuFade{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:translateY(0)}}
</style>
<script>
/* Vuexy Menu._toggleAnimation ile aynı yükseklik geçişi: önce başlık yüksekliği, sonra alt menü yüksekliği. */
(() => {
  const groups = () => [...document.querySelectorAll('.patient-nav > .report-menu-group')].filter(group => group.querySelector(':scope > .report-submenu'));
  const animate = (group, open) => {
    const trigger = group.querySelector(':scope > a'), submenu = group.querySelector(':scope > .report-submenu');
    if (!trigger || !submenu || group.classList.contains('vox-menu-animating')) return;
    const triggerHeight = Math.round(trigger.getBoundingClientRect().height);
    const finish = () => { group.classList.remove('vox-menu-animating','menu-closing'); group.style.removeProperty('height'); group.style.removeProperty('overflow'); };
    group.classList.add('vox-menu-animating');
    if (open) {
      group.style.height = triggerHeight + 'px';
      group.style.overflow = 'hidden';
      group.classList.add('open');
      requestAnimationFrame(() => requestAnimationFrame(() => group.style.height = (triggerHeight + submenu.scrollHeight) + 'px'));
      group.addEventListener('transitionend', event => { if (event.propertyName === 'height') finish(); }, {once:true});
    } else {
      group.style.height = (triggerHeight + submenu.scrollHeight) + 'px';
      group.style.overflow = 'hidden';
      group.classList.add('menu-closing');
      requestAnimationFrame(() => requestAnimationFrame(() => group.style.height = triggerHeight + 'px'));
      group.addEventListener('transitionend', event => { if (event.propertyName === 'height') { group.classList.remove('open'); finish(); } }, {once:true});
    }
  };
  document.addEventListener('click', event => {
    const trigger = event.target.closest('.patient-nav > .report-menu-group > a');
    const group = trigger?.parentElement;
    if (!trigger || !group) return;
    event.preventDefault(); event.stopImmediatePropagation();
    const opening = !group.classList.contains('open');
    if (opening) groups().filter(item => item !== group && item.classList.contains('open')).forEach(item => animate(item, false));
    animate(group, opening);
  }, true);
})();
</script></body></html>
<?php
}
