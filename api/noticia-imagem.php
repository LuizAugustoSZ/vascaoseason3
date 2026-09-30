<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
try {
    $stmt = db()->prepare('SELECT capa_base64 FROM noticias WHERE id=? AND ativo=1');
    $stmt->execute([(int)($_GET['id'] ?? 0)]);
    $data = (string)$stmt->fetchColumn();
    if (!preg_match('#^data:(image/(?:png|jpeg|webp));base64,(.+)$#s', $data, $match)) throw new RuntimeException('Capa não encontrada.');
    $binary = base64_decode($match[2], true);
    if ($binary === false || !getimagesizefromstring($binary)) throw new RuntimeException('Capa inválida.');
    header('Content-Type: ' . $match[1]);
    header('Cache-Control: public, max-age=300');
    header('X-Content-Type-Options: nosniff');
    echo $binary;
} catch (Throwable $error) {
    http_response_code(404);
}
