<?php

declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

try {
    $titleId = (int)($_GET['titulo_id'] ?? 0);
    $stmt = db()->prepare('SELECT imagem_base64 FROM titulos WHERE id=? LIMIT 1');
    $stmt->execute([$titleId]);
    $dataUrl = (string)($stmt->fetchColumn() ?: '');
    if (!preg_match('#^data:(image/(?:png|webp|jpeg));base64,(.+)$#s', $dataUrl, $match)) throw new RuntimeException('Imagem não encontrada.');
    $binary = base64_decode($match[2], true);
    if ($binary === false) throw new RuntimeException('Imagem inválida.');
    header('Content-Type: ' . $match[1]);
    header('Cache-Control: public, max-age=3600');
    echo $binary;
} catch (Throwable $error) {
    http_response_code(404);
}
