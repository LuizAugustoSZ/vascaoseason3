<?php
// Entrega os placares de pênaltis ao formulário de edição do painel.
require __DIR__ . '/../includes/bootstrap.php';
admin_required();
$pdo=db();
$stmt=$pdo->prepare('SELECT id,campeonato_id,fase,ordem,time_a_id,time_b_id,penaltis_a,penaltis_b FROM jogos_mata_mata WHERE id=? AND ativo=1');
$stmt->execute([(int)($_GET['id'] ?? 0)]);$game=$stmt->fetch();
if(!$game) json_response(['ok'=>true,'jogo'=>null]);
$others=$pdo->prepare('SELECT time_a_id,time_b_id,gols_a,gols_b FROM jogos_mata_mata WHERE campeonato_id=? AND fase=? AND ordem=? AND id<>? AND ativo=1');
$others->execute([$game['campeonato_id'],$game['fase'],$game['ordem'],$game['id']]);$otherA=0;$otherB=0;
foreach($others->fetchAll() as $other){
  if($other['gols_a']===null || $other['gols_b']===null)continue;
  if((int)$other['time_a_id']===(int)$game['time_a_id']){$otherA+=(int)$other['gols_a'];$otherB+=(int)$other['gols_b'];}
  else{$otherA+=(int)$other['gols_b'];$otherB+=(int)$other['gols_a'];}
}
$game['outros_gols_a']=$otherA;$game['outros_gols_b']=$otherB;
$goals=$pdo->prepare('SELECT participante_id,jogador,minuto,tipo FROM gols_mata_mata WHERE jogo_mata_mata_id=? ORDER BY id');$goals->execute([(int)$game['id']]);
json_response(['ok'=>true,'jogo'=>$game,'gols'=>$goals->fetchAll()]);
