CREATE TABLE IF NOT EXISTS competicao_identidades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chave VARCHAR(120) NOT NULL,
    nome VARCHAR(150) NOT NULL,
    logo_base64 MEDIUMTEXT NULL,
    trofeu_base64 MEDIUMTEXT NULL,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_competicao_identidade_chave (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE campeonatos
    ADD COLUMN identidade_id INT UNSIGNED NULL AFTER nome,
    ADD KEY idx_campeonatos_identidade (identidade_id),
    ADD CONSTRAINT fk_campeonatos_identidade FOREIGN KEY (identidade_id) REFERENCES competicao_identidades(id);
