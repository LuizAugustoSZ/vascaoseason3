<?php
// Carrega a sessão e as funções de segurança.
require __DIR__ . "/includes/bootstrap.php";
// Encaminha uma conta que já está autenticada.
if (account_logged_in()) {
    header(
        "Location: " .
            (account_must_change_password()
                ? "trocar-senha.php"
                : (account_is_admin()
                    ? "admin/"
                    : "index.php")),
    );
    exit();
}
$error = "";
// Valida o formulário de acesso.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    try {
        // Procura qualquer conta ativa pelo e-mail informado.
        $email = mb_strtolower(trim((string) ($_POST["email"] ?? "")));
        $stmt = db()->prepare(
            "SELECT id, participante_id, nome, email, senha_hash, eh_admin, trocar_senha FROM contas WHERE email=? AND ativo=1 LIMIT 1",
        );
        $stmt->execute([$email]);
        $conta = $stmt->fetch();
        // Compara a senha informada com o hash do banco.
        if (
            $conta &&
            password_verify(
                (string) ($_POST["senha"] ?? ""),
                $conta["senha_hash"],
            )
        ) {
            session_regenerate_id(true);
            $_SESSION["conta_id"] = (int) $conta["id"];
            $_SESSION["conta_nome"] = $conta["nome"];
            $_SESSION["conta_email"] = $conta["email"];
            $_SESSION["conta_eh_admin"] = (int) $conta["eh_admin"];
            $_SESSION["conta_trocar_senha"] = (int) $conta["trocar_senha"];
            $_SESSION["participante_id"] =
                $conta["participante_id"] === null
                ? null
                : (int) $conta["participante_id"];
            db()
                ->prepare("UPDATE contas SET ultimo_acesso_em=NOW() WHERE id=?")
                ->execute([(int) $conta["id"]]);
            header(
                "Location: " .
                    ((int) $conta["trocar_senha"] === 1
                        ? "trocar-senha.php"
                        : ((int) $conta["eh_admin"] > 0
                            ? "admin/"
                            : "index.php")),
            );
            exit();
        }
        $error = "E-mail ou senha inválidos.";
    } catch (Throwable $e) {
        $error = "Não foi possível conectar ao banco.";
    }
}
?>
<!doctype html>
<html lang="pt-BR" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login | Vascão S3</title>
    <link rel="icon" href="favicon.ico" sizes="any">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/branding.css?v=5">
    <script defer src="assets/js/password-toggle.js?v=<?= filemtime(
                                                            __DIR__ . "/assets/js/password-toggle.js",
                                                        ) ?>"></script>
</head>

<body class="d-flex align-items-center min-vh-100">
    <main class="container" style="max-width:460px"><a class="text-secondary text-decoration-none" href="index.php">← Voltar ao site</a>
        <div class="panel p-4 mt-3"><span class="eyebrow">Área de acesso</span>
            <h1 class="font-condensed mt-3">ENTRAR</h1>
            <p class="text-secondary">Use sua conta para acessar os recursos disponíveis no Vascão S3.</p><?php if (
                                                                                                                $error
                                                                                                            ): ?><div class="alert alert-danger"><?= e(
                                                                                                                    $error,
                                                                                                                ) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(
                                                                                            csrf_token(),
                                                                                        ) ?>">
                <div class="mb-3"><label class="form-label">E-mail</label><input class="form-control" type="email" name="email" autocomplete="email" required autofocus></div>
                <div class="mb-4"><label class="form-label">Senha</label><input class="form-control" type="password" name="senha" autocomplete="current-password" required></div><button class="btn btn-danger w-100">Entrar</button>
            </form><small class="text-secondary d-block mt-3">Ainda não possui acesso? <a href="cadastro.php">Criar conta</a></small>
        </div>
    </main>
</body>

</html>