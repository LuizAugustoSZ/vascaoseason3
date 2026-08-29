<?php

declare(strict_types=1);

// Mantém a relação entre um mata-mata e as quatro posições de uma liga.
function ensure_g4_knockout_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS mata_mata_g4 (
        campeonato_id INT UNSIGNED NOT NULL PRIMARY KEY,
        origem_campeonato_id INT UNSIGNED NOT NULL,
        sorteio_json JSON NOT NULL,
        congelado_em DATETIME NULL,
        criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_mata_g4_origem (origem_campeonato_id),
        CONSTRAINT fk_mata_g4_campeonato FOREIGN KEY (campeonato_id) REFERENCES campeonatos(id),
        CONSTRAINT fk_mata_g4_origem FOREIGN KEY (origem_campeonato_id) REFERENCES campeonatos(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Atualiza as vagas enquanto nenhuma partida do torneio tiver começado.
function sync_g4_knockout_slots(PDO $pdo, ?int $championshipId = null): void
{
    try {
        $sql = "SELECT g.* FROM mata_mata_g4 g JOIN campeonatos c ON c.id=g.campeonato_id WHERE c.ativo=1";
        $params = [];
        if ($championshipId !== null) {
            $sql .= " AND g.campeonato_id=?";
            $params[] = $championshipId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $links = $stmt->fetchAll();
    } catch (Throwable $ignored) {
        return; // Permite publicar antes da migration ser aplicada.
    }

    foreach ($links as $link) {
        if ($link['congelado_em'] !== null) continue;
        $started = $pdo->prepare("SELECT COUNT(*) FROM jogos_mata_mata WHERE campeonato_id=? AND ativo=1 AND (status IN ('finalizado','wo') OR gols_a IS NOT NULL OR gols_b IS NOT NULL)");
        $started->execute([(int)$link['campeonato_id']]);
        if ((int)$started->fetchColumn() > 0) {
            $pdo->prepare("UPDATE mata_mata_g4 SET congelado_em=NOW() WHERE campeonato_id=? AND congelado_em IS NULL")
                ->execute([(int)$link['campeonato_id']]);
            continue;
        }

        $ranking = standings($pdo, (int)$link['origem_campeonato_id']);
        if (count($ranking) < 4) continue;
        $positions = json_decode((string)$link['sorteio_json'], true);
        if (!is_array($positions) || count($positions) !== 4) continue;
        $teams = [];
        foreach ($positions as $position) {
            $index = (int)$position - 1;
            if (!isset($ranking[$index]['id'])) continue 2;
            $teams[] = (int)$ranking[$index]['id'];
        }

        $games = $pdo->prepare("SELECT id,jogo,ordem FROM jogos_mata_mata WHERE campeonato_id=? AND fase='Semifinal' AND ativo=1 ORDER BY ordem,jogo,id");
        $games->execute([(int)$link['campeonato_id']]);
        foreach ($games->fetchAll() as $game) {
            $offset = ((int)$game['ordem'] - 1) * 2;
            $a = $teams[$offset] ?? null;
            $b = $teams[$offset + 1] ?? null;
            if ((int)$game['jogo'] === 2) [$a, $b] = [$b, $a];
            $pdo->prepare("UPDATE jogos_mata_mata SET time_a_id=?,time_b_id=? WHERE id=?")
                ->execute([$a, $b, (int)$game['id']]);
        }
    }
}
