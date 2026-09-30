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
  const begin = label => {
    pending++; lock(label);
    let ended = false;
    return () => { if (ended) return; ended = true; pending--; release(); };
  };
  const track = promise => {
    const finish = begin();
    return Promise.resolve(promise).finally(finish);
  };
  const report = error => {
    const message = 'Não foi possível carregar todos os controles do painel. ' + (error?.message || 'Tente novamente.');
    if (window.siteToast) window.siteToast(message, 'danger');
    else {
      const notice = document.createElement('div'); notice.className = 'alert alert-danger'; notice.setAttribute('role', 'alert'); notice.textContent = message;
      document.querySelector('main')?.prepend(notice);
    }
  };
  const navigate = tab => {
    const url = new URL(location.href);
    if (tab) url.searchParams.set('tab', tab);
    ['_refresh', 'editar_noticia'].forEach(key => url.searchParams.delete(key));
    try { sessionStorage.setItem(storageKey, JSON.stringify({path: url.pathname, lists: window.__adminListState || {}, top: window.scrollY})); } catch (_) {}
    navigating = true; lock('ATUALIZANDO PAINEL');
    // Reconstrói todos os dados, eventos, modais e componentes em uma nova página.
    location.replace(url.href);
  };
  window.adminLoading = {begin, track, report, navigate};
  lock();
  document.addEventListener('submit', event => {
    // Os formulários fora de main usam a navegação nativa (modais, por exemplo).
    queueMicrotask(() => {
      if (!event.defaultPrevented) { navigating = true; lock('SALVANDO ALTERAÇÕES'); }
    });
  });
  const finishDocument = () => { pending--; release(); };
  if (document.readyState === 'complete') finishDocument();
  else window.addEventListener('load', finishDocument, {once: true});
  const nativeFetch = window.fetch.bind(window);
  window.fetch = (...args) => {
    const input = args[0];
    const url = new URL(typeof input === 'string' || input instanceof URL ? input : input.url, location.href);
    if (url.origin !== location.origin || !/\/(?:campeonatos-dados|identidades-dados|rodada-prompt|partida-dados|mata-dados|conta-dados|associacoes-dados)\.php$/.test(url.pathname)) return nativeFetch(...args);
    const finish = begin();
    return nativeFetch(...args).then(response => {
      response.clone().text().catch(() => {}).finally(() => setTimeout(finish, 0));
      return response;
    }, error => { finish(); throw error; });
  };
  window.addEventListener('pageshow', event => { if (event.persisted) { navigating = false; pending = 0; release(); } });
})();
