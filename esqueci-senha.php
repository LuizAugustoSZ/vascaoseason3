<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/password-reset.php';

if (account_logged_in()) { header('Location: index.php'); exit(); }
$sent = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Informe um e-mail válido.';
    } else {
        try {
            $deliveryStatus = password_reset_request($email);
            audit_event('recuperacao_solicitada', 'autenticacao', 'Recuperação de senha solicitada.', [
                'email_hash' => hash('sha256', $email),
                'resultado_envio' => $deliveryStatus,
            ]);
            $sent = true;
        } catch (Throwable $exception) {
            error_log('Password reset request failed: ' . $exception->getMessage());
            $error = 'Não foi possível processar o pedido agora. Tente novamente.';
        }
    }
}
?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Esqueci minha senha | Vascao S3</title><link rel="icon" href="favicon.ico" sizes="any"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__.'/assets/css/style.css') ?>"><link rel="stylesheet" href="assets/css/branding.css?v=5"></head>
<body class="d-flex align-items-center min-vh-100"><main class="container" style="max-width:460px"><a class="text-secondary text-decoration-none" href="login.php">← Voltar ao login</a><div class="panel p-4 mt-3"><span class="eyebrow">Recuperação de acesso</span><h1 class="font-condensed mt-3">ESQUECI MINHA SENHA</h1>
<?php if ($sent): ?><div class="alert alert-success">Se houver uma conta ativa com esse e-mail, enviaremos um link para redefinir a senha. Confira também a caixa de spam.</div><a class="btn btn-danger w-100" href="login.php">Voltar ao login</a>
<?php else: ?><p class="text-secondary">Informe o e-mail da sua conta. Você receberá um link válido por 1 hora.</p><?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><div class="mb-4"><label class="form-label">E-mail</label><input class="form-control" type="email" name="email" autocomplete="email" required autofocus></div><button class="btn btn-danger w-100">Enviar link de recuperação</button></form><?php endif; ?></div></main></body></html>
