<?php

declare(strict_types=1);

// Soma os jogos da chave e envia automaticamente classificado e eliminado às fases seguintes.
function advance_knockout(PDO $pdo, int $championshipId, string $phase, int $order): void
{
    $stmt = $pdo->prepare(
        "SELECT id,time_a_id,time_b_id,gols_a,gols_b,penaltis_a,penaltis_b,status FROM jogos_mata_mata WHERE campeonato_id=? AND fase=? AND ordem=? AND ativo=1 ORDER BY jogo,id",
    );
    $stmt->execute([$championshipId, $phase, $order]);
    $matches = $stmt->fetchAll();
    if (!$matches) return;

    $scores = [];
    $penalties = [];
    $allFinished = true;
    foreach ($matches as $match) {
        if ($match["time_a_id"] === null || $match["time_b_id"] === null) return;
        if ($match["status"] !== "finalizado" || $match["gols_a"] === null || $match["gols_b"] === null) {
            $allFinished = false;
            continue;
        }
        $a = (int)$match["time_a_id"];
        $b = (int)$match["time_b_id"];
        $scores[$a] = ($scores[$a] ?? 0) + (int)$match["gols_a"];
        $scores[$b] = ($scores[$b] ?? 0) + (int)$match["gols_b"];
        if ($match["penaltis_a"] !== null && $match["penaltis_b"] !== null) {
            $penalties[$a] = (int)$match["penaltis_a"];
            $penalties[$b] = (int)$match["penaltis_b"];
        }
    }
    if (count($penalties) < 2 && !$allFinished) return;

    arsort($scores);
    $ids = array_keys($scores);
    $winner = null;
    if (count($penalties) >= 2) {
        $penaltyIds = array_keys($penalties);
        $winner = $penalties[$penaltyIds[0]] > $penalties[$penaltyIds[1]] ? (int)$penaltyIds[0] : (int)$penaltyIds[1];
    } elseif (count($ids) >= 2 && $scores[$ids[0]] > $scores[$ids[1]]) {
        $winner = (int)$ids[0];
    }
    if ($winner === null) {
        $pdo->prepare("UPDATE jogos_mata_mata SET vencedor_id=NULL WHERE campeonato_id=? AND fase=? AND ordem=? AND ativo=1")
            ->execute([$championshipId, $phase, $order]);
        return;
    }

    $loser = null;
    foreach ($scores as $teamId => $score) {
        if ((int)$teamId !== $winner) {
            $loser = (int)$teamId;
            break;
        }
    }
    $pdo->prepare("UPDATE jogos_mata_mata SET vencedor_id=? WHERE campeonato_id=? AND fase=? AND ordem=? AND ativo=1")
        ->execute([$winner, $championshipId, $phase, $order]);
    $pdo->prepare("UPDATE jogos_mata_mata SET time_a_id=IF(origem_a_tipo='perdedor',?,?) WHERE campeonato_id=? AND origem_a_fase=? AND origem_a_ordem=? AND ativo=1")
        ->execute([$loser, $winner, $championshipId, $phase, $order]);
    $pdo->prepare("UPDATE jogos_mata_mata SET time_b_id=IF(origem_b_tipo='perdedor',?,?) WHERE campeonato_id=? AND origem_b_fase=? AND origem_b_ordem=? AND ativo=1")
        ->execute([$loser, $winner, $championshipId, $phase, $order]);
}

function knockout_normalized_name(string $value): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($value), 'UTF-8'));
    return preg_replace('/[^a-z0-9]+/', '', $ascii !== false ? $ascii : $value) ?? '';
}

// Corrige súmulas de ida e volta que tenham sido vinculadas à perna de mandos invertidos.
function reconcile_knockout_summaries(PDO $pdo): void
{
    try {
        $rows = $pdo->query("SELECT s.id sumula_id,s.jogo_mata_mata_id,s.dados_json,j.campeonato_id,j.fase,j.ordem
            FROM sumulas_dreamteam s JOIN jogos_mata_mata j ON j.id=s.jogo_mata_mata_id
            WHERE s.origem='mata' AND j.ativo=1")->fetchAll();
    } catch (Throwable $ignored) {
        return;
    }
    $groups = [];
    foreach ($rows as $row) $groups[$row['campeonato_id'].'|'.$row['fase'].'|'.$row['ordem']][] = $row;

    foreach ($groups as $summaries) {
        if (count($summaries) < 2) continue;
        $first = $summaries[0];
        $gamesStmt = $pdo->prepare("SELECT j.id,j.time_a_id,j.time_b_id,a.time_nome time_a,b.time_nome time_b
            FROM jogos_mata_mata j JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id
            WHERE j.campeonato_id=? AND j.fase=? AND j.ordem=? AND j.ativo=1");
        $gamesStmt->execute([$first['campeonato_id'], $first['fase'], $first['ordem']]);
        $games = $gamesStmt->fetchAll();
        $assignments = [];
        foreach ($summaries as $summary) {
            $data = json_decode((string)$summary['dados_json'], true);
            if (!$data || empty($data['home_name']) || empty($data['away_name'])) continue 2;
            $target = null;
            foreach ($games as $game) {
                if (knockout_normalized_name((string)$game['time_a']) === knockout_normalized_name((string)$data['home_name'])
                    && knockout_normalized_name((string)$game['time_b']) === knockout_normalized_name((string)$data['away_name'])) {
                    $target = $game;
                    break;
                }
            }
            if (!$target) continue 2;
            $assignments[] = ['summary' => $summary, 'data' => $data, 'game' => $target];
        }
        $needsRepair = array_filter($assignments, static fn(array $item): bool => (int)$item['summary']['jogo_mata_mata_id'] !== (int)$item['game']['id']);
        if (!$needsRepair || count(array_unique(array_map(static fn(array $item): int => (int)$item['game']['id'], $assignments))) !== count($assignments)) continue;

        $pdo->beginTransaction();
        try {
            $ids = array_map(static fn(array $item): int => (int)$item['summary']['sumula_id'], $assignments);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE sumulas_dreamteam SET jogo_mata_mata_id=NULL WHERE id IN ($ph)")->execute($ids);
            foreach ($assignments as $item) {
                $game = $item['game'];
                $data = $item['data'];
                $gameId = (int)$game['id'];
                $pdo->prepare("DELETE FROM gols_mata_mata WHERE jogo_mata_mata_id=?")->execute([$gameId]);
                $codes = array_column((array)($data['teams'] ?? []), 'code');
                $insertGoal = $pdo->prepare("INSERT INTO gols_mata_mata(jogo_mata_mata_id,participante_id,jogador,minuto,tipo) VALUES(?,?,?,?,?)");
                foreach ((array)($data['goals'] ?? []) as $goal) {
                    $teamId = ($goal['team_code'] ?? '') === ($codes[0] ?? '') ? (int)$game['time_a_id'] : (int)$game['time_b_id'];
                    $type = in_array(($goal['goal_type'] ?? 'normal'), ['normal','penalti','falta','olimpico','contra'], true) ? $goal['goal_type'] : 'normal';
                    $insertGoal->execute([$gameId, $teamId, $goal['player'], (string)$goal['minute'], $type]);
                }
                $goalsA = (int)$data['home_goals'];
                $goalsB = (int)$data['away_goals'];
                $winner = $goalsA === $goalsB ? null : ($goalsA > $goalsB ? (int)$game['time_a_id'] : (int)$game['time_b_id']);
                $pdo->prepare("UPDATE jogos_mata_mata SET gols_a=?,gols_b=?,vencedor_id=?,status='finalizado' WHERE id=?")
                    ->execute([$goalsA, $goalsB, $winner, $gameId]);
                $pdo->prepare("UPDATE sumulas_dreamteam SET jogo_mata_mata_id=? WHERE id=?")
                    ->execute([$gameId, $item['summary']['sumula_id']]);
            }
            $pdo->commit();
            advance_knockout($pdo, (int)$first['campeonato_id'], (string)$first['fase'], (int)$first['ordem']);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
}
