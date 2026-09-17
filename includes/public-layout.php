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
        "regulamento_noticias" => "8",
    ];

    try {
        foreach (db()->query("SELECT chave,valor FROM configuracoes_site")->fetchAll() as $row) {
            $config[$row["chave"]] = $row["valor"];
        }
    } catch (Throwable $ignored) {
    }

    return $config;
}

function public_nav_icon(string $name): string
{
    $paths = [
        'noticias' => '<path d="M4 5.5h16v13H4zM7 9h4v3H7zm7 0h3M14 12h3M7 15h10"/>',
        'competicao' => '<path d="M8 4h8v4a4 4 0 0 1-8 0V4Zm0 2H5v1a4 4 0 0 0 4 4m7-5h3v1a4 4 0 0 1-4 4m-3 1v4m-4 3h8"/>',
        'artilharia' => '<circle cx="12" cy="8" r="3.5"/><path d="M5.5 19c.7-4 3-6 6.5-6s5.8 2 6.5 6"/>',
        'participantes' => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3.5 19c.5-4 2.4-6 5.5-6s5 2 5.5 6m0-5c3.2 0 5 1.7 5.5 5"/>',
        'titulos' => '<circle cx="12" cy="14" r="5"/><path d="m9 9-3-5h4l2 4 2-4h4l-3 5m-5 5 1.4 1.1L13 13"/>',
        'estatisticas' => '<path d="M4 20V10h4v10m4 0V4h4v16m4 0v-7h-4M3 20h18"/>',
        'transferencias' => '<path d="M4 8h13m-3-3 3 3-3 3m6 5H7m3-3-3 3 3 3"/>',
        'comandos' => '<rect x="3.5" y="5" width="17" height="14" rx="2"/><path d="m7 10 3 2-3 2m5 1h5"/>',
        'regulamento' => '<path d="M6 3.5h9l3 3V20H6zM15 3.5V7h3M9 11h6M9 14h6M9 17h4"/>',
        'time' => '<path d="M12 3 19 6v5c0 4.4-2.3 7.5-7 10-4.7-2.5-7-5.6-7-10V6l7-3Z"/>',
        'elenco' => '<circle cx="8" cy="9" r="3"/><circle cx="16" cy="9" r="3"/><path d="M2.5 19c.5-3.5 2.4-5.5 5.5-5.5m13.5 5.5c-.5-3.5-2.4-5.5-5.5-5.5M8 19c.5-3.5 1.8-5.5 4-5.5s3.5 2 4 5.5"/>',
        'gestao' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v2m0 14v2M3 12h2m14 0h2M5.6 5.6 7 7m10 10 1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4"/>',
        'admin' => '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($paths[$name] ?? $paths['admin']) . '</svg>';
}

function public_navbar(string $active = "", bool $onLandingPage = false, bool $adminLayout = false): void
{
    $root = $adminLayout ? '../' : '';
    $home = $onLandingPage ? "" : "index.php";
    $sectionLinks = [
        "noticias" => ["noticias.php", "Notícias"],
        "competicao" => ["competicao.php", "Competição"],
        "participantes" => [$home . "#participantes", "Participantes"],
        "artilharia" => ["jogadores.php", "Jogadores"],
        "titulos" => ["titulos.php", "Títulos"],
        "estatisticas" => ["estatisticas.php", "Estatísticas"],
        "midia" => [$home . "#midia", "Vídeos"],
    ];
    $configuredOrder = array_filter(array_map(
        'trim',
        explode(',', (string)(public_site_config()['ordem_secoes'] ?? '')),
    ));
    $links = [];
    foreach ($configuredOrder as $key) {
        if (isset($sectionLinks[$key]) && !isset($links[$key])) $links[$key] = $sectionLinks[$key];
    }
    $links += [
        "estatisticas" => ["estatisticas.php", "Estatísticas"],
        "midia" => [$home . "#midia", "Vídeos"],
        "transferencias" => ["mercado-transferencias.php", "Mercado"],
        "comandos" => ["comandos.php", "Comandos"],
        "regulamento" => ["regulamento.php", "Regulamento"],
    ];
    $navGroups = [
        'principal' => ['label' => 'Principal', 'links' => ['noticias', 'midia']],
        'competicao' => ['label' => 'Competição', 'links' => ['competicao', 'artilharia', 'participantes', 'titulos', 'estatisticas']],
        'mercado' => ['label' => 'Mercado', 'links' => ['transferencias']],
        'informacoes' => ['label' => 'Informações', 'links' => ['comandos', 'regulamento']],
    ];
    $globalSectionLinks = [
        'noticias' => [$home . '#noticias', 'Notícias'],
        'competicao' => [$home . '#competicao', 'Competição'],
        'artilharia' => [$home . '#artilharia', 'Jogadores'],
        'participantes' => [$home . '#participantes', 'Participantes'],
        'titulos' => [$home . '#titulos', 'Títulos'],
        'midia' => [$home . '#midia', 'Vídeos'],
    ];
    $globalLinks = [];
    foreach ($configuredOrder as $key) if (isset($globalSectionLinks[$key])) $globalLinks[$key] = $globalSectionLinks[$key];
    $participantId = account_logged_in() ? (int)(account_participant_id() ?? 0) : 0;
    $viewedTeamId = $active === 'time' ? (int)($_GET['id'] ?? 0) : 0;
    $isOwnTeamPage = $active === 'time' && $participantId > 0 && $viewedTeamId === $participantId;
    $teamNavLabel = 'Meu time';
    $teamShield = '';
    $teamInitials = 'TM';
    if ($participantId > 0) {
        try {
            $teamLabelStmt = db()->prepare("SELECT time_nome,sigla,escudo_url FROM participantes WHERE id=? AND ativo=1 LIMIT 1");
            $teamLabelStmt->execute([$participantId]);
            $teamNavData = $teamLabelStmt->fetch() ?: [];
            $teamNavLabel = (string)($teamNavData['time_nome'] ?? 'Meu time');
            $teamShield = trim((string)($teamNavData['escudo_url'] ?? ''));
            $teamInitials = trim((string)($teamNavData['sigla'] ?? '')) ?: mb_strtoupper(mb_substr($teamNavLabel, 0, 3));
        } catch (Throwable $ignored) {
        }
    }
?>
    <link rel="stylesheet" href="<?= $root ?>assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/shields.css?v=<?=filemtime(__DIR__.'/../assets/css/shields.css')?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/site-toolbar.css?v=<?=filemtime(__DIR__.'/../assets/css/site-toolbar.css')?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/page-headings.css?v=<?=filemtime(__DIR__.'/../assets/css/page-headings.css')?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/navigation-v22.css?v=<?=filemtime(__DIR__.'/../assets/css/navigation-v22.css')?>">
    <script defer src="<?= $root ?>assets/js/account-menu.js?v=<?=filemtime(__DIR__.'/../assets/js/account-menu.js')?>"></script>
    <script defer src="<?= $root ?>assets/js/site-toolbar.js?v=<?=filemtime(__DIR__.'/../assets/js/site-toolbar.js')?>"></script>
    <?php if (!$adminLayout): ?><script>document.body.classList.add('site-has-sidebar');if(innerWidth>=768){document.body.classList.add('site-nav-collapsed');try{if(sessionStorage.getItem('site-sidebar-state')==='expanded')document.body.classList.remove('site-nav-collapsed')}catch(error){}}</script>
    <div class="site-loading-screen" role="status" aria-live="polite" aria-label="Carregando página">
        <img src="<?= $root ?>assets/img/logo-season3.webp?v=5" alt="" aria-hidden="true">
        <span class="site-loading-spinner"></span>
        <strong>CARREGANDO</strong>
    </div>
    <?php endif; ?>
    <nav class="navbar fixed-top navbar-dark site-topbar" data-site-root="<?= $root ?>">
        <div class="container">
            <a class="navbar-brand site-mobile-brand align-items-center gap-2" href="<?= $root . ($onLandingPage ? '#inicio' : 'index.php') ?>"><img class="brand-mark" src="<?= $root ?>assets/img/logo-season3.webp?v=5" alt="Vascão Season 3"><span>VASCÃO <b>S3</b></span></a>
            <div class="site-search"><input type="search" data-site-search placeholder="Buscar no Vascão…" aria-label="Buscar telas, clubes, jogadores e notícias" autocomplete="off"><div class="site-search-results" data-search-results hidden aria-live="polite"></div></div>
            <?php if(account_logged_in()): ?><div class="site-notifications"><button class="site-notification-toggle" data-notification-toggle data-csrf="<?=e(csrf_token())?>" aria-label="Notificações" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M9 21h6"/></svg><b data-notification-count hidden></b></button><div class="site-notification-panel" data-notification-panel hidden><button data-notification-read>Marcar como lidas</button><div data-notification-list></div><a href="<?= $root ?>notificacoes.php">Histórico e preferências</a></div></div><?php endif; ?>
            <?php if (account_logged_in()): ?><button class="site-topbar-profile" type="button" data-account-popover-toggle data-account-popover-origin="top" aria-expanded="false" aria-controls="site-account-popover"><span class="site-topbar-avatar"><?php if ($teamShield !== ''): ?><img src="<?= e($teamShield) ?>" alt="" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><b hidden><?= e($teamInitials) ?></b><?php else: ?><b><?= e($participantId > 0 ? $teamInitials : 'S3') ?></b><?php endif; ?></span><span><strong><?= e((string)($_SESSION['conta_nome'] ?? 'Usuário')) ?></strong><small><i></i><?= e($participantId > 0 ? $teamNavLabel : (account_is_admin() ? 'Administração' : 'Online')) ?></small></span><em>⌄</em></button><?php endif; ?>
            <?php if (!account_logged_in()): ?><a class="btn btn-outline-light ms-auto me-2" href="<?= $root ?>login.php">Entrar</a><?php endif; ?>
            <button class="<?= $adminLayout ? 'admin-menu-toggle' : 'site-mobile-menu-trigger' ?>" type="button" aria-controls="<?= $adminLayout ? 'admin-side-menu' : 'site-side-menu' ?>" aria-expanded="false" aria-label="Abrir menu"><span></span><span></span><span></span></button>
        </div>
    </nav>
    <?php if (!$adminLayout): ?>
    <div class="site-menu-backdrop" data-site-menu-close></div>
    <aside id="site-side-menu" class="site-side-menu" aria-label="Menu principal" aria-hidden="true">
        <div class="site-side-head">
            <a class="site-side-brand" href="<?= $onLandingPage ? "#inicio" : "index.php" ?>"><img src="<?= $root ?>assets/img/logo-season3.webp?v=5" alt=""><span>VASCÃO <b>SEASON 3</b></span></a>
            <button type="button" class="site-menu-close" data-sidebar-toggle aria-label="Recolher menu" title="Expandir ou recolher">‹</button>
        </div>
        <nav class="site-side-nav">
            <?php foreach ($navGroups as $groupKey => $group): $visibleGroupLinks = array_values(array_filter($group['links'], static fn(string $key): bool => isset($links[$key]))); if (!$visibleGroupLinks) continue; ?><section class="site-nav-group" data-nav-group="<?= e($groupKey) ?>"><span class="site-side-label"><?= e(mb_strtoupper($group['label'])) ?></span><ul><?php foreach ($visibleGroupLinks as $key): [$href, $label] = $links[$key]; ?><li><a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e($href) ?>" aria-label="<?= e($label) ?>"><i><?= public_nav_icon($key) ?></i><span><?= e($label) ?></span><b aria-hidden="true">›</b></a></li><?php endforeach; ?></ul></section><?php endforeach; ?>
            <?php if ($participantId > 0): ?><section class="site-nav-group" data-nav-group="clube"><span class="site-side-label">MEU CLUBE</span><ul><li><a class="<?= $isOwnTeamPage ? 'active' : '' ?>" href="<?= $root ?>time.php?id=<?= $participantId ?>" aria-label="Página do <?= e($teamNavLabel) ?>"<?= $isOwnTeamPage ? ' aria-current="page"' : '' ?>><i><?= public_nav_icon('time') ?></i><span>Página do <?= e($teamNavLabel) ?></span><b>›</b></a></li><li><a class="<?= $active === 'elenco-geral' ? 'active' : '' ?>" href="elenco-geral.php" aria-label="Elenco geral"><i><?= public_nav_icon('elenco') ?></i><span>Elenco geral</span><b>›</b></a></li><li><a class="<?= $active === 'mercado' ? 'active' : '' ?>" href="mercado.php" aria-label="Gestão da competição"><i><?= public_nav_icon('gestao') ?></i><span>Gestão da competição</span><b>›</b></a></li></ul></section><?php endif; ?>
            <?php if (account_logged_in() && account_is_admin()): ?><section class="site-nav-group"><span class="site-side-label">SISTEMA</span><ul><li><a href="<?= $root ?>admin/" aria-label="Administração"><i><?= public_nav_icon('admin') ?></i><span>Administração</span><b>›</b></a></li></ul></section><?php endif; ?>
        </nav>
    </aside>
    <?php endif; ?>
    <?php if (account_logged_in()): ?><aside id="site-account-popover" class="site-account-popover" aria-label="Conta e time" aria-hidden="true">
        <div class="site-account-popover-profile"><div class="site-account-popover-shield"><?php if ($teamShield !== ''): ?><img src="<?= e($teamShield) ?>" alt="Escudo do <?= e($teamNavLabel) ?>"><?php else: ?><span><?= e($participantId > 0 ? $teamInitials : 'S3') ?></span><?php endif; ?></div><div><small><?= account_is_admin() ? 'ADMINISTRAÇÃO' : 'CONTA CONECTADA' ?></small><strong><?= e((string)($_SESSION['conta_nome'] ?? 'Usuário')) ?></strong><span><?= e($participantId > 0 ? $teamNavLabel : 'Sem time associado') ?></span></div></div>
        <nav><?php if ($adminLayout): ?><a href="../index.php"><i><?= public_nav_icon('noticias') ?></i><span>Abrir site</span></a><?php endif; ?><?php if ($participantId > 0): ?><a href="<?= $root ?>time.php?id=<?= $participantId ?>"><i><?= public_nav_icon('time') ?></i><span>Página do clube</span></a><?php endif; ?><a href="<?= $root ?>trocar-senha.php"><i><?= public_nav_icon('gestao') ?></i><span>Segurança da conta</span></a><a href="<?= $root ?>notificacoes.php"><i><?= public_nav_icon('noticias') ?></i><span>Notificações e e-mails</span></a><?php if (account_is_admin()): ?><a href="<?= $root ?>admin/"><i><?= public_nav_icon('admin') ?></i><span>Painel administrativo</span></a><?php endif; ?><a class="site-account-logout" href="<?= $root ?>logout.php"><i><?= public_nav_icon('transferencias') ?></i><span>Sair da conta</span></a></nav>
    </aside><?php endif; ?>
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
    <script defer src="assets/js/stats-modal-navigation.js?v=<?= filemtime(__DIR__ . '/../assets/js/stats-modal-navigation.js') ?>"></script>
    <script defer src="assets/js/match-details.js?v=<?= filemtime(__DIR__ . '/../assets/js/match-details.js') ?>"></script>
    <script defer src="assets/js/player-details.js?v=<?= filemtime(__DIR__ . '/../assets/js/player-details.js') ?>"></script>
    <script defer src="assets/js/market.js?v=<?= filemtime(__DIR__ . '/../assets/js/market.js') ?>"></script>
    <script defer src="assets/js/site-loader.js?v=<?= filemtime(__DIR__ . '/../assets/js/site-loader.js') ?>"></script>
    <script defer src="assets/js/side-menu.js?v=<?= filemtime(__DIR__ . '/../assets/js/side-menu.js') ?>"></script>
<?php
}
