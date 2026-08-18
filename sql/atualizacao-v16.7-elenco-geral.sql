CREATE TABLE IF NOT EXISTS jogadores_gerais (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    participante_id INT UNSIGNED NOT NULL,
    nome VARCHAR(150) NOT NULL,
    overall TINYINT UNSIGNED NOT NULL,
    posicao VARCHAR(30) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    entrou_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    saiu_em TIMESTAMP NULL,
    UNIQUE KEY uk_jogador_geral_carta (participante_id,nome,overall,posicao),
    KEY idx_jogador_geral_clube (participante_id,ativo,posicao,nome),
    CONSTRAINT fk_jogador_geral_participante FOREIGN KEY (participante_id) REFERENCES participantes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO jogadores_gerais(participante_id,nome,overall,posicao,ativo,entrou_em)
SELECT participante_id,nome,overall,posicao,1,MIN(entrou_em)
FROM jogadores_elenco
WHERE ativo=1
GROUP BY participante_id,nome,overall,posicao;
