<?php

declare(strict_types=1);

$mysqlUrl = getenv("MYSQL_URL") ?: "";
$mysql = $mysqlUrl !== "" ? (parse_url($mysqlUrl) ?: []) : [];

return [
    "db" => [
        "host" =>
        getenv("DB_HOST") ?: (getenv("MYSQLHOST") ?:
                $mysql["host"] ?? "localhost"),
        "port" =>
        getenv("DB_PORT") ?: (getenv("MYSQLPORT") ?:
                (string) ($mysql["port"] ?? "3306")),
        "name" =>
        getenv("DB_NAME") ?: (getenv("MYSQLDATABASE") ?:
                ltrim((string) ($mysql["path"] ?? "vascaoseason3"), "/")),
        "user" =>
        getenv("DB_USER") ?: (getenv("MYSQLUSER") ?:
                urldecode((string) ($mysql["user"] ?? "root"))),
        "pass" =>
        getenv("DB_PASS") ?: (getenv("MYSQLPASSWORD") ?:
                urldecode((string) ($mysql["pass"] ?? ""))),
        "charset" => "utf8mb4",
    ],
    "app" => [
        "name" => "Servidor do Vascao | Season 3",
        "base_url" => rtrim(getenv("APP_URL") ?: "", "/"),
        "environment" => getenv("APP_ENV") ?: "local",
        "timezone" => "America/Sao_Paulo",
    ],
    // Na homologação, source_url aponta para o site oficial. Em produção,
    // deixe source_url vazio para impedir sincronização no sentido inverso.
    "sync" => [
        "source_url" => rtrim(getenv("SYNC_SOURCE_URL") ?: "", "/"),
        "secret" => getenv("SYNC_SECRET") ?: "",
    ],
];
