<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'vascaoseason3',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'Servidor do Vascao | Season 3',
        'base_url' => rtrim(getenv('APP_URL') ?: '', '/'),
        'timezone' => 'America/Sao_Paulo',
    ],
];
