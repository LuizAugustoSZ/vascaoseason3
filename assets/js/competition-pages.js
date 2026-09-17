/* Dedicated views reuse the existing API, ranking order and navigation handlers. */
let dedicatedTeams=[];
const originalRenderSite=renderSite;
renderSite=function(data){
 dedicatedTeams=data.participantes||[];originalRenderSite(data);
 if(!document.body.classList.contains('competition-page'))return;
 const games=data.partidas||[],finished=games.filter(g=>['finalizada','wo','penalidade'].includes(g.status));
 $('#competition-summary').remove();
 const status=data.campeonato?.status==='finalizado'?'Finalizado':Number(data.campeonato?.iniciado)===1?'Em andamento':'Ainda não iniciado';
 $('<div id="competition-summary" class="competition-summary"></div>').html([[data.classificacao.length,'Equipes'],[new Set(games.map(g=>g.rodada)).size,'Rodadas'],[games.length,'Jogos'],[status,'Situação']].map(([n,label])=>`<article><strong>${esc(n)}</strong><small>${label}</small></article>`).join('')).prependTo('#pontos-corridos');
 const head=$('#pontos-corridos thead tr');if(!head.find('[data-extra]').length)head.append('<th data-extra>GM</th><th data-extra>GS</th><th data-extra>Últimos 5</th>');
 $('#standings-body tr[data-team-href]').each(function(index){const team=data.classificacao[index];const recent=finished.filter(g=>Number(g.mandante_id)===Number(team.id)||Number(g.visitante_id)===Number(team.id)).sort((a,b)=>String(a.data_partida||'').localeCompare(String(b.data_partida||''))||Number(a.rodada)-Number(b.rodada)||Number(a.id)-Number(b.id)).slice(-5);$(this).append(`<td>${team.gp}</td><td>${team.gc}</td><td><div class="recent-form">${recent.map(g=>{const own=Number(g.mandante_id)===Number(team.id)?Number(g.gols_mandante):Number(g.gols_visitante),other=Number(g.mandante_id)===Number(team.id)?Number(g.gols_visitante):Number(g.gols_mandante),result=own===other?'E':own>other?'V':'D';return `<span class="form-${result}" title="${esc(g.mandante)} ${g.gols_mandante} × ${g.gols_visitante} ${esc(g.visitante)}">${result}</span>`;}).join('')}</div></td>`);$(this).find('td').eq(8).addClass(Number(team.sg)>0?'positive':Number(team.sg)<0?'negative':'');});
};
const originalRenderGames=renderLeagueGames;
renderLeagueGames=function(){
 originalRenderGames();if(!document.body.classList.contains('competition-page'))return;
 const round=$('#round-select').val(),games=leagueGames.filter(g=>round==='all'||String(g.rodada)===round),done=games.filter(g=>['finalizada','wo','penalidade'].includes(g.status)&&g.gols_mandante!==null&&g.gols_visitante!==null);
 const goals=done.reduce((sum,g)=>sum+Number(g.gols_mandante)+Number(g.gols_visitante),0),home=done.filter(g=>Number(g.gols_mandante)>Number(g.gols_visitante)).length,draw=done.filter(g=>Number(g.gols_mandante)===Number(g.gols_visitante)).length;
 $('#round-summary').remove();const best=[...done].sort((a,b)=>Math.abs(Number(b.gols_mandante)-Number(b.gols_visitante))-Math.abs(Number(a.gols_mandante)-Number(a.gols_visitante)))[0];
 $('<section id="round-summary" class="panel round-summary"></section>').html(`<div class="panel-head"><h3>Resumo ${round==='all'?'dos jogos':'da rodada '+esc(round)}</h3></div>${done.length?`<div class="round-summary-grid">${[[goals,'Gols marcados'],[(goals/done.length).toLocaleString('pt-BR',{maximumFractionDigits:2}),'Média por jogo'],[home,'Vitórias em casa'],[draw,'Empates'],[done.length-home-draw,'Vitórias fora']].map(([n,label])=>`<article><strong>${n}</strong><small>${label}</small></article>`).join('')}</div><p>${done.length} de ${games.length} jogos finalizados</p>${best&&Number(best.gols_mandante)!==Number(best.gols_visitante)?`<p>Maior diferença: ${esc(best.mandante)} <b>${best.gols_mandante} × ${best.gols_visitante}</b> ${esc(best.visitante)}</p>`:''}`:'<p>Aguardando resultados nesta rodada.</p>'}`).appendTo('#pontos-corridos');
 $('#league-games .game-item').each(function(){const game=leagueGames.find(g=>Number(g.id)===Number(this.dataset.matchId));if(game?.data_partida)$(this).find('.game-meta').append(`<time>${esc(new Date(game.data_partida.replace(' ','T')).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}))}</time>`);});
};
const originalRenderScorers=renderScorers;
renderScorers=function(){
 if(!document.body.classList.contains('players-page'))return originalRenderScorers();
 const rows=(playerRanking==='assists'?assists:scorers).slice(0,10),label=playerRanking==='assists'?'assistências':'gols',value=p=>playerRanking==='assists'?p.assistencias:p.gols;
 const avatar=p=>{const team=dedicatedTeams.find(t=>Number(t.id)===Number(p.participante_id));return team?badge(team):`<span class="player-initials">${esc(p.jogador.slice(0,2).toUpperCase())}</span>`;};
 $('#player-podium').remove();$('<div id="player-podium" class="player-podium"></div>').html(rows.slice(0,3).map((p,i)=>`<button type="button" class="podium-card place-${i+1} player-open" data-player-name="${esc(p.jogador)}" data-player-team="${Number(p.participante_id)}"><small>${i+1}º</small>${avatar(p)}<strong>${esc(p.jogador)}</strong><span>${esc(p.participante)}</span><b>${value(p)} ${label}</b></button>`).join('')).insertBefore('#scorers-list');
 const remaining=rows.slice(3),pages=Math.max(1,Math.ceil(remaining.length/5));scorersPage=Math.min(Math.max(1,scorersPage),pages);
 $('#scorers-list').html(rows.length?remaining.slice((scorersPage-1)*5,scorersPage*5).map((p,i)=>`<button type="button" class="scorer player-open" data-player-name="${esc(p.jogador)}" data-player-team="${Number(p.participante_id)}"><span>${String(4+(scorersPage-1)*5+i).padStart(2,'0')}</span><strong>${esc(p.jogador)}</strong><span class="ranking-club">${avatar(p)}${esc(p.participante)}</span><b>${value(p)} <small>${label}</small></b></button>`).join(''):publicEmpty('O ranking começa com o primeiro registro.'));
 $('#scorers-pagination').html(pages>1?`<button class="page-scorer" data-page="${scorersPage-1}" ${scorersPage===1?'disabled':''} aria-label="Página anterior">‹</button><span>${scorersPage} / ${pages}</span><button class="page-scorer" data-page="${scorersPage+1}" ${scorersPage===pages?'disabled':''} aria-label="Próxima página">›</button>`:'');$('#scorers-download').toggleClass('d-none',!rows.length).prop('disabled',!rows.length);
};
