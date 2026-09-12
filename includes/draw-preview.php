<?php

// A prévia usa a mesma geração da confirmação, mas desfaz a transação antes de responder.
function draw_preview_finish(PDO $pdo, int $championshipId, array $ids, array $positions = []): void
{
    if (isset($_POST['draw_token'])) {
        return;
    }
    $query = $pdo->prepare('SELECT j.*,a.time_nome time_a,a.sigla sigla_a,a.escudo_url escudo_a,b.time_nome time_b,b.sigla sigla_b,b.escudo_url escudo_b FROM jogos_mata_mata j LEFT JOIN participantes a ON a.id=j.time_a_id LEFT JOIN participantes b ON b.id=j.time_b_id WHERE j.campeonato_id=? ORDER BY j.ordem,j.jogo');
    $query->execute([$championshipId]);
    $games = $query->fetchAll();
    $art = $pdo->prepare('SELECT i.logo_base64,i.trofeu_base64 FROM campeonatos c LEFT JOIN competicao_identidades i ON i.id=c.identidade_id WHERE c.id=?');
    $art->execute([$championshipId]);
    $identity = $art->fetch() ?: [];
    $pdo->rollBack();
    $token = bin2hex(random_bytes(24));
    foreach ($_SESSION['draw_previews'] ?? [] as $key => $draft) {
        if ($draft['expires'] < time()) unset($_SESSION['draw_previews'][$key]);
    }
    if (count($_SESSION['draw_previews'] ?? []) >= 20) array_shift($_SESSION['draw_previews']);
    $_SESSION['draw_previews'][$token] = ['post' => $_POST, 'ids' => $ids, 'positions' => $positions, 'expires' => time() + 86400];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'preview' => true, 'token' => $token, 'name' => $_POST['nome_campeonato'], 'games' => $games, 'positions' => $positions, 'trophy' => $identity['trofeu_base64'] ?? ''], JSON_UNESCAPED_UNICODE);
    exit;
}

function draw_check_available(PDO $pdo, int $identityId, string $name): void
{
    if ($identityId) {
        $lock = $pdo->prepare('SELECT id FROM competicao_identidades WHERE id=? FOR UPDATE');
        $lock->execute([$identityId]);
    }
    $query = $pdo->prepare("SELECT COUNT(*) FROM campeonatos WHERE ativo=1 AND (nome=? OR (identidade_id=? AND status<>'finalizado'))");
    $query->execute([$name, $identityId ?: null]);
    if ((int)$query->fetchColumn()) throw new RuntimeException('Esta edição já existe ou o modelo possui uma edição em andamento. Atualize o sorteador.');
}
