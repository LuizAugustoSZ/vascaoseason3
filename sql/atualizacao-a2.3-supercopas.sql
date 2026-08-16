-- Execute primeiro em homologação e, após validação, em produção.
ALTER TABLE campeonatos
    MODIFY tipo ENUM('pontos_corridos','mata_mata','supercopa') NOT NULL;

CREATE TABLE supercopas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campeonato_id INT UNSIGNED NOT NULL,
    origem_a_campeonato_id INT UNSIGNED NOT NULL,
    origem_b_campeonato_id INT UNSIGNED NOT NULL,
    regra_mesmo_campeao ENUM('vice_origem_a','vice_origem_b') NOT NULL DEFAULT 'vice_origem_a',
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_supercopa_campeonato (campeonato_id),
    KEY idx_supercopa_origem_a (origem_a_campeonato_id),
    KEY idx_supercopa_origem_b (origem_b_campeonato_id),
    CONSTRAINT fk_supercopa_campeonato FOREIGN KEY (campeonato_id) REFERENCES campeonatos(id),
    CONSTRAINT fk_supercopa_origem_a FOREIGN KEY (origem_a_campeonato_id) REFERENCES campeonatos(id),
    CONSTRAINT fk_supercopa_origem_b FOREIGN KEY (origem_b_campeonato_id) REFERENCES campeonatos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
