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
<!doctype html>
<html lang="pt-BR" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="description" content="<?= e(
                                            $article["resumo"] ?: $article["titulo"],
                                        ) ?>">
    <title><?= e(
                $article["titulo"],
            ) ?> | Jornal S3</title>
    <link rel="icon" href="favicon.ico?v=5" sizes="any">
    <link rel="icon" type="image/png" href="assets/img/favicon-season3.png?v=5">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png?v=5">
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/branding.css?v=5">
    <link rel="stylesheet" href="assets/css/news.css?v=<?= filemtime(
                                                            __DIR__ . "/assets/css/news.css",
                                                        ) ?>">
    <link rel="stylesheet" href="assets/css/socials.css?v=<?= filemtime(__DIR__ . "/assets/css/socials.css") ?>">
</head>

<body>
    <?php public_navbar('noticias'); ?>
    <main class="news-page">
        <article class="container article-shell"><a class="text-secondary text-decoration-none" href="noticias.php">← Voltar ao jornal</a><?php if (account_is_admin()): ?><div class="article-admin-actions"><a class="btn btn-sm btn-outline-light" href="admin/index.php?tab=noticias&amp;editar_noticia=<?= (int)$article['id'] ?>">Editar notícia</a><button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#delete-news-modal">Excluir notícia</button></div><?php endif; ?><span class="eyebrow d-block mt-4">Notícias do servidor</span>
            <h1 class="display-3 fw-bold mt-3"><?= e(
                                                    $article["titulo"],
                                                ) ?></h1><?php if ($article["resumo"]): ?><p class="lead text-secondary"><?= e(
                                                                                $article["resumo"],
                                                                            ) ?></p><?php endif; ?><p class="news-meta mb-4"><?= e(
                                                        format_datetime_br($article["publicado_em"]),
                                                    ) ?> • Por <?= e($article["autor"]) ?></p><img class="article-cover" src="<?= e(
                                                                                $article["capa_base64"],
                                                                            ) ?>" alt="">
            <div class="article-content"><?= $article["conteudo"] ?></div>
        </article>
    </main><?php if (account_is_admin()): ?><div class="modal fade news-delete-modal" id="delete-news-modal" tabindex="-1" aria-labelledby="delete-news-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><small class="eyebrow">Exclusão segura</small><h2 class="modal-title" id="delete-news-title">REMOVER NOTÍCIA?</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><p>“<?= e($article['titulo']) ?>” deixará de aparecer no jornal, nos regulamentos e nos links públicos.</p><div class="alert alert-secondary mb-0">O registro continuará preservado no banco de dados como exclusão lógica.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><form method="post" action="admin/index.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="desativar_noticia"><input type="hidden" name="noticia_id" value="<?= (int)$article['id'] ?>"><button class="btn btn-danger">Sim, remover</button></form></div></div></div></div><?php endif; ?><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
