(() => {
  const loader = document.querySelector('.site-loading-screen');
  if (!loader) return;

  let pending = 1;
  let hideTimer = 0;
  function showPageLoading() {
    pending += 1;
    window.clearTimeout(hideTimer);
    loader.classList.remove('is-finished');
    document.body.setAttribute('aria-busy', 'true');
  }

  function revealPage() {
    pending = Math.max(0, pending - 1);
    if (pending > 0) return;
    window.clearTimeout(hideTimer);
    hideTimer = window.setTimeout(() => requestAnimationFrame(() => requestAnimationFrame(() => {
      if (pending > 0) return;
      loader.classList.add('is-finished');
      document.body.removeAttribute('aria-busy');
    })), 180);
  }

  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (...args) => {
    const request = args[0];
    const requestUrl = typeof request === 'string' ? request : request?.url || '';
    if (requestUrl.includes('api/partida-detalhes.php') || requestUrl.includes('api/jogador-detalhes.php')) {
      return nativeFetch(...args);
    }
    showPageLoading();
    try {
      const response = await nativeFetch(...args);
      let bodyTracked = false;
      ['json', 'text', 'blob', 'arrayBuffer', 'formData'].forEach(method => {
        if (typeof response[method] !== 'function') return;
        const nativeMethod = response[method].bind(response);
        response[method] = async (...methodArgs) => {
          bodyTracked = true;
          try { return await nativeMethod(...methodArgs); }
          finally { revealPage(); }
        };
      });
      window.setTimeout(() => { if (!bodyTracked) revealPage(); }, 12000);
      return response;
    } catch (error) {
      revealPage();
      throw error;
    }
  };

  if (document.readyState === 'complete') {
    revealPage();
  } else {
    window.addEventListener('load', revealPage, { once: true });
  }

  // Evita bloquear a navegação indefinidamente caso um recurso externo falhe.
  window.setTimeout(() => {
    if (pending === 1) revealPage();
  }, 12000);
})();
