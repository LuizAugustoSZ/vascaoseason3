<?php

declare(strict_types=1);

const SYNC_TABLES = [
    "participantes",
    "campeonatos",
    "configuracoes_site",
    "partidas",
    "jogos_mata_mata",
    "gols_partida",
    "sumulas_dreamteam",
    "gols_mata_mata",
    "artilharia",
    "titulos",
    "noticias",
    "videos",
];

function sync_source_url(): string
{
    global $config;
    return rtrim(trim((string) (
        getenv("SYNC_SOURCE_URL") ?:
        ($config["sync"]["source_url"] ?? "")
    )), "/");
}

function sync_user_allowed(): bool
{
    global $config;
    $sourceUrl = sync_source_url();
    $currentUrl = rtrim(trim((string) ($config["app"]["base_url"] ?? "")), "/");
    $isSyncTarget = $sourceUrl !== "" &&
        ($currentUrl === "" || strcasecmp($currentUrl, $sourceUrl) !== 0);

    return $isSyncTarget &&
        account_is_master() &&
        mb_strtolower(
            trim((string) ($_SESSION["conta_nome"] ?? "")),
            "UTF-8",
        ) === "slower";
}

function sync_source_allowed(): bool
{
    // A origem oficial fornece snapshots, mas nunca aponta para outra URL.
    return sync_source_url() === "" && sync_secret() !== "";
}

function sync_secret(): string
{
    global $config;
    return trim((string) (
        getenv("SYNC_SECRET") ?:
        ($config["sync"]["secret"] ?? "")
    ));
}

function sync_ensure_history(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS sync_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        executado_por_conta_id INT NULL,
        executado_por_nome VARCHAR(120) NOT NULL,
        hash_producao CHAR(64) NOT NULL,
        hash_anterior CHAR(64) NOT NULL,
        hash_final CHAR(64) NULL,
        status ENUM('iniciado','concluido','erro') NOT NULL DEFAULT 'iniciado',
        backup_gzip LONGBLOB NULL,
        detalhes TEXT NULL,
        criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        concluido_em TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_sync_history_criado (criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function sync_snapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach (SYNC_TABLES as $table) {
        $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll();
        if (!$columns) {
            throw new RuntimeException(
                "A tabela {$table} não está disponível para sincronização.",
            );
        }
        $order = implode(
            ",",
            array_map(
                static fn(array $column): string => "`" .
                    str_replace("`", "``", (string) $column["Field"]) .
                    "`",
                $columns,
            ),
        );
        $snapshot[$table] = $pdo
            ->query("SELECT * FROM `$table` ORDER BY $order")
            ->fetchAll();
    }
    return $snapshot;
}

function sync_hash(array $snapshot): string
{
    $json = json_encode(
        $snapshot,
        JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRESERVE_ZERO_FRACTION,
    );
    if ($json === false) {
        throw new RuntimeException(
            "Não foi possível calcular a assinatura dos dados.",
        );
    }
    return hash("sha256", $json);
}

function sync_summary(array $snapshot): array
{
    $summary = [];
    foreach (SYNC_TABLES as $table) {
        $summary[$table] = count($snapshot[$table] ?? []);
    }
    return $summary;
}

function sync_remote_snapshot(bool $withData): array
{
    $baseUrl = sync_source_url();
    $secret = sync_secret();
    if ($baseUrl === "" || $secret === "") {
        throw new RuntimeException(
            "A sincronização ainda não foi configurada neste ambiente.",
        );
    }
    $context = stream_context_create([
        "http" => [
            "method" => "GET",
            "timeout" => 90,
            "ignore_errors" => true,
            "header" => "Accept: application/json\r\nX-Sync-Token: {$secret}\r\n",
        ],
    ]);
    $url = $baseUrl . "/api/sync-snapshot.php?data=" . ($withData ? "1" : "0");
    $body = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? "";
    if ($body === false || !str_contains($statusLine, " 200 ")) {
        throw new RuntimeException(
            "Não foi possível consultar o banco de produção.",
        );
    }
    $payload = json_decode($body, true);
    if (
        !is_array($payload) ||
        empty($payload["ok"]) ||
        empty($payload["hash"])
    ) {
        throw new RuntimeException(
            "A produção retornou uma resposta de sincronização inválida.",
        );
    }
    return $payload;
}

function sync_replace_snapshot(PDO $pdo, array $snapshot): void
{
    foreach (SYNC_TABLES as $table) {
        if (!isset($snapshot[$table]) || !is_array($snapshot[$table])) {
            throw new RuntimeException(
                "O snapshot não contém a tabela {$table}.",
            );
        }
    }
    $pdo->beginTransaction();
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        foreach (array_reverse(SYNC_TABLES) as $table) {
            $pdo->exec("DELETE FROM `$table`");
        }
        foreach (SYNC_TABLES as $table) {
            $rows = $snapshot[$table];
            if (!$rows) {
                continue;
            }
            $columns = array_keys($rows[0]);
            $quoted = implode(
                ",",
                array_map(
                    static fn(string $column): string => "`" .
                        str_replace("`", "``", $column) .
                        "`",
                    $columns,
                ),
            );
            $placeholders = implode(",", array_fill(0, count($columns), "?"));
            $insert = $pdo->prepare(
                "INSERT INTO `$table` ($quoted) VALUES ($placeholders)",
            );
            foreach ($rows as $row) {
                $insert->execute(
                    array_map(
                        static fn(string $column) => $row[$column] ?? null,
                        $columns,
                    ),
                );
            }
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
}
