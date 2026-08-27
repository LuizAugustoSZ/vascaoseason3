<?php

declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/mata-prompt.php';
admin_required();

header('Content-Type: application/json; charset=utf-8');
function prompt_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function league_prompt_data(PDO $pdo, int $championshipId): array
{
    $stmt = $pdo->prepare("SELECT p.id,p.rodada,p.status,p.mandante_id,p.visitante_id,p.gols_mandante,p.gols_visitante,p.data_partida,m.time_nome mandante,m.nome tecnico_mandante,v.time_nome visitante,v.nome tecnico_visitante,s.estadio,s.clima,s.duracao,s.craque,s.craque_nota,s.dados_json,s.texto_original FROM partidas p JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id LEFT JOIN sumulas_dreamteam s ON s.origem='pontos' AND s.partida_id=p.id WHERE p.campeonato_id=? AND p.ativo=1 ORDER BY p.rodada,p.id");
    $stmt->execute([$championshipId]);
    $matches = $stmt->fetchAll();
    $table = [];
    $form = [];
    $remaining = [];
    $scorers = [];
    $assists = [];
    $totals = ['jogos'=>0,'gols'=>0,'amarelos'=>0,'vermelhos'=>0,'finalizacoes'=>0,'chutes_no_gol'=>0,'defesas'=>0,'escanteios'=>0];
    $facts = [];
    foreach ($matches as $match) {
        foreach ([['id'=>(int)$match['mandante_id'],'nome'=>$match['mandante']], ['id'=>(int)$match['visitante_id'],'nome'=>$match['visitante']]] as $team) {
            $table[$team['id']] ??= ['id'=>$team['id'],'nome'=>$team['nome'],'j'=>0,'v'=>0,'e'=>0,'d'=>0,'gp'=>0,'gc'=>0,'sg'=>0,'pts'=>0];
            $form[$team['id']] ??= [];
            $remaining[$team['id']] ??= [];
        }
        $finished = in_array((string)$match['status'], ['finalizada','wo'], true) && $match['gols_mandante'] !== null && $match['gols_visitante'] !== null;
        $home=(int)$match['mandante_id']; $away=(int)$match['visitante_id'];
        if (!$finished) {
            $date = $match['data_partida'] ? date('d/m/Y H:i', strtotime((string)$match['data_partida'])) : 'data não informada';
            $remaining[$home][] = $match['rodada'] . 'ª: ' . $match['visitante'] . ' (casa; ' . $date . ')';
            $remaining[$away][] = $match['rodada'] . 'ª: ' . $match['mandante'] . ' (fora; ' . $date . ')';
            $facts[] = $match['rodada'] . 'ª rodada: ' . $match['mandante'] . ' x ' . $match['visitante'] . ' — ' . $match['status'] . ' — ' . $date;
            continue;
        }
        $hg=(int)$match['gols_mandante']; $ag=(int)$match['gols_visitante'];
        $totals['jogos']++; $totals['gols'] += $hg + $ag;
        $table[$home]['j']++; $table[$away]['j']++; $table[$home]['gp']+=$hg; $table[$home]['gc']+=$ag; $table[$away]['gp']+=$ag; $table[$away]['gc']+=$hg;
        if ($hg>$ag) { $table[$home]['v']++; $table[$home]['pts']+=3; $table[$away]['d']++; $form[$home][]='V'; $form[$away][]='D'; }
        elseif ($hg<$ag) { $table[$away]['v']++; $table[$away]['pts']+=3; $table[$home]['d']++; $form[$home][]='D'; $form[$away][]='V'; }
        else { $table[$home]['e']++; $table[$away]['e']++; $table[$home]['pts']++; $table[$away]['pts']++; $form[$home][]='E'; $form[$away][]='E'; }
        $detail = [$match['rodada'] . 'ª rodada: ' . $match['mandante'] . ' ' . $hg . ' x ' . $ag . ' ' . $match['visitante']];
        if ($match['data_partida']) $detail[] = 'Data: ' . date('d/m/Y H:i', strtotime((string)$match['data_partida']));
        if ($match['estadio']) $detail[] = 'Estádio: ' . $match['estadio'];
        if ($match['clima']) $detail[] = 'Clima: ' . $match['clima'];
        if ($match['duracao']) $detail[] = 'Duração: ' . (int)$match['duracao'] . ' minutos';
        if ($match['craque']) $detail[] = 'Craque: ' . $match['craque'] . ($match['craque_nota'] !== null ? ' (nota ' . $match['craque_nota'] . ')' : '');
        $parsed = $match['dados_json'] ? json_decode((string)$match['dados_json'], true) : null;
        if (is_array($parsed)) {
            foreach (($parsed['teams'] ?? []) as $team) {
                if (!empty($team['stats'])) {
                    $detail[] = 'Estatísticas de ' . ($team['name'] ?? $team['code'] ?? 'time') . ': ' . json_encode($team['stats'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    foreach ((array)$team['stats'] as $key=>$value) {
                        $normalized = strtolower((string)$key);
                        $number = is_numeric($value) ? (int)$value : 0;
                        if (str_contains($normalized, 'finaliza')) $totals['finalizacoes'] += $number;
                        if (str_contains($normalized, 'gol') && str_contains($normalized, 'chute')) $totals['chutes_no_gol'] += $number;
                        if (str_contains($normalized, 'defesa')) $totals['defesas'] += $number;
                        if (str_contains($normalized, 'escante')) $totals['escanteios'] += $number;
                    }
                }
            }
            foreach (($parsed['events'] ?? []) as $event) {
                $type=(string)($event['type'] ?? ''); $player=trim((string)($event['player'] ?? '')); $assist=trim((string)($event['assist'] ?? ''));
                if ($type==='goal' && empty($event['cancelled']) && ($event['goal_type'] ?? '') !== 'contra' && $player!=='') $scorers[$player]=($scorers[$player] ?? 0)+1;
                if ($type==='goal' && empty($event['cancelled']) && $assist!=='') $assists[$assist]=($assists[$assist] ?? 0)+1;
                if ($type==='yellow_card') $totals['amarelos']++;
                if ($type==='red_card') $totals['vermelhos']++;
            }
        }
        if (!empty($match['texto_original'])) $detail[] = "SÚMULA ORIGINAL COMPLETA:\n" . trim((string)$match['texto_original']);
        $facts[] = implode("\n", $detail);
    }
    foreach ($table as &$team) { $team['sg']=$team['gp']-$team['gc']; $team['restantes']=count($remaining[$team['id']]); $team['max_pts']=$team['pts'] + ($team['restantes']*3); $team['forma']=implode('-', array_slice($form[$team['id']], -5)) ?: 'sem jogos'; }
    unset($team);
    $table=array_values($table);
    usort($table, static function(array $a,array $b): int { foreach(['pts','v','sg','gp'] as $key) if($a[$key]!==$b[$key]) return $b[$key]<=>$a[$key]; return strcmp($a['nome'],$b['nome']); });
    arsort($scorers); arsort($assists);
    return compact('matches','table','remaining','scorers','assists','totals','facts');
}

function league_table_text(array $table, array $remaining): string
{
    $lines=[];
    foreach ($table as $index=>$team) {
        $games = $remaining[$team['id']] ? implode('; ', $remaining[$team['id']]) : 'nenhum';
        $lines[] = sprintf('%dº %s: %d pts | J %d | V %d | E %d | D %d | GP %d | GC %d | SG %+d | forma (últimos 5) %s | jogos restantes %d | máximo possível %d pts | próximos: %s', $index+1,$team['nome'],$team['pts'],$team['j'],$team['v'],$team['e'],$team['d'],$team['gp'],$team['gc'],$team['sg'],$team['forma'],$team['restantes'],$team['max_pts'],$games);
    }
    return implode("\n", $lines);
}

function ranking_text(array $ranking, int $limit=10): string
{
    if (!$ranking) return 'nenhum dado identificado nas súmulas';
    $lines=[]; $position=0;
    foreach ($ranking as $name=>$value) { $lines[]=(++$position) . 'º ' . $name . ': ' . $value; if ($position >= $limit) break; }
    return implode('; ', $lines);
}

try {
    $pdo = db();
    $campeonatoId = (int)($_GET['campeonato_id'] ?? 0);
    $rodada = (int)($_GET['rodada'] ?? 0);
    $fase = trim((string)($_GET['fase'] ?? ''));
    $acao = trim((string)($_GET['acao'] ?? ''));
    if ($campeonatoId < 1) throw new RuntimeException('Selecione um campeonato.');

    $championshipStmt = $pdo->prepare("SELECT nome,tipo,status FROM campeonatos WHERE id=? AND ativo=1 LIMIT 1");
    $championshipStmt->execute([$campeonatoId]);
    $championship = $championshipStmt->fetch();
    $campeonato = (string)($championship['nome'] ?? '');
    if ($campeonato === '') throw new RuntimeException('Campeonato não encontrado.');
    if (($championship['tipo'] ?? '') === 'mata_mata') {
        knockout_prompt_response($pdo, $campeonatoId, $campeonato, $fase);
    }

    $roundStmt = $pdo->prepare("SELECT rodada,MAX(CASE WHEN gols_mandante IS NOT NULL AND gols_visitante IS NOT NULL THEN 1 ELSE 0 END) tem_resultado FROM partidas WHERE campeonato_id=? AND ativo=1 GROUP BY rodada ORDER BY rodada");
    $roundStmt->execute([$campeonatoId]);
    $rodadas = array_map(static fn(array $item): array => ['rodada' => (int)$item['rodada'], 'tem_resultado' => (bool)$item['tem_resultado']], $roundStmt->fetchAll());
    $rodadaAtual = 0;
    foreach ($rodadas as $item) if ($item['tem_resultado']) $rodadaAtual = max($rodadaAtual, $item['rodada']);
    if ($rodadaAtual < 1 && $rodadas) $rodadaAtual = $rodadas[0]['rodada'];
    $cicloAtual = $rodadaAtual > 0 ? intdiv($rodadaAtual - 1, 8) + 1: 1;
    $inicioCicloAtual = (($cicloAtual - 1) * 8) + 1;
    $fimCicloAtual = $inicioCicloAtual + 7;
    $contextoAtual = "Rodada atual: {$rodadaAtual}ª · Ciclo {$cicloAtual}: {$inicioCicloAtual}ª à {$fimCicloAtual}ª rodada";
    if (in_array($acao, ['chances_titulo','campeonato_completo'], true)) {
        $data = league_prompt_data($pdo, $campeonatoId);
        if (!$data['matches']) throw new RuntimeException('Nenhuma partida cadastrada neste campeonato.');
        $tableText = league_table_text($data['table'], $data['remaining']);
        $finished = $data['totals']['jogos']; $total = count($data['matches']); $remainingCount = $total - $finished;
        if ($acao === 'chances_titulo') {
            $g4 = array_slice($data['table'], 0, 4);
            $g4Names = implode(', ', array_column($g4, 'nome'));
            $prompt = "Você é um jornalista esportivo e analista estatístico responsável pela cobertura do campeonato {$campeonato}.\n\nEscreva uma notícia sobre as chances de título dos quatro primeiros colocados (G4): {$g4Names}. Use EXCLUSIVAMENTE os números fornecidos. Calcule e apresente uma PORCENTAGEM ESTIMADA DE SER CAMPEÃO para cada integrante do G4, com uma casa decimal, e faça as quatro porcentagens somarem exatamente 100,0%. A estimativa deve considerar pontos, vitórias, saldo e gols pró (nesta ordem de desempate), forma nos últimos cinco jogos, mando e dificuldade dos confrontos restantes, máximo de pontos e combinações necessárias. Explique claramente que são projeções editoriais baseadas no momento, não probabilidades oficiais. Não invente resultados, lesões, suspensões, falas ou fatos. Mostre as contas e os caminhos possíveis de cada candidato, inclusive quem depende de tropeços.\n\nPADRÃO EDITORIAL OBRIGATÓRIO:\n1. TÍTULO: manchete forte, com até 180 caracteres.\n2. RESUMO: um parágrafo de até 500 caracteres.\n3. DESCRIÇÃO: repita o título; abra com o cenário; crie um bloco para cada time do G4 com sua porcentagem; inclua **📊 Como calculamos**, **🧮 Contas do título**, **🗓️ Jogos restantes** e **🏆 Veredito**. Use negrito nos números decisivos e emojis apenas nos subtítulos.\n\nENTREGUE EXATAMENTE SEPARADO ASSIM:\nTÍTULO:\n[texto]\n\nRESUMO:\n[texto]\n\nDESCRIÇÃO:\n[matéria completa]\n\nDADOS OFICIAIS FORNECIDOS:\nCAMPEONATO: {$campeonato}\nPARTIDAS ENCERRADAS: {$finished} de {$total}\nPARTIDAS RESTANTES: {$remainingCount}\nCRITÉRIOS DE DESEMPATE DISPONÍVEIS: pontos, vitórias, saldo de gols e gols pró.\n\nCLASSIFICAÇÃO E CENÁRIOS NUMÉRICOS:\n{$tableText}\n\nTODOS OS JOGOS DO CAMPEONATO:\n" . implode("\n\n---\n\n", $data['facts']);
            prompt_json(['ok'=>true,'prompt'=>$prompt,'partidas'=>$total,'contexto'=>'Porcentagem de ser campeão · G4']);
        }
        $champion = $data['table'][0]['nome'] ?? 'não definido';
        $completed = $remainingCount === 0;
        $statusText = $completed ? "ENCERRADO — campeão: {$champion}" : "EM ANDAMENTO — ainda restam {$remainingCount} partidas; não declare campeão";
        $average = $finished ? number_format($data['totals']['gols']/$finished, 2, ',', '.') : '0,00';
        $prompt = "Você é um jornalista esportivo responsável pela cobertura do campeonato {$campeonato}.\n\nEscreva a grande matéria de encerramento usando EXCLUSIVAMENTE os dados fornecidos. {$statusText}. Se o campeonato estiver encerrado, dê parabéns ao campeão {$champion}, conte sua campanha completa rodada por rodada e destaque por que conquistou o título. Se ainda estiver em andamento, produza apenas uma prévia do encerramento e jamais trate o líder como campeão. Não invente fatos, falas, números ou acontecimentos.\n\nPADRÃO EDITORIAL OBRIGATÓRIO:\n1. TÍTULO: manchete forte, com até 180 caracteres, no formato de celebração ao campeão quando o torneio estiver encerrado.\n2. RESUMO: um parágrafo de até 500 caracteres.\n3. DESCRIÇÃO: repita o título e use blocos como **🏆 O campeão**, **🛣️ A campanha**, **⚽ Números gerais**, **🎯 Artilheiros**, **🅰️ Assistências**, **🔥 Jogos decisivos**, **📊 Classificação final** e **👏 Parabéns ao campeão**. Conte a trajetória inteira e encerre celebrando o título. Emojis apenas nos subtítulos.\n\nENTREGUE EXATAMENTE SEPARADO ASSIM:\nTÍTULO:\n[texto]\n\nRESUMO:\n[texto]\n\nDESCRIÇÃO:\n[matéria completa]\n\nDADOS OFICIAIS FORNECIDOS:\nCAMPEONATO: {$campeonato}\nSTATUS: {$statusText}\nPARTIDAS: {$finished} encerradas de {$total}\nGOLS: {$data['totals']['gols']}\nMÉDIA: {$average} gols por partida\nCARTÕES: {$data['totals']['amarelos']} amarelos; {$data['totals']['vermelhos']} vermelhos\nESTATÍSTICAS SOMADAS DAS SÚMULAS: {$data['totals']['finalizacoes']} finalizações; {$data['totals']['chutes_no_gol']} chutes no gol; {$data['totals']['defesas']} defesas; {$data['totals']['escanteios']} escanteios\nARTILHEIROS: " . ranking_text($data['scorers']) . "\nASSISTÊNCIAS: " . ranking_text($data['assists']) . "\n\nCLASSIFICAÇÃO COMPLETA:\n{$tableText}\n\nTODAS AS RODADAS E SÚMULAS:\n" . implode("\n\n---\n\n", $data['facts']);
        prompt_json(['ok'=>true,'prompt'=>$prompt,'partidas'=>$total,'contexto'=>'Todas as rodadas · matéria do campeão']);
    }
    if ($rodada < 1) prompt_json(['ok' => true, 'tipo' => 'pontos_corridos', 'rodadas' => $rodadas, 'rodada_atual' => $rodadaAtual, 'contexto' => $contextoAtual]);
    if (!in_array($rodada, array_column($rodadas, 'rodada'), true)) throw new RuntimeException('Rodada não encontrada neste campeonato.');

    $ciclo = intdiv($rodada - 1, 8) + 1;
    $inicioCiclo = (($ciclo - 1) * 8) + 1;
    $fimCiclo = $inicioCiclo + 7;
    $posicaoCiclo = (($rodada - 1) % 8) + 1;
    $faseCiclo = $posicaoCiclo <= 5 ? 'elenco travado': 'janela de transferências aberta';
    $contexto = "{$rodada}ª rodada · Ciclo {$ciclo} ({$inicioCiclo}ª à {$fimCiclo}ª) · {$faseCiclo}";

    $matchesStmt = $pdo->prepare("SELECT p.id,p.status,p.gols_mandante,p.gols_visitante,p.data_partida,m.time_nome mandante,m.nome tecnico_mandante,m.sigla mandante_sigla,v.time_nome visitante,v.nome tecnico_visitante,v.sigla visitante_sigla,s.estadio,s.clima,s.duracao,s.craque,s.craque_nota,s.dados_json,s.texto_original FROM partidas p JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id LEFT JOIN sumulas_dreamteam s ON s.origem='pontos' AND s.partida_id=p.id WHERE p.campeonato_id=? AND p.rodada=? AND p.ativo=1 ORDER BY p.id");
    $matchesStmt->execute([$campeonatoId, $rodada]);
    $partidas = $matchesStmt->fetchAll();
    if (!$partidas) throw new RuntimeException('Nenhuma partida cadastrada nesta rodada.');
    $finishedMatches = count(array_filter($partidas, static fn(array $match): bool => in_array($match['status'], ['finalizada','wo'], true)));
    $roundStatus = $finishedMatches === count($partidas) ? 'rodada encerrada': "rodada em andamento ({$finishedMatches} de " . count($partidas) . ' partidas encerradas)';

    $facts = [];
    $goalTotals = [];
    foreach ($partidas as $partida) {
        $placar = $partida['gols_mandante'] === null || $partida['gols_visitante'] === null
            ? 'placar ainda não informado'
           : (int)$partida['gols_mandante'] . ' x ' . (int)$partida['gols_visitante'];
        $lines = [sprintf('%s (técnico: %s) %s %s (técnico: %s): status: %s', $partida['mandante'], $partida['tecnico_mandante'], $placar, $partida['visitante'], $partida['tecnico_visitante'], $partida['status'])];
        if ($partida['data_partida']) $lines[] = 'Data: ' . date('d/m/Y H:i', strtotime((string)$partida['data_partida']));
        if ($partida['estadio']) $lines[] = 'Estádio: ' . $partida['estadio'];
        if ($partida['clima']) $lines[] = 'Clima: ' . $partida['clima'];
        if ($partida['duracao']) $lines[] = 'Duração: ' . (int)$partida['duracao'] . ' minutos';
        if ($partida['craque']) $lines[] = 'Craque: ' . $partida['craque'] . ($partida['craque_nota'] !== null ? ' (nota ' . $partida['craque_nota'] . ')': '');

        $parsed = $partida['dados_json'] ? json_decode((string)$partida['dados_json'], true): null;
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
                $minute = ($event['minute'] ?? '') !== '' ? (string)$event['minute'] . "'": 'minuto não informado';
                $detail = match ($type) {
                    'goal' => 'Gol de ' . ($event['player'] ?? 'não informado') . (!empty($event['assist']) ? ', assistência de ' . $event['assist']: '') . ', tipo ' . ($event['goal_type'] ?? 'normal') . (!empty($event['cancelled']) ? ' (ANULADO)': ''),
                    'yellow_card' => 'Cartão amarelo para ' . ($event['player'] ?? 'não informado') . (!empty($event['via_var']) ? ' após VAR': ''),
                    'red_card' => 'Cartão vermelho para ' . ($event['player'] ?? 'não informado'),
                    'var_goal_cancelled' => 'VAR anulou gol de ' . ($event['player'] ?? 'não informado'),
                    'var_penalty_cancelled' => 'VAR cancelou pênalti envolvendo ' . ($event['player'] ?? 'não informado'),
                    'injury' => 'Lesão de ' . ($event['player'] ?? 'não informado'),
                    'substitution' => 'Substituição: saiu ' . ($event['player_out'] ?? 'não informado') . ', entrou ' . ($event['player_in'] ?? 'não informado'),
                    default => ucfirst(str_replace('_', ' ', $type)) . ': ' . ($event['description'] ?? ''),
                };
                $lines[] = $minute . ': ' . $detail . ($team !== '' ? ' (' . $team . ')': '');
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
    $scorers = $goalTotals ? implode(', ', array_map(static fn($name, $goals) => $name . ' (' . $goals . ')', array_keys($goalTotals), $goalTotals)): 'nenhum artilheiro identificado nas súmulas';

    $campaignStmt = $pdo->prepare("SELECT p.mandante_id,p.visitante_id,p.gols_mandante,p.gols_visitante,m.time_nome mandante,v.time_nome visitante FROM partidas p JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id WHERE p.campeonato_id=? AND p.ativo=1 AND p.rodada<=? AND p.status IN ('finalizada','wo') ORDER BY p.rodada,p.id");
    $campaignStmt->execute([$campeonatoId, $rodada]);
    $campaignGames = $campaignStmt->fetchAll();
    $table = [];
    $sequences = [];
    $totalGoals = 0;
    foreach ($campaignGames as $game) {
        foreach ([['id'=>(int)$game['mandante_id'],'nome'=>$game['mandante']],['id'=>(int)$game['visitante_id'],'nome'=>$game['visitante']]] as $team) {
            $table[$team['id']] ??= ['nome'=>$team['nome'],'j'=>0,'v'=>0,'e'=>0,'d'=>0,'gp'=>0,'gc'=>0,'sg'=>0,'pts'=>0];
        }
        $home=(int)$game['mandante_id']; $away=(int)$game['visitante_id']; $hg=(int)$game['gols_mandante']; $ag=(int)$game['gols_visitante'];
        foreach ([[$home, $game['mandante'], $hg, $ag], [$away, $game['visitante'], $ag, $hg]] as [$teamId, $teamName, $goalsFor, $goalsAgainst]) {
            $sequences[$teamId] ??= ['nome'=>$teamName,'vitorias'=>0,'invicto'=>0];
            if ($goalsFor > $goalsAgainst) {
                $sequences[$teamId]['vitorias']++;
                $sequences[$teamId]['invicto']++;
            } elseif ($goalsFor === $goalsAgainst) {
                $sequences[$teamId]['vitorias']=0;
                $sequences[$teamId]['invicto']++;
            } else {
                $sequences[$teamId]['vitorias']=0;
                $sequences[$teamId]['invicto']=0;
            }
        }
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
    foreach($table as $index=>$team) $standingsLines[] = sprintf('%dº %s: %d pts | J %d | V %d | E %d | D %d | GP %d | GC %d | SG %+d', $index+1,$team['nome'],$team['pts'],$team['j'],$team['v'],$team['e'],$team['d'],$team['gp'],$team['gc'],$team['sg']);
    $sequenceLines=[];
    foreach ($sequences as $sequence) {
        if ($sequence['vitorias'] >= 2) $sequenceLines[] = $sequence['nome'] . ': ' . $sequence['vitorias'] . ' vitórias consecutivas';
        elseif ($sequence['invicto'] >= 2) $sequenceLines[] = $sequence['nome'] . ': ' . $sequence['invicto'] . ' jogos consecutivos sem perder';
    }
    $sequenceText = $sequenceLines ? implode("\n", $sequenceLines): 'Nenhuma sequência atual de pelo menos duas vitórias ou dois jogos invictos.';
    $average = count($campaignGames) ? number_format($totalGoals / count($campaignGames), 2, ',', '.'): '0,00';

    $prompt = "Você é um jornalista esportivo responsável pela cobertura do campeonato {$campeonato}.\n\n"
        . "Escreva uma notícia completa sobre a {$rodada}ª rodada usando EXCLUSIVAMENTE os dados fornecidos. Não invente fatos, falas, números ou acontecimentos. Quando algo não estiver disponível, omita. Explore o máximo de informação real: posse de bola, finalizações, chutes no alvo, passes, defesas, cartões, VAR, pênaltis, assistências, minutos dos gols, bolas paradas, craques e notas. Compare os números para explicar domínio, equilíbrio e atuações individuais. Faça uma leitura editorial automática de todos os dados: procure e destaque vitórias consecutivas, séries invictas, liderança mantida ou tomada, recuperação, queda de rendimento, melhor ataque, melhor defesa e outros recordes ou sequências comprováveis. Não espere que o usuário peça esses destaques. Informe sempre a quantidade exata da sequência e não use termos como invicto, consecutivo, recorde, bicampeão ou tricampeão sem comprovação nos dados.\n\n"
        . "PADRÃO EDITORIAL OBRIGATÓRIO:\n1. TÍTULO: manchete forte e informativa, com até 180 caracteres, citando o principal impacto da rodada.\n2. RESUMO: um único parágrafo de até 500 caracteres resumindo líderes, destaques e fatos marcantes.\n3. DESCRIÇÃO: comece repetindo o título e faça uma abertura geral. Depois crie blocos com subtítulos em negrito e emojis pertinentes, como **🥇 Liderança**, **🔥 Destaque**, **🎩 Atuação individual**, **🧤 Goleiros**, **🎯 Pênaltis, cartões e VAR**, **📊 Classificação** e **📋 Resultados da rodada**. Escolha somente blocos sustentados pelos dados. Analise as partidas importantes com placar, gols, minutos, assistências, estatísticas, craque e nota. Feche com a classificação relevante, a lista completa de resultados e uma projeção genérica para a próxima rodada, sem inventar confrontos. Use negrito em nomes e números importantes. Emojis apenas nos subtítulos.\n\n"
        . "ENTREGUE EXATAMENTE SEPARADO ASSIM:\nTÍTULO:\n[texto]\n\nRESUMO:\n[texto]\n\nDESCRIÇÃO:\n[matéria completa]\n\n"
        . "CONTEXTO DO CAMPEONATO:\nCAMPEONATO: {$campeonato}\nRODADA ANALISADA: {$rodada}ª\nSTATUS DA RODADA: {$roundStatus}. Nunca diga que a rodada terminou se ela estiver em andamento.\nCICLO: {$ciclo}\nINÍCIO DO CICLO: {$inicioCiclo}ª rodada\nFIM DO CICLO: {$fimCiclo}ª rodada\nFASE DO CICLO: {$faseCiclo}\nREGRA: cada ciclo possui cinco rodadas com elenco travado e três rodadas com alterações liberadas. Só mencione o ciclo se isso for editorialmente relevante.\nARTILHEIROS DA RODADA: {$scorers}\nTOTAL ACUMULADO ATÉ A RODADA: {$totalGoals} gols em " . count($campaignGames) . " jogos; média de {$average} gols por partida.\n\nSEQUÊNCIAS ATUAIS CONFIRMADAS ATÉ A RODADA:\n{$sequenceText}\n\nCLASSIFICAÇÃO APÓS A RODADA:\n" . implode("\n", $standingsLines) . "\n\nDADOS DAS PARTIDAS:\n\n"
        . implode("\n\n---\n\n", $facts);

    prompt_json(['ok' => true, 'prompt' => $prompt, 'partidas' => count($partidas), 'rodada' => $rodada, 'contexto' => $contexto]);
} catch (Throwable $error) {
    prompt_json(['ok' => false, 'message' => $error->getMessage()], 422);
}
