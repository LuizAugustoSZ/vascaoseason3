<?php
declare(strict_types=1);
require __DIR__ . '/../includes/mercado.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE campeonatos (id INTEGER PRIMARY KEY, status TEXT)');
$pdo->exec('CREATE TABLE partidas (id INTEGER PRIMARY KEY, campeonato_id INT, mandante_id INT, visitante_id INT, rodada INT, status TEXT, ativo INT)');
$pdo->exec("INSERT INTO campeonatos VALUES (1,'em_andamento')");
function check_window(PDO $pdo, bool $expected, string $label): array {
    $state = mercado_estado_clube($pdo, 1, 1);
    if ($state['aberto'] !== $expected || mercado_pode_editar(['elenco_confirmado' => 1], $state['proxima_partida'], $state) !== $expected) {
        throw new RuntimeException($label . ': ' . json_encode($state));
    }
    return $state;
}
check_window($pdo, true, 'Sem agenda');
$insert = $pdo->prepare("INSERT INTO partidas VALUES (?,1,1,2,?,'agendada',1)");
for ($round = 1; $round <= 33; $round++) $insert->execute([$round, $round]);
check_window($pdo, true, 'Antes da estreia com elenco confirmado');
// Quatro ciclos inteiros, incluindo os limites 5/8, 13/16, 21/24 e 29/32.
for ($completed = 1; $completed <= 32; $completed++) {
    $pdo->exec("UPDATE partidas SET status='finalizada' WHERE id=$completed");
    $state = check_window($pdo, in_array($completed, [5,6,7,13,14,15,21,22,23,29,30,31], true), "$completed cumpridas");
    $description = mercado_descricao_janela($state);
    $opensAfter = [5,13,21,29,37][intdiv($completed, 8)];
    if (!str_contains($description, "após cumprir $opensAfter rodadas")
        || !str_contains($description, ($opensAfter + 1).'ª, '.($opensAfter + 2).'ª e '.($opensAfter + 3).'ª')) {
        throw new RuntimeException('Descricao incorreta: '.$description);
    }
}
$pdo->exec("UPDATE partidas SET status='wo' WHERE id=33");
check_window($pdo, true, 'Participacao concluida');
$pdo->exec("UPDATE partidas SET status='agendada'");
$pdo->exec('DELETE FROM partidas WHERE rodada<4');
check_window($pdo, true, 'Folgas antes da estreia');
$pdo->exec("UPDATE partidas SET status='penalidade' WHERE rodada BETWEEN 4 AND 8");
$pdo->exec('DELETE FROM partidas WHERE rodada=6');
$state = check_window($pdo, true, 'Cinco etapas com uma folga apos estreia');
if ($state['etapas_concluidas'] !== 5 || $state['folgas'] !== 1) throw new RuntimeException('Contagem de folgas');
if (!str_contains(mercado_descricao_janela($state), 'cumpriu 5 rodada(s), incluindo 1 folga(s)')) throw new RuntimeException('Descricao de folgas');
$pdo->exec("UPDATE partidas SET status='finalizada' WHERE rodada=10");
check_window($pdo, true, 'Jogo futuro nao avanca alem da pendencia');
echo "OK: ciclos, permissao, estreia, folgas, pendencia e conclusao.\n";
