<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/sync.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$provided = (string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');
$secret = sync_secret();
if (sync_environment() !== 'production' || $secret === '' || !hash_equals($secret, $provided)) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}
try {
    $snapshot = sync_snapshot(db());
    $payload = ['ok' => true, 'hash' => sync_hash($snapshot), 'summary' => sync_summary($snapshot)];
    if (($_GET['data'] ?? '') === '1') {
        $payload['snapshot'] = $snapshot;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Falha ao preparar o snapshot.']);
}
