function initializeCardPagination(card) {
  const itemsContainer = card.querySelector('.card-page-items');
  const pagination = card.querySelector('.card-pages');
  const itemsPerPage = Number(card.dataset.cardPages);

  if (!itemsContainer || !pagination || itemsPerPage < 1) {
    return;
  }

  const items = [...itemsContainer.children];

  if (items.length <= itemsPerPage) {
    return;
  }

  let currentPage = 1;
  const totalPages = Math.ceil(items.length / itemsPerPage);

  function renderPage() {
    const firstVisibleIndex = (currentPage - 1) * itemsPerPage;
    const lastVisibleIndex = firstVisibleIndex + itemsPerPage;

    items.forEach((item, index) => {
      item.hidden = index < firstVisibleIndex || index >= lastVisibleIndex;
    });

    pagination.innerHTML = `
      <button
        type="button"
        aria-label="Página anterior"
        data-go="-1"
        ${currentPage === 1 ? 'disabled' : ''}
      >‹</button>
      <span>${currentPage} / ${totalPages}</span>
      <button
        type="button"
        aria-label="Próxima página"
        data-go="1"
        ${currentPage === totalPages ? 'disabled' : ''}
      >›</button>
    `;
  }

  pagination.addEventListener('click', event => {
    const button = event.target.closest('button[data-go]');

    if (!button || button.disabled) {
      return;
    }

    currentPage += Number(button.dataset.go);
    renderPage();
  });

  renderPage();
}

document
  .querySelectorAll('[data-card-pages]')
  .forEach(initializeCardPagination);

document.querySelectorAll('.match-shield img').forEach(image => {
  image.addEventListener('error', () => {
    image.closest('.match-shield')?.classList.add('is-broken');
  });
});

const modulesStylesheet = document.createElement('link');
modulesStylesheet.rel = 'stylesheet';
modulesStylesheet.href = 'assets/css/team-profile-modules.css';
document.head.append(modulesStylesheet);
