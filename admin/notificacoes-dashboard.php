<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/notifications.php';

master_required();

$pdo = db();
notifications_schema($pdo);

$embedded = isset($_GET['embed']);
$days = max(7, min(90, (int)($_GET['dias'] ?? 30)));

$summary = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN COALESCE(np.news_email, 0)=1 OR COALESCE(np.market_email, 0)=1 THEN 1 ELSE 0 END) AS qualquer,
        SUM(COALESCE(np.news_email, 0)=1) AS noticias,
        SUM(COALESCE(np.market_email, 0)=1) AS mercado,
        SUM(COALESCE(np.news_email, 0)=1 AND COALESCE(np.market_email, 0)=1) AS ambos
     FROM contas c
     LEFT JOIN notification_preferences np ON np.account_id=c.id
     WHERE c.ativo=1"
)->fetch() ?: [];

$delivery = $pdo->prepare(
    "SELECT
        SUM(email_status='sent') AS enviados,
        SUM(email_status='failed') AS falhas,
        SUM(email_status='pending') AS pendentes
     FROM notifications
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
);
$delivery->execute([$days]);
$deliveryMetrics = $delivery->fetch() ?: [];

$accounts = $pdo->query(
    "SELECT
        c.id,c.nome,c.email,c.ultimo_acesso_em,p.time_nome,
        COALESCE(np.news_email,0) AS news_email,
        COALESCE(np.market_email,0) AS market_email,
        (SELECT MAX(n.created_at) FROM notifications n WHERE n.account_id=c.id AND n.email_status='sent') AS ultimo_email,
        (SELECT COUNT(*) FROM notifications n WHERE n.account_id=c.id AND n.email_status='sent') AS emails_enviados
     FROM contas c
     LEFT JOIN participantes p ON p.id=c.participante_id
     LEFT JOIN notification_preferences np ON np.account_id=c.id
     WHERE c.ativo=1
     ORDER BY (COALESCE(np.news_email,0)=1 OR COALESCE(np.market_email,0)=1) DESC,c.nome"
)->fetchAll();

$total = (int)($summary['total'] ?? 0);
$enabled = (int)($summary['qualquer'] ?? 0);
$adoption = $total > 0 ? round(($enabled / $total) * 100, 1) : 0;
?>
<!doctype html>
<html lang="pt-BR" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Adesão às notificações | Vascão S3</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <link rel="stylesheet" href="../assets/css/branding.css?v=5">
    <style>
        body{background:#08090b}.notification-dashboard{padding:<?= $embedded ? '0 0 40px' : '42px 0 70px' ?>}.metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:24px 0}.metric,.adoption-panel{border:1px solid #292d35;border-radius:16px;background:#111318}.metric{padding:20px}.metric small{display:block;color:#9299a5;text-transform:uppercase}.metric strong{display:block;font:800 2.2rem 'Barlow Condensed',sans-serif}.metric span{color:#9299a5;font-size:.8rem}.adoption-panel{overflow:hidden}.adoption-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:20px;border-bottom:1px solid #292d35}.adoption-progress{height:9px;margin-top:10px;border-radius:999px;background:#252932;overflow:hidden}.adoption-progress span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#d71920,#ff5660)}.preference-status{display:inline-flex;align-items:center;gap:6px;font-size:.78rem;font-weight:700}.preference-status:before{content:'';width:8px;height:8px;border-radius:50%;background:#59606c}.preference-status.on{color:#66d59a}.preference-status.on:before{background:#39b982}.delivery-strip{display:flex;flex-wrap:wrap;gap:12px;padding:14px 20px;border-bottom:1px solid #292d35;color:#aeb4bf}.delivery-strip strong{color:#fff}.empty{padding:45px;text-align:center;color:#9299a5}@media(max-width:900px){.metric-grid{grid-template-columns:repeat(2,1fr)}.adoption-head{align-items:flex-start;flex-direction:column}}@media(max-width:520px){.metric-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<main class="notification-dashboard">
<div class="<?= $embedded ? 'container-fluid px-0' : 'container' ?>">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div><span class="eyebrow">Período de avaliação</span><h1 class="display-4 fw-bold mb-0">NOTIFICAÇÕES</h1><p class="text-secondary mb-0">Acompanhe a adesão dos usuários antes de decidir os próximos passos do e-mail.</p></div>
        <form class="d-flex gap-2" method="get"><?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?><select class="form-select" name="dias" aria-label="Período"><?php foreach ([7,30,60,90] as $option): ?><option value="<?= $option ?>" <?= $days === $option ? 'selected' : '' ?>>Últimos <?= $option ?> dias</option><?php endforeach; ?></select><button class="btn btn-outline-danger">Atualizar</button></form>
    </div>

    <div class="metric-grid">
        <article class="metric"><small>Adesão geral</small><strong><?= e(number_format($adoption, 1, ',', '.')) ?>%</strong><span><?= $enabled ?> de <?= $total ?> usuários</span></article>
        <article class="metric"><small>Novas notícias</small><strong><?= (int)($summary['noticias'] ?? 0) ?></strong><span>usuários ativaram</span></article>
        <article class="metric"><small>Mercado</small><strong><?= (int)($summary['mercado'] ?? 0) ?></strong><span>usuários ativaram</span></article>
        <article class="metric"><small>Todas as opções</small><strong><?= (int)($summary['ambos'] ?? 0) ?></strong><span>usuários ativaram ambas</span></article>
    </div>

    <section class="adoption-panel">
        <div class="adoption-head"><div class="flex-grow-1"><span class="eyebrow">Visão nominal</span><h2 class="h4 mb-0">Quem ativou os e-mails</h2><div class="adoption-progress" title="<?= e((string)$adoption) ?>% de adesão"><span style="width:<?= min(100, $adoption) ?>%"></span></div></div><span class="text-secondary"><?= $enabled ?> com e-mail · <?= max(0, $total - $enabled) ?> sem e-mail</span></div>
        <div class="delivery-strip"><span>Período: <strong><?= $days ?> dias</strong></span><span>Enviados: <strong><?= (int)($deliveryMetrics['enviados'] ?? 0) ?></strong></span><span>Falhas: <strong><?= (int)($deliveryMetrics['falhas'] ?? 0) ?></strong></span><span>Pendentes: <strong><?= (int)($deliveryMetrics['pendentes'] ?? 0) ?></strong></span></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Usuário</th><th>Time</th><th>Notícias</th><th>Mercado</th><th>E-mails enviados</th><th>Último envio</th></tr></thead><tbody><?php foreach ($accounts as $account): ?><tr><td><strong><?= e((string)$account['nome']) ?></strong><small class="d-block text-secondary"><?= e((string)$account['email']) ?></small></td><td><?= e((string)($account['time_nome'] ?: 'Sem time associado')) ?></td><td><span class="preference-status <?= (int)$account['news_email'] === 1 ? 'on' : '' ?>"><?= (int)$account['news_email'] === 1 ? 'Ativado' : 'Desativado' ?></span></td><td><span class="preference-status <?= (int)$account['market_email'] === 1 ? 'on' : '' ?>"><?= (int)$account['market_email'] === 1 ? 'Ativado' : 'Desativado' ?></span></td><td><?= (int)$account['emails_enviados'] ?></td><td class="text-nowrap"><?= $account['ultimo_email'] ? e(format_datetime_br((string)$account['ultimo_email'])) : '<span class="text-secondary">Nenhum</span>' ?></td></tr><?php endforeach; ?><?php if (!$accounts): ?><tr><td colspan="6" class="empty">Nenhuma conta ativa encontrada.</td></tr><?php endif; ?></tbody></table></div>
    </section>
</div>
</main>
</body>
</html>
