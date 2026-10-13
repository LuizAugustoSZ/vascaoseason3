(() => {
  const nativeDocument = document;
  // Existing modules can initialize a renewed pane without rebinding the shell.
  window.adminDocumentScope = root => root === nativeDocument ? nativeDocument : new Proxy(nativeDocument, {
    get(target, key) {
      if (key === 'getElementById') return id => root.id === id ? root : root.querySelector(`#${CSS.escape(id)}`);
      if (key === 'querySelector') return selector => root.matches(selector) ? root : root.querySelector(selector);
      if (key === 'querySelectorAll') return selector => root.querySelectorAll(selector);
      if (key === 'addEventListener') return () => {};
      const value = target[key]; return typeof value === 'function' ? value.bind(target) : value;
    }
  });
  const notice = (message, error = false) => {
    if (window.siteToast) return window.siteToast(message, error ? 'danger' : 'success');
    const box = document.createElement('div'); box.className = `alert alert-${error ? 'danger' : 'success'}`;
    box.setAttribute('role', 'status'); box.textContent = message; document.querySelector('main').prepend(box);
  };
  const capture = pane => {
    pane?.querySelectorAll('table').forEach((table, index) => {
      const wrapper = table.closest('.table-responsive');
      window.__adminListState ||= {};
      window.__adminListState[`${pane.id}:${index}`] = {
        page: Number(wrapper?.nextElementSibling?.textContent.match(/Página\s+(\d+)/i)?.[1] || 1),
        values: [...wrapper?.previousElementSibling?.querySelectorAll('input,select') || []].map(field => field.value)
      };
      if(table.querySelector('#artilheiros-admin-body')){
        const values={};['artilheiros-admin-filter','artilheiros-admin-search','artilheiros-admin-team'].forEach(id=>{values[id]=document.getElementById(id)?.value||'';});
        window.__adminListState[`${pane.id}:${index}`]={values,page:Number(document.getElementById('artilheiros-admin-empty')?.nextElementSibling?.textContent.match(/Página\s+(\d+)/i)?.[1]||1)};
      }
    });
  };
  const drafts = pane => [...pane.querySelectorAll('form')].filter(form => form.id).map(form => ({id: form.id, dataset:{...form.dataset},
    editors:[...form.querySelectorAll('#news-editor,#match-goals-editor,#mata-goals-editor')].map(editor=>({id:editor.id,html:editor.innerHTML})),
    images:[...form.querySelectorAll('img')].map(img=>({src:img.getAttribute('src'),className:img.className})),
    buttons:[...form.querySelectorAll('button[id]')].map(button=>({id:button.id,html:button.innerHTML})),
    fields: [...form.elements].filter(field => field.name)
      .map(field => ({name:field.name, value:field.type === 'file' ? '' : field.value, checked:field.checked, disabled:field.disabled, required:field.required, readOnly:field.readOnly, file:field.type === 'file' && field.files.length ? field : null}))}));
  window.refreshAdminPane = async (tab, message, submitted) => {
    const pane = document.getElementById(`tab-${tab}`);
    if (!pane) { notice(message || 'Salvo.'); return; }
    capture(pane);
    const retained = drafts(pane).filter(item => item.id !== submitted?.id);
    const top = window.scrollY;
    const result = await requestJSON(`index.php?_fragment=1&tab=${encodeURIComponent(tab)}`, {cache:'no-store'});
    const fresh = new DOMParser().parseFromString(result.html, 'text/html');
    const source = fresh.getElementById(pane.id);
    if (!source) throw new Error('Não foi possível atualizar esta listagem.');
    const modal = submitted?.closest('.modal');
    if (modal?.classList.contains('show')) await window.hideSavedModal(modal);
    pane.querySelectorAll('.modal').forEach(item => bootstrap.Modal.getInstance(item)?.dispose());
    pane.replaceChildren(...source.childNodes);
    window.adminLeagueGames = result.games; window.adminMataGames = result.mataGames;
    window.adminMarketPacks = result.packs;
    window.initializeAdmin?.(pane);
    window.initializeAdminLists?.();
    if (tab === 'noticias') { window.initializeNewsEditor?.(); window.initializeNewsRoundPrompt?.(); }
    if (tab === 'mercado') window.initializeAdminPackImport?.();
    window.initializeAdminSharing?.(pane);
    const delayedFields=[];
    retained.forEach(draft => {
      const form = document.getElementById(draft.id); if (!form) return;
      Object.assign(form.dataset,draft.dataset);
      draft.editors.forEach(saved=>{const editor=form.querySelector(`#${CSS.escape(saved.id)}`);if(editor)editor.innerHTML=saved.html;});
      form.querySelectorAll('img').forEach((img,index)=>{const saved=draft.images[index];if(saved){if(saved.src)img.setAttribute('src',saved.src);else img.removeAttribute('src');img.className=saved.className;}});
      draft.buttons.forEach(saved=>{const button=form.querySelector(`#${CSS.escape(saved.id)}`);if(button)button.innerHTML=saved.html;});
      const occurrences={};
      draft.fields.forEach(saved => {
        const index=occurrences[saved.name]||0;occurrences[saved.name]=index+1;
        const named=form.elements.namedItem(saved.name);
        const field = named?.tagName ? named : named?.[index];
        if (!field || !field.tagName) {delayedFields.push({id:draft.id,saved,index});return;}
        if (saved.file) { field.replaceWith(saved.file); return; }
        if (field.type === 'file') return;
        field.value = saved.value; field.checked = saved.checked; field.disabled = saved.disabled;field.required=saved.required;field.readOnly=saved.readOnly;
      });
    });
    // Restore existing fields immediately. Only newly fetched selectors wait.
    await window.adminLoading?.settled?.();
    delayedFields.forEach(({id,saved,index})=>{
      const named=document.getElementById(id)?.elements.namedItem(saved.name);
      const field=named?.tagName ? named : named?.[index];if(!field)return;
      field.value=saved.value;field.disabled=saved.disabled;field.required=saved.required;
    });
    window.lucide?.createIcons(); window.scrollTo(0, top); notice(message || 'Salvo.');
  };
  document.addEventListener('click', event => {
    const button=event.target.closest('.admin-side-nav [data-bs-target]');
    const pane=button && document.querySelector(button.dataset.bsTarget);
    if(pane?.dataset.stale){delete pane.dataset.stale;window.refreshAdminPane(pane.id.replace('tab-','')).catch(error=>notice(error.message,true));}
  });
  window.applyAdminSave = async (data, form) => {
    if (data.game) {
      const game = data.game;
      const collection = game.origin === 'pontos' ? window.adminLeagueGames : window.adminMataGames;
      const stored = collection?.find(item => Number(item.id) === game.id);
      if (stored) stored.data_partida = game.date;
      document.querySelectorAll(`.editar-data-jogo[data-origin="${game.origin}"][data-id="${game.id}"]`).forEach(button => {
        button.dataset.date = game.date;
        button.title=game.formatted;
        const row = button.closest('tr');
        let dateCell = row?.querySelector('[data-game-date]');
        if (!dateCell && row) { dateCell = document.createElement('small'); dateCell.dataset.gameDate = ''; dateCell.className = 'd-block text-secondary'; row.cells[2].append(dateCell); }
        if (dateCell) dateCell.textContent = game.formatted;
      });
      const modal = form.closest('.modal'); if (modal) await window.hideSavedModal(modal);
      notice(`${data.message} ${game.formatted}`); return;
    }
    const tab=data.tab || form.closest('.tab-pane')?.id.replace('tab-', '') || document.querySelector('.tab-content>.tab-pane.active')?.id.replace('tab-','') || 'sumula';
    const action=form.elements.namedItem('action')?.value||'';
    await window.refreshAdminPane(tab, data.message, form);
    // Other panes reconcile when opened, preserving their current drafts in the meantime.
    const affected=/campeonato|supercopa|identidade|partida|mata/.test(action) ? ['campeonatos','jogos','mata','extra','titulos','supercopa','mercado'] : /participante/.test(action) ? ['jogos','mata','extra','usuarios','titulos','supercopa','mercado'] : [];
    affected.filter(id=>id!==tab).forEach(id=>{const pane=document.getElementById(`tab-${id}`);if(pane)pane.dataset.stale='1';});
  };
  // Registered independently of optional editor initialization failures.
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (event.defaultPrevented || form.method.toLowerCase() !== 'post' || !form.elements.namedItem('action')) return;
    if (form.id === 'news-form' || form.closest('.admin-news-list')) return;
    event.preventDefault();
    if (form.dataset.ajaxBusy === '1') return;
    form.dataset.ajaxBusy = '1';
    const button = event.submitter || form.querySelector('button:not([type="button"])');
    const label = button?.textContent; const disabled = button?.disabled;
    const controls=[...form.elements].filter(field=>'disabled' in field).map(field=>[field,field.disabled]);
    let committed = false;
    try {
      const body = new FormData(form); body.set('_ajax', '1');
      if (event.submitter?.name) body.append(event.submitter.name, event.submitter.value);
      controls.forEach(([field])=>{field.disabled=true;});
      if (button) { button.disabled = true; button.textContent = 'Salvando…'; }
      form.setAttribute('aria-busy', 'true');
      const result = await requestJSON(form.getAttribute('action') || 'index.php', {method:'POST', body});
      committed = true; await window.applyAdminSave(result, form);
    } catch (error) {
      notice(committed ? `Os dados foram salvos, mas a visualização não foi atualizada. ${error.message}` : error.message, true);
    } finally {
      delete form.dataset.ajaxBusy; form.removeAttribute('aria-busy');
      controls.forEach(([field,wasDisabled])=>{field.disabled=wasDisabled;});
      if (button) { button.disabled = disabled; button.textContent = label; }
    }
  });
})();
