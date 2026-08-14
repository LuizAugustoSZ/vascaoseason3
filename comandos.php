<?php require __DIR__ . "/includes/bootstrap.php";
require __DIR__ . "/includes/public-layout.php"; ?>
<!doctype html>
<html lang="pt-BR" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="description" content="Guia de comandos do DreamTeam para a comunidade do Vascão Season 3.">
    <title>Comandos | Vascão Season 3</title>
    <link rel="icon" href="favicon.ico?v=5" sizes="any">
    <link rel="icon" type="image/png" href="assets/img/favicon-season3.png?v=5">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/branding.css?v=5">
    <link rel="stylesheet" href="assets/css/season3-update.css?v=<?= filemtime(
                                                                        __DIR__ . "/assets/css/season3-update.css",
                                                                    ) ?>">
    <link rel="stylesheet" href="assets/css/public-states.css?v=<?= filemtime(
                                                                    __DIR__ . "/assets/css/public-states.css",
                                                                ) ?>">
    <link rel="stylesheet" href="assets/css/socials.css?v=<?= filemtime(
                                                                __DIR__ . "/assets/css/socials.css",
                                                            ) ?>">
    <link rel="stylesheet" href="assets/css/version-history.css?v=<?= filemtime(
                                                                        __DIR__ . "/assets/css/version-history.css",
                                                                    ) ?>">
    <link rel="stylesheet" href="assets/css/commands-page.css?v=<?= filemtime(
                                                                    __DIR__ . "/assets/css/commands-page.css",
                                                                ) ?>">
</head>

<body>
    <?php public_navbar('comandos'); ?>
    <main class="commands-page section-pad">
        <div class="container"><span class="eyebrow">Guia DreamTeam</span>
            <div class="section-title mt-4 mb-3"><span>/</span>
                <div><small>CONSULTA RÁPIDA</small>
                    <h2>COMANDOS</h2>
                </div>
            </div>
            <p class="commands-intro mb-4">Encontre rapidamente o comando necessário para gerenciar seu clube, elenco, mercado e partidas.</p>
            <div class="command-search mb-2"><span>/</span><input id="command-search" type="search" placeholder="Busque por /time, mercado, carreira..." autocomplete="off" autofocus></div>
            <p id="commands-count" class="commands-count mb-4"></p>
            <div class="row g-3" id="commands-grid"></div>
            <div id="commands-empty" class="public-empty d-none"><img class="empty-season-logo" src="assets/img/logo-season3.webp?v=5" alt="">
                <p>Nenhum comando corresponde à sua busca.</p>
            </div>
        </div>
    </main>
    <?php public_footer(); ?>
    <script src="https://code.jquery.com/jquery-4.0.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (
        account_is_admin()
    ): ?><script>
            window.adminSiteVersions = <?= json_encode(
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
                                        ) ?>;
        </script><?php endif; ?>
    <script src="assets/js/version-history.js?v=<?= filemtime(
                                                    __DIR__ . "/assets/js/version-history.js",
                                                ) ?>"></script>
    <script src="assets/js/commands.js?v=<?= filemtime(
                                                __DIR__ . "/assets/js/commands.js",
                                            ) ?>"></script>
</body>

</html>