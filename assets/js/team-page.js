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

    const visibleWidth = right - left + 1;
    const visibleHeight = bottom - top + 1;
    const padding = Math.round(Math.max(visibleWidth, visibleHeight) * .035);
    left = Math.max(0, left - padding);
    top = Math.max(0, top - padding);
    right = Math.min(source.width - 1, right + padding);
    bottom = Math.min(source.height - 1, bottom + padding);

    const cropWidth = right - left + 1;
    const cropHeight = bottom - top + 1;
    const output = document.createElement('canvas');
    const scale = Math.min(1, 256 / cropWidth, 256 / cropHeight);
    output.width = Math.max(1, Math.round(cropWidth * scale));
    output.height = Math.max(1, Math.round(cropHeight * scale));
    output.getContext('2d').drawImage(
      source,
      left,
      top,
      cropWidth,
      cropHeight,
      0,
      0,
      output.width,
      output.height
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
