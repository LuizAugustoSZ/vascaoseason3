<?php

declare(strict_types=1);

const MERCADO_FORMACOES = [
    '3-3-4',
    '3-4-3',
    '3-5-2',
    '4-2-3-1',
    '4-2-4 Ofensivo',
    '4-2-4 Defensivo',
    '4-3-3',
    '4-3-3 Ofensivo',
    '4-3-3 Equilibrado',
    '4-3-3 Defensivo',
    '4-4-2',
    '4-4-2 Ofensivo',
    '4-4-2 Equilibrado',
    '4-4-2 Defensivo',
    '4-5-1 Ofensivo',
    '4-5-1 Defensivo',
    '5-3-2',
    '5-4-1',
];
const MERCADO_POSICOES = ['GOL', 'LD', 'LE', 'ZAG', 'VOL', 'MC', 'MEI', 'PD', 'PE', 'ATA'];

function mercado_normalizar_formacao(string $formacao, string $custom = ''): string
{
    if (in_array($formacao, MERCADO_FORMACOES, true)) return $formacao;
    if ($formacao !== '__custom__') throw new RuntimeException('Selecione uma formação válida.');

    $valor = trim($custom);
    if (preg_match('/^([1-9])([1-9])([1-9])$/', $valor, $partes)) {
        $valor = $partes[1] . '-' . $partes[2] . '-' . $partes[3];
    }
    if (!preg_match('/^([1-9])-([1-9])-([1-9])(?:\s+Custom)?$/i', $valor, $partes)) {
        throw new RuntimeException('A formação custom deve seguir o padrão 4-3-3 ou 433.');
    }
    if ((int)$partes[1] + (int)$partes[2] + (int)$partes[3] !== 10) {
        throw new RuntimeException('A formação custom precisa totalizar 10 jogadores de linha.');
    }
    return $partes[1] . '-' . $partes[2] . '-' . $partes[3] . ' Custom';
}

function mercado_parse_valor(string $valor): float
{
    $limpo = preg_replace('/\D/', '', trim($valor)) ?? '';
    if ($limpo === '') throw new RuntimeException('Informe um valor inteiro em reais.');
    return round((float)$limpo, 0);
}

function mercado_garantir_estrutura(PDO $pdo): void
{
    $migration = file_get_contents(__DIR__ . '/../sql/atualizacao-v8.9-mercado-transferencias.sql');
    if ($migration === false) throw new RuntimeException('Migration do mercado não encontrada.');
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $migration, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
        $pdo->exec(trim($statement));
    }
    $columns = $pdo->query("SHOW COLUMNS FROM clubes_campeonato")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('mural', $columns, true)) {
        $pdo->exec("ALTER TABLE clubes_campeonato ADD mural TEXT NULL AFTER elenco_confirmado");
    }
    if (!in_array('jogador_favorito_id', $columns, true)) {
        $pdo->exec("ALTER TABLE clubes_campeonato ADD jogador_favorito_id INT UNSIGNED NULL AFTER mural");
    }
}

function mercado_partidas_concluidas(PDO $pdo, int $campeonatoId, int $participanteId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM partidas WHERE campeonato_id=? AND ativo=1 AND status IN ('finalizada','wo') AND (mandante_id=? OR visitante_id=?)");
    $stmt->execute([$campeonatoId, $participanteId, $participanteId]);
    return (int)$stmt->fetchColumn();
}

function mercado_rodada_atual(PDO $pdo, int $campeonatoId, int $participanteId): int
{
    return mercado_partidas_concluidas($pdo, $campeonatoId, $participanteId) + 1;
}

function mercado_aberto_na_rodada(int $rodada): bool
{
    $posicaoNoCiclo = (($rodada - 1) % 8) + 1;
    return $posicaoNoCiclo >= 6;
}

function mercado_estado_ciclo(int $rodada): array
{
    $posicao = (($rodada - 1) % 8) + 1;
    $ciclo = intdiv($rodada - 1, 8) + 1;
    return ['ciclo' => $ciclo, 'posicao' => $posicao, 'aberto' => $posicao >= 6, 'restantes' => $posicao >= 6 ? 9 - $posicao : 6 - $posicao];
}

function mercado_estado_clube(PDO $pdo, int $campeonatoId, int $participanteId): array
{
    $concluidas = mercado_partidas_concluidas($pdo, $campeonatoId, $participanteId);
    return mercado_estado_ciclo($concluidas + 1) + [
        'partidas_concluidas' => $concluidas,
        'proxima_partida' => $concluidas + 1,
    ];
}

function mercado_clube(PDO $pdo, int $campeonatoId, int $participanteId, bool $lock = false): array
{
    $pdo->prepare("INSERT IGNORE INTO clubes_campeonato(campeonato_id,participante_id) VALUES(?,?)")->execute([$campeonatoId, $participanteId]);
    $stmt = $pdo->prepare("SELECT * FROM clubes_campeonato WHERE campeonato_id=? AND participante_id=?" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$campeonatoId, $participanteId]);
    return $stmt->fetch();
}

function mercado_pode_editar(array $clube, int $rodada): bool
{
    return !(bool)$clube['elenco_confirmado'] || mercado_aberto_na_rodada($rodada);
}
