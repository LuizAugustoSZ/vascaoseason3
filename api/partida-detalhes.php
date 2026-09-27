<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

try {
    $type = ($_GET['tipo'] ?? '') === 'mata' ? 'mata' : 'pontos';
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) throw new RuntimeException('Partida inválida.');
    $pdo = db();
    competition_identities_seed($pdo);
    competition_schedule_ensure_schema($pdo);
    if ($type === 'pontos') {
        $stmt=$pdo->prepare("SELECT p.id,p.rodada etapa,p.data_partida,p.status,p.gols_mandante gols_a,p.gols_visitante gols_b,m.id time_a_id,m.time_nome time_a,m.sigla sigla_a,m.escudo_url escudo_a,v.id time_b_id,v.time_nome time_b,v.sigla sigla_b,v.escudo_url escudo_b,c.nome campeonato FROM partidas p JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id JOIN campeonatos c ON c.id=p.campeonato_id WHERE p.id=? AND p.ativo=1");
    } else {
        $stmt=$pdo->prepare("SELECT j.id,j.campeonato_id,j.fase,j.ordem,j.jogo,CONCAT(j.fase,' ',j.ordem) etapa,s.criado_em data_partida,j.status,j.gols_a,j.gols_b,a.id time_a_id,a.time_nome time_a,a.sigla sigla_a,a.escudo_url escudo_a,b.id time_b_id,b.time_nome time_b,b.sigla sigla_b,b.escudo_url escudo_b,c.nome campeonato,j.penaltis_a,j.penaltis_b FROM jogos_mata_mata j JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id JOIN campeonatos c ON c.id=j.campeonato_id LEFT JOIN sumulas_dreamteam s ON s.origem='mata' AND s.jogo_mata_mata_id=j.id WHERE j.id=? AND j.ativo=1");
    }
    $stmt->execute([$id]);$match=$stmt->fetch();if(!$match)throw new RuntimeException('Partida não encontrada.');
    $summary=null;
    try {
        $column=$type==='pontos'?'partida_id':'jogo_mata_mata_id';
        $summaryStmt=$pdo->prepare("SELECT dreamteam_id,estadio,clima,duracao,craque,craque_nota,dados_json FROM sumulas_dreamteam WHERE origem=? AND `$column`=? LIMIT 1");
        $summaryStmt->execute([$type,$id]);$row=$summaryStmt->fetch();
        if($row){$summary=json_decode($row['dados_json'],true);$summary['dreamteam_id']=$row['dreamteam_id'];}
    } catch(Throwable $ignored) {}
    $matches = null;
    if ($type === 'mata') {
        $legs = $pdo->prepare("SELECT j.id,j.jogo,CONCAT(j.fase,' ',j.ordem) etapa,s.criado_em data_partida,j.status,j.gols_a,j.gols_b,a.id time_a_id,a.time_nome time_a,a.sigla sigla_a,a.escudo_url escudo_a,b.id time_b_id,b.time_nome time_b,b.sigla sigla_b,b.escudo_url escudo_b,c.nome campeonato,j.penaltis_a,j.penaltis_b
            FROM jogos_mata_mata j JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id JOIN campeonatos c ON c.id=j.campeonato_id LEFT JOIN sumulas_dreamteam s ON s.origem='mata' AND s.jogo_mata_mata_id=j.id
            WHERE j.campeonato_id=? AND j.fase=? AND j.ordem=? AND j.ativo=1 ORDER BY j.jogo,j.id");
        $legs->execute([$match['campeonato_id'], $match['fase'], $match['ordem']]);
        $matches = [];
        foreach ($legs->fetchAll() as $leg) {
            $legSummary = null;
            try {
                $summaryStmt = $pdo->prepare("SELECT dados_json,dreamteam_id FROM sumulas_dreamteam WHERE origem='mata' AND jogo_mata_mata_id=? LIMIT 1");
                $summaryStmt->execute([$leg['id']]);
                $summaryRow = $summaryStmt->fetch();
                if ($summaryRow) {
                    $legSummary = json_decode($summaryRow['dados_json'], true);
                    $legSummary['dreamteam_id'] = $summaryRow['dreamteam_id'];
                }
            } catch (Throwable $ignored) {}
            $matches[] = ['match' => $leg, 'summary' => $legSummary];
        }
    }
    $historyStmt = $pdo->prepare("SELECT time_a_id,time_b_id,gols_a,gols_b FROM (
        SELECT mandante_id time_a_id,visitante_id time_b_id,gols_mandante gols_a,gols_visitante gols_b FROM partidas WHERE ativo=1 AND status IN('finalizada','finalizado','wo','penalidade')
        UNION ALL
        SELECT time_a_id,time_b_id,gols_a,gols_b FROM jogos_mata_mata WHERE ativo=1 AND status IN('finalizada','finalizado','wo','penalidade')
    ) historico WHERE (time_a_id=? AND time_b_id=?) OR (time_a_id=? AND time_b_id=?)");
    $teamAId=(int)$match['time_a_id'];$teamBId=(int)$match['time_b_id'];
    $historyStmt->execute([$teamAId,$teamBId,$teamBId,$teamAId]);
    $headToHead=['games'=>0,'team_a_wins'=>0,'draws'=>0,'team_b_wins'=>0,'team_a_goals'=>0,'team_b_goals'=>0];
    foreach($historyStmt->fetchAll() as $history){
        $sameSide=(int)$history['time_a_id']===$teamAId;
        $goalsA=(int)($sameSide?$history['gols_a']:$history['gols_b']);
        $goalsB=(int)($sameSide?$history['gols_b']:$history['gols_a']);
        $headToHead['games']++;$headToHead['team_a_goals']+=$goalsA;$headToHead['team_b_goals']+=$goalsB;
        if($goalsA>$goalsB)$headToHead['team_a_wins']++;elseif($goalsB>$goalsA)$headToHead['team_b_wins']++;else$headToHead['draws']++;
    }
    json_response(['ok'=>true,'match'=>$match,'summary'=>$summary,'matches'=>$matches,'head_to_head'=>$headToHead]);
} catch(Throwable $error) { json_response(['ok'=>false,'message'=>$error->getMessage()],404); }
