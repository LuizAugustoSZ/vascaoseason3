ALTER TABLE titulos
    ADD COLUMN campeonato_id INT UNSIGNED NULL AFTER participante_id,
    ADD UNIQUE KEY uk_titulo_campeonato (campeonato_id);

