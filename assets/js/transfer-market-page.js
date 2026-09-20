document.querySelectorAll('[data-transfer-market]').forEach(page => {
  const items = [...page.querySelectorAll('[data-transfer-item]')];
  const search = page.querySelector('#transfer-search');
  const championship = page.querySelector('#transfer-championship');
  const club = page.querySelector('#transfer-club');
  const type = page.querySelector('#transfer-type');
  const count = page.querySelector('[data-transfer-count]');
  const empty = page.querySelector('[data-transfer-empty]');
  const pagination = page.querySelector('.transfer-market-pages');
  const perPage = Number(page.dataset.itemsPerPage) || 12;
  let currentPage = 1;

  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR').trim();
  function filteredItems() {
    const term = normalize(search.value);
    return items.filter(item => (!term || normalize(item.dataset.player).includes(term))
      && (championship.value === 'all' || item.dataset.championship === championship.value)
      && (club.value === 'all' || item.dataset.club === club.value)
      && (type.value === 'all' || item.dataset.type === type.value));
  }
  function render() {
    const filtered = filteredItems();
    const pages = Math.max(1, Math.ceil(filtered.length / perPage));
    currentPage = Math.min(currentPage, pages);
    const visiblePageCount = 10;
    const firstPage = Math.max(1, Math.min(currentPage - Math.floor(visiblePageCount / 2), pages - visiblePageCount + 1));
    const lastPage = Math.min(pages, firstPage + visiblePageCount - 1);
    const pageButtons = Array.from({length: lastPage - firstPage + 1}, (_, index) => firstPage + index)
      .map(pageNumber => `<button type="button" data-page="${pageNumber}" class="${currentPage === pageNumber ? 'active' : ''}" aria-label="Página ${pageNumber}">${pageNumber}</button>`).join('');
    const pageOptions = Array.from({length: pages}, (_, index) => index + 1)
      .map(pageNumber => `<option value="${pageNumber}" ${currentPage === pageNumber ? 'selected' : ''}>Página ${pageNumber}</option>`).join('');
    items.forEach(item => { item.hidden = true; });
    filtered.slice((currentPage - 1) * perPage, currentPage * perPage).forEach(item => { item.hidden = false; });
    count.textContent = filtered.length;
    empty.classList.toggle('d-none', filtered.length > 0);
    pagination.innerHTML = filtered.length > perPage
      ? `<button type="button" data-page="prev" ${currentPage === 1 ? 'disabled' : ''} aria-label="Página anterior">‹</button>${pageButtons}<button type="button" data-page="next" ${currentPage === pages ? 'disabled' : ''} aria-label="Próxima página">›</button><select class="transfer-page-picker" aria-label="Ir para a página">${pageOptions}</select>` : '';
  }
  [search, championship, club, type].forEach(control => control.addEventListener(control === search ? 'input' : 'change', () => { currentPage = 1; render(); }));
  page.querySelector('[data-clear-transfer-filters]').addEventListener('click', () => { search.value = ''; championship.value = club.value = type.value = 'all'; currentPage = 1; render(); });
  pagination.addEventListener('click', event => { const button = event.target.closest('[data-page]'); if (!button || button.disabled) return; currentPage = button.dataset.page === 'prev' ? currentPage - 1 : button.dataset.page === 'next' ? currentPage + 1 : Number(button.dataset.page); render(); });
  pagination.addEventListener('change', event => { const picker = event.target.closest('.transfer-page-picker'); if (!picker) return; currentPage = Number(picker.value); render(); });
  render();
});
