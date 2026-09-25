(() => {
  const formatMoneyInput = input => {
    const digits = input.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    if (!digits) {
      input.value = '';
      return;
    }

    const formatted = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    input.value = input.dataset.externalCurrencyPrefix === '1' ? formatted : `R$ ${formatted}`;
  };

  document.querySelectorAll('input[name="saldo"], input[name="valor"]').forEach(input => {
    const prefix = input.closest('.input-group')?.querySelector('.input-group-text');
    if (prefix?.textContent.trim() === 'R$') input.dataset.externalCurrencyPrefix = '1';

    input.type = 'text';
    input.inputMode = 'numeric';
    input.placeholder = input.dataset.externalCurrencyPrefix === '1' ? '0' : 'R$ 0';
    formatMoneyInput(input);
    input.addEventListener('input', () => formatMoneyInput(input));
  });
})();
