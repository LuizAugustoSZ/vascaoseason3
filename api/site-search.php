<?php
require __DIR__.'/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$query=mb_substr(trim((string)($_GET['q']??'')),0,80);$results=[];
if(mb_strlen($query)<2){echo '[]';exit;}
$pages=['Notícias'=>'noticias.php','Competição'=>'index.php#competicao','Participantes'=>'index.php#participantes','Jogadores'=>'index.php#artilharia','Vídeos'=>'index.php#midia','Títulos'=>'titulos.php','Estatísticas e recordes'=>'estatisticas.php','Mercado'=>'mercado-transferencias.php','Comandos'=>'comandos.php','Regulamento'=>'regulamento.php'];
if(account_logged_in())$pages+=['Elenco geral'=>'elenco-geral.php','Gestão da competição'=>'mercado.php','Notificações'=>'notificacoes.php'];
foreach($pages as $label=>$url)if(mb_stripos($label,$query)!==false)$results[]=['label'=>$label,'type'=>'Tela','url'=>$url];
$like='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
$q=db()->prepare("SELECT id,time_nome FROM participantes WHERE ativo=1 AND time_nome LIKE ? ESCAPE '!' ORDER BY time_nome LIMIT 5");$q->execute([$like]);foreach($q as $row)$results[]=['label'=>$row['time_nome'],'type'=>'Clube','url'=>'time.php?id='.$row['id']];
$q=db()->prepare("SELECT id,titulo FROM noticias WHERE ativo=1 AND titulo LIKE ? ESCAPE '!' ORDER BY id DESC LIMIT 5");$q->execute([$like]);foreach($q as $row)$results[]=['label'=>$row['titulo'],'type'=>'Notícia','url'=>'noticia.php?id='.$row['id']];
$q=db()->prepare("SELECT j.nome,j.participante_id,p.time_nome FROM jogadores_gerais j JOIN participantes p ON p.id=j.participante_id WHERE j.ativo=1 AND p.ativo=1 AND j.nome LIKE ? ESCAPE '!' ORDER BY j.nome LIMIT 5");$q->execute([$like]);foreach($q as $row)$results[]=['label'=>$row['nome'].' · '.$row['time_nome'],'type'=>'Jogador','url'=>'time.php?id='.$row['participante_id']];
echo json_encode($results,JSON_UNESCAPED_UNICODE);
