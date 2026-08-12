<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: 'localhost'),
        'port' => getenv('DB_PORT') ?: (getenv('MYSQLPORT') ?: '3306'),
        'name' => getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: 'vascaoseason3'),
        'user' => getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: 'root'),
        'pass' => getenv('DB_PASS') ?: (getenv('MYSQLPASSWORD') ?: ''),
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'Servidor do Vascao | Season 3',
        'base_url' => rtrim(getenv('APP_URL') ?: '', '/'),
        'timezone' => 'America/Sao_Paulo',
    ],
];
