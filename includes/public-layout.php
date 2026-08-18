<?php

declare(strict_types=1);

function public_site_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        "footer_nome" => "Vascão dos Gigantes • Season 3",
        "footer_projeto" => "Projeto independente para a comunidade DreamTeam",
        "discord_url" => "https://discord.gg/nkDynjHbMM",
        "youtube_url" => "https://www.youtube.com/@DreamBotSeason2",
        "ordem_secoes" => "noticias,competicao,participantes,artilharia,titulos,midia",
    ];

    try {
        foreach (db()->query("SELECT chave,valor FROM configuracoes_site")->fetchAll() as $row) {
            $config[$row["chave"]] = $row["valor"];
        }
    } catch (Throwable $ignored) {
    }

    return $config;
}

function public_navbar(string $active = "", bool $onLandingPage = false): void
{
    $home = $onLandingPage ? "" : "index.php";
    $sectionLinks = [
        "noticias" => ["noticias.php", "Notícias"],
        "competicao" => [$home . "#competicao", "Competição"],
        "participantes" => [$home . "#participantes", "Participantes"],
        "artilharia" => [$home . "#artilharia", "Jogadores"],
        "titulos" => ["titulos.php", "Títulos"],
    ];
    $configuredOrder = array_filter(array_map(
        'trim',
        explode(',', (string)(public_site_config()['ordem_secoes'] ?? '')),
    ));
    $links = [];
    foreach (array_merge($configuredOrder, array_keys($sectionLinks)) as $key) {
        if (isset($sectionLinks[$key]) && !isset($links[$key])) $links[$key] = $sectionLinks[$key];
    }
    $links += [
        "transferencias" => ["mercado-transferencias.php", "Mercado"],
        "comandos" => ["comandos.php", "Comandos"],
        "regulamento" => ["regulamento.php", "Regulamento"],
    ];
    $participantId = account_logged_in() ? (int)(account_participant_id() ?? 0) : 0;
    $teamNavLabel = 'Time';
    if ($participantId > 0) {
        try {
            $teamLabelStmt = db()->prepare("SELECT time_nome FROM participantes WHERE id=? AND ativo=1 LIMIT 1");
            $teamLabelStmt->execute([$participantId]);
            $teamNavLabel = (string)($teamLabelStmt->fetchColumn() ?: 'Time');
        } catch (Throwable $ignored) {
        }
    }
?>
    <div class="site-loading-screen" role="status" aria-live="polite" aria-label="Carregando página">
        <img src="assets/img/logo-season3.webp?v=5" alt="" aria-hidden="true">
        <span class="site-loading-spinner"></span>
        <strong>CARREGANDO</strong>
    </div>
    <nav class="navbar navbar-expand-lg fixed-top navbar-dark">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?= $onLandingPage ? "#inicio" : "index.php" ?>"><img class="brand-mark" src="assets/img/logo-season3.webp?v=5" alt="Vascão Season 3"><span>VASCÃO <b>S3</b></span></a>
            <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#menu" aria-controls="menu" aria-expanded="false" aria-label="Abrir menu"><span class="navbar-toggler-icon"></span></button>
            <div id="menu" class="collapse navbar-collapse">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <?php foreach ($links as $key => [$href, $label]): ?>
                        <li class="nav-item"><a class="nav-link<?= $active === $key ? " active" : "" ?>" href="<?= e($href) ?>"><?= e($label) ?></a></li>
                    <?php endforeach; ?>
                    <?php if ($participantId > 0): ?>
                        <li class="nav-item dropdown team-nav-dropdown">
                            <a class="nav-link dropdown-toggle<?= in_array($active, ['time', 'mercado'], true) ? ' active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><?= e($teamNavLabel) ?></a>
                            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
                                <li><a class="dropdown-item" href="time.php?id=<?= $participantId ?>">Página do time</a></li>
                                <li><a class="dropdown-item" href="mercado.php">Transferências e escalação</a></li>
                            </ul>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item ms-lg-2"><?php if (account_logged_in() && account_is_admin()): ?><a class="btn btn-danger btn-sm px-3" href="admin/">Painel</a><?php elseif (account_logged_in()): ?><a class="btn btn-danger btn-sm px-3" href="logout.php">Sair</a><?php else: ?><a class="btn btn-danger btn-sm px-3" href="login.php">Entrar / cadastrar</a><?php endif; ?></li>
                </ul>
            </div>
        </div>
    </nav>
<?php
}

function public_footer(): void
{
    $config = public_site_config();
?>
    <footer>
        <div class="container d-flex flex-wrap align-items-center justify-content-between gap-3"><span><?= e($config["footer_nome"]) ?></span>
            <div class="footer-socials" aria-label="Redes sociais"><strong>REDES SOCIAIS</strong><a href="<?= e($config["discord_url"]) ?>" target="_blank" rel="noopener noreferrer" aria-label="Entrar no servidor do Discord" title="Discord"><svg viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="currentColor" d="M19.5 5.34A16.3 16.3 0 0 0 15.44 4l-.5 1.02a15 15 0 0 0-5.88 0L8.56 4A16.5 16.5 0 0 0 4.5 5.35C1.93 9.18 1.23 12.91 1.58 16.6a16.7 16.7 0 0 0 4.98 2.51l1.2-1.65a10.6 10.6 0 0 1-1.89-.9l.46-.36c3.65 1.69 7.61 1.69 11.22 0l.47.36c-.61.36-1.25.66-1.9.9l1.2 1.65a16.6 16.6 0 0 0 4.98-2.51c.42-4.28-.72-7.97-2.8-11.26ZM8.52 14.34c-1.1 0-2-1.01-2-2.25s.88-2.25 2-2.25c1.13 0 2.02 1.02 2 2.25 0 1.24-.88 2.25-2 2.25Zm6.96 0c-1.1 0-2-1.01-2-2.25s.88-2.25 2-2.25c1.13 0 2.02 1.02 2 2.25 0 1.24-.87 2.25-2 2.25Z" />
                    </svg></a><a href="<?= e($config["youtube_url"]) ?>" target="_blank" rel="noopener noreferrer" aria-label="Acessar o canal no YouTube" title="YouTube"><svg viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="currentColor" d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 0 0 .5 6.2 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 0 0 2.1-2.1A31 31 0 0 0 24 12a31 31 0 0 0-.5-5.8ZM9.6 15.6V8.4l6.3 3.6-6.3 3.6Z" />
                    </svg></a></div><span><?= e($config["footer_projeto"]) ?></span>
        </div>
    </footer>
    <link rel="stylesheet" href="assets/css/socials.css?v=<?= filemtime(__DIR__ . '/../assets/css/socials.css') ?>">
    <link rel="stylesheet" href="assets/css/version-history.css?v=<?= filemtime(__DIR__ . '/../assets/css/version-history.css') ?>">
    <script defer src="assets/js/version-history.js?v=<?= filemtime(__DIR__ . '/../assets/js/version-history.js') ?>"></script>
    <div class="modal fade match-details-modal" id="match-details-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title font-condensed">DETALHES DA PARTIDA</h2><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div id="match-details-body" class="modal-body"></div>
            </div>
        </div>
    </div>
    <div class="modal fade compact-stats-modal" id="player-stats-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><small>Histórico individual</small><h2 class="modal-title" id="player-stats-title">Jogador</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body" id="player-stats-body"></div></div></div></div>
    <link rel="stylesheet" href="assets/css/player-details.css?v=<?= filemtime(__DIR__ . '/../assets/css/player-details.css') ?>">
    <link rel="stylesheet" href="assets/css/match-details.css?v=<?= filemtime(__DIR__ . '/../assets/css/match-details.css') ?>">
    <script defer src="assets/js/match-details.js?v=<?= filemtime(__DIR__ . '/../assets/js/match-details.js') ?>"></script>
    <script defer src="assets/js/player-details.js?v=<?= filemtime(__DIR__ . '/../assets/js/player-details.js') ?>"></script>
    <script defer src="assets/js/market.js?v=<?= filemtime(__DIR__ . '/../assets/js/market.js') ?>"></script>
    <script defer src="assets/js/site-loader.js?v=<?= filemtime(__DIR__ . '/../assets/js/site-loader.js') ?>"></script>
<?php
}
