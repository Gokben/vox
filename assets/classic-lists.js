(() => {
  'use strict';

  const onReady = callback => document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', callback, {once:true})
    : callback();

  onReady(() => {
    if (!document.body?.matches('#vox-app') || !window.matchMedia('(min-width:901px)').matches) return;

    const main = document.querySelector('body#vox-app > main');
    if (!main) return;
    const file = (location.pathname.split('/').pop() || '').toLowerCase();
    const query = new URLSearchParams(location.search);
    const sortFirstPages = new Set(['social-securities.php','sources.php','complaints.php','banks.php','anamnesis-questions.php']);
    if (sortFirstPages.has(file)) {
      try {
        const migrationKey = 'vox.settings.sort-first.v1';
        const migrated = JSON.parse(localStorage.getItem(migrationKey) || '[]');
        if (!migrated.includes(file)) {
          localStorage.removeItem(`vox.classic.widths.${file}.0`);
          localStorage.removeItem(`vox.classic.columns.${file}.0`);
          migrated.push(file);
          localStorage.setItem(migrationKey, JSON.stringify(migrated));
        }
      } catch (_) {}
    }
    const listFiles = new Set([
      'appointment-list.php','anamnesis-questions.php','admin.php','banks.php','branches.php','brands.php',
      'cash-categories.php','cash.php','company-patients.php','complaints.php',
      'company-finance.php','current-account-documents.php','current-account-movements.php','current-accounts.php','daily-events-list.php',
      'employees.php','hearing-devices.php','charger-devices.php','invoice-list.php','invoice-list-v2.php','invoice-list-v3.php','models.php',
      'patient-results.php','patients.php','price-lists.php','result-list.php','sales.php','service-names.php',
      'service-types.php','sgk-list.php','social-securities.php','sources.php','stock-movements.php','stock-prices.php',
      'stock-exit.php','stocks.php','technical-service.php','unit-patients.php','unit-visits.php','units-card.php','units-card-v2.php'
    ]);
    if (file === 'stock-entry.php' && !query.has('new') && !query.has('edit')) listFiles.add(file);
    if (file === 'units.php' && !query.has('new') && !query.has('edit')) listFiles.add(file);
    if (file === 'companies.php' && !query.has('new') && !query.has('edit')) listFiles.add(file);
    if (file === 'company-visits.php' && !query.has('new') && !query.has('edit')) listFiles.add(file);

    const classSignal = /(?:^|\s)[a-z0-9_-]*(?:list|datatable)(?:-page)?(?:\s|$)/i.test(String(main.className || ''));
    const isListPage = listFiles.has(file) || classSignal;
    if (!isListPage) return;

    document.querySelector('.vox-work-window-close')?.remove();
    const removeListButtons = root => {
      root.querySelectorAll?.('button,a').forEach(control => {
        if (String(control.textContent || '').replace(/\s+/g,' ').trim().toLocaleLowerCase('tr-TR') === 'listele') control.remove();
      });
    };
    removeListButtons(main);
    new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
      if (!(node instanceof Element)) return;
      if (node.matches('button,a') && String(node.textContent || '').replace(/\s+/g,' ').trim().toLocaleLowerCase('tr-TR') === 'listele') node.remove();
      else removeListButtons(node);
    }))).observe(main,{childList:true,subtree:true});
    if (file === 'patients.php') {
      main.classList.add('vox-patient-classic-list');
    }

    const tables = [...main.querySelectorAll('table')].filter(table => table.tHead?.rows?.[0]?.cells?.length >= 2 && !table.closest('[role="dialog"],.modal') && !table.classList.contains('no-classic-list'));
    if (!tables.length) return;
    document.body.classList.add('vox-classic-list-page');

    const normalize = value => String(value ?? '').trim().toLocaleLowerCase('tr-TR');
    const sortableValue = value => {
      const text = normalize(value);
      const date = text.match(/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/);
      if (date) return {type:'number', value:Number(`${date[3]}${date[2].padStart(2,'0')}${date[1].padStart(2,'0')}`)};
      const numeric = text.replace(/\s/g,'').replace(/\./g,'').replace(',','.');
      if (/^-?\d+(?:\.\d+)?$/.test(numeric)) return {type:'number', value:Number(numeric)};
      return {type:'text', value:text};
    };

    const menu = document.createElement('div');
    menu.className = 'vox-shared-column-menu';
    menu.hidden = true;
    document.body.append(menu);
    let activeHeader = null;

    const hideMenu = () => {
      menu.hidden = true;
      activeHeader?.classList.remove('vox-column-open');
      activeHeader = null;
    };

    const placeMenu = button => {
      const rect = button.getBoundingClientRect();
      menu.hidden = false;
      const menuRect = menu.getBoundingClientRect();
      menu.style.left = Math.max(4, Math.min(innerWidth - menuRect.width - 4, rect.right - menuRect.width)) + 'px';
      menu.style.top = Math.max(4, Math.min(innerHeight - menuRect.height - 4, rect.bottom + 2)) + 'px';
    };

    const sortTable = (table, index, direction) => {
      const body = table.tBodies[0];
      if (!body) return;
      const rows = [...body.rows].filter(row => row.cells.length > index && !row.querySelector('td[colspan]'));
      rows.sort((a,b) => {
        const av = sortableValue(a.cells[index]?.textContent);
        const bv = sortableValue(b.cells[index]?.textContent);
        const compare = av.type === 'number' && bv.type === 'number'
          ? av.value - bv.value
          : String(av.value).localeCompare(String(bv.value), 'tr', {numeric:true, sensitivity:'base'});
        return direction === 'asc' ? compare : -compare;
      });
      rows.forEach(row => body.append(row));
    };

    tables.forEach((table, tableIndex) => {
      table.classList.add('vox-classic-table');
      if (main.classList.contains('setup-page')) table.classList.add('vox-custom-column-sizing','vox-setup-fixed-list');
      const customColumnSizing = table.classList.contains('vox-custom-column-sizing');
      const scrollHost = table.parentElement;
      if (scrollHost) scrollHost.classList.add('vox-classic-scroll');
      const headers = [...table.tHead.rows[0].cells];
      const storageKey = `vox.classic.columns.${file}.${tableIndex}`;
      const widthStorageKey = `vox.classic.widths.${file}.${tableIndex}`;
      let saved = [];
      let savedWidths = [];
      try {
        saved = JSON.parse(localStorage.getItem(storageKey) || '[]');
        savedWidths = JSON.parse(localStorage.getItem(widthStorageKey) || '[]');
      } catch (_) {}

      // Preserve existing patient column choices when adding the service-name column.
      if (file === 'patients.php' && headers.length === 18) {
        if (Array.isArray(saved) && saved.length === 17) { saved.splice(13,0,true); try { localStorage.setItem(storageKey,JSON.stringify(saved)); } catch (_) {} }
        if (Array.isArray(savedWidths) && savedWidths.length === 17) { savedWidths.splice(13,0,130); try { localStorage.setItem(widthStorageKey,JSON.stringify(savedWidths)); } catch (_) {} }
      }

      const setColumnVisible = (index, visible) => {
        [...table.rows].forEach(row => {
          const cell = row.cells[index];
          if (!cell) return;
          cell.classList.toggle('vox-column-hidden', !visible);
          cell.style.removeProperty('display');
        });
      };
      if (Array.isArray(saved) && saved.length === headers.length) saved.forEach((visible,index) => setColumnVisible(index, Boolean(visible)));
      if (!customColumnSizing && Array.isArray(savedWidths) && savedWidths.length === headers.length && savedWidths.some(Boolean)) {
        table.style.setProperty('table-layout','fixed','important');
        headers.forEach((header,index) => {
          const width = Number(savedWidths[index]);
          if (!Number.isFinite(width) || width < 45) return;
          header.style.setProperty('width',width + 'px','important');
          header.style.setProperty('min-width',width + 'px','important');
          header.style.setProperty('max-width',width + 'px','important');
        });
        const total = savedWidths.reduce((sum,width) => sum + (Number(width) || 0), 0);
        if (total > 0) table.style.setProperty('width',Math.max(scrollHost?.clientWidth || 0,total) + 'px','important');
      }

      headers.forEach((header, index) => {
        header.style.position = 'sticky';
        const title = header.textContent.trim() || `Sütun ${index + 1}`;
        header.dataset.voxColumnTitle = title;

        const lockedActionsColumn = (file === 'patients.php' || customColumnSizing) && index === headers.length - 1;
        if (lockedActionsColumn) {
          header.classList.add('vox-fixed-actions-column');
          return;
        }

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'vox-shared-column-trigger';
        trigger.textContent = '▼';
        trigger.title = `${title} sütun işlemleri`;
        trigger.setAttribute('aria-label', `${title} sütun işlemleri`);
        header.append(trigger);

        trigger.addEventListener('click', event => {
          event.preventDefault();
          event.stopPropagation();
          activeHeader?.classList.remove('vox-column-open');
          activeHeader = header;
          header.classList.add('vox-column-open');
          menu.replaceChildren();

          const asc = document.createElement('button');
          asc.type = 'button'; asc.textContent = 'A ↧  Artan sırada sırala';
          const desc = document.createElement('button');
          desc.type = 'button'; desc.textContent = 'Z ↥  Azalan sırada sırala';
          asc.addEventListener('click', () => { sortTable(table,index,'asc'); hideMenu(); });
          desc.addEventListener('click', () => { sortTable(table,index,'desc'); hideMenu(); });
          menu.append(asc, desc, document.createElement('hr'));

          const box = document.createElement('div');
          box.className = 'vox-shared-columns-box';
          headers.forEach((column, columnIndex) => {
            if ((file === 'patients.php' || customColumnSizing) && columnIndex === headers.length - 1) return;
            const label = document.createElement('label');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = !column.classList.contains('vox-column-hidden');
            checkbox.addEventListener('change', () => {
              setColumnVisible(columnIndex, checkbox.checked);
              try { localStorage.setItem(storageKey, JSON.stringify(headers.map(item => !item.classList.contains('vox-column-hidden')))); } catch (_) {}
            });
            label.append(checkbox, document.createTextNode(column.dataset.voxColumnTitle || `Sütun ${columnIndex + 1}`));
            box.append(label);
          });
          menu.append(box);
          placeMenu(trigger);
        });

        if (!customColumnSizing && index < headers.length - 1) {
          const resizer = document.createElement('span');
          resizer.className = 'vox-shared-column-resizer';
          resizer.setAttribute('aria-hidden', 'true');
          header.append(resizer);
          resizer.addEventListener('pointerdown', event => {
            event.preventDefault();
            event.stopPropagation();
            const startX = event.clientX;
            const startWidth = header.getBoundingClientRect().width;
            const startTableWidth = table.getBoundingClientRect().width;
            table.style.setProperty('table-layout','fixed','important');
            table.style.setProperty('width',startTableWidth + 'px','important');
            resizer.setPointerCapture(event.pointerId);
            const move = moveEvent => {
              const delta = moveEvent.clientX - startX;
              const width = Math.max(45, startWidth + delta);
              header.style.width = width + 'px';
              header.style.minWidth = width + 'px';
              header.style.maxWidth = width + 'px';
              table.style.setProperty('width',Math.max(scrollHost?.clientWidth || 0,startTableWidth + width - startWidth) + 'px','important');
            };
            const stop = () => {
              try { localStorage.setItem(widthStorageKey, JSON.stringify(headers.map(item => Math.round(item.getBoundingClientRect().width)))); } catch (_) {}
              resizer.removeEventListener('pointermove', move);
              resizer.removeEventListener('pointerup', stop);
              resizer.removeEventListener('pointercancel', stop);
            };
            resizer.addEventListener('pointermove', move);
            resizer.addEventListener('pointerup', stop);
            resizer.addEventListener('pointercancel', stop);
          });
        }
      });
    });

    document.addEventListener('click', event => {
      if (!menu.hidden && !menu.contains(event.target) && !event.target.closest('.vox-shared-column-trigger')) hideMenu();
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') hideMenu(); });
    window.addEventListener('resize', hideMenu);

    if (document.body.classList.contains('vox-embedded-window')) return;

    const topbar = document.querySelector('.patient-topbar');
    const task = document.querySelector('.desktop-task-title');
    if (!topbar || !task || topbar.querySelector('.vox-shared-list-controls')) return;
    const controls = document.createElement('div');
    controls.className = 'vox-shared-list-controls';
    controls.innerHTML = '<button type="button" data-window-action="minimize" title="Simge durumuna küçült" aria-label="Simge durumuna küçült">_</button><button type="button" data-window-action="maximize" title="Büyüt" aria-label="Büyüt">□</button><button type="button" data-window-action="close" title="Kapat" aria-label="Kapat">×</button>';
    topbar.append(controls);

    let savedRect = null;
    const clearRect = () => [topbar,main].forEach(element => ['left','right','top','bottom','width','height'].forEach(property => element.style.removeProperty(property)));
    controls.addEventListener('click', event => {
      const action = event.target.closest('button')?.dataset.windowAction;
      if (action === 'minimize') {
        document.body.classList.add('vox-shared-list-minimized');
        task.title = 'Listeyi geri aç';
      }
      if (action === 'close') {
        const editingAccount = file === 'company-finance.php' || (file === 'current-accounts.php' && (new URLSearchParams(location.search).has('edit') || document.querySelector('.new-account-card[open]')));
        location.href = editingAccount ? 'current-accounts.php' + (new URLSearchParams(location.search).get('_vox_window') === '1' ? '?_vox_window=1' : '') : 'index.php';
      }
      if (action === 'maximize') {
        const maximizing = !document.body.classList.contains('vox-shared-list-maximized');
        if (maximizing) savedRect = {bar:topbar.getAttribute('style'), main:main.getAttribute('style')};
        document.body.classList.toggle('vox-shared-list-maximized', maximizing);
        clearRect();
        event.target.textContent = maximizing ? '❐' : '□';
        event.target.title = maximizing ? 'Geri al' : 'Büyüt';
        if (!maximizing && savedRect) {
          savedRect.bar === null ? topbar.removeAttribute('style') : topbar.setAttribute('style', savedRect.bar);
          savedRect.main === null ? main.removeAttribute('style') : main.setAttribute('style', savedRect.main);
        }
      }
    });
    if (file === 'company-finance.php' && !document.body.classList.contains('vox-shared-list-maximized')) controls.querySelector('[data-window-action="maximize"]')?.click();
    task.addEventListener('click', () => {
      document.body.classList.remove('vox-shared-list-minimized');
      task.removeAttribute('title');
    });
    const grip = document.createElement('span');
    grip.className = 'vox-shared-window-grip';
    grip.title = 'Pencere boyutunu değiştir';
    document.body.append(grip);
    let drag = null;
    const syncGrip = () => {
      if (document.body.classList.contains('vox-shared-list-maximized')) { grip.style.display = 'none'; return; }
      grip.style.display = '';
      const rect = main.getBoundingClientRect();
      grip.style.left = (rect.right - 17) + 'px';
      grip.style.top = (rect.bottom - 17) + 'px';
    };
    const applyRect = (left,top,width,height) => {
      width = Math.max(650,Math.min(innerWidth,width));
      height = Math.max(360,Math.min(innerHeight - 31,height));
      left = Math.max(0,Math.min(innerWidth-width,left));
      top = Math.max(0,Math.min(innerHeight-31-height,top));
      topbar.style.cssText += `;left:${left}px!important;right:auto!important;top:${top}px!important;width:${width}px!important`;
      main.style.cssText += `;left:${left}px!important;right:auto!important;top:${top+31}px!important;bottom:auto!important;width:${width}px!important;height:${height-31}px!important`;
      syncGrip();
    };
    topbar.addEventListener('pointerdown', event => {
      if (event.target.closest('button,a,.account') || document.body.classList.contains('vox-shared-list-maximized')) return;
      const barRect = topbar.getBoundingClientRect();
      const mainRect = main.getBoundingClientRect();
      drag = {mode:'move',x:event.clientX,y:event.clientY,left:barRect.left,top:barRect.top,width:mainRect.width,height:mainRect.height+31};
      topbar.setPointerCapture(event.pointerId);
      document.body.classList.add('vox-shared-list-dragging');
    });
    topbar.addEventListener('pointermove', event => {
      if (!drag || drag.mode !== 'move') return;
      applyRect(drag.left + event.clientX-drag.x, drag.top + event.clientY-drag.y, drag.width, drag.height);
    });
    const stopMove = () => { drag=null; document.body.classList.remove('vox-shared-list-dragging'); };
    topbar.addEventListener('pointerup', stopMove);
    topbar.addEventListener('pointercancel', stopMove);
    topbar.addEventListener('dblclick', event => { if (!event.target.closest('button,a')) controls.querySelector('[data-window-action="maximize"]')?.click(); });

    grip.addEventListener('pointerdown', event => {
      if (document.body.classList.contains('vox-shared-list-maximized')) return;
      const rect = main.getBoundingClientRect();
      drag = {mode:'resize',x:event.clientX,y:event.clientY,left:rect.left,top:topbar.getBoundingClientRect().top,width:rect.width,height:rect.height+31};
      grip.setPointerCapture(event.pointerId);
      document.body.classList.add('vox-shared-list-resizing');
    });
    grip.addEventListener('pointermove', event => {
      if (!drag || drag.mode !== 'resize') return;
      applyRect(drag.left,drag.top,drag.width+event.clientX-drag.x,drag.height+event.clientY-drag.y);
    });
    const stopResize = () => { drag=null; document.body.classList.remove('vox-shared-list-resizing'); };
    grip.addEventListener('pointerup', stopResize);
    grip.addEventListener('pointercancel', stopResize);
    new ResizeObserver(syncGrip).observe(main);
    window.addEventListener('resize', syncGrip);
    syncGrip();
  });
})();
