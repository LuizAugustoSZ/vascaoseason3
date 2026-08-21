(function(){
const adminGroupButtons=[...document.querySelectorAll('[data-admin-group]')],adminSubmenus=[...document.querySelectorAll('[data-admin-submenu]')];
function showAdminGroup(group){adminGroupButtons.forEach(button=>{const active=button.dataset.adminGroup===group;button.classList.toggle('active',active);button.setAttribute('aria-selected',String(active))});adminSubmenus.forEach(menu=>menu.classList.toggle('active',menu.dataset.adminSubmenu===group));}
adminGroupButtons.forEach(button=>button.addEventListener('click',()=>showAdminGroup(button.dataset.adminGroup)));
document.querySelectorAll('.admin-submenu [data-bs-target]').forEach(button=>button.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();const target=document.querySelector(button.dataset.bsTarget);if(!target)return;document.querySelectorAll('.admin-submenu [data-bs-target]').forEach(item=>{item.classList.remove('active');item.setAttribute('aria-selected','false')});document.querySelectorAll('.tab-content>.tab-pane').forEach(pane=>pane.classList.remove('show','active'));button.classList.add('active');button.setAttribute('aria-selected','true');target.classList.add('show','active');showAdminGroup(button.closest('[data-admin-submenu]').dataset.adminSubmenu);const tab=target.id.replace(/^tab-/,'');const url=new URL(location.href);url.searchParams.set('tab',tab);history.replaceState(null,'',url)}));
document.querySelectorAll('[data-admin-frame]').forEach(frame=>frame.addEventListener('load',()=>{try{frame.style.height=`${Math.max(900,frame.contentDocument.documentElement.scrollHeight)}px`}catch(error){}}));
// Lê os gols já digitados antes de redesenhar as linhas do placar.
function currentGoalRows(){return [...document.querySelectorAll('#match-goals-editor .match-goal-row')].map(row=>({participante_id:row.querySelector('[name="gol_time[]"]').value,jogador:row.querySelector('[name="gol_jogador[]"]').value,minuto:row.querySelector('[name="gol_minuto[]"]').value,tipo:row.querySelector('[name="gol_tipo[]"]').value}));}

// Cria uma linha para cada gol informado no placar, separando mandante e visitante.
function renderMatchGoalEditor(existing=currentGoalRows()){
  const form=document.getElementById('form-partida'),editor=document.getElementById('match-goals-editor');if(!form||!editor)return;
  if(form.gols_mandante.value!=='' && form.gols_visitante.value!=='' && form.status.value!=='wo')form.status.value='finalizada';
  if(form.status.value==='wo'){editor.innerHTML='<div class="alert alert-secondary mb-0">Partidas por W.O. não contabilizam artilheiros.</div>';return;}
  const homeTotal=Math.max(0,Number(form.gols_mandante.value||0)),awayTotal=Math.max(0,Number(form.gols_visitante.value||0));
  const homeId=form.mandante_id.value,awayId=form.visitante_id.value;
  const teamName=select=>select.options[select.selectedIndex]?.textContent.split(' — Técnico ')[0]||'Time';
  const safe=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const scorers=[...new Set([...document.querySelectorAll('.editar-artilheiro')].map(button=>button.dataset.jogador).filter(Boolean))];
  const datalist=scorers.length?`<datalist id="known-scorers">${scorers.map(name=>`<option value="${safe(name)}">`).join('')}</datalist>`:'';
  const oldByTeam={};existing.forEach(goal=>(oldByTeam[goal.participante_id]??=[]).push(goal));
  const row=(teamId,team,index)=>{const old=(oldByTeam[teamId]||[])[index]||{};return `<div class="match-goal-row border rounded p-2 mb-2"><input type="hidden" name="gol_time[]" value="${teamId}"><div class="d-flex justify-content-between align-items-center mb-2"><strong>${safe(team)} — Gol ${index+1}</strong><span class="text-secondary small">Detalhes do gol</span></div><div class="row g-2"><div class="col-md-5"><label class="form-label small">Jogador</label><input class="form-control form-control-sm" name="gol_jogador[]" list="known-scorers" value="${safe(old.jogador)}" required></div><div class="col-md-3"><label class="form-label small">Tempo</label><input class="form-control form-control-sm" name="gol_minuto[]" value="${safe(old.minuto)}" placeholder="45+2, 90+6..." maxlength="30" required></div><div class="col-md-4"><label class="form-label small">Tipo</label><select class="form-select form-select-sm" name="gol_tipo[]"><option value="normal" ${old.tipo==='normal'||!old.tipo?'selected':''}>Gol normal</option><option value="penalti" ${old.tipo==='penalti'?'selected':''}>Pênalti</option><option value="falta" ${old.tipo==='falta'?'selected':''}>Gol de falta</option><option value="olimpico" ${old.tipo==='olimpico'?'selected':''}>Gol olímpico</option><option value="contra" ${old.tipo==='contra'?'selected':''}>Gol contra</option></select></div></div></div>`;};
  let html='';for(let i=0;i<homeTotal;i++)html+=row(homeId,teamName(form.mandante_id),i);for(let i=0;i<awayTotal;i++)html+=row(awayId,teamName(form.visitante_id),i);
  editor.innerHTML=html?`<div class="d-flex justify-content-between align-items-center mb-2"><strong>Gols da partida</strong><small class="text-secondary">${homeTotal+awayTotal} registro${homeTotal+awayTotal===1?'':'s'}</small></div>${html}${datalist}`:'';
}

function updateLeagueWo(form){
  const isWo=form.status.value==='wo',box=form.querySelector('[data-wo-winner]');box?.classList.toggle('d-none',!isWo);
  if(form.vencedor_wo_id){const current=form.vencedor_wo_id.value;form.vencedor_wo_id.innerHTML='<option value="">Selecione o vencedor</option>';[form.mandante_id,form.visitante_id].forEach(select=>form.vencedor_wo_id.add(new Option(select.options[select.selectedIndex]?.textContent||'Time',select.value)));form.vencedor_wo_id.value=current;form.vencedor_wo_id.required=isWo;}
  form.gols_mandante.readOnly=isWo;form.gols_visitante.readOnly=isWo;
  if(isWo){form.gols_mandante.value='';form.gols_visitante.value='';}
}
const leagueForm=document.getElementById('form-partida');
if(leagueForm){
  const woOption=[...leagueForm.status.options].find(option=>option.value==='wo');if(woOption)woOption.textContent='Finalizada — W.O.';
  leagueForm.status.closest('[class*="col-"]').insertAdjacentHTML('beforeend','<div class="mt-2 d-none" data-wo-winner><label class="form-label">Time vencedor do W.O.</label><select name="vencedor_wo_id" class="form-select"><option value="">Selecione o vencedor</option></select></div>');
  leagueForm.gols_mandante.addEventListener('input',()=>renderMatchGoalEditor());leagueForm.gols_visitante.addEventListener('input',()=>renderMatchGoalEditor());leagueForm.status.addEventListener('change',()=>{updateLeagueWo(leagueForm);renderMatchGoalEditor()});leagueForm.mandante_id.addEventListener('change',()=>renderMatchGoalEditor());leagueForm.visitante_id.addEventListener('change',()=>renderMatchGoalEditor());updateLeagueWo(leagueForm);
}

// Preenche o formulário dos pontos corridos e recupera os gols detalhados da partida.
document.querySelectorAll('.editar-partida').forEach(botao => botao.addEventListener('click', async () => {
  const form=document.getElementById('form-partida');
  form.partida_id.value=botao.dataset.id;
  form.rodada.value=botao.dataset.rodada;
  // Mantém os times sorteados visíveis, mas bloqueados durante a edição do resultado.
  selecionarTime(form.mandante_id,botao.dataset.mandante);
  selecionarTime(form.visitante_id,botao.dataset.visitante);
  form.mandante_id.disabled=true;
  form.visitante_id.disabled=true;
  if(form.campeonato_id)form.campeonato_id.disabled=true;
  form.gols_mandante.value=botao.dataset.golsMandante;
  form.gols_visitante.value=botao.dataset.golsVisitante;
  form.status.value=botao.dataset.status;
  updateLeagueWo(form);
  if(form.status.value==='wo')selecionarTime(form.vencedor_wo_id,Number(botao.dataset.golsMandante)>Number(botao.dataset.golsVisitante)?botao.dataset.mandante:botao.dataset.visitante);
  const response=await fetch(`partida-dados.php?id=${encodeURIComponent(botao.dataset.id)}`);const data=await response.json();renderMatchGoalEditor(data.gols||[]);
  const aviso=document.getElementById('partida-edicao');
  aviso.querySelector('span').textContent=`Editando: ${botao.dataset.mandante} x ${botao.dataset.visitante}`;
  aviso.classList.remove('d-none');
  aviso.classList.add('d-flex');
  form.scrollIntoView({behavior:'smooth'});
}));

// Preenche o formulário do mata-mata com o confronto escolhido na tabela.
document.querySelectorAll('.editar-mata').forEach(botao => botao.addEventListener('click', async () => {
  const form=document.getElementById('form-mata');
  form.jogo_mata_id.value=botao.dataset.id;
  form.fase.value=botao.dataset.fase;
  form.ordem.value=botao.dataset.ordem;
  // Mantém o confronto sorteado visível, mas impede a troca dos times na edição.
  selecionarTime(form.time_a_id,botao.dataset.timeA);
  selecionarTime(form.time_b_id,botao.dataset.timeB);
  form.time_a_id.disabled=true;
  form.time_b_id.disabled=true;
  if(form.campeonato_id)form.campeonato_id.disabled=true;
  form.gols_a.value=botao.dataset.golsA;
  form.gols_b.value=botao.dataset.golsB;
  const response=await fetch(`mata-dados.php?id=${encodeURIComponent(botao.dataset.id)}`);
  const data=await response.json();
  renderMataGoalEditor(data.gols||[]);
  form.penaltis_a.value=data.jogo?.penaltis_a ?? '';
  form.penaltis_b.value=data.jogo?.penaltis_b ?? '';
  form.dataset.outrosGolsA=data.jogo?.outros_gols_a ?? 0;
  form.dataset.outrosGolsB=data.jogo?.outros_gols_b ?? 0;
  form.status.value=botao.dataset.status;
  form.status.dispatchEvent(new Event('change'));
  updateMataSuggestion(form);
  const aviso=document.getElementById('mata-edicao');
  aviso.querySelector('span').textContent=`Editando: ${botao.dataset.timeA} x ${botao.dataset.timeB}`;
  aviso.classList.remove('d-none');
  aviso.classList.add('d-flex');
  form.scrollIntoView({behavior:'smooth'});
}));

// Sugere o vencedor pelo placar e atualiza automaticamente o status do confronto.
function updateMataSuggestion(form){
  if(form.status.value==='wo')return;
  const goalsA=form.gols_a.value; const goalsB=form.gols_b.value;
  if(goalsA==='' || goalsB===''){form.vencedor_id.value='';form.status.value='agendado';return;}
  const a=Number(goalsA)+Number(form.dataset.outrosGolsA||0),b=Number(goalsB)+Number(form.dataset.outrosGolsB||0),pa=form.penaltis_a.value,pb=form.penaltis_b.value; form.status.value='finalizado';
  form.vencedor_id.value=a>b?form.time_a_id.value:(b>a?form.time_b_id.value:(pa!==''&&pb!==''&&Number(pa)!==Number(pb)?(Number(pa)>Number(pb)?form.time_a_id.value:form.time_b_id.value):''));
}

const mataForm=document.getElementById('form-mata');
if(mataForm){
  if(![...mataForm.status.options].some(option=>option.value==='wo'))mataForm.status.add(new Option('Finalizado — W.O.','wo'));
  if(![...mataForm.fase.options].some(option=>option.value==='Terceiro lugar'))mataForm.fase.add(new Option('Terceiro lugar','Terceiro lugar'));
  const goalsB=mataForm.gols_b.closest('[class*="col-"]');
  goalsB.insertAdjacentHTML('afterend','<div class="col-md-2"><label class="form-label">Pênaltis A</label><input class="form-control" type="number" min="0" name="penaltis_a" placeholder="Opcional"></div><div class="col-md-2"><label class="form-label">Pênaltis B</label><input class="form-control" type="number" min="0" name="penaltis_b" placeholder="Opcional"></div>');
  mataForm.status.required=true;
  mataForm.status.previousElementSibling.textContent='Status *';
  mataForm.vencedor_id.previousElementSibling.textContent='Vencedor sugerido';
  mataForm.gols_a.addEventListener('input',()=>updateMataSuggestion(mataForm));
  mataForm.gols_b.addEventListener('input',()=>updateMataSuggestion(mataForm));
  mataForm.penaltis_a.addEventListener('input',()=>updateMataSuggestion(mataForm));
  mataForm.penaltis_b.addEventListener('input',()=>updateMataSuggestion(mataForm));
  mataForm.status.addEventListener('change',()=>{
    const isWo=mataForm.status.value==='wo';mataForm.vencedor_id.required=isWo;
    mataForm.vencedor_id.previousElementSibling.textContent=isWo?'Time vencedor do W.O. *':'Vencedor sugerido';
    if(isWo){mataForm.gols_a.value='';mataForm.gols_b.value='';mataForm.penaltis_a.value='';mataForm.penaltis_b.value='';renderMataGoalEditor([]);}
  });
}

// Permite escolher a qual campeonato pertence um cadastro manual.
fetch('campeonatos-dados.php').then(response=>response.json()).then(data=>{
  [['form-partida',['pontos_corridos']],['form-mata',['mata_mata','supercopa']]].forEach(([id,types])=>{const form=document.getElementById(id);if(!form)return;const options=(data.campeonatos||[]).filter(item=>types.includes(item.tipo)).map(item=>`<option value="${item.id}">${item.nome} — ${item.status==='finalizado'?'Finalizado':'Em andamento'}</option>`).join('');form.insertAdjacentHTML('afterbegin',`<div class="mb-3"><label class="form-label">Campeonato</label><select class="form-select" name="campeonato_id" required><option value="">Selecione</option>${options}</select></div>`);});
});

// Seleciona no formulário o time exibido na linha escolhida.
function selecionarTime(select,nome){
  const option=[...select.options].find(item=>item.textContent.startsWith(`${nome} —`));
  if(option) select.value=option.value;
}

// Volta o formulário ao modo de cadastro e libera novamente os campos de times.
document.querySelectorAll('.cancelar-edicao').forEach(botao=>botao.addEventListener('click',()=>{
  const form=document.getElementById(botao.dataset.form);
  form.reset();
  form.querySelectorAll('select[name="mandante_id"],select[name="visitante_id"],select[name="time_a_id"],select[name="time_b_id"]').forEach(campo=>campo.disabled=false);
  if(form.campeonato_id)form.campeonato_id.disabled=false;
  form.querySelector('input[name="partida_id"],input[name="jogo_mata_id"]').value='';
  if(form.id==='form-partida')renderMatchGoalEditor([]);
  const aviso=form.querySelector('.alert');
  aviso.classList.add('d-none');
  aviso.classList.remove('d-flex');
}));

// Divide as tabelas de partidas do admin em páginas menores.
function paginateAdminTable(buttonSelector,perPage=5){
  const firstButton=document.querySelector(buttonSelector);
  if(!firstButton)return;
  const table=firstButton.closest('table');
  const rows=[...table.querySelectorAll('tbody tr')];
  if(rows.length<=perPage)return;
  let page=1;
  const totalPages=Math.ceil(rows.length/perPage);
  const pagination=document.createElement('div');
  pagination.className='d-flex justify-content-between align-items-center gap-2 p-3 border-top';
  table.closest('.table-responsive').after(pagination);
  const render=()=>{
    rows.forEach((row,index)=>row.classList.toggle('d-none',index<(page-1)*perPage || index>=page*perPage));
    pagination.innerHTML=`<button type="button" class="btn btn-sm btn-outline-light admin-page-prev" ${page===1?'disabled':''}>Anterior</button><span class="text-secondary small">Página ${page} de ${totalPages}</span><button type="button" class="btn btn-sm btn-outline-light admin-page-next" ${page===totalPages?'disabled':''}>Próxima</button>`;
  };
  pagination.addEventListener('click',event=>{
    if(event.target.closest('.admin-page-prev') && page>1)page--;
    else if(event.target.closest('.admin-page-next') && page<totalPages)page++;
    else return;
    render();
  });
  render();
}

// Adiciona pesquisa, filtro por técnico/time, filtro por rodada e paginação aos pontos corridos.
function setupLeagueAdminTable(perPage=5){
  const firstButton=document.querySelector('.editar-partida');
  if(!firstButton)return;
  const table=firstButton.closest('table');
  const wrapper=table.closest('.table-responsive');
  const rows=[...table.querySelectorAll('tbody tr')];
  const teamOptions=[...document.querySelectorAll('#form-partida select[name="mandante_id"] option')].map(option=>{
    const [team,coach='']=option.textContent.split(' — Técnico ');
    return {team:team.trim(),coach:coach.trim(),label:option.textContent.trim()};
  });
  const rounds=[...new Set(rows.map(row=>row.cells[0].textContent.trim()))].sort((a,b)=>Number(a)-Number(b));
  const filters=document.createElement('div');
  filters.className='row g-2 p-3 border-bottom';
  const safe=value=>String(value).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  filters.innerHTML=`<div class="col-lg-5"><label class="form-label small">Pesquisar jogo</label><input class="form-control form-control-sm admin-game-search" type="search" placeholder="Time, técnico ou confronto..."></div><div class="col-lg-4"><label class="form-label small">Técnico / time</label><select class="form-select form-select-sm admin-team-filter"><option value="">Todos</option>${teamOptions.map(item=>`<option value="${safe(item.team)}">${safe(item.label)}</option>`).join('')}</select></div><div class="col-lg-3"><label class="form-label small">Rodada</label><select class="form-select form-select-sm admin-round-filter"><option value="">Todas</option>${rounds.map(round=>`<option value="${safe(round)}">Rodada ${safe(round)}</option>`).join('')}</select></div><div class="col-12"><span class="text-secondary small admin-filter-count"></span></div>`;
  wrapper.before(filters);
  const pagination=document.createElement('div');
  pagination.className='d-flex justify-content-between align-items-center gap-2 p-3 border-top';
  wrapper.after(pagination);
  const search=filters.querySelector('.admin-game-search');
  const teamFilter=filters.querySelector('.admin-team-filter');
  const roundFilter=filters.querySelector('.admin-round-filter');
  const count=filters.querySelector('.admin-filter-count');
  const currentRound=rounds.find(round=>rows.some(row=>row.cells[0].textContent.trim()===round && !['finalizada','wo'].includes(row.cells[3].textContent.trim().toLocaleLowerCase('pt-BR'))));
  if(currentRound)roundFilter.value=currentRound;
  let page=1;
  const rowSearch=row=>{const text=row.textContent;const people=teamOptions.filter(item=>text.includes(item.team)).map(item=>item.label).join(' ');return `${text} ${people}`.toLocaleLowerCase('pt-BR');};
  const render=()=>{
    const query=search.value.toLocaleLowerCase('pt-BR').trim();
    const filtered=rows.filter(row=>(!query || rowSearch(row).includes(query)) && (!teamFilter.value || row.textContent.includes(teamFilter.value)) && (!roundFilter.value || row.cells[0].textContent.trim()===roundFilter.value));
    const totalPages=Math.max(1,Math.ceil(filtered.length/perPage)); page=Math.min(page,totalPages);
    const visible=new Set(filtered.slice((page-1)*perPage,page*perPage));
    rows.forEach(row=>row.classList.toggle('d-none',!visible.has(row)));
    count.textContent=`${filtered.length} partida${filtered.length===1?'':'s'} encontrada${filtered.length===1?'':'s'}.`;
    pagination.innerHTML=filtered.length>perPage?`<button type="button" class="btn btn-sm btn-outline-light admin-page-prev" ${page===1?'disabled':''}>Anterior</button><span class="text-secondary small">Página ${page} de ${totalPages}</span><button type="button" class="btn btn-sm btn-outline-light admin-page-next" ${page===totalPages?'disabled':''}>Próxima</button>`:'';
  };
  filters.addEventListener('input',()=>{page=1;render()});
  filters.addEventListener('change',()=>{page=1;render()});
  pagination.addEventListener('click',event=>{if(event.target.closest('.admin-page-prev')&&page>1)page--;else if(event.target.closest('.admin-page-next'))page++;else return;render();});
  render();
}

// Ativa a paginação nos pontos corridos e no mata-mata.
setupLeagueAdminTable();

// Troca o campo de URL por upload e converte o escudo para WebP em Base64.
const shieldData=document.querySelector('#tab-times input[name="escudo_url"]');
if(shieldData){
  shieldData.type='hidden';
  const label=shieldData.previousElementSibling;
  if(label)label.textContent='Arquivo do escudo (opcional)';
  const shieldFile=document.createElement('input');
  shieldFile.type='file'; shieldFile.accept='image/png,image/jpeg,image/webp'; shieldFile.className='form-control';
  const preview=document.createElement('img');
  preview.className='team-badge mt-2 d-none'; preview.alt='Prévia do escudo';
  shieldData.after(shieldFile,preview);
  shieldFile.addEventListener('change',()=>{
    const file=shieldFile.files[0]; if(!file)return;
    const image=new Image(); const url=URL.createObjectURL(file);
    image.onload=()=>{
      // Recorta a transparência e centraliza qualquer formato em um arquivo padrão de 500 × 500 px.
      const scan=document.createElement('canvas');scan.width=image.naturalWidth;scan.height=image.naturalHeight;
      const scanContext=scan.getContext('2d',{willReadFrequently:true});scanContext.drawImage(image,0,0);
      const pixels=scanContext.getImageData(0,0,scan.width,scan.height).data;
      let left=scan.width,top=scan.height,right=-1,bottom=-1;
      for(let y=0;y<scan.height;y++)for(let x=0;x<scan.width;x++)if(pixels[(y*scan.width+x)*4+3]>10){left=Math.min(left,x);top=Math.min(top,y);right=Math.max(right,x);bottom=Math.max(bottom,y);}
      if(right<left||bottom<top){left=0;top=0;right=scan.width-1;bottom=scan.height-1;}
      const cropWidth=right-left+1,cropHeight=bottom-top+1;
      const canvas=document.createElement('canvas');canvas.width=500;canvas.height=500;
      const maxShieldSize=470,scale=Math.min(maxShieldSize/cropWidth,maxShieldSize/cropHeight);
      const drawWidth=Math.max(1,Math.round(cropWidth*scale)),drawHeight=Math.max(1,Math.round(cropHeight*scale));
      const drawX=Math.round((canvas.width-drawWidth)/2),drawY=Math.round((canvas.height-drawHeight)/2);
      const context=canvas.getContext('2d');context.imageSmoothingEnabled=true;context.imageSmoothingQuality='high';
      context.drawImage(image,left,top,cropWidth,cropHeight,drawX,drawY,drawWidth,drawHeight);
      URL.revokeObjectURL(url);shieldData.value=canvas.toDataURL('image/webp',.9);preview.src=shieldData.value;preview.classList.remove('d-none');
    };
    image.onerror=()=>{URL.revokeObjectURL(url);alert('Não foi possível abrir esse escudo.');}; image.src=url;
  });
}

// Carrega todos os dados do participante escolhido, inclusive o escudo atual.
const participantDataElement=document.getElementById('participants-admin-data');
const participantForm=document.getElementById('form-participante');
if(participantDataElement && participantForm){
  const participants=JSON.parse(participantDataElement.textContent || '[]');
  const shieldPreview=participantForm.querySelector('img[alt="Prévia do escudo"]');
  const shieldFile=participantForm.querySelector('input[type="file"]');
  // Exibe ativos e inativos na mesma listagem e oferece soft delete/reativação.
  const participantButtons=[...document.querySelectorAll('.editar-participante')];
  const participantTable=participantButtons[0]?.closest('table');
  if(participantTable){
    const participantCount=participantTable.closest('.panel')?.querySelector('.panel-head span');
    if(participantCount)participantCount.textContent=`${participants.length} registros`;
    const actionHeader=participantTable.tHead?.rows[0]?.lastElementChild;
    if(actionHeader){const statusHeader=document.createElement('th');statusHeader.textContent='Status';actionHeader.before(statusHeader);}
    participantButtons.forEach(button=>{
      const participant=participants.find(item=>Number(item.id)===Number(button.dataset.id));if(!participant)return;
      const active=Number(participant.ativo)===1,row=button.closest('tr'),actionCell=button.closest('td');
      const statusCell=document.createElement('td');statusCell.textContent=active?'Ativo':'Inativo';actionCell.before(statusCell);
      row.classList.toggle('opacity-50',!active);
      const actionGroup=document.createElement('div');actionGroup.className='d-flex gap-2 flex-wrap align-items-center';
      actionCell.insertBefore(actionGroup,button);actionGroup.append(button);
      const statusForm=document.createElement('form');statusForm.method='post';statusForm.onsubmit=()=>confirm(`${active?'Desativar':'Reativar'} ${participant.time_nome}?`);
      statusForm.innerHTML=`<input type="hidden" name="csrf" value="${participantForm.csrf.value}"><input type="hidden" name="action" value="status_participante"><input type="hidden" name="participante_id" value="${participant.id}"><input type="hidden" name="ativo" value="${active?0:1}"><button class="btn btn-sm ${active?'btn-outline-danger':'btn-outline-success'}">${active?'Desativar':'Reativar'}</button>`;
      actionGroup.append(statusForm);
    });
  }
  document.querySelectorAll('.editar-participante').forEach(button=>button.addEventListener('click',()=>{
    const participant=participants.find(item=>Number(item.id)===Number(button.dataset.id)); if(!participant)return;
    participantForm.participante_id.value=participant.id;
    participantForm.nome.value=participant.nome;
    participantForm.time_nome.value=participant.time_nome;
    participantForm.sigla.value=participant.sigla;
    participantForm.descricao.value=participant.descricao || '';
    shieldData.value=participant.escudo_url || '';
    if(shieldFile)shieldFile.value='';
    if(shieldPreview){shieldPreview.src=shieldData.value;shieldPreview.classList.toggle('d-none',!shieldData.value);}
    document.getElementById('participante-form-title').textContent='Editar técnico e time';
    document.getElementById('participante-submit').textContent='Salvar alterações';
    const notice=document.getElementById('participante-edicao');notice.querySelector('span').textContent=`Editando: ${participant.nome} — ${participant.time_nome}`;notice.classList.remove('d-none');notice.classList.add('d-flex');
    participantForm.scrollIntoView({behavior:'smooth'});
  }));
  document.querySelector('.cancelar-participante').addEventListener('click',()=>{
    participantForm.reset(); participantForm.participante_id.value=''; shieldData.value='';
    if(shieldPreview){shieldPreview.src='';shieldPreview.classList.add('d-none');}
    document.getElementById('participante-form-title').textContent='Novo técnico e time';
    document.getElementById('participante-submit').textContent='Cadastrar técnico';
    const notice=document.getElementById('participante-edicao');notice.classList.add('d-none');notice.classList.remove('d-flex');
  });
}

// Reabre a aba correta depois de cadastrar ou editar um participante.
if(new URLSearchParams(location.search).get('tab')==='times') document.querySelector('[data-bs-target="#tab-times"]')?.click();
if(new URLSearchParams(location.search).get('tab')==='usuarios') document.querySelector('[data-bs-target="#tab-usuarios"]')?.click();
if(new URLSearchParams(location.search).get('tab')==='campeonatos') document.querySelector('[data-bs-target="#tab-campeonatos"]')?.click();
if(new URLSearchParams(location.search).get('tab')==='extra') document.querySelector('[data-bs-target="#tab-extra"]')?.click();
if(new URLSearchParams(location.search).get('tab')==='mercado') document.querySelector('[data-bs-target="#tab-mercado"]')?.click();

// Filtra os artilheiros pelo campeonato e numera o ranking pela quantidade de gols.
const scorerFilter=document.getElementById('artilheiros-admin-filter');
const scorerForm=document.getElementById('form-artilharia');
if(scorerFilter && scorerForm){
  const rows=[...document.querySelectorAll('#artilheiros-admin-body tr')];
  const renderScorerRows=()=>{
    let position=0;
    const selected=scorerFilter.value;
    rows.forEach(row=>{const visible=!selected || row.dataset.campeonato===selected;row.classList.toggle('d-none',!visible);if(visible)row.querySelector('.artilheiro-posicao').textContent=++position;});
    document.getElementById('artilheiros-admin-count').textContent=`${position} registro${position===1?'':'s'}`;
    document.getElementById('artilheiros-admin-empty').classList.toggle('d-none',position>0);
  };
  scorerFilter.addEventListener('change',renderScorerRows);
  document.querySelectorAll('.editar-artilheiro').forEach(button=>button.addEventListener('click',()=>{
    scorerForm.artilheiro_id.value=button.dataset.id;
    scorerForm.campeonato_id.value=button.dataset.campeonato;
    scorerForm.jogador.value=button.dataset.jogador;
    scorerForm.participante_id.value=button.dataset.participante;
    scorerForm.gols.value=button.dataset.gols;
    const notice=document.getElementById('artilheiro-edicao');notice.querySelector('span').textContent=`Editando: ${button.dataset.jogador}`;notice.classList.remove('d-none');notice.classList.add('d-flex');
    document.getElementById('artilheiro-submit').textContent='Salvar alterações';
    scorerForm.scrollIntoView({behavior:'smooth'});
  }));
  document.querySelector('.cancelar-artilheiro')?.addEventListener('click',()=>{
    scorerForm.reset();scorerForm.artilheiro_id.value='';document.getElementById('artilheiro-submit').textContent='Salvar artilheiro';
    const notice=document.getElementById('artilheiro-edicao');notice.classList.add('d-none');notice.classList.remove('d-flex');
  });
  renderScorerRows();
}


// Mostra os campos corretos conforme o tipo escolhido no cadastro do título.
const origemTitulo=document.getElementById('origem_titulo');
if(origemTitulo) origemTitulo.addEventListener('change',()=>{
  const historico=origemTitulo.value==='historico';
  document.querySelectorAll('.titulo-atual').forEach(item=>item.classList.toggle('d-none',historico));
  document.querySelectorAll('.titulo-historico').forEach(item=>item.classList.toggle('d-none',!historico));
});

// Replica no mata-mata o cadastro individual de cada gol usado nos pontos corridos.
function renderMataGoalEditor(existing=[]){
  const form=document.getElementById('form-mata'),editor=document.getElementById('mata-goals-editor');if(!form||!editor)return;
  if(form.status.value==='wo'){editor.innerHTML='<div class="alert alert-secondary mb-0">O W.O. fecha todas as partidas deste confronto em 3 a 0, sem contabilizar artilheiros.</div>';return;}
  const totalA=Number(form.gols_a.value||0),totalB=Number(form.gols_b.value||0),teamA=form.time_a_id.value,teamB=form.time_b_id.value;
  const safe=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const name=select=>select.options[select.selectedIndex]?.textContent.split(' — Técnico ')[0]||'Time';
  const oldByTeam={};existing.forEach(goal=>(oldByTeam[goal.participante_id]??=[]).push(goal));
  const row=(teamId,team,index)=>{const old=(oldByTeam[teamId]||[])[index]||{};return `<div class="match-goal-row border rounded p-2 mb-2"><input type="hidden" name="mata_gol_time[]" value="${teamId}"><strong>${safe(team)} — Gol ${index+1}</strong><div class="row g-2 mt-1"><div class="col-md-5"><label class="form-label small">Jogador</label><input class="form-control form-control-sm" name="mata_gol_jogador[]" value="${safe(old.jogador)}" required></div><div class="col-md-3"><label class="form-label small">Tempo</label><input class="form-control form-control-sm" name="mata_gol_minuto[]" value="${safe(old.minuto)}" placeholder="45+2, pênaltis..." maxlength="30" required></div><div class="col-md-4"><label class="form-label small">Tipo</label><select class="form-select form-select-sm" name="mata_gol_tipo[]"><option value="normal" ${!old.tipo||old.tipo==='normal'?'selected':''}>Gol normal</option><option value="penalti" ${old.tipo==='penalti'?'selected':''}>Pênalti</option><option value="falta" ${old.tipo==='falta'?'selected':''}>Gol de falta</option><option value="olimpico" ${old.tipo==='olimpico'?'selected':''}>Gol olímpico</option><option value="contra" ${old.tipo==='contra'?'selected':''}>Gol contra</option></select></div></div></div>`;};
  let html='';for(let i=0;i<totalA;i++)html+=row(teamA,name(form.time_a_id),i);for(let i=0;i<totalB;i++)html+=row(teamB,name(form.time_b_id),i);
  editor.innerHTML=html?`<div class="d-flex justify-content-between mb-2"><strong>Gols deste jogo</strong><small class="text-secondary">${totalA+totalB} registros</small></div>${html}`:'';
}
function currentMataGoalRows(){return [...document.querySelectorAll('#mata-goals-editor .match-goal-row')].map(row=>({participante_id:row.querySelector('[name="mata_gol_time[]"]').value,jogador:row.querySelector('[name="mata_gol_jogador[]"]').value,minuto:row.querySelector('[name="mata_gol_minuto[]"]').value,tipo:row.querySelector('[name="mata_gol_tipo[]"]').value}));}

if(mataForm){
  const editor=document.createElement('div');editor.id='mata-goals-editor';editor.className='col-12 mt-2';mataForm.querySelector('.row.g-2').append(editor);
  ['gols_a','gols_b'].forEach(field=>mataForm[field].addEventListener('input',()=>renderMataGoalEditor(currentMataGoalRows())));
}

// Busca, filtra por campeonato/time e pagina os artilheiros em grupos de cinco.
if(scorerFilter && scorerForm){
  const filterBox=scorerFilter.closest('.p-3');
  filterBox.insertAdjacentHTML('beforeend','<div class="row g-2 mt-1"><div class="col-md-6"><input id="artilheiros-admin-search" class="form-control form-control-sm" type="search" placeholder="Pesquisar jogador, time ou técnico..."></div><div class="col-md-6"><select id="artilheiros-admin-team" class="form-select form-select-sm"><option value="">Todos os times</option></select></div></div>');
  const rows=[...document.querySelectorAll('#artilheiros-admin-body tr')],teamSelect=document.getElementById('artilheiros-admin-team'),search=document.getElementById('artilheiros-admin-search');
  [...new Set(rows.map(row=>row.cells[2].childNodes[0]?.textContent.trim()).filter(Boolean))].sort().forEach(team=>teamSelect.add(new Option(team,team)));
  const pagination=document.createElement('div');pagination.className='d-flex justify-content-between align-items-center p-3 border-top';document.getElementById('artilheiros-admin-empty').after(pagination);let page=1;
  const render=()=>{const q=search.value.trim().toLocaleLowerCase('pt-BR');const filtered=rows.filter(row=>(!scorerFilter.value||row.dataset.campeonato===scorerFilter.value)&&(!teamSelect.value||row.cells[2].textContent.includes(teamSelect.value))&&(!q||row.textContent.toLocaleLowerCase('pt-BR').includes(q)));const pages=Math.max(1,Math.ceil(filtered.length/5));page=Math.min(page,pages);const visible=new Set(filtered.slice((page-1)*5,page*5));rows.forEach(row=>row.classList.toggle('d-none',!visible.has(row)));filtered.forEach((row,index)=>row.querySelector('.artilheiro-posicao').textContent=index+1);document.getElementById('artilheiros-admin-count').textContent=`${filtered.length} registro${filtered.length===1?'':'s'}`;document.getElementById('artilheiros-admin-empty').classList.toggle('d-none',filtered.length>0);pagination.innerHTML=filtered.length>5?`<button type="button" class="btn btn-sm btn-outline-light prev">Anterior</button><span class="text-secondary small">Página ${page} de ${pages}</span><button type="button" class="btn btn-sm btn-outline-light next">Próxima</button>`:'';pagination.querySelector('.prev')?.toggleAttribute('disabled',page===1);pagination.querySelector('.next')?.toggleAttribute('disabled',page===pages);};
  [scorerFilter,teamSelect,search].forEach(control=>control.addEventListener(control.tagName==='INPUT'?'input':'change',()=>{page=1;render()}));pagination.addEventListener('click',event=>{if(event.target.closest('.prev'))page--;if(event.target.closest('.next'))page++;render()});render();
  scorerForm.addEventListener('submit',event=>{if(scorerForm.artilheiro_id.value)return;const player=scorerForm.jogador.value.trim(),champ=scorerForm.campeonato_id.value,team=scorerForm.participante_id.value;const duplicate=[...document.querySelectorAll('.editar-artilheiro')].find(button=>button.dataset.campeonato===champ&&button.dataset.participante===team&&button.dataset.jogador.trim().toLocaleLowerCase('pt-BR')===player.toLocaleLowerCase('pt-BR'));if(!duplicate)return;if(!confirm(`${duplicate.dataset.jogador} já possui ${duplicate.dataset.gols} gol(s) neste campeonato. Deseja substituir pelo novo total?`)){event.preventDefault();return;}let hidden=scorerForm.querySelector('[name="confirmar_existente"]');if(!hidden){hidden=document.createElement('input');hidden.type='hidden';hidden.name='confirmar_existente';scorerForm.append(hidden)}hidden.value='1';});
}

// A publicação de vídeos agora vive em uma aba própria.
document.querySelector('#tab-extra form.panel.admin-form.mt-4')?.classList.add('d-none');
{const requestedTab=new URLSearchParams(location.search).get('tab');if(requestedTab)document.querySelector(`[data-bs-target="#tab-${CSS.escape(requestedTab)}"]`)?.click();}

// Troca o antigo checkbox administrativo pelo seletor dos três níveis de acesso.
const adminAccessCheckbox=document.getElementById('usuario-eh-admin');
if(adminAccessCheckbox){
  const wrapper=adminAccessCheckbox.closest('.form-check');
  wrapper.className='mt-3';
  wrapper.innerHTML='<label class="form-label">Nível de acesso</label><select class="form-select" name="nivel_acesso" required><option value="0">Usuário comum</option><option value="2">Editor da Competição</option><option value="1">Admin Master</option></select><small class="text-secondary d-block mt-2">O Editor pode administrar pontos corridos, mata-mata, artilharia e notícias.</small>';
}

// Permite editar uma conta existente preenchendo o formulário automaticamente.
const userForm=document.querySelector('#tab-usuarios form.panel.admin-form');
if(userForm){
  const title=userForm.querySelector('h2'),submit=userForm.querySelector('button:not([type])');
  const password=userForm.querySelector('[name="senha"]'),confirmation=userForm.querySelector('[name="confirmar_senha"]');
  const accountId=document.createElement('input');accountId.type='hidden';accountId.name='conta_id';accountId.value='';userForm.append(accountId);
  const notice=document.createElement('div');notice.className='alert alert-info d-none justify-content-between align-items-center';notice.innerHTML='<span></span><button type="button" class="btn btn-sm btn-outline-info">Cancelar edição</button>';userForm.querySelector('p')?.after(notice);
  const resetUserForm=()=>{userForm.reset();accountId.value='';password.required=true;confirmation.required=true;title.textContent='Cadastrar usuário';submit.textContent='Cadastrar usuário';notice.classList.add('d-none');notice.classList.remove('d-flex');};
  notice.querySelector('button').addEventListener('click',resetUserForm);
  document.querySelectorAll('#tab-usuarios tbody tr').forEach(row=>{
    const statusForm=row.querySelector('form [name="conta_id"]')?.closest('form');if(!statusForm)return;
    const id=statusForm.querySelector('[name="conta_id"]').value,button=document.createElement('button');button.type='button';button.className='btn btn-sm btn-outline-light me-2';button.textContent='Editar';statusForm.parentElement.prepend(button);
    button.addEventListener('click',async()=>{
      try{
        const response=await fetch(`conta-dados.php?id=${encodeURIComponent(id)}`,{credentials:'same-origin'}),data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'Não foi possível carregar o usuário.');
        const account=data.conta;accountId.value=account.id;userForm.nome.value=account.nome;userForm.email.value=account.email;userForm.participante_id.value=account.participante_id||'';userForm.nivel_acesso.value=String(account.eh_admin);password.value='';confirmation.value='';password.required=false;confirmation.required=false;title.textContent='Editar usuário';submit.textContent='Salvar alterações';notice.querySelector('span').textContent=`Editando: ${account.nome}`;notice.classList.remove('d-none');notice.classList.add('d-flex');userForm.scrollIntoView({behavior:'smooth',block:'start'});
      }catch(error){showAdminToast(error.message,'danger');}
    });
  });
}

// Busca o jogador enquanto o nome é digitado e recupera time, campeonato e total já salvo.
if(scorerForm){
  const playerInput=scorerForm.jogador;
  const resultBox=document.createElement('div');resultBox.className='list-group mb-2 d-none';playerInput.after(resultBox);
  const normalize=value=>String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('pt-BR').trim();
  const choose=button=>{
    scorerForm.artilheiro_id.value=button.dataset.id;scorerForm.campeonato_id.value=button.dataset.campeonato;scorerForm.jogador.value=button.dataset.jogador;scorerForm.participante_id.value=button.dataset.participante;scorerForm.gols.value=button.dataset.gols;
    document.getElementById('artilheiro-submit').textContent='Salvar alterações';
    const notice=document.getElementById('artilheiro-edicao');notice.querySelector('span').textContent=`Editando: ${button.dataset.jogador} — ${button.dataset.gols} gol(s)`;notice.classList.remove('d-none');notice.classList.add('d-flex');resultBox.classList.add('d-none');
  };
  const searchExisting=()=>{
    const query=normalize(playerInput.value);if(query.length<2){resultBox.classList.add('d-none');resultBox.innerHTML='';return;}
    const championship=scorerForm.campeonato_id.value;
    const matches=[...document.querySelectorAll('.editar-artilheiro')].filter(button=>normalize(button.dataset.jogador).includes(query)&&(!championship||button.dataset.campeonato===championship)).slice(0,10);
    resultBox.innerHTML=matches.map((button,index)=>`<button type="button" class="list-group-item list-group-item-action bg-dark text-light border-secondary" data-result="${index}"><strong>${button.dataset.jogador}</strong><small class="d-block text-secondary">${button.closest('tr')?.cells[2]?.textContent.trim()||'Time'} • ${button.dataset.gols} gol(s)</small></button>`).join('');
    resultBox.classList.toggle('d-none',matches.length===0);resultBox.querySelectorAll('[data-result]').forEach((item,index)=>item.addEventListener('click',()=>choose(matches[index])));
    const exact=matches.filter(button=>normalize(button.dataset.jogador)===query);if(exact.length===1)choose(exact[0]);
  };
  playerInput.addEventListener('input',searchExisting);scorerForm.campeonato_id.addEventListener('change',searchExisting);
}

// Recomenda vínculos de conta por coincidência exata com o nome do técnico.
if(document.querySelector('#tab-usuarios'))fetch('associacoes-dados.php').then(response=>response.json()).then(data=>{if(!data.ok||!data.sugestoes.length)return;const panel=document.createElement('div');panel.className='panel p-3 mb-4';const title=document.createElement('h3');title.className='h5';title.textContent='Sugestões de associação';const intro=document.createElement('p');intro.className='text-secondary small';intro.textContent='Coincidências entre o nome da conta e o técnico. Confirme cada vínculo antes de associar.';const list=document.createElement('div');list.className='d-flex flex-wrap gap-2';data.sugestoes.forEach(item=>{const form=document.createElement('form');form.method='post';form.className='border rounded p-2';[['csrf',data.csrf],['action','vincular_conta'],['conta_id',item.conta_id],['participante_id',item.participante_id]].forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.append(input)});const name=document.createElement('strong');name.textContent=item.conta_nome;const detail=document.createElement('small');detail.className='d-block text-secondary mb-2';detail.textContent=`${item.time_nome} • Técnico ${item.tecnico}`;const button=document.createElement('button');button.className='btn btn-sm btn-warning';button.textContent='Associar conta e time';form.append(name,detail,button);list.append(form)});panel.append(title,intro,list);document.querySelector('#tab-usuarios').prepend(panel)}).catch(()=>{});

// Padroniza todas as demais listagens: cinco registros, pesquisa e filtros por coluna.
function setupUniversalAdminLists(){
  document.querySelectorAll('.tab-pane table').forEach(table=>{
    if(table.closest('#tab-extra')||table.querySelector('.editar-partida')||table.dataset.enhanced)return;
    table.dataset.enhanced='1';const rows=[...table.tBodies[0]?.rows||[]].filter(row=>row.cells.length>1);if(!rows.length)return;
    const wrapper=table.closest('.table-responsive');const headers=[...table.tHead?.rows[0]?.cells||[]].map(cell=>cell.textContent.trim());
    const controls=document.createElement('div');controls.className='row g-2 p-3 border-bottom admin-universal-filters';
    controls.innerHTML='<div class="col-lg-5"><label class="form-label small">Pesquisar</label><input class="form-control form-control-sm universal-search" type="search" placeholder="Digite para localizar um registro..."></div>';
    const filterNames=['Campeonato','Time','Técnico / Time','Modalidade','Status','Fase','Acesso','Vínculo'];
    headers.forEach((header,index)=>{if(!filterNames.includes(header))return;const values=[...new Set(rows.map(row=>row.cells[index]?.textContent.replace(/\s+/g,' ').trim()).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'pt-BR'));if(values.length<2||values.length>30)return;const col=document.createElement('div');col.className='col-lg-3';col.innerHTML=`<label class="form-label small">${header}</label><select class="form-select form-select-sm universal-filter" data-column="${index}"><option value="">Todos</option>${values.map(value=>`<option value="${value.replace(/"/g,'&quot;')}">${value}</option>`).join('')}</select>`;controls.append(col)});
    wrapper.before(controls);const pagination=document.createElement('div');pagination.className='d-flex justify-content-between align-items-center gap-2 p-3 border-top';wrapper.after(pagination);let page=1;
    const render=()=>{const query=controls.querySelector('.universal-search').value.toLocaleLowerCase('pt-BR').trim();const filters=[...controls.querySelectorAll('.universal-filter')];const filtered=rows.filter(row=>(!query||row.textContent.toLocaleLowerCase('pt-BR').includes(query))&&filters.every(select=>!select.value||row.cells[Number(select.dataset.column)]?.textContent.replace(/\s+/g,' ').trim()===select.value));const pages=Math.max(1,Math.ceil(filtered.length/5));page=Math.min(page,pages);const visible=new Set(filtered.slice((page-1)*5,page*5));rows.forEach(row=>row.classList.toggle('d-none',!visible.has(row)));pagination.innerHTML=`<span class="text-secondary small">${filtered.length} registro${filtered.length===1?'':'s'}</span>${filtered.length>5?`<div class="d-flex align-items-center gap-2"><button type="button" class="btn btn-sm btn-outline-light prev" ${page===1?'disabled':''}>Anterior</button><span class="text-secondary small">Página ${page} de ${pages}</span><button type="button" class="btn btn-sm btn-outline-light next" ${page===pages?'disabled':''}>Próxima</button></div>`:''}`;};
    controls.addEventListener('input',()=>{page=1;render()});controls.addEventListener('change',()=>{page=1;render()});pagination.addEventListener('click',event=>{if(event.target.closest('.prev'))page--;else if(event.target.closest('.next'))page++;else return;render()});render();
  });
}
setupUniversalAdminLists();

// Preenche o modal sem transportar as imagens base64 dentro do HTML da listagem.
document.querySelectorAll('.editar-campeonato').forEach(button=>button.addEventListener('click',()=>{
  const form=document.getElementById('competition-edit-form');if(!form)return;
  form.campeonato_id.value=button.dataset.id;form.nome.value=button.dataset.name;form.status.value=button.dataset.status;
  form.querySelector('[data-preview="logo"]').src=`../api/competicao-imagem.php?campeonato_id=${button.dataset.id}&tipo=logo&v=${Date.now()}`;
  form.querySelector('[data-preview="trofeu"]').src=`../api/competicao-imagem.php?campeonato_id=${button.dataset.id}&tipo=trofeu&v=${Date.now()}`;
  form.logo.value='';form.trofeu.value='';form.logo_base64.value='';form.trofeu_base64.value='';
}));
const editionEditForm=document.getElementById('competition-edit-form');
if(editionEditForm){editionEditForm.querySelector('.row.g-3')?.remove();const note=editionEditForm.querySelector('.modal-body>small');if(note)note.outerHTML='<div class="alert alert-info small mb-0">Logo e taça são controladas em <strong>Campeonatos padrões</strong> e atualizam todas as edições juntas.</div>';}

// Centraliza os modelos: uma única logo/taça alimenta edições, títulos, perfis e vitrine.
const championshipTab=document.getElementById('tab-campeonatos');
if(championshipTab){
  fetch('identidades-dados.php',{credentials:'same-origin'}).then(response=>response.json()).then(data=>{
    if(!data.ok)return;
    const csrf=document.querySelector('#competition-edit-form input[name="csrf"]')?.value||'',esc=value=>String(value).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const shell=document.createElement('section');shell.className='panel mb-4 competition-models-panel';
    shell.innerHTML=`<div class="panel-head"><div><small class="eyebrow">Fonte única de imagens</small><h3 class="mb-0">CAMPEONATOS PADRÕES</h3></div><span>${data.identidades.length} modelos</span></div><div class="p-3 text-secondary border-bottom">Edite aqui. A alteração é refletida automaticamente em todas as edições, títulos, perfis e na vitrine.</div><div class="competition-model-grid">${data.identidades.map(item=>`<article class="competition-model-card"><div class="competition-model-visual"><img src="${esc(item.trofeu_url)}" alt=""><img src="${esc(item.logo_url)}" alt=""></div><div><h4>${esc(item.nome)}</h4><small>${item.edicoes} edição${item.edicoes===1?'':'ões'} • ${item.titulos} título${item.titulos===1?'':'s'}</small><button type="button" class="btn btn-sm btn-outline-warning w-100 mt-3 editar-identidade" data-id="${item.id}" data-name="${esc(item.nome)}" data-logo="${esc(item.logo_url)}" data-trophy="${esc(item.trofeu_url)}">Editar padrão</button></div></article>`).join('')}</div>`;
    championshipTab.prepend(shell);
    document.body.insertAdjacentHTML('beforeend',`<div class="modal fade" id="identity-edit-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="identity-edit-form" method="post" enctype="multipart/form-data"><div class="modal-header"><div><small class="eyebrow">Fonte única</small><h2 class="modal-title">EDITAR CAMPEONATO PADRÃO</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="${csrf}"><input type="hidden" name="action" value="editar_identidade_competicao"><input type="hidden" name="identidade_id"><label class="form-label">Nome do padrão</label><input class="form-control mb-3" name="nome" maxlength="150" required><div class="row g-3"><div class="col-6"><label class="form-label">Logo única</label><div class="competition-image-preview"><img data-preview="logo" alt=""></div><input type="hidden" name="logo_base64"><input class="form-control form-control-sm competition-art-file" type="file" name="logo" data-art-type="logo" accept="image/png,image/webp,image/jpeg"></div><div class="col-6"><label class="form-label">Taça única</label><div class="competition-image-preview"><img data-preview="trofeu" alt=""></div><input type="hidden" name="trofeu_base64"><input class="form-control form-control-sm competition-art-file" type="file" name="trofeu" data-art-type="trofeu" accept="image/png,image/webp,image/jpeg"></div></div><div class="alert alert-info small mt-3 mb-0">Esta alteração será usada por todas as edições e títulos ligados a este padrão.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger">Atualizar em todo o site</button></div></form></div></div></div>`);
    const modalElement=document.getElementById('identity-edit-modal'),modal=bootstrap.Modal.getOrCreateInstance(modalElement),form=document.getElementById('identity-edit-form');
    shell.querySelectorAll('.editar-identidade').forEach(button=>button.addEventListener('click',()=>{form.identidade_id.value=button.dataset.id;form.nome.value=button.dataset.name;form.logo_base64.value='';form.trofeu_base64.value='';form.logo.value='';form.trofeu.value='';form.querySelector('[data-preview="logo"]').src=`${button.dataset.logo}&v=${Date.now()}`;form.querySelector('[data-preview="trofeu"]').src=`${button.dataset.trophy}&v=${Date.now()}`;modal.show()}));
    const supercupForm=document.querySelector('#tab-supercopa form input[name="action"][value="criar_supercopa"]')?.closest('form');
    if(supercupForm){const nameInput=supercupForm.querySelector('input[name="nome"]'),nameLabel=nameInput?.previousElementSibling;nameLabel?.insertAdjacentHTML('beforebegin',`<label class="form-label">Campeonato padrão</label><select class="form-select mb-3" name="identidade_id" required>${data.identidades.map(item=>`<option value="${item.id}" data-name="${esc(item.nome)}" data-next="${Math.max(item.edicoes,item.titulos)+1}" ${item.chave==='supercopa r'?'selected':''}>${esc(item.nome)} — criar próxima edição</option>`).join('')}</select>`);const model=supercupForm.identidade_id,updateName=()=>{const option=model.selectedOptions[0],next=Number(option.dataset.next||1);nameInput.value=option.dataset.name+(next>1?' '+romanEdition(next):'')};const romanEdition=n=>{const values=[[1000,'M'],[900,'CM'],[500,'D'],[400,'CD'],[100,'C'],[90,'XC'],[50,'L'],[40,'XL'],[10,'X'],[9,'IX'],[5,'V'],[4,'IV'],[1,'I']];let value='';for(const [amount,symbol] of values)while(n>=amount){value+=symbol;n-=amount}return value};model.addEventListener('change',updateName);updateName();}
  }).catch(()=>{});
  const style=document.createElement('style');style.textContent='.competition-model-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;padding:16px}.competition-model-card{overflow:hidden;border:1px solid #343941;border-radius:12px;background:#0c0e11}.competition-model-card>div:last-child{padding:14px}.competition-model-card h4{margin:0;font:800 1.25rem "Barlow Condensed",sans-serif}.competition-model-card small{color:#8f97a4}.competition-model-visual{display:grid;grid-template-columns:1fr 1fr;align-items:center;height:145px;padding:10px;background:radial-gradient(circle,rgba(237,27,47,.12),transparent 65%)}.competition-model-visual img{width:100%;height:125px;object-fit:contain}';document.head.append(style);
}

// Editor reutilizável de enquadramento: arraste a arte e ajuste o zoom antes de salvar.
const artEditor=document.getElementById('competition-art-editor');
if(artEditor){
  const canvas=artEditor.querySelector('canvas'),context=canvas.getContext('2d'),zoomInput=artEditor.querySelector('#competition-art-zoom');
  let activeInput=null,sourceImage=null,zoom=1,offsetX=0,offsetY=0,dragging=false,lastX=0,lastY=0;
  const dimensions=type=>type==='logo'?[720,480]:[600,720];
  const draw=()=>{if(!sourceImage)return;context.clearRect(0,0,canvas.width,canvas.height);const base=Math.min(canvas.width/sourceImage.naturalWidth,canvas.height/sourceImage.naturalHeight),scale=base*zoom,w=sourceImage.naturalWidth*scale,h=sourceImage.naturalHeight*scale;context.imageSmoothingEnabled=true;context.imageSmoothingQuality='high';context.drawImage(sourceImage,(canvas.width-w)/2+offsetX,(canvas.height-h)/2+offsetY,w,h)};
  const close=()=>{artEditor.hidden=true;if(activeInput)activeInput.value='';activeInput=null;sourceImage=null};
  document.addEventListener('change',event=>{const input=event.target.closest('.competition-art-file,.title-art-file');if(!input)return;
    const file=input.files?.[0];if(!file)return;activeInput=input;const [width,height]=dimensions(input.dataset.artType);canvas.width=width;canvas.height=height;zoom=1;offsetX=0;offsetY=0;zoomInput.value='1';sourceImage=new Image();sourceImage.onload=()=>{artEditor.hidden=false;draw()};sourceImage.onerror=()=>{showAdminToast('Não foi possível abrir essa imagem.','danger');close()};sourceImage.src=URL.createObjectURL(file);
  });
  zoomInput.addEventListener('input',()=>{zoom=Number(zoomInput.value);draw()});
  canvas.addEventListener('pointerdown',event=>{dragging=true;lastX=event.clientX;lastY=event.clientY;canvas.setPointerCapture(event.pointerId)});
  canvas.addEventListener('pointermove',event=>{if(!dragging)return;const rect=canvas.getBoundingClientRect();offsetX+=(event.clientX-lastX)*(canvas.width/rect.width);offsetY+=(event.clientY-lastY)*(canvas.height/rect.height);lastX=event.clientX;lastY=event.clientY;draw()});
  canvas.addEventListener('pointerup',()=>dragging=false);canvas.addEventListener('pointercancel',()=>dragging=false);
  artEditor.querySelectorAll('[data-art-cancel]').forEach(button=>button.addEventListener('click',close));
  artEditor.querySelector('[data-art-apply]').addEventListener('click',()=>{if(!activeInput)return;draw();const data=canvas.toDataURL('image/webp',.92),type=activeInput.dataset.artType,form=activeInput.closest('form');if(type==='titulo'){form.titulo_imagem_base64.value=data;const preview=form.querySelector('[data-title-preview]');preview.src=data;preview.classList.remove('d-none')}else{form[`${type}_base64`].value=data;form.querySelector(`[data-preview="${type}"]`).src=data}artEditor.hidden=true;activeInput.value='';activeInput=null;sourceImage=null});
}

const titleForm=document.getElementById('form-titulo');
if(titleForm){
  const toggleTitleOrigin=()=>{const historical=titleForm.origem_titulo.value==='historico';titleForm.querySelectorAll('.titulo-historico').forEach(item=>item.classList.toggle('d-none',!historical));titleForm.querySelectorAll('.titulo-atual').forEach(item=>item.classList.toggle('d-none',historical))};
  const resetTitle=()=>{titleForm.reset();titleForm.titulo_id.value='';titleForm.titulo_imagem_base64.value='';const preview=titleForm.querySelector('[data-title-preview]');preview.removeAttribute('src');preview.classList.add('d-none');toggleTitleOrigin()};
  titleForm.origem_titulo.addEventListener('change',toggleTitleOrigin);toggleTitleOrigin();titleForm.querySelector('[data-title-cancel]').addEventListener('click',resetTitle);
  document.querySelectorAll('.editar-titulo').forEach(button=>button.addEventListener('click',()=>{const historical=Number(button.dataset.participant)===0;titleForm.titulo_id.value=button.dataset.id;titleForm.origem_titulo.value=historical?'historico':'atual';titleForm.participante_id.value=historical?'':button.dataset.participant;titleForm.tecnico_historico.value=button.dataset.coach;titleForm.time_historico.value=button.dataset.team;titleForm.titulo.value=button.dataset.title;titleForm.temporada.value=button.dataset.season;titleForm.conquistado_em.value=(button.dataset.date||'').slice(0,10);titleForm.descricao.value=button.dataset.description;titleForm.titulo_imagem_base64.value='';const preview=titleForm.querySelector('[data-title-preview]');if(button.dataset.hasImage==='1'){preview.src=`../api/titulo-imagem.php?titulo_id=${button.dataset.id}&v=${Date.now()}`;preview.classList.remove('d-none')}else{preview.removeAttribute('src');preview.classList.add('d-none')}toggleTitleOrigin();titleForm.scrollIntoView({behavior:'smooth',block:'start'})}));
}

// Salva qualquer ação administrativa sem recarregar a página inteira.
document.querySelectorAll('main form[method="post"]').forEach(form=>form.addEventListener('submit',async event=>{
  if(event.defaultPrevented)return;event.preventDefault();if(form.dataset.ajaxBusy==='1')return;form.dataset.ajaxBusy='1';
  const button=event.submitter||form.querySelector('button[type="submit"],button:not([type])');const oldText=button?.textContent;if(button){button.disabled=true;button.textContent='Salvando...';}
  try{
    const payload=new FormData(form);payload.set('_ajax','1');const response=await fetch('index.php',{method:'POST',body:payload,headers:{'Accept':'application/json'},credentials:'same-origin'});const data=await response.json().catch(()=>({ok:false,message:'Resposta inválida do servidor.'}));if(!response.ok||!data.ok)throw new Error(data.message||'Não foi possível salvar.');
    const active=data.tab||document.querySelector('.nav-pills .nav-link.active')?.dataset.bsTarget?.replace('#tab-','')||'';const top=window.scrollY;const url=new URL(location.href);if(active)url.searchParams.set('tab',active);
    try{url.searchParams.set('_refresh',Date.now());const html=await fetch(url,{cache:'no-store',credentials:'same-origin'}).then(result=>{if(!result.ok)throw new Error('Não foi possível atualizar a lista.');return result.text()});url.searchParams.delete('_refresh');const parsed=new DOMParser().parseFromString(html,'text/html');const fresh=parsed.querySelector('.tab-content');if(!fresh)throw new Error('A lista atualizada não foi encontrada.');document.querySelector('.tab-content').replaceWith(fresh);history.replaceState(null,'',url);for(const file of ['news-editor.js','news-round-prompt.js','sumula-importer.js','admin.js']){await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src=`../assets/js/${file}?v=${Date.now()}`;script.onload=resolve;script.onerror=reject;document.body.append(script)})}window.scrollTo({top,behavior:'instant'});}catch(refreshError){showAdminToast(`${data.message} Porém, ${refreshError.message.toLocaleLowerCase('pt-BR')}`,'warning');return;}
    showAdminToast(data.message,'success');
  }catch(error){form.dataset.ajaxBusy='0';if(button){button.disabled=false;button.textContent=oldText;}showAdminToast(error.message,'danger');}
}));
function showAdminToast(message,type){const toast=document.createElement('div');toast.className=`alert alert-${type} position-fixed top-0 start-50 translate-middle-x mt-3 shadow`;toast.style.zIndex='2000';toast.textContent=message;document.body.append(toast);setTimeout(()=>toast.remove(),type==='success'?3200:5000);}
})();
const passwordToggleScript=document.createElement('script');passwordToggleScript.src='../assets/js/password-toggle.js';document.head.append(passwordToggleScript);
