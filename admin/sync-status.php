<?php
declare(strict_types=1);
require __DIR__ . "/../includes/bootstrap.php";
require __DIR__ . "/../includes/sync.php";
admin_required();
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
if (!sync_user_allowed()) {
    http_response_code(403);
    echo json_encode(["ok" => false]);
    exit();
}
try {
    $local = sync_snapshot(db());
    $remote = sync_remote_snapshot(false);
    $localHash = sync_hash($local);
    echo json_encode(
        [
            "ok" => true,
            "changed" => !hash_equals($localHash, (string) $remote["hash"]),
            "local_summary" => sync_summary($local),
            "production_summary" => $remote["summary"] ?? [],
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(
        ["ok" => false, "message" => $error->getMessage()],
        JSON_UNESCAPED_UNICODE,
    );
}
