<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (account_logged_in()) {
    header('Location: index.php');
    exit;
}

$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $nome=trim((string)($_POST['nome'] ?? ''));
        $email=mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $senha=(string)($_POST['senha'] ?? '');
        $confirmacao=(string)($_POST['confirmar_senha'] ?? '');
        if (mb_strlen($nome)<2 || mb_strlen($nome)>120) throw new RuntimeException('Informe seu nome com até 120 caracteres.');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($email)>190) throw new RuntimeException('Informe um e-mail válido.');
        if (strlen($senha)<8) throw new RuntimeException('A senha precisa ter pelo menos 8 caracteres.');
        if ($senha!==$confirmacao) throw new RuntimeException('A confirmação da senha não confere.');
        $pdo=db();
        $exists=$pdo->prepare('SELECT id FROM contas WHERE email=? LIMIT 1');
        $exists->execute([$email]);
        if ($exists->fetch()) throw new RuntimeException('Já existe uma conta com este e-mail.');
        $stmt=$pdo->prepare('INSERT INTO contas(participante_id,nome,email,senha_hash,eh_admin,trocar_senha,ativo) VALUES(NULL,?,?,?,0,0,1)');
        $stmt->execute([$nome,$email,password_hash($senha,PASSWORD_DEFAULT)]);
        session_regenerate_id(true);
        $_SESSION['conta_id']=(int)$pdo->lastInsertId();
        $_SESSION['conta_nome']=$nome;
        $_SESSION['conta_email']=$email;
        $_SESSION['conta_eh_admin']=0;
        $_SESSION['conta_trocar_senha']=0;
        $_SESSION['participante_id']=null;
        header('Location: index.php?conta=criada');
        exit;
    } catch (Throwable $e) {
        $error=$e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível criar a conta agora.';
    }
}
?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Criar conta | Vascão S3</title><link rel="icon" href="favicon.ico" sizes="any"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/branding.css?v=5"></head>
<body class="d-flex align-items-center min-vh-100 py-5"><main class="container" style="max-width:520px"><a class="text-secondary text-decoration-none" href="index.php">← Voltar ao site</a><div class="panel p-4 mt-3"><span class="eyebrow">Vascão Season 3</span><h1 class="font-condensed mt-3">CRIAR CONTA</h1><p class="text-secondary">Crie seu acesso. A associação ao seu time será feita pela administração depois da conferência.</p><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label class="form-label">Nome</label><input class="form-control mb-3" name="nome" maxlength="120" autocomplete="name" value="<?=e($_POST['nome'] ?? '')?>" required autofocus><label class="form-label">E-mail</label><input class="form-control mb-3" type="email" name="email" maxlength="190" autocomplete="email" value="<?=e($_POST['email'] ?? '')?>" required><div class="row g-3"><div class="col-md-6"><label class="form-label">Senha</label><input class="form-control" type="password" name="senha" minlength="8" autocomplete="new-password" required></div><div class="col-md-6"><label class="form-label">Confirmar senha</label><input class="form-control" type="password" name="confirmar_senha" minlength="8" autocomplete="new-password" required></div></div><button class="btn btn-danger w-100 mt-4">Criar minha conta</button></form><small class="text-secondary d-block mt-3">Já possui acesso? <a href="login.php">Entrar</a></small></div></main></body></html>
