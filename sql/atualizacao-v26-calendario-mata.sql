-- A aplicação também instala esta coluna automaticamente de forma idempotente.
ALTER TABLE jogos_mata_mata ADD COLUMN IF NOT EXISTS data_partida DATETIME NULL;
