(() => {
  const showToast = (message, type) => {
    if (window.siteToast) return window.siteToast(message, type);
    const notice = document.createElement('div'); notice.className = `alert alert-${type}`; notice.textContent = message; document.body.append(notice);
  };
  const panel = document.getElementById('discord-prompt-panel');
  const selector = document.getElementById('discord-news-id');
  const output = document.getElementById('discord-prompt-output');
  let prompts = {};
  try { prompts = JSON.parse(document.getElementById('discord-prompts-data')?.textContent || '{}'); } catch (_) {}
  const update = () => { if (output) output.value = prompts[selector?.value] || ''; };
  selector?.addEventListener('change', update);
  document.getElementById('show-discord-prompt')?.addEventListener('click', () => {
    panel.classList.remove('d-none'); panel.scrollIntoView({behavior: 'smooth'});
  });
  document.getElementById('copy-discord-prompt')?.addEventListener('click', async () => {
    if (!output.value) return showToast('Selecione uma notícia publicada.', 'warning');
    try { await navigator.clipboard.writeText(output.value); }
    catch (_) { output.select(); document.execCommand('copy'); }
    showToast('Prompt do Discord copiado!', 'success');
  });
  update();

  document.querySelectorAll('.editar-data-jogo').forEach(button => button.addEventListener('click', () => {
    let modal = document.getElementById('game-date-modal');
    if (!modal) {
      modal = document.createElement('div'); modal.id = 'game-date-modal'; modal.className = 'modal fade'; modal.tabIndex = -1;
      modal.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><div class="modal-header"><h2 class="modal-title">EDITAR DATA DO JOGO</h2><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><input type="hidden" name="csrf"><input type="hidden" name="action" value="editar_data_jogo"><input type="hidden" name="origem"><input type="hidden" name="jogo_id"><label class="form-label">Data e horário</label><input type="datetime-local" name="data_partida" class="form-control" required><p class="text-secondary small mt-3">Atualiza apenas o calendário deste jogo.</p></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger">Salvar data</button></div></form></div></div>';
      document.body.append(modal);
    }
    const form = modal.querySelector('form');
    form.elements.csrf.value = document.querySelector('input[name="csrf"]').value;
    form.elements.origem.value = button.dataset.origin;
    form.elements.jogo_id.value = button.dataset.id;
    form.elements.data_partida.value = (button.dataset.date || '').replace(' ', 'T').slice(0, 16);
    bootstrap.Modal.getOrCreateInstance(modal).show();
  }));
})();
