<?php require __DIR__ . "/includes/bootstrap.php";
require __DIR__ . "/includes/public-layout.php";
$siteConfig = public_site_config();
$regulationIds = array_values(array_unique(array_filter(
    array_map('intval', explode(',', (string)($siteConfig['regulamento_noticias'] ?? ''))),
    static fn(int $id): bool => $id > 0,
)));
$regulations = [];
if ($regulationIds) {
    $placeholders = implode(',', array_fill(0, count($regulationIds), '?'));
    $order = implode(',', $regulationIds);
    $stmt = db()->prepare("SELECT id,titulo,resumo,capa_base64,autor,publicado_em FROM noticias WHERE ativo=1 AND id IN ($placeholders) ORDER BY FIELD(id,$order)");
    $stmt->execute($regulationIds);
    $regulations = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="pt-BR" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="description" content="Regulamento oficial das competições do Vascão Season 3.">
    <title>Regulamento | Vascão Season 3</title>
    <link rel="icon" href="favicon.ico?v=5" sizes="any">
    <link rel="icon" type="image/png" href="assets/img/favicon-season3.png?v=5">
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/branding.css?v=5">
    <link rel="stylesheet" href="assets/css/news.css?v=<?= filemtime(__DIR__ . '/assets/css/news.css') ?>">
    <link rel="stylesheet" href="assets/css/season3-update.css?v=<?= filemtime(
                                                                        __DIR__ . "/assets/css/season3-update.css",
                                                                    ) ?>">
    <link rel="stylesheet" href="assets/css/socials.css?v=<?= filemtime(
                                                                __DIR__ . "/assets/css/socials.css",
                                                            ) ?>">
    <link rel="stylesheet" href="assets/css/version-history.css?v=<?= filemtime(
                                                                        __DIR__ . "/assets/css/version-history.css",
                                                                    ) ?>">
</head>

<body>
    <?php public_navbar('regulamento'); ?>
    <main class="section-pad" style="padding-top:130px;min-height:calc(100vh - 90px)">
        <div class="container"><span class="eyebrow">Jogo limpo</span>
            <div class="section-title mt-4">
                <div><small>REGRAS DA SEASON</small>
                    <h2>REGULAMENTO</h2>
                </div>
            </div>
            <p class="lead text-secondary mb-5">Consulte os regulamentos oficiais publicados para as competições da temporada.</p>
            <div class="row g-4"><?php foreach ($regulations as $item): ?>
                <div class="col-md-6 col-xl-4">
                    <article class="news-card h-100"><a href="noticia.php?id=<?= (int)$item['id'] ?>"><img src="<?= e($item['capa_base64']) ?>" alt=""></a><div class="news-card-body"><span class="news-meta"><?= e(format_datetime_br($item['publicado_em'])) ?> • <?= e($item['autor']) ?></span><h2 class="mt-2"><a class="text-white text-decoration-none" href="noticia.php?id=<?= (int)$item['id'] ?>"><?= e($item['titulo']) ?></a></h2><?php if ($item['resumo']): ?><p><?= e($item['resumo']) ?></p><?php endif; ?><a class="btn btn-danger btn-sm" href="noticia.php?id=<?= (int)$item['id'] ?>">Ver regulamento</a></div></article>
                </div>
            <?php endforeach; ?><?php if (!$regulations): ?><div class="col-12"><div class="empty-state">Nenhum regulamento publicado no momento.</div></div><?php endif; ?></div>
        </div>
    </main><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><?php if (
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
</body>

</html>
