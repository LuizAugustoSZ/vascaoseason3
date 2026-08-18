<?php

declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

try {
    $championshipId = (int)($_GET['campeonato_id'] ?? 0);
    $type = ($_GET['tipo'] ?? 'logo') === 'trofeu' ? 'trofeu_base64' : 'logo_base64';
    $stmt = db()->prepare("SELECT i.$type imagem FROM campeonatos c JOIN competicao_identidades i ON i.id=c.identidade_id WHERE c.id=? AND c.ativo=1 LIMIT 1");
    $stmt->execute([$championshipId]);
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
