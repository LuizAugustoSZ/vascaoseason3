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

CREATE TABLE IF NOT EXISTS clubes_gerais (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    participante_id INT UNSIGNED NOT NULL,
    saldo DECIMAL(12,2) NOT NULL DEFAULT 0,
    cofre_configurado TINYINT(1) NOT NULL DEFAULT 0,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_clube_geral (participante_id),
    CONSTRAINT fk_clube_geral_participante FOREIGN KEY (participante_id) REFERENCES participantes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS movimentacoes_elenco_geral (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    participante_id INT UNSIGNED NOT NULL,
    jogador_geral_id INT UNSIGNED NOT NULL,
    tipo ENUM('compra','venda') NOT NULL,
    origem VARCHAR(30) NOT NULL DEFAULT 'compra_direta',
    origem_detalhe VARCHAR(120) NULL,
    valor_origem DECIMAL(12,2) NULL,
    moeda_origem VARCHAR(20) NULL,
    jogador_nome VARCHAR(150) NOT NULL,
    jogador_overall TINYINT UNSIGNED NOT NULL,
    jogador_posicao VARCHAR(30) NOT NULL,
    valor DECIMAL(12,2) NOT NULL DEFAULT 0,
    saldo_anterior DECIMAL(12,2) NOT NULL,
    saldo_posterior DECIMAL(12,2) NOT NULL,
    conta_id INT UNSIGNED NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_movimento_geral_historico (participante_id,criado_em),
    CONSTRAINT fk_movimento_geral_participante FOREIGN KEY (participante_id) REFERENCES participantes(id),
    CONSTRAINT fk_movimento_geral_jogador FOREIGN KEY (jogador_geral_id) REFERENCES jogadores_gerais(id),
    CONSTRAINT fk_movimento_geral_conta FOREIGN KEY (conta_id) REFERENCES contas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO jogadores_gerais(participante_id,nome,overall,posicao,ativo,entrou_em)
SELECT participante_id,nome,overall,posicao,1,MIN(entrou_em)
FROM jogadores_elenco
WHERE ativo=1
GROUP BY participante_id,nome,overall,posicao;
