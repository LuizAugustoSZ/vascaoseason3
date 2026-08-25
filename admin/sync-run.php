<?php
declare(strict_types=1);
require __DIR__ . "/../includes/bootstrap.php";
require __DIR__ . "/../includes/sync.php";
admin_required();
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
if (!sync_user_allowed()) {
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "Acesso negado."]);
    exit();
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false]);
    exit();
}
verify_csrf();
$pdo = db();
$historyId = 0;
try {
    // A produção já aceita resumos maiores; alinhe a coluna da homologação antes de copiar os dados.
    ensure_news_summary_schema($pdo);
    sync_ensure_history($pdo);
    $before = sync_snapshot($pdo);
    $beforeHash = sync_hash($before);
    $remote = sync_remote_snapshot(true);
    $productionHash = (string) $remote["hash"];
    if (hash_equals($beforeHash, $productionHash)) {
        echo json_encode(
            [
                "ok" => true,
                "changed" => false,
                "message" => "A homologação já está atualizada.",
            ],
            JSON_UNESCAPED_UNICODE,
        );
        exit();
    }
    $backupJson = json_encode(
        $before,
        JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRESERVE_ZERO_FRACTION,
    );
    $backup = $backupJson === false ? false : gzencode($backupJson, 6);
    if ($backup === false) {
        throw new RuntimeException(
            "Não foi possível gerar o backup da homologação.",
        );
    }
    $stmt = $pdo->prepare(
        "INSERT INTO sync_history(executado_por_conta_id,executado_por_nome,hash_producao,hash_anterior,status,backup_gzip,detalhes) VALUES(?,?,?,?,'iniciado',?,?)",
    );
    $stmt->execute([
        (int) ($_SESSION["conta_id"] ?? 0),
        (string) ($_SESSION["conta_nome"] ?? "Slower"),
        $productionHash,
        $beforeHash,
        $backup,
        json_encode($remote["summary"] ?? [], JSON_UNESCAPED_UNICODE),
    ]);
    $historyId = (int) $pdo->lastInsertId();
    sync_replace_snapshot($pdo, $remote["snapshot"] ?? []);
    $after = sync_snapshot($pdo);
    $afterHash = sync_hash($after);
    if (!hash_equals($productionHash, $afterHash)) {
        throw new RuntimeException(
            "A verificação final encontrou diferenças após a sincronização.",
        );
    }
    $pdo->prepare(
        "UPDATE sync_history SET hash_final=?,status='concluido',concluido_em=NOW() WHERE id=?",
    )->execute([$afterHash, $historyId]);
    $pdo->exec(
        "DELETE FROM sync_history WHERE id NOT IN (SELECT id FROM (SELECT id FROM sync_history ORDER BY id DESC LIMIT 3) recentes)",
    );
    echo json_encode(
        [
            "ok" => true,
            "changed" => true,
            "message" => "Banco de homologação atualizado com sucesso.",
            "summary" => sync_summary($after),
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
} catch (Throwable $error) {
    if ($historyId > 0) {
        try {
            $pdo->prepare(
                "UPDATE sync_history SET status='erro',detalhes=?,concluido_em=NOW() WHERE id=?",
            )->execute([$error->getMessage(), $historyId]);
        } catch (Throwable $ignored) {
        }
    }
    http_response_code(500);
    echo json_encode(
        ["ok" => false, "message" => $error->getMessage()],
        JSON_UNESCAPED_UNICODE,
    );
}
