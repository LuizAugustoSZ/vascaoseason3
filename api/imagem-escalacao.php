<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/lineup-image.php';

$championshipId = (int)($_GET['campeonato_id'] ?? 0);
$participantId = (int)($_GET['participante_id'] ?? 0);
if ($championshipId <= 0 || $participantId <= 0) { http_response_code(404); exit; }

try {
    $pdo = db();
    lineup_image_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT conteudo,mime,atualizado_em FROM imagens_escalacao WHERE campeonato_id=? AND participante_id=? LIMIT 1");
    $stmt->execute([$championshipId, $participantId]);
    $image = $stmt->fetch();
    if (!$image || empty($image['conteudo'])) { http_response_code(404); exit; }
    $etag = '"' . sha1((string)$image['atualizado_em'] . ':' . strlen((string)$image['conteudo'])) . '"';
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
    header('Content-Type: ' . ((string)$image['mime'] ?: 'image/webp'));
    header('Content-Length: ' . strlen((string)$image['conteudo']));
    header('Cache-Control: public, max-age=3600');
    header('ETag: ' . $etag);
    echo $image['conteudo'];
} catch (Throwable $error) {
    http_response_code(404);
}
