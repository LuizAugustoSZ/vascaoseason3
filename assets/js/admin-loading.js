(() => {
  if (window.__adminLoadingInstalled) return;
  window.__adminLoadingInstalled = true;

  const loader = document.querySelector('.admin-loading-screen');
  if (!loader) return;

  let pending = 1;
  let hideTimer = 0;

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

  if (document.readyState === 'complete') finishLoading();
  else window.addEventListener('load', finishLoading, { once: true });
})();
