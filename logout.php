<?php
// Carrega a sessão que será encerrada.
require __DIR__ . "/includes/bootstrap.php";
audit_event("logout", "autenticacao", "Logout realizado.");
auth_forget_login();
// Limpa os dados da sessão.
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(),
        "",
        time() - 42000,
        $p["path"],
        $p["domain"],
        $p["secure"],
        $p["httponly"],
    );
}
// Encerra o login e retorna para a tela de acesso.
session_destroy();
header("Location: login.php");
exit();
