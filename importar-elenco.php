<?php

declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/public-layout.php';
require __DIR__ . '/includes/mercado.php';
require __DIR__ . '/includes/elenco-geral.php';
require __DIR__ . '/includes/elenco-parser.php';
if (!account_logged_in()) {
    header('Location: login.php');
    exit;
}
$pdo = db();
elenco_geral_garantir_estrutura($pdo);
$participanteSessao = (int)(account_participant_id() ?? 0);
$participanteSolicitado = (int)($_GET['participante_id'] ?? $_POST['participante_id'] ?? 0);
$participante = account_is_master() && $participanteSolicitado > 0
    ? $participanteSolicitado
    : $participanteSessao;
if (!$participante) {
    http_response_code(403);
    exit('Sua conta precisa estar vinculada a um time.');
}
$erro = '';
$preview = [];
$texto = (string)($_POST['texto'] ?? '');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $preview = parse_elenco_dreamteam($texto);
        if (($_POST['action'] ?? '') === 'confirmar') {
            $pdo->beginTransaction();
            $clube = elenco_geral_clube($pdo, $participante, true);
            $insert = $pdo->prepare('INSERT IGNORE INTO jogadores_gerais(participante_id,nome,overall,posicao) VALUES(?,?,?,?)');
            $history = $pdo->prepare("INSERT INTO movimentacoes_elenco_geral(participante_id,jogador_geral_id,tipo,origem,jogador_nome,jogador_overall,jogador_posicao,valor,saldo_anterior,saldo_posterior,conta_id) VALUES(?,?,'compra','importacao',?,?,?,0,?,?,?)");
            $novos = 0;
            foreach ($preview as $j) {
                $insert->execute([$participante, $j['nome'], $j['overall'], $j['posicao']]);
                if ($insert->rowCount()) {
                    $history->execute([$participante, (int)$pdo->lastInsertId(), $j['nome'], $j['overall'], $j['posicao'], $clube['saldo'], $clube['saldo'], (int)$_SESSION['conta_id']]);
                    $novos++;
                }
            }
            $pdo->commit();
            $_SESSION['elenco_geral_mensagem'] = "$novos jogador(es) novo(s) importado(s). Os que já existiam foram preservados.";
            header('Location: elenco-geral.php' . (account_is_master() && $participante !== $participanteSessao ? '?participante_id=' . $participante : ''));
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

<body><?php public_navbar('elenco-geral'); ?><main class="container market-page"><a href="elenco-geral.php<?= account_is_master() && $participante !== $participanteSessao ? '?participante_id=' . $participante : '' ?>" class="text-secondary text-decoration-none">← Voltar ao Elenco Geral</a>
        <h1>IMPORTAR ELENCO GERAL</h1>
        <section class="panel p-4 mb-4 roster-import-tutorial">
            <div class="roster-import-guide">
                <span class="eyebrow">Como copiar corretamente</span>
                <h2>DO DISCORD PARA O SITE</h2>
                <ol>
                    <li>Use o comando <strong>..elenco</strong> ou <strong>/elenco</strong> no Discord.</li>
                    <li>Selecione e copie titulares e reservas que realmente fazem parte do seu time, incluindo nome, OVR e posição, como na imagem.</li>
                    <li>Cole todo o conteúdo no campo abaixo e clique em <strong>Analisar lista</strong>.</li>
                    <li>Confira a prévia. A confirmação adiciona somente os jogadores que ainda não estão no Geral.</li>
                </ol>
                <div class="alert alert-info roster-import-tip"><strong>Importante:</strong> nenhum jogador atual e nenhuma inscrição em competição serão apagados.</div>
                <p>Negrito, emojis e identificadores das cartas são removidos automaticamente.</p>
            </div>
            <figure><img src="assets/img/tutorial-importar-elenco.png" alt="Exemplo de seleção do elenco no Discord para copiar e colar"><figcaption>Copie a listagem dos jogadores conforme a área selecionada.</figcaption></figure>
        </section>
        <?php if ($erro): ?><div class="alert alert-danger"><?= e($erro) ?></div><?php endif; ?><form method="post" class="panel p-4 mb-4"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><?php if (account_is_master() && $participante !== $participanteSessao): ?><input type="hidden" name="participante_id" value="<?= $participante ?>"><?php endif; ?><input type="hidden" name="action" value="preview"><label class="form-label">Lista completa do elenco</label><textarea class="form-control" name="texto" rows="14" placeholder="Cole aqui a lista copiada do Discord..." required><?= e($texto) ?></textarea><button class="btn btn-danger mt-3">Analisar lista</button></form><?php if ($preview): ?><section class="panel p-4">
                <h2>PRÉVIA · <?= count($preview) ?> JOGADORES</h2>
                <div class="roster-grid"><?php foreach ($preview as $j): ?><article><b><?= e($j['nome']) ?></b><strong><?= $j['overall'] ?></strong><span><?= e($j['descricao']) ?> · <?= e($j['posicao']) ?></span></article><?php endforeach; ?></div>
                <form method="post" class="mt-4"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><?php if (account_is_master() && $participante !== $participanteSessao): ?><input type="hidden" name="participante_id" value="<?= $participante ?>"><?php endif; ?><input type="hidden" name="action" value="confirmar"><textarea class="d-none" name="texto"><?= e($texto) ?></textarea>
                    <div class="alert alert-info"><strong>Importação segura:</strong> jogadores repetidos serão ignorados e o elenco atual será preservado.</div><button class="btn btn-success">Adicionar ao Elenco Geral</button>
                </form>
            </section><?php endif; ?>
    </main><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
