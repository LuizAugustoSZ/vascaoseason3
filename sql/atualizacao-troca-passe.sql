-- A aplicação também aplica esta expansão de forma idempotente antes das transações.
ALTER TABLE movimentacoes_elenco_geral MODIFY tipo ENUM('compra','venda','troca') NOT NULL;
