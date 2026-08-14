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

let marketTransferPane = null;
const marketPage = document.querySelector('.market-page');
const lineupSection = document.getElementById('elenco');
const marketSummary = marketPage?.querySelector('.market-summary');
if (marketPage && lineupSection && marketSummary) {
  const oldHelp = marketPage.querySelector('.market-help-grid');
  const configPanel = marketPage.querySelector('.market-config-panel');
  const contractPanel = marketPage.querySelector('.market-contract-panel');
  const historyPanel = marketPage.querySelector('.market-history');
  const tabNav = document.createElement('nav');
  const tabContent = document.createElement('div');
  tabNav.className = 'market-tabs';
  tabNav.setAttribute('aria-label', 'Áreas da gestão do time');
  tabContent.className = 'market-tab-content';
  const championshipForm = marketPage.querySelector('form.mb-4');
  if (oldHelp && championshipForm) championshipForm.before(oldHelp);
  marketSummary.before(tabNav, tabContent);

  function createPane(id, label) {
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.marketTab = id;
    button.textContent = label;
    const pane = document.createElement('section');
    pane.className = 'market-tab-pane';
    pane.dataset.marketPane = id;
    tabNav.append(button);
    tabContent.append(pane);
    return pane;
  }

  marketTransferPane = createPane('transferencias', 'Transferências');
  const lineupPane = createPane('escalacao', 'Escalação');
  const treasuryPane = createPane('cofre', 'Cofre');

  treasuryPane.append(marketSummary);
  if (configPanel) treasuryPane.append(configPanel);
  if (contractPanel) marketTransferPane.append(contractPanel);

  const saleHeading = [...lineupSection.querySelectorAll('h2')].find(title => title.textContent.trim() === 'VENDER JOGADOR');
  if (saleHeading) {
    const saleForm = saleHeading.nextElementSibling;
    const saleDivider = saleHeading.previousElementSibling;
    const salePanel = document.createElement('section');
    salePanel.className = 'panel p-4 mb-4 market-sale-panel';
    salePanel.append(saleHeading);
    if (saleForm?.tagName === 'FORM') salePanel.append(saleForm);
    if (saleDivider?.tagName === 'HR') saleDivider.remove();
    marketTransferPane.append(salePanel);
  }
  if (historyPanel) marketTransferPane.append(historyPanel);

  const lineupLauncher = document.createElement('section');
  lineupLauncher.className = 'panel p-4 lineup-launcher';
  lineupLauncher.innerHTML = '<div><h2>FORMAÇÃO, TITULARES E BANCO</h2><p>Abra o editor para escolher a formação e os onze titulares. Os demais jogadores serão enviados automaticamente ao banco.</p></div><button class="btn btn-danger" type="button" data-bs-toggle="modal" data-bs-target="#lineup-management-modal">Editar escalação</button>';
  lineupPane.append(lineupLauncher);

  const lineupModal = document.createElement('div');
  lineupModal.className = 'modal fade';
  lineupModal.id = 'lineup-management-modal';
  lineupModal.tabIndex = -1;
  lineupModal.setAttribute('aria-hidden', 'true');
  lineupModal.innerHTML = '<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><small class="eyebrow">Gestão do elenco</small><h2 class="modal-title">TITULARES E BANCO</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"></div></div></div>';
  lineupSection.classList.remove('mb-4');
  lineupModal.querySelector('.modal-body').append(lineupSection);
  document.body.append(lineupModal);

  const tabChampionship = marketPage.querySelector('select[name="campeonato_id"], input[name="campeonato_id"]');
  const tabKey = `market-tab-${tabChampionship?.value || 'default'}`;
  function activateMarketTab(id) {
    tabNav.querySelectorAll('[data-market-tab]').forEach(button => button.classList.toggle('active', button.dataset.marketTab === id));
    tabContent.querySelectorAll('[data-market-pane]').forEach(pane => pane.classList.toggle('active', pane.dataset.marketPane === id));
    sessionStorage.setItem(tabKey, id);
  }
  tabNav.addEventListener('click', event => {
    const button = event.target.closest('[data-market-tab]');
    if (button) activateMarketTab(button.dataset.marketTab);
  });
  marketPage.addEventListener('submit', () => {
    const active = tabNav.querySelector('[data-market-tab].active');
    if (active) sessionStorage.setItem(tabKey, active.dataset.marketTab);
  });
  const requestedTab = new URLSearchParams(location.search).get('aba');
  const savedTab = sessionStorage.getItem(tabKey);
  activateMarketTab(['transferencias', 'escalacao', 'cofre'].includes(requestedTab) ? requestedTab : (savedTab || 'transferencias'));
}

const marketTitle = document.querySelector('.market-page h1');
const championship = document.querySelector('input[name="campeonato_id"], select[name="campeonato_id"]');
if (marketTitle && championship && document.querySelector('input[value="adicionar_inicial"]')) {
  const link = document.createElement('a');
  link.className = 'btn btn-outline-light mb-4';
  const participant = new URLSearchParams(location.search).get('participante_id');
  link.href = `importar-elenco.php?campeonato_id=${encodeURIComponent(championship.value)}${participant ? `&participante_id=${encodeURIComponent(participant)}` : ''}`;
  link.textContent = 'Colar elenco completo';
  if (marketTransferPane) marketTransferPane.append(link);
  else marketTitle.insertAdjacentElement('afterend', link);
}
