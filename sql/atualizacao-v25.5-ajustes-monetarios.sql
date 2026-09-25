ALTER TABLE clubes_campeonato
    MODIFY saldo DECIMAL(18,2) NOT NULL DEFAULT 0;

ALTER TABLE movimentacoes_elenco
    MODIFY valor_origem DECIMAL(18,2) NULL,
    MODIFY valor DECIMAL(18,2) NOT NULL,
    MODIFY saldo_anterior DECIMAL(18,2) NOT NULL,
    MODIFY saldo_posterior DECIMAL(18,2) NOT NULL;

ALTER TABLE clubes_gerais
    MODIFY saldo DECIMAL(18,2) NOT NULL DEFAULT 0;

ALTER TABLE movimentacoes_elenco_geral
    MODIFY valor_origem DECIMAL(18,2) NULL,
    MODIFY valor DECIMAL(18,2) NOT NULL DEFAULT 0,
    MODIFY saldo_anterior DECIMAL(18,2) NOT NULL,
    MODIFY saldo_posterior DECIMAL(18,2) NOT NULL;
