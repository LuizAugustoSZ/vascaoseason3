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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $preview = parse_elenco_dreamteam($texto);
        if (($_POST['action'] ?? '') === 'confirmar') {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE jogadores_elenco SET ativo=0,saiu_em=NOW() WHERE campeonato_id=? AND participante_id=? AND ativo=1")->execute([$campeonato, $participante]);
            $insert = $pdo->prepare("INSERT INTO jogadores_elenco(campeonato_id,participante_id,nome,overall,posicao,grupo,ordem) VALUES(?,?,?,?,?,'banco',?)");
            foreach ($preview as $ordem => $j) $insert->execute([$campeonato, $participante, $j['nome'], $j['overall'], $j['posicao'], $ordem + 1]);
            mercado_ordenar_elenco($pdo, $campeonato, $participante);
            $pdo->prepare("UPDATE clubes_campeonato SET elenco_confirmado=0 WHERE campeonato_id=? AND participante_id=?")->execute([$campeonato, $participante]);
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
        <section class="panel p-4 mb-4 roster-import-tutorial">
            <div class="roster-import-guide">
                <span class="eyebrow">Como copiar corretamente</span>
                <h2>DO DISCORD PARA O SITE</h2>
                <ol>
                    <li>Use o comando <strong>.elenco</strong> no Discord.</li>
                    <li>Selecione e copie titulares e reservas que realmente fazem parte do seu time, incluindo nome, OVR e posição, como na imagem.</li>
                    <li>Cole todo o conteúdo no campo abaixo e clique em <strong>Analisar lista</strong>.</li>
                    <li>Confira a prévia. Todos entram primeiro no banco; depois você escolhe os 11 titulares.</li>
                </ol>
                <div class="alert alert-warning roster-import-tip"><strong>Recomendação:</strong> não importe cartas que você não usa. Elas só deixam a organização do elenco mais demorada. Se o jogador faz parte das suas substituições, inclua-o para ficar no banco de reservas.</div>
                <p>Negrito, emojis e identificadores das cartas são removidos automaticamente. O elenco só será substituído quando você confirmar.</p>
            </div>
            <figure><img src="assets/img/tutorial-importar-elenco.png" alt="Exemplo de seleção do elenco no Discord para copiar e colar"><figcaption>Copie a listagem dos jogadores conforme a área selecionada.</figcaption></figure>
        </section>
        <?php if ($erro): ?><div class="alert alert-danger"><?= e($erro) ?></div><?php endif; ?><form method="post" class="panel p-4 mb-4"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonato ?>"><?php if (account_is_master() && $participante !== $participanteSessao): ?><input type="hidden" name="participante_id" value="<?= $participante ?>"><?php endif; ?><input type="hidden" name="action" value="preview"><label class="form-label">Lista completa do elenco</label><textarea class="form-control" name="texto" rows="14" placeholder="Cole aqui a lista copiada do Discord..." required><?= e($texto) ?></textarea><button class="btn btn-danger mt-3">Analisar lista</button></form><?php if ($preview): ?><section class="panel p-4">
                <h2>PRÉVIA · <?= count($preview) ?> JOGADORES</h2>
                <div class="roster-grid"><?php foreach ($preview as $j): ?><article><b><?= e($j['nome']) ?></b><strong><?= $j['overall'] ?></strong><span><?= e($j['descricao']) ?> · <?= e($j['posicao']) ?></span></article><?php endforeach; ?></div>
                <form method="post" class="mt-4"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonato ?>"><?php if (account_is_master() && $participante !== $participanteSessao): ?><input type="hidden" name="participante_id" value="<?= $participante ?>"><?php endif; ?><input type="hidden" name="action" value="confirmar"><textarea class="d-none" name="texto"><?= e($texto) ?></textarea>
                    <div class="alert alert-warning"><strong>Atenção:</strong> ao confirmar, esta lista substituirá todo o elenco atualmente cadastrado. Todos entrarão primeiro no banco para você escolher novamente os 11 titulares.</div><button class="btn btn-success">Confirmar importação</button>
                </form>
            </section><?php endif; ?>
    </main><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
