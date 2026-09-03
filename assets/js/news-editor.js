(() => {
  const editor = document.getElementById('news-editor');
  const form = document.getElementById('news-form');
  if (!editor || !form) return;
  const coverFile = document.getElementById('cover-file');
  const coverData = document.getElementById('cover-data');
  const coverPreview = document.getElementById('cover-preview');
  const bodyImage = document.getElementById('body-image');
  let savedRange = null;

  const compressImage = (file, maxWidth, maxHeight, quality = .78) => new Promise((resolve, reject) => {
    const image = new Image();
    const url = URL.createObjectURL(file);
    image.onload = () => {
      const scale = Math.min(1, maxWidth / image.width, maxHeight / image.height);
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(image.width * scale);
      canvas.height = Math.round(image.height * scale);
      canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
      URL.revokeObjectURL(url);
      resolve(canvas.toDataURL('image/webp', quality));
    };
    image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Não foi possível abrir a imagem.')); };
    image.src = url;
  });

  document.querySelectorAll('#tab-noticias .editor-toolbar [data-command]').forEach(button => button.addEventListener('click', () => {
    editor.focus(); document.execCommand(button.dataset.command, false);
  }));
  document.querySelectorAll('#tab-noticias .editor-toolbar [data-block]').forEach(button => button.addEventListener('click', () => {
    editor.focus(); document.execCommand('formatBlock', false, button.dataset.block);
  }));
  document.getElementById('insert-image')?.addEventListener('click', () => {
    const selection = window.getSelection();
    savedRange = selection.rangeCount && editor.contains(selection.getRangeAt(0).commonAncestorContainer) ? selection.getRangeAt(0).cloneRange() : null;
    bodyImage.click();
  });
  coverFile.addEventListener('change', async () => {
    if (!coverFile.files[0]) return;
    try {
      coverData.value = await compressImage(coverFile.files[0], 1200, 700, .72);
      coverPreview.src = coverData.value; coverPreview.classList.remove('d-none');
    } catch (error) { showToast(error.message, 'danger'); }
  });
  bodyImage.addEventListener('change', async () => {
    if (!bodyImage.files[0]) return;
    try {
      const source = await compressImage(bodyImage.files[0], 1200, 1000, .72);
      const html = `<p><img src="${source}" alt="Imagem da notícia"></p><p><br></p>`;
      editor.focus();
      const selection = window.getSelection();
      if (savedRange && editor.contains(savedRange.commonAncestorContainer)) {
        selection.removeAllRanges(); selection.addRange(savedRange); document.execCommand('insertHTML', false, html);
      } else editor.insertAdjacentHTML('beforeend', html);
      savedRange = null; bodyImage.value = '';
    } catch (error) { showToast(error.message, 'danger'); }
  });

  const resetForm = () => {
    form.reset(); form.noticia_id.value = ''; coverData.value = ''; coverPreview.src = ''; coverPreview.classList.add('d-none'); editor.innerHTML = '';
    document.getElementById('news-submit').textContent = 'Publicar notícia';
    const notice = document.getElementById('news-editing'); notice.classList.add('d-none'); notice.classList.remove('d-flex');
  };
  document.getElementById('cancel-news-edit')?.addEventListener('click', resetForm);
  let news = [];
  try { news = JSON.parse(document.getElementById('news-admin-data')?.textContent || '[]'); } catch (_) {}
  document.querySelectorAll('.editar-noticia').forEach(button => button.addEventListener('click', () => {
    const item = news.find(entry => String(entry.id) === button.dataset.id); if (!item) return;
    form.noticia_id.value = item.id; form.titulo.value = item.titulo; form.resumo.value = item.resumo; coverData.value = item.capa_base64;
    coverPreview.src = item.capa_base64; coverPreview.classList.remove('d-none'); editor.innerHTML = item.conteudo;
    document.getElementById('news-submit').textContent = 'Atualizar notícia';
    const notice = document.getElementById('news-editing'); notice.querySelector('span').textContent = `Editando: ${item.titulo}`; notice.classList.remove('d-none'); notice.classList.add('d-flex');
    form.scrollIntoView({behavior: 'smooth', block: 'start'});
  }));
  const requestedNewsId = new URLSearchParams(location.search).get('editar_noticia');
  if (requestedNewsId) document.querySelector(`.editar-noticia[data-id="${CSS.escape(requestedNewsId)}"]`)?.click();
  const confirmNewsRemoval = title => new Promise(resolve => {
    let element = document.getElementById('admin-news-delete-modal');
    if (!element) {
      element = document.createElement('div'); element.id = 'admin-news-delete-modal'; element.className = 'modal fade news-delete-modal'; element.tabIndex = -1;
      element.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><small class="eyebrow">Exclusão segura</small><h2 class="modal-title">REMOVER NOTÍCIA?</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><p data-delete-message></p><div class="alert alert-secondary mb-0">O registro será ocultado do site, mas continuará preservado no banco de dados.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-danger" data-delete-confirm>Sim, remover</button></div></div></div>';
      document.body.append(element);
    }
    element.querySelector('[data-delete-message]').textContent = `“${title}” deixará de aparecer no jornal e nos regulamentos.`;
    const modal = bootstrap.Modal.getOrCreateInstance(element); let answered = false;
    const accept = () => { answered = true; modal.hide(); resolve(true); };
    const closed = () => { element.querySelector('[data-delete-confirm]').removeEventListener('click', accept); if (!answered) resolve(false); };
    element.querySelector('[data-delete-confirm]').addEventListener('click', accept, {once: true});
    element.addEventListener('hidden.bs.modal', closed, {once: true});
    modal.show();
  });
  form.addEventListener('submit', async event => {
    event.preventDefault(); event.stopImmediatePropagation();
    if (!coverData.value) return showToast('Selecione uma imagem de capa.', 'danger');
    if (!editor.textContent.trim() && !editor.querySelector('img')) { editor.focus(); return showToast('Escreva o conteúdo da matéria.', 'danger'); }
    document.getElementById('news-content').value = editor.innerHTML;
    const button = event.submitter || document.getElementById('news-submit'); const old = button.textContent; button.disabled = true; button.textContent = 'Salvando...';
    try {
      const payload = new FormData(form); payload.set('_ajax', '1');
      const response = await fetch('index.php', {method: 'POST', body: payload, headers: {Accept: 'application/json'}, credentials: 'same-origin'}); const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'Não foi possível salvar.');
      await refreshTab(data.message);
    } catch (error) { button.disabled = false; button.textContent = old; showToast(error.message, 'danger'); }
  });
  document.querySelectorAll('.admin-news-list form[method="post"]').forEach(deleteForm => deleteForm.addEventListener('submit', async event => {
    event.preventDefault(); event.stopImmediatePropagation();
    const title = deleteForm.closest('article')?.querySelector('h3')?.textContent?.trim() || 'Esta notícia';
    if (!await confirmNewsRemoval(title)) return;
    try {
      const payload = new FormData(deleteForm); payload.set('_ajax', '1');
      const response = await fetch('index.php', {method: 'POST', body: payload, headers: {Accept: 'application/json'}, credentials: 'same-origin'}); const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'Não foi possível apagar.'); await refreshTab(data.message);
    } catch (error) { showToast(error.message, 'danger'); }
  }));

  const list = document.querySelector('.admin-news-list');
  if (list) {
    const items = [...list.querySelectorAll(':scope > article')];
    if (items.length) {
      const controls = document.createElement('div'); controls.className = 'p-3 border-bottom'; controls.innerHTML = '<input class="form-control form-control-sm" type="search" placeholder="Pesquisar notícia ou autor...">'; list.before(controls);
      const pager = document.createElement('div'); pager.className = 'd-flex justify-content-between align-items-center p-3 border-top'; list.after(pager); let page = 1;
      const render = () => { const q = controls.querySelector('input').value.toLocaleLowerCase('pt-BR').trim(); const filtered = items.filter(item => !q || item.textContent.toLocaleLowerCase('pt-BR').includes(q)); const pages = Math.max(1, Math.ceil(filtered.length / 5)); page = Math.min(page, pages); const visible = new Set(filtered.slice((page - 1) * 5, page * 5)); items.forEach(item => item.classList.toggle('d-none', !visible.has(item))); pager.innerHTML = `<span class="text-secondary small">${filtered.length} postagem${filtered.length === 1 ? '' : 's'}</span>${filtered.length > 5 ? `<div class="d-flex gap-2 align-items-center"><button class="btn btn-sm btn-outline-light prev" ${page === 1 ? 'disabled' : ''}>Anterior</button><span class="small text-secondary">Página ${page} de ${pages}</span><button class="btn btn-sm btn-outline-light next" ${page === pages ? 'disabled' : ''}>Próxima</button></div>` : ''}`; };
      controls.addEventListener('input', () => { page = 1; render(); }); pager.addEventListener('click', event => { if (event.target.closest('.prev')) page--; else if (event.target.closest('.next')) page++; else return; render(); }); render();
    }
  }

  async function refreshTab(message) {
    const response = await fetch(`index.php?tab=noticias&_refresh=${Date.now()}`, {cache: 'no-store', credentials: 'same-origin'}); const html = await response.text(); const fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('tab-noticias');
    if (!response.ok || !fresh) throw new Error('A notícia foi salva, mas não foi possível atualizar a listagem.');
    fresh.classList.add('show', 'active'); document.getElementById('tab-noticias').replaceWith(fresh); history.replaceState(null, '', 'index.php?tab=noticias');
    for (const file of ['news-editor.js', 'news-round-prompt.js']) { const script = document.createElement('script'); script.src = `../assets/js/${file}?v=${Date.now()}`; document.body.append(script); }
    showToast(message, 'success');
  }
  function showToast(message, type) { const toast = document.createElement('div'); toast.className = `alert alert-${type} position-fixed top-0 start-50 translate-middle-x mt-3 shadow`; toast.style.zIndex = '2000'; toast.textContent = message; document.body.append(toast); setTimeout(() => toast.remove(), 4000); }
})();
