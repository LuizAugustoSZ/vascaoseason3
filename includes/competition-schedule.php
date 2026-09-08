<?php

declare(strict_types=1);

/**
 * Instala a data inicial das competições e agenda as edições que já
 * estavam ativas quando o recurso foi publicado.
 */
function competition_schedule_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;

    $column = $pdo->query("SHOW COLUMNS FROM campeonatos LIKE 'data_inicio'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE campeonatos ADD COLUMN data_inicio DATE NULL AFTER formato, ADD KEY idx_campeonatos_data_inicio (data_inicio)");
    }

    $defaults = [
        'brasileirao' => '2026-09-08',
        'champions league' => '2026-09-13',
        'libertadores g4' => '2026-09-19',
    ];
    $updateCompetition = $pdo->prepare(
        "UPDATE campeonatos c
         LEFT JOIN competicao_identidades i ON i.id=c.identidade_id
         SET c.data_inicio=?
         WHERE c.ativo=1 AND c.status<>'finalizado' AND c.data_inicio IS NULL
           AND (i.chave=? OR LOWER(c.nome) LIKE ?)"
    );
    foreach ($defaults as $key => $date) {
        $namePattern = match ($key) {
            'brasileirao' => '%brasileir%',
            'champions league' => '%champions%',
            default => '%libertadores%',
        };
        $updateCompetition->execute([$date, $key, $namePattern]);
    }

    // Cada rodada dos pontos corridos acontece no dia seguinte à anterior.
    $pdo->exec(
        "UPDATE partidas p
         JOIN campeonatos c ON c.id=p.campeonato_id
         SET p.data_partida=DATE_ADD(c.data_inicio, INTERVAL (p.rodada - 1) DAY)
         WHERE p.ativo=1 AND p.data_partida IS NULL AND c.tipo='pontos_corridos' AND c.data_inicio IS NOT NULL"
    );

    $ready = true;
}

function competition_start_date_from_post(): string
{
    $date = trim((string)($_POST['data_inicio'] ?? ''));
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new RuntimeException('Informe uma data de início válida.');
    }
    return $date;
}

function competition_round_date(string $startDate, int $round): string
{
    if ($round < 1) throw new RuntimeException('Rodada inválida para o calendário.');
    return (new DateTimeImmutable($startDate))->modify('+' . ($round - 1) . ' days')->format('Y-m-d 00:00:00');
}
