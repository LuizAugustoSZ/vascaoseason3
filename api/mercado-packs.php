<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/mercado.php';
if (!account_logged_in()) {
    http_response_code(401);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['packs' => mercado_packs_atuais(db())], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
