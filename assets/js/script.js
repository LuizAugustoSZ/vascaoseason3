// Escapa os textos da API antes de inserir no HTML.
const esc = value => $('<div>').text(value ?? '').html();
const publicEmpty = message => `<div class="public-empty"><img class="empty-season-logo" src="assets/img/logo-season3.webp?v=5" alt=""><p>${esc(message)}</p></div>`;
// Mostra o escudo ou usa a sigla do time.
const badge = team => {const initials=esc(team.sigla || team.nome.slice(0,3).toUpperCase());return team.escudo_url ? `<span class="team-badge team-badge-image"><img src="${esc(team.escudo_url)}" alt="Escudo de ${esc(team.time_nome || team.nome)}"><b>${initials}</b></span>` : `<span class="team-badge">${initials}</span>`;};
const teamLink = (id,name,className='team-link') => `<a class="${className}" href="time.php?id=${Number(id)}">${esc(name)}</a>`;
const gameTeam=(game,side)=>{const home=side==='home',id=game[home?'mandante_id':'visitante_id'],name=game[home?'mandante':'visitante'],coach=game[home?'tecnico_mandante':'tecnico_visitante'],shield=game[home?'mandante_escudo':'visitante_escudo'],sigla=game[home?'mandante_sigla':'visitante_sigla'];return `<span class="game-team-side"><a class="game-team-shield" href="time.php?id=${Number(id)}" aria-label="Abrir página de ${esc(name)}">${badge({escudo_url:shield,sigla,nome:name,time_nome:name})}</a><span>${teamLink(id,name)}<small>${esc(coach)}</small></span></span>`;};
// Mostra um time definido ou a origem do vencedor que ocupará a vaga futura.
const bracketSide=(game,side)=>{const team=game[`time_${side}`],id=game[`time_${side}_id`],qualification=game[`classificacao_${side}`];if(team){const teamBadge=badge({escudo_url:game[`escudo_${side}`],sigla:game[`sigla_${side}`],nome:game[`tecnico_${side}`],time_nome:team}),isActive=Number(game[`time_${side}_ativo`])===1;return `${isActive?`<a class="bracket-team-shield" href="time.php?id=${Number(id)}" aria-label="Abrir página de ${esc(team)}">${teamBadge}</a>`:`<span class="bracket-team-shield">${teamBadge}</span>`}<span>${isActive?teamLink(id,team):esc(team)}${qualification?`<small>${esc(qualification)}</small>`:''}</span>`;}if(qualification)return `<span class="team-badge bracket-wait">?</span><span class="bracket-placeholder">${esc(qualification)}</span>`;const phase=game[`origem_${side}_fase`],order=game[`origem_${side}_ordem`],origin=game[`origem_${side}_tipo`]==='perdedor'?'Perdedor':'Vencedor';const route=phase?`${origin} da ${phase}${order?` ${order}`:''}`:'Classificação ainda não definida';return `<span class="team-badge bracket-wait">?</span><span class="bracket-placeholder">${esc(route)}</span>`;};
// Agrupa ida e volta em uma única chave e calcula o placar agregado.
const bracketTieCard=matches=>{const first=matches.find(match=>Number(match.jogo)===1)||matches[0];const firstA=String(first.time_a_id??''),firstB=String(first.time_b_id??''),winnerId=String(matches.find(match=>match.vencedor_id)?.vencedor_id??''),phase=first.fase||'';let goalsA=0,goalsB=0,hasScore=false,penaltiesA=null,penaltiesB=null;const legs=matches.map((match,index)=>{const filled=match.gols_a!==null&&match.gols_b!==null;if(filled){hasScore=true;if(String(match.time_a_id)===firstA){goalsA+=Number(match.gols_a);goalsB+=Number(match.gols_b);}else{goalsA+=Number(match.gols_b);goalsB+=Number(match.gols_a);}}if(match.penaltis_a!==null&&match.penaltis_b!==null){penaltiesA=String(match.time_a_id)===firstA?match.penaltis_a:match.penaltis_b;penaltiesB=String(match.time_b_id)===firstB?match.penaltis_b:match.penaltis_a;}const left=filled?(String(match.time_a_id)===firstA?match.gols_a:match.gols_b):'-';const right=filled?(String(match.time_b_id)===firstB?match.gols_b:match.gols_a):'-';return `${index===0?'Ida':'Volta'}: ${left} × ${right}`;});const details=matches.length>1?[`Agregado: ${hasScore?`${goalsA} × ${goalsB}`:'-'}`,...legs].filter(Boolean).join(' • '):'';const state=id=>!winnerId?'':winnerId===id?' is-qualified':' is-eliminated';const outcome=id=>{if(!winnerId)return '';if(phase==='Final')return winnerId===id?'<span class="bracket-outcome champion">CAMPEÃO</span>':'<span class="bracket-outcome runner-up">VICE-CAMPEÃO</span>';if(phase==='Terceiro lugar'&&winnerId===id)return '<span class="bracket-outcome third-place">3º LUGAR</span>';return winnerId===id?'<span class="bracket-outcome qualified">CLASSIFICADO</span>':'';};const score=(goals,penalties)=>`${hasScore?goals:'-'}${penalties!==null?` <span class="bracket-penalty-score">(${penalties})</span>`:''}`;return `<div class="bracket-game match-open ${winnerId?'is-decided':''}" tabindex="0" role="button" data-match-type="mata" data-match-id="${Number(first.id)}"><div class="bracket-team${state(firstA)}"><div class="bracket-team-info">${bracketSide(first,'a')}</div>${outcome(firstA)}<b>${score(goalsA,penaltiesA)}</b></div><div class="bracket-team${state(firstB)}"><div class="bracket-team-info">${bracketSide(first,'b')}</div>${outcome(firstB)}<b>${score(goalsB,penaltiesB)}</b></div>${details?`<small class="bracket-legs">${details}</small>`:''}</div>`;};
let leagueGames = [];
let leaguePage = 1;
let currentChampionship = null;
let allChampionships = [];
const gamesPerPage = 5;
let scorers = [];
let assists = [];
let playerRanking = 'goals';
let scorersPage = 1;
const scorersPerPage = 5;
let titleGroups = [];
const titlePages = {};
const titlesPerPage = 4;

// Mantém uma paginação independente em cada técnico e prioriza os participantes da temporada.
function renderTitles() {
  $('#titles-grid').html(titleGroups.length ? titleGroups.map((group,index)=>{
    const totalPages=Math.ceil(group.titles.length/titlesPerPage);
    const page=Math.min(Math.max(1,titlePages[index] || 1),totalPages);
    titlePages[index]=page;
    const visible=group.titles.slice((page-1)*titlesPerPage,page*titlesPerPage);
    const pagination=totalPages>1?`<footer class="title-pagination" aria-label="Paginação dos títulos de ${esc(group.coach)}"><button type="button" class="page-title" data-group="${index}" data-page="${page-1}" ${page===1?'disabled':''} aria-label="Página anterior">‹</button><span>${page} / ${totalPages}</span><button type="button" class="page-title" data-group="${index}" data-page="${page+1}" ${page===totalPages?'disabled':''} aria-label="Próxima página">›</button></footer>`:'';
    return `<div class="col-md-6 col-xl-4"><article class="title-card"><div class="title-card-head"><span class="title-trophy">🏆</span><div><small>Técnico</small><h3>${esc(group.coach)}</h3>${group.team ? `<p>${group.participantId?teamLink(group.participantId,group.team):esc(group.team)}</p>` : ''}</div><strong>${group.titles.length}</strong></div><div class="title-list">${visible.map(t=>`<div><span>${esc(t.titulo)}</span><b>${esc(t.temporada)}</b></div>`).join('')}</div>${pagination}</article></div>`;
  }).join('') : publicEmpty('A história dos campeões será apresentada em breve.'));
}

// Exibe os dez maiores artilheiros em duas páginas com cinco jogadores cada.
function renderScorers() {
  const topScorers=(playerRanking==='assists'?assists:scorers).slice(0,10);
  const totalPages=Math.max(1,Math.ceil(topScorers.length/scorersPerPage));
  scorersPage=Math.min(Math.max(1,scorersPage),totalPages);
  const visible=topScorers.slice((scorersPage-1)*scorersPerPage,scorersPage*scorersPerPage);
  $('#scorers-list').html(visible.length ? visible.map((a,index)=>{const position=(scorersPage-1)*scorersPerPage+index+1,value=playerRanking==='assists'?a.assistencias:a.gols,label=playerRanking==='assists'?'assistências':'gols';return `<button type="button" class="scorer player-open ${position<=3?`podium podium-${position}`:''}" data-player-name="${esc(a.jogador)}" data-player-team="${Number(a.participante_id)}"><strong class="scorer-rank">${String(position).padStart(2,'0')}</strong><div><strong>${esc(a.jogador)}</strong><small>${esc(a.participante)}</small></div><strong>${value} ${label}</strong></button>`}).join('') : `<div class="empty-state">O ranking de ${playerRanking==='assists'?'assistências':'artilheiros'} começa com o primeiro registro.</div>`);
  $('#scorers-download').toggleClass('d-none',!topScorers.length).prop('disabled',!topScorers.length);
  $('#scorers-pagination').html(topScorers.length>scorersPerPage ? `<button type="button" class="page-scorer" data-page="${scorersPage-1}" ${scorersPage===1?'disabled':''} aria-label="Página anterior">‹</button><span>${scorersPage} / ${totalPages}</span><button type="button" class="page-scorer" data-page="${scorersPage+1}" ${scorersPage===totalPages?'disabled':''} aria-label="Próxima página">›</button>` : '');
}

// Informa se todos os confrontos de uma rodada já receberam resultado.
const roundFinished = games => games.length > 0 && games.every(game => ['finalizada','wo'].includes(game.status));

// Mostra somente a rodada e os times pesquisados pelo usuário.
function renderLeagueGames() {
  const selected=$('#round-select').val() || 'all';
  const query=($('#game-search').val() || '').toLocaleLowerCase('pt-BR').trim();
  let games=leagueGames.filter(game=>selected==='all' || String(game.rodada)===selected);
  if(query) games=games.filter(game=>`${game.mandante} ${game.tecnico_mandante} ${game.visitante} ${game.tecnico_visitante}`.toLocaleLowerCase('pt-BR').includes(query));
  if(selected!=='all')games.sort((a,b)=>Number(!['finalizada','wo'].includes(a.status))-Number(!['finalizada','wo'].includes(b.status)));
  const totalPages=Math.max(1,Math.ceil(games.length/gamesPerPage));
  leaguePage=Math.min(Math.max(1,leaguePage),totalPages);
  const visibleGames=games.slice((leaguePage-1)*gamesPerPage,leaguePage*gamesPerPage);
  if(selected==='all') $('#round-status').text(`${games.length} jogo${games.length===1?'':'s'}`);
  else {
    const roundGames=leagueGames.filter(game=>String(game.rodada)===selected);
    $('#round-status').text(roundFinished(roundGames) ? 'Rodada concluída' : 'Rodada em andamento');
  }
  $('#league-games').html(visibleGames.length ? `<div class="game-sides-head"><span>MANDANTE</span><i aria-hidden="true"></i><span>VISITANTE</span></div>${visibleGames.map(g=>`<div class="game-item match-open" tabindex="0" role="button" data-match-type="pontos" data-match-id="${Number(g.id)}"><div class="game-meta"><span>Rodada ${g.rodada}</span><span>${g.status}</span></div><div class="game-score">${gameTeam(g,'home')}<b class="score">${g.gols_mandante ?? '-'} × ${g.gols_visitante ?? '-'}</b>${gameTeam(g,'away')}</div></div>`).join('')}` : publicEmpty(leagueGames.length?'Nenhuma partida corresponde à sua busca.':'O calendário de jogos será divulgado em breve.'));
  $('#league-pagination').html(games.length>gamesPerPage ? `<button type="button" class="page-game" data-page="${leaguePage-1}" ${leaguePage===1?'disabled':''}>Anterior</button><span>Página ${leaguePage} de ${totalPages}</span><button type="button" class="page-game" data-page="${leaguePage+1}" ${leaguePage===totalPages?'disabled':''}>Próxima</button>` : '');
}

// Preenche o seletor e abre automaticamente a primeira rodada ainda pendente.
function prepareRoundSelect(games) {
  const rounds=[...new Set(games.map(game=>Number(game.rodada)))].sort((a,b)=>a-b);
  const options=rounds.map(round=>{const items=games.filter(game=>Number(game.rodada)===round);return `<option value="${round}">Rodada ${round} — ${roundFinished(items)?'Concluída':'Em andamento'}</option>`}).join('');
  $('#round-select').html('<option value="all">Todas as rodadas</option>'+options);
  const current=rounds.find(round=>!roundFinished(games.filter(game=>Number(game.rodada)===round)));
  $('#round-select').val(current ? String(current) : (rounds[0] ? String(rounds[0]) : 'all'));
}

// Preenche as seções com os dados de api/data.php.
function renderSite(data) {
  currentChampionship=data.campeonato || null;
  allChampionships=data.campeonatos || [];
  const selector=$('#championship-select');
  if(selector.length){const sameType=allChampionships.filter(c=>c.tipo===data.campeonato?.tipo),statusLabel=c=>c.status==='finalizado'?'Finalizado':Number(c.iniciado)===1?'Em andamento':'Ainda não iniciado';selector.html(sameType.map(c=>`<option value="${c.id}" ${Number(c.id)===Number(data.campeonato?.id)?'selected':''}>${esc(c.nome)} — ${statusLabel(c)}</option>`).join(''));$('#championship-title').text(data.campeonato?.nome || 'As próximas competições serão anunciadas em breve.');$('.competition-download').addClass('d-none').prop('disabled',true);if(data.campeonato?.tipo==='pontos_corridos')$('.competition-download[data-export="pontos"]').removeClass('d-none').prop('disabled',false);if(['mata_mata','supercopa'].includes(data.campeonato?.tipo))$('.competition-download[data-export="mata"]').removeClass('d-none').prop('disabled',false);}
  selector.prop('disabled',selector.children().length===0);
  $('#season-status').text(data.resumo?.status || 'Temporada zerada');
  $('#season-summary').text(`${data.resumo?.participantes || 0} técnicos • ${data.resumo?.partidas_finalizadas || 0} partidas finalizadas`);
  $('#season-status-dot').toggleClass('inactive', /aguardando|breve/i.test(data.resumo?.status || ''));
  // Monta a tabela de classificação.
  $('#standings-body').html(data.classificacao.length ? data.classificacao.map(t=>`<tr class="standings-team-row" tabindex="0" role="link" data-team-href="time.php?id=${Number(t.id)}"><td><span class="position-pill ${t.posicao<=4?'top':''}">${t.posicao}</span></td><td><div class="team-cell">${badge(t)}<span>${esc(t.time_nome)}</span></div></td><td>${esc(t.nome)}</td><td><strong>${t.pts}</strong></td><td>${t.j}</td><td>${t.v}</td><td>${t.e}</td><td>${t.d}</td><td>${t.sg>0?'+':''}${t.sg}</td></tr>`).join('') : `<tr><td colspan="9">${publicEmpty('A classificação será exibida assim que a competição começar.')}</td></tr>`);
  // Monta os cards dos participantes.
  $('#participants-grid').html(data.participantes.length ? data.participantes.map(t=>`<div class="col-6 col-lg-3"><a class="participant-card-link" href="time.php?id=${t.id}"><article class="participant-card">${badge(t)}<h3>${esc(t.time_nome)}</h3><p class="coach-name">Técnico: ${esc(t.nome)}</p><p>Participante da Season 3</p></article></a></div>`).join('') : publicEmpty('Os participantes da temporada serão apresentados em breve.'));
  // Monta o seletor e exibe apenas os jogos da rodada escolhida.
  leagueGames=data.partidas || [];
  prepareRoundSelect(leagueGames);
  renderLeagueGames();
  const phases = ['Preliminar','Oitavas','Quartas','Semifinal'];
  // Mantém todas as informações e reúne Final e 3º Lugar na coluna decisiva.
  const phaseColumns=phases.map(phase=>{const games=data.mata_mata.filter(g=>g.fase===phase);if(!games.length)return '';const ties=games.reduce((groups,game)=>{(groups[game.ordem]??=[]).push(game);return groups;},{});return `<div class="bracket-stage" data-stage="${phase}"><h4>${phase}</h4>${Object.values(ties).map(bracketTieCard).join('')}</div>`}).join('');
  const decisionBlock=['Final','Terceiro lugar'].map(phase=>{const games=data.mata_mata.filter(g=>g.fase===phase);if(!games.length)return '';const ties=games.reduce((groups,game)=>{(groups[game.ordem]??=[]).push(game);return groups;},{});return `<section class="bracket-decision ${phase==='Final'?'bracket-final':'bracket-third'}"><h4>${phase==='Terceiro lugar'?'3º LUGAR':'FINAL'}</h4>${Object.values(ties).map(bracketTieCard).join('')}</section>`}).join('');
  const bracketTarget=data.campeonato?.tipo==='supercopa'?'#supercup-bracket':'#bracket';
  $(bracketTarget).html((phaseColumns+(decisionBlock?`<div class="bracket-stage bracket-stage--decisions">${decisionBlock}</div>`:'')) || publicEmpty(data.campeonato?.tipo==='supercopa'?'Aguardando a definição dos campeões.':'O chaveamento será revelado em breve.'));
  // Monta o ranking e o seletor independente de campeonatos da artilharia.
  scorers=data.artilharia || []; assists=data.assistencias || []; scorersPage=1; renderScorers();
  const scorerSelector=$('#scorers-championship-select');
  if(scorerSelector.length){scorerSelector.html(allChampionships.map(c=>`<option value="${c.id}" ${Number(c.id)===Number(data.campeonato?.id)?'selected':''}>${esc(c.nome)} — ${c.status==='finalizado'?'Finalizado':Number(c.iniciado)===1?'Em andamento':'Ainda não iniciado'}</option>`).join(''));scorerSelector.prop('disabled',!allChampionships.length);$('#scorers-championship-title').text(data.campeonato?.nome || 'Os rankings serão apresentados quando houver um campeonato.');}
  // Agrupa os títulos pelo técnico e pelo time, mesmo quando o time histórico não foi informado.
  const titlesByCoach = (data.titulos || []).reduce((groups, title) => { const coach=title.tecnico || 'Técnico não informado'; const team=title.time_nome || ''; const participantId=title.participante_id||null; const key=JSON.stringify([coach,team,participantId]); if(!groups[key])groups[key]={coach,team,participantId,active:Number(title.participante_ativo)===1,titles:[]}; groups[key].titles.push(title); return groups; }, {});
  titleGroups=Object.values(titlesByCoach).sort((a,b)=>Number(b.active)-Number(a.active));
  Object.keys(titlePages).forEach(key=>delete titlePages[key]);
  renderTitles();
  // Monta os vídeos do YouTube.
  $('#videos-grid').html(data.videos.length ? data.videos.map(v=>{const id=(v.youtube_url.match(/(?:youtu\.be\/|v=|embed\/)([\w-]{11})/)||[])[1];return id?`<div class="col-lg-6"><article class="video-card"><iframe src="https://www.youtube-nocookie.com/embed/${id}" title="${esc(v.titulo)}" loading="lazy" allowfullscreen></iframe><h3>${esc(v.titulo)}</h3></article></div>`:''}).join('') : publicEmpty('Novos vídeos da comunidade aparecerão aqui.'));
  // Se um escudo estiver inválido, volta automaticamente para a sigla do time.
  document.querySelectorAll('.team-badge-image img').forEach(image=>image.addEventListener('error',()=>{image.classList.add('d-none');image.nextElementSibling.classList.add('d-grid');}));
  if(data.campeonato?.tipo==='supercopa')bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#supercopa"]')).show();
  else if(data.campeonato?.tipo==='mata_mata')bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#mata-mata"]')).show();
  else if(data.campeonato?.tipo==='pontos_corridos')bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#pontos-corridos"]')).show();
}

function loadChampionship(id=''){$.getJSON('api/data.php',id?{campeonato_id:id}:{}).done(res=>{if(res.ok)renderSite(res)});}
function openCompetitionType(type){const candidates=allChampionships.filter(item=>item.tipo===type);const preferred=candidates.find(item=>Number(item.iniciado)===1&&item.status==='ativo')||candidates.find(item=>Number(item.iniciado)===1)||candidates[0];if(preferred&&Number(preferred.id)!==Number(currentChampionship?.id))loadChampionship(preferred.id);}
async function downloadCompetition(type){
  if(!currentChampionship)return;
  if(!window.html2canvas){await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js';script.onload=resolve;script.onerror=reject;document.head.appendChild(script);});}
  const target=type==='mata'?document.querySelector(currentChampionship?.tipo==='supercopa'?'#supercopa .panel':'#mata-mata .panel'):document.querySelector('#pontos-corridos .panel');const exportArea=document.createElement('div');exportArea.style.cssText='position:fixed;left:-10000px;top:0;width:1400px;padding:40px;background:#101318;color:#fff;z-index:-1';exportArea.innerHTML=`<h1 style="margin:0 0 24px;font-weight:800">${esc(currentChampionship?.nome||'Campeonato')}</h1><p style="color:#9ca8bd">${currentChampionship?.status==='finalizado'?'CAMPEONATO FINALIZADO':'CAMPEONATO EM ANDAMENTO'}</p>`;const clone=target.cloneNode(true);clone.querySelectorAll('.competition-download').forEach(item=>item.remove());exportArea.appendChild(clone);document.body.appendChild(exportArea);
  const canvas=await html2canvas(exportArea,{backgroundColor:'#101318',scale:2,useCORS:true});exportArea.remove();const link=document.createElement('a');link.download=`${(currentChampionship?.nome||'campeonato').replace(/[^a-z0-9]+/gi,'-').toLowerCase()}.png`;link.href=canvas.toDataURL('image/png');link.click();
}

// Gera uma imagem com os dez maiores artilheiros, independentemente da página visível.
async function downloadScorers(){
  const topScorers=(playerRanking==='assists'?assists:scorers).slice(0,10);
  if(!topScorers.length)return;
  if(!window.html2canvas){await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js';script.onload=resolve;script.onerror=reject;document.head.appendChild(script);});}
  const championshipName=$('#scorers-championship-title').text() || 'Campeonato';
  const podiumColors=['#f1c84b','#c8d0da','#c47a3a'];
  const podiumBackgrounds=['rgba(241,200,75,.10)','rgba(200,208,218,.08)','rgba(196,122,58,.09)'];
  const exportArea=document.createElement('div');
  exportArea.style.cssText='position:fixed;left:-10000px;top:0;width:1000px;padding:44px;background:#101318;color:#fff;z-index:-1;font-family:Inter,Arial,sans-serif';
  exportArea.innerHTML=`<div style="border-left:4px solid #ed1c2b;padding-left:18px;margin-bottom:28px"><small style="color:#9ca8bd;letter-spacing:2px">${playerRanking==='assists'?'ASSISTÊNCIAS':'ARTILHARIA'}</small><h1 style="margin:6px 0 0;font-size:44px;font-weight:800">${esc(championshipName)}</h1></div><div style="border:1px solid #303640">${topScorers.map((item,index)=>{const podiumColor=podiumColors[index],positionColor=podiumColor||'#8fa2bf',goalsColor=podiumColor||'#aab5c9',background=podiumBackgrounds[index]||'transparent',value=playerRanking==='assists'?item.assistencias:item.gols,label=playerRanking==='assists'?'assistências':'gols';return `<div style="display:grid;grid-template-columns:70px 1fr 180px 120px;align-items:center;gap:18px;padding:18px 22px;border-left:3px solid ${index<3?podiumColor:'transparent'};border-bottom:${index===topScorers.length-1?'0':'1px solid #303640'};background:${background}"><strong style="font-size:24px;color:${positionColor}">${String(index+1).padStart(2,'0')}</strong><strong style="font-size:20px">${esc(item.jogador)}</strong><span style="color:#aab5c9">${esc(item.participante)}</span><strong style="text-align:right;font-size:20px;color:${goalsColor}">${value} ${label}</strong></div>`}).join('')}</div><p style="margin:24px 0 0;color:#7f8ba0;font-size:13px">Vascão dos Gigantes • Season 3</p>`;
  document.body.appendChild(exportArea);
  const canvas=await html2canvas(exportArea,{backgroundColor:'#101318',scale:2,useCORS:true});
  exportArea.remove();
  const link=document.createElement('a');link.download=`artilharia-${championshipName.replace(/[^a-z0-9]+/gi,'-').toLowerCase()}.png`;link.href=canvas.toDataURL('image/png');link.click();
}

// Aguarda a página carregar antes de iniciar o script.
$(function(){
  $('.competition-tabs').before('<div class="panel p-3 mb-3"><label class="form-label small">CAMPEONATO</label><select id="championship-select" class="form-select"></select><small id="championship-title" class="text-secondary"></small></div>');
  const downloadIcon='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 5-5m-5 5-5-5M5 19h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  $('#pontos-corridos .panel-head').first().append(`<button class="competition-download d-none" data-export="pontos" type="button" title="Baixar classificação como PNG" aria-label="Baixar classificação como PNG">${downloadIcon}</button>`);
  $('#mata-mata .panel-head').first().append(`<button class="competition-download d-none" data-export="mata" type="button" title="Baixar chaveamento como PNG" aria-label="Baixar chaveamento como PNG">${downloadIcon}</button>`);
  $('#supercopa .panel-head').first().append(`<button class="competition-download d-none" data-export="mata" type="button" title="Baixar decisão como PNG" aria-label="Baixar decisão como PNG">${downloadIcon}</button>`);
  $('#round-select').on('change', function(){leaguePage=1;renderLeagueGames()});
  $('#game-search').on('input', function(){leaguePage=1;renderLeagueGames()});
  $('#league-pagination').on('click','.page-game',function(){if(this.disabled)return;leaguePage=Number(this.dataset.page);renderLeagueGames()});
  $('#scorers-pagination').on('click','.page-scorer',function(){if(this.disabled)return;scorersPage=Number(this.dataset.page);renderScorers()});
  $('#titles-grid').on('click','.page-title',function(){if(this.disabled)return;titlePages[Number(this.dataset.group)]=Number(this.dataset.page);renderTitles()});
  $('.player-ranking-tabs').on('click','[data-ranking]',function(){playerRanking=this.dataset.ranking;scorersPage=1;$('.player-ranking-tabs [data-ranking]').removeClass('active').attr('aria-selected','false');$(this).addClass('active').attr('aria-selected','true');renderScorers()});
  $('#scorers-download').on('click',downloadScorers);
  $('#standings-body').on('click','.standings-team-row',function(){location.href=this.dataset.teamHref}).on('keydown','.standings-team-row',function(event){if(event.key==='Enter'||event.key===' '){event.preventDefault();location.href=this.dataset.teamHref}});
  $('#scorers-championship-select').on('change',function(){$.getJSON('api/data.php',{campeonato_id:this.value}).done(res=>{if(!res.ok)return;scorers=res.artilharia||[];assists=res.assistencias||[];scorersPage=1;$('#scorers-championship-title').text(res.campeonato?.nome||'');renderScorers();});});
  // Monta a tabela de classificação.
  $('#championship-select').on('change',function(){loadChampionship(this.value)});$('.competition-download').on('click',function(){downloadCompetition(this.dataset.export)});
  $('[data-bs-target="#pontos-corridos"]').on('shown.bs.tab',()=>openCompetitionType('pontos_corridos'));
  $('[data-bs-target="#mata-mata"]').on('shown.bs.tab',()=>openCompetitionType('mata_mata'));
  $('[data-bs-target="#supercopa"]').on('shown.bs.tab',()=>openCompetitionType('supercopa'));
  const requestedChampionship=new URLSearchParams(location.search).get('campeonato_id')||'';
  $.getJSON('api/data.php',requestedChampionship?{campeonato_id:requestedChampionship}:{}).done(res=>{if(res.ok)renderSite(res)}).fail(()=>{$('#standings-body').html(`<tr><td colspan="9">${publicEmpty('Não foi possível carregar a classificação agora.')}</td></tr>`);$('#league-games,#participants-grid,#scorers-list,#titles-grid,#videos-grid,#bracket').html(publicEmpty('O conteúdo está temporariamente indisponível. Tente novamente em instantes.'))});
});
