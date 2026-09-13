(() => {
  'use strict';
  const validDate = (year, month, day) => year >= 1000 && year <= 9999 && month >= 1 && month <= 12 && day >= 1 && day <= new Date(Date.UTC(year, month, 0)).getUTCDate();
  const pad = value => String(value || '').replace(/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?=$| )/, (_, d, m, y) => `${d.padStart(2,'0')}.${m.padStart(2,'0')}.${y}`);
  const parse = (value, timed = false) => {
    if (!value) return '';
    const match = (timed ? /^(\d{2})\.(\d{2})\.(\d{4}) (\d{2}):(\d{2})$/ : /^(\d{2})\.(\d{2})\.(\d{4})$/).exec(value);
    if (!match || !validDate(+match[3], +match[2], +match[1]) || (timed && (+match[4] > 23 || +match[5] > 59))) return null;
    return `${match[3]}-${match[2]}-${match[1]}` + (timed ? `T${match[4]}:${match[5]}` : '');
  };
  const format = value => String(value || '').replace(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::\d{2})?)?$/, (_, y, m, d, h, min) => `${d}.${m}.${y}` + (h === undefined ? '' : ` ${h}:${min}`));
  const age = (iso, today = new Date()) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
    if (!match || !validDate(+match[1], +match[2], +match[3])) return null;
    const y = +match[1], m = +match[2], d = +match[3];
    const years = today.getFullYear() - y - ((today.getMonth() + 1 < m || (today.getMonth() + 1 === m && today.getDate() < d)) ? 1 : 0);
    return years < 0 ? null : years;
  };
  globalThis.VoxDateFormat = { parse, format, age, pad };
  if (typeof document === 'undefined') return;
  const controls = new Map();
  const valueProperty = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  const textControls = new Map();
  const textSelector = 'input[data-vox-date-text]';
  const selector = 'input[type="date"],input[type="datetime-local"]';
  const prepare = source => {
    if (controls.has(source) || !source.parentNode) return;
    const timed = source.type === 'datetime-local';
    if (source.name === 'birth_date') { const today = new Date(); source.max = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-'); }
    const editor = document.createElement('input');
    editor.type = 'text';
    editor.className = source.className + ' vox-date-editor';
    editor.style.cssText = source.style.cssText;
    editor.placeholder = timed ? 'gg.aa.yyyy ss:dd' : 'gg.aa.yyyy';
    editor.inputMode = timed ? 'text' : 'decimal';
    editor.maxLength = timed ? 16 : 10;
    editor.autocomplete = 'off';
    editor.pattern = timed ? '[0-9]{2}\\.[0-9]{2}\\.[0-9]{4} [0-9]{2}:[0-9]{2}' : '[0-9]{2}\\.[0-9]{2}\\.[0-9]{4}';
    editor.title = timed ? 'gg.aa.yyyy ss:dd — örnek: 13.09.2026 14:30' : 'gg.aa.yyyy — örnek: 13.09.2026';
    editor.required = source.required;
    if (source.hasAttribute('form')) editor.setAttribute('form', source.getAttribute('form'));
    editor.setAttribute('aria-label', (source.getAttribute('aria-label') || source.labels?.[0]?.textContent?.trim() || source.closest('.icon-form-row')?.querySelector('label')?.textContent?.trim() || source.name || 'Tarih') + ' (gg.aa.yyyy)');
    source.required = false;
    source.setAttribute('aria-hidden', 'true');
    source.tabIndex = -1;
    source.classList.add('vox-date-source');
    source.style.setProperty('display', 'none', 'important');
    source.after(editor);
    let editing = false;
    const sync = () => {
      if (!editing) editor.value = format(source.value);
      editor.disabled = source.disabled;
      editor.readOnly = source.readOnly;
    };
    const validate = () => {
      const iso = parse(editor.value, timed);
      let message = iso === null ? 'Geçerli bir tarihi gg.aa.yyyy biçiminde girin.' : '';
      if (iso && source.min && iso < source.min) message = `Tarih ${format(source.min)} veya sonrası olmalıdır.`;
      if (iso && source.max && iso > source.max) message = `Tarih ${format(source.max)} veya öncesi olmalıdır.`;
      editor.setCustomValidity(message);
      return !message;
    };
    Object.defineProperty(source, 'value', {
      configurable: true,
      get() { return valueProperty.get.call(this); },
      set(value) { valueProperty.set.call(this, value); sync(); if (!editing) validate(); }
    });
    const update = event => {
      editor.value = pad(editor.value);
      editing = true;
      source.value = parse(editor.value, timed) || '';
      validate();
      source.dispatchEvent(new Event(event.type, { bubbles: true }));
      editing = false;
    };
    editor.addEventListener('input', update);
    editor.addEventListener('change', update);
    editor.addEventListener('blur', validate);
    source.addEventListener('input', () => { sync(); if (!editing) validate(); });
    source.addEventListener('change', () => { sync(); if (!editing) validate(); });
    source.addEventListener('invalid', event => { event.preventDefault(); editor.reportValidity(); });
    controls.set(source, { editor, sync, validate });
    sync(); validate();
  };
  const prepareText = editor => {
    if (textControls.has(editor)) return;
    editor.placeholder = 'gg.aa.yyyy';
    editor.maxLength = 10;
    editor.pattern = '[0-9]{2}\\.[0-9]{2}\\.[0-9]{4}';
    const validate = () => {
      editor.value = pad(editor.value);
      const parsed = parse(editor.value);
      editor.setCustomValidity(parsed === null ? 'Geçerli bir tarihi gg.aa.yyyy biçiminde girin.' : '');
      return editor.validity.valid;
    };
    editor.addEventListener('input', validate);
    editor.addEventListener('blur', validate);
    textControls.set(editor, { validate });
    validate();
  };
  const scan = root => {
    if (root.matches?.(textSelector)) prepareText(root);
    root.querySelectorAll?.(textSelector).forEach(prepareText);
    if (root.matches?.(selector)) prepare(root);
    root.querySelectorAll?.(selector).forEach(prepare);
  };
  const start = () => {
    scan(document);
    new MutationObserver(records => {
      for (const record of records) {
        if (record.type === 'attributes') {
          const control = controls.get(record.target);
          if (control) {
            if (record.attributeName === 'required' && record.target.required) {
              control.editor.required = true;
              record.target.required = false;
            }
            control.sync(); control.validate();
          } else if (record.attributeName === 'type') scan(record.target);
        }
        else record.addedNodes.forEach(node => { if (node.nodeType === 1) scan(node); });
      }
      for (const [editor] of textControls) if (!editor.isConnected) textControls.delete(editor);
      for (const [source, control] of controls) {
        if (!source.isConnected) { control.editor.remove(); controls.delete(source); }
        else if (source.nextElementSibling !== control.editor) source.after(control.editor);
      }
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['value', 'disabled', 'readonly', 'min', 'max', 'required', 'type'] });
    // Validate before page-specific change handlers can send AJAX requests.
    document.addEventListener('change', event => {
      const control = textControls.get(event.target);
      if (control && !control.validate()) {
        event.stopImmediatePropagation(); event.preventDefault(); event.target.reportValidity();
      }
    }, true);
    document.addEventListener('submit', event => {
      for (const [editor, control] of textControls) {
        if (editor.form === event.target && !editor.disabled && !control.validate()) {
          event.preventDefault(); event.stopImmediatePropagation(); editor.reportValidity(); return;
        }
      }
      for (const [source, control] of controls) {
        if (source.form !== event.target || source.disabled) continue;
        if (!control.validate() || !control.editor.checkValidity()) {
          event.preventDefault(); event.stopImmediatePropagation(); control.editor.reportValidity(); return;
        }
      }
    }, true);
    document.addEventListener('formdata', event => {
      // Include the typed value for invalid input even in programmatic submissions;
      // the server then rejects it instead of silently saving an empty date.
      for (const [source, control] of controls) {
        if (source.form === event.target && source.name && !source.disabled && !control.validate()) event.formData.set(source.name, control.editor.value);
      }
    });
    document.addEventListener('reset', event => setTimeout(() => {
      for (const [source, control] of controls) if (source.form === event.target) { control.sync(); control.validate(); }
    }, 0));
  };
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start, { once: true }) : start();
})();