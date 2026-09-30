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
    if (!$pdo->query("SHOW COLUMNS FROM jogos_mata_mata LIKE 'data_partida'")->fetch()) {
        $pdo->exec("ALTER TABLE jogos_mata_mata ADD COLUMN data_partida DATETIME NULL");
    }

    if (!$column) {
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
    }

    // Cada rodada dos pontos corridos acontece no dia seguinte à anterior.
    $pdo->exec(
        "UPDATE partidas p
         JOIN campeonatos c ON c.id=p.campeonato_id
         SET p.data_partida=DATE_ADD(c.data_inicio, INTERVAL (p.rodada - 1) DAY)
         WHERE p.ativo=1 AND p.data_partida IS NULL AND c.tipo='pontos_corridos' AND c.data_inicio IS NOT NULL"
    );
    $pdo->exec("UPDATE jogos_mata_mata j JOIN campeonatos c ON c.id=j.campeonato_id SET j.data_partida=c.data_inicio WHERE j.ativo=1 AND j.status='agendado' AND j.data_partida IS NULL AND c.data_inicio IS NOT NULL");

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

function competition_schedule_datetime(string $value): ?string
{
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    if (!$date || $date->format('Y-m-d\TH:i') !== $value) throw new RuntimeException('Informe uma data e horário válidos.');
    return $date->format('Y-m-d H:i:s');
}

// Deve ser chamada dentro da transação de edição. Preserva intervalos e horários.
function competition_schedule_reschedule(PDO $pdo, int $id, string $newDate): void
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $newDate);
    if (!$parsed || $parsed->format('Y-m-d') !== $newDate) throw new RuntimeException('Informe uma data de início válida.');
    // Serializa as alterações de calendário, inclusive entre competições diferentes.
    $pdo->query('SELECT id FROM campeonatos WHERE ativo=1 ORDER BY id FOR UPDATE')->fetchAll();
    $stmt = $pdo->prepare('SELECT data_inicio,status,tipo FROM campeonatos WHERE id=? AND ativo=1');
    $stmt->execute([$id]);
    $competition = $stmt->fetch();
    if (!$competition) throw new RuntimeException('Competição não encontrada.');
    if ($competition['data_inicio'] === $newDate) return;
    if ($competition['status'] === 'finalizado') throw new RuntimeException('Reabra a competição antes de alterar seu calendário.');
    $stmt = $pdo->prepare("SELECT nome FROM campeonatos WHERE ativo=1 AND id<>? AND data_inicio=? LIMIT 1");
    $stmt->execute([$id, $newDate]);
    if ($conflict = $stmt->fetchColumn()) throw new RuntimeException('Já existe uma competição nesta data: ' . $conflict . '. Escolha outro dia.');
    $oldDate = $competition['data_inicio'];
    if (!$oldDate) {
        $stmt = $pdo->prepare('SELECT MIN(data_partida) FROM (SELECT data_partida FROM partidas WHERE campeonato_id=? AND ativo=1 UNION ALL SELECT data_partida FROM jogos_mata_mata WHERE campeonato_id=? AND ativo=1) calendario');
        $stmt->execute([$id, $id]);
        $oldDate = substr((string)$stmt->fetchColumn(), 0, 10) ?: $newDate;
    }
    $days = (int)(new DateTimeImmutable($oldDate))->diff($parsed)->format('%r%a');
    foreach (['partidas', 'jogos_mata_mata'] as $table) {
        $fallback = $table === 'partidas' && $competition['tipo'] === 'pontos_corridos'
            ? 'DATE_ADD(?, INTERVAL (rodada - 1) DAY)' : '?';
        $stmt = $pdo->prepare("UPDATE $table SET data_partida=CASE WHEN data_partida IS NOT NULL THEN DATE_ADD(data_partida, INTERVAL ? DAY) ELSE $fallback END WHERE campeonato_id=? AND ativo=1");
        $stmt->execute([$days, $newDate, $id]);
    }
    $pdo->prepare('UPDATE campeonatos SET data_inicio=? WHERE id=?')->execute([$newDate, $id]);
}

function competition_schedule_priority_sql(string $alias = 'c'): string
{
    return "CASE WHEN $alias.status='finalizado' THEN 2 WHEN EXISTS(SELECT 1 FROM partidas sp WHERE sp.campeonato_id=$alias.id AND sp.ativo=1 AND sp.status IN ('finalizada','wo','penalidade')) OR EXISTS(SELECT 1 FROM jogos_mata_mata sj WHERE sj.campeonato_id=$alias.id AND sj.ativo=1 AND sj.status IN ('finalizado','wo')) THEN 0 ELSE 1 END";
}

function competition_schedule_order_sql(string $alias = 'c'): string
{
    return competition_schedule_priority_sql($alias) . ", $alias.data_inicio IS NULL, $alias.data_inicio, $alias.id DESC";
}
