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

function public_nav_icon(string $name): string
{
    $paths = [
        'noticias' => '<path d="M4 5.5h16v13H4zM7 9h4v3H7zm7 0h3M14 12h3M7 15h10"/>',
        'competicao' => '<path d="M8 4h8v4a4 4 0 0 1-8 0V4Zm0 2H5v1a4 4 0 0 0 4 4m7-5h3v1a4 4 0 0 1-4 4m-3 1v4m-4 3h8"/>',
        'artilharia' => '<circle cx="12" cy="8" r="3.5"/><path d="M5.5 19c.7-4 3-6 6.5-6s5.8 2 6.5 6"/>',
        'participantes' => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3.5 19c.5-4 2.4-6 5.5-6s5 2 5.5 6m0-5c3.2 0 5 1.7 5.5 5"/>',
        'titulos' => '<circle cx="12" cy="14" r="5"/><path d="m9 9-3-5h4l2 4 2-4h4l-3 5m-5 5 1.4 1.1L13 13"/>',
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
    foreach ($configuredOrder as $key) {
        if (isset($sectionLinks[$key]) && !isset($links[$key])) $links[$key] = $sectionLinks[$key];
    }
    $links += [
        "transferencias" => ["mercado-transferencias.php", "Mercado"],
        "comandos" => ["comandos.php", "Comandos"],
        "regulamento" => ["regulamento.php", "Regulamento"],
    ];
    $navGroups = [
        'principal' => ['label' => 'Principal', 'links' => ['noticias']],
        'competicao' => ['label' => 'Competição', 'links' => ['competicao', 'artilharia', 'participantes', 'titulos']],
        'mercado' => ['label' => 'Mercado', 'links' => ['transferencias']],
        'informacoes' => ['label' => 'Informações', 'links' => ['comandos', 'regulamento']],
    ];
    $globalSectionLinks = [
        'competicao' => [$home . '#competicao', 'Competição'],
        'artilharia' => [$home . '#artilharia', 'Jogadores'],
        'participantes' => [$home . '#participantes', 'Participantes'],
        'titulos' => [$home . '#titulos', 'Títulos'],
        'midia' => [$home . '#midia', 'Vídeos'],
    ];
    $globalLinks = [];
    foreach ($configuredOrder as $key) if (isset($globalSectionLinks[$key])) $globalLinks[$key] = $globalSectionLinks[$key];
    $participantId = account_logged_in() ? (int)(account_participant_id() ?? 0) : 0;
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
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <script>document.body.classList.add('site-has-sidebar');if(innerWidth>=768){document.body.classList.add('site-nav-collapsed');try{if(sessionStorage.getItem('site-sidebar-state')==='expanded')document.body.classList.remove('site-nav-collapsed')}catch(error){}}</script>
    <div class="site-loading-screen" role="status" aria-live="polite" aria-label="Carregando página">
        <img src="assets/img/logo-season3.webp?v=5" alt="" aria-hidden="true">
        <span class="site-loading-spinner"></span>
        <strong>CARREGANDO</strong>
    </div>
    <nav class="navbar fixed-top navbar-dark site-topbar">
        <div class="container">
            <a class="navbar-brand site-mobile-brand align-items-center gap-2" href="<?= $onLandingPage ? '#inicio' : 'index.php' ?>"><img class="brand-mark" src="assets/img/logo-season3.webp?v=5" alt="Vascão Season 3"><span>VASCÃO <b>S3</b></span></a>
            <div class="global-nav-links" aria-label="Seções principais"><?php foreach ($globalLinks as [$href, $label]): ?><a href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?></div>
            <button class="site-mobile-menu-trigger" type="button" aria-controls="site-side-menu" aria-expanded="false" aria-label="Abrir menu"><span></span><span></span><span></span></button>
        </div>
    </nav>
    <div class="site-menu-backdrop" data-site-menu-close></div>
    <aside id="site-side-menu" class="site-side-menu" aria-label="Menu principal" aria-hidden="true">
        <div class="site-side-head">
            <a class="site-side-brand" href="<?= $onLandingPage ? "#inicio" : "index.php" ?>"><img src="assets/img/logo-season3.webp?v=5" alt=""><span>VASCÃO <b>SEASON 3</b></span></a>
            <button type="button" class="site-menu-close" data-sidebar-toggle aria-label="Recolher menu" title="Expandir ou recolher">‹</button>
        </div>
        <?php if (account_logged_in()): ?>
            <div class="site-account-card">
                <button type="button" class="site-account-shield" data-account-popover-toggle aria-expanded="false" aria-controls="site-account-popover" aria-label="Abrir menu da conta e do time"><?php if ($teamShield !== ''): ?><img src="<?= e($teamShield) ?>" alt="Escudo do <?= e($teamNavLabel) ?>" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><span hidden><?= e($teamInitials) ?></span><?php else: ?><span><?= e($participantId > 0 ? $teamInitials : 'S3') ?></span><?php endif; ?></button>
                <div><small>CONTA CONECTADA</small><strong><?= e((string)($_SESSION['conta_nome'] ?? 'Usuário')) ?></strong><span><?= e($participantId > 0 ? $teamNavLabel : (account_is_admin() ? 'Administração' : 'Sem time associado')) ?></span></div>
            </div>
        <?php endif; ?>
        <nav class="site-side-nav">
            <?php foreach ($navGroups as $groupKey => $group): $visibleGroupLinks = array_values(array_filter($group['links'], static fn(string $key): bool => isset($links[$key]))); if (!$visibleGroupLinks) continue; ?><section class="site-nav-group" data-nav-group="<?= e($groupKey) ?>"><span class="site-side-label"><?= e(mb_strtoupper($group['label'])) ?></span><ul><?php foreach ($visibleGroupLinks as $key): [$href, $label] = $links[$key]; ?><li><a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e($href) ?>" title="<?= e($label) ?>"><i><?= public_nav_icon($key) ?></i><span><?= e($label) ?></span><b aria-hidden="true">›</b></a></li><?php endforeach; ?></ul></section><?php endforeach; ?>
            <?php if ($participantId > 0): ?><section class="site-nav-group" data-nav-group="clube"><span class="site-side-label">MEU CLUBE</span><ul><li><a class="<?= $active === 'time' ? 'active' : '' ?>" href="time.php?id=<?= $participantId ?>" title="Página do clube"><i><?= public_nav_icon('time') ?></i><span>Página do <?= e($teamNavLabel) ?></span><b>›</b></a></li><li><a class="<?= $active === 'elenco-geral' ? 'active' : '' ?>" href="elenco-geral.php" title="Elenco geral"><i><?= public_nav_icon('elenco') ?></i><span>Elenco geral</span><b>›</b></a></li><li><a class="<?= $active === 'mercado' ? 'active' : '' ?>" href="mercado.php" title="Gestão da competição"><i><?= public_nav_icon('gestao') ?></i><span>Gestão da competição</span><b>›</b></a></li></ul></section><?php endif; ?>
            <?php if (account_logged_in() && account_is_admin()): ?><section class="site-nav-group"><span class="site-side-label">SISTEMA</span><ul><li><a href="admin/" title="Administração"><i><?= public_nav_icon('admin') ?></i><span>Administração</span><b>›</b></a></li></ul></section><?php endif; ?>
        </nav>
        <div class="site-side-account"><?php if (account_logged_in() && account_is_admin()): ?><a class="site-side-primary" href="admin/">Abrir painel administrativo</a><a href="logout.php">Sair da conta</a><?php elseif (account_logged_in() && $participantId > 0): ?><a class="site-side-primary" href="time.php?id=<?= $participantId ?>">Acessar meu time</a><a href="logout.php">Sair da conta</a><?php elseif (account_logged_in()): ?><span></span><a href="logout.php">Sair da conta</a><?php else: ?><a class="site-side-primary" href="login.php">Entrar</a><a href="cadastro.php">Criar uma conta</a><?php endif; ?></div>
    </aside>
    <?php if (account_logged_in()): ?><aside id="site-account-popover" class="site-account-popover" aria-label="Conta e time" aria-hidden="true">
        <div class="site-account-popover-profile"><div class="site-account-popover-shield"><?php if ($teamShield !== ''): ?><img src="<?= e($teamShield) ?>" alt="Escudo do <?= e($teamNavLabel) ?>"><?php else: ?><span><?= e($participantId > 0 ? $teamInitials : 'S3') ?></span><?php endif; ?></div><div><small><?= account_is_admin() ? 'ADMINISTRAÇÃO' : 'CONTA CONECTADA' ?></small><strong><?= e((string)($_SESSION['conta_nome'] ?? 'Usuário')) ?></strong><span><?= e($participantId > 0 ? $teamNavLabel : 'Sem time associado') ?></span></div></div>
        <nav><?php if ($participantId > 0): ?><a href="time.php?id=<?= $participantId ?>"><i><?= public_nav_icon('time') ?></i><span>Página do clube</span></a><?php endif; ?><a href="trocar-senha.php"><i><?= public_nav_icon('gestao') ?></i><span>Segurança da conta</span></a><?php if (account_is_admin()): ?><a href="admin/"><i><?= public_nav_icon('admin') ?></i><span>Painel administrativo</span></a><?php endif; ?><a class="site-account-logout" href="logout.php"><i><?= public_nav_icon('transferencias') ?></i><span>Sair da conta</span></a></nav>
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
    <script defer src="assets/js/match-details.js?v=<?= filemtime(__DIR__ . '/../assets/js/match-details.js') ?>"></script>
    <script defer src="assets/js/player-details.js?v=<?= filemtime(__DIR__ . '/../assets/js/player-details.js') ?>"></script>
    <script defer src="assets/js/market.js?v=<?= filemtime(__DIR__ . '/../assets/js/market.js') ?>"></script>
    <script defer src="assets/js/site-loader.js?v=<?= filemtime(__DIR__ . '/../assets/js/site-loader.js') ?>"></script>
    <script defer src="assets/js/side-menu.js?v=<?= filemtime(__DIR__ . '/../assets/js/side-menu.js') ?>"></script>
<?php
}
