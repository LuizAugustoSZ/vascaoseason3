<?php
declare(strict_types=1);
// Carrega as funções e abre o acesso ao banco.
require __DIR__ . '/../includes/bootstrap.php';

try {
    $pdo = db();
    $campeonatos=$pdo->query("SELECT id,nome,tipo,formato,status,criado_em FROM campeonatos WHERE ativo=1 ORDER BY criado_em DESC,id DESC")->fetchAll();
    $requested=(int)($_GET['campeonato_id'] ?? 0);$campeonato=null;
    foreach($campeonatos as $item)if((int)$item['id']===$requested){$campeonato=$item;break;}
    if($campeonato===null)$campeonato=$campeonatos[0] ?? null;$campeonatoId=(int)($campeonato['id'] ?? 0);
    // Lista os participantes mostrados no site.
    $participantes = $pdo->query("SELECT id, nome, time_nome, sigla, escudo_url, descricao FROM participantes WHERE ativo=1 ORDER BY time_nome")->fetchAll();
    // Busca as partidas dos pontos corridos.
    $partidaStmt=$pdo->prepare("SELECT p.*,m.time_nome mandante,m.nome tecnico_mandante,v.time_nome visitante,v.nome tecnico_visitante FROM partidas p JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id WHERE p.campeonato_id=? AND p.ativo=1 ORDER BY p.rodada,p.data_partida");$partidaStmt->execute([$campeonatoId]);$partidas=$partidaStmt->fetchAll();
    // Busca os confrontos do chaveamento.
    $mataStmt=$pdo->prepare("SELECT j.*,a.time_nome time_a,a.nome tecnico_a,a.sigla sigla_a,a.escudo_url escudo_a,b.time_nome time_b,b.nome tecnico_b,b.sigla sigla_b,b.escudo_url escudo_b,w.time_nome vencedor FROM jogos_mata_mata j LEFT JOIN participantes a ON a.id=j.time_a_id LEFT JOIN participantes b ON b.id=j.time_b_id LEFT JOIN participantes w ON w.id=j.vencedor_id WHERE j.campeonato_id=? AND j.ativo=1 ORDER BY FIELD(j.fase,'Oitavas','Quartas','Semifinal','Terceiro lugar','Final'),j.ordem,j.jogo,j.id");$mataStmt->execute([$campeonatoId]);$mataMata=$mataStmt->fetchAll();
    // Busca o ranking de artilheiros.
    $artilhariaStmt=$pdo->prepare("SELECT a.id,a.campeonato_id,a.participante_id,a.jogador,a.gols,p.time_nome participante,p.nome tecnico FROM artilharia a JOIN participantes p ON p.id=a.participante_id WHERE a.campeonato_id=? ORDER BY a.gols DESC,a.jogador LIMIT 10");
    $artilhariaStmt->execute([$campeonatoId]);
    $artilharia=$artilhariaStmt->fetchAll();
    // Busca as conquistas dos técnicos.
    $titulos = $pdo->query("SELECT t.id,t.participante_id,t.titulo,t.temporada,t.descricao,t.conquistado_em,COALESCE(p.nome,t.tecnico_nome) tecnico,COALESCE(p.time_nome,t.time_nome) time_nome FROM titulos t LEFT JOIN participantes p ON p.id=t.participante_id ORDER BY FIELD(t.temporada,'Season 3','Season 2','Season 1'),t.conquistado_em DESC,t.titulo")->fetchAll();
    // Busca os vídeos publicados.
    $videos = $pdo->query("SELECT id, titulo, youtube_url FROM videos WHERE ativo=1 ORDER BY criado_em DESC")->fetchAll();
    $finalizadas = count(array_filter($partidas, fn($jogo) => in_array($jogo['status'], ['finalizada','wo'], true))) + count(array_filter($mataMata, fn($jogo) => $jogo['status'] === 'finalizado'));
    $classification=standings($pdo,$campeonatoId);$enrolled=[];foreach($partidas as $game){$enrolled[(int)$game['mandante_id']]=true;$enrolled[(int)$game['visitante_id']]=true;}foreach($mataMata as $game){if($game['time_a_id']!==null)$enrolled[(int)$game['time_a_id']]=true;if($game['time_b_id']!==null)$enrolled[(int)$game['time_b_id']]=true;}
    $temCampeonatoAtivo=count(array_filter($campeonatos,fn($item)=>$item['status']==='ativo'))>0;
    $statusTemporada=$temCampeonatoAtivo?'Em disputa':($campeonatos?'Aguardando próxima competição':'Novidades em breve');
    $resumo = ['status'=>$statusTemporada,'participantes'=>count($enrolled),'partidas_finalizadas'=>$finalizadas];
    // Entrega todos os dados em JSON para o script.js.
    json_response(['ok'=>true,'campeonatos'=>$campeonatos,'campeonato'=>$campeonato,'resumo'=>$resumo,'classificacao'=>$classification,'participantes'=>$participantes,'partidas'=>$partidas,'mata_mata'=>$mataMata,'artilharia'=>$artilharia,'titulos'=>$titulos,'videos'=>$videos]);
} catch (Throwable $error) {
    // Entrega todos os dados em JSON para o script.js.
    json_response(['ok'=>false, 'message'=>'Banco de dados indisponível. Confira config/config.php e importe database.sql.'], 500);
}
