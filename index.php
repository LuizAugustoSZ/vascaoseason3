<?php require __DIR__ . "/includes/bootstrap.php";
require __DIR__ . "/includes/public-layout.php";
$latestNews = [];
$latestVideo = null;
$siteConfig = public_site_config();
try {
    $latestNews = db()
        ->query(
            "SELECT id,titulo,resumo,capa_base64,publicado_em FROM noticias WHERE ativo=1 ORDER BY publicado_em DESC,id DESC LIMIT 3",
        )
        ->fetchAll();
    $latestVideo =
        db()
        ->query(
            "SELECT id,titulo,youtube_url FROM videos WHERE ativo=1 ORDER BY criado_em DESC,id DESC LIMIT 1",
        )
        ->fetch() ?:
        null;
} catch (Throwable $error) {
}
$heroVideoId = "";
if (
    $latestVideo &&
    preg_match(
        "~(?:youtu\.be/|[?&]v=|embed/)([A-Za-z0-9_-]{11})~",
        $latestVideo["youtube_url"],
        $heroVideoMatch,
    )
) {
    $heroVideoId = $heroVideoMatch[1];
}
?>
<!doctype html>
<html lang="pt-BR" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Portal oficial da Season 3 do Servidor do Vascão dos Gigantes.">
    <title>Vascão dos Gigantes | Season 3</title>
    <link rel="icon" href="favicon.ico?v=5" sizes="any">
    <link rel="icon" type="image/png" href="assets/img/favicon-season3.png?v=5">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png?v=5">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/branding.css?v=<?= filemtime(
                                                                __DIR__ . "/assets/css/branding.css",
                                                            ) ?>">
    <link rel="stylesheet" href="assets/css/bracket-v5.css?v=<?= filemtime(
                                                                    __DIR__ . "/assets/css/bracket-v5.css",
                                                                ) ?>">
    <link rel="stylesheet" href="assets/css/hero-feature.css?v=<?= filemtime(
                                                                    __DIR__ . "/assets/css/hero-feature.css",
                                                                ) ?>">
    <link rel="stylesheet" href="assets/css/scorers-podium.css?v=<?= filemtime(
                                                                        __DIR__ . "/assets/css/scorers-podium.css",
                                                                    ) ?>">
    <link rel="stylesheet" href="assets/css/season3-update.css?v=<?= filemtime(
                                                                        __DIR__ . "/assets/css/season3-update.css",
                                                                    ) ?>">
    <style>
        .participant-card-link,
        .team-link {
            color: inherit;
            text-decoration: none
        }

        .participant-card-link {
            display: block;
            height: 100%
        }

        .team-link:hover {
            color: #ff555b
        }
    </style>
    <link rel="stylesheet" href="assets/css/games.css?v=<?= filemtime(
                                                            __DIR__ . "/assets/css/games.css",
                                                        ) ?>">
    <link rel="stylesheet" href="assets/css/news.css?v=<?= filemtime(
                                                            __DIR__ . "/assets/css/news.css",
                                                        ) ?>">
    <link rel="stylesheet" href="assets/css/shields.css?v=<?= filemtime(
                                                                __DIR__ . "/assets/css/shields.css",
                                                            ) ?>">
    <link rel="stylesheet" href="assets/css/version-history.css?v=<?= filemtime(
                                                                        __DIR__ . "/assets/css/version-history.css",
                                                                    ) ?>">
    <link rel="stylesheet" href="assets/css/export-actions.css?v=<?= filemtime(
                                                                        __DIR__ . "/assets/css/export-actions.css",
                                                                    ) ?>">
    <link rel="stylesheet" href="assets/css/public-states.css?v=<?= filemtime(
                                                                    __DIR__ . "/assets/css/public-states.css",
                                                                ) ?>">
    <link rel="stylesheet" href="assets/css/socials.css?v=<?= filemtime(
                                                                __DIR__ . "/assets/css/socials.css",
                                                            ) ?>">
</head>

<body>
    <?php
    // Menu principal com atalhos para as seções da página.
    ?>
    <?php public_navbar('', true); ?>

    <?php
    // Apresentação da Season e resumo do status atual.
    ?>
    <header id="inicio" class="hero d-flex align-items-center">
        <div class="hero-lines"></div>
        <div class="container position-relative py-5">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <span class="eyebrow">DreamTeam • Campeonato da comunidade</span>
                    <h1>SEASON <span>3</span><br>O GIGANTE VOLTOU.</h1>
                    <p class="lead text-secondary">Classificação, confrontos, mata-mata e tudo que acontece na competição.</p>
                    <a href="#competicao" class="btn btn-danger btn-lg">Ver competição</a>
                </div>
                <div class="col-lg-5">
                    <div id="hero-feature-carousel" class="carousel slide hero-feature-carousel" data-bs-ride="carousel" data-bs-interval="7000" data-bs-pause="hover">
                        <div class="carousel-indicators"><?php
                                                            $heroSlideCount = ($heroVideoId !== "" ? 1 : 0) + count($latestNews);
                                                            for (
                                                                $heroSlide = 0;
                                                                $heroSlide < $heroSlideCount;
                                                                $heroSlide++
                                                            ): ?><button type="button" data-bs-target="#hero-feature-carousel" data-bs-slide-to="<?= $heroSlide ?>" class="<?= $heroSlide ===
                                                                                                                            0
                                                                                                                            ? "active"
                                                                                                                            : "" ?>" aria-label="Destaque <?= $heroSlide + 1 ?>"></button><?php endfor;
                                                                    ?></div>
                        <div class="carousel-inner"><?php
                                                    $heroFirst = true;
                                                    if (
                                                        $heroVideoId !== ""
                                                    ): ?><div class="carousel-item active" data-feature-type="video">
                                    <div id="hero-video-shell" class="hero-video-shell"><button id="hero-video-close" class="hero-video-close" type="button" aria-label="Fechar vídeo flutuante">×</button>
                                        <div id="hero-latest-video" data-video-id="<?= e(
                                                                                        $heroVideoId,
                                                                                    ) ?>"></div>
                                    </div>
                                    <div class="hero-feature-caption"><small>ÚLTIMO VÍDEO</small><strong><?= e(
                                                                                                                $latestVideo["titulo"],
                                                                                                            ) ?></strong></div>
                                </div><?php $heroFirst = false;
                                                    endif;
                                                    foreach ($latestNews as $heroNews): ?><div class="carousel-item <?= $heroFirst
                                                                    ? "active"
                                                                    : "" ?>" data-feature-type="news"><a class="hero-news-slide" href="noticia.php?id=<?= $heroNews["id"] ?>"><img src="<?= e(
                                                            $heroNews["capa_base64"],
                                                        ) ?>" alt=""><span class="hero-news-overlay"></span>
                                        <div class="hero-feature-caption"><small>ÚLTIMA NOTÍCIA</small><strong><?= e(
                                                                                                                    $heroNews["titulo"],
                                                                                                                ) ?></strong><span>Ler matéria →</span></div>
                                    </a></div><?php $heroFirst = false;
                                                    endforeach;
                                                    if (
                                                        $heroFirst
                                                    ): ?><div class="carousel-item active">
                                    <div class="hero-feature-empty"><img class="empty-season-logo" src="assets/img/logo-season3.webp?v=5" alt="">
                                        <p>Novos vídeos e notícias aparecerão aqui.</p>
                                    </div>
                                </div><?php endif;
                                        ?></div><?php if (
            $heroSlideCount > 1
        ): ?><button class="carousel-control-prev" type="button" data-bs-target="#hero-feature-carousel" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button><button class="carousel-control-next" type="button" data-bs-target="#hero-feature-carousel" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button><?php endif; ?>
                    </div>
                    <div class="status-card mt-3"><small>STATUS DA TEMPORADA</small><strong><i id="season-status-dot"></i> <span id="season-status">CARREGANDO...</span></strong><span id="season-summary">Consultando os registros da Season 3</span></div>
                </div>
            </div>
        </div>
    </header>

    <main>
        <?php
        // Classificação, jogos e chaveamento alimentados pela API.
        ?>
        <section id="competicao" class="section-pad">
            <div class="container">
                <div class="section-title">
                    <div><small>A DISPUTA</small>
                        <h2>COMPETIÇÃO</h2>
                    </div>
                </div>
                <ul class="nav competition-tabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pontos-corridos">Pontos corridos</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#mata-mata">Mata-mata</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#supercopa">Supercopa</button></li>
                </ul>
                <div class="tab-content pt-4">
                    <div class="tab-pane fade show active" id="pontos-corridos">
                        <div class="row g-4">
                            <div class="col-xl-8">
                                <div class="panel">
                                    <div class="panel-head">
                                        <h3>Classificação</h3><span>Atualização automática</span>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table ranking-table mb-0">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Time</th>
                                                    <th>Técnico</th>
                                                    <th>PTS</th>
                                                    <th>J</th>
                                                    <th>V</th>
                                                    <th>E</th>
                                                    <th>D</th>
                                                    <th>SG</th>
                                                </tr>
                                            </thead>
                                            <tbody id="standings-body"></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-4">
                                <div class="panel h-100">
                                    <div class="panel-head">
                                        <h3>Jogos</h3><span id="round-status">Selecione a rodada</span>
                                    </div>
                                    <div class="game-tools"><label class="form-label" for="round-select">Rodada</label><select id="round-select" class="form-select">
                                            <option value="all">Todas as rodadas</option>
                                        </select>
                                        <div class="command-search game-search mt-2"><span>⌕</span><input id="game-search" type="search" placeholder="Buscar técnico ou time..." autocomplete="off"></div>
                                    </div>
                                    <div id="league-games" class="game-list"></div>
                                    <div id="league-pagination" class="game-pagination"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="mata-mata">
                        <div class="panel">
                            <div class="panel-head">
                                <h3>Chaveamento</h3><span>Da primeira fase até a final</span>
                            </div>
                            <div id="bracket" class="bracket"></div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="supercopa">
                        <div class="panel">
                            <div class="panel-head"><h3>Decisão dos campeões</h3><span>Vagas automáticas</span></div>
                            <div id="supercup-bracket" class="bracket"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <?php
        // Cards dos técnicos e times cadastrados no painel.
        ?>
        <section id="participantes" class="section-pad bg-panel">
            <div class="container">
                <div class="section-title">
                    <div><small>OS GIGANTES</small>
                        <h2>PARTICIPANTES</h2>
                    </div>
                </div>
                <div id="participants-grid" class="row g-3"></div>
            </div>
        </section>
        <?php
        // Rankings dos jogadores com mais gols e assistências.
        ?>
        <section id="artilharia" class="section-pad">
            <div class="container">
                <div class="section-title">
                    <div><small>QUEM DECIDE</small>
                        <h2>JOGADORES</h2>
                    </div>
                </div>
                <div class="panel p-3 mb-3"><label class="form-label small" for="scorers-championship-select">CAMPEONATO</label><select id="scorers-championship-select" class="form-select"></select><small id="scorers-championship-title" class="text-secondary"></small></div>
                <div class="panel">
                    <div class="panel-head">
                        <div class="player-ranking-tabs" role="tablist" aria-label="Ranking de jogadores"><button type="button" class="active" data-ranking="goals" role="tab" aria-selected="true">Artilheiros</button><button type="button" data-ranking="assists" role="tab" aria-selected="false">Assistências</button></div><button id="scorers-download" class="competition-download" type="button" title="Baixar ranking completo como PNG" aria-label="Baixar ranking completo como PNG"><svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 3v12m0 0 5-5m-5 5-5-5M5 19h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg></button>
                    </div>
                    <div id="scorers-list" class="scorers-list"></div>
                    <div id="scorers-pagination" class="scorers-pagination"></div>
                </div>
            </div>
        </section>

        <?php
        // Galeria das conquistas registradas por técnico.
        ?>
        <section id="titulos" class="section-pad bg-panel">
            <div class="container">
                <div class="section-title">
                    <div><small>GALERIA DOS CAMPEÕES</small>
                        <h2>TÍTULOS</h2>
                    </div>
                </div>
                <div class="panel p-4 p-lg-5 text-center"><p class="text-secondary mb-4">Conheça as taças em destaque, suas identidades e todos os campeões de cada edição.</p><a class="btn btn-danger btn-lg" href="titulos.php">ABRIR VITRINE DE TAÇAS</a></div>
            </div>
        </section>

        <?php
        // Últimas matérias do jornal do servidor.
        ?>
        <section id="noticias" class="section-pad">
            <div class="container">
                <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
                    <div class="section-title mb-0">
                        <div><small>JORNAL DO SERVIDOR</small>
                            <h2>NOTÍCIAS</h2>
                        </div>
                    </div><a class="btn btn-outline-light" href="noticias.php">Ver postagens</a>
                </div>
                <div class="row g-4"><?php
                                        foreach (
                                            $latestNews
                                            as $item
                                        ): ?><div class="col-md-6 col-xl-4">
                            <article class="news-card"><a href="noticia.php?id=<?= $item["id"] ?>"><img src="<?= e(
                                                $item["capa_base64"],
                                            ) ?>" alt=""></a>
                                <div class="news-card-body"><span class="news-meta"><?= e(
                                                                                        format_datetime_br($item["publicado_em"], "d/m/Y"),
                                                                                    ) ?></span>
                                    <h3 class="mt-2"><a class="text-white text-decoration-none" href="noticia.php?id=<?= $item["id"] ?>"><?= e($item["titulo"]) ?></a></h3><?php if ($item["resumo"]): ?><p><?= e(
                                                                                $item["resumo"],
                                                                            ) ?></p><?php endif; ?><a class="btn btn-danger btn-sm" href="noticia.php?id=<?= $item["id"] ?>">Ler notícia</a>
                                </div>
                            </article>
                        </div><?php endforeach;
                                        if (
                                            !$latestNews
                                        ): ?><div class="empty-state">As notícias do servidor aparecerão aqui.</div><?php endif;
                                                                                ?></div>
            </div>
        </section>

        <?php
        // Vídeos publicados pelo painel administrativo.
        ?>
        <section id="midia" class="section-pad bg-panel">
            <div class="container">
                <div class="section-title">
                    <div><small>NA REDE</small>
                        <h2>VÍDEOS</h2>
                    </div>
                </div>
                <div id="videos-grid" class="row g-4"></div>
            </div>
        </section>
    </main>

    <?php public_footer(); ?>
    <div id="app-alert" class="toast-container position-fixed bottom-0 end-0 p-3"></div>
    <script src="https://code.jquery.com/jquery-4.0.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
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
    <script src="assets/js/script.js?v=<?= filemtime(
                                            __DIR__ . "/assets/js/script.js",
                                        ) ?>"></script>
    <script src="assets/js/hero-feature.js?v=<?= filemtime(
                                                    __DIR__ . "/assets/js/hero-feature.js",
                                                ) ?>"></script>
    <script>
        (() => {
            const config = <?= json_encode(
                                $siteConfig,
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                            ) ?>;
            const main = document.querySelector('main'),
                order = String(config.ordem_secoes ?? 'noticias,competicao,participantes,artilharia,titulos,midia').split(',').map(item => item.trim()).filter(Boolean),
                configurableSections = ['noticias','competicao','participantes','artilharia','titulos','midia'];
            configurableSections.forEach(id => { if (!order.includes(id)) document.getElementById(id)?.remove() });
            order.forEach(id => {
                const section = document.getElementById(id);
                if (section) main.append(section)
            });
            const sections = [...main.querySelectorAll(':scope > section')];
            sections.forEach((section, index) => {
                section.classList.toggle('bg-panel', index % 2 === 1);
            });
            const footer = document.querySelector('footer .container');
            if (!footer) return;
            if (config.footer_nome) footer.firstElementChild.textContent = config.footer_nome;
            if (config.footer_projeto) footer.lastElementChild.textContent = config.footer_projeto;
            const social = [...footer.querySelectorAll('.footer-socials a')];
            if (config.discord_url && social[0]) social[0].href = config.discord_url;
            if (config.youtube_url && social[1]) social[1].href = config.youtube_url;
        })();
    </script>
</body>

</html>
