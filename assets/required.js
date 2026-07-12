document.querySelectorAll('.entry-grid > label').forEach(label => {
  const node = [...label.childNodes].find(item => item.nodeType === 3 && item.textContent.includes('*'));
  if (!node) return;
  node.textContent = node.textContent.replace('*', '');
  const star = document.createElement('span');
  star.className = 'required-star';
  star.textContent = '*';
  node.after(star);
});

document.getElementById('item-form').addEventListener('submit', event => {
  const item = document.querySelector('input[name="item_definition"]');
  if (item.value) return;
  event.preventDefault();
  const select = document.querySelector('[data-search-select]');
  select.classList.add('open');
  select.querySelector('.search-select-trigger').setAttribute('aria-expanded', 'true');
  select.querySelector('.search-select-input').focus();
});
