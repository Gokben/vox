(function(){
  try{document.documentElement.dataset.theme=localStorage.getItem('vox-theme')||localStorage.getItem('lf-theme')||'light'}catch(error){document.documentElement.dataset.theme='light'}

  function trDate(value){
    var match=String(value||'').trim().match(/^(\d{4})-(\d{2})-(\d{2})(?:[T\s](\d{2}):(\d{2})(?::\d{2})?)?$/);
    if(!match)return value;
    return match[3]+'.'+match[2]+'.'+match[1]+(match[4]?' '+match[4]+':'+match[5]:'');
  }
  function formatTableDates(){
    document.querySelectorAll('table').forEach(function(table){
      var headers=Array.from(table.querySelectorAll('thead th'));
      headers.forEach(function(header,index){
        var title=(header.textContent||'').trim().toLocaleUpperCase('tr-TR');
        if(title.indexOf('TARİH')===-1&&title.indexOf('ZAMAN')===-1)return;
        table.querySelectorAll('tbody tr').forEach(function(row){
          var cell=row.children[index];
          if(!cell)return;
          var original=cell.textContent.trim(),formatted=trDate(original);
          if(formatted!==original){cell.textContent=formatted;cell.dataset.isoDate=original;}
        });
      });
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',formatTableDates);else formatTableDates();
  window.voxFormatDate=trDate;
})();

(() => {
  function initActionIcons() {
    if (!document.querySelector('link[href*="iconify-icons.css"]')) {
      const iconStyles = document.createElement('link');
      iconStyles.rel = 'stylesheet';
      iconStyles.href = 'assets/vendor/fonts/iconify-icons.css?v=10.11.1';
      document.head.appendChild(iconStyles);
    }

    document.querySelectorAll('button,a').forEach(element => {
      const label = element.textContent.trim();
      const isSave = label === 'Kaydet';
      const isCancel = label === 'İptal';
      const isListBack = label === 'Listeye dön';
      const isDeactivate = label === 'Pasifleştir';
      const isProfile = label === 'Profilim';
      const isAccountSettings = label === 'Ayarlar' && element.matches('.account-menu a');
      const isLogout = label === 'Çıkış yap' && element.matches('.account-menu a');
      if (!isSave && !isCancel && !isListBack && !isDeactivate && !isProfile && !isAccountSettings && !isLogout) return;

      const actionClass = isSave ? 'vox-save-action'
        : isCancel ? 'vox-cancel-action'
        : isListBack ? 'vox-list-action'
        : isDeactivate ? 'vox-deactivate-action'
        : isProfile ? 'vox-profile-action'
        : isAccountSettings ? 'vox-settings-action'
        : 'vox-logout-action';
      element.classList.add('vox-action-icon', actionClass);
      element.setAttribute('title', label);
      if (!element.hasAttribute('aria-label')) element.setAttribute('aria-label', label);
      element.innerHTML = isSave
        ? '<i class="ti tabler-device-floppy" aria-hidden="true"></i>'
        : (isCancel
          ? '<i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i>'
          : (isListBack
            ? '<i class="ti tabler-home-hand" aria-hidden="true"></i>'
            : (isDeactivate
              ? '<i class="ti tabler-home-x" aria-hidden="true"></i>'
              : (isProfile
                ? '<i class="ti tabler-user-check" aria-hidden="true"></i>'
                : (isAccountSettings
                  ? '<i class="ti tabler-settings" aria-hidden="true"></i>'
                  : '<i class="ti tabler-logout" aria-hidden="true"></i>')))));
    });

    const style = document.createElement('style');
    style.textContent = 'body .account-menu.open{display:flex!important;flex-direction:row!important;align-items:center!important;gap:8px!important}body .vox-action-icon.vox-save-action,body .vox-action-icon.vox-cancel-action,body .vox-action-icon.vox-list-action,body .vox-action-icon.vox-deactivate-action,body .vox-action-icon.vox-profile-action,body .vox-action-icon.vox-settings-action,body .vox-action-icon.vox-logout-action{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:40px!important;height:42px!important;min-width:40px!important;max-width:40px!important;min-height:42px!important;padding:0!important;border:0!important;border-radius:7px!important;color:#fff!important;line-height:1!important;text-decoration:none!important;vertical-align:middle!important;box-sizing:border-box!important}body .vox-action-icon.vox-save-action{background:#19a94b!important}body .vox-action-icon.vox-cancel-action,body .vox-action-icon.vox-deactivate-action,body .vox-action-icon.vox-logout-action{background:#e04f55!important}body .vox-action-icon.vox-list-action{background:#19a94b!important}body .vox-action-icon.vox-profile-action,body .vox-action-icon.vox-settings-action{border:1px solid #e1e2e8!important;background:#fff!important;color:#2f2b3d!important}body .vox-action-icon.vox-save-action:hover{background:#148d3e!important}body .vox-action-icon.vox-cancel-action:hover,body .vox-action-icon.vox-deactivate-action:hover,body .vox-action-icon.vox-logout-action:hover{background:#c83f46!important}body .vox-action-icon.vox-list-action:hover{background:#148d3e!important}body .vox-action-icon.vox-profile-action:hover,body .vox-action-icon.vox-settings-action:hover{background:#f1f1f4!important}body .vox-action-icon>.ti{width:18px!important;height:18px!important;font-size:18px!important;flex:0 0 18px!important}body .vox-action-icon>.fa-solid.fa-arrow-rotate-left{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:18px!important;height:18px!important;font:700 20px/18px Arial,sans-serif!important}body .vox-action-icon>.fa-solid.fa-arrow-rotate-left:before{content:"↶"}';
    style.textContent += 'body .vox-action-icon.vox-save-action,body .vox-action-icon.vox-cancel-action,body .vox-action-icon.vox-list-action,body .vox-action-icon.vox-deactivate-action,body .vox-action-icon.vox-profile-action,body .vox-action-icon.vox-settings-action,body .vox-action-icon.vox-logout-action{width:36px!important;height:36px!important;min-width:36px!important;max-width:36px!important;min-height:36px!important;max-height:36px!important}';
    document.head.appendChild(style);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initActionIcons);
  else initActionIcons();
})();

// Çalışan listesinde aktif/pasif durumunun yönetimi.
(() => {
  async function initEmployeeStatus() {
    const page = document.querySelector('.employee-page');
    const table = page?.querySelector('.employee-card table');
    if (!page || !table || table.dataset.statusReady) return;
    table.dataset.statusReady = 'true';

    try {
      const endpoint = new URL('employee-status.php', location.href).href;
      const employees = await fetch(endpoint, { credentials: 'same-origin' }).then(response => response.json());
      const byId = new Map(employees.map(employee => [String(employee.id), employee]));
      const csrf = document.querySelector('input[name="csrf"]')?.value || '';
      const head = table.tHead?.rows[0];
      if (head) {
        const statusHead = document.createElement('th');
        statusHead.textContent = 'Durum';
        head.insertBefore(statusHead, head.lastElementChild);
      }

      table.tBodies[0]?.querySelectorAll('tr').forEach(row => {
        const edit = row.querySelector('a[href*="employees.php?edit="]');
        const id = edit?.href.match(/[?&]edit=(\d+)/)?.[1];
        const employee = id ? byId.get(id) : null;
        if (!employee) return;
        const cell = document.createElement('td');
        const form = document.createElement('form');
        form.method = 'post';
        form.action = endpoint;
        form.className = 'employee-status-form';
        form.innerHTML = `<input type="hidden" name="csrf" value="${csrf}"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="${employee.id}"><button type="submit" class="employee-status ${employee.active ? 'is-active' : 'is-passive'}">${employee.active ? 'Aktif' : 'Pasif'}</button>`;
        cell.append(form);
        row.insertBefore(cell, row.lastElementChild);
      });

      const style = document.createElement('style');
      style.textContent = `.employee-status{border:0;border-radius:999px;padding:6px 11px;font:inherit;font-size:12px;font-weight:700;cursor:pointer}.employee-status.is-active{background:#e3f7e9;color:#157a39}.employee-status.is-passive{background:#f1f1f4;color:#6d6b78}.employee-status-form{display:inline}`;
      document.head.append(style);
    } catch (_) {
      // Durum alanı yüklenemediğinde mevcut çalışan listesi kullanılmaya devam eder.
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initEmployeeStatus);
  else initEmployeeStatus();
})();

// Çalışan formunu açılıp kapanabilir bir akordiyona dönüştürür.
(() => {
  function initEmployeeAccordion() {
    const page = document.querySelector('.employee-page');
    const card = page?.querySelector('.employee-card');
    const header = card?.querySelector(':scope > header');
    const form = card?.querySelector(':scope > .employee-form');
    if (!card || !header || !form || card.dataset.accordionReady) return;

    card.dataset.accordionReady = 'true';
    card.classList.add('employee-accordion');
    header.classList.add('employee-accordion-toggle');
    header.setAttribute('role', 'button');
    header.setAttribute('tabindex', '0');

    const chevron = document.createElement('span');
    chevron.className = 'employee-accordion-chevron';
    chevron.setAttribute('aria-hidden', 'true');
    chevron.textContent = '⌄';
    header.appendChild(chevron);

    const shouldOpen = new URLSearchParams(location.search).has('edit') || !!page.querySelector('.notice.error');
    card.classList.toggle('is-open', shouldOpen);
    header.setAttribute('aria-expanded', String(shouldOpen));

    const toggle = () => {
      const open = card.classList.toggle('is-open');
      header.setAttribute('aria-expanded', String(open));
    };
    header.addEventListener('click', toggle);
    header.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        toggle();
      }
    });

    const style = document.createElement('style');
    style.textContent = `
      .employee-accordion > .employee-accordion-toggle{display:flex;align-items:center;justify-content:space-between;gap:20px;cursor:pointer;user-select:none}
      .employee-accordion-chevron{font-size:28px;line-height:1;transition:transform .2s ease}
      .employee-accordion.is-open .employee-accordion-chevron{transform:rotate(180deg)}
      .employee-accordion:not(.is-open) > .employee-form{display:none}
      .employee-accordion > .employee-accordion-toggle:focus-visible{outline:2px solid #19a94b;outline-offset:-3px}
    `;
    document.head.appendChild(style);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initEmployeeAccordion);
  else initEmployeeAccordion();
})();

/* Profil güvenlik alanını Hesap paneline birleştir. */
(function(){
  function mergeProfileSecurity(){
    var accountPanel=document.getElementById('account-panel');
    var securityPanel=document.getElementById('security-panel');
    if(!accountPanel||!securityPanel)return;

    ['organization','address','state','zip_code','country','language','timezone','currency'].forEach(function(name){
      var field=accountPanel.querySelector('[name="'+name+'"]');
      var label=field&&field.closest('label');
      if(label)label.remove();
    });

    var securityTab=document.querySelector('.profile-tab[data-tab="security"]');
    if(securityTab)securityTab.remove();
    var profileTabs=document.querySelector('.profile-tabs');
    if(profileTabs)profileTabs.remove();

    var pageHeader=document.querySelector('body > header');
    if(pageHeader&&!pageHeader.querySelector('.profile-header-tools')){
      var brand=pageHeader.querySelector('.brand');
      if(brand){
        brand.removeAttribute('onclick');
        brand.innerHTML='';
        var brandLogo=document.createElement('img');
        brandLogo.src=new URL('assets/vox-logo-02.png',location.href).href;
        brandLogo.alt='VOX';brandLogo.className='profile-vox-logo';brand.appendChild(brandLogo);
      }
      var oldHome=pageHeader.querySelector('.profile-home-link');
      if(oldHome)oldHome.remove();
      var tools=document.createElement('div');tools.className='profile-header-tools';
      tools.innerHTML='<button class="profile-plain-tool profile-search-tool" type="button" title="Arama" aria-label="Arama">⌕</button><span class="profile-language">TR</span><button class="profile-plain-tool profile-theme-tool" type="button" title="Görünümü değiştir" aria-label="Görünümü değiştir">☼</button>';
      var account=document.createElement('div');account.className='profile-header-account';
      var accountButton=document.createElement('button');accountButton.type='button';accountButton.className='profile-header-account-button';accountButton.setAttribute('aria-expanded','false');
      var accountButtonStyle=document.createElement('style');
      accountButtonStyle.textContent='.profile-header-account-button,.profile-header-account-button:hover,.profile-header-account-button:focus,.profile-header-account-button:active{background:transparent!important;background-color:transparent!important;color:#242132!important;border-color:transparent!important;outline:0!important;box-shadow:none!important}[data-theme="dark"] .profile-header-account-button,[data-theme="dark"] .profile-header-account-button:hover,[data-theme="dark"] .profile-header-account-button:focus,[data-theme="dark"] .profile-header-account-button:active{background:transparent!important;background-color:transparent!important;color:#fff!important}';
      document.head.appendChild(accountButtonStyle);
      accountButtonStyle.textContent+='.profile-header-account-button{font-family:inherit!important;font-size:inherit!important;font-weight:400!important}.profile-header-identity{text-align:left!important;font-family:inherit!important}.profile-header-identity b{font-size:13px!important;font-weight:700!important;line-height:1.2!important;color:#444050!important}.profile-header-identity small{display:block!important;margin-top:2px!important;font-size:10px!important;font-weight:400!important;line-height:1.1!important;color:#7b7b8d!important}.profile-header-arrow{font-size:13px!important;font-weight:400!important;color:#444050!important}[data-theme="dark"] .profile-header-identity b,[data-theme="dark"] .profile-header-arrow{color:#fff!important}[data-theme="dark"] .profile-header-identity small{color:#a8aabd!important}';
      var avatar=document.createElement('span');avatar.className='profile-header-avatar';
      var photo=accountPanel.querySelector('.profile-photo img');
      if(photo){var avatarImage=photo.cloneNode();avatarImage.alt='Profil fotoğrafı';avatar.appendChild(avatarImage);}
      else{avatar.textContent=(accountPanel.querySelector('[name="name"]')?.value||'V').trim().charAt(0).toLocaleUpperCase('tr-TR');}
      var identity=document.createElement('span');identity.className='profile-header-identity';
      var identityName=document.createElement('b');identityName.textContent=accountPanel.querySelector('[name="name"]')?.value||'Vox Yöneticisi';
      var identityRole=document.createElement('small');identityRole.textContent='Admin';
      identity.append(identityName,identityRole);
      var arrow=document.createElement('span');arrow.className='profile-header-arrow';arrow.textContent='⌄';
      accountButton.append(avatar,identity,arrow);
      var accountMenu=document.createElement('div');accountMenu.className='profile-header-menu';
      var profileLink=document.createElement('a');profileLink.href=new URL('profile.php',location.href).href;profileLink.textContent='Profilim';
      var logoutLink=document.createElement('a');logoutLink.href=new URL('logout.php',location.href).href;logoutLink.className='logout';logoutLink.textContent='Çıkış yap';
      accountMenu.append(profileLink,logoutLink);account.append(accountButton,accountMenu);tools.appendChild(account);pageHeader.appendChild(tools);
      accountButton.addEventListener('click',function(event){event.stopPropagation();var open=account.classList.toggle('open');accountButton.setAttribute('aria-expanded',open?'true':'false');});
      document.addEventListener('click',function(){account.classList.remove('open');accountButton.setAttribute('aria-expanded','false');});
      accountMenu.addEventListener('click',function(event){event.stopPropagation();});
      var themeButton=tools.querySelector('.profile-theme-tool');
      function refreshProfileTheme(){var dark=document.documentElement.dataset.theme==='dark';themeButton.textContent=dark?'☾':'☼';}
      refreshProfileTheme();themeButton.addEventListener('click',function(){var next=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=next;localStorage.setItem('vox-theme',next);refreshProfileTheme();});
    }

    var profileForm=accountPanel.querySelector('.profile-form');
    var passwordForm=securityPanel.querySelector('.password-form');
    var profileActions=profileForm&&profileForm.querySelector('.profile-actions');
    if(profileForm&&passwordForm&&profileActions){
      var passwordHeading=document.createElement('div');
      passwordHeading.className='password-section-heading';
      passwordHeading.style.setProperty('border-top','0','important');
      passwordHeading.innerHTML='<h2>Şifre Değiştir</h2><p>Şifrenizi değiştirmek istemiyorsanız bu alanları boş bırakın.</p>';
      profileForm.insertBefore(passwordHeading,profileActions);
      Array.from(passwordForm.querySelectorAll('label')).forEach(function(label){
        var input=label.querySelector('input');
        if(input)input.required=false;
        profileForm.insertBefore(label,profileActions);
      });
    }
    securityPanel.remove();

    if(location.hash==='#security')history.replaceState(null,'',location.pathname+location.search+'#account');
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mergeProfileSecurity);
  else mergeProfileSecurity();
})();

// Vuexy-style sidebar: the control is inserted here so all shared PHP pages get it.
(() => {
  function initSidebar() {
    const sidebar = document.querySelector('.patient-nav');
    const topbar = document.querySelector('.patient-topbar');
    if (!sidebar || !topbar || sidebar.dataset.sidebarReady) return;
    sidebar.dataset.sidebarReady = 'true';

    const icons = {
      'Ana Sayfa': '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
      'Hasta': '<circle cx="12" cy="8" r="3"/><path d="M5 21c.5-4 2.8-6 7-6s6.5 2 7 6M18 8h4m-2-2v4"/>',
      'Takvim': '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>',
      'Kanban': '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 8v8M16 8v5"/>',
      'Takip': '<path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5M8 12l3 3 5-6"/>',
      'Satış': '<path d="M3 3h2l2.4 11.4a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 7H7"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/>',
      'Rapor': '<path d="M5 3h10l4 4v14H5zM15 3v5h5M8 17v-4M12 17V9M16 17v-2"/>',
      'Stok': '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9zM4 7.5 12 12l8-4.5M12 12v9"/>',
      'Ayar': '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.2 2.2-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5v.2h-3.2v-.2a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1-2.2-2.2.1-.1A1.7 1.7 0 0 0 6.6 15a1.7 1.7 0 0 0-1.5-1H5v-3.2h.2a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 2.2-2.2.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.5V4h3.2v.2a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1 2.2 2.2-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.5 1h.2V14h-.2a1.7 1.7 0 0 0-1.5 1z"/>',
      'Kurulum': '<path d="m14 6-4 4 4 4M3 12h11M14 18l4-4-4-4M21 3v4M19 5h4"/>'
    };
    sidebar.querySelectorAll('a').forEach(link => {
      const label = (link.textContent || '').trim();
      const key = Object.keys(icons).find(name => label.startsWith(name));
      const holder = link.querySelector(':scope > span');
      if (!key || !holder) return;
      holder.setAttribute('aria-hidden', 'true');
      holder.innerHTML = `<svg class="menu-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${icons[key]}</svg>`;
    });

    if (!document.getElementById('vuexy-tabler-icons')) {
      const iconStyles = document.createElement('link');
      iconStyles.id = 'vuexy-tabler-icons';
      iconStyles.rel = 'stylesheet';
      iconStyles.href = new URL('assets/vuexy-tabler-icons.css?v=2', location.href).href;
      document.head.appendChild(iconStyles);
    }
    if (!document.getElementById('vuexy-layout-fixes')) {
      const layoutStyles = document.createElement('link');
      layoutStyles.id = 'vuexy-layout-fixes';
      layoutStyles.rel = 'stylesheet';
      layoutStyles.href = new URL('assets/vuexy-layout-fixes.css?v=20', location.href).href;
      document.head.appendChild(layoutStyles);
    }
    const vuexyIcons = {
      'Ana Sayfa': 'tabler-smart-home', 'Hasta': 'tabler-layout-sidebar', 'Takvim': 'tabler-calendar',
      'Kanban': 'tabler-layout-kanban', 'Takip': 'tabler-refresh', 'Satış': 'tabler-shopping-cart', 'Rapor': 'tabler-file-report', 'Stok': 'tabler-package',
      'Ayar': 'tabler-settings', 'Kurulum': 'tabler-tools'
    };
    sidebar.querySelectorAll('a').forEach(link => {
      const label = (link.textContent || '').trim();
      const key = Object.keys(vuexyIcons).find(name => label.startsWith(name));
      const holder = link.querySelector(':scope > span');
      if (key && holder) holder.innerHTML = `<i class="ti ${vuexyIcons[key]}"></i>`;
    });
    sidebar.querySelectorAll('.report-submenu a > span').forEach(icon => icon.remove());
    const taskLink = sidebar.querySelector('a[href*="kanban.php"]');
    if (taskLink && taskLink.lastChild?.nodeType === Node.TEXT_NODE) taskLink.lastChild.textContent = ' Görev Takip';

    const button = topbar.querySelector('.patient-brand');
    if (!button) return;
    button.setAttribute('aria-label', 'Menüyü aç veya kapat');
    button.setAttribute('aria-expanded', 'true');

    const mobile = () => window.matchMedia('(max-width: 900px)').matches;
    const saved = localStorage.getItem('vox-sidebar-collapsed') === 'true';
    function closeSubmenus() {
      sidebar.querySelectorAll('.report-menu-group.open').forEach(group => {
        group.classList.remove('open');
        group.querySelector(':scope > a')?.setAttribute('aria-expanded', 'false');
      });
    }
    document.body.classList.add('layout-navbar-fixed', 'layout-compact', 'layout-menu-fixed');
    if (!mobile() && saved) {
      document.body.classList.add('menu-collapsed', 'layout-menu-collapsed');
      closeSubmenus();
    }

    function syncLayoutClasses() {
      const collapsed = document.body.classList.contains('menu-collapsed');
      document.body.classList.toggle('layout-menu-collapsed', collapsed);
      document.body.classList.toggle('layout-menu-expanded', !collapsed);
    }
    syncLayoutClasses();
    sidebar.addEventListener('click', event => {
      const groupLink = event.target.closest('.report-menu-group > a');
      if (!mobile() && groupLink && document.body.classList.contains('menu-collapsed')) {
        event.preventDefault();
        event.stopImmediatePropagation();
        document.body.classList.remove('menu-collapsed');
        localStorage.setItem('vox-sidebar-collapsed', 'false');
        closeSubmenus();
        syncLayoutClasses();
        update();
      }
    }, true);

    function update() {
      const open = mobile() ? document.body.classList.contains('menu-open') : !document.body.classList.contains('menu-collapsed');
      button.setAttribute('aria-expanded', String(open));
      button.setAttribute('aria-label', open ? 'Menüyü gizle' : 'Menüyü göster');
    }
    button.addEventListener('click', event => {
      event.preventDefault();
      if (mobile()) document.body.classList.toggle('menu-open');
      else {
        document.body.classList.toggle('menu-collapsed');
        if (document.body.classList.contains('menu-collapsed')) closeSubmenus();
        localStorage.setItem('vox-sidebar-collapsed', String(document.body.classList.contains('menu-collapsed')));
      }
      syncLayoutClasses();
      update();
    });
    document.addEventListener('click', event => {
      if (mobile() && document.body.classList.contains('menu-open') && !sidebar.contains(event.target) && !button.contains(event.target)) {
        document.body.classList.remove('menu-open');
        update();
      }
    });
    window.addEventListener('resize', () => {
      document.body.classList.remove('menu-open');
      if (!mobile() && localStorage.getItem('vox-sidebar-collapsed') === 'true') {
        document.body.classList.add('menu-collapsed');
        closeSubmenus();
      }
      syncLayoutClasses();
      update();
    });
    update();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initSidebar);
  else initSidebar();
})();
