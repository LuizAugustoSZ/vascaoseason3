function formatBRLInput(input) {
  const digits = input.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
  if (!digits) {
    input.value = '';
    return;
  }
  const padded = digits.padStart(3, '0');
  const integer = padded.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  input.value = `R$ ${integer},${padded.slice(-2)}`;
}

document.querySelectorAll('input[name="saldo"], input[name="valor"]').forEach(input => {
  input.type = 'text';
  input.inputMode = 'decimal';
  input.placeholder = 'R$ 0,00';
  if (input.value) {
    const value = Number(input.value.replace(',', '.'));
    if (Number.isFinite(value)) input.value = `R$ ${value.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
  }
  input.addEventListener('input', () => formatBRLInput(input));
});
