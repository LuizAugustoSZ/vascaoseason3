<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/public-layout.php';
require __DIR__ . '/includes/mercado.php';

$pdo = db();
$transferencias = $pdo->query("SELECT m.*,c.nome campeonato,p.time_nome clube,p.sigla clube_sigla,p.escudo_url clube_escudo
    FROM movimentacoes_elenco m
    JOIN campeonatos c ON c.id=m.campeonato_id
    JOIN participantes p ON p.id=m.participante_id
    WHERE c.ativo=1 AND p.ativo=1
    ORDER BY m.criado_em DESC,m.id DESC")->fetchAll();
$campeonatos = [];
$clubes = [];
foreach ($transferencias as $movimento) {
    $campeonatos[(int)$movimento['campeonato_id']] = (string)$movimento['campeonato'];
    $clubes[(int)$movimento['participante_id']] = (string)$movimento['clube'];
}
asort($campeonatos, SORT_NATURAL | SORT_FLAG_CASE);
asort($clubes, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!doctype html>
<html lang="pt-BR" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="description" content="Mercado de transferências dos campeonatos do Vascão Season 3.">
    <title>Mercado de Transferências | Vascão Season 3</title>
    <link rel="icon" href="favicon.ico?v=5" sizes="any">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/branding.css?v=5">
    <link rel="stylesheet" href="assets/css/transfer-market-page.css?v=<?= filemtime(__DIR__ . '/assets/css/transfer-market-page.css') ?>">
</head>
<body>
<?php public_navbar('transferencias'); ?>
<main class="transfer-market-page section-pad" data-transfer-market data-items-per-page="12">
    <div class="container">
        <span class="eyebrow">Movimentações oficiais</span>
        <div class="section-title mt-4 mb-3"><span>⇄</span><div><small>COMPRAS, PACKS E VENDAS</small><h2>MERCADO DE TRANSFERÊNCIAS</h2></div></div>
        <p class="transfer-market-intro">Acompanhe todas as chegadas e saídas registradas pelos clubes, com valores, origem e horário de cada movimentação.</p>

        <section class="transfer-market-filters" aria-label="Filtros do mercado">
            <div><label for="transfer-search">Jogador</label><input class="form-control" id="transfer-search" type="search" placeholder="Buscar jogador..." autocomplete="off"></div>
            <div><label for="transfer-championship">Campeonato</label><select class="form-select" id="transfer-championship"><option value="all">Todos</option><?php foreach ($campeonatos as $id => $nome): ?><option value="<?= $id ?>"><?= e($nome) ?></option><?php endforeach; ?></select></div>
            <div><label for="transfer-club">Clube</label><select class="form-select" id="transfer-club"><option value="all">Todos</option><?php foreach ($clubes as $id => $nome): ?><option value="<?= $id ?>"><?= e($nome) ?></option><?php endforeach; ?></select></div>
            <div><label for="transfer-type">Movimentação</label><select class="form-select" id="transfer-type"><option value="all">Todas</option><option value="compra">Contratações</option><option value="venda">Vendas</option></select></div>
        </section>

        <div class="transfer-market-meta"><strong><span data-transfer-count><?= count($transferencias) ?></span> movimentações</strong><button class="btn btn-sm btn-outline-light" type="button" data-clear-transfer-filters>Limpar filtros</button></div>
        <section class="transfer-market-grid">
            <?php foreach ($transferencias as $movimento): ?>
                <article class="transfer-market-card" data-transfer-item data-type="<?= e($movimento['tipo']) ?>" data-championship="<?= (int)$movimento['campeonato_id'] ?>" data-club="<?= (int)$movimento['participante_id'] ?>" data-player="<?= e(mb_strtolower((string)$movimento['jogador_nome'], 'UTF-8')) ?>">
                    <div class="transfer-card-top"><span class="transfer-card-kind <?= $movimento['tipo'] === 'venda' ? 'is-sale' : 'is-purchase' ?>"><?= e(mercado_rotulo_origem($movimento)) ?></span><time datetime="<?= e(date('c', strtotime((string)$movimento['criado_em']))) ?>"><?= e(format_datetime_br((string)$movimento['criado_em'])) ?></time></div>
                    <strong class="transfer-player-name"><?= e($movimento['jogador_nome']) ?></strong>
                    <p><?= (int)$movimento['jogador_overall'] ?> OVR · <?= e($movimento['jogador_posicao']) ?><?= !empty($movimento['origem_detalhe']) ? ' · ' . e($movimento['origem_detalhe']) : '' ?></p>
                    <div class="transfer-card-club"><a class="transfer-club-shield" href="time.php?id=<?= (int)$movimento['participante_id'] ?>" aria-label="Abrir página do <?= e($movimento['clube']) ?>"><?php if (!empty($movimento['clube_escudo'])): ?><img src="<?= e($movimento['clube_escudo']) ?>" alt="Escudo do <?= e($movimento['clube']) ?>" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><span hidden><?= e($movimento['clube_sigla'] ?: mb_substr((string)$movimento['clube'], 0, 3)) ?></span><?php else: ?><span><?= e($movimento['clube_sigla'] ?: mb_substr((string)$movimento['clube'], 0, 3)) ?></span><?php endif; ?></a><div><a class="transfer-club-name" href="time.php?id=<?= (int)$movimento['participante_id'] ?>"><?= e($movimento['clube']) ?></a><a class="transfer-championship-link" href="index.php?campeonato_id=<?= (int)$movimento['campeonato_id'] ?>#competicao"><?= e($movimento['campeonato']) ?></a></div></div>
                    <footer><span><?= $movimento['tipo'] === 'venda' ? 'Valor recebido' : 'Custo registrado' ?></span><strong><?= e(mercado_valor_movimento($movimento)) ?></strong></footer>
                </article>
            <?php endforeach; ?>
        </section>
        <div class="public-empty transfer-market-empty<?= $transferencias ? ' d-none' : '' ?>" data-transfer-empty><p>Nenhuma transferência encontrada.</p></div>
        <nav class="transfer-market-pages" aria-label="Páginas do mercado"></nav>
    </div>
</main>
<?php public_footer(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/transfer-market-page.js?v=<?= filemtime(__DIR__ . '/assets/js/transfer-market-page.js') ?>"></script>
</body>
</html>
