-- Vincula um mata-mata de quatro clubes às posições atuais do G4 de uma liga.
CREATE TABLE IF NOT EXISTS mata_mata_g4 (
    campeonato_id INT UNSIGNED NOT NULL PRIMARY KEY,
    origem_campeonato_id INT UNSIGNED NOT NULL,
    sorteio_json JSON NOT NULL,
    congelado_em DATETIME NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mata_g4_origem (origem_campeonato_id),
    CONSTRAINT fk_mata_g4_campeonato FOREIGN KEY (campeonato_id) REFERENCES campeonatos(id),
    CONSTRAINT fk_mata_g4_origem FOREIGN KEY (origem_campeonato_id) REFERENCES campeonatos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
