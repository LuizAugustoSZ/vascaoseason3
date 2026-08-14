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

document.querySelectorAll('[data-transfer-module]').forEach(module => {
  const items = [...module.querySelectorAll('.transfer-entry')];
  const pagination = module.querySelector('.transfer-pages');
  const filters = [...module.querySelectorAll('[data-transfer-filter]')];
  const perPage = Number(module.dataset.itemsPerPage) || 6;
  let activeFilter = 'todas';
  let currentPage = 1;

  function renderTransfers() {
    const visible = items.filter(item => activeFilter === 'todas' || item.dataset.transferType === activeFilter);
    const totalPages = Math.max(1, Math.ceil(visible.length / perPage));
    currentPage = Math.min(currentPage, totalPages);
    items.forEach(item => { item.hidden = true; });
    visible.slice((currentPage - 1) * perPage, currentPage * perPage).forEach(item => { item.hidden = false; });
    pagination.innerHTML = visible.length > perPage ? `<button type="button" data-go="-1" aria-label="Página anterior" ${currentPage === 1 ? 'disabled' : ''}>‹</button><span>${currentPage} / ${totalPages}</span><button type="button" data-go="1" aria-label="Próxima página" ${currentPage === totalPages ? 'disabled' : ''}>›</button>` : '';
  }

  filters.forEach(button => button.addEventListener('click', () => {
    activeFilter = button.dataset.transferFilter;
    currentPage = 1;
    filters.forEach(filter => filter.classList.toggle('active', filter === button));
    renderTransfers();
  }));
  pagination.addEventListener('click', event => {
    const button = event.target.closest('button[data-go]');
    if (!button || button.disabled) return;
    currentPage += Number(button.dataset.go);
    renderTransfers();
  });
  renderTransfers();
});

function normalizeShieldVisibleArea(image) {
  if (!image.naturalWidth || !image.naturalHeight || image.dataset.normalized) {
    return;
  }

  image.dataset.normalized = 'true';

  try {
    const source = document.createElement('canvas');
    source.width = image.naturalWidth;
    source.height = image.naturalHeight;
    const context = source.getContext('2d', { willReadFrequently: true });
    context.drawImage(image, 0, 0);

    const pixels = context.getImageData(0, 0, source.width, source.height).data;
    let left = source.width;
    let top = source.height;
    let right = -1;
    let bottom = -1;

    for (let y = 0; y < source.height; y += 1) {
      for (let x = 0; x < source.width; x += 1) {
        if (pixels[(y * source.width + x) * 4 + 3] > 10) {
          left = Math.min(left, x);
          top = Math.min(top, y);
          right = Math.max(right, x);
          bottom = Math.max(bottom, y);
        }
      }
    }

    if (right < left || bottom < top) {
      return;
    }

    const cropWidth = right - left + 1;
    const cropHeight = bottom - top + 1;
    const output = document.createElement('canvas');
    output.width = 500;
    output.height = 500;
    const scale = Math.min(470 / cropWidth, 470 / cropHeight);
    const drawWidth = Math.max(1, Math.round(cropWidth * scale));
    const drawHeight = Math.max(1, Math.round(cropHeight * scale));
    const drawX = Math.round((output.width - drawWidth) / 2);
    const drawY = Math.round((output.height - drawHeight) / 2);
    const outputContext = output.getContext('2d');
    outputContext.imageSmoothingEnabled = true;
    outputContext.imageSmoothingQuality = 'high';
    outputContext.drawImage(
      source,
      left,
      top,
      cropWidth,
      cropHeight,
      drawX,
      drawY,
      drawWidth,
      drawHeight
    );

    image.src = output.toDataURL('image/webp', .9);
  } catch (error) {
    // URLs externas sem permissão de leitura continuam usando a imagem original.
  }
}

document.querySelectorAll('.match-shield img').forEach(image => {
  image.addEventListener('error', () => {
    image.closest('.match-shield')?.classList.add('is-broken');
  });

  if (image.complete) {
    normalizeShieldVisibleArea(image);
  } else {
    image.addEventListener('load', () => normalizeShieldVisibleArea(image), { once: true });
  }
});

const modulesStylesheet = document.createElement('link');
modulesStylesheet.rel = 'stylesheet';
modulesStylesheet.href = 'assets/css/team-profile-modules.css';
document.head.append(modulesStylesheet);

const queryParams = new URLSearchParams(location.search);
const requestedClubEditor = queryParams.get('editar') || (queryParams.get('editar_perfil') === '1' ? 'cofre' : '');
const requestedClubModal = { sobre: 'club-about-modal', cofre: 'club-treasury-modal', heroi: 'club-hero-modal' }[requestedClubEditor];
if (requestedClubModal) {
  const modal = document.getElementById(requestedClubModal);
  if (modal) bootstrap.Modal.getOrCreateInstance(modal).show();
}
