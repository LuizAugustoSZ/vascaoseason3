CREATE TABLE IF NOT EXISTS imagens_escalacao (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campeonato_id INT NOT NULL,
    participante_id INT NOT NULL,
    caminho VARCHAR(255) NOT NULL,
    conteudo MEDIUMBLOB NULL,
    mime VARCHAR(50) NOT NULL DEFAULT 'image/webp',
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_imagem_escalacao_clube (campeonato_id, participante_id),
    KEY idx_imagem_escalacao_participante (participante_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
