// Shared transport: a timeout never implies that a write failed or should be replayed.
(() => {
  if (window.requestJSON) return;
  const nativeFetch = window.fetch.bind(window);
  document.addEventListener('show.bs.modal',event=>{event.target.dataset.asyncModalReady='0';},true);
  document.addEventListener('shown.bs.modal',event=>{event.target.dataset.asyncModalReady='1';},true);
  window.hideSavedModal = element => {
    if(!element?.classList.contains('show'))return Promise.resolve();
    return new Promise((resolve,reject)=>{
      const modal=bootstrap.Modal.getOrCreateInstance(element);
      const cleanup=()=>{element.removeEventListener('hidden.bs.modal',hidden);element.removeEventListener('hide.bs.modal',hiding);element.removeEventListener('shown.bs.modal',hide);};
      const hidden=()=>{cleanup();resolve();};
      const hiding=event=>queueMicrotask(()=>{if(event.defaultPrevented){cleanup();reject(new Error('O fechamento do modal foi cancelado. Feche-o para continuar.'));}});
      const hide=()=>modal.hide();
      element.addEventListener('hidden.bs.modal',hidden,{once:true});element.addEventListener('hide.bs.modal',hiding);
      if(element.dataset.asyncModalReady==='0')element.addEventListener('shown.bs.modal',hide,{once:true});else hide();
    });
  };
  // Existing AJAX modules also get bounded network/body waits without changing APIs.
  window.fetch = async (url, options = {}) => {
    const controller = new AbortController();
    const signal = options.signal;
    const abort = () => controller.abort();
    if (signal?.aborted) abort(); else signal?.addEventListener('abort', abort, {once:true});
    const timer = setTimeout(abort, 45000);
    try {
      const response = await nativeFetch(url, {...options, signal:controller.signal});
      await response.clone().arrayBuffer();
      return response;
    } catch(error) {
      if(controller.signal.aborted)throw new Error('Não foi possível confirmar a operação no prazo. Confira os dados antes de repetir.');
      throw error;
    } finally { clearTimeout(timer); signal?.removeEventListener('abort', abort); }
  };
  window.requestJSON = async (url, options = {}) => {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), options.timeout || 45000);
    try {
      const response = await fetch(url, {...options, signal: controller.signal,
        credentials: 'same-origin', headers: {...options.headers, Accept: 'application/json'}});
      const text = await response.text();
      let result;
      try { result = JSON.parse(text); }
      catch (_) { throw new Error(response.redirected ? 'Sua sessão expirou. Entre novamente; os campos foram preservados.' : 'Resposta inválida. Confira os dados antes de tentar salvar novamente.'); }
      if(!result || typeof result !== 'object' || Array.isArray(result))throw new Error('Resposta inválida. Confira os dados antes de tentar salvar novamente.');
      if (!response.ok || result.ok !== true || result.error) throw new Error(result.message || result.error || `Não foi possível confirmar a resposta (HTTP ${response.status}).`);
      return result;
    } catch (error) {
      if (error.name === 'AbortError' || error instanceof TypeError)
        throw new Error('Não foi possível confirmar a operação. Os campos foram preservados. Confira os dados antes de repetir.');
      throw error;
    } finally { clearTimeout(timer); }
  };
})();
