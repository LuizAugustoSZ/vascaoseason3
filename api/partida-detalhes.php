<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

try {
    $type = ($_GET['tipo'] ?? '') === 'mata' ? 'mata' : 'pontos';
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) throw new RuntimeException('Partida inválida.');
    $pdo = db();
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
    json_response(['ok'=>true,'match'=>$match,'summary'=>$summary,'matches'=>$matches]);
} catch(Throwable $error) { json_response(['ok'=>false,'message'=>$error->getMessage()],404); }
