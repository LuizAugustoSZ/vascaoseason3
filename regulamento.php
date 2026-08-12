<?php require __DIR__ . "/includes/bootstrap.php"; ?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="description" content="Regulamento oficial das competições do Vascão Season 3."><title>Regulamento | Vascão Season 3</title><link rel="icon" href="favicon.ico?v=5" sizes="any"><link rel="icon" type="image/png" href="assets/img/favicon-season3.png?v=5"><link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/branding.css?v=5"><link rel="stylesheet" href="assets/css/season3-update.css?v=<?= filemtime(
    __DIR__ . "/assets/css/season3-update.css",
) ?>"><link rel="stylesheet" href="assets/css/socials.css?v=<?= filemtime(
    __DIR__ . "/assets/css/socials.css",
) ?>"><link rel="stylesheet" href="assets/css/version-history.css?v=<?= filemtime(
    __DIR__ . "/assets/css/version-history.css",
) ?>"></head><body>
<nav class="navbar navbar-expand-lg fixed-top navbar-dark"><div class="container"><a class="navbar-brand d-flex align-items-center gap-2" href="index.php"><img class="brand-mark" src="assets/img/logo-season3.webp?v=5" alt="Vascao Season 3"><span>VASCÃO <b>S3</b></span></a><button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#rules-menu"><span class="navbar-toggler-icon"></span></button><div id="rules-menu" class="collapse navbar-collapse"><ul class="navbar-nav ms-auto align-items-lg-center"><li class="nav-item"><a class="nav-link" href="index.php#competicao">Competição</a></li><li class="nav-item"><a class="nav-link" href="index.php#participantes">Participantes</a></li><li class="nav-item"><a class="nav-link" href="index.php#artilharia">Artilharia</a></li><li class="nav-item"><a class="nav-link" href="index.php#titulos">Títulos</a></li><li class="nav-item"><a class="nav-link" href="comandos.php">Comandos</a></li><li class="nav-item"><a class="nav-link active" href="regulamento.php">Regulamento</a></li><li class="nav-item"><a class="nav-link" href="noticias.php">Notícias</a></li><li class="nav-item ms-lg-2"><?php if (
    account_logged_in() &&
    account_is_admin()
): ?><a class="btn btn-danger btn-sm px-3" href="admin/">Painel</a><?php elseif (
    account_logged_in()
): ?><a class="btn btn-danger btn-sm px-3" href="logout.php">Sair</a><?php else: ?><a class="btn btn-danger btn-sm px-3" href="login.php">Login</a><?php endif; ?></li></ul></div></div></nav>
<main class="section-pad" style="padding-top:130px;min-height:calc(100vh - 90px)"><div class="container"><span class="eyebrow">Jogo limpo</span><div class="section-title mt-4"><div><small>REGRAS DA SEASON</small><h2>REGULAMENTO</h2></div></div><p class="lead text-secondary mb-3">Consulte as regras de pontuação, partidas, comprovação e conduta das competições.</p><a class="btn btn-danger mb-5" href="noticia.php?id=8" target="_blank" rel="noopener noreferrer">Ver regulamento do Brasileirão</a><div class="row g-3">
<div class="col-md-6 col-xl-4"><article class="rule-card h-100"><b>01</b><h3>Pontuação</h3><p>Vitória: 3 pontos. Empate: 1 ponto. Derrota: 0 pontos.</p></article></div><div class="col-md-6 col-xl-4"><article class="rule-card h-100"><b>02</b><h3>Partidas</h3><p>Jogos pelo <code>/confronto</code>, dentro do prazo e com horário combinado. Uma rodada só termina quando todos os confrontos dela forem finalizados.</p></article></div><div class="col-md-6 col-xl-4"><article class="rule-card h-100"><b>03</b><h3>Comprovação</h3><p>Envie captura com participantes e placar final. Sem prova, o resultado pode não ser contabilizado.</p></article></div><div class="col-md-6 col-xl-4"><article class="rule-card h-100"><b>04</b><h3>Desempate</h3><p>Vitórias, saldo de gols, gols marcados, confronto direto, menor número de W.O. e decisão da organização.</p></article></div><div class="col-md-6 col-xl-4"><article class="rule-card h-100"><b>05</b><h3>W.O.</h3><p>O participante ausente pode perder por 3 a 0. Tentativas de contato deverão ser comprovadas.</p></article></div><div class="col-md-6 col-xl-4"><article class="rule-card h-100"><b>06</b><h3>Conduta</h3><p>Bugs, contas alternativas, resultados combinados e entrega proposital podem gerar punição ou expulsão.</p></article></div>
</div></div></main><footer><div class="container d-flex flex-wrap align-items-center justify-content-between gap-3"><span>Vascão dos Gigantes • Season 3</span><div class="footer-socials" aria-label="Redes sociais"><strong>REDES SOCIAIS</strong><a href="https://discord.gg/nkDynjHbMM" target="_blank" rel="noopener noreferrer" aria-label="Entrar no servidor do Discord" title="Discord"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19.5 5.34A16.3 16.3 0 0 0 15.44 4l-.5 1.02a15 15 0 0 0-5.88 0L8.56 4A16.5 16.5 0 0 0 4.5 5.35C1.93 9.18 1.23 12.91 1.58 16.6a16.7 16.7 0 0 0 4.98 2.51l1.2-1.65a10.6 10.6 0 0 1-1.89-.9l.46-.36c3.65 1.69 7.61 1.69 11.22 0l.47.36c-.61.36-1.25.66-1.9.9l1.2 1.65a16.6 16.6 0 0 0 4.98-2.51c.42-4.28-.72-7.97-2.8-11.26ZM8.52 14.34c-1.1 0-2-1.01-2-2.25s.88-2.25 2-2.25c1.13 0 2.02 1.02 2 2.25 0 1.24-.88 2.25-2 2.25Zm6.96 0c-1.1 0-2-1.01-2-2.25s.88-2.25 2-2.25c1.13 0 2.02 1.02 2 2.25 0 1.24-.87 2.25-2 2.25Z"/></svg></a><a href="https://www.youtube.com/@DreamBotSeason2" target="_blank" rel="noopener noreferrer" aria-label="Acessar o canal no YouTube" title="YouTube"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 0 0 .5 6.2 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 0 0 2.1-2.1A31 31 0 0 0 24 12a31 31 0 0 0-.5-5.8ZM9.6 15.6V8.4l6.3 3.6-6.3 3.6Z"/></svg></a></div><span>Projeto independente para a comunidade DreamTeam</span></div></footer><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><?php if (
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
