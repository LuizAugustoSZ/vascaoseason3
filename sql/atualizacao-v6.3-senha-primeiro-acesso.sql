-- Adiciona o controle de troca obrigatória da senha temporária.
ALTER TABLE contas
  ADD COLUMN trocar_senha TINYINT(1) NOT NULL DEFAULT 0 AFTER senha_hash;

-- Obriga as contas de Editor da Competição já existentes a criarem a própria senha.
UPDATE contas
SET trocar_senha = 1
WHERE eh_admin = 2;
