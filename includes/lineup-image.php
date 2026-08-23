<?php
declare(strict_types=1);

function lineup_image_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS imagens_escalacao (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, campeonato_id INT NOT NULL, participante_id INT NOT NULL, caminho VARCHAR(255) NOT NULL, conteudo MEDIUMBLOB NULL, mime VARCHAR(50) NOT NULL DEFAULT 'image/webp', atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_imagem_escalacao_clube (campeonato_id, participante_id), KEY idx_imagem_escalacao_participante (participante_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $columns = $pdo->query("SHOW COLUMNS FROM imagens_escalacao")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('conteudo', $columns, true)) $pdo->exec("ALTER TABLE imagens_escalacao ADD conteudo MEDIUMBLOB NULL AFTER caminho");
    if (!in_array('mime', $columns, true)) $pdo->exec("ALTER TABLE imagens_escalacao ADD mime VARCHAR(50) NOT NULL DEFAULT 'image/webp' AFTER conteudo");
}

function lineup_image_store(array $upload, int $championshipId, int $participantId): string
{
    if (!extension_loaded('gd') || !function_exists('imagewebp')) throw new RuntimeException('O servidor ainda não está preparado para processar imagens. Tente novamente após a atualização.');
    $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new RuntimeException('A imagem ultrapassa o limite de 12 MB.');
    if ($uploadError !== UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível receber a imagem. Selecione o arquivo novamente.');
    if ((int)($upload['size'] ?? 0) > 12 * 1024 * 1024) throw new RuntimeException('A imagem deve ter no máximo 12 MB.');
    $temporaryPath = (string)($upload['tmp_name'] ?? '');
    $info = @getimagesize($temporaryPath);
    if (!$info || !in_array((string)($info['mime'] ?? ''), ['image/jpeg', 'image/png', 'image/webp'], true)) throw new RuntimeException('Envie uma imagem PNG, JPEG ou WebP válida.');
    if ((int)$info[0] < 600 || (int)$info[1] < 600) throw new RuntimeException('A imagem precisa ter pelo menos 600 × 600 pixels.');
    $source = match ((string)$info['mime']) {'image/jpeg' => @imagecreatefromjpeg($temporaryPath), 'image/png' => @imagecreatefrompng($temporaryPath), 'image/webp' => @imagecreatefromwebp($temporaryPath), default => false};
    if (!$source) throw new RuntimeException('Não foi possível processar a imagem enviada.');
    $width = imagesx($source); $height = imagesy($source); $scale = min(1, 2500 / max($width, $height));
    $targetWidth = max(1, (int)round($width * $scale)); $targetHeight = max(1, (int)round($height * $scale));
    $output = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$output) { imagedestroy($source); throw new RuntimeException('Não foi possível preparar a imagem.'); }
    imagealphablending($output, false); imagesavealpha($output, true);
    imagecopyresampled($output, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
    $relativeDirectory = 'assets/uploads/lineups'; $absoluteDirectory = dirname(__DIR__) . '/' . $relativeDirectory;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) { imagedestroy($source); imagedestroy($output); throw new RuntimeException('Não foi possível criar a pasta das imagens.'); }
    $filename = 'lineup-' . $championshipId . '-' . $participantId . '-' . bin2hex(random_bytes(6)) . '.webp';
    $saved = imagewebp($output, $absoluteDirectory . '/' . $filename, 90); imagedestroy($source); imagedestroy($output);
    if (!$saved) throw new RuntimeException('Não foi possível salvar a imagem da escalação.');
    return $relativeDirectory . '/' . $filename;
}

function lineup_image_delete_file(?string $relativePath): void
{
    if (!$relativePath || !preg_match('#^assets/uploads/lineups/[a-zA-Z0-9._-]+\.webp$#', $relativePath)) return;
    $absolutePath = dirname(__DIR__) . '/' . $relativePath;
    if (is_file($absolutePath)) @unlink($absolutePath);
}
