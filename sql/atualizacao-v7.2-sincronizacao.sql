CREATE TABLE IF NOT EXISTS sync_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  executado_por_conta_id INT NULL,
  executado_por_nome VARCHAR(120) NOT NULL,
  hash_producao CHAR(64) NOT NULL,
  hash_anterior CHAR(64) NOT NULL,
  hash_final CHAR(64) NULL,
  status ENUM('iniciado','concluido','erro') NOT NULL DEFAULT 'iniciado',
  backup_gzip LONGBLOB NULL,
  detalhes TEXT NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  concluido_em TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_sync_history_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
