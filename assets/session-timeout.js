(() => {
  'use strict';

  const script = document.currentScript;
  if (!script) return;

  const timeoutSeconds = Number(script.dataset.timeout || 1800);
  const timeoutMs = Math.max(60, timeoutSeconds) * 1000;
  const logoutUrl = script.dataset.logoutUrl || 'logout.php?timeout=1';
  const heartbeatUrl = script.dataset.heartbeatUrl || 'session-heartbeat.php';
  const activityKey = 'vox.session.lastActivity';
  const heartbeatEveryMs = 5 * 60 * 1000;
  let lastWrite = 0;
  let lastHeartbeat = 0;
  let loggingOut = false;

  const readActivity = () => {
    const stored = Number(localStorage.getItem(activityKey) || 0);
    return Number.isFinite(stored) && stored > 0 ? stored : Date.now();
  };

  const markActivity = (force = false) => {
    const now = Date.now();
    if (!force && now - lastWrite < 1000) return;
    lastWrite = now;
    localStorage.setItem(activityKey, String(now));
  };

  const heartbeat = async () => {
    const now = Date.now();
    if (now - lastHeartbeat < heartbeatEveryMs) return;
    if (now - readActivity() >= timeoutMs) return;
    lastHeartbeat = now;
    try {
      const response = await fetch(heartbeatUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (response.redirected || response.status === 401 || response.status === 403) logout();
    } catch (_) {
      // Geçici ağ hatası kullanıcı etkinliğini sıfırlamamalıdır.
    }
  };

  const logout = () => {
    if (loggingOut) return;
    loggingOut = true;
    localStorage.removeItem(activityKey);
    try { window.top.location.assign(logoutUrl); }
    catch (_) { window.location.assign(logoutUrl); }
  };

  const check = () => {
    if (Date.now() - readActivity() >= timeoutMs) {
      logout();
      return;
    }
    heartbeat();
  };

  ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart'].forEach(eventName => {
    window.addEventListener(eventName, () => markActivity(), { passive: true });
  });
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) markActivity(true);
  });

  markActivity(true);
  heartbeat();
  window.setInterval(check, 5000);
})();
