(() => {
  'use strict';

  const ready = callback => document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded',callback,{once:true})
    : callback();

  ready(() => {
    const createText = /(?:^|\s)(?:ekle|oluştur)$/iu;
    const cleanLabel = value => String(value || '')
      .replace(/^[▣💾\s]+/u,'')
      .replace(/\s*\(F2\)\s*$/iu,'')
      .replace(/\s+/g,' ')
      .trim();

    const isSubmitControl = control => {
      if (control instanceof HTMLInputElement) return control.type === 'submit';
      if (!(control instanceof HTMLButtonElement)) return false;
      return !control.hasAttribute('type') || control.type === 'submit';
    };
    const labelOf = control => {
      const visibleLabel = control instanceof HTMLInputElement ? control.value : control.textContent;
      return cleanLabel(visibleLabel) || control.getAttribute('aria-label') || control.getAttribute('title') || '';
    };
    const isSaveControl = control => {
      if (!(control instanceof HTMLButtonElement || control instanceof HTMLInputElement)) return false;
      if (control.matches('.delete,.delete-definition,[class*="delete"],[name*="delete"],[data-action="delete"]')) return false;
      const label = cleanLabel(labelOf(control)).toLocaleLowerCase('tr-TR');
      return /kaydet|güncelle/u.test(label) || (isSubmitControl(control) && createText.test(label));
    };

    const prepare = control => {
      if (!isSaveControl(control) || control.dataset.voxClassicSave === '1') return;
      control.dataset.voxClassicSave = '1';
      control.classList.add('vox-classic-save');
      // Keep the shared icon button size even on pages with older !important rules.
      Object.entries({'box-sizing':'border-box','width':'36px','min-width':'36px','max-width':'36px','height':'30px','min-height':'30px','max-height':'30px','padding':'0','flex':'0 0 36px','display':'inline-flex','align-items':'center','justify-content':'center','line-height':'1'}).forEach(([property,value])=>control.style.setProperty(property,value,'important'));
      const form = control.closest('form');
      form?.classList.add('vox-compact-form');
      form?.closest('main')?.classList.add('vox-form-page');
      const label = cleanLabel(labelOf(control));
      if (!(control instanceof HTMLInputElement)) {
        control.innerHTML = '<svg class="vox-save-icon" style="width:18px!important;min-width:18px!important;max-width:18px!important;height:18px!important;min-height:18px!important;max-height:18px!important;display:block!important;flex-shrink:0!important" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M5 3h12l4 4v14H3V3h2zm2 0v7h10V3M7 21v-8h10v8M14 5v3" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
      }
      if (!control.getAttribute('aria-label')) control.setAttribute('aria-label',label);
      control.title = `${label} — F2`;
    };
    const prepareAll = root => {
      if (root instanceof HTMLButtonElement || root instanceof HTMLInputElement) prepare(root);
      root.querySelectorAll?.('button,input[type="submit"]').forEach(prepare);
    };
    const isBackControl = control => {
      if (!(control instanceof HTMLAnchorElement || control instanceof HTMLButtonElement)) return false;
      if (control.matches('.brand-back-link,.patient-card-return-button,[data-patient-back],[data-vox-confirm-cancel],[aria-label="Kapat"],[title="Kapat"]')) return false;
      if (control.matches('.home-link,.profile-home-link,.price-list-cancel,.service-back-link,.company-visits-back,.unit-visits-back,.cancel-link,.vox-cancel-action')) return true;
      const label = cleanLabel(control.textContent || control.getAttribute('aria-label') || control.getAttribute('title')).toLocaleLowerCase('tr-TR');
      return label === '↶' || /^(?:geri dön|listeye dön|vazgeç|.+[ae] dön)$/u.test(label);
    };
    const removeBackControls = root => {
      if (root instanceof Element && isBackControl(root)) root.remove();
      root.querySelectorAll?.('a,button').forEach(control => {
        if (isBackControl(control)) control.remove();
      });
    };
    removeBackControls(document);
    prepareAll(document);
    new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
      if (node instanceof Element) {
        removeBackControls(node);
        if (node.isConnected) prepareAll(node);
      }
    }))).observe(document.documentElement,{childList:true,subtree:true});

    document.addEventListener('keydown',event => {
      if (event.key !== 'F2' || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
      const activeForm = document.activeElement?.closest?.('form');
      const inActiveForm = activeForm ? [...activeForm.querySelectorAll('.vox-classic-save')].find(button => !button.disabled && button.getClientRects().length) : null;
      const visible = [...document.querySelectorAll('.vox-classic-save')].filter(button => !button.disabled && button.getClientRects().length);
      const target = inActiveForm || visible.find(button => {
        const rect = button.getBoundingClientRect();
        return rect.top < innerHeight && rect.bottom > 0;
      }) || visible[0];
      if (!target) return;
      event.preventDefault();
      target.click();
    });
  });
})();
