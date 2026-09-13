(() => {
  'use strict';
  const fields = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]),select,textarea,[contenteditable="true"]';
  document.addEventListener('keydown', event => {
    if (event.key !== 'Tab' || event.ctrlKey || event.altKey || event.metaKey || event.isComposing) return;
    const current = event.target;
    if (!(current instanceof Element) || !current.matches(fields)) return;
    const dialog = current.closest('dialog,[role="dialog"],.modal');
    const scope = dialog || current.closest('form') || document;
    const candidates = Array.from(scope.querySelectorAll(fields)).filter(field => {
      if (field.matches(':disabled,[readonly]') || field.tabIndex < 0 || field.closest('[hidden],[inert],[aria-hidden="true"]')) return false;
      const style = getComputedStyle(field);
      return style.visibility !== 'hidden' && style.visibility !== 'collapse' && field.getClientRects().length > 0;
    }).map((field, index) => ({field, index, rect: field.getBoundingClientRect()}));
    // Follow the displayed rows even when CSS grid has rearranged the source order.
    candidates.sort((a, b) => a.rect.top - b.rect.top || a.rect.left - b.rect.left || a.index - b.index);
    const index = candidates.findIndex(item => item.field === current);
    if (index < 0) return;
    const next = candidates[index + (event.shiftKey ? -1 : 1)];
    // At the form boundaries retain native navigation to Save and other controls.
    if (!next) return;
    event.preventDefault();
    next.field.focus();
  }, true);
})();
