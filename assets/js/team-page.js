function initializeCardPagination(card) {
  const itemsContainer = card.querySelector('.card-page-items');
  const pagination = card.querySelector('.card-pages');
  const dynamicPagination = card.dataset.cardPages === 'dynamic';
  let itemsPerPage = dynamicPagination ? 1 : Number(card.dataset.cardPages);

  if (!itemsContainer || !pagination || (!dynamicPagination && itemsPerPage < 1)) {
    return;
  }

  const items = [...itemsContainer.children];

  if (!dynamicPagination && items.length <= itemsPerPage) {
    return;
  }

  let currentPage = 1;

  function calculateItemsPerPage() {
    const sample = items.find(item => !item.hidden) || items[0];
    if (!sample) return 1;
    return Math.max(1, Math.floor(itemsContainer.clientHeight / Math.max(1, sample.getBoundingClientRect().height)));
  }

  function renderPage() {
    if (dynamicPagination) itemsPerPage = calculateItemsPerPage();
    const totalPages = Math.max(1, Math.ceil(items.length / itemsPerPage));
    currentPage = Math.min(currentPage, totalPages);
    const firstVisibleIndex = (currentPage - 1) * itemsPerPage;
    const lastVisibleIndex = firstVisibleIndex + itemsPerPage;

    items.forEach((item, index) => {
      item.hidden = index < firstVisibleIndex || index >= lastVisibleIndex;
    });

    pagination.innerHTML = totalPages > 1 ? `
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
    ` : '';
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
  if (dynamicPagination && 'ResizeObserver' in window) {
    let previousHeight = 0;
    new ResizeObserver(() => {
      const height = Math.round(itemsContainer.getBoundingClientRect().height);
      if (height === previousHeight) return;
      previousHeight = height;
      requestAnimationFrame(renderPage);
    }).observe(itemsContainer);
  }
}

document
  .querySelectorAll('[data-card-pages]')
  .forEach(initializeCardPagination);

document.querySelectorAll('.lineup-placeholder').forEach(module => {
  const tabs = [...module.querySelectorAll('[data-lineup-tab]')];
  const panels = [...module.querySelectorAll('[data-lineup-panel]')];
  tabs.forEach(tab => tab.addEventListener('click', () => {
    if (tab.disabled) return;
    tabs.forEach(item => { const selected = item === tab; item.classList.toggle('active', selected); item.setAttribute('aria-selected', String(selected)); });
    panels.forEach(panel => { panel.hidden = panel.dataset.lineupPanel !== tab.dataset.lineupTab; });
  }));
});

document.querySelectorAll('[data-lineup-upload-form]').forEach(form => {
  const input = form.querySelector('input[type="file"]');
  const dropzone = form.querySelector('[data-lineup-dropzone]');
  const preview = form.querySelector('[data-lineup-preview]');
  const copy = form.querySelector('[data-lineup-drop-copy]');
  if (preview.getAttribute('src')) copy.hidden = true;
  const showPreview = file => { if (!file || !file.type.startsWith('image/')) return; preview.src = URL.createObjectURL(file); preview.hidden = false; copy.hidden = true; };
  input.addEventListener('change', () => showPreview(input.files[0]));
  ['dragenter', 'dragover'].forEach(type => dropzone.addEventListener(type, event => { event.preventDefault(); dropzone.classList.add('is-dragging'); }));
  ['dragleave', 'drop'].forEach(type => dropzone.addEventListener(type, event => { event.preventDefault(); dropzone.classList.remove('is-dragging'); }));
  dropzone.addEventListener('drop', event => { const file = event.dataTransfer.files[0]; if (!file) return; const transfer = new DataTransfer(); transfer.items.add(file); input.files = transfer.files; showPreview(file); });
});

document.querySelectorAll('.rivalry-card a').forEach(link => link.addEventListener('click', event => event.stopPropagation()));

document.querySelectorAll('[data-transfer-module]').forEach(module => {
  const items = [...module.querySelectorAll('.transfer-entry')];
  const pagination = module.querySelector('.transfer-pages');
  const filters = [...module.querySelectorAll('[data-transfer-filter]')];
  const dynamicPagination = module.dataset.itemsPerPage === 'dynamic';
  let perPage = dynamicPagination ? 1 : (Number(module.dataset.itemsPerPage) || 6);
  let activeFilter = 'todas';
  let currentPage = 1;

  function renderTransfers() {
    const visible = items.filter(item => activeFilter === 'todas' || item.dataset.transferType === activeFilter);
    if (dynamicPagination) {
      const sample = items.find(item => !item.hidden) || visible[0] || items[0];
      const itemHeight = sample ? Math.max(1, sample.getBoundingClientRect().height) : 1;
      perPage = Math.max(1, Math.floor(module.querySelector('.transfer-page-items').clientHeight / itemHeight));
    }
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
  if (dynamicPagination && 'ResizeObserver' in window) {
    let previousHeight = 0;
    const itemsContainer = module.querySelector('.transfer-page-items');
    new ResizeObserver(() => {
      const height = Math.round(itemsContainer.getBoundingClientRect().height);
      if (height === previousHeight) return;
      previousHeight = height;
      requestAnimationFrame(renderTransfers);
    }).observe(itemsContainer);
  }
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
modulesStylesheet.href = 'assets/css/team-profile-modules.css?v=18.6.1';
document.head.append(modulesStylesheet);

const queryParams = new URLSearchParams(location.search);
const requestedClubEditor = queryParams.get('editar') || (queryParams.get('editar_perfil') === '1' ? 'cofre' : '');
const requestedClubModal = { sobre: 'club-about-modal', cofre: 'club-treasury-modal', heroi: 'club-hero-modal' }[requestedClubEditor];
if (requestedClubModal) {
  const modal = document.getElementById(requestedClubModal);
  if (modal) bootstrap.Modal.getOrCreateInstance(modal).show();
}

document.querySelectorAll('[data-player-ranking]').forEach(module => {
  const scorers = JSON.parse(module.dataset.scorers || '[]');
  const assists = JSON.parse(module.dataset.assists || '[]');
  const filter = module.querySelector('.ranking-championship');
  const list = module.querySelector('.ranking-items');
  const pages = module.querySelector('.card-pages');
  const tabs = [...module.querySelectorAll('[data-ranking]')];
  let ranking = 'goals';
  let page = 1;

  const aggregate = rows => {
    const valueKey = ranking === 'goals' ? 'gols' : 'assistencias';
    const selected = filter.value;
    const map = new Map();
    rows.filter(row => selected === 'all' || String(row.campeonato_id) === selected).forEach(row => {
      const key = String(row.jogador).trim().toLocaleLowerCase('pt-BR');
      const current = map.get(key) || { jogador: row.jogador, value: 0 };
      current.value += Number(row[valueKey]) || 0;
      map.set(key, current);
    });
    return [...map.values()].sort((a, b) => b.value - a.value || a.jogador.localeCompare(b.jogador, 'pt-BR'));
  };

  function render() {
    const rows = aggregate(ranking === 'goals' ? scorers : assists);
    const totalPages = Math.max(1, Math.ceil(rows.length / 3));
    page = Math.min(page, totalPages);
    list.innerHTML = rows.length ? rows.slice((page - 1) * 3, page * 3).map((row, index) => `<button class="ranking-player player-open" type="button" data-player-name="${escapeHtml(row.jogador)}" data-player-team="${Number(module.dataset.teamId)}"><b>${String((page - 1) * 3 + index + 1).padStart(2, '0')}</b><span>${escapeHtml(row.jogador)}</span><strong>${row.value}</strong></button>`).join('') : `<p class="empty-copy">Nenhum dado registrado.</p>`;
    pages.innerHTML = rows.length > 3 ? `<button type="button" data-go="-1" ${page === 1 ? 'disabled' : ''}>‹</button><span>${page} / ${totalPages}</span><button type="button" data-go="1" ${page === totalPages ? 'disabled' : ''}>›</button>` : '';
  }
  tabs.forEach(tab => tab.addEventListener('click', () => { ranking = tab.dataset.ranking; page = 1; tabs.forEach(item => item.classList.toggle('active', item === tab)); render(); }));
  filter.addEventListener('change', () => { page = 1; render(); });
  pages.addEventListener('click', event => { const button = event.target.closest('[data-go]'); if (!button || button.disabled) return; page += Number(button.dataset.go); render(); });
  render();
});

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
}
