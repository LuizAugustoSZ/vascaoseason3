(() => {
  const loader = document.querySelector('.site-loading-screen');
  if (!loader) return;

  let finished = false;
  function revealPage() {
    if (finished) return;
    finished = true;
    requestAnimationFrame(() => {
      loader.classList.add('is-finished');
      window.setTimeout(() => loader.remove(), 300);
    });
  }

  if (document.readyState === 'complete') {
    revealPage();
  } else {
    window.addEventListener('load', revealPage, { once: true });
  }

  // Evita bloquear a navegação indefinidamente caso um recurso externo falhe.
  window.setTimeout(revealPage, 12000);
})();
