<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/public-layout.php';
require __DIR__ . '/includes/notifications.php';

if (!account_logged_in()) {
    header('Location: login.php');
    exit;
}

$pdo = db();
notifications_schema($pdo);
$accountId = (int)$_SESSION['conta_id'];

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preferences') {
    verify_csrf();
    $newsEmail = isset($_POST['news_email']) ? 1 : 0;
    $marketEmail = isset($_POST['market_email']) ? 1 : 0;
    $stmt = $pdo->prepare('INSERT INTO notification_preferences (account_id, news_email, market_email)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE news_email=VALUES(news_email), market_email=VALUES(market_email)');
    $stmt->execute([$accountId, $newsEmail, $marketEmail]);
    $notice = 'Preferências salvas com sucesso.';
}

$q = $pdo->prepare('SELECT * FROM notification_preferences WHERE account_id=? LIMIT 1');
$q->execute([$accountId]);
$preferences = $q->fetch() ?: [];
?>
<!doctype html>
<html lang="pt-BR" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notificações | Vascão Season 3</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/notifications.css?v=<?= filemtime(__DIR__ . '/assets/css/notifications.css') ?>">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <?php public_navbar('notificacoes'); ?>

    <main class="container notifications-page-shell">
        <div class="notifications-page-head">
            <h1>Notificações</h1>
            <p>Escolha quais avisos deseja receber por e-mail. Os avisos continuam disponíveis no sino.</p>
        </div>

        <div id="preferences-status" <?= $notice ? 'class="alert alert-success d-flex align-items-center gap-2 mb-4"' : 'class="d-none"' ?>>
            <?php if ($notice): ?><i data-lucide="circle-check"></i> <?= e($notice) ?><?php endif; ?>
        </div>

        <!-- CARD: PREFERÊNCIAS DE NOTIFICAÇÃO -->
        <section class="notification-card">
            <div class="notification-card-header">
                <div class="notification-card-header-icon">
                    <i data-lucide="settings"></i>
                </div>
                <div>
                    <h2>Preferências de notificação</h2>
                    <p>Selecione os tipos de avisos que você deseja receber por e-mail.</p>
                </div>
            </div>

            <form id="notification-preferences" method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="preferences">

                <!-- Linha 1: Novas Notícias -->
                <div class="notification-pref-item">
                    <div class="notification-pref-left">
                        <div class="notification-pref-icon">
                            <i data-lucide="newspaper"></i>
                        </div>
                        <div class="notification-pref-info">
                            <strong>Novas notícias</strong>
                            <span>Receba avisos quando novas matérias forem publicadas.</span>
                        </div>
                    </div>
                    <label class="notification-switch" title="Ativar ou desativar aviso por e-mail de novas notícias">
                        <input type="checkbox" name="news_email" value="1" <?= !empty($preferences['news_email']) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <!-- Linha 2: Mercado -->
                <div class="notification-pref-item">
                    <div class="notification-pref-left">
                        <div class="notification-pref-icon">
                            <i data-lucide="chart-no-axes-column-increasing"></i>
                        </div>
                        <div class="notification-pref-info">
                            <strong>Mercado: abertura, fechamento e lembretes</strong>
                            <span>Avisos sobre movimentações e prazos do mercado.</span>
                        </div>
                    </div>
                    <label class="notification-switch" title="Ativar ou desativar aviso por e-mail sobre o mercado">
                        <input type="checkbox" name="market_email" value="1" <?= !empty($preferences['market_email']) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="notification-card-actions">
                    <button type="submit" class="btn-save-preferences">
                        <i data-lucide="save"></i>
                        <span>Salvar preferências</span>
                    </button>
                </div>
            </form>
        </section>

        <!-- CARD: ÚLTIMOS AVISOS -->
        <section class="notification-card">
            <div class="notification-card-header">
                <div class="notification-card-header-icon">
                    <i data-lucide="bell"></i>
                </div>
                <div>
                    <h2>Últimos avisos</h2>
                    <p>Aqui você acompanha seus últimos avisos recebidos.</p>
                </div>
            </div>

            <div id="notification-history">
                <div class="notification-empty-state py-5">
                    <div class="notification-empty-circle">
                        <i data-lucide="bell"></i>
                    </div>
                    <strong class="fs-5">Tudo tranquilo por aqui</strong>
                    <span>Quando houver novos avisos, eles aparecerão aqui.</span>
                </div>
            </div>
        </section>
    </main>

    <?php public_footer(); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
