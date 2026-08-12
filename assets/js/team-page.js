document.querySelectorAll('[data-team-games]').forEach(section=>{
  const games=[...section.querySelectorAll('.team-games article')],pagination=section.querySelector('.team-games-pagination');
  if(games.length<=5)return;
  let page=1;const pages=Math.ceil(games.length/5);
  const render=()=>{games.forEach((game,index)=>game.classList.toggle('d-none',index<(page-1)*5||index>=page*5));pagination.innerHTML=`<button type="button" ${page===1?'disabled':''} data-direction="-1">Anterior</button><span>Página ${page} de ${pages}</span><button type="button" ${page===pages?'disabled':''} data-direction="1">Próxima</button>`;};
  pagination.addEventListener('click',event=>{const button=event.target.closest('button');if(!button||button.disabled)return;page+=Number(button.dataset.direction);render();section.scrollIntoView({behavior:'smooth',block:'start'});});render();
});

// Resume automaticamente a campanha a partir dos resultados oficiais exibidos.
const playedSection=document.querySelector('[data-team-games]');
const teamName=document.querySelector('.team-identity h1')?.textContent.trim();
if(playedSection&&teamName){const summary={Jogos:0,'Vitórias':0,Empates:0,Derrotas:0,'Gols pró':0,'Gols contra':0,Saldo:0};playedSection.querySelectorAll('.team-games article').forEach(game=>{const sides=game.querySelectorAll('div>span'),score=game.querySelector('div>strong')?.textContent.match(/(\d+)\s*×\s*(\d+)/);if(sides.length<2||!score)return;const home=Number(score[1]),away=Number(score[2]),isHome=sides[0].textContent.trim()===teamName,goalsFor=isHome?home:away,goalsAgainst=isHome?away:home;summary.Jogos++;summary['Gols pró']+=goalsFor;summary['Gols contra']+=goalsAgainst;if(goalsFor>goalsAgainst)summary['Vitórias']++;else if(goalsFor===goalsAgainst)summary.Empates++;else summary.Derrotas++;});summary.Saldo=summary['Gols pró']-summary['Gols contra'];const stats=document.createElement('section');stats.className='team-stats';stats.setAttribute('aria-label','Resumo da campanha');stats.innerHTML=Object.entries(summary).map(([label,value])=>`<div><small>${label}</small><strong>${label==='Saldo'&&value>0?'+':''}${value}</strong></div>`).join('');const label=document.createElement('small');label.className='team-auto-label';label.textContent='Atualizado automaticamente pelas competições';const row=document.querySelector('.team-page>.row');row?.before(stats,label);}

const teamStyles=document.createElement('link');teamStyles.rel='stylesheet';teamStyles.href='assets/css/team-games.css';document.head.append(teamStyles);
