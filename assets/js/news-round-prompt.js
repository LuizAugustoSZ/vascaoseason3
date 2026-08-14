(() => {
  const championship = document.getElementById('round-prompt-championship');
  const round = document.getElementById('round-prompt-round');
  const generate = document.getElementById('generate-round-prompt');
  const result = document.getElementById('round-prompt-result');
  const output = document.getElementById('round-prompt-output');
  const context = document.getElementById('round-prompt-context');
  const title = document.getElementById('round-prompt-title');
  const count = document.getElementById('round-prompt-count');
  const status = document.getElementById('round-prompt-status');
  const copy = document.getElementById('copy-round-prompt');
  if (!championship || !round || !generate) return;

  const endpoint = 'rodada-prompt.php';
  const showError = message => { context.textContent = message; context.classList.add('text-danger'); };
  const loadRounds = async () => {
    if (!championship.value) return;
    round.disabled = true; generate.disabled = true; context.classList.remove('text-danger');
    context.textContent = 'Identificando a rodada atual...';
    try {
      const response = await fetch(`${endpoint}?campeonato_id=${encodeURIComponent(championship.value)}`, {headers: {'Accept': 'application/json'}});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'Não foi possível consultar as rodadas.');
      round.innerHTML = data.rodadas.map(item => `<option value="${item.rodada}"${item.rodada === data.rodada_atual ? ' selected' : ''}>${item.rodada}ª rodada${item.tem_resultado ? '' : ' — sem resultado'}</option>`).join('');
      round.disabled = !data.rodadas.length; generate.disabled = !data.rodadas.length;
      context.textContent = data.contexto;
    } catch (error) { showError(error.message); }
  };
  championship.addEventListener('change', loadRounds);
  generate.addEventListener('click', async () => {
    generate.disabled = true; generate.textContent = 'GERANDO...'; status.textContent = '';
    try {
      const response = await fetch(`${endpoint}?campeonato_id=${encodeURIComponent(championship.value)}&rodada=${encodeURIComponent(round.value)}`, {headers: {'Accept': 'application/json'}});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'Não foi possível gerar o prompt.');
      output.value = data.prompt; title.textContent = data.contexto; count.textContent = `${data.partidas} partida(s) reunida(s)`;
      result.classList.remove('d-none'); output.scrollTop = 0; status.textContent = 'Prompt pronto para usar no ChatGPT.';
    } catch (error) { showError(error.message); }
    finally { generate.disabled = false; generate.textContent = 'GERAR DADOS E PROMPT'; }
  });
  copy.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(output.value); }
    catch (_) { output.select(); document.execCommand('copy'); }
    status.textContent = 'Prompt copiado! Agora é só colar no ChatGPT.';
  });
  if (championship.value) loadRounds();
})();
