(()=>{
const text=document.getElementById('dreamteam-summary-text');
const analyze=document.getElementById('dreamteam-analyze');
const preview=document.getElementById('dreamteam-preview');
const csrf=document.getElementById('dreamteam-summary-csrf');
if(!text||!analyze||!preview||!csrf)return;
const escape=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
const request=async action=>{
  const payload=new FormData();payload.set('csrf',csrf.value);payload.set('action',action);payload.set('sumula',text.value);
  if(action==='import'){
    payload.set('match_key',preview.querySelector('[name="dreamteam_match"]')?.value||'');
    preview.querySelectorAll('[name="ignored_roster_issues[]"]:checked').forEach(input=>payload.append('ignored_roster_issues[]',input.value));
  }
  const response=await fetch('sumula-importar.php',{method:'POST',body:payload,headers:{Accept:'application/json'},credentials:'same-origin'});
  const body=await response.text();
  let data;try{data=JSON.parse(body);}catch{const plain=body.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();data={ok:false,message:response.redirected?'Sua sessão expirou. Atualize a página e entre novamente.':(plain||`Erro ${response.status} ao processar a súmula.`)};}
  if(!response.ok||!data.ok)throw new Error(data.message||'Não foi possível processar a súmula.');return data;
};
const render=data=>{
  const p=data.parsed;
  const options=data.candidates.map((item,index)=>`<option value="${escape(item.key)}" ${data.existing_match_key===item.key||(!data.existing_match_key&&(data.candidates.length===1||index===0))?'selected':''}>${escape(item.label)}: ${escape(item.status)}</option>`).join('');
  const warnings=p.warnings.map(item=>`<li>${escape(item)}</li>`).join('');
  const goals=p.goals.map(goal=>`<li><b>${escape(goal.minute)}'</b> ${escape(goal.player)} (${escape(goal.team_code)}): ${escape(goal.goal_type)}${goal.assist?` • assistência: ${escape(goal.assist)}`:''}</li>`).join('');
  const stats=p.teams.map((team,index)=>`<div class="col-md-6"><div class="border p-3 h-100"><strong>${escape(index===0?p.home_name:p.away_name)} (${escape(team.code)})</strong><small class="d-block text-secondary mt-2">Finalizações: ${team.stats.shots??'-'} • No gol: ${team.stats.shots_on_target??'-'}<br>Posse: ${team.stats.possession??'-'}% • Escanteios: ${team.stats.corners??'-'}<br>Defesas: ${team.stats.saves??'-'} • Faltas sofridas: ${team.stats.fouls_suffered??'-'}<br>Cartões: ${team.stats.yellow_cards??'-'} amarelos, ${team.stats.red_cards??'-'} vermelhos</small></div></div>`).join('');
  const rewrite=data.is_rewrite?'<div class="alert alert-info"><strong>Esta partida já possui súmula.</strong> Ao confirmar, os dados anteriores serão substituídos.</div>':'';
  preview.innerHTML=`<span class="eyebrow">${escape(p.dreamteam_id)}</span><h2 class="mt-2">${escape(p.home_name)} ${p.home_goals} × ${p.away_goals} ${escape(p.away_name)}</h2><p class="text-secondary">${escape(p.stadium)} • ${escape(p.weather)} • ${p.duration??'-'} minutos</p>${rewrite}${warnings?`<div class="alert alert-warning"><strong>Revisão necessária</strong><ul class="mb-0 mt-2">${warnings}</ul></div>`:''}<div class="row g-2 mb-3">${stats}</div><h3>Gols válidos (${p.goals.length})</h3><ul class="small">${goals||'<li>Nenhum gol</li>'}</ul><p class="small text-secondary">Acontecimentos reconhecidos: ${p.events.length}.</p><label class="form-label">Partida identificada</label><select class="form-select" name="dreamteam_match">${options||'<option value="">Nenhuma partida compatível encontrada</option>'}</select><div class="dreamteam-roster-issues mt-3"></div><button type="button" class="btn btn-success mt-3 dreamteam-confirm" ${warnings||!options?'disabled':''}>${data.is_rewrite?'Confirmar e reescrever súmula':'Confirmar e importar tudo'}</button>`;
  const matchSelect=preview.querySelector('[name="dreamteam_match"]');
  const rosterBox=preview.querySelector('.dreamteam-roster-issues');
  const confirmButton=preview.querySelector('.dreamteam-confirm');
  const updateConfirmState=()=>{const issues=data.roster_issues?.[matchSelect?.value]||[];const ignored=rosterBox.querySelectorAll('[name="ignored_roster_issues[]"]:checked').length;if(confirmButton)confirmButton.disabled=Boolean(warnings||!options||ignored<issues.length);};
  const updateRosterIssues=()=>{const issues=data.roster_issues?.[matchSelect?.value]||[];rosterBox.innerHTML=issues.length?`<div class="alert alert-danger mb-0"><strong>Verifique a escalação</strong><p class="small mb-2 mt-1">Confira cada jogador e marque somente os avisos que deseja ignorar.</p><ul class="mb-0 ps-3">${issues.map((item,index)=>`<li class="mb-2"><div>${escape(item)}</div><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="ignored_roster_issues[]" value="${escape(item)}" id="dreamteam-roster-issue-${index}"><label class="form-check-label" for="dreamteam-roster-issue-${index}">Ignorar este aviso</label></div></li>`).join('')}</ul></div>`:'';updateConfirmState();};
  rosterBox.addEventListener('change',event=>{if(event.target.matches('[name="ignored_roster_issues[]"]'))updateConfirmState();});
  matchSelect?.addEventListener('change',updateRosterIssues);updateRosterIssues();
};
analyze.addEventListener('click',async()=>{const old=analyze.textContent;analyze.disabled=true;analyze.textContent='Analisando...';try{render(await request('analyze'));}catch(error){preview.innerHTML=`<div class="alert alert-danger mb-0">${escape(error.message)}</div>`;}finally{analyze.disabled=false;analyze.textContent=old;}});
preview.addEventListener('click',async event=>{const button=event.target.closest('.dreamteam-confirm');if(!button)return;const originalLabel=button.textContent;const rewriting=originalLabel.includes('reescrever');if(!confirm(rewriting?'Confirmar a reescrita e substituir os dados anteriores desta partida?':'Confirmar a importação e atualizar o resultado desta partida?'))return;button.disabled=true;button.textContent=rewriting?'Reescrevendo...':'Importando...';try{const data=await request('import');preview.innerHTML=`<div class="alert alert-success mb-0"><strong>${rewriting?'Reescrita':'Importação'} concluída.</strong><br>${escape(data.message)}</div>`;text.value='';}catch(error){button.disabled=false;button.textContent=originalLabel;alert(error.message);}});
})();
