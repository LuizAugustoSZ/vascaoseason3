// Run with: node tests/club-instant-save-test.js <path-to-jsdom-package>
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {execFileSync} = require('node:child_process');
const {JSDOM} = require(process.argv[2] || 'jsdom');
const read = name => fs.readFileSync(`assets/js/${name}.js`, 'utf8');
const tick = () => new Promise(resolve => setTimeout(resolve, 15));
const renderGeneral = variant => execFileSync('php', ['tests/instant-save-fixture.php', variant || ''], {encoding:'utf8'});
const domFor = async html => {
  const dom = new JSDOM(html, {url:'http://localhost/elenco-geral.php', runScripts:'outside-only', pretendToBeVisual:true});
  const w = dom.window;
  w.scrollTo = options => { w.lastScroll = options; };
  w.bootstrap = {Modal:{getOrCreateInstance:element => ({
    show(){element.classList.add('show');element.dispatchEvent(new w.Event('shown.bs.modal'));},
    hide(){element.classList.remove('show');element.dispatchEvent(new w.Event('hidden.bs.modal'));}
  })}};
  w.fetch = async () => ({ok:true,json:async()=>({packs:{}})});
  await tick();
  w.eval(read('club-instant-save'));
  return dom;
};
const submit = (w, form) => {
  const button = form.querySelector('button:not([type="button"])');
  form.dispatchEvent(new w.SubmitEvent('submit', {bubbles:true,cancelable:true,submitter:button}));
};
(async () => {
  const original = renderGeneral();
  const dom = await domFor(original), w = dom.window, d = w.document;
  w.eval(read('elenco-geral'));w.eval(read('market'));w.eval(read('money-input'));
  d.dispatchEvent(new w.Event('DOMContentLoaded'));
  await tick();
  const purchase = d.querySelector('[name="origem"]').closest('form');
  const purchaseName = purchase.elements.nome;
  purchaseName.value = 'CONTRATAÇÃO EM ANDAMENTO';
  purchaseName.dispatchEvent(new w.Event('input',{bubbles:true}));
  const search = d.querySelector('[data-roster-search]');
  search.value = 'JOGADOR'; search.dispatchEvent(new w.Event('input'));
  d.querySelector('[data-roster-origin]').value = 'passe';
  d.querySelector('[data-roster-origin]').dispatchEvent(new w.Event('change'));
  d.querySelector('[data-roster-pagination] button[aria-label="Página 3"]').click();
  d.querySelector('[data-history-pagination] button[aria-label="Página 2"]').click();
  d.querySelector('[data-edit-player][data-player-id="25"]').click();
  const edit = d.querySelector('#general-player-edit-modal form');
  edit.elements.nome.value = 'COURTOIS EDITADO';
  edit.elements.nome.dispatchEvent(new w.Event('input',{bubbles:true}));
  let requests = 0, complete;
  w.fetch = async (url, options) => {
    assert.equal(url, 'http://localhost/elenco-geral.php');
    assert.equal(options.headers.Accept, 'application/json');
    assert.equal(options.body.get('action'), 'editar_jogador_geral');
    requests++;
    return new Promise(resolve => {complete = () => resolve({ok:true,json:async()=>({ok:true,message:'Salvo.',html:renderGeneral('edited')})});});
  };
  submit(w,edit); submit(w,edit);
  assert.equal(requests,1,'double submission must be suppressed');
  assert.equal(edit.getAttribute('aria-busy'),'true');
  assert.equal(edit.querySelector('.btn-danger').textContent,'Salvando…');
  assert.equal(w.sessionStorage.length,2,'purchase and player drafts are independent');
  complete();await tick();
  assert.equal(purchaseName.value,'CONTRATAÇÃO EM ANDAMENTO');
  assert.equal(search.value,'JOGADOR');
  assert.equal(d.querySelector('[data-roster-origin]').value,'passe');
  assert.equal(d.querySelector('[data-roster-pagination] .active').textContent,'3');
  assert.equal(d.querySelector('[data-history-pagination] .active').textContent,'2');
  assert.equal(w.sessionStorage.length,1,'successful save clears only submitted draft');
  assert.equal(edit.closest('.modal').classList.contains('show'),false);
  assert.equal(edit.getAttribute('aria-busy'),null);
  assert.ok(w.lastScroll,'scroll position is restored after updating');
  // Updated controls must still work without reloading the document.
  d.querySelector('[data-sell-player][data-player-id="25"]').click();
  const sale = d.querySelector('#general-sale-modal form');
  assert.equal(sale.querySelector('[data-sale-name]').textContent,'COURTOIS EDITADO');
  sale.elements.valor.value = '100';
  w.fetch = async () => ({ok:true,json:async()=>({ok:true,message:'Vendido.',html:renderGeneral('sold')})});
  submit(w,sale);
  assert.equal(d.querySelector('#club-confirmation-modal').classList.contains('show'),true);
  d.querySelector('[data-confirm-accept]').click();await tick();
  assert.equal(d.querySelector('[data-player-id="25"]'),null);
  assert.equal(purchaseName.value,'CONTRATAÇÃO EM ANDAMENTO');
  assert.equal(d.querySelector('[data-roster-pagination] .active').textContent,'3');
  // Failed exchange keeps replacement, outgoing ID, and unrelated draft.
  d.querySelector('[data-trade-player][data-player-id="26"]').click();
  const trade = d.querySelector('#general-trade-modal form');
  trade.elements.adicionar_recebido.checked = true;
  trade.elements.adicionar_recebido.dispatchEvent(new w.Event('change',{bubbles:true}));
  trade.elements.nome_recebido.value = 'NOVA CARTA';
  trade.elements.overall_recebido.value = '93';
  w.fetch = async () => ({ok:false,json:async()=>({ok:false,message:'Ciclo fechado.'})});
  submit(w,trade);await tick();
  assert.equal(trade.elements.nome_recebido.value,'NOVA CARTA');
  assert.equal(trade.elements.jogador_id.value,'26');
  assert.equal(trade.closest('.modal').classList.contains('show'),true);
  assert.equal(trade.querySelector('[data-trade-confirm]').disabled,false);
  assert.ok(d.body.textContent.includes('Ciclo fechado.'));
  assert.equal(w.sessionStorage.length,2);
  // Opening another player must not restore this player's replacement.
  w.bootstrap.Modal.getOrCreateInstance(trade.closest('.modal')).hide();
  d.querySelector('[data-trade-player][data-player-id="24"]').click();
  assert.equal(trade.elements.nome_recebido.value,'');
  w.bootstrap.Modal.getOrCreateInstance(trade.closest('.modal')).hide();
  d.querySelector('[data-trade-player][data-player-id="26"]').click();
  assert.equal(trade.elements.nome_recebido.value,'NOVA CARTA');
  assert.equal(trade.querySelector('[data-trade-replacement]').disabled,false);
  dom.window.close();
  console.log('Instant saves: edit, sell, exchange error, drafts, scroll, filters, pages and duplicate submission OK.');
  const teamHtml = image => `<body data-instant-save-scope="1:1"><div class="future-grid"><article class="lineup-placeholder">
    <button type="button" data-lineup-tab="image" ${image?'':'disabled'}>Escalação</button><button type="button" class="active" data-lineup-tab="players">Jogadores</button>
    <div data-lineup-panel="image" hidden>${image?'<img class="lineup-image" src="fixture.webp">':''}</div><div data-lineup-panel="players">Onze titulares</div></article>
    <article class="treasury-module">R$ 200</article><article class="favorite-player-module">Courtois</article><article class="about-module">Texto salvo</article></div>
    <div class="modal" id="club-about-modal"><form method="post"><input type="hidden" name="action" value="atualizar_sobre_clube"><textarea name="descricao">Texto salvo</textarea><button>Salvar</button></form></div>
    <div class="modal" id="club-treasury-modal"><form method="post"><input type="hidden" name="action" value="atualizar_cofre_clube"><input name="saldo" value="200"><button>Salvar</button></form></div>
    <div class="modal" id="lineup-image-modal"><div class="modal-content"><form method="post" data-lineup-upload-form><input type="hidden" name="action" value="salvar_imagem_escalacao">
    <label data-lineup-dropzone><input type="file" name="imagem_escalacao"><img data-lineup-preview hidden><span data-lineup-drop-copy>Imagem</span></label>
    <div class="modal-footer">${image?'<button form="lineup-image-remove-form">Remover</button>':''}<button>Enviar</button></div></form>
    ${image?'<form method="post" id="lineup-image-remove-form"><input type="hidden" name="action" value="remover_imagem_escalacao"></form>':''}</div></div></body>`;
  const team = await domFor(teamHtml(false)), tw = team.window, td = tw.document;
  tw.eval(read('team-page'));tw.eval(read('market'));tw.eval(read('money-input'));td.dispatchEvent(new tw.Event('DOMContentLoaded'));await tick();
  const about = td.querySelector('#club-about-modal form'), treasury = td.querySelector('#club-treasury-modal form');
  tw.bootstrap.Modal.getOrCreateInstance(about.closest('.modal')).show();
  about.elements.descricao.value = 'TEXTO EM ANDAMENTO';about.elements.descricao.dispatchEvent(new tw.Event('input',{bubbles:true}));
  tw.bootstrap.Modal.getOrCreateInstance(about.closest('.modal')).hide();
  tw.bootstrap.Modal.getOrCreateInstance(treasury.closest('.modal')).show();
  tw.fetch = async () => ({ok:true,json:async()=>({ok:true,message:'Cofre salvo',html:teamHtml(false)})});
  submit(tw,treasury);td.querySelector('[data-confirm-accept]').click();await tick();
  assert.equal(td.querySelector('.treasury-module').textContent,'R$ 200');
  assert.equal(about.elements.descricao.value,'TEXTO EM ANDAMENTO');
  assert.equal(tw.sessionStorage.length,1);
  const upload = td.querySelector('[data-lineup-upload-form]');
  tw.bootstrap.Modal.getOrCreateInstance(upload.closest('.modal')).show();
  tw.fetch = async () => ({ok:true,json:async()=>({ok:true,message:'Imagem salva',html:teamHtml(true)})});
  submit(tw,upload);await tick();
  assert.equal(td.querySelector('[data-lineup-tab="players"]').classList.contains('active'),true);
  assert.equal(td.querySelector('[data-lineup-tab="image"]').disabled,false);
  assert.equal(td.querySelector('[data-lineup-preview]').getAttribute('src'),'fixture.webp');
  assert.equal(td.querySelector('[data-lineup-preview]').hidden,false);
  assert.ok(td.getElementById('lineup-image-remove-form'),'upload creates functional remove form');
  td.querySelector('[data-lineup-tab="image"]').click();
  tw.bootstrap.Modal.getOrCreateInstance(upload.closest('.modal')).show();
  tw.fetch = async () => ({ok:true,json:async()=>({ok:true,message:'Imagem removida',html:teamHtml(false)})});
  submit(tw,td.getElementById('lineup-image-remove-form'));await tick();
  assert.equal(td.querySelector('[data-lineup-tab="players"]').classList.contains('active'),true);
  assert.equal(td.querySelector('[data-lineup-tab="image"]').disabled,true);
  assert.equal(td.getElementById('lineup-image-remove-form'),null);
  assert.equal(td.querySelector('[data-lineup-preview]').hidden,true);
  assert.equal(about.elements.descricao.value,'TEXTO EM ANDAMENTO');
  assert.equal(tw.sessionStorage.length,1);
  team.window.close();
  console.log('Team saves: cofre, independent about draft, upload/remove image and active lineup tab OK.');
})().catch(error => {console.error(error);process.exitCode=1;});
