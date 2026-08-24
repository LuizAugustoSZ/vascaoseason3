<?php

declare(strict_types=1);

function elenco_geral_garantir_estrutura(PDO $pdo): void
{
    $migration = file_get_contents(__DIR__ . '/../sql/atualizacao-v16.7-elenco-geral.sql');
    if ($migration === false) throw new RuntimeException('Migration do Elenco Geral não encontrada.');
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $migration, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
        $pdo->exec(trim($statement));
    }
    $columns = $pdo->query("SHOW COLUMNS FROM jogadores_elenco")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('jogador_geral_id', $columns, true)) {
        $pdo->exec("ALTER TABLE jogadores_elenco ADD jogador_geral_id INT UNSIGNED NULL AFTER participante_id, ADD KEY idx_elenco_jogador_geral (jogador_geral_id), ADD CONSTRAINT fk_elenco_jogador_geral FOREIGN KEY (jogador_geral_id) REFERENCES jogadores_gerais(id)");
    }
    $pdo->exec("UPDATE jogadores_elenco e JOIN jogadores_gerais g ON g.participante_id=e.participante_id AND g.nome=e.nome AND g.overall=e.overall AND g.posicao=e.posicao SET e.jogador_geral_id=g.id WHERE e.jogador_geral_id IS NULL");
    elenco_geral_inscrever_corte_brasileirao_ii($pdo);
}

function elenco_geral_inscrever_corte_brasileirao_ii(PDO $pdo): int
{
    static $executed = false;
    if ($executed) return 0;
    $executed = true;

    $championships = $pdo->query("SELECT id,nome FROM campeonatos WHERE ativo=1 AND status='ativo' AND tipo='pontos_corridos'")->fetchAll();
    $targetIds = [];
    foreach ($championships as $championship) {
        if (str_replace(' ', '', competition_identity_key((string)$championship['nome'])) === 'brasileiraoii') $targetIds[] = (int)$championship['id'];
    }
    if (!$targetIds) return 0;

    $participants = $pdo->prepare("SELECT participante_id FROM (SELECT mandante_id participante_id FROM partidas WHERE campeonato_id=? AND ativo=1 UNION SELECT visitante_id FROM partidas WHERE campeonato_id=? AND ativo=1) clubes");
    $insert = $pdo->prepare("INSERT INTO jogadores_elenco(campeonato_id,participante_id,jogador_geral_id,nome,overall,posicao,grupo,ordem)
        SELECT ?,g.participante_id,g.id,g.nome,g.overall,g.posicao,'banco',99
        FROM jogadores_gerais g
        WHERE g.participante_id=? AND g.ativo=1 AND g.entrou_em<'2026-08-18 00:00:00'
          AND NOT EXISTS(
              SELECT 1 FROM jogadores_elenco e
              WHERE e.campeonato_id=? AND e.participante_id=g.participante_id
                AND (e.jogador_geral_id=g.id OR (e.nome=g.nome AND e.overall=g.overall AND e.posicao=g.posicao))
          )");
    $inserted = 0;
    foreach ($targetIds as $championshipId) {
        $participants->execute([$championshipId, $championshipId]);
        foreach ($participants->fetchAll(PDO::FETCH_COLUMN) as $participantId) {
            $insert->execute([$championshipId,(int)$participantId,$championshipId]);
            $inserted += $insert->rowCount();
        }
    }
    return $inserted;
}

function elenco_geral_clube(PDO $pdo, int $participanteId, bool $lock = false): array
{
    $pdo->prepare("INSERT IGNORE INTO clubes_gerais(participante_id,saldo,cofre_configurado) SELECT ?,saldo,cofre_configurado FROM clubes_campeonato WHERE participante_id=? ORDER BY cofre_configurado DESC,atualizado_em DESC,id DESC LIMIT 1")->execute([$participanteId, $participanteId]);
    $pdo->prepare("INSERT IGNORE INTO clubes_gerais(participante_id) VALUES(?)")->execute([$participanteId]);
    $stmt = $pdo->prepare("SELECT * FROM clubes_gerais WHERE participante_id=?" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$participanteId]);
    return $stmt->fetch();
}

function elenco_geral_do_clube(PDO $pdo, int $participanteId): array
{
    $stmt = $pdo->prepare("SELECT id,nome,overall,posicao,entrou_em FROM jogadores_gerais WHERE participante_id=? AND ativo=1 ORDER BY FIELD(posicao,'GOL','LD','LE','ZAG','VOL','MC','MEI','PD','PE','ATA'),overall DESC,nome");
    $stmt->execute([$participanteId]);
    return $stmt->fetchAll();
}
