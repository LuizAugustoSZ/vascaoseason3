<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/sync.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$provided = (string)($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');
$secret = sync_secret();
if ($secret === '' || $provided === '' || !hash_equals($secret, $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Acesso negado.']);
    exit;
}

$pdo = db();
$participant = $pdo->query(
    "SELECT id,nome,time_nome,ativo FROM participantes
     WHERE LOWER(nome) LIKE '%yuri%' OR LOWER(time_nome) LIKE '%yuri%'
     ORDER BY ativo DESC,id"
)->fetchAll();
$accounts = $pdo->query(
    "SELECT id,participante_id,nome,ativo FROM contas
     WHERE LOWER(nome) LIKE '%yuri%' OR participante_id IN (
         SELECT id FROM participantes
         WHERE LOWER(nome) LIKE '%yuri%' OR LOWER(time_nome) LIKE '%yuri%'
     ) ORDER BY id"
)->fetchAll();
$championships = $pdo->query(
    "SELECT id,nome,tipo,formato,status,ativo FROM campeonatos ORDER BY id"
)->fetchAll();
$matches = $pdo->query(
    "SELECT p.id,p.campeonato_id,c.nome campeonato,p.rodada,p.turno,
            p.mandante_id,p.visitante_id,p.status,p.ativo,
            p.gols_mandante,p.gols_visitante
     FROM partidas p
     JOIN campeonatos c ON c.id=p.campeonato_id
     WHERE c.status='ativo'
     ORDER BY p.campeonato_id,p.rodada,p.id"
)->fetchAll();

$summary = [];
foreach ($matches as $match) {
    $championshipId = (int)$match['campeonato_id'];
    $round = (int)$match['rodada'];
    $summary[$championshipId] ??= [
        'campeonato' => $match['campeonato'],
        'total' => 0,
        'ativos' => 0,
        'status' => [],
        'rodadas' => [],
        'yuri' => [],
    ];
    $summary[$championshipId]['total']++;
    $summary[$championshipId]['ativos'] += (int)$match['ativo'];
    $summary[$championshipId]['status'][$match['status']] =
        ($summary[$championshipId]['status'][$match['status']] ?? 0) + 1;
    $summary[$championshipId]['rodadas'][$round] =
        ($summary[$championshipId]['rodadas'][$round] ?? 0) + (int)$match['ativo'];
    if ((int)$match['mandante_id'] === 4 || (int)$match['visitante_id'] === 4) {
        $summary[$championshipId]['yuri'][] = $match;
    }
}

echo json_encode([
    'ok' => true,
    'participants' => $participant,
    'accounts' => $accounts,
    'championships' => $championships,
    'active_championship_summary' => array_values($summary),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
