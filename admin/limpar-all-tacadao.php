<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
admin_required();
if (!account_is_master()) {
    http_response_code(403);
    exit('Acesso restrito ao Admin Master.');
}

$pdo = db();
$teams = $pdo->query("SELECT id,nome,time_nome FROM participantes WHERE LOWER(time_nome) LIKE '%tacad%'")->fetchAll();
if (count($teams) !== 1) {
    http_response_code(409);
    exit('Não foi possível identificar unicamente o All Tacadão. Encontrados: ' . count($teams));
}
$team = $teams[0];
$participantId = (int) $team['id'];
$accountStmt = $pdo->prepare("SELECT id,nome,participante_id FROM contas WHERE LOWER(nome)=LOWER('haori404') AND ativo=1");
$accountStmt->execute();
$account = $accountStmt->fetch();
if (!$account || (int) $account['participante_id'] !== $participantId) {
    http_response_code(409);
    exit('A conta haori404 não está vinculada ao All Tacadão. Nenhum dado foi alterado.');
}

$queries = [
    'Partidas de pontos corridos' => "SELECT COUNT(*) FROM partidas WHERE mandante_id=? OR visitante_id=?",
    'Jogos de mata-mata' => "SELECT COUNT(*) FROM jogos_mata_mata WHERE time_a_id=? OR time_b_id=? OR vencedor_id=?",
    'Gols em partidas' => "SELECT COUNT(*) FROM gols_partida WHERE participante_id=? OR partida_id IN (SELECT id FROM partidas WHERE mandante_id=? OR visitante_id=?)",
    'Gols em mata-mata' => "SELECT COUNT(*) FROM gols_mata_mata WHERE participante_id=? OR jogo_mata_mata_id IN (SELECT id FROM jogos_mata_mata WHERE time_a_id=? OR time_b_id=? OR vencedor_id=?)",
    'Súmulas' => "SELECT COUNT(*) FROM sumulas_dreamteam WHERE partida_id IN (SELECT id FROM partidas WHERE mandante_id=? OR visitante_id=?) OR jogo_mata_mata_id IN (SELECT id FROM jogos_mata_mata WHERE time_a_id=? OR time_b_id=? OR vencedor_id=?)",
    'Registros de artilharia' => "SELECT COUNT(*) FROM artilharia WHERE participante_id=?",
    'Jogadores do elenco' => "SELECT COUNT(*) FROM jogadores_elenco WHERE participante_id=?",
    'Movimentações do mercado' => "SELECT COUNT(*) FROM movimentacoes_elenco WHERE participante_id=?",
    'Registros de cofre' => "SELECT COUNT(*) FROM clubes_campeonato WHERE participante_id=?",
    'Títulos preservados' => "SELECT COUNT(*) FROM titulos WHERE participante_id=?",
];
$params = [
    [$participantId, $participantId],
    [$participantId, $participantId, $participantId],
    [$participantId, $participantId, $participantId],
    [$participantId, $participantId, $participantId, $participantId],
    [$participantId, $participantId, $participantId, $participantId, $participantId],
    [$participantId], [$participantId], [$participantId], [$participantId], [$participantId],
];
$counts = [];
foreach ($queries as $label => $sql) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_shift($params));
    $counts[$label] = (int) $stmt->fetchColumn();
}

$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['confirmacao'] ?? '') !== 'APAGAR ALL TACADAO') exit('Confirmação inválida.');
    $pdo->beginTransaction();
    try {
        $matchIds = "SELECT id FROM partidas WHERE mandante_id=$participantId OR visitante_id=$participantId";
        $knockoutIds = "SELECT id FROM jogos_mata_mata WHERE time_a_id=$participantId OR time_b_id=$participantId OR vencedor_id=$participantId";
        $pdo->exec("DELETE FROM gols_partida WHERE participante_id=$participantId OR partida_id IN ($matchIds)");
        $pdo->exec("DELETE FROM gols_mata_mata WHERE participante_id=$participantId OR jogo_mata_mata_id IN ($knockoutIds)");
        $pdo->exec("DELETE FROM sumulas_dreamteam WHERE partida_id IN ($matchIds) OR jogo_mata_mata_id IN ($knockoutIds)");
        $pdo->exec("DELETE FROM artilharia WHERE participante_id=$participantId");
        $pdo->exec("DELETE FROM movimentacoes_elenco WHERE participante_id=$participantId");
        $pdo->exec("DELETE FROM jogadores_elenco WHERE participante_id=$participantId");
        $pdo->exec("DELETE FROM clubes_campeonato WHERE participante_id=$participantId");
        $pdo->exec("DELETE FROM partidas WHERE mandante_id=$participantId OR visitante_id=$participantId");
        $pdo->exec("DELETE FROM jogos_mata_mata WHERE time_a_id=$participantId OR time_b_id=$participantId OR vencedor_id=$participantId");
        $pdo->commit();
        $done = true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Limpeza All Tacadão</title><link rel="stylesheet" href="../assets/css/style.css"></head><body><main class="container py-5"><div class="panel p-4"><h1>Limpeza do All Tacadão</h1><?php if ($done): ?><div class="alert alert-success">Limpeza concluída. Títulos e vínculo com haori404 foram preservados.</div><?php else: ?><p><strong>Clube:</strong> <?= e($team['time_nome']) ?> (ID <?= $participantId ?>)<br><strong>Conta preservada:</strong> <?= e($account['nome']) ?> (ID <?= (int)$account['id'] ?>)</p><table class="table"><tbody><?php foreach ($counts as $label=>$count): ?><tr><td><?= e($label) ?></td><td><strong><?= $count ?></strong></td></tr><?php endforeach; ?></tbody></table><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Digite <strong>APAGAR ALL TACADAO</strong></label><input class="form-control my-3" name="confirmacao" required><button class="btn btn-danger">Executar limpeza definitiva</button></form><?php endif; ?></div></main></body></html>
