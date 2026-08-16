<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/sync.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$provided = (string)($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');
$secret = sync_secret();
if (
    sync_environment() !== 'staging' ||
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    $secret === '' ||
    $provided === '' ||
    !hash_equals($secret, $provided)
) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Acesso negado.']);
    exit;
}

$source = 'https://vascaoseason3-ironhaven.up.railway.app/api/sync-snapshot.php?data=1';
$context = stream_context_create(['http' => [
    'method' => 'GET',
    'timeout' => 90,
    'ignore_errors' => true,
    'header' => "Accept: application/json\r\nX-Sync-Token: {$secret}\r\n",
]]);
$body = @file_get_contents($source, false, $context);
$statusLine = $http_response_header[0] ?? '';
if ($body === false || !str_contains($statusLine, ' 200 ')) {
    throw new RuntimeException('Não foi possível consultar a produção.');
}
$remote = json_decode($body, true);
if (!is_array($remote) || empty($remote['ok']) || empty($remote['snapshot']) || empty($remote['hash'])) {
    throw new RuntimeException('A produção retornou um snapshot inválido.');
}

$pdo = db();
$pdo->exec(
    "ALTER TABLE jogos_mata_mata
     MODIFY fase VARCHAR(40) NOT NULL,
     MODIFY origem_a_fase VARCHAR(40) NULL,
     MODIFY origem_b_fase VARCHAR(40) NULL"
);
sync_ensure_history($pdo);
$before = sync_snapshot($pdo);
$beforeHash = sync_hash($before);
$productionHash = (string)$remote['hash'];
if (hash_equals($beforeHash, $productionHash)) {
    echo json_encode(['ok' => true, 'changed' => false, 'hash' => $productionHash]);
    exit;
}
$backupJson = json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
$backup = $backupJson === false ? false : gzencode($backupJson, 6);
if ($backup === false) throw new RuntimeException('Não foi possível gerar o backup da homologação.');

$history = $pdo->prepare(
    "INSERT INTO sync_history(executado_por_conta_id,executado_por_nome,hash_producao,hash_anterior,status,backup_gzip,detalhes)
     VALUES(NULL,'Manutenção Codex',?,?,'iniciado',?,?)"
);
$history->execute([
    $productionHash,
    $beforeHash,
    $backup,
    json_encode($remote['summary'] ?? [], JSON_UNESCAPED_UNICODE),
]);
$historyId = (int)$pdo->lastInsertId();

try {
    sync_replace_snapshot($pdo, $remote['snapshot']);
    $after = sync_snapshot($pdo);
    $afterHash = sync_hash($after);
    if (!hash_equals($productionHash, $afterHash)) {
        throw new RuntimeException('A homologação divergiu após a sincronização.');
    }
    $pdo->prepare(
        "UPDATE sync_history SET hash_final=?,status='concluido',concluido_em=NOW() WHERE id=?"
    )->execute([$afterHash, $historyId]);
    echo json_encode([
        'ok' => true,
        'changed' => true,
        'hash' => $afterHash,
        'summary' => sync_summary($after),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $pdo->prepare(
        "UPDATE sync_history SET status='erro',detalhes=?,concluido_em=NOW() WHERE id=?"
    )->execute([$error->getMessage(), $historyId]);
    throw $error;
}
