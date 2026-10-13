(() => {
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (event.defaultPrevented || !form.matches('[data-async-form]')) return;
    event.preventDefault(); if (form.dataset.busy === '1') return;
    form.dataset.busy = '1';
    const button = event.submitter || form.querySelector('button');
    const label = button?.textContent; const disabled = button?.disabled;
    const controls=[...form.elements].filter(field=>'disabled' in field).map(field=>[field,field.disabled]);
    try {
      const body = new FormData(form); body.set('_ajax','1');
      controls.forEach(([field])=>{field.disabled=true;});
      if (button) { button.disabled = true; button.textContent = 'Salvando…'; }
      const data = await requestJSON(form.getAttribute('action') || location.href, {method:'POST', body});
      let status = form.parentElement.querySelector('[data-save-status]');
      if (!status) { status = document.createElement('div'); status.dataset.saveStatus=''; form.before(status); }
      status.className='alert alert-success'; status.setAttribute('role','status'); status.textContent=data.message;
      if (data.html) {
        const fresh = new DOMParser().parseFromString(data.html,'text/html');
        const target = document.querySelector('[data-import-preview]');
        target.replaceChildren(...fresh.querySelector('[data-import-preview]').childNodes);
      }
      if (data.status) {
        const row = form.closest('tr'); row.cells[4].textContent = data.status;
        form.elements.status.value = data.status === 'ativo' ? 'finalizado' : 'ativo';
        if (button) button.dataset.nextLabel = data.status === 'ativo' ? 'Finalizar' : 'Reabrir';
      }
      if (data.removed) {
        await window.hideSavedModal(form.closest('.modal'));
        document.querySelector('[data-article-reader]')?.replaceChildren(status);
      }
      if (data.next) {
        const link = document.createElement('a'); link.href=data.next; link.className='btn btn-outline-light ms-2'; link.textContent='Continuar'; status.append(link);
        if(/\/(cadastro|trocar-senha|redefinir-senha)\.php$/.test(location.pathname))form.hidden=true;
      }
    } catch (error) {
      let status=form.parentElement.querySelector('[data-save-status]');
      if (!status) {status=document.createElement('div');status.dataset.saveStatus='';form.before(status);}
      status.className='alert alert-danger';status.setAttribute('role','alert');status.textContent=error.message;
    } finally {
      delete form.dataset.busy;
      controls.forEach(([field,wasDisabled])=>{field.disabled=wasDisabled;});
      if (button) {button.disabled=disabled;button.textContent=button.dataset.nextLabel || label;}
    }
  });
})();
