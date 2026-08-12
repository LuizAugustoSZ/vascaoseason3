<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
master_required();
$contas=db()->query('SELECT id,nome FROM contas WHERE ativo=1 AND participante_id IS NULL ORDER BY nome')->fetchAll();
$times=db()->query('SELECT id,nome,time_nome FROM participantes WHERE ativo=1 AND id NOT IN (SELECT participante_id FROM contas WHERE participante_id IS NOT NULL) ORDER BY time_nome')->fetchAll();
$sugestoes=[];
foreach($contas as $conta){foreach($times as $time){if(mb_strtolower(trim($conta['nome']))===mb_strtolower(trim($time['nome']))){$sugestoes[]=['conta_id'=>(int)$conta['id'],'conta_nome'=>$conta['nome'],'participante_id'=>(int)$time['id'],'tecnico'=>$time['nome'],'time_nome'=>$time['time_nome']];break;}}}
json_response(['ok'=>true,'csrf'=>csrf_token(),'sugestoes'=>$sugestoes]);
