<?php
require __DIR__ . "/includes/bootstrap.php";

if (!account_logged_in()) {
    header("Location: login.php");
    exit();
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    try {
        $senhaAtual = (string) ($_POST["senha_atual"] ?? "");
        $novaSenha = (string) ($_POST["nova_senha"] ?? "");
        $confirmacao = (string) ($_POST["confirmar_senha"] ?? "");
        $stmt = db()->prepare(
            "SELECT senha_hash FROM contas WHERE id=? AND ativo=1 LIMIT 1",
        );
        $stmt->execute([(int) $_SESSION["conta_id"]]);
        $conta = $stmt->fetch();
        if (!$conta || !password_verify($senhaAtual, $conta["senha_hash"])) {
            throw new RuntimeException(
                "A senha temporária ou atual está incorreta.",
            );
        }
        if (strlen($novaSenha) < 8) {
            throw new RuntimeException(
                "A nova senha precisa ter pelo menos 8 caracteres.",
            );
        }
        if ($novaSenha !== $confirmacao) {
            throw new RuntimeException(
                "A confirmação da nova senha não confere.",
            );
        }
        if (password_verify($novaSenha, $conta["senha_hash"])) {
            throw new RuntimeException("Escolha uma senha diferente da atual.");
        }
        $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
        db()
            ->prepare(
                "UPDATE contas SET senha_hash=?,trocar_senha=0 WHERE id=?",
            )
            ->execute([$novoHash, (int) $_SESSION["conta_id"]]);
        $_SESSION["conta_trocar_senha"] = 0;
        session_regenerate_id(true);
        audit_event("edicao", "usuarios", "Senha da conta atualizada.", ["conta_id" => (int)$_SESSION["conta_id"]]);
        header("Location: index.php");
        exit();
    } catch (Throwable $e) {
        $error =
            $e instanceof RuntimeException
            ? $e->getMessage()
            : "Não foi possível alterar a senha agora.";
    }
}
$obrigatoria = account_must_change_password();
?>
<!doctype html>
<html lang="pt-BR" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Alterar senha | Vascão S3</title>
    <link rel="icon" href="favicon.ico" sizes="any">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/branding.css?v=5">
    <script defer src="assets/js/password-toggle.js?v=<?= filemtime(
                                                            __DIR__ . "/assets/js/password-toggle.js",
                                                        ) ?>"></script>
</head>

<body class="d-flex align-items-center min-vh-100">
    <main class="container" style="max-width:520px">
        <div class="panel p-4"><span class="eyebrow"><?= $obrigatoria
                                                            ? "Primeiro acesso"
                                                            : "Segurança da conta" ?></span>
            <h1 class="font-condensed mt-3"><?= $obrigatoria
                                                ? "CRIE SUA SENHA"
                                                : "ALTERAR SENHA" ?></h1>
            <p class="text-secondary"><?= $obrigatoria
                                            ? "Para proteger sua conta, substitua a senha temporária por uma senha que somente você conheça."
                                            : "Informe sua senha atual e escolha uma nova senha." ?></p><?php if (
                                                                    $error
                                                                ): ?><div class="alert alert-danger"><?= e(
                                                                        $error,
                                                                    ) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(
                                                                                            csrf_token(),
                                                                                        ) ?>">
                <div class="mb-3"><label class="form-label">Senha temporária ou atual</label><input class="form-control" type="password" name="senha_atual" autocomplete="current-password" required autofocus></div>
                <div class="mb-3"><label class="form-label">Nova senha</label><input class="form-control" type="password" name="nova_senha" minlength="8" autocomplete="new-password" required><small class="text-secondary">Use pelo menos 8 caracteres.</small></div>
                <div class="mb-4"><label class="form-label">Confirmar nova senha</label><input class="form-control" type="password" name="confirmar_senha" minlength="8" autocomplete="new-password" required></div><button class="btn btn-danger w-100">Salvar minha nova senha</button>
            </form><?php if (
                        !$obrigatoria
                    ): ?><a class="btn btn-link text-secondary w-100 mt-2" href="index.php">Cancelar</a><?php endif; ?><a class="btn btn-link text-secondary w-100" href="logout.php">Sair da conta</a>
        </div>
    </main>
</body>

</html>
