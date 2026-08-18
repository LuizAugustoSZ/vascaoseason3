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
