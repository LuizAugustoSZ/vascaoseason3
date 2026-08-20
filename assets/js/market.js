function formatBRLInput(input) {
  const digits = input.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
  if (!digits) {
    input.value = '';
    return;
  }
  const integer = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  input.value = input.dataset.externalCurrencyPrefix === '1' ? integer : `R$ ${integer}`;
}

// Centraliza confirmações de ações que alteram o clube.
const clubConfirmationCopy = {
  comprar: ['CONFIRMAR CONTRATAÇÃO', 'Você realmente quer contratar este jogador?', 'Confirmar contratação'],
  comprar_geral: ['CONTRATAR PARA O ELENCO GERAL', 'Confirma a entrada deste jogador no patrimônio do clube?', 'Confirmar contratação'],
  vender: ['CONFIRMAR VENDA', 'Você realmente quer vender este jogador? Esta ação altera o elenco e o saldo do cofre.', 'Confirmar venda'],
  vender_geral: ['CONFIRMAR VENDA', 'Confirma a venda deste jogador e sua saída do Elenco Geral?', 'Confirmar venda'],
  atualizar_escalacao: ['SALVAR ESCALAÇÃO', 'Confirma a formação e os 11 titulares selecionados?', 'Salvar escalação'],
  confirmar_elenco: ['CONFIRMAR ELENCO', 'Confirma os 11 titulares e deseja iniciar o ciclo deste elenco?', 'Confirmar elenco'],
  configurar_inicial: ['SALVAR CONFIGURAÇÃO', 'Confirma a formação escolhida para o clube?', 'Salvar configuração'],
  importar_elenco_campeonato: ['IMPORTAR ELENCO', 'Deseja importar este elenco? Os jogadores entrarão inicialmente no banco.', 'Confirmar importação'],
  atualizar_inscricao_geral: ['SALVAR INSCRIÇÃO', 'Confirma os jogadores escolhidos para esta competição?', 'Salvar inscrição'],
  confirmar: ['IMPORTAR PARA O ELENCO GERAL', 'Confirma a inclusão dos jogadores novos? Ninguém que já está no clube será removido.', 'Confirmar importação'],
  atualizar_perfil_clube: ['SALVAR PERFIL', 'Confirma as alterações no perfil e no cofre do clube?', 'Salvar perfil'],
  atualizar_sobre_clube: ['SALVAR SOBRE O CLUBE', 'Confirma a publicação deste texto no perfil do clube?', 'Salvar sobre'],
  atualizar_cofre_clube: ['SALVAR COFRE', 'Confirma o novo saldo do cofre do clube?', 'Salvar cofre'],
  atualizar_heroi_clube: ['SALVAR HERÓI', 'Confirma este jogador como herói do time?', 'Salvar herói']
};

let clubConfirmationModal = null;
let clubConfirmationResolve = null;
function getClubConfirmationModal() {
  if (clubConfirmationModal) return clubConfirmationModal;
  const element = document.createElement('div');
  element.className = 'modal fade club-confirmation-modal';
  element.id = 'club-confirmation-modal';
  element.tabIndex = -1;
  element.setAttribute('aria-hidden', 'true');
  element.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><small class="eyebrow">Revise antes de continuar</small><h2 class="modal-title" data-confirm-title>CONFIRMAR AÇÃO</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><p data-confirm-message></p><div class="alert alert-secondary mb-0" data-confirm-detail hidden></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Voltar</button><button type="button" class="btn btn-danger" data-confirm-accept>Confirmar</button></div></div></div>';
  document.body.append(element);
  clubConfirmationModal = bootstrap.Modal.getOrCreateInstance(element, {backdrop: 'static'});
  element.querySelector('[data-confirm-accept]').addEventListener('click', () => {
    const resolve = clubConfirmationResolve;
    clubConfirmationResolve = null;
    clubConfirmationModal.hide();
    resolve?.(true);
  });
  element.addEventListener('hidden.bs.modal', () => {
    if (!clubConfirmationResolve) return;
    const resolve = clubConfirmationResolve;
    clubConfirmationResolve = null;
    resolve(false);
  });
  return clubConfirmationModal;
}

function askClubConfirmation(title, message, acceptLabel, detail = '') {
  const modal = getClubConfirmationModal();
  const element = document.getElementById('club-confirmation-modal');
  element.querySelector('[data-confirm-title]').textContent = title;
  element.querySelector('[data-confirm-message]').textContent = message;
  element.querySelector('[data-confirm-accept]').textContent = acceptLabel;
  const detailBox = element.querySelector('[data-confirm-detail]');
  detailBox.textContent = detail;
  detailBox.hidden = !detail;
  return new Promise(resolve => {
    clubConfirmationResolve = resolve;
    modal.show();
  });
}

function confirmationDetail(form, action) {
  const selectedText = name => form.querySelector(`[name="${name}"]`)?.selectedOptions?.[0]?.textContent.trim() || '';
  const value = name => form.querySelector(`[name="${name}"]`)?.value.trim() || '';
  if (['comprar','comprar_geral'].includes(action)) return [value('nome'), value('overall') && `OVR ${value('overall')}`, selectedText('posicao'), selectedText('origem'), value('valor')].filter(Boolean).join(' · ');
  if (action === 'vender') return [selectedText('jogador_id'), value('valor')].filter(Boolean).join(' · ');
  if (action === 'vender_geral') return [form.querySelector('[data-sale-name]')?.textContent.trim(), value('valor')].filter(Boolean).join(' · ');
  if (action === 'atualizar_escalacao') return `${form.querySelectorAll('input[name="titular_id[]"]:checked').length} titulares selecionados · Formação ${selectedText('formacao')}`;
  if (action === 'atualizar_inscricao_geral') return `${form.querySelectorAll('input[name="titular_geral_id[]"]:checked').length} titulares · ${form.querySelectorAll('input[name="inscrito_id[]"]:checked').length} inscritos no total`;
  if (action === 'importar_elenco_campeonato') return selectedText('campeonato_origem_id');
  return '';
}

document.querySelectorAll('form[method="post"]').forEach(form => {
  const action = form.querySelector('input[name="action"]')?.value;
  const copy = clubConfirmationCopy[action];
  if (!copy) return;
  form.addEventListener('submit', async event => {
    if (form.dataset.confirmedSubmit === '1' || event.defaultPrevented) return;
    event.preventDefault();
    if (!form.reportValidity()) return;
    const confirmed = await askClubConfirmation(copy[0], copy[1], copy[2], confirmationDetail(form, action));
    if (!confirmed) return;
    form.dataset.confirmedSubmit = '1';
    form.requestSubmit(event.submitter || undefined);
  });
});

document.querySelectorAll('input[name="saldo"], input[name="valor"]').forEach(input => {
  const prefix = input.closest('.input-group')?.querySelector('.input-group-text');
  if (prefix?.textContent.trim() === 'R$') input.dataset.externalCurrencyPrefix = '1';
  input.type = 'text';
  input.inputMode = 'decimal';
  input.placeholder = input.dataset.externalCurrencyPrefix === '1' ? '0' : 'R$ 0';
  if (input.value) {
    const value = Number(input.value.replace(',', '.'));
    if (Number.isFinite(value)) {
      const formatted = Math.round(value).toLocaleString('pt-BR', {maximumFractionDigits: 0});
      input.value = input.dataset.externalCurrencyPrefix === '1' ? formatted : `R$ ${formatted}`;
    }
  }
  input.addEventListener('input', () => formatBRLInput(input));
});

const starterInputs = [...document.querySelectorAll('input[name="titular_id[]"]')];
const starterCount = document.querySelector('[data-selected-starters]');
const starterWarning = document.querySelector('.lineup-limit-warning');
let starterWarningTimer = null;
function updateStarterSelection() {
  const selected = starterInputs.filter(input => input.checked).length;
  if (starterCount) starterCount.textContent = selected;
  starterInputs.forEach(input => input.closest('.roster-select-card')?.classList.toggle('is-starter', input.checked));
}
starterInputs.forEach(input => input.addEventListener('change', () => {
  const selected = starterInputs.filter(item => item.checked).length;
  if (input.checked && selected > 11) {
    input.checked = false;
    input.setCustomValidity('Você já selecionou os 11 titulares. Desmarque um jogador antes de escolher outro.');
    input.reportValidity();
    input.setCustomValidity('');
    if (starterWarning) {
      starterWarning.hidden = false;
      starterWarning.classList.remove('is-flashing');
      void starterWarning.offsetWidth;
      starterWarning.classList.add('is-flashing');
      clearTimeout(starterWarningTimer);
      starterWarningTimer = setTimeout(() => {
        starterWarning.hidden = true;
        starterWarning.classList.remove('is-flashing');
      }, 3200);
    }
  }
  updateStarterSelection();
}));
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

document.querySelectorAll('.market-contract-panel form').forEach(form => {
  const origin = form.querySelector('select[name="origem"]');
  const packField = form.querySelector('.acquisition-pack-field');
  const pack = form.querySelector('select[name="pack"]');
  const valueField = form.querySelector('.acquisition-value-field');
  const value = form.querySelector('input[name="valor"]');
  const overall = form.querySelector('input[name="overall"]');
  const note = form.querySelector('.acquisition-note');
  const group = form.querySelector('select[name="grupo"]');
  const replacementField = form.querySelector('.acquisition-replacement-field');
  const replacement = form.querySelector('select[name="substituir_titular_id"]');
  if (!origin) return;

  function updateReplacementField() {
    if (!group || !replacementField || !replacement) return;
    const needed = group.value === 'titular' && Number(form.dataset.currentStarters) >= 11;
    replacementField.hidden = !needed;
    replacement.required = needed;
  }

  function updateAcquisitionFields() {
    const type = origin.value;
    const isDirect = type === 'compra_direta';
    const isPack = type === 'pack';
    packField.hidden = !isPack;
    valueField.hidden = !isDirect;
    pack.required = isPack;
    value.required = isDirect;
    if (isPack) {
      updatePackRange();
    } else if (overall) {
      overall.min = '1';
      overall.max = '99';
    }
    note.hidden = isDirect;
    if (note) note.textContent = isPack
      ? 'O custo do pack será registrado em DP e não altera o cofre em Real.'
      : type === 'passe'
        ? 'Jogador recebido pelo passe: entrada sem custo e sem alteração no cofre.'
        : type === 'prancheta'
          ? 'Jogador recebido pela prancheta: entrada sem custo e sem alteração no cofre.'
          : 'Jogador ganho em sorteio: entrada sem custo e sem alteração no cofre.';
  }
  function updatePackRange() {
    const option = pack.selectedOptions[0];
    if (!option?.dataset.minOvr || !overall) return;
    overall.min = option.dataset.minOvr;
    overall.max = option.dataset.maxOvr;
  }
  origin.addEventListener('change', updateAcquisitionFields);
  group?.addEventListener('change', updateReplacementField);
  pack?.addEventListener('change', updatePackRange);
  updateAcquisitionFields();
  updateReplacementField();
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

const movementEditElement = document.getElementById('market-movement-modal');
if (movementEditElement) {
  const form = movementEditElement.querySelector('form');
  const originField = form.querySelector('.movement-origin-field');
  const origin = form.querySelector('[name="origem"]');
  const packField = form.querySelector('.movement-pack-field');
  const pack = form.querySelector('[name="pack"]');
  const valueField = form.querySelector('.movement-value-field');
  const valueInput = form.querySelector('[name="valor"]');
  const note = form.querySelector('.movement-edit-note');
  let movementType = 'compra';

  function updateMovementEditFields() {
    const isSale = movementType === 'venda';
    const isPack = !isSale && origin.value === 'pack';
    const hasRealValue = isSale || origin.value === 'compra_direta';
    originField.hidden = isSale;
    packField.hidden = !isPack;
    valueField.hidden = !hasRealValue;
    pack.required = isPack;
    valueInput.required = hasRealValue;
    note.textContent = isSale
      ? 'Ao salvar, o sistema corrige a diferença no cofre e mantém o jogador fora do elenco.'
      : hasRealValue
        ? 'A diferença do valor será corrigida automaticamente no cofre.'
        : 'Esta origem não altera o cofre em reais.';
  }
  origin.addEventListener('change', updateMovementEditFields);

  document.querySelectorAll('[data-edit-movement]').forEach(button => button.addEventListener('click', () => {
    movementType = button.dataset.movementType;
    form.querySelector('[name="movimentacao_id"]').value = button.dataset.movementId;
    form.querySelector('[name="nome"]').value = button.dataset.playerName;
    form.querySelector('[name="overall"]').value = button.dataset.playerOverall;
    form.querySelector('[name="posicao"]').value = button.dataset.playerPosition;
    origin.value = movementType === 'venda' ? 'compra_direta' : button.dataset.movementOrigin;
    pack.value = button.dataset.movementPack || '';
    valueInput.value = button.dataset.movementValue ? `R$ ${Math.round(Number(button.dataset.movementValue)).toLocaleString('pt-BR')}` : 'R$ 0';
    movementEditElement.querySelector('.modal-title').textContent = movementType === 'venda' ? 'EDITAR VENDA' : 'EDITAR CONTRATAÇÃO';
    updateMovementEditFields();
    bootstrap.Modal.getOrCreateInstance(movementEditElement).show();
  }));
}

const movementUndoElement = document.getElementById('market-undo-modal');
if (movementUndoElement) {
  const form = movementUndoElement.querySelector('form');
  document.querySelectorAll('[data-undo-movement]').forEach(button => button.addEventListener('click', () => {
    const isSale = button.dataset.movementType === 'venda';
    const player = button.dataset.playerName;
    form.querySelector('[name="movimentacao_id"]').value = button.dataset.movementId;
    movementUndoElement.querySelector('.modal-title').textContent = isSale ? 'DESFAZER VENDA' : 'DESFAZER CONTRATAÇÃO';
    movementUndoElement.querySelector('.movement-undo-copy').textContent = `Tem certeza que deseja desfazer ${isSale ? 'a venda' : 'a contratação'} de ${player}?`;
    movementUndoElement.querySelector('.movement-undo-detail').textContent = isSale
      ? 'O jogador voltará para o banco, o valor recebido será retirado do cofre e o registro sairá do histórico.'
      : 'O jogador sairá do elenco, o valor pago voltará ao cofre quando aplicável e o registro sairá do histórico.';
    bootstrap.Modal.getOrCreateInstance(movementUndoElement).show();
  }));
}

let marketTransferPane = null;
const marketPage = document.querySelector('.market-page');
const lineupSection = document.getElementById('elenco');
const marketSummary = marketPage?.querySelector('.market-summary');
if (false && marketPage && lineupSection && marketSummary) {
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

  marketTransferPane.append(marketSummary);
  if (configPanel) lineupPane.append(configPanel);
  if (contractPanel) marketTransferPane.append(contractPanel);

  const saleHeading = [...lineupSection.querySelectorAll('h2')].find(title => title.textContent.trim() === 'VENDER JOGADOR');
  if (saleHeading) {
    const saleHelp = saleHeading.nextElementSibling?.classList.contains('market-sale-help')
      ? saleHeading.nextElementSibling
      : null;
    const saleForm = saleHelp?.nextElementSibling?.tagName === 'FORM'
      ? saleHelp.nextElementSibling
      : (saleHeading.nextElementSibling?.tagName === 'FORM' ? saleHeading.nextElementSibling : null);
    const saleDivider = saleHeading.previousElementSibling;
    const salePanel = document.createElement('section');
    salePanel.className = 'panel p-4 mb-4 market-sale-panel';
    salePanel.append(saleHeading);
    if (saleHelp) salePanel.append(saleHelp);
    if (saleForm) salePanel.append(saleForm);
    if (saleDivider?.tagName === 'HR') saleDivider.remove();
    marketTransferPane.append(salePanel);
  }
  if (historyPanel) marketTransferPane.append(historyPanel);

  const lineupLauncher = document.createElement('section');
  lineupLauncher.className = 'panel p-4 lineup-launcher';
  lineupLauncher.innerHTML = '<div><h2>FORMAÇÃO, TITULARES E BANCO</h2><p>Escolha a formação e os onze titulares entre os jogadores inscritos nesta competição. A escalação pode ser alterada em qualquer rodada; os demais inscritos ficam no banco.</p></div><button class="btn btn-danger" type="button" data-bs-toggle="modal" data-bs-target="#lineup-management-modal">Editar escalação</button>';
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

  const lineupForm = lineupSection.querySelector('form input[name="action"][value="atualizar_escalacao"]')?.closest('form');
  if (lineupForm) {
    let lineupSnapshot = '';
    let allowLineupClose = false;
    const snapshot = () => [...new FormData(lineupForm).entries()].map(([key, value]) => `${key}=${value}`).sort().join('&');
    lineupModal.addEventListener('shown.bs.modal', () => {
      lineupSnapshot = snapshot();
      allowLineupClose = false;
    });
    lineupModal.addEventListener('hide.bs.modal', event => {
      if (allowLineupClose || snapshot() === lineupSnapshot || lineupForm.dataset.confirmedSubmit === '1') return;
      event.preventDefault();
      askClubConfirmation('SAIR SEM SALVAR?', 'Você alterou a escalação. Tem certeza que deseja fechar e perder essas alterações?', 'Sair sem salvar').then(confirmed => {
        if (!confirmed) return;
        allowLineupClose = true;
        bootstrap.Modal.getOrCreateInstance(lineupModal).hide();
      });
    });
  }

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
  const initialTab = ['transferencias', 'escalacao'].includes(requestedTab)
    ? requestedTab
    : (['transferencias', 'escalacao'].includes(savedTab) ? savedTab : 'transferencias');
  activateMarketTab(initialTab);
}

const marketTitle = document.querySelector('.market-page h1');
const championship = document.querySelector('input[name="campeonato_id"], select[name="campeonato_id"]');
if (marketTitle && championship && marketTransferPane) {
  const panel = document.createElement('section');
  panel.className = 'panel p-4 mb-4 market-import-panel';
  panel.innerHTML = '<div><h2>IMPORTAR OU SUBSTITUIR ELENCO</h2><p class="text-secondary mb-0">Cole a lista do DreamTeam para montar ou reformular todo o elenco. O sistema reconhece nome, OVR e posição automaticamente.</p></div>';
  const link = document.createElement('a');
  link.className = 'btn btn-danger';
  const participant = new URLSearchParams(location.search).get('participante_id');
  link.href = `importar-elenco.php?campeonato_id=${encodeURIComponent(championship.value)}${participant ? `&participante_id=${encodeURIComponent(participant)}` : ''}`;
  link.textContent = 'Colar e analisar elenco';
  if (marketPage.dataset.marketEditable !== '1') {
    link.classList.add('disabled');
    link.setAttribute('aria-disabled', 'true');
    link.removeAttribute('href');
    link.textContent = 'Elenco travado';
  }
  panel.append(link);
  if (marketTransferPane) marketTransferPane.prepend(panel);
  else marketTitle.insertAdjacentElement('afterend', panel);
}
