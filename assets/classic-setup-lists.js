(() => {
  'use strict';

  const ready = callback => document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', callback, {once:true})
    : callback();

  ready(() => {
    const main = document.querySelector('main.setup-page');
    if (!main) return;

    const listSections = [...main.querySelectorAll('section')].filter(section =>
      section.querySelector('table') && section.querySelector(':scope > header')
    );

    listSections.forEach(section => {
      if (section.classList.contains('vox-setup-list-window')) return;
      section.classList.add('vox-setup-list-window');

      const header = section.querySelector(':scope > header');
      const heading = header?.querySelector('h1,h2');
      const closeButton = document.createElement('button');
      closeButton.type = 'button';
      closeButton.className = 'vox-setup-list-close';
      closeButton.textContent = '×';
      closeButton.title = `${heading?.textContent?.trim() || 'Liste'} penceresini kapat`;
      closeButton.setAttribute('aria-label', closeButton.title);
      closeButton.addEventListener('click', () => { section.hidden = true; });
      header?.append(closeButton);
    });
  });
})();
