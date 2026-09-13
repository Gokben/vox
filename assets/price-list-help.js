(() => {
  const endpoint = document.currentScript.dataset.endpoint;
  const dialog = document.createElement('dialog');
  dialog.className = 'vox-price-help-dialog';
  dialog.setAttribute('aria-labelledby', 'vox-price-help-title');
  const header = document.createElement('header');
  const title = document.createElement('h2');
  title.id = 'vox-price-help-title';
  title.textContent = 'Fiyat Listesi Aktarım Kuralları';
  const close = document.createElement('button');
  close.type = 'button'; close.textContent = '×';
  close.setAttribute('aria-label', 'Yardımı kapat');
  close.addEventListener('click', () => dialog.close());
  header.append(title, close);
  const content = document.createElement('div');
  content.className = 'vox-price-help-content';
  dialog.append(header, content); document.body.append(dialog);
  function render(text) {
    content.replaceChildren();
    let list = null, table = null;
    for (const line of text.split(/\r?\n/)) {
      if (!line.trim()) { list = null; table = null; continue; }
      if (/^\|[\s|:-]+\|$/.test(line)) continue;
      if (line.startsWith('|')) {
        if (!table) { table = document.createElement('table'); content.append(table); }
        const row = document.createElement('tr');
        for (const cell of line.split('|').slice(1, -1)) {
          const td = document.createElement(table.rows.length ? 'td' : 'th');
          td.textContent = cell.trim(); row.append(td);
        }
        table.append(row); continue;
      }
      const heading = line.match(/^(#{1,3})\s+(.*)$/);
      if (heading) {
        list = null;
        const h = document.createElement(heading[1].length === 1 ? 'h3' : 'h4');
        h.textContent = heading[2]; content.append(h); continue;
      }
      const bullet = line.startsWith('- ');
      if (bullet && !list) { list = document.createElement('ul'); content.append(list); }
      const el = document.createElement(bullet ? 'li' : 'p');
      el.textContent = (bullet ? line.slice(2) : line).replace(/`([^`]+)`/g, '$1');
      (bullet ? list : content).append(el);
    }
  }
  async function open(event) {
    event.preventDefault(); event.stopPropagation();
    if (!dialog.open) dialog.showModal();
    content.textContent = 'Kurallar yükleniyor…'; close.focus();
    try {
      const response = await fetch(endpoint, {credentials: 'same-origin', cache: 'no-store'});
      if (!response.ok) throw new Error();
      const data = await response.json();
      if (typeof data.text !== 'string') throw new Error();
      title.textContent = data.title; render(data.text);
    } catch { content.textContent = 'Kurallar yüklenemedi. Oturumunuzu kontrol edip yeniden deneyin.'; }
  }
  function button() {
    const b = document.createElement('button'); b.type = 'button';
    b.className = 'vox-price-help-button'; b.textContent = '?';
    b.title = 'Fiyat listesi aktarım kuralları';
    b.setAttribute('aria-label', 'Fiyat listesi aktarım kurallarını aç');
    b.addEventListener('click', open); return b;
  }
  function placeHelp() {
    if (!document.querySelector('.price-lists-page, .stock-prices-page')) return;
    let heading;
    try {
      heading = window.frameElement?.closest('.vox-mdi-window')?.querySelector('.vox-mdi-title');
      if (!heading && window.frameElement) heading = window.frameElement.parentElement?.querySelector('.vox-mdi-title');
    } catch {}
    heading ||= document.querySelector('.page-context strong');
    if (!heading) return;
    const b = button();
    b.addEventListener('pointerdown', event => event.stopPropagation());
    b.addEventListener('dblclick', event => event.stopPropagation());
    heading.after(b);
    window.addEventListener('pagehide', () => b.remove(), {once:true});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', placeHelp, {once:true});
  else placeHelp();
})();
