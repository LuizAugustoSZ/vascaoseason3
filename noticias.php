<?php
// Lista as notícias ativas do jornal com paginação.
require __DIR__ . "/includes/bootstrap.php";
require __DIR__ . "/includes/public-layout.php";
$pdo = db();
$page = max(1, (int) ($_GET["pagina"] ?? 1));
$perPage = 6;
$total = (int) $pdo
    ->query("SELECT COUNT(*) FROM noticias WHERE ativo=1")
    ->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare(
    "SELECT id,titulo,resumo,capa_base64,autor,publicado_em FROM noticias WHERE ativo=1 ORDER BY publicado_em DESC,id DESC LIMIT :limit OFFSET :offset",
);
$stmt->bindValue(":limit", $perPage, PDO::PARAM_INT);
$stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
$stmt->execute();
$news = $stmt->fetchAll();
?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="description" content="Notícias e novidades da Season 3 do Vascão dos Gigantes."><title>Notícias | Vascão Season 3</title><link rel="icon" href="favicon.ico?v=5" sizes="any"><link rel="icon" type="image/png" href="assets/img/favicon-season3.png?v=5"><link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png?v=5"><link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/branding.css?v=5"><link rel="stylesheet" href="assets/css/news.css?v=<?= filemtime(
    __DIR__ . "/assets/css/news.css",
) ?>"><link rel="stylesheet" href="assets/css/socials.css?v=<?= filemtime(__DIR__ . "/assets/css/socials.css") ?>"></head><body>
<?php public_navbar('noticias'); ?>
<main class="news-page"><div class="container"><span class="eyebrow">Notícias do servidor</span><div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4"><h1 class="display-3 fw-bold mb-0">JORNAL DO VASCÃO</h1><span class="text-secondary"><?= $total ?> <?= $total ===
 1
     ? "postagem"
     : "postagens" ?></span></div><div class="row g-4"><?php
foreach (
    $news
    as $item
): ?><div class="col-md-6 col-xl-4"><article class="news-card"><a href="noticia.php?id=<?= $item[
    "id"
] ?>"><img src="<?= e(
    $item["capa_base64"],
) ?>" alt=""></a><div class="news-card-body"><span class="news-meta"><?= e(
    date("d/m/Y H:i", strtotime($item["publicado_em"])),
) ?> • <?= e(
     $item["autor"],
 ) ?></span><h2 class="mt-2"><a class="text-white text-decoration-none" href="noticia.php?id=<?= $item[
    "id"
] ?>"><?= e($item["titulo"]) ?></a></h2><?php if ($item["resumo"]): ?><p><?= e(
    $item["resumo"],
) ?></p><?php endif; ?><a class="btn btn-danger btn-sm" href="noticia.php?id=<?= $item[
    "id"
] ?>">Ler notícia</a></div></article></div><?php endforeach;
if (
    !$news
): ?><div class="empty-state">Nenhuma notícia publicada ainda.</div><?php endif;
?></div><?php if (
    $pages > 1
): ?><div class="d-flex justify-content-center gap-2 mt-5"><?php for (
    $i = 1;
    $i <= $pages;
    $i++
): ?><a class="btn btn-sm <?= $i === $page
    ? "btn-danger"
    : "btn-outline-light" ?>" href="?pagina=<?= $i ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?></div></main><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script></body></html>
