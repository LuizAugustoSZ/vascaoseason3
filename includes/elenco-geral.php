<?php

declare(strict_types=1);

function elenco_geral_garantir_estrutura(PDO $pdo): void
{
    $migration = file_get_contents(__DIR__ . '/../sql/atualizacao-v16.7-elenco-geral.sql');
    if ($migration === false) throw new RuntimeException('Migration do Elenco Geral não encontrada.');
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $migration, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
        $pdo->exec(trim($statement));
    }
}

function elenco_geral_do_clube(PDO $pdo, int $participanteId): array
{
    $stmt = $pdo->prepare("SELECT id,nome,overall,posicao,entrou_em FROM jogadores_gerais WHERE participante_id=? AND ativo=1 ORDER BY FIELD(posicao,'GOL','LD','LE','ZAG','VOL','MC','MEI','PD','PE','ATA'),overall DESC,nome");
    $stmt->execute([$participanteId]);
    return $stmt->fetchAll();
}
