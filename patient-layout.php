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
<link rel="stylesheet" href="<?=url('assets/amerce/fonts/fonts.css')?>"><link rel="stylesheet" href="<?=url('assets/patients.css?v=20260725-1')?>"><link rel="stylesheet" href="<?=url('assets/vendor/fonts/iconify-icons.css?v=10.11.1')?>"><link rel="stylesheet" href="<?=url('assets/employees-buttons.css?v=20260725-6')?>"><script src="<?=url('assets/theme.js?v=20260725-33')?>" defer></script>
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
const stockCardLink = document.createElement('a');
stockCardLink.href = <?= json_encode(url('stocks.php')) ?>;
stockCardLink.textContent = 'Stok Kartı Listesi';
const stockPricesLink = document.createElement('a');
stockPricesLink.href = <?= json_encode(url('stock-prices.php')) ?>;
stockPricesLink.textContent = 'Liste Fiyatları';
const priceListsLink = document.createElement('a');
priceListsLink.href = <?= json_encode(url('price-lists.php')) ?>;
priceListsLink.textContent = 'Liste Fiyatları';
stockSubmenu.append(stockEntryLink, stockCardLink, priceListsLink);
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
if (location.pathname.endsWith('/stock-card.php') || location.pathname.endsWith('/stocks.php') || location.pathname.endsWith('/stock-entry.php') || location.pathname.endsWith('/price-lists.php') || location.pathname.endsWith('/stock-prices.php') || sessionStorage.getItem('vox.stockMenuOpen') === '1') { stockMenuLink.classList.add('active'); stockGroup.classList.add('open'); stockMenuLink.setAttribute('aria-expanded', 'true'); }
if (location.pathname.endsWith('/stock-entry.php')) stockEntryLink.classList.add('active');
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
  const listPages = ['result-list.php', 'patient-results.php', 'hearing-devices.php', 'sales.php'];
  const shouldOpenListsMenu = listPages.includes(location.pathname.split('/').pop()) || sessionStorage.getItem('vox.listsMenuOpen') === '1';
  if (shouldOpenListsMenu) {
    resultListLink.classList.add('active');
    reportMenuLink.classList.add('active');
    reportGroup.classList.add('open');
  }
  if (location.pathname.endsWith('/hearing-devices.php')) { resultListLink.classList.remove('active'); followUpMenuLink.classList.add('active'); }
  if (location.pathname.endsWith('/sales.php')) { resultListLink.classList.remove('active'); salesMenuLink.classList.add('active'); }
  reportSubmenu.append(followUpMenuLink, salesMenuLink, resultListLink);
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
  'sources.php':['<?=url('sources.php')?>','Başvuru Kaynağı'],
  'complaints.php':['<?=url('complaints.php')?>','Şikayet / Arıza']
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
</script></body></html>
<?php
}
