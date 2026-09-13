(() => {
  'use strict';

  const ready = callback => document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', callback, {once:true})
    : callback();

  ready(() => {
    if (!document.body?.matches('#vox-app') || document.body.classList.contains('vox-embedded-window') || !window.matchMedia('(min-width:901px)').matches) return;

    const nav = document.querySelector('.patient-nav');
    const taskbar = document.querySelector('.desktop-taskbar');
    const clock = taskbar?.querySelector('.desktop-clock');
    if (!nav || !taskbar) return;

    const updateClock = () => {
      if (!clock) return;
      clock.textContent = new Intl.DateTimeFormat('tr-TR', {
        hour: '2-digit', minute: '2-digit', hour12: false,
      }).format(new Date());
    };
    updateClock();
    window.setInterval(updateClock, 1000);

    const workspace = document.createElement('div');
    workspace.className = 'vox-window-workspace';
    document.body.append(workspace);

    const records = new Map();
    let sequence = 0;
    let zIndex = 90;

    const windowUrl = raw => {
      const url = new URL(raw, location.href);
      url.searchParams.set('_vox_window', '1');
      return url;
    };
    const recordKey = raw => {
      const url = new URL(raw, location.href);
      url.searchParams.delete('_vox_window');
      if (/\/(?:brands|cash-categories|service-names|service-types|admin|branches|employees|social-securities|sources|complaints|banks|anamnesis-questions)\.php$/i.test(url.pathname)) return 'vox:setup-window';
      if (/\/patient-form\.php$/i.test(url.pathname)) {
        const id = url.searchParams.get('id') || 'new';
        return url.pathname + '?patient=' + encodeURIComponent(id);
      }
      return url.pathname + '?' + url.searchParams.toString();
    };
    const safeTitle = value => String(value || 'VOX Penceresi').replace(/\s+/g,' ').trim();

    const fitFormWindow = record => {
      if (record.window.classList.contains('vox-mdi-minimized') || (record.window.classList.contains('vox-mdi-maximized') && !record.window.classList.contains('vox-mdi-content-height'))) return;
      try {
        const doc = record.frame.contentDocument;
        if (!doc?.body || doc.body.classList.contains('vox-classic-list-page')) return;
        const main = doc.querySelector('main');
        if (main?.matches('.datatable-page')) return;
        const compactForm = main?.querySelector('form.vox-compact-form');
        const patientForm = main?.matches('main.patient-form-page') && main.querySelector('form.classic-patient-form');
        const cardForm = compactForm || patientForm || main?.querySelector('form');
        if (!main || !cardForm) return;
        const mainRect = main.getBoundingClientRect();
        const measuredChildren = [...cardForm.children];
        const visibleChildren = measuredChildren.filter(child => child.getClientRects().length && !child.matches('script,style,input[type="hidden"]'));
        if (!visibleChildren.length) return;
        const contentBottom = Math.max(...visibleChildren.map(child => child.getBoundingClientRect().bottom));
        const mainStyle = doc.defaultView.getComputedStyle(main);
        const formStyle = doc.defaultView.getComputedStyle(cardForm);
        const bottomSpace = Math.max(parseFloat(mainStyle.paddingBottom || '0'), parseFloat(formStyle.paddingBottom || '0'));
        const frameHeight = Math.ceil(contentBottom - mainRect.top + bottomSpace + 2);
        const available = Math.max(350, innerHeight - 31 - record.window.offsetTop);
        const fittedHeight = Math.min(available, Math.max(350, frameHeight + 31));
        if (record.window.classList.contains('vox-mdi-content-height')) record.window.style.setProperty('--vox-mdi-content-height', fittedHeight + 'px');
        else record.window.style.height = fittedHeight + 'px';
        if (record.centerAfterFit && !record.window.classList.contains('vox-mdi-maximized')) {
          const availableWidth = Math.max(620, innerWidth - 40);
          const fittedWidth = Math.min(1120, availableWidth);
          record.window.style.width = fittedWidth + 'px';
          record.window.style.left = Math.max(0, Math.round((innerWidth - fittedWidth) / 2)) + 'px';
          record.window.style.top = Math.max(0, Math.round((innerHeight - 31 - fittedHeight) / 2)) + 'px';
        }
      } catch (_) {}
    };

    const setActive = record => {
      records.forEach(item => {
        item.window.classList.toggle('vox-mdi-active', item === record);
        item.task.classList.toggle('vox-mdi-task-active', item === record);
      });
      record.window.style.zIndex = String(++zIndex);
    };

    const minimize = record => {
      record.window.classList.add('vox-mdi-minimized');
      record.task.classList.add('vox-mdi-task-minimized');
      record.task.classList.remove('vox-mdi-task-active');
    };
    const restore = record => {
      record.window.classList.remove('vox-mdi-minimized');
      record.task.classList.remove('vox-mdi-task-minimized');
      setActive(record);
    };
    const close = record => {
      record.window.remove();
      record.task.remove();
      records.delete(record.key);
    };

    const prepareFrame = record => {
      try {
        const doc = record.frame.contentDocument;
        if (!doc?.body) return;
        doc.body.classList.add('vox-embedded-window');
        let contextTitle = doc.querySelector('.page-context strong')?.textContent || doc.title?.replace(/\s*\|.*$/,'');
        const framePath = record.frame.contentWindow?.location?.pathname || '';
        const frameSearch = record.frame.contentWindow?.location?.search || '';
        const isPatientList = /\/patients\.php$/i.test(framePath);
        const isCalendar = /\/calendar\.php$/i.test(framePath);
        const isKanban = /\/kanban\.php$/i.test(framePath);
        const isListsMenuPage = /\/(?:hearing-devices|sales|result-list|sgk-list)\.php$/i.test(framePath);
        const isStockMenuPage = /\/(?:stock-exit|stocks|price-lists|stock-prices|invoice-list)\.php$/i.test(framePath);
        const isTechnicalService = /\/technical-service\.php$/i.test(framePath);
        const isPreCash = /\/cash-pre\.php$/i.test(framePath);
        const isCurrentAccountsPage = /\/(?:current-accounts|current-account-movements|current-account-documents|company-finance)\.php$/i.test(framePath);
        const isAppointmentForm = /\/appointment-form\.php$/i.test(framePath);
        const isExternalPatientWindow = /\/external-technical-patient\.php$/i.test(framePath);
        const isExternalRepairWindow = /\/external-technical-repair\.php$/i.test(framePath);
        const isUnitsPage = /\/(?:units|unit-patients|unit-visits)\.php$/i.test(framePath);
  const isSettingsPage = /\/(?:admin|branches|employees|social-securities|sources|complaints|banks|anamnesis-questions|anamnesis-designer(?:-v2)?)\.php$/i.test(framePath);
  const isSetupPage = /\/(?:brands|cash-categories|service-names|service-types)\.php$/i.test(framePath);
        const isDefaultMaximized = isPatientList || isCalendar || isKanban || isListsMenuPage || isStockMenuPage || isTechnicalService || isPreCash || isCurrentAccountsPage || isUnitsPage || isSettingsPage || isSetupPage;
        const isSalesWindow = /(?:^|[?&])sales_window=1(?:&|$)/.test(frameSearch);
        const isAnamnesisWindow = /(?:^|[?&])anamnesis_window=1(?:&|$)/.test(frameSearch);
        record.centerAfterFit = isExternalPatientWindow;
        if (isAnamnesisWindow) {
          record.lockHorizontalResize = true;
          record.window.classList.add('vox-mdi-vertical-resize-only');
          const maximizeButton = record.window.querySelector('[data-mdi-action="maximize"]');
          if (maximizeButton) { maximizeButton.hidden = true; maximizeButton.disabled = true; }
        }
        if (isDefaultMaximized && !record.defaultMaximized) {
          record.defaultMaximized = true;
          requestAnimationFrame(() => record.window.querySelector('[data-mdi-action="maximize"]')?.click());
        } else if (!isDefaultMaximized && record.window.classList.contains('vox-mdi-maximized')) {
          record.defaultMaximized = false;
          requestAnimationFrame(() => record.window.querySelector('[data-mdi-action="maximize"]')?.click());
        }
        if (isSalesWindow || isAnamnesisWindow) {
          const centerCardWindow = () => {
            if (record.window.classList.contains('vox-mdi-maximized')) return;
            const availableWidth = Math.max(620, window.innerWidth - 32);
            const availableHeight = Math.max(240, window.innerHeight - 55);
            const width = Math.min(isAnamnesisWindow ? 940 : 1180, availableWidth);
            const height = Math.min(isAnamnesisWindow ? 820 : 780, availableHeight);
            record.window.classList.remove('vox-mdi-content-height');
            record.window.style.width = width + 'px';
            record.window.style.height = height + 'px';
            record.window.style.minHeight = Math.min(350, availableHeight) + 'px';
            record.window.style.left = Math.max(0, Math.round((window.innerWidth - width) / 2)) + 'px';
            record.window.style.top = Math.max(0, Math.round((window.innerHeight - 31 - height) / 2)) + 'px';
          };
          [0,80,220].forEach(delay => setTimeout(centerCardWindow, delay));
        }
        const patientName = doc.querySelector('input[name="full_name"]')?.value?.replace(/\s+/g,' ').trim();
        if (/\/patient-form\.php$/i.test(framePath) && patientName) contextTitle = `Hasta Kartı - ${patientName}`;
        const salesWindowTitle = doc.querySelector('#sales-details-title')?.textContent?.replace(/\s+/g,' ').trim();
        const anamnesisPatientName = doc.querySelector('#anamnesis-card-modal .anamnesis-meta strong')?.textContent?.replace(/\s+/g,' ').trim();
        const serviceFormTitle = doc.querySelector('#service-card-form')?.previousElementSibling?.querySelector('h2')?.textContent?.replace(/\s+/g,' ').trim();
        if (/\/patient-followup\.php$/i.test(framePath) && isSalesWindow && salesWindowTitle) contextTitle = salesWindowTitle;
        else if (/\/patient-followup\.php$/i.test(framePath) && isAnamnesisWindow) contextTitle = 'Anamnez' + (anamnesisPatientName ? ' - ' + anamnesisPatientName : '');
        else if (/\/patient-followup\.php$/i.test(framePath) && serviceFormTitle) contextTitle = serviceFormTitle;
        if (contextTitle) {
          record.title.textContent = safeTitle(contextTitle);
          record.task.textContent = safeTitle(contextTitle);
          record.task.title = safeTitle(contextTitle);
        }
        doc.querySelectorAll('form').forEach(form => {
          if (form.querySelector('input[name="_vox_window"]')) return;
          const hidden = doc.createElement('input');
          hidden.type = 'hidden'; hidden.name = '_vox_window'; hidden.value = '1';
          form.append(hidden);
        });
        doc.addEventListener('click', event => {
          const link = event.target.closest?.('a[href]');
          if (!link || link.hasAttribute('download') || link.target === '_blank') return;
          try {
            const url = new URL(link.href, record.frame.contentWindow.location.href);
            if (url.origin !== location.origin || !/\.php$/i.test(url.pathname)) return;
            if (link.matches('.calendar-add')) {
              event.preventDefault();
              event.stopPropagation();
              openWindow(url.href, link.textContent);
              return;
            }
            url.searchParams.set('_vox_window','1');
            link.href = url.href;
          } catch (_) {}
        }, true);
        if (isAppointmentForm && record.waitForInitialFit) {
          fitFormWindow(record);
          record.waitForInitialFit = false;
          requestAnimationFrame(() => record.window.classList.remove('vox-mdi-sizing'));
        }
        if (!isSalesWindow && !isAnamnesisWindow && !isExternalRepairWindow) [40,120,300,700,1400].forEach(delay => requestAnimationFrame(() => setTimeout(() => fitFormWindow(record), delay)));
      } catch (_) {}
    };

    const openWindow = (rawUrl, rawTitle) => {
      const key = recordKey(rawUrl);
      const existing = records.get(key);
      if (existing) {
        // Bir form kaydedildikten sonra iframe liste görünümüne yönlenebilir;
        // kayıt anahtarı ise önceki düzenleme URL'sinde kalır. Aynı Düzenle
        // bağlantısına basıldığında eski pencereyi yalnızca öne getirmek yerine
        // istenen kartı yeniden yükle.
        try {
          if (recordKey(existing.frame.contentWindow.location.href) !== key) {
            existing.frame.src = windowUrl(rawUrl).href;
          }
        } catch (_) {
          existing.frame.src = windowUrl(rawUrl).href;
        }
        if (key === 'vox:setup-window') {
          const requestedUrl = windowUrl(rawUrl).href;
          if (existing.frame.src !== requestedUrl) existing.frame.src = requestedUrl;
          existing.title.textContent = safeTitle(rawTitle);
          existing.task.textContent = safeTitle(rawTitle);
          existing.task.title = safeTitle(rawTitle);
        }
        restore(existing);
        return existing;
      }

      const id = ++sequence;
      const titleText = safeTitle(rawTitle);
      const panel = document.createElement('section');
      panel.className = 'vox-mdi-window vox-mdi-active';
      let initialPath = '';
      try { initialPath = new URL(rawUrl, location.href).pathname; } catch (_) {}
      const waitForInitialFit = /\/appointment-form\.php$/i.test(initialPath);
      if (waitForInitialFit) panel.classList.add('vox-mdi-sizing');
      panel.dataset.windowId = String(id);
      const offset = (id - 1) % 7;
      panel.style.left = (8 + offset * 2.1) + 'vw';
      panel.style.top = (5 + offset * 2.5) + 'vh';
      panel.innerHTML = '<div class="vox-mdi-titlebar"><span class="vox-mdi-title"></span></div><div class="vox-mdi-controls"><button type="button" data-mdi-action="minimize" title="Simge durumuna küçült" aria-label="Simge durumuna küçült">_</button><button type="button" data-mdi-action="maximize" title="Büyüt" aria-label="Büyüt">□</button><button type="button" data-mdi-action="close" title="Kapat" aria-label="Kapat">×</button></div><iframe class="vox-mdi-frame" title=""></iframe><span class="vox-mdi-resizer" title="Pencere boyutunu değiştir"></span>';
      const title = panel.querySelector('.vox-mdi-title');
      const frame = panel.querySelector('.vox-mdi-frame');
      title.textContent = titleText;
      frame.title = titleText;
      frame.src = windowUrl(rawUrl).href;

      const task = document.createElement('button');
      task.type = 'button';
      task.className = 'vox-mdi-task vox-mdi-task-active';
      task.textContent = titleText;
      task.title = titleText;
      taskbar.insertBefore(task, taskbar.querySelector('.vox-version-tools') || clock || null);
      workspace.append(panel);

      const record = {id,key,window:panel,title,frame,task,restoreStyle:null,waitForInitialFit,centerAfterFit:false};
      records.set(key,record);
      frame.addEventListener('load', () => prepareFrame(record));
      if (waitForInitialFit) setTimeout(() => panel.classList.remove('vox-mdi-sizing'), 1200);
      panel.addEventListener('pointerdown', () => setActive(record));
      task.addEventListener('click', () => panel.classList.contains('vox-mdi-minimized') ? restore(record) : (panel.classList.contains('vox-mdi-active') ? minimize(record) : setActive(record)));

      panel.querySelector('.vox-mdi-controls').addEventListener('click', event => {
        const button = event.target.closest('button');
        const action = button?.dataset.mdiAction;
        if (action === 'minimize') minimize(record);
        if (action === 'close') {
          let returnedToAccounts = false;
          try {
            const frameLocation = new URL(record.frame.contentWindow.location.href);
            if (frameLocation.pathname.endsWith('/company-finance.php') || (frameLocation.pathname.endsWith('/current-accounts.php') && (frameLocation.searchParams.has('edit') || record.frame.contentDocument.querySelector('.new-account-card[open]')))) {
              frameLocation.pathname = frameLocation.pathname.replace(/[^/]+$/, 'current-accounts.php');
              frameLocation.search = '?_vox_window=1';
              frameLocation.hash = '';
              record.frame.contentWindow.location.assign(frameLocation.href);
              returnedToAccounts = true;
            }
          } catch (_) {}
          if (!returnedToAccounts) close(record);
        }
        if (action === 'maximize') {
          const maximizing = !panel.classList.contains('vox-mdi-maximized');
          const frameDoc = frame.contentDocument;
          const frameMain = frameDoc?.querySelector('main');
          const fitContentHeight = maximizing && !!frameMain && !frameDoc?.body?.classList.contains('vox-classic-list-page') && !frameMain.matches('.datatable-page');
          if (maximizing) record.restoreStyle = panel.getAttribute('style');
          panel.classList.toggle('vox-mdi-content-height', fitContentHeight);
          panel.classList.toggle('vox-mdi-maximized', maximizing);
          if (maximizing) panel.removeAttribute('style');
          else {
            panel.classList.remove('vox-mdi-content-height');
            panel.style.removeProperty('--vox-mdi-content-height');
            if (record.restoreStyle !== null) panel.setAttribute('style',record.restoreStyle);
          }
          button.textContent = maximizing ? '❐' : '□';
          button.title = maximizing ? 'Geri al' : 'Büyüt';
          setActive(record);
          if (fitContentHeight) [0,40,120].forEach(delay => setTimeout(() => fitFormWindow(record), delay));
        }
      });

      let interaction = null;
      const titlebar = panel.querySelector('.vox-mdi-titlebar');
      titlebar.addEventListener('pointerdown', event => {
        if (panel.classList.contains('vox-mdi-maximized')) return;
        const rect = panel.getBoundingClientRect();
        interaction = {mode:'move',x:event.clientX,y:event.clientY,left:rect.left,top:rect.top};
        titlebar.setPointerCapture(event.pointerId);
        document.body.classList.add('vox-mdi-dragging');
        setActive(record);
      });
      titlebar.addEventListener('pointermove', event => {
        if (!interaction || interaction.mode !== 'move') return;
        const left = Math.max(0,Math.min(innerWidth-panel.offsetWidth,interaction.left+event.clientX-interaction.x));
        const top = Math.max(0,Math.min(innerHeight-62,interaction.top+event.clientY-interaction.y));
        panel.style.left = left+'px'; panel.style.top = top+'px';
      });
      const stopMove = () => { interaction=null; document.body.classList.remove('vox-mdi-dragging'); };
      titlebar.addEventListener('pointerup',stopMove); titlebar.addEventListener('pointercancel',stopMove);
      titlebar.addEventListener('dblclick',() => panel.querySelector('[data-mdi-action="maximize"]')?.click());

      const resizer = panel.querySelector('.vox-mdi-resizer');
      resizer.addEventListener('pointerdown', event => {
        if (panel.classList.contains('vox-mdi-maximized')) return;
        const rect = panel.getBoundingClientRect();
        interaction = {mode:'resize',x:event.clientX,y:event.clientY,width:rect.width,height:rect.height};
        resizer.setPointerCapture(event.pointerId);
        document.body.classList.add('vox-mdi-resizing');
        setActive(record);
      });
      resizer.addEventListener('pointermove', event => {
        if (!interaction || interaction.mode !== 'resize') return;
        if (!record.lockHorizontalResize) panel.style.width = Math.max(620,Math.min(innerWidth-panel.offsetLeft,interaction.width+event.clientX-interaction.x))+'px';
        panel.style.height = Math.max(350,Math.min(innerHeight-31-panel.offsetTop,interaction.height+event.clientY-interaction.y))+'px';
      });
      const stopResize = () => { interaction=null; document.body.classList.remove('vox-mdi-resizing'); };
      resizer.addEventListener('pointerup',stopResize); resizer.addEventListener('pointercancel',stopResize);
      setActive(record);
      return record;
    };

    nav.addEventListener('click', event => {
      const link = event.target.closest('a[href]');
      if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      const href = link.getAttribute('href') || '';
      if (href === '#' || href.startsWith('javascript:')) return;
      let url;
      try { url = new URL(link.href,location.href); } catch (_) { return; }
      if (url.origin !== location.origin || !/\.php$/i.test(url.pathname) || /\/(?:logout|login)\.php$/i.test(url.pathname)) return;
      event.preventDefault();
      event.stopPropagation();
      document.body.classList.remove('desktop-menu-open');
      openWindow(url.href,link.textContent);
    }, true);

    window.addEventListener('message', event => {
      if (event.origin !== location.origin) return;
      if (event.data?.type === 'vox-patient-saved') {
        let sourceRecord = null;
        let patientListRecord = null;
        for (const record of records.values()) {
          if (record.frame.contentWindow === event.source) sourceRecord = record;
          try {
            if (/\/patients\.php$/i.test(record.frame.contentWindow?.location?.pathname || '')) {
              patientListRecord = record;
              record.frame.contentWindow.location.reload();
            }
          } catch (_) {}
        }
        if (sourceRecord) close(sourceRecord);
        if (patientListRecord) setActive(patientListRecord);
        return;
      }
      if (event.data?.type === 'vox-return-window') {
        let url;
        try { url = new URL(event.data.url,location.href); } catch (_) { return; }
        if (url.origin !== location.origin || !/\.php$/i.test(url.pathname)) return;
        let sourceRecord = null;
        for (const record of records.values()) {
          if (record.frame.contentWindow === event.source) { sourceRecord = record; break; }
        }
        const targetRecord = openWindow(url.href,event.data.title);
        if (event.data.refresh && targetRecord !== sourceRecord) {
          try { targetRecord.frame.contentWindow.location.reload(); }
          catch (_) { targetRecord.frame.src = windowUrl(url.href).href; }
        }
        if (sourceRecord && sourceRecord !== targetRecord) close(sourceRecord);
        restore(targetRecord);
        return;
      }
      if (event.data?.type === 'vox-close-window') {
        for (const record of records.values()) {
          if (record.frame.contentWindow === event.source) { close(record); break; }
        }
        return;
      }
      if (event.data?.type === 'vox-fit-content-window') {
        let sourceRecord = null;
        for (const record of records.values()) {
          if (record.frame.contentWindow === event.source) { sourceRecord = record; break; }
        }
        if (!sourceRecord) return;
        restore(sourceRecord);
        if (sourceRecord.window.classList.contains('vox-mdi-maximized')) {
          sourceRecord.window.classList.remove('vox-mdi-maximized','vox-mdi-content-height');
          sourceRecord.window.style.removeProperty('--vox-mdi-content-height');
          if (sourceRecord.restoreStyle !== null) sourceRecord.window.setAttribute('style',sourceRecord.restoreStyle);
          sourceRecord.window.querySelector('[data-mdi-action="maximize"]').textContent = '□';
        }
        const margin = 7;
        const requestedHeight = Number(event.data.height) || 320;
        const requestedWidth = Number(event.data.width) || sourceRecord.window.offsetWidth;
        const width = Math.max(620, Math.min(innerWidth - margin * 2, requestedWidth));
        const height = Math.max(260, Math.min(innerHeight - 31 - margin * 2, requestedHeight));
        sourceRecord.window.style.minHeight = '260px';
        sourceRecord.window.style.width = width + 'px';
        sourceRecord.window.style.height = height + 'px';
        sourceRecord.window.style.top = Math.max(margin, Math.round((innerHeight - 31 - height) / 2)) + 'px';
        sourceRecord.window.style.left = Math.max(0, Math.round((innerWidth - width) / 2)) + 'px';
        setActive(sourceRecord);
        return;
      }
      if (event.data?.type !== 'vox-open-window') return;
      let url;
      try { url = new URL(event.data.url,location.href); } catch (_) { return; }
      if (url.origin !== location.origin || !/\.php$/i.test(url.pathname)) return;
      openWindow(url.href,event.data.title);
    });

    document.querySelector('.desktop-task-title')?.addEventListener('click', () => {
      records.forEach(record => {
        record.window.classList.remove('vox-mdi-active');
        record.task.classList.remove('vox-mdi-task-active');
      });
    });
    window.voxOpenWindow = openWindow;
  });
})();
