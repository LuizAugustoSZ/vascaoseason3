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
