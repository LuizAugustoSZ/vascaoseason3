<?php

declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/public-layout.php';
require __DIR__ . '/includes/elenco-geral.php';

if (!account_logged_in()) {
    header('Location: login.php');
    exit;
}
if (account_must_change_password()) {
    header('Location: trocar-senha.php');
    exit;
}

$participantId = (int)(account_participant_id() ?? 0);
$requestedParticipantId = (int)($_GET['participante_id'] ?? 0);
if (account_is_master() && $requestedParticipantId > 0) $participantId = $requestedParticipantId;
if ($participantId < 1) {
    http_response_code(403);
    exit('Sua conta precisa estar vinculada a um time.');
}

$pdo = db();
try {
    elenco_geral_garantir_estrutura($pdo);
    $teamStmt = $pdo->prepare('SELECT time_nome,nome FROM participantes WHERE id=? AND ativo=1 LIMIT 1');
    $teamStmt->execute([$participantId]);
    $time = $teamStmt->fetch();
    if (!$time) throw new RuntimeException('Clube não encontrado.');
    $jogadores = elenco_geral_do_clube($pdo, $participantId);
} catch (Throwable $error) {
    http_response_code(503);
    exit('O Elenco Geral está sendo preparado na homologação. Tente novamente em instantes.');
}

$porPosicao = [];
foreach ($jogadores as $jogador) $porPosicao[$jogador['posicao']][] = $jogador;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Elenco Geral | <?= e((string)$time['time_nome']) ?></title>
    <link rel="icon" href="favicon.ico" sizes="any">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/branding.css?v=5">
    <link rel="stylesheet" href="assets/css/elenco-geral.css?v=<?= filemtime(__DIR__ . '/assets/css/elenco-geral.css') ?>">
</head>
<body>
<?php public_navbar('elenco-geral'); ?>
<main class="general-roster-page"><div class="container">
    <header class="general-roster-hero"><div><span class="eyebrow">Patrimônio do clube</span><h1>ELENCO GERAL</h1><p><?= e((string)$time['time_nome']) ?> · <?= count($jogadores) ?> jogador<?= count($jogadores) === 1 ? '' : 'es' ?></p></div><a class="btn btn-danger" href="mercado.php">Abrir mercado e inscrições</a></header>
    <div class="alert alert-info general-roster-info"><strong>Fonte oficial do clube.</strong> Contratações futuras entram aqui. Cada competição terá sua própria inscrição com 11 titulares e até 15 reservas, sem alterar elencos que já estejam congelados.</div>
    <?php if ($jogadores): ?><section class="general-roster-groups">
        <?php foreach ($porPosicao as $posicao => $lista): ?><article class="panel general-position-group"><header><h2><?= e((string)$posicao) ?></h2><span><?= count($lista) ?> jogador<?= count($lista) === 1 ? '' : 'es' ?></span></header><div class="general-player-grid">
            <?php foreach ($lista as $jogador): ?><div class="general-player-card"><span><?= e((string)$jogador['posicao']) ?></span><strong><?= e((string)$jogador['nome']) ?></strong><b><?= (int)$jogador['overall'] ?> OVR</b></div><?php endforeach; ?>
        </div></article><?php endforeach; ?>
    </section><?php else: ?><section class="panel p-5 text-center"><h2>ELENCO AINDA VAZIO</h2><p class="text-secondary mb-0">Os jogadores aparecerão aqui quando forem importados ou contratados.</p></section><?php endif; ?>
</div></main>
<?php public_footer(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
