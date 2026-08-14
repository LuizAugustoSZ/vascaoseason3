<?php

declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
admin_required();

header('Content-Type: application/json; charset=utf-8');
function prompt_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = db();
    $campeonatoId = (int)($_GET['campeonato_id'] ?? 0);
    $rodada = (int)($_GET['rodada'] ?? 0);
    if ($campeonatoId < 1) throw new RuntimeException('Selecione um campeonato.');

    $championshipStmt = $pdo->prepare("SELECT nome FROM campeonatos WHERE id=? AND ativo=1 LIMIT 1");
    $championshipStmt->execute([$campeonatoId]);
    $campeonato = (string)($championshipStmt->fetchColumn() ?: '');
    if ($campeonato === '') throw new RuntimeException('Campeonato não encontrado.');

    $roundStmt = $pdo->prepare("SELECT rodada,MAX(CASE WHEN gols_mandante IS NOT NULL AND gols_visitante IS NOT NULL THEN 1 ELSE 0 END) tem_resultado FROM partidas WHERE campeonato_id=? AND ativo=1 GROUP BY rodada ORDER BY rodada");
    $roundStmt->execute([$campeonatoId]);
    $rodadas = array_map(static fn(array $item): array => ['rodada' => (int)$item['rodada'], 'tem_resultado' => (bool)$item['tem_resultado']], $roundStmt->fetchAll());
    $rodadaAtual = 0;
    foreach ($rodadas as $item) if ($item['tem_resultado']) $rodadaAtual = max($rodadaAtual, $item['rodada']);
    if ($rodadaAtual < 1 && $rodadas) $rodadaAtual = $rodadas[0]['rodada'];
    $cicloAtual = $rodadaAtual > 0 ? intdiv($rodadaAtual - 1, 8) + 1 : 1;
    $inicioCicloAtual = (($cicloAtual - 1) * 8) + 1;
    $fimCicloAtual = $inicioCicloAtual + 7;
    $contextoAtual = "Rodada atual: {$rodadaAtual}ª · Ciclo {$cicloAtual}: {$inicioCicloAtual}ª à {$fimCicloAtual}ª rodada";
    if ($rodada < 1) prompt_json(['ok' => true, 'rodadas' => $rodadas, 'rodada_atual' => $rodadaAtual, 'contexto' => $contextoAtual]);
    if (!in_array($rodada, array_column($rodadas, 'rodada'), true)) throw new RuntimeException('Rodada não encontrada neste campeonato.');

    $ciclo = intdiv($rodada - 1, 8) + 1;
    $inicioCiclo = (($ciclo - 1) * 8) + 1;
    $fimCiclo = $inicioCiclo + 7;
    $posicaoCiclo = (($rodada - 1) % 8) + 1;
    $faseCiclo = $posicaoCiclo <= 5 ? 'elenco travado' : 'janela de transferências aberta';
    $contexto = "{$rodada}ª rodada · Ciclo {$ciclo} ({$inicioCiclo}ª à {$fimCiclo}ª) · {$faseCiclo}";

    $matchesStmt = $pdo->prepare("SELECT p.id,p.status,p.gols_mandante,p.gols_visitante,p.data_partida,m.time_nome mandante,m.nome tecnico_mandante,m.sigla mandante_sigla,v.time_nome visitante,v.nome tecnico_visitante,v.sigla visitante_sigla,s.estadio,s.clima,s.duracao,s.craque,s.craque_nota,s.dados_json,s.texto_original FROM partidas p JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id LEFT JOIN sumulas_dreamteam s ON s.origem='pontos' AND s.partida_id=p.id WHERE p.campeonato_id=? AND p.rodada=? AND p.ativo=1 ORDER BY p.id");
    $matchesStmt->execute([$campeonatoId, $rodada]);
    $partidas = $matchesStmt->fetchAll();
    if (!$partidas) throw new RuntimeException('Nenhuma partida cadastrada nesta rodada.');
    $finishedMatches = count(array_filter($partidas, static fn(array $match): bool => in_array($match['status'], ['finalizada','wo'], true)));
    $roundStatus = $finishedMatches === count($partidas) ? 'rodada encerrada' : "rodada em andamento ({$finishedMatches} de " . count($partidas) . ' partidas encerradas)';

    $facts = [];
    $goalTotals = [];
    foreach ($partidas as $partida) {
        $placar = $partida['gols_mandante'] === null || $partida['gols_visitante'] === null
            ? 'placar ainda não informado'
            : (int)$partida['gols_mandante'] . ' x ' . (int)$partida['gols_visitante'];
        $lines = [sprintf('%s (técnico: %s) %s %s (técnico: %s) — status: %s', $partida['mandante'], $partida['tecnico_mandante'], $placar, $partida['visitante'], $partida['tecnico_visitante'], $partida['status'])];
        if ($partida['data_partida']) $lines[] = 'Data: ' . date('d/m/Y H:i', strtotime((string)$partida['data_partida']));
        if ($partida['estadio']) $lines[] = 'Estádio: ' . $partida['estadio'];
        if ($partida['clima']) $lines[] = 'Clima: ' . $partida['clima'];
        if ($partida['duracao']) $lines[] = 'Duração: ' . (int)$partida['duracao'] . ' minutos';
        if ($partida['craque']) $lines[] = 'Craque: ' . $partida['craque'] . ($partida['craque_nota'] !== null ? ' (nota ' . $partida['craque_nota'] . ')' : '');

        $parsed = $partida['dados_json'] ? json_decode((string)$partida['dados_json'], true) : null;
        if (is_array($parsed)) {
            $teamNames = [];
            foreach (($parsed['teams'] ?? []) as $team) {
                $code = (string)($team['code'] ?? '');
                $teamNames[$code] = (string)($team['name'] ?? $code);
                if (!empty($team['stats'])) $lines[] = 'Estatísticas de ' . $teamNames[$code] . ': ' . json_encode($team['stats'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            foreach (($parsed['events'] ?? []) as $event) {
                $type = (string)($event['type'] ?? 'evento');
                $team = $teamNames[(string)($event['team_code'] ?? '')] ?? (string)($event['team_code'] ?? '');
                $minute = ($event['minute'] ?? '') !== '' ? (string)$event['minute'] . "'" : 'minuto não informado';
                $detail = match ($type) {
                    'goal' => 'Gol de ' . ($event['player'] ?? 'não informado') . (!empty($event['assist']) ? ', assistência de ' . $event['assist'] : '') . ', tipo ' . ($event['goal_type'] ?? 'normal') . (!empty($event['cancelled']) ? ' (ANULADO)' : ''),
                    'yellow_card' => 'Cartão amarelo para ' . ($event['player'] ?? 'não informado') . (!empty($event['via_var']) ? ' após VAR' : ''),
                    'red_card' => 'Cartão vermelho para ' . ($event['player'] ?? 'não informado'),
                    'var_goal_cancelled' => 'VAR anulou gol de ' . ($event['player'] ?? 'não informado'),
                    'var_penalty_cancelled' => 'VAR cancelou pênalti envolvendo ' . ($event['player'] ?? 'não informado'),
                    'injury' => 'Lesão de ' . ($event['player'] ?? 'não informado'),
                    'substitution' => 'Substituição: saiu ' . ($event['player_out'] ?? 'não informado') . ', entrou ' . ($event['player_in'] ?? 'não informado'),
                    default => ucfirst(str_replace('_', ' ', $type)) . ': ' . ($event['description'] ?? ''),
                };
                $lines[] = $minute . ' — ' . $detail . ($team !== '' ? ' (' . $team . ')' : '');
                if ($type === 'goal' && empty($event['cancelled']) && ($event['goal_type'] ?? '') !== 'contra') {
                    $player = trim((string)($event['player'] ?? ''));
                    if ($player !== '') $goalTotals[$player] = ($goalTotals[$player] ?? 0) + 1;
                }
            }
        } else {
            $lines[] = 'Súmula detalhada não importada para esta partida.';
        }
        if (!empty($partida['texto_original'])) $lines[] = "SÚMULA ORIGINAL COMPLETA:\n" . trim((string)$partida['texto_original']);
        $facts[] = implode("\n", $lines);
    }
    arsort($goalTotals);
    $scorers = $goalTotals ? implode(', ', array_map(static fn($name, $goals) => $name . ' (' . $goals . ')', array_keys($goalTotals), $goalTotals)) : 'nenhum artilheiro identificado nas súmulas';

    $campaignStmt = $pdo->prepare("SELECT p.mandante_id,p.visitante_id,p.gols_mandante,p.gols_visitante,m.time_nome mandante,v.time_nome visitante FROM partidas p JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id WHERE p.campeonato_id=? AND p.ativo=1 AND p.rodada<=? AND p.status IN ('finalizada','wo') ORDER BY p.rodada,p.id");
    $campaignStmt->execute([$campeonatoId, $rodada]);
    $campaignGames = $campaignStmt->fetchAll();
    $table = [];
    $totalGoals = 0;
    foreach ($campaignGames as $game) {
        foreach ([['id'=>(int)$game['mandante_id'],'nome'=>$game['mandante']],['id'=>(int)$game['visitante_id'],'nome'=>$game['visitante']]] as $team) {
            $table[$team['id']] ??= ['nome'=>$team['nome'],'j'=>0,'v'=>0,'e'=>0,'d'=>0,'gp'=>0,'gc'=>0,'sg'=>0,'pts'=>0];
        }
        $home=(int)$game['mandante_id']; $away=(int)$game['visitante_id']; $hg=(int)$game['gols_mandante']; $ag=(int)$game['gols_visitante'];
        $table[$home]['j']++; $table[$away]['j']++; $table[$home]['gp']+=$hg; $table[$home]['gc']+=$ag; $table[$away]['gp']+=$ag; $table[$away]['gc']+=$hg;
        if ($hg>$ag) { $table[$home]['v']++; $table[$home]['pts']+=3; $table[$away]['d']++; }
        elseif ($hg<$ag) { $table[$away]['v']++; $table[$away]['pts']+=3; $table[$home]['d']++; }
        else { $table[$home]['e']++; $table[$away]['e']++; $table[$home]['pts']++; $table[$away]['pts']++; }
        $totalGoals += $hg + $ag;
    }
    foreach ($table as &$team) $team['sg']=$team['gp']-$team['gc'];
    unset($team);
    $table=array_values($table);
    usort($table, static function(array $a,array $b): int { foreach(['pts','v','sg','gp'] as $key) if($a[$key]!==$b[$key]) return $b[$key]<=>$a[$key]; return strcmp($a['nome'],$b['nome']); });
    $standingsLines=[];
    foreach($table as $index=>$team) $standingsLines[] = sprintf('%dº %s — %d pts | J %d | V %d | E %d | D %d | GP %d | GC %d | SG %+d', $index+1,$team['nome'],$team['pts'],$team['j'],$team['v'],$team['e'],$team['d'],$team['gp'],$team['gc'],$team['sg']);
    $average = count($campaignGames) ? number_format($totalGoals / count($campaignGames), 2, ',', '.') : '0,00';

    $prompt = "Você é um jornalista esportivo responsável pela cobertura do campeonato {$campeonato}.\n\n"
        . "Escreva uma notícia completa sobre a {$rodada}ª rodada usando EXCLUSIVAMENTE os dados fornecidos. Não invente fatos, falas, números ou acontecimentos. Quando algo não estiver disponível, omita. Explore o máximo de informação real: posse de bola, finalizações, chutes no alvo, passes, defesas, cartões, VAR, pênaltis, assistências, minutos dos gols, bolas paradas, craques e notas. Compare os números para explicar domínio, equilíbrio e atuações individuais.\n\n"
        . "PADRÃO EDITORIAL OBRIGATÓRIO:\n1. TÍTULO: manchete forte e informativa, com até 180 caracteres, citando o principal impacto da rodada.\n2. RESUMO: um único parágrafo de até 500 caracteres resumindo líderes, destaques e fatos marcantes.\n3. DESCRIÇÃO: comece repetindo o título e faça uma abertura geral. Depois crie blocos com subtítulos em negrito e emojis pertinentes, como **🥇 Liderança**, **🔥 Destaque**, **🎩 Atuação individual**, **🧤 Goleiros**, **🎯 Pênaltis, cartões e VAR**, **📊 Classificação** e **📋 Resultados da rodada**. Escolha somente blocos sustentados pelos dados. Analise as partidas importantes com placar, gols, minutos, assistências, estatísticas, craque e nota. Feche com a classificação relevante, a lista completa de resultados e uma projeção genérica para a próxima rodada, sem inventar confrontos. Use negrito em nomes e números importantes. Emojis apenas nos subtítulos.\n\n"
        . "ENTREGUE EXATAMENTE SEPARADO ASSIM:\nTÍTULO:\n[texto]\n\nRESUMO:\n[texto]\n\nDESCRIÇÃO:\n[matéria completa]\n\n"
        . "CONTEXTO DO CAMPEONATO:\nCAMPEONATO: {$campeonato}\nRODADA ANALISADA: {$rodada}ª\nSTATUS DA RODADA: {$roundStatus}. Nunca diga que a rodada terminou se ela estiver em andamento.\nCICLO: {$ciclo}\nINÍCIO DO CICLO: {$inicioCiclo}ª rodada\nFIM DO CICLO: {$fimCiclo}ª rodada\nFASE DO CICLO: {$faseCiclo}\nREGRA: cada ciclo possui cinco rodadas com elenco travado e três rodadas com alterações liberadas. Só mencione o ciclo se isso for editorialmente relevante.\nARTILHEIROS DA RODADA: {$scorers}\nTOTAL ACUMULADO ATÉ A RODADA: {$totalGoals} gols em " . count($campaignGames) . " jogos; média de {$average} gols por partida.\n\nCLASSIFICAÇÃO APÓS A RODADA:\n" . implode("\n", $standingsLines) . "\n\nDADOS DAS PARTIDAS:\n\n"
        . implode("\n\n---\n\n", $facts);

    prompt_json(['ok' => true, 'prompt' => $prompt, 'partidas' => count($partidas), 'rodada' => $rodada, 'contexto' => $contexto]);
} catch (Throwable $error) {
    prompt_json(['ok' => false, 'message' => $error->getMessage()], 422);
}
