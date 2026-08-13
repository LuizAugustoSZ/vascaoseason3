CREATE TABLE IF NOT EXISTS sumulas_dreamteam (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dreamteam_id VARCHAR(40) NOT NULL,
    origem VARCHAR(20) NOT NULL,
    partida_id INT UNSIGNED NULL,
    jogo_mata_mata_id INT UNSIGNED NULL,
    estadio VARCHAR(190) NULL,
    clima VARCHAR(120) NULL,
    duracao SMALLINT UNSIGNED NULL,
    craque VARCHAR(190) NULL,
    craque_nota DECIMAL(4,2) NULL,
    dados_json LONGTEXT NOT NULL,
    texto_original MEDIUMTEXT NOT NULL,
    criado_por INT UNSIGNED NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sumula_dreamteam_id (dreamteam_id),
    UNIQUE KEY uk_sumula_partida (origem, partida_id),
    UNIQUE KEY uk_sumula_mata (origem, jogo_mata_mata_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
