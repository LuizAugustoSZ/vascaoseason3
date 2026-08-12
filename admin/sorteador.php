<?php
// Carrega a estrutura compartilhada e exige login de administrador.
require __DIR__ . '/../includes/bootstrap.php'; master_required();
$pdo=db(); $notice=$_SESSION['notice'] ?? ''; unset($_SESSION['notice']);
function draw_ajax(): bool { return isset($_POST['_ajax']) || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))==='xmlhttprequest'; }
function go(string $message): never { if(draw_ajax()){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'message'=>$message],JSON_UNESCAPED_UNICODE);exit;}$_SESSION['notice']=$message;header('Location: sorteador.php');exit; }
// Valida os participantes escolhidos e embaralha seus IDs.
function selected(PDO $pdo): array {
  $ids=array_values(array_unique(array_filter(array_map('intval',$_POST['participantes'] ?? []))));
  if(count($ids)<2) throw new RuntimeException('Selecione pelo menos 2 participantes.');
  $marks=implode(',',array_fill(0,count($ids),'?')); $stmt=$pdo->prepare("SELECT id FROM participantes WHERE ativo=1 AND id IN ($marks)"); $stmt->execute($ids);
  $valid=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)); if(count($valid)!==count($ids)) throw new RuntimeException('Participante inválido ou inativo.');
  shuffle($valid); return $valid;
}
// Gera rodadas em que cada time enfrenta todos os outros.
function rounds(array $ids): array {
  if(count($ids)%2)$ids[]=null; $count=count($ids); $result=[];
  for($r=1;$r<$count;$r++){ $games=[]; for($i=0;$i<$count/2;$i++){ $a=$ids[$i];$b=$ids[$count-1-$i];if($a!==null&&$b!==null)$games[]=$r%2?[$a,$b]:[$b,$a]; } $result[]=$games; $fixed=array_shift($ids);$last=array_pop($ids);array_unshift($ids,$fixed);array_splice($ids,1,0,[$last]); }
  return $result;
}
// Retorna todas as fases que precisam existir no chaveamento sorteado.
function knockout_phases(int $count): array {
  return match($count){4=>['Semifinal','Final'],8=>['Quartas','Semifinal','Final'],16=>['Oitavas','Quartas','Semifinal','Final']};
}
// Executa o sorteio enviado por um dos formulários.
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  try{
    $action=$_POST['action'] ?? ''; $ids=selected($pdo);$championshipName=trim($_POST['nome_campeonato'] ?? '');
    if($championshipName==='')throw new RuntimeException('Informe o nome do campeonato.');
    // Sorteia todas as rodadas dos pontos corridos.
    if($action==='pontos'){
      $format=$_POST['formato'] ?? 'ida'; if(!in_array($format,['ida','ida_volta'],true))throw new RuntimeException('Formato inválido.');
      $all=rounds($ids);$pdo->beginTransaction();$pdo->prepare("INSERT INTO campeonatos(nome,tipo,formato) VALUES(?,'pontos_corridos',?)")->execute([$championshipName,$format]);$championshipId=(int)$pdo->lastInsertId();
      $stmt=$pdo->prepare("INSERT INTO partidas(campeonato_id,rodada,turno,mandante_id,visitante_id,gols_mandante,gols_visitante,data_partida,status,comprovacao_url) VALUES(?,?,?,?,?,NULL,NULL,NULL,'agendada','')");
      foreach($all as $r=>$games)foreach($games as [$a,$b])$stmt->execute([$championshipId,$r+1,1,$a,$b]);
      if($format==='ida_volta'){$offset=count($all);foreach($all as $r=>$games)foreach($games as [$a,$b])$stmt->execute([$championshipId,$offset+$r+1,2,$b,$a]);}
      $pdo->commit();go('Pontos corridos sorteados. Os jogos já apareceram no site.');
    }
    // Sorteia a primeira fase do mata-mata.
    if($action==='mata'){
      $count=count($ids);$format=$_POST['formato'] ?? 'unico';$finalFormat=$_POST['formato_final'] ?? 'unico';$thirdFormat=$_POST['formato_terceiro'] ?? 'unico';if(!in_array($count,[4,8,16],true))throw new RuntimeException('Selecione 4, 8 ou 16 participantes.');foreach([$format,$finalFormat,$thirdFormat] as $selectedFormat)if(!in_array($selectedFormat,['unico','ida_volta'],true))throw new RuntimeException('Formato inválido.');
      $pdo->beginTransaction();$pdo->prepare("INSERT INTO campeonatos(nome,tipo,formato) VALUES(?,'mata_mata',?)")->execute([$championshipName,$format]);$championshipId=(int)$pdo->lastInsertId();
      $phases=knockout_phases($count);$stmt=$pdo->prepare("INSERT INTO jogos_mata_mata(campeonato_id,fase,ordem,jogo,time_a_id,time_b_id,origem_a_fase,origem_a_ordem,origem_b_fase,origem_b_ordem,gols_a,gols_b,vencedor_id,status) VALUES(?,?,?,?,?,?,?,?,?,?,NULL,NULL,NULL,'agendado')");
      foreach($phases as $phaseIndex=>$phase){
        $ties=intdiv($count,2**($phaseIndex+1));
        for($order=1;$order<=$ties;$order++){
          if($phaseIndex===0){$timeA=$ids[($order-1)*2];$timeB=$ids[(($order-1)*2)+1];$originPhase=null;$originA=null;$originB=null;}
          else{$timeA=null;$timeB=null;$originPhase=$phases[$phaseIndex-1];$originA=(($order-1)*2)+1;$originB=$originA+1;}
          $stmt->execute([$championshipId,$phase,$order,1,$timeA,$timeB,$originPhase,$originA,$originPhase,$originB]);
          $phaseFormat=$phase==='Final'?$finalFormat:$format;
          if($phaseFormat==='ida_volta')$stmt->execute([$championshipId,$phase,$order,2,$timeB,$timeA,$originPhase,$originB,$originPhase,$originA]);
        }
      }
      // A disputa de terceiro lugar usa o formato escolhido independentemente das demais fases.
      $bronze=$pdo->prepare("INSERT INTO jogos_mata_mata(campeonato_id,fase,ordem,jogo,time_a_id,time_b_id,origem_a_fase,origem_a_ordem,origem_a_tipo,origem_b_fase,origem_b_ordem,origem_b_tipo,gols_a,gols_b,vencedor_id,status) VALUES(?,'Terceiro lugar',1,1,NULL,NULL,'Semifinal',1,'perdedor','Semifinal',2,'perdedor',NULL,NULL,NULL,'agendado')");
      $bronze->execute([$championshipId]);
      if($thirdFormat==='ida_volta'){
        $bronzeVolta=$pdo->prepare("INSERT INTO jogos_mata_mata(campeonato_id,fase,ordem,jogo,time_a_id,time_b_id,origem_a_fase,origem_a_ordem,origem_a_tipo,origem_b_fase,origem_b_ordem,origem_b_tipo,gols_a,gols_b,vencedor_id,status) VALUES(?,'Terceiro lugar',1,2,NULL,NULL,'Semifinal',2,'perdedor','Semifinal',1,'perdedor',NULL,NULL,NULL,'agendado')");
        $bronzeVolta->execute([$championshipId]);
      }
      $pdo->commit();go('Mata-mata sorteado. O chaveamento já apareceu no site.');
    }
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(draw_ajax()){header('Content-Type: application/json; charset=utf-8');http_response_code(422);echo json_encode(['ok'=>false,'message'=>'Erro: '.$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;}$notice='Erro: '.$e->getMessage();}
}
$teams=$pdo->query('SELECT id,nome,time_nome FROM participantes WHERE ativo=1 ORDER BY time_nome')->fetchAll();
function checks(array $teams):string{$out='';foreach($teams as $t)$out.='<div class="col-md-6"><label class="form-check sorteio-team"><input class="form-check-input" type="checkbox" name="participantes[]" value="'.(int)$t['id'].'" checked><span><strong>'.e($t['time_nome']).'</strong><small>Técnico '.e($t['nome']).'</small></span></label></div>';return $out;}
?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sorteador | Season 3</title><link rel="icon" href="../favicon.ico" sizes="any"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/style.css"><style>.admin-shell{padding:105px 0 60px}.sorteio-form{padding:1.4rem}.sorteio-form h2{font:800 1.8rem 'Barlow Condensed',sans-serif;text-transform:uppercase}.form-select{background:#0b0c0e;border-color:#343941}.sorteio-team{display:flex;gap:.7rem;height:100%;padding:1rem 1rem 1rem 2.3rem;border:1px solid #343941;cursor:pointer}.sorteio-team small{display:block;color:#8d98ad}.sorteio-team:has(input:checked){border-color:#ed1b2f;background:rgba(237,27,47,.08)}</style></head><body>
<nav class="navbar fixed-top navbar-dark"><div class="container"><a class="navbar-brand" href="index.php"><span class="brand-mark d-inline-grid me-2">VG</span> SORTEADOR S3</a><div><a href="index.php" class="btn btn-outline-light btn-sm me-2">Administração</a><a href="../index.php" class="btn btn-danger btn-sm" target="_blank">Abrir site</a></div></div></nav>
<main class="admin-shell"><div class="container"><span class="eyebrow">Sorteio automático</span><h1 class="display-4 fw-bold mb-4">CRIAR COMPETIÇÕES</h1><?php if($notice):?><div class="alert alert-info"><?=e($notice)?></div><?php endif?><div class="row g-4">
<div class="col-xl-6"><form class="panel sorteio-form sorteio-pontos" method="post" onsubmit="return confirm('Sortear e gravar todos os jogos?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="pontos"><h2>Pontos corridos</h2><p class="text-secondary">Todos contra todos, com rodadas organizadas automaticamente.</p><div class="alert alert-info small"><strong class="selected-count"></strong><br>Quantidade ímpar é permitida: com 7 participantes, cada rodada terá 3 jogos e 1 participante de folga. Em ida e volta, os confrontos se repetem com mando invertido.</div><div class="row g-2 mb-3"><?=checks($teams)?></div><label class="form-label">Formato</label><select class="form-select" name="formato"><option value="ida">Somente ida</option><option value="ida_volta">Ida e volta</option></select><button class="btn btn-danger mt-3">Iniciar sorteio</button></form></div>
<div class="col-xl-6"><form class="panel sorteio-form sorteio-mata" method="post" onsubmit="return confirm('Sortear e gravar o chaveamento?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="mata"><h2>Mata-mata</h2><p class="text-secondary">Selecione exatamente 4, 8 ou 16 participantes.</p><div class="alert alert-info small"><strong class="selected-count"></strong><br>O mata-mata precisa ter 4, 8 ou 16 participantes para formar todas as chaves sem folgas. A Final e o 3º Lugar podem ter formatos diferentes das fases anteriores.</div><div class="row g-2 mb-3"><?=checks($teams)?></div><div class="row g-2"><div class="col-12"><label class="form-label">Formato das fases anteriores</label><select class="form-select" name="formato"><option value="unico">Jogo único</option><option value="ida_volta">Ida e volta</option></select></div><div class="col-md-6"><label class="form-label">Formato da Final</label><select class="form-select" name="formato_final"><option value="unico">Jogo único</option><option value="ida_volta">Ida e volta</option></select></div><div class="col-md-6"><label class="form-label">Formato do 3º Lugar</label><select class="form-select" name="formato_terceiro"><option value="unico">Jogo único</option><option value="ida_volta">Ida e volta</option></select></div></div><button class="btn btn-danger mt-3">Iniciar sorteio</button></form></div>
</div></div></main><script>document.querySelectorAll('.sorteio-form').forEach(form=>{const title=form.querySelector('h2');title.insertAdjacentHTML('afterend','<label class="form-label mt-2">Nome do campeonato</label><input class="form-control mb-3" name="nome_campeonato" maxlength="150" placeholder="Ex.: Copa Vascão S3" required>');const update=()=>{const total=form.querySelectorAll('input[name="participantes[]"]:checked').length;form.querySelector('.selected-count').textContent=`${total} participante${total===1?'':'s'} selecionado${total===1?'':'s'}.`;};form.addEventListener('change',update);update();form.addEventListener('submit',async event=>{if(event.defaultPrevented)return;event.preventDefault();const button=event.submitter||form.querySelector('button');const old=button.textContent;button.disabled=true;button.textContent='Sorteando...';try{const payload=new FormData(form);payload.set('_ajax','1');const response=await fetch('sorteador.php',{method:'POST',body:payload,headers:{'Accept':'application/json'},credentials:'same-origin'});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'Não foi possível sortear.');form.nome_campeonato.value='';alert(data.message);}catch(error){alert(error.message);}finally{button.disabled=false;button.textContent=old;}});});</script></body></html>
