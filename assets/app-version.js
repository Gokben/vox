(() => {
  'use strict';
  const script = document.currentScript;
  if (!script || window !== window.top) return;
  const loadedBuild = document.querySelector('meta[name="vox-build"]')?.content;
  const version = document.querySelector('meta[name="vox-version"]')?.content;
  const label = document.createElement('div');
  label.className = 'vox-version-tools';
  label.textContent = 'Versiyon ' + (version || '—');
  const clock = document.querySelector('.desktop-clock');
  if (clock) clock.insertAdjacentElement('beforebegin', label);
  else document.body.append(label);
  const notice = document.createElement('div');
  notice.className = 'vox-release-notice';
  notice.setAttribute('role', 'status');
  notice.textContent = 'Yeni sürüm hazır. Açık işlemleri kaydedip pencereleri kapattığınızda güncelleme uygulanacak.';
  let available = false, checking = false, reloading = false, dirty = false;
  document.addEventListener('input', () => { dirty = true; }, true);
  document.addEventListener('change', () => { dirty = true; }, true);
  function reloadWhenSafe() {
    // Minimized windows also count. Standalone forms are always protected.
    const standalone = !/\/(?:index\.php)?$/.test(location.pathname);
    const openWindows = document.querySelectorAll('.vox-mdi-window').length;
    const dialogOpen = !!document.querySelector('dialog[open], [aria-modal="true"]');
    if (available && !reloading && !dirty && !standalone && !openWindows && !dialogOpen && document.visibilityState === 'visible') {
      reloading = true;
      location.reload();
    }
  }
  async function check() {
    if (!loadedBuild || checking || document.visibilityState !== 'visible') return;
    checking = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(script.dataset.releaseUrl, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
      if (!response.ok || response.redirected) return;
      const release = await response.json();
      if (!/^[a-f0-9]{64}$/.test(release.build) || !/^\d{5}\.\d{2,}$/.test(release.version)) return;
      if (release.build !== loadedBuild) {
        available = true;
        if (!notice.isConnected) document.body.append(notice);
        reloadWhenSafe();
      }
    } catch (_) { /* Offline or expired sessions never interrupt work. */ }
    finally { clearTimeout(timeout); checking = false; }
  }
  const observer = new MutationObserver(reloadWhenSafe);
  observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['open', 'aria-modal'] });
  const timer = setInterval(check, 60000);
  document.addEventListener('visibilitychange', () => { check(); reloadWhenSafe(); });
  check();
})();
