ALTER TABLE participantes
    ADD COLUMN participacoes_futuras TINYINT(1) NOT NULL DEFAULT 1 AFTER ativo;
