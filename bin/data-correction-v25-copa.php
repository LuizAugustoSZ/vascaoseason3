<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$correctionId = 'v25.1-copa-do-brasil-duplicate-v2';
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

$pdo->beginTransaction();
try {
    // Restaura fielmente o registro histórico caso a primeira revisão deste patch
    // tenha sido executada durante o intervalo entre os dois deploys.
    $wrongCorrection = 'v25.1-copa-do-brasil-history';
    $restoreStmt = $pdo->prepare("SELECT row_id,payload FROM data_correction_backups WHERE correction_id=? AND table_name='titulos'");
    $restoreStmt->execute([$wrongCorrection]);
    $restore = $pdo->prepare('UPDATE titulos SET participante_id=?,tecnico_nome=?,time_nome=? WHERE id=?');
    foreach ($restoreStmt->fetchAll() as $backupRow) {
        $original = json_decode((string)$backupRow['payload'], true);
        if (!is_array($original) || ($original['temporada']??'') !== 'Season 2' || mb_strtolower(trim((string)($original['titulo']??'')), 'UTF-8') !== 'copa do brasil') continue;
        $restore->execute([$original['participante_id']??null,$original['tecnico_nome']??null,$original['time_nome']??null,(int)$backupRow['row_id']]);
    }

    $alreadyApplied = $pdo->prepare('SELECT 1 FROM data_corrections WHERE id=?');
    $alreadyApplied->execute([$correctionId]);
    if ($alreadyApplied->fetchColumn()) {
        $pdo->commit();
        exit(0);
    }

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

    $backup = $pdo->prepare('INSERT IGNORE INTO data_correction_backups(correction_id,table_name,row_id,payload) VALUES(?,?,?,?)');
    foreach ($duplicates as $row) {
        $backup->execute([$correctionId, 'titulos', (int)$row['id'], json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    if ($duplicates) {
        $delete = $pdo->prepare('DELETE FROM titulos WHERE id=? AND campeonato_id IS NULL');
        foreach ($duplicates as $row) $delete->execute([(int)$row['id']]);
    }
    $details = sprintf('Duplicados removidos: %d; nomes de clubes alterados: 0', count($duplicates));
    $done = $pdo->prepare('INSERT INTO data_corrections(id,details) VALUES(?,?)');
    $done->execute([$correctionId, $details]);
    $pdo->commit();
    fwrite(STDOUT, $details . PHP_EOL);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
