(() => {
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (event.defaultPrevented || form.method !== 'post' || !form.closest('.market-page,#lineup-management-modal')) return;
    event.preventDefault(); if (form.dataset.saving === '1') return;
    form.dataset.saving = '1';
    const button = event.submitter || form.querySelector('button:not([type="button"])');
    const label = button?.textContent; const disabled = button?.disabled;
    const controls=[...form.elements].filter(field=>'disabled' in field).map(field=>[field,field.disabled]);
    let committed = false;
    try {
      const body = new FormData(form); body.set('_ajax','1');
      if (button?.name) body.append(button.name,button.value);
      controls.forEach(([field])=>{field.disabled=true;});
      if (button) { button.disabled=true;button.textContent='Salvando…'; }
      const data = await requestJSON(location.href,{method:'POST',body}); committed=true;
      const fresh = new DOMParser().parseFromString(data.html,'text/html');
      const main = document.querySelector('.market-page'), source = fresh.querySelector('.market-page');
      if (!source) throw new Error('Visualização indisponível.');
      const top = window.scrollY;
      const filters = [...document.querySelectorAll('[data-roster-form]')].map(item => ({type:item.dataset.rosterForm,
        values:[...item.querySelectorAll('.roster-tools input,.roster-tools select')].map(field => field.value),
        position:item.querySelector('[data-position-filter][aria-pressed="true"]')?.dataset.positionFilter}));
      const open = form.closest('.modal');
      if (open?.classList.contains('show')) await window.hideSavedModal(open);
      // Registration drafts and filters are managed by the existing roster editor.
      // Only competition components are renewed; the selector, shell and footer stay.
      main.querySelectorAll('.modal').forEach(modal => bootstrap.Modal.getInstance(modal)?.dispose());
      const lineup=document.getElementById('lineup-management-modal');if(lineup){bootstrap.Modal.getInstance(lineup)?.dispose();lineup.remove();}
      const keep = [...main.children].filter(node => node.tagName === 'H1' || node.tagName === 'FORM' && node.method === 'get' || node.classList.contains('eyebrow') || node.classList.contains('market-managed-team'));
      main.replaceChildren(...keep, ...[...source.children].filter(node => !['H1','SCRIPT'].includes(node.tagName) && !(node.tagName === 'FORM' && node.method === 'get') && !node.classList.contains('eyebrow') && !node.classList.contains('market-managed-team')));
      Object.assign(main.dataset,source.dataset);
      const quick=document.getElementById('market-player-edit-modal'); if(quick){bootstrap.Modal.getInstance(quick)?.dispose();quick.remove();}
      window.initializeMarketConfirmations();window.initializeMarket();window.initializeCompetitionRoster();window.initializeMarketQuickEdit();
      filters.forEach(saved => {
        const target=document.querySelector(`[data-roster-form="${saved.type}"]`); if(!target)return;
        target.querySelectorAll('.roster-tools input,.roster-tools select').forEach((field,index)=>{field.value=saved.values[index];field.dispatchEvent(new Event('input'));field.dispatchEvent(new Event('change'));});
        target.querySelector(`[data-position-filter="${saved.position}"]`)?.click();
      });
      window.lucide?.createIcons();window.scrollTo(0,top);window.siteToast?.(data.message || 'Salvo.','success');
    } catch (error) {
      const message = committed ? `Os dados foram salvos, mas a visualização não foi atualizada. ${error.message}` : error.message;
      if(window.siteToast) window.siteToast(message,'danger');
      else {const status=document.createElement('div');status.className='alert alert-danger';status.textContent=message;form.before(status);}
    } finally {
      delete form.dataset.saving;delete form.dataset.confirmedSubmit;
      controls.forEach(([field,wasDisabled])=>{field.disabled=wasDisabled;});
      if(button){button.disabled=disabled;button.textContent=label;}
    }
  });
})();
