// Loaded only by Elenco Geral and the club page. Existing confirmations run first.
window.ClubInstantSave = {
  initialize({actions, update, afterSave}) {
    const forms = () => [...document.querySelectorAll('form[method="post"]')].filter(form => actions.includes(form.elements.action?.value));
    const scope = document.body.dataset.instantSaveScope;
    const key = form => `club-draft:${location.pathname}:${scope}:${form.elements.action.value}:${form.elements.jogador_id?.value || form.elements.movimentacao_id?.value || form.elements.campeonato_id?.value || ''}`;
    const fields = form => [...form.elements].filter(field => field.name && !['hidden', 'file', 'password', 'submit', 'button'].includes(field.type) && field.tagName !== 'FIELDSET');
    const store = form => {
      if (form.dataset.instantSaving === '1') return;
      try { sessionStorage.setItem(key(form), JSON.stringify(fields(form).map(field => ({name:field.name, value:field.value, checked:field.checked})))); } catch (_) {}
    };
    const clear = form => { try { sessionStorage.removeItem(key(form)); } catch (_) {} };
    const restore = form => {
      form.dataset.instantRestoring = '1';
      try {
        const saved = JSON.parse(sessionStorage.getItem(key(form)) || 'null');
        if (!Array.isArray(saved)) return;
        saved.forEach(item => {
          const field = fields(form).find(field => field.name === item.name);
          if (!field) return;
          if (field.type === 'checkbox' || field.type === 'radio') field.checked = Boolean(item.checked);
          else if (field.tagName !== 'SELECT' || [...field.options].some(option => option.value === item.value)) field.value = item.value;
          field.dataset.instantDirty = '1';
          field.dispatchEvent(new Event('change', {bubbles:true}));
        });
      } catch (_) {} finally { delete form.dataset.instantRestoring; }
    };
    const notice = document.createElement('div');
    notice.setAttribute('role', 'status');
    notice.hidden = true;
    Object.assign(notice.style, {position:'fixed', bottom:'20px', right:'20px', zIndex:'20000', maxWidth:'min(420px, calc(100vw - 40px))'});
    document.body.append(notice);
    let noticeTimer, pending = false;
    const showNotice = (message, error = false) => {
      clearTimeout(noticeTimer);
      notice.className = `alert alert-${error ? 'danger' : 'success'}`;
      notice.textContent = message;
      notice.hidden = false;
      if (!error) noticeTimer = setTimeout(() => { notice.hidden = true; }, 4500);
    };
    forms().forEach(form => {
      ['input','change'].forEach(type => form.addEventListener(type, event => {
        if (form.dataset.instantRestoring === '1' || (form.closest('.modal') && !form.closest('.modal').classList.contains('show'))) return;
        if (fields(form).includes(event.target)) { event.target.dataset.instantDirty = '1'; delete form.dataset.confirmedSubmit; store(form); }
      }));
      const modal = form.closest('.modal');
      if (modal) {
        modal.addEventListener('shown.bs.modal', () => restore(form));
        modal.addEventListener('hide.bs.modal', event => { if (pending && modal.querySelector('[data-instant-saving="1"]')) event.preventDefault(); });
      } else restore(form);
    });
    document.addEventListener('submit', async event => {
      const form = event.target;
      if (!forms().includes(form) || event.defaultPrevented) return;
      event.preventDefault();
      if (pending || !form.reportValidity()) return;
      store(form);
      const body = new FormData(form);
      if (event.submitter?.name) body.append(event.submitter.name, event.submitter.value);
      const controls = [...form.elements, ...document.querySelectorAll(`button[form="${form.id}"]`)].filter(field => 'disabled' in field);
      const disabled = controls.map(field => [field, field.disabled]);
      const button = event.submitter;
      const label = button?.textContent;
      pending = true; form.dataset.instantSaving = '1'; form.setAttribute('aria-busy', 'true');
      controls.forEach(field => { field.disabled = true; });
      if (button) button.textContent = 'Salvando…';
      let committed = false;
      try {
        const result = await requestJSON(form.getAttribute('action') || location.href, {method:'POST', body});
        if (!result.ok) throw new Error(result.message || 'Não foi possível salvar.');
        committed = true;
        clear(form);
        if (typeof result.html !== 'string' || !result.html) throw new Error('Visualização indisponível.');
        const fresh = new DOMParser().parseFromString(result.html, 'text/html');
        if (fresh.body.dataset.instantSaveScope !== scope) throw new Error('Visualização indisponível.');
        const scroll = {x:window.scrollX, y:window.scrollY};
        update(fresh, form);
        // Synchronize defaults without erasing other forms the user is filling out.
        forms().forEach(other => {
          const source = [...fresh.querySelectorAll('form[method="post"]')].find(item => item.elements.action?.value === other.elements.action.value);
          if (!source) return;
          fields(other).forEach(field => {
            const next = source.elements.namedItem(field.name);
            if (!next || (other !== form && field.dataset.instantDirty === '1')) return;
            field.defaultValue = next.value;
            if (field.tagName === 'SELECT') [...field.options].forEach(option => { option.defaultSelected = option.value === next.value; });
            if (other !== form) field.value = next.value;
          });
        });
        delete form.dataset.instantSaving;
        const modal = form.closest('.modal');
        if (modal) {
          await window.hideSavedModal(modal);
        }
        fields(form).forEach(field => { delete field.dataset.instantDirty; });
        afterSave?.(fresh, form);
        clear(form);
        window.scrollTo({left:scroll.x, top:scroll.y, behavior:'instant'});
        requestAnimationFrame(() => window.scrollTo({left:scroll.x, top:scroll.y, behavior:'instant'}));
        showNotice(result.message || 'Salvo.');
      } catch (error) {
        const message = committed ? 'Os dados foram salvos, mas não foi possível atualizar a visualização. Atualize a página para conferir.' : error instanceof SyntaxError || error instanceof TypeError ? 'Não foi possível confirmar o salvamento. Seus campos foram preservados; confira os dados antes de repetir.' : error.message;
        showNotice(message, true);
      } finally {
        disabled.forEach(([field, wasDisabled]) => { field.disabled = wasDisabled; });
        if (button) button.textContent = label;
        delete form.dataset.instantSaving; delete form.dataset.confirmedSubmit;
        form.removeAttribute('aria-busy'); pending = false;
      }
    });
  }
};
