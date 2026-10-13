(() => {
  if (window.adminLoading) return;
  const loader = document.querySelector('.admin-loading-screen');
  if (!loader) return;

  const storageKey = 'vascao-admin-navigation';
  let saved;
  try { saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null'); sessionStorage.removeItem(storageKey); } catch (_) {}
  if (saved?.path === location.pathname) window.__adminListState = saved.lists || {};
  let pending = 1, navigating = false, timer;
  const inertElements = new Map();
  const lock = (label = 'CARREGANDO DADOS') => {
    clearTimeout(timer);
    loader.querySelector('[data-loading-label]').textContent = label;
    loader.classList.remove('is-finished');
    document.body.classList.add('admin-is-loading');
    document.body.setAttribute('aria-busy', 'true');
    for (const element of document.body.children) {
      if (element === loader || element.tagName === 'SCRIPT' || inertElements.has(element)) continue;
      inertElements.set(element, element.inert); element.inert = true;
    }
  };
  const release = () => {
    if (pending || navigating) return;
    timer = setTimeout(() => requestAnimationFrame(() => requestAnimationFrame(() => {
      if (pending || navigating) return;
      for (const [element, wasInert] of inertElements) element.inert = wasInert;
      inertElements.clear();
      loader.classList.add('is-finished');
      document.body.classList.remove('admin-is-loading');
      document.body.removeAttribute('aria-busy');
      if (saved?.path === location.pathname) { window.scrollTo(0, saved.top || 0); saved = null; }
      window.dispatchEvent(new Event('admin:ready'));
    })), 0);
  };
  const report = error => {
    const message = 'Não foi possível carregar todos os controles do painel. ' + (error?.message || 'Tente novamente.');
    if (window.siteToast) window.siteToast(message, 'danger');
    else {
      const notice = document.createElement('div'); notice.className = 'alert alert-danger'; notice.setAttribute('role', 'alert'); notice.textContent = message;
      document.querySelector('main')?.prepend(notice);
    }
  };
  const active = new Set();
  const localTrack = promise => {
    const tracked = Promise.resolve(promise); active.add(tracked);
    tracked.finally(() => active.delete(tracked)).catch(() => {});
    return tracked;
  };
  window.adminLoading = {begin: () => () => {}, track: localTrack, report,
    navigate: tab => window.refreshAdminPane(tab), settled: () => Promise.allSettled([...active])};
  lock();
  const finishDocument = () => { pending--; release(); };
  if (document.readyState !== 'loading') finishDocument();
  else document.addEventListener('DOMContentLoaded', finishDocument, {once: true});
  window.addEventListener('pageshow', event => { if (event.persisted) { navigating = false; pending = 0; release(); } });
})();
