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
