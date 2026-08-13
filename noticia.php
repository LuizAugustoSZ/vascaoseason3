<?php
// Exibe uma matéria individual do jornal.
require __DIR__ . "/includes/bootstrap.php";
require __DIR__ . "/includes/public-layout.php";
$pdo = db();
$id = (int) ($_GET["id"] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM noticias WHERE id=? AND ativo=1");
$stmt->execute([$id]);
$article = $stmt->fetch();
if (!$article) {
    http_response_code(404);
    exit("Notícia não encontrada.");
}
?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="description" content="<?= e(
    $article["resumo"] ?: $article["titulo"],
) ?>"><title><?= e(
    $article["titulo"],
) ?> | Jornal S3</title><link rel="icon" href="favicon.ico?v=5" sizes="any"><link rel="icon" type="image/png" href="assets/img/favicon-season3.png?v=5"><link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png?v=5"><link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/branding.css?v=5"><link rel="stylesheet" href="assets/css/news.css?v=<?= filemtime(
     __DIR__ . "/assets/css/news.css",
 ) ?>"><link rel="stylesheet" href="assets/css/socials.css?v=<?= filemtime(__DIR__ . "/assets/css/socials.css") ?>"></head><body>
<?php public_navbar('noticias'); ?>
<main class="news-page"><article class="container article-shell"><a class="text-secondary text-decoration-none" href="noticias.php">← Voltar ao jornal</a><span class="eyebrow d-block mt-4">Notícias do servidor</span><h1 class="display-3 fw-bold mt-3"><?= e(
    $article["titulo"],
) ?></h1><?php if ($article["resumo"]): ?><p class="lead text-secondary"><?= e(
    $article["resumo"],
) ?></p><?php endif; ?><p class="news-meta mb-4"><?= e(
    date("d/m/Y H:i", strtotime($article["publicado_em"])),
) ?> • Por <?= e($article["autor"]) ?></p><img class="article-cover" src="<?= e(
    $article["capa_base64"],
) ?>" alt=""><div class="article-content"><?= $article[
    "conteudo"
] ?></div></article></main><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script></body></html>
