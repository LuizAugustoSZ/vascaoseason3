<?php

declare(strict_types=1);

require_once __DIR__ . '/email.php';

function password_reset_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS recuperacoes_senha (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conta_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expira_em DATETIME NOT NULL,
        usado_em DATETIME NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_recuperacao_token (token_hash),
        KEY idx_recuperacao_conta (conta_id, criado_em),
        CONSTRAINT fk_recuperacao_conta FOREIGN KEY (conta_id) REFERENCES contas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function password_reset_request(string $email): void
{
    global $config;
    $pdo = db();
    password_reset_ensure_schema($pdo);
    $pdo->exec("DELETE FROM recuperacoes_senha WHERE expira_em < DATE_SUB(NOW(), INTERVAL 1 DAY)");

    $stmt = $pdo->prepare("SELECT id,nome,email FROM contas WHERE email=? AND ativo=1 LIMIT 1");
    $stmt->execute([mb_strtolower(trim($email))]);
    $account = $stmt->fetch();
    if (!$account) return;

    $recent = $pdo->prepare("SELECT 1 FROM recuperacoes_senha WHERE conta_id=? AND criado_em>DATE_SUB(NOW(),INTERVAL 2 MINUTE) LIMIT 1");
    $recent->execute([(int)$account['id']]);
    if ($recent->fetchColumn()) return;

    $baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        error_log('Password reset not sent: APP_URL is not configured.');
        return;
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $pdo->prepare("INSERT INTO recuperacoes_senha(conta_id,token_hash,expira_em) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))")
        ->execute([(int)$account['id'], $tokenHash]);

    $link = $baseUrl . '/redefinir-senha.php?token=' . rawurlencode($token);
    $body = "Olá, {$account['nome']}!\n\nRecebemos um pedido para redefinir sua senha do Vascão S3.\n\nAbra o link abaixo para criar uma nova senha:\n{$link}\n\nO link expira em 1 hora e só pode ser usado uma vez. Se você não pediu a troca, ignore este e-mail.";
    if (!system_email_send((string)$account['email'], 'Redefinição de senha | Vascão S3', $body, 'password-reset-' . $tokenHash)) {
        $pdo->prepare("UPDATE recuperacoes_senha SET usado_em=NOW() WHERE token_hash=?")->execute([$tokenHash]);
        error_log('Password reset email could not be delivered for account ' . (int)$account['id']);
    } else {
        $pdo->prepare("UPDATE recuperacoes_senha SET usado_em=NOW() WHERE conta_id=? AND token_hash<>? AND usado_em IS NULL")
            ->execute([(int)$account['id'], $tokenHash]);
    }
}

function password_reset_find(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    password_reset_ensure_schema(db());
    $stmt = db()->prepare("SELECT r.id,r.conta_id,c.nome,c.senha_hash FROM recuperacoes_senha r JOIN contas c ON c.id=r.conta_id AND c.ativo=1 WHERE r.token_hash=? AND r.usado_em IS NULL AND r.expira_em>NOW() LIMIT 1");
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}
