<?php

declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

try {
    $titleId = (int)($_GET['titulo_id'] ?? 0);
    $stmt = db()->prepare('SELECT t.imagem_base64,t.titulo,i.trofeu_base64 FROM titulos t LEFT JOIN campeonatos c ON c.id=t.campeonato_id LEFT JOIN competicao_identidades i ON i.id=c.identidade_id WHERE t.id=? LIMIT 1');
    $stmt->execute([$titleId]);
    $title = $stmt->fetch();
    $key = competition_identity_match((string)($title['titulo'] ?? ''));
    $dataUrl = $key ? '' : (string)($title['imagem_base64'] ?? '');
    if ($key) {
        $identity = db()->prepare('SELECT trofeu_base64 FROM competicao_identidades WHERE chave=? LIMIT 1');
        $identity->execute([$key]);
        $dataUrl = (string)($identity->fetchColumn() ?: '');
    }
    if (!empty($title['trofeu_base64'])) $dataUrl = (string)$title['trofeu_base64'];
    if (!preg_match('#^data:(image/(?:png|webp|jpeg));base64,(.+)$#s', $dataUrl, $match)) throw new RuntimeException('Imagem não encontrada.');
    $binary = base64_decode($match[2], true);
    if ($binary === false) throw new RuntimeException('Imagem inválida.');
    header('Content-Type: ' . $match[1]);
    header('Cache-Control: public, max-age=3600');
    echo $binary;
} catch (Throwable $error) {
    http_response_code(404);
}
