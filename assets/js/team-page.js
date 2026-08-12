document.querySelectorAll('[data-team-games]').forEach(section=>{
  const games=[...section.querySelectorAll('.team-games article')],pagination=section.querySelector('.team-games-pagination');
  if(games.length<=5)return;
  let page=1;const pages=Math.ceil(games.length/5);
  const render=()=>{games.forEach((game,index)=>game.classList.toggle('d-none',index<(page-1)*5||index>=page*5));pagination.innerHTML=`<button type="button" ${page===1?'disabled':''} data-direction="-1">Anterior</button><span>Página ${page} de ${pages}</span><button type="button" ${page===pages?'disabled':''} data-direction="1">Próxima</button>`;};
  pagination.addEventListener('click',event=>{const button=event.target.closest('button');if(!button||button.disabled)return;page+=Number(button.dataset.direction);render();section.scrollIntoView({behavior:'smooth',block:'start'});});render();
});
document.querySelector('.navbar')?.classList.add('fixed-top');

// Resume automaticamente a campanha a partir dos resultados oficiais exibidos.
const playedSection=document.querySelector('[data-team-games]');
const teamName=document.querySelector('.team-identity h1')?.textContent.trim();
if(playedSection&&teamName){const summary={Jogos:0,'Vitórias':0,Empates:0,Derrotas:0,'Gols pró':0,'Gols contra':0,Saldo:0};playedSection.querySelectorAll('.team-games article').forEach(game=>{const sides=game.querySelectorAll('div>span'),score=game.querySelector('div>strong')?.textContent.match(/(\d+)\s*×\s*(\d+)/);if(sides.length<2||!score)return;const home=Number(score[1]),away=Number(score[2]),isHome=sides[0].textContent.trim()===teamName,goalsFor=isHome?home:away,goalsAgainst=isHome?away:home;summary.Jogos++;summary['Gols pró']+=goalsFor;summary['Gols contra']+=goalsAgainst;if(goalsFor>goalsAgainst)summary['Vitórias']++;else if(goalsFor===goalsAgainst)summary.Empates++;else summary.Derrotas++;});summary.Saldo=summary['Gols pró']-summary['Gols contra'];const stats=document.createElement('section');stats.className='team-stats';stats.setAttribute('aria-label','Resumo da campanha');stats.innerHTML=Object.entries(summary).map(([label,value])=>`<div><small>${label}</small><strong>${label==='Saldo'&&value>0?'+':''}${value}</strong></div>`).join('');const label=document.createElement('small');label.className='team-auto-label';label.textContent='Atualizado automaticamente pelas competições';const row=document.querySelector('.team-page>.row');row?.before(stats,label);}

// Reorganiza os dados reais e os módulos futuros no formato de painel do clube.
const originalRow=document.querySelector('.team-page>.row');
if(originalRow){const gameSections=[...originalRow.querySelectorAll('[data-team-games]')],scorersSection=originalRow.querySelector('.team-scorers')?.closest('section');const dashboard=document.createElement('section');dashboard.className='club-dashboard';gameSections[0]?.classList.remove('mb-4');gameSections.forEach(section=>dashboard.append(section));if(scorersSection)dashboard.append(scorersSection);dashboard.insertAdjacentHTML('beforeend','<article class="club-module club-module--treasury"><small>Cofre do clube</small><strong>Em breve</strong><span>Saldo e movimentações serão liberados com o mercado.</span></article><article class="club-module club-module--transfers"><small>Últimas contratações</small><strong>Em breve</strong><span>As movimentações oficiais aparecerão neste quadro.</span></article>');originalRow.replaceWith(dashboard);const duplicateTreasury=[...document.querySelectorAll('.team-future>div')].find(module=>module.querySelector('small')?.textContent.trim()==='COFRE');duplicateTreasury?.remove();}

// Usa o próprio escudo como marca d'água no fundo do cabeçalho do clube.
const hero=document.querySelector('.team-hero'),shield=document.querySelector('.team-identity>img');
if(hero&&shield){const watermark=shield.cloneNode();watermark.className='team-hero-watermark';watermark.alt='';watermark.setAttribute('aria-hidden','true');hero.append(watermark);hero.classList.add('has-team-watermark');}
