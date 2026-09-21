<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$correctionId = 'v25.1-copa-do-brasil-history';
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS data_corrections (
    id VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    details TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS data_correction_backups (
    correction_id VARCHAR(100) NOT NULL,
    table_name VARCHAR(64) NOT NULL,
    row_id INT UNSIGNED NOT NULL,
    payload LONGTEXT NOT NULL,
    backed_up_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (correction_id,table_name,row_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$alreadyApplied = $pdo->prepare('SELECT 1 FROM data_corrections WHERE id=?');
$alreadyApplied->execute([$correctionId]);
if ($alreadyApplied->fetchColumn()) exit(0);

$pdo->beginTransaction();
try {
    $duplicateStmt = $pdo->query("SELECT manual.* FROM titulos manual
        JOIN titulos official ON official.campeonato_id IS NOT NULL
            AND official.participante_id=manual.participante_id
            AND official.temporada=manual.temporada
            AND LOWER(TRIM(official.titulo))=LOWER(TRIM(manual.titulo))
        JOIN participantes p ON p.id=official.participante_id
        WHERE manual.campeonato_id IS NULL
          AND manual.temporada='Season 3'
          AND LOWER(TRIM(manual.titulo))='copa do brasil ii'
          AND p.time_nome='Locomotiva FC'
        FOR UPDATE");
    $duplicates = $duplicateStmt->fetchAll();

    $historicalStmt = $pdo->query("SELECT t.* FROM titulos t
        LEFT JOIN participantes p ON p.id=t.participante_id
        WHERE t.temporada='Season 2'
          AND LOWER(TRIM(t.titulo))='copa do brasil'
          AND COALESCE(p.nome,t.tecnico_nome)='Bay'
        FOR UPDATE");
    $historicalTitles = $historicalStmt->fetchAll();

    $backup = $pdo->prepare('INSERT IGNORE INTO data_correction_backups(correction_id,table_name,row_id,payload) VALUES(?,?,?,?)');
    foreach (array_merge($duplicates, $historicalTitles) as $row) {
        $backup->execute([$correctionId, 'titulos', (int)$row['id'], json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    if ($duplicates) {
        $delete = $pdo->prepare('DELETE FROM titulos WHERE id=? AND campeonato_id IS NULL');
        foreach ($duplicates as $row) $delete->execute([(int)$row['id']]);
    }
    if ($historicalTitles) {
        $update = $pdo->prepare("UPDATE titulos SET participante_id=NULL,tecnico_nome='Bay',time_nome='Paris Saint-Germain' WHERE id=?");
        foreach ($historicalTitles as $row) $update->execute([(int)$row['id']]);
    }

    $details = sprintf('Duplicados removidos: %d; títulos históricos corrigidos: %d', count($duplicates), count($historicalTitles));
    $done = $pdo->prepare('INSERT INTO data_corrections(id,details) VALUES(?,?)');
    $done->execute([$correctionId, $details]);
    $pdo->commit();
    fwrite(STDOUT, $details . PHP_EOL);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
