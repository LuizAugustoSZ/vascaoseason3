<?php

declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/public-layout.php';
require __DIR__ . '/includes/mercado.php';
require __DIR__ . '/includes/elenco-parser.php';
if (!account_logged_in()) {
    header('Location: login.php');
    exit;
}
$pdo = db();
mercado_garantir_estrutura($pdo);
$participanteSessao = (int)(account_participant_id() ?? 0);
$participanteSolicitado = (int)($_GET['participante_id'] ?? $_POST['participante_id'] ?? 0);
$participante = account_is_master() && $participanteSolicitado > 0
    ? $participanteSolicitado
    : $participanteSessao;
$campeonato = (int)($_GET['campeonato_id'] ?? $_POST['campeonato_id'] ?? 0);
if (!$participante || !$campeonato) {
    http_response_code(403);
    exit('Conta ou campeonato inválido.');
}
$clube = mercado_clube($pdo, $campeonato, $participante);
$erro = '';
$preview = [];
$texto = (string)($_POST['texto'] ?? '');
try {
    if ((bool)$clube['elenco_confirmado']) throw new RuntimeException('A importação em massa é exclusiva da montagem do elenco inicial.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $preview = parse_elenco_dreamteam($texto);
        if (($_POST['action'] ?? '') === 'confirmar') {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE jogadores_elenco SET ativo=0,saiu_em=NOW() WHERE campeonato_id=? AND participante_id=? AND ativo=1")->execute([$campeonato, $participante]);
            $insert = $pdo->prepare("INSERT INTO jogadores_elenco(campeonato_id,participante_id,nome,overall,posicao,grupo,ordem) VALUES(?,?,?,?,?,'banco',?)");
            foreach ($preview as $ordem => $j) $insert->execute([$campeonato, $participante, $j['nome'], $j['overall'], $j['posicao'], $ordem + 1]);
            $pdo->commit();
            header('Location: mercado.php?campeonato_id=' . $campeonato . (account_is_master() && $participante !== $participanteSessao ? '&participante_id=' . $participante : ''));
            exit;
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $erro = $e->getMessage();
}
?>
<!doctype html>
<html lang="pt-BR" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Importar elenco | Vascão S3</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/market.css">
</head>

<body><?php public_navbar('mercado'); ?><main class="container market-page"><a href="mercado.php?campeonato_id=<?= $campeonato ?><?= account_is_master() && $participante !== $participanteSessao ? '&participante_id=' . $participante : '' ?>" class="text-secondary text-decoration-none">← Voltar</a>
        <h1>IMPORTAR ELENCO</h1>
        <p class="text-secondary">Cole a lista copiada do DreamTeam. Emojis e identificadores das cartas serão ignorados.</p><?php if ($erro): ?><div class="alert alert-danger"><?= e($erro) ?></div><?php endif; ?><form method="post" class="panel p-4 mb-4"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonato ?>"><?php if (account_is_master() && $participante !== $participanteSessao): ?><input type="hidden" name="participante_id" value="<?= $participante ?>"><?php endif; ?><input type="hidden" name="action" value="preview"><label class="form-label">Lista completa do elenco</label><textarea class="form-control" name="texto" rows="14" required><?= e($texto) ?></textarea><button class="btn btn-danger mt-3">Analisar lista</button></form><?php if ($preview): ?><section class="panel p-4">
                <h2>PRÉVIA · <?= count($preview) ?> JOGADORES</h2>
                <div class="roster-grid"><?php foreach ($preview as $j): ?><article><b><?= e($j['nome']) ?></b><strong><?= $j['overall'] ?></strong><span><?= e($j['descricao']) ?> · <?= e($j['posicao']) ?></span></article><?php endforeach; ?></div>
                <form method="post" class="mt-4"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonato ?>"><?php if (account_is_master() && $participante !== $participanteSessao): ?><input type="hidden" name="participante_id" value="<?= $participante ?>"><?php endif; ?><input type="hidden" name="action" value="confirmar"><textarea class="d-none" name="texto"><?= e($texto) ?></textarea>
                    <div class="alert alert-warning">Ao confirmar, esta lista substituirá o elenco inicial atualmente cadastrado. Todos entrarão primeiro no banco para você escolher os 11 titulares.</div><button class="btn btn-success">Confirmar importação</button>
                </form>
            </section><?php endif; ?>
    </main><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
