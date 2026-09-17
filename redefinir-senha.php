<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/password-reset.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$reset = password_reset_find($token);
$error = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (!$reset) throw new RuntimeException('Este link é inválido, expirou ou já foi utilizado.');
        $password = (string)($_POST['nova_senha'] ?? '');
        $confirmation = (string)($_POST['confirmar_senha'] ?? '');
        if (strlen($password) < 8) throw new RuntimeException('A nova senha precisa ter pelo menos 8 caracteres.');
        if ($password !== $confirmation) throw new RuntimeException('A confirmacao da nova senha nao confere.');
        if (password_verify($password, $reset['senha_hash'])) throw new RuntimeException('Escolha uma senha diferente da atual.');

        $pdo = db();
        auth_ensure_persistent_table();
        $pdo->beginTransaction();
        $consume = $pdo->prepare("UPDATE recuperacoes_senha SET usado_em=NOW() WHERE id=? AND usado_em IS NULL AND expira_em>NOW()");
        $consume->execute([(int)$reset['id']]);
        if ($consume->rowCount() !== 1) throw new RuntimeException('Este link é inválido, expirou ou já foi utilizado.');
        $pdo->prepare("UPDATE contas SET senha_hash=?,trocar_senha=0 WHERE id=? AND ativo=1")
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset['conta_id']]);
        $pdo->prepare("UPDATE recuperacoes_senha SET usado_em=NOW() WHERE conta_id=? AND usado_em IS NULL")
            ->execute([(int)$reset['conta_id']]);
        $pdo->prepare("DELETE FROM login_persistente WHERE conta_id=?")->execute([(int)$reset['conta_id']]);
        $pdo->commit();
        audit_event('senha_redefinida', 'autenticacao', 'Senha redefinida por link de recuperacao.', ['conta_id' => (int)$reset['conta_id']]);
        $success = true;
        $reset = null;
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Não foi possível redefinir a senha agora.';
    }
}
?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Redefinir senha | Vascao S3</title><link rel="icon" href="favicon.ico" sizes="any"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__.'/assets/css/style.css') ?>"><link rel="stylesheet" href="assets/css/branding.css?v=5"><script defer src="assets/js/password-toggle.js?v=<?= filemtime(__DIR__.'/assets/js/password-toggle.js') ?>"></script></head>
<body class="d-flex align-items-center min-vh-100"><main class="container" style="max-width:460px"><div class="panel p-4"><span class="eyebrow">Segurança da conta</span><h1 class="font-condensed mt-3">REDEFINIR SENHA</h1>
<?php if ($success): ?><div class="alert alert-success">Senha alterada com sucesso. Agora você já pode entrar.</div><a class="btn btn-danger w-100" href="login.php">Entrar</a>
<?php elseif (!$reset): ?><div class="alert alert-danger"><?= e($error ?: 'Este link é inválido, expirou ou já foi utilizado.') ?></div><a class="btn btn-danger w-100" href="esqueci-senha.php">Solicitar outro link</a>
<?php else: ?><p class="text-secondary">Olá, <?= e($reset['nome']) ?>. Crie uma nova senha para sua conta.</p><?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><div class="mb-3"><label class="form-label">Nova senha</label><input class="form-control" type="password" name="nova_senha" minlength="8" autocomplete="new-password" required autofocus><small class="text-secondary">Use pelo menos 8 caracteres.</small></div><div class="mb-4"><label class="form-label">Confirmar nova senha</label><input class="form-control" type="password" name="confirmar_senha" minlength="8" autocomplete="new-password" required></div><button class="btn btn-danger w-100">Salvar nova senha</button></form><?php endif; ?></div></main></body></html>
