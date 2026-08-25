(() => {
  const loader = document.querySelector('.site-loading-screen');
  if (!loader) return;

  let pending = 1;
  let hideTimer = 0;
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
