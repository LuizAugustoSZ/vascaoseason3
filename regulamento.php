<?php require __DIR__ . "/includes/bootstrap.php";
require __DIR__ . "/includes/public-layout.php"; ?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="description" content="Regulamento oficial das competições do Vascão Season 3."><title>Regulamento | Vascão Season 3</title><link rel="icon" href="favicon.ico?v=5" sizes="any"><link rel="icon" type="image/png" href="assets/img/favicon-season3.png?v=5"><link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/branding.css?v=5"><link rel="stylesheet" href="assets/css/season3-update.css?v=<?= filemtime(
    __DIR__ . "/assets/css/season3-update.css",
) ?>"><link rel="stylesheet" href="assets/css/socials.css?v=<?= filemtime(
    __DIR__ . "/assets/css/socials.css",
) ?>"><link rel="stylesheet" href="assets/css/version-history.css?v=<?= filemtime(
    __DIR__ . "/assets/css/version-history.css",
) ?>"></head><body>
<?php public_navbar('regulamento'); ?>
<main class="section-pad" style="padding-top:130px;min-height:calc(100vh - 90px)"><div class="container"><span class="eyebrow">Jogo limpo</span><div class="section-title mt-4"><div><small>REGRAS DA SEASON</small><h2>REGULAMENTO</h2></div></div><p class="lead text-secondary mb-3">Consulte as regras de pontuação, partidas, comprovação e conduta das competições.</p><a class="btn btn-danger mb-5" href="noticia.php?id=8" target="_blank" rel="noopener noreferrer">Ver regulamento do Brasileirão</a><div class="row g-3">
<div class="col-md-6 col-xl-4"><article class="rule-card h-100"><b>01</b><h3>Pontuação</h3><p>Vitória: 3 pontos. Empate: 1 ponto. Derrota: 0 pontos.</p></article></div><div class="col-md-6 col-xl-4"><article class="rule-card h-100"><b>02</b><h3>Partidas</h3><p>Jogos pelo <code>/confronto</code>, dentro do prazo e com horário combinado. Uma rodada só termina quando todos os confrontos dela forem finalizados.</p></article></div><div class="col-md-6 col-xl-4"><article class="rule-card h-100"><b>03</b><h3>Comprovação</h3><p>Envie captura com participantes e placar final. Sem prova, o resultado pode não ser contabilizado.</p></article></div><div class="col-md-6 col-xl-4"><article class="rule-card h-100"><b>04</b><h3>Desempate</h3><p>Vitórias, saldo de gols, gols marcados, confronto direto, menor número de W.O. e decisão da organização.</p></article></div><div class="col-md-6 col-xl-4"><article class="rule-card h-100"><b>05</b><h3>W.O.</h3><p>O participante ausente pode perder por 3 a 0. Tentativas de contato deverão ser comprovadas.</p></article></div><div class="col-md-6 col-xl-4"><article class="rule-card h-100"><b>06</b><h3>Conduta</h3><p>Bugs, contas alternativas, resultados combinados e entrega proposital podem gerar punição ou expulsão.</p></article></div>
</div></div></main><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><?php if (
    account_is_admin()
): ?><script>window.adminSiteVersions=<?= json_encode(
    [
        [
            "a1.8",
            "Notícias integradas ao painel principal para Admin Master e Editor da Competição.",
        ],
        [
            "a1.7",
            "Ações sem recarregamento, preenchimento inteligente de artilheiros e paginação geral do painel.",
        ],
        [
            "a1.6",
            "Gols do mata-mata, terceiro lugar automático, busca avançada de artilheiros e configurações do site.",
        ],
        [
            "a1.5",
            "Gols individuais por partida com sincronização segura da artilharia.",
        ],
        ["a1.4", "Gestão e edição de artilheiros organizadas por campeonato."],
        ["a1.3", "Histórico de versões separado por nível de acesso."],
        ["a1.2", "Gestão de campeonatos incorporada ao painel principal."],
        [
            "a1.1",
            "Cadastro e controle administrativo de contas com permissão eh_admin.",
        ],
        ["a1.0", "Autenticação migrada para a estrutura escalável de contas."],
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
) ?>;</script><?php endif; ?><script src="assets/js/version-history.js?v=<?= filemtime(
    __DIR__ . "/assets/js/version-history.js",
) ?>"></script></body></html>
