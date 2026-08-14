(() => {
  if (window.__adminLoadingInstalled) return;
  window.__adminLoadingInstalled = true;

  const loader = document.querySelector('.admin-loading-screen');
  const label = loader?.querySelector('[data-loading-label]');
  if (!loader) return;

  let pending = 1;
  let hideTimer = 0;

  function showLoading(message = 'CARREGANDO DADOS') {
    pending += 1;
    window.clearTimeout(hideTimer);
    if (label) label.textContent = message;
    loader.classList.remove('is-finished');
    document.body.setAttribute('aria-busy', 'true');
  }

  function finishLoading() {
    pending = Math.max(0, pending - 1);
    if (pending > 0) return;
    window.clearTimeout(hideTimer);
    hideTimer = window.setTimeout(() => {
      requestAnimationFrame(() => requestAnimationFrame(() => {
        if (pending > 0) return;
        loader.classList.add('is-finished');
        document.body.removeAttribute('aria-busy');
      }));
    }, 180);
  }

  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (...args) => {
    showLoading('ATUALIZANDO DADOS');
    try {
      const response = await nativeFetch(...args);
      let bodyTracked = false;
      ['json', 'text', 'blob', 'arrayBuffer', 'formData'].forEach(method => {
        if (typeof response[method] !== 'function') return;
        const nativeMethod = response[method].bind(response);
        response[method] = async (...methodArgs) => {
          bodyTracked = true;
          try {
            return await nativeMethod(...methodArgs);
          } finally {
            finishLoading();
          }
        };
      });
      window.setTimeout(() => {
        if (!bodyTracked) finishLoading();
      }, 12000);
      return response;
    } catch (error) {
      finishLoading();
      throw error;
    }
  };

  window.adminLoading = { show: showLoading, hide: finishLoading };
  if (document.readyState === 'complete') finishLoading();
  else window.addEventListener('load', finishLoading, { once: true });
})();
