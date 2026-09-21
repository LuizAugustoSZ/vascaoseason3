<?php
declare(strict_types=1);
require __DIR__ . '/../includes/mercado.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE campeonatos (id INTEGER PRIMARY KEY, status TEXT)');
$pdo->exec('CREATE TABLE partidas (id INTEGER PRIMARY KEY, campeonato_id INT, mandante_id INT, visitante_id INT, rodada INT, status TEXT, ativo INT)');
$pdo->exec("INSERT INTO campeonatos VALUES (8,'ativo'),(10,'ativo')");
$insert = $pdo->prepare("INSERT INTO partidas VALUES (?, ?, ?, ?, ?, 'agendada', 1)");
$id = 1;
foreach ([8, 10] as $competition) {
    foreach ([[1, 2], [3, 4]] as [$home, $away]) {
        for ($round = 1; $round <= 24; $round++) $insert->execute([$id++, $competition, $home, $away, $round]);
    }
}
function check_nona(PDO $pdo, int $competition, int $club, bool $expected, string $label): array
{
    $state = mercado_estado_clube($pdo, $competition, $club);
    if ($state['aberto'] !== $expected || mercado_pode_editar(['elenco_confirmado' => 1], $state['proxima_partida'], $state) !== $expected) {
        throw new RuntimeException($label . ': ' . json_encode($state));
    }
    return $state;
}
for ($completed = 0; $completed <= 23; $completed++) {
    $pdo->exec("UPDATE partidas SET status='finalizada' WHERE mandante_id=1 AND rodada<=$completed");
    $exceptionOpen = $completed < 9 || in_array($completed, [13,14,15,21,22,23], true);
    $state = check_nona($pdo, 8, 1, $exceptionOpen, "Brasileirão III: $completed jogos");
    check_nona($pdo, 8, 3, true, 'Outro clube não trava junto');
    check_nona($pdo, 10, 1, $completed === 0 || in_array($completed, [5,6,7,13,14,15,21,22,23], true), 'Outra edição mantém o ciclo');
    if ($completed < 9 && !str_contains(mercado_descricao_janela($state), '9ª rodada')) throw new RuntimeException('Descrição da exceção ausente');
}
// O resultado da nona encerra a exceção mesmo com uma partida anterior pendente.
$pdo->exec("UPDATE partidas SET status='agendada' WHERE campeonato_id=8 AND mandante_id=1");
foreach (['finalizada', 'wo', 'penalidade'] as $status) {
    $pdo->exec("UPDATE partidas SET status='$status' WHERE campeonato_id=8 AND mandante_id=1 AND rodada=9");
    check_nona($pdo, 8, 1, false, "Nona concluída por $status com pendência anterior");
}
$pdo->exec('UPDATE partidas SET ativo=0 WHERE campeonato_id=8 AND mandante_id=1 AND rodada=9');
check_nona($pdo, 8, 1, true, 'Partida inativa não encerra a exceção');
$pdo->exec("UPDATE campeonatos SET status='finalizado' WHERE id=8");
check_nona($pdo, 8, 1, false, 'Campeonato finalizado encerra a inscrição');
echo "OK: exceção individual da nona, isolamento da edição e ciclos seguintes.\n";
