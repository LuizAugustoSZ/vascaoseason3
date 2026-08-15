<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

function same_player(string $left, string $right): bool
{
    $normalize = static function (string $value): string {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return preg_replace('/[^a-z0-9]+/', '', $ascii !== false ? $ascii : $value) ?? '';
    };
    return $normalize($left) !== '' && $normalize($left) === $normalize($right);
}

try {
    $participantId = (int)($_GET['participante_id'] ?? 0);
    $player = trim((string)($_GET['jogador'] ?? ''));
    if ($participantId < 1 || $player === '') throw new RuntimeException('Jogador inválido.');
    $pdo = db();

    $teamStmt = $pdo->prepare('SELECT time_nome FROM participantes WHERE id=? AND ativo=1');
    $teamStmt->execute([$participantId]);
    $teamName = (string)($teamStmt->fetchColumn() ?: '');
    if ($teamName === '') throw new RuntimeException('Clube não encontrado.');

    $goalsStmt = $pdo->prepare('SELECT COALESCE(SUM(gols),0) FROM artilharia WHERE participante_id=? AND LOWER(jogador)=LOWER(?)');
    $goalsStmt->execute([$participantId, $player]);
    $historicalGoals = (int)$goalsStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT s.dados_json,'pontos' origem,p.id,p.data_partida,c.nome campeonato,CONCAT('Rodada ',p.rodada) etapa,m.time_nome time_a,v.time_nome time_b,p.gols_mandante gols_a,p.gols_visitante gols_b FROM sumulas_dreamteam s JOIN partidas p ON s.origem='pontos' AND p.id=s.partida_id JOIN campeonatos c ON c.id=p.campeonato_id JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id WHERE p.mandante_id=? OR p.visitante_id=? UNION ALL SELECT s.dados_json,'mata',j.id,NULL,c.nome,CONCAT(j.fase,' ',j.ordem),a.time_nome,b.time_nome,j.gols_a,j.gols_b FROM sumulas_dreamteam s JOIN jogos_mata_mata j ON s.origem='mata' AND j.id=s.jogo_mata_mata_id JOIN campeonatos c ON c.id=j.campeonato_id JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id WHERE j.time_a_id=? OR j.time_b_id=?");
    $stmt->execute([$participantId, $participantId, $participantId, $participantId]);
    $games = [];
    $totals = ['goals_in_summaries' => 0, 'assists' => 0, 'yellow_cards' => 0, 'red_cards' => 0, 'var' => 0, 'man_of_match' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $summary = json_decode((string)$row['dados_json'], true);
        if (!is_array($summary)) continue;
        $events = [];
        foreach (($summary['events'] ?? []) as $event) {
            $type = (string)($event['type'] ?? '');
            $isPlayer = same_player((string)($event['player'] ?? ''), $player);
            $isAssist = $type === 'goal' && empty($event['cancelled']) && same_player((string)($event['assist'] ?? ''), $player);
            $isSub = same_player((string)($event['player_in'] ?? ''), $player) || same_player((string)($event['player_out'] ?? ''), $player);
            if (!$isPlayer && !$isAssist && !$isSub) continue;
            $events[] = $event;
            if ($type === 'goal' && $isPlayer && empty($event['cancelled'])) $totals['goals_in_summaries']++;
            if ($isAssist) $totals['assists']++;
            if ($type === 'yellow_card' && $isPlayer) $totals['yellow_cards']++;
            if ($type === 'red_card' && $isPlayer) $totals['red_cards']++;
            if (str_starts_with($type, 'var_') && $isPlayer) $totals['var']++;
        }
        $motm = same_player((string)($summary['man_of_match'] ?? ''), $player);
        if ($motm) $totals['man_of_match']++;
        if (!$events && !$motm) continue;
        $games[] = [
            'id' => (int)$row['id'], 'origem' => $row['origem'], 'campeonato' => $row['campeonato'],
            'etapa' => $row['etapa'], 'data_partida' => $row['data_partida'], 'time_a' => $row['time_a'],
            'time_b' => $row['time_b'], 'gols_a' => $row['gols_a'], 'gols_b' => $row['gols_b'],
            'events' => $events, 'man_of_match' => $motm, 'rating' => $motm ? ($summary['man_of_match_rating'] ?? null) : null,
        ];
    }
    usort($games, static fn($a, $b) => strcmp((string)($b['data_partida'] ?? ''), (string)($a['data_partida'] ?? '')) ?: $b['id'] <=> $a['id']);
    json_response(['ok' => true, 'player' => $player, 'team' => $teamName, 'historical_goals' => $historicalGoals, 'totals' => $totals, 'games' => $games]);
} catch (Throwable $error) {
    json_response(['ok' => false, 'message' => $error->getMessage()], 422);
}
