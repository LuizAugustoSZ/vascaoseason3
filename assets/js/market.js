function formatBRLInput(input) {
  const digits = input.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
  if (!digits) {
    input.value = '';
    return;
  }
  const integer = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  input.value = `R$ ${integer}`;
}

document.querySelectorAll('input[name="saldo"], input[name="valor"]').forEach(input => {
  input.type = 'text';
  input.inputMode = 'decimal';
  input.placeholder = 'R$ 0,00';
  if (input.value) {
    const value = Number(input.value.replace(',', '.'));
    if (Number.isFinite(value)) input.value = `R$ ${Math.round(value).toLocaleString('pt-BR', {maximumFractionDigits: 0})}`;
  }
  input.addEventListener('input', () => formatBRLInput(input));
});

const starterInputs = [...document.querySelectorAll('input[name="titular_id[]"]')];
const starterCount = document.querySelector('[data-selected-starters]');
function updateStarterSelection() {
  const selected = starterInputs.filter(input => input.checked).length;
  if (starterCount) starterCount.textContent = selected;
  starterInputs.forEach(input => input.closest('.roster-select-card')?.classList.toggle('is-starter', input.checked));
}
starterInputs.forEach(input => input.addEventListener('change', updateStarterSelection));
updateStarterSelection();

document.querySelectorAll('.formation-control').forEach(control => {
  const select = control.querySelector('select[name="formacao"]');
  const custom = control.querySelector('input[name="formacao_custom"]');
  if (!select || !custom) return;

  function updateCustomFormation() {
    const enabled = select.value === '__custom__';
    custom.hidden = !enabled;
    custom.required = enabled;
    control.querySelector('small')?.classList.toggle('d-none', !enabled);
  }
  custom.addEventListener('input', () => {
    const digits = custom.value.replace(/\D/g, '').slice(0, 3);
    custom.value = digits.length > 1 ? digits.split('').join('-') : digits;
  });
  select.addEventListener('change', updateCustomFormation);
  updateCustomFormation();
});

document.querySelectorAll('[data-market-history]').forEach(history => {
  const items = [...history.querySelectorAll('[data-history-type]')];
  const filters = [...history.querySelectorAll('[data-history-filter]')];
  const pagination = history.querySelector('.history-pages');
  const perPage = Number(history.dataset.itemsPerPage) || 4;
  let activeFilter = 'todas';
  let currentPage = 1;

  function renderHistory() {
    const visible = items.filter(item => activeFilter === 'todas' || item.dataset.historyType === activeFilter);
    const totalPages = Math.max(1, Math.ceil(visible.length / perPage));
    currentPage = Math.min(currentPage, totalPages);
    items.forEach(item => { item.hidden = true; });
    visible.slice((currentPage - 1) * perPage, currentPage * perPage).forEach(item => { item.hidden = false; });
    pagination.innerHTML = visible.length > perPage ? `<button type="button" data-go="-1" aria-label="Página anterior" ${currentPage === 1 ? 'disabled' : ''}>‹</button><span>${currentPage} / ${totalPages}</span><button type="button" data-go="1" aria-label="Próxima página" ${currentPage === totalPages ? 'disabled' : ''}>›</button>` : '';
  }
  filters.forEach(button => button.addEventListener('click', () => {
    activeFilter = button.dataset.historyFilter;
    currentPage = 1;
    filters.forEach(filter => filter.classList.toggle('active', filter === button));
    renderHistory();
  }));
  pagination.addEventListener('click', event => {
    const button = event.target.closest('button[data-go]');
    if (!button || button.disabled) return;
    currentPage += Number(button.dataset.go);
    renderHistory();
  });
  renderHistory();
});

const marketTitle = document.querySelector('.market-page h1');
const championship = document.querySelector('input[name="campeonato_id"], select[name="campeonato_id"]');
if (marketTitle && championship && document.querySelector('input[value="adicionar_inicial"]')) {
  const link = document.createElement('a');
  link.className = 'btn btn-outline-light mb-4';
  const participant = new URLSearchParams(location.search).get('participante_id');
  link.href = `importar-elenco.php?campeonato_id=${encodeURIComponent(championship.value)}${participant ? `&participante_id=${encodeURIComponent(participant)}` : ''}`;
  link.textContent = 'Colar elenco completo';
  marketTitle.insertAdjacentElement('afterend', link);
}
