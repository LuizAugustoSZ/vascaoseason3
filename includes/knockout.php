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
