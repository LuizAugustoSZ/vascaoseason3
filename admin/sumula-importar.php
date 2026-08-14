<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/dreamteam-parser.php';
require __DIR__ . '/../includes/knockout.php';
admin_required();

function ensure_summary_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS sumulas_dreamteam (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dreamteam_id VARCHAR(40) NOT NULL,
        origem VARCHAR(20) NOT NULL,
        partida_id INT UNSIGNED NULL,
        jogo_mata_mata_id INT UNSIGNED NULL,
        estadio VARCHAR(190) NULL,
        clima VARCHAR(120) NULL,
        duracao SMALLINT UNSIGNED NULL,
        craque VARCHAR(190) NULL,
        craque_nota DECIMAL(4,2) NULL,
        dados_json LONGTEXT NOT NULL,
        texto_original MEDIUMTEXT NOT NULL,
        criado_por INT UNSIGNED NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_sumula_dreamteam_id (dreamteam_id),
        UNIQUE KEY uk_sumula_partida (origem, partida_id),
        UNIQUE KEY uk_sumula_mata (origem, jogo_mata_mata_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function normalized_team_name(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    return preg_replace('/[^a-z0-9]+/', '', $ascii !== false ? $ascii : $value) ?? '';
}

function identify_summary_context(PDO $pdo, array $parsed): array
{
    $participants = $pdo->query("SELECT id,time_nome,sigla FROM participantes WHERE ativo=1")->fetchAll();
    $byName = [];
    foreach ($participants as $participant) {
        $byName[normalized_team_name($participant['time_nome'])] = $participant;
    }
    $home = $byName[normalized_team_name($parsed['home_name'])] ?? null;
    $away = $byName[normalized_team_name($parsed['away_name'])] ?? null;
    if (!$home || !$away) {
        $missing = [];
        if (!$home) $missing[] = $parsed['home_name'];
        if (!$away) $missing[] = $parsed['away_name'];
        throw new RuntimeException('Time não encontrado no cadastro: ' . implode(', ', $missing) . '.');
    }
    $homeId = (int) $home['id'];
    $awayId = (int) $away['id'];
    $candidates = [];
    $stmt = $pdo->prepare("SELECT p.id,p.campeonato_id,p.rodada,p.mandante_id,p.visitante_id,p.status,c.nome campeonato,m.time_nome mandante,v.time_nome visitante FROM partidas p JOIN campeonatos c ON c.id=p.campeonato_id JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id WHERE p.ativo=1 AND ((p.mandante_id=? AND p.visitante_id=?) OR (p.mandante_id=? AND p.visitante_id=?)) ORDER BY FIELD(p.status,'agendada','finalizada','wo'),p.id DESC");
    $stmt->execute([$homeId, $awayId, $awayId, $homeId]);
    foreach ($stmt->fetchAll() as $match) {
        $candidates[] = [
            'key' => 'pontos:' . $match['id'],
            'type' => 'pontos',
            'id' => (int) $match['id'],
            'label' => $match['campeonato'] . ' • Rodada ' . $match['rodada'] . ' • ' . $match['mandante'] . ' × ' . $match['visitante'],
            'status' => $match['status'],
            'reversed' => (int) $match['mandante_id'] !== $homeId,
        ];
    }
    $stmt = $pdo->prepare("SELECT j.id,j.campeonato_id,j.fase,j.ordem,j.jogo,j.time_a_id,j.time_b_id,j.status,c.nome campeonato,a.time_nome time_a,b.time_nome time_b FROM jogos_mata_mata j JOIN campeonatos c ON c.id=j.campeonato_id JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id WHERE j.ativo=1 AND ((j.time_a_id=? AND j.time_b_id=?) OR (j.time_a_id=? AND j.time_b_id=?)) ORDER BY FIELD(j.status,'agendado','finalizado'),j.id DESC");
    $stmt->execute([$homeId, $awayId, $awayId, $homeId]);
    foreach ($stmt->fetchAll() as $match) {
        $candidates[] = [
            'key' => 'mata:' . $match['id'],
            'type' => 'mata',
            'id' => (int) $match['id'],
            'label' => $match['campeonato'] . ' • ' . $match['fase'] . ' ' . $match['ordem'] . ' • Jogo ' . $match['jogo'] . ' • ' . $match['time_a'] . ' × ' . $match['time_b'],
            'status' => $match['status'],
            'reversed' => (int) $match['time_a_id'] !== $homeId,
        ];
    }
    return ['home' => $home, 'away' => $away, 'candidates' => $candidates];
}

function replace_imported_goals(PDO $pdo, array $parsed, array $context, string $type, int $matchId, bool $reversed): void
{
    $homeId = (int) $context['home']['id'];
    $awayId = (int) $context['away']['id'];
    $codes = array_column($parsed['teams'], 'code');
    if (count($codes) !== 2) throw new RuntimeException('Não foi possível vincular as siglas dos dois times.');
    $goals = [];
    foreach ($parsed['goals'] as $goal) {
        $teamId = $goal['team_code'] === $codes[0] ? $homeId : ($goal['team_code'] === $codes[1] ? $awayId : 0);
        if (!$teamId) throw new RuntimeException('Um gol possui uma sigla de time desconhecida: ' . $goal['team_code']);
        $goals[] = ['team_id' => $teamId, 'player' => $goal['player'], 'minute' => (string) $goal['minute'], 'type' => in_array($goal['goal_type'], ['normal','penalti','falta','olimpico','contra'], true) ? $goal['goal_type'] : 'normal'];
    }
    $counts = [$homeId => 0, $awayId => 0];
    foreach ($goals as $goal) $counts[$goal['team_id']]++;
    if ($counts[$homeId] !== $parsed['home_goals'] || $counts[$awayId] !== $parsed['away_goals']) throw new RuntimeException('Os gols válidos identificados não correspondem ao placar. Revise a prévia.');

    if ($type === 'pontos') {
        $match = $pdo->prepare("SELECT campeonato_id,mandante_id,visitante_id FROM partidas WHERE id=? AND ativo=1 FOR UPDATE");
        $match->execute([$matchId]);
        $row = $match->fetch();
        if (!$row) throw new RuntimeException('Partida não encontrada.');
        $old = $pdo->prepare("SELECT participante_id,jogador,tipo FROM gols_partida WHERE partida_id=?");
        $old->execute([$matchId]);
        $oldGoals = $old->fetchAll();
        $pdo->prepare("DELETE FROM gols_partida WHERE partida_id=?")->execute([$matchId]);
        $insert = $pdo->prepare("INSERT INTO gols_partida(partida_id,participante_id,jogador,minuto,tipo) VALUES(?,?,?,?,?)");
        foreach ($goals as $goal) $insert->execute([$matchId,$goal['team_id'],$goal['player'],$goal['minute'],$goal['type']]);
        $goalsA = $reversed ? $parsed['away_goals'] : $parsed['home_goals'];
        $goalsB = $reversed ? $parsed['home_goals'] : $parsed['away_goals'];
        $pdo->prepare("UPDATE partidas SET gols_mandante=?,gols_visitante=?,status='finalizada' WHERE id=?")->execute([$goalsA,$goalsB,$matchId]);
        $championshipId = (int) $row['campeonato_id'];
    } else {
        $match = $pdo->prepare("SELECT campeonato_id,time_a_id,time_b_id FROM jogos_mata_mata WHERE id=? AND ativo=1 FOR UPDATE");
        $match->execute([$matchId]);
        $row = $match->fetch();
        if (!$row) throw new RuntimeException('Jogo de mata-mata não encontrado.');
        $old = $pdo->prepare("SELECT participante_id,jogador,tipo FROM gols_mata_mata WHERE jogo_mata_mata_id=?");
        $old->execute([$matchId]);
        $oldGoals = $old->fetchAll();
        $pdo->prepare("DELETE FROM gols_mata_mata WHERE jogo_mata_mata_id=?")->execute([$matchId]);
        $insert = $pdo->prepare("INSERT INTO gols_mata_mata(jogo_mata_mata_id,participante_id,jogador,minuto,tipo) VALUES(?,?,?,?,?)");
        foreach ($goals as $goal) $insert->execute([$matchId,$goal['team_id'],$goal['player'],$goal['minute'],$goal['type']]);
        $goalsA = $reversed ? $parsed['away_goals'] : $parsed['home_goals'];
        $goalsB = $reversed ? $parsed['home_goals'] : $parsed['away_goals'];
        $winner = $goalsA === $goalsB ? null : ($goalsA > $goalsB ? (int) $row['time_a_id'] : (int) $row['time_b_id']);
        $pdo->prepare("UPDATE jogos_mata_mata SET gols_a=?,gols_b=?,vencedor_id=?,status='finalizado' WHERE id=?")->execute([$goalsA,$goalsB,$winner,$matchId]);
        $tie = $pdo->prepare("SELECT fase,ordem FROM jogos_mata_mata WHERE id=?");
        $tie->execute([$matchId]);
        $tieData = $tie->fetch();
        advance_knockout($pdo, (int)$row['campeonato_id'], (string)$tieData['fase'], (int)$tieData['ordem']);
        $championshipId = (int) $row['campeonato_id'];
    }
    $deltas = [];
    foreach ($oldGoals as $goal) if ($goal['tipo'] !== 'contra') {
        $key=$goal['participante_id'].'|'.mb_strtolower($goal['jogador']);
        $deltas[$key]=['team'=>(int)$goal['participante_id'],'player'=>$goal['jogador'],'delta'=>($deltas[$key]['delta']??0)-1];
    }
    foreach ($goals as $goal) if ($goal['type'] !== 'contra') {
        $key=$goal['team_id'].'|'.mb_strtolower($goal['player']);
        $deltas[$key]=['team'=>$goal['team_id'],'player'=>$goal['player'],'delta'=>($deltas[$key]['delta']??0)+1];
    }
    foreach ($deltas as $delta) {
        if (!$delta['delta']) continue;
        if ($delta['delta'] > 0) $pdo->prepare("INSERT INTO artilharia(campeonato_id,jogador,participante_id,gols) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE gols=gols+VALUES(gols)")->execute([$championshipId,$delta['player'],$delta['team'],$delta['delta']]);
        else $pdo->prepare("UPDATE artilharia SET gols=GREATEST(0,gols+?) WHERE campeonato_id=? AND jogador=? AND participante_id=?")->execute([$delta['delta'],$championshipId,$delta['player'],$delta['team']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método inválido.'], 405);
verify_csrf();
try {
    $pdo = db();
    ensure_summary_table($pdo);
    $raw = (string) ($_POST['sumula'] ?? '');
    $parsed = dreamteam_parse_summary($raw);
    if (!$parsed['dreamteam_id']) throw new RuntimeException('O ID único DT-... não foi encontrado.');
    $context = identify_summary_context($pdo, $parsed);
    $duplicate = $pdo->prepare("SELECT id FROM sumulas_dreamteam WHERE dreamteam_id=? LIMIT 1");
    $duplicate->execute([$parsed['dreamteam_id']]);
    if ($duplicate->fetchColumn()) throw new RuntimeException('Esta súmula já foi importada anteriormente.');
    if (($_POST['action'] ?? 'analyze') === 'analyze') {
        json_response(['ok'=>true,'parsed'=>$parsed,'candidates'=>$context['candidates'],'teams'=>['home'=>$context['home'],'away'=>$context['away']]]);
    }
    if ($parsed['warnings']) throw new RuntimeException('A súmula possui alertas que precisam ser corrigidos antes da importação: ' . implode(' ', $parsed['warnings']));
    [$type,$idText] = array_pad(explode(':', (string) ($_POST['match_key'] ?? ''), 2), 2, '');
    $matchId = (int) $idText;
    $candidate = null;
    foreach ($context['candidates'] as $item) if ($item['type'] === $type && $item['id'] === $matchId) $candidate = $item;
    if (!$candidate) throw new RuntimeException('Selecione uma partida compatível.');
    $pdo->beginTransaction();
    replace_imported_goals($pdo,$parsed,$context,$type,$matchId,(bool)$candidate['reversed']);
    $insert = $pdo->prepare("INSERT INTO sumulas_dreamteam(dreamteam_id,origem,partida_id,jogo_mata_mata_id,estadio,clima,duracao,craque,craque_nota,dados_json,texto_original,criado_por) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
    $insert->execute([$parsed['dreamteam_id'],$type,$type==='pontos'?$matchId:null,$type==='mata'?$matchId:null,$parsed['stadium'],$parsed['weather'],$parsed['duration'],$parsed['man_of_match'],$parsed['man_of_match_rating'],json_encode($parsed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$raw,(int)($_SESSION['conta_id']??0)]);
    $pdo->commit();
    json_response(['ok'=>true,'message'=>'Súmula importada, resultado atualizado e eventos armazenados com sucesso.']);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok'=>false,'message'=>$error->getMessage()],422);
}
