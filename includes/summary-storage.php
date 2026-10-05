<?php
declare(strict_types=1);

/** Upgrade legacy result tables before starting the import transaction. */
function summary_ensure_utf8_storage(PDO $pdo): void
{
    $tables = ['gols_partida', 'gols_mata_mata', 'artilharia', 'sumulas_dreamteam'];
    $columns = $pdo->query("SELECT TABLE_NAME, CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('gols_partida','gols_mata_mata','artilharia','sumulas_dreamteam') AND CHARACTER_SET_NAME IS NOT NULL")->fetchAll();
    $legacy = [];
    foreach ($columns as $column) {
        if ($column['CHARACTER_SET_NAME'] !== 'utf8mb4' && in_array($column['TABLE_NAME'], $tables, true)) $legacy[$column['TABLE_NAME']] = true;
    }
    if ($legacy && $pdo->inTransaction()) throw new RuntimeException('A atualização UTF-8 deve ocorrer antes da transação da partida.');
    foreach (array_keys($legacy) as $table) {
        // Fixed allowlist above: identifiers never come from the pasted report.
        $pdo->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
}
