<?php

declare(strict_types=1);
require __DIR__ . '/../includes/proximo-confronto.php';
require __DIR__ . '/../includes/competition-schedule.php';

function jogo(int $id, int $rodada, string $status, int $mandante = 1, int $visitante = 2, ?string $data = null): array
{
    return ['id' => $id, 'campeonato_id' => 10, 'etapa' => $rodada, 'status' => $status, 'origem' => 'pontos', 'mandante_id' => $mandante, 'visitante_id' => $visitante, 'data_jogo' => $data];
}

function mata(int $id, string $data): array
{
    return ['id' => $id, 'campeonato_id' => 20, 'etapa' => 'Semifinal', 'status' => 'agendado', 'origem' => 'mata', 'mandante_id' => 1, 'visitante_id' => 3, 'data_jogo' => $data];
}

function verificar(bool $condicao, string $mensagem): void
{
    if (!$condicao) throw new RuntimeException($mensagem);
}

$finalizados = [jogo(1, 13, 'finalizada'), jogo(2, 14, 'finalizada', 2, 1)];
$futuros = [jogo(18, 18, 'agendada'), jogo(16, 16, 'agendada', 2, 1), jogo(15, 15, 'agendada'), jogo(17, 17, 'agendada')];
$ordenados = ordenar_proximos_confrontos($futuros, $finalizados);
verificar((int)$ordenados[0]['etapa'] === 15, 'A menor rodada futura deve ser a 15.');
verificar((int)$ordenados[1]['etapa'] === 16, 'As rodadas devem permanecer em ordem crescente.');

$semQuinze = ordenar_proximos_confrontos([jogo(16, 16, 'agendada')], $finalizados);
verificar((int)$semQuinze[0]['etapa'] === 16, 'Sem rodada 15, a próxima deve ser a 16.');

$comAgendadaAntiga = ordenar_proximos_confrontos([jogo(12, 12, 'agendada'), jogo(15, 15, 'agendada')], $finalizados);
verificar(count($comAgendadaAntiga) === 1 && (int)$comAgendadaAntiga[0]['etapa'] === 15, 'Agendamentos antigos não podem reaparecer.');

$mesmaRodada = ordenar_proximos_confrontos([jogo(4, 15, 'agendada', 1, 2, '2026-08-25 20:00:00'), jogo(3, 15, 'agendada', 2, 1, '2026-08-24 20:00:00')], $finalizados);
verificar((int)$mesmaRodada[0]['id'] === 3, 'Data e hora devem desempatar jogos da mesma rodada.');

verificar(ordenar_proximos_confrontos([], $finalizados) === [], 'Sem partidas futuras, o resultado deve permanecer vazio.');

$calendario = ordenar_proximos_confrontos([
    mata(30, '2026-09-13'),
    jogo(1, 1, 'agendada', 1, 2, '2026-09-08'),
    mata(31, '2026-09-19'),
], []);
verificar((int)$calendario[0]['id'] === 1 && $calendario[0]['origem'] === 'pontos', 'A rodada 1 deve vir antes dos mata-matas futuros.');
verificar((int)$calendario[1]['id'] === 30 && (int)$calendario[2]['id'] === 31, 'Mata-matas devem respeitar a data da competição.');
verificar(competition_round_date('2026-09-08', 1) === '2026-09-08 00:00:00', 'A rodada 1 deve usar a data inicial.');
verificar(competition_round_date('2026-09-08', 14) === '2026-09-21 00:00:00', 'A rodada 14 deve acontecer treze dias depois da rodada 1.');

echo "OK: seleção do próximo confronto validada.\n";
