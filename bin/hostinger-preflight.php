<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Diagnóstico sem migrations, coleta de notificações ou exibição de segredos.
require __DIR__ . '/../includes/bootstrap.php';
$required = ['pdo_mysql', 'gd', 'mbstring', 'dom', 'fileinfo', 'iconv', 'openssl', 'zlib'];
$missing = array_values(array_filter($required, static fn(string $name): bool => !extension_loaded($name)));
$report = [
    'php' => PHP_VERSION,
    'missing_extensions' => $missing,
    'gd_webp' => function_exists('imagewebp'),
    'https_streams' => (bool) ini_get('allow_url_fopen'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'private_config_loaded' => isset($privateConfig) && is_file($privateConfig),
    'email_disabled' => getenv('EMAIL_ENABLED') !== false && !filter_var(getenv('EMAIL_ENABLED'), FILTER_VALIDATE_BOOLEAN),
    'worker_disabled' => getenv('NOTIFICATIONS_WORKER_ENABLED') !== false && !filter_var(getenv('NOTIFICATIONS_WORKER_ENABLED'), FILTER_VALIDATE_BOOLEAN),
];
try {
    $pdo = db();
    $report['database_connected'] = true;
    $report['database_version'] = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $report['database_name'] = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $report['table_count'] = count($pdo->query('SHOW FULL TABLES')->fetchAll());
} catch (Throwable $error) {
    $report['database_connected'] = false;
    // As mensagens PDO podem conter hosts e nomes de usuários; não imprimi-las.
    $report['database_error_type'] = get_class($error);
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($missing || empty($report['database_connected']) ? 1 : 0);
