<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/trophy-order.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['ok'=>false,'message'=>'Use POST para salvar.']);
        exit;
    }
    $pdo = db();
    if (!trophy_order_allowed($pdo)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'message'=>'Acesso exclusivo do Admin Master Slower.']);
        exit;
    }
    if (empty($_SESSION['csrf']) || empty($_SERVER['HTTP_X_CSRF_TOKEN'])
        || !hash_equals($_SESSION['csrf'], (string) $_SERVER['HTTP_X_CSRF_TOKEN'])) {
        http_response_code(419);
        echo json_encode(['ok'=>false,'message'=>'Sessão expirada. Atualize a página.']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    competition_identities_ensure_schema($pdo);
    $pdo->beginTransaction();
    $existing = $pdo->query('SELECT id FROM competicao_identidades ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
    $ids = trophy_order_validate($body['ids'] ?? null, $existing);
    $save = $pdo->prepare('UPDATE competicao_identidades SET ordem_exibicao=? WHERE id=?');
    foreach ($ids as $position => $id) $save->execute([$position + 1, $id]);
    $pdo->commit();
    echo json_encode(['ok'=>true,'message'=>'Ordem salva para todos os visitantes.']);
} catch (InvalidArgumentException | JsonException $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['ok'=>false,'message'=>'Ordem inválida ou desatualizada. Atualize a página e tente novamente.']);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Falha ao ordenar taças: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Não foi possível salvar. Tente novamente.']);
}
