<?php

declare(strict_types=1);

const MERCADO_FORMACOES = ['4-3-3', '4-3-3O Custom', '4-4-2', '4-2-3-1', '3-5-2', '3-4-3', '5-3-2', '5-4-1'];
const MERCADO_POSICOES = ['GOL', 'LD', 'LE', 'ZAG', 'VOL', 'MC', 'MEI', 'PD', 'PE', 'ATA'];

function mercado_parse_valor(string $valor): float
{
    $limpo = preg_replace('/[^0-9,.-]/', '', trim($valor)) ?? '';
    if (str_contains($limpo, ',')) {
        $limpo = str_replace('.', '', $limpo);
        $limpo = str_replace(',', '.', $limpo);
    }
    if ($limpo === '' || !is_numeric($limpo)) throw new RuntimeException('Informe um valor válido em reais.');
    return round((float)$limpo, 2);
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
