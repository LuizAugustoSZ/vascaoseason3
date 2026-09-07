<?php

declare(strict_types=1);

function dreamteam_clean_line(string $line): string
{
    $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
    return trim(preg_replace('/^(?::[\w-]+:|[^\p{L}\p{N}])+\s*/u', '', $line) ?? $line);
}

function dreamteam_goal_type(string $description): string
{
    $description = mb_strtolower($description);
    return match (true) {
        str_contains($description, 'pênalti'), str_contains($description, 'penalti') => 'penalti',
        str_contains($description, 'falta') => 'falta',
        str_contains($description, 'olímpico'), str_contains($description, 'olimpico') => 'olimpico',
        str_contains($description, 'contra') => 'contra',
        str_contains($description, 'cabeça'), str_contains($description, 'cabeca') => 'cabeca',
        default => 'normal',
    };
}

function dreamteam_player_key(string $name): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($name), 'UTF-8'));
    return preg_replace('/[^a-z0-9]+/', '', $ascii !== false ? $ascii : mb_strtolower(trim($name), 'UTF-8')) ?? '';
}

function dreamteam_compact_text(string $raw): string
{
    $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/u', '$1', $text) ?? $text;
    $text = str_replace(['**', '__'], '', $text);
    $text = str_replace('`', ' ', $text);
    $text = preg_replace('/(?::[\w-]+:|[🏟🌦⚖⭐🎙️])/u', '', $text) ?? $text;
    $text = preg_replace('/^\s*>\s?/m', '', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function dreamteam_parse_compact_summary(string $raw): ?array
{
    // The legacy format has an explicit match ID and its own event vocabulary.
    if (preg_match('/\bID:\s*DT-[A-Z0-9-]+/i', $raw)) return null;
    $text = dreamteam_compact_text($raw);
    if (!preg_match('/(?:RANQUEADA|PARTIDA)\s+FINALIZADA\s*-\s*(\d+)\'/ui', $text, $finished)) return null;
    if (substr_count($text, 'Man of the Match:') !== 1) throw new RuntimeException('Cole exatamente uma partida completa por vez.');
    if (!str_contains($text, 'Lances da Partida')) throw new RuntimeException('A seção “Lances da Partida” é obrigatória para importar a súmula.');

    $header = preg_split('/Man of the Match:/u', $text, 2)[0];
    // Full team names in the statistics are also present immediately around the score.
    preg_match_all('/(\d+)\s*x\s*(\d+)/u', $header, $scores, PREG_OFFSET_CAPTURE);
    if (count($scores[0]) !== 1) throw new RuntimeException('Não foi possível identificar o placar final.');
    $score = $scores[0][0];
    $awayName = trim(substr($header, $score[1] + strlen($score[0])));
    $left = trim(substr($header, 0, $score[1]));
    if (!preg_match('/dreamteam\.futbol\s*-\s*Partida entre\s+(.+?)\s+e\s+'.preg_quote($awayName, '/').'(?=\s|$)/ui', $text, $footer)) {
        throw new RuntimeException('Não foi possível identificar os nomes dos times no rodapé da súmula.');
    }
    $homeName = trim($footer[1]);
    if (!str_ends_with($left, $homeName)) throw new RuntimeException('O mandante do rodapé não corresponde ao placar.');
    $venueText = trim(substr($left, strpos($left, $finished[0]) + strlen($finished[0])));
    $venueText = trim(substr($venueText, 0, -strlen($homeName)));
    $venueText = preg_split('/\s+Arbitragem:/ui', $venueText, 2)[0];
    preg_match('/^(.+?)\s+((?:Ensolarado|Nublado|Garoa|Neblina|Chuva(?: forte)?|Tempo aberto)(?:\s*·\s*-?\d+\s*°C)?)$/ui', $venueText, $venue);
    preg_match('/Man of the Match:\s*(.+?)\s+Nota:\s*([\d,.]+)/ui', $text, $motm);
    $motmName = trim(preg_replace('/\s+\d+\s+(?:gols?|assistências?|defesas?).*$/ui', '', $motm[1] ?? '') ?? '');
    $teams = [];
    foreach ([$homeName, $awayName] as $i => $name) {
        $stop = $i === 0 ? preg_quote($awayName, '/').'\s+Finalizações:' : '(?:dreamteam\.futbol|Estádio e bilheteria|Classificação)';
        if (!preg_match('/'.preg_quote($name, '/').'\s+Finalizações:(.*?)(?=\s+'.$stop.')/ui', $text, $block)) throw new RuntimeException('Estatísticas não identificadas: '.$name.'.');
        $stats = [];
        foreach (['shots'=>'Finalizações','shots_on_target'=>'No gol','saves'=>'Defesas','corners'=>'Escanteios','possession'=>'Posse','fouls_suffered'=>'Faltas Sofridas','yellow_cards'=>'Amarelos','red_cards'=>'Vermelhos'] as $key=>$label) {
            $stats[$key] = preg_match('/'.preg_quote($label, '/').':\s*(\d+)/ui', 'Finalizações:'.$block[1], $m) ? (int)$m[1] : null;
        }
        if (preg_match('/xG:\s*([\d,.]+)/ui', $block[1], $m)) $stats['xg'] = (float)str_replace(',', '.', $m[1]);
        $scorers = [];
        $marker = preg_split('/Marcadores:\s*/ui', $block[1], 2)[1] ?? '';
        preg_match_all('/([^:]+?):\s*(\d+)\s+gols?\b/ui', $marker, $scorerRows, PREG_SET_ORDER);
        foreach ($scorerRows as $m) $scorers[] = ['player'=>trim($m[1]), 'goals'=>(int)$m[2]];
        $teams[] = ['code'=>'', 'stats'=>$stats, 'scorers'=>$scorers];
    }
    $events = [];
    $warnings = [];
    $eventText = preg_split('/Lances da Partida/ui', $text, 2)[1];
    $eventText = preg_split('/Notas dos Jogadores|Rota Silver\/Gold/ui', $eventText, 2)[0];
    preg_match_all('/(\d+(?:\+\d+)?)\'\s*(.*?)(?=(?<!\d)\d+(?:\+\d+)?\'|$)/u', $eventText, $rows, PREG_SET_ORDER);
    foreach ($rows as $row) {
        $body = trim($row[2]);
        if (preg_match('/^Substituição\s*-\s*Sai\s+(.+?),?\s+entra\s+(.+?)\s*\[([A-Z0-9]+)\]/ui', $body, $m)) {
            $events[] = ['type'=>'substitution','minute'=>$row[1],'player_out'=>rtrim(trim($m[1]), ','),'player_in'=>trim($m[2]),'team_code'=>$m[3]];
            continue;
        }
        if (!preg_match('/^(Gol(?:\s+anulado)?|Cartão amarelo|Cartão vermelho|Lesão|Pênalti cancelado)\s*-\s*(.+?)\s*\[([A-Z0-9]+)\](.*)$/ui', $body, $m)) {
            $warnings[] = 'Lance não reconhecido aos '.$row[1].' minutos: '.$body;
            continue;
        }
        $type = match (mb_strtolower($m[1])) {
            'gol'=>'goal', 'gol anulado'=>'var_goal_cancelled', 'cartão amarelo'=>'yellow_card',
            'cartão vermelho'=>'red_card', 'lesão'=>'injury', default=>'var_penalty_cancelled',
        };
        $event = ['type'=>$type,'minute'=>$row[1],'player'=>trim($m[2]),'team_code'=>$m[3],'description'=>trim($m[4], " \t-·")];
        if ($type === 'goal') {
            preg_match('/Assistência de\s+(.+?)\s*\[([A-Z0-9]+)\]/ui', $m[4], $assist);
            $event += ['goal_type'=>dreamteam_goal_type(preg_split('/Assistência de/ui', $m[4], 2)[0]),'assist'=>isset($assist[1])?trim($assist[1]):null,'cancelled'=>false];
        }
        if ($type === 'yellow_card') $event['via_var'] = str_contains(mb_strtolower($m[4]), 'var');
        if ($type === 'var_goal_cancelled') {
            for ($j=count($events)-1; $j>=0; $j--) {
                if ($events[$j]['type']==='goal' && empty($events[$j]['cancelled']) && $events[$j]['team_code']===$m[3] && dreamteam_player_key($events[$j]['player'])===dreamteam_player_key($m[2])) {
                    $events[$j]['cancelled']=true;
                    break;
                }
            }
        }
        $events[] = $event;
    }
    $goals = array_values(array_filter($events, static fn(array $e): bool => $e['type']==='goal' && empty($e['cancelled'])));
    // Compare the complete scorer totals, never the order in which teams scored.
    foreach ($teams as $i=>&$team) {
        $expected = [];
        foreach ($team['scorers'] as $scorer) $expected[dreamteam_player_key($scorer['player'])] = $scorer['goals'];
        ksort($expected);
        $candidates = [];
        foreach (array_unique(array_column($goals, 'team_code')) as $code) {
            $actual = [];
            foreach ($goals as $goal) if ($goal['team_code']===$code) {
                $key=dreamteam_player_key($goal['player']);
                $actual[$key]=($actual[$key]??0)+1;
            }
            ksort($actual);
            if ($expected === $actual) $candidates[]=$code;
        }
        $team['code'] = count($candidates)===1 ? $candidates[0] : 'SUMMARY_'.($i===0?'HOME':'AWAY');
    }
    unset($team);
    if (array_sum(array_column($teams[0]['scorers'], 'goals')) !== (int)$scores[1][0][0] || array_sum(array_column($teams[1]['scorers'], 'goals')) !== (int)$scores[2][0][0] || count($goals)!==(int)$scores[1][0][0]+(int)$scores[2][0][0]) $warnings[]='Os marcadores e os gols dos lances não correspondem ao placar final.';
    if ($teams[0]['stats']['possession']+$teams[1]['stats']['possession']!==100) $warnings[]='A soma da posse de bola não corresponde a 100%.';
    $result = ['home_name'=>$homeName,'home_goals'=>(int)$scores[1][0][0],'away_goals'=>(int)$scores[2][0][0],'away_name'=>$awayName,'duration'=>(int)$finished[1],'stadium'=>$venue[1]??$venueText,'weather'=>$venue[2]??'','man_of_match'=>$motmName,'man_of_match_team_code'=>null,'man_of_match_rating'=>isset($motm[2])?(float)str_replace(',','.',$motm[2]):null,'teams'=>$teams,'events'=>$events,'goals'=>$goals,'warnings'=>$warnings];
    $result['dreamteam_id']='DT-IMPORT-'.strtoupper(substr(hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE)),0,20));
    return $result;
}

/** Fill codes absent from the pasted report using the identified registered teams. */
function dreamteam_bind_team_codes(array $parsed, array $participants): array
{
    foreach ($parsed['teams'] as $i=>&$team) {
        if (str_starts_with($team['code'], 'SUMMARY_')) {
            $registered = strtoupper(trim((string)($participants[$i]['sigla']??'')));
            if ($registered !== '') $team['code']=$registered;
        }
    }
    unset($team);
    $codes=array_column($parsed['teams'], 'code');
    if (count(array_unique($codes))!==2) $parsed['warnings'][]='As siglas dos times são ambíguas.';
    foreach ($parsed['events'] as $event) {
        if (!in_array($event['team_code'], $codes, true)) $parsed['warnings'][]='Sigla dos lances não vinculada a um time: '.$event['team_code'].'.';
    }
    foreach ($parsed['teams'] as $i=>$team) {
        $count=count(array_filter($parsed['goals'], static fn(array $g):bool=>$g['team_code']===$team['code']));
        if ($count!==$parsed[$i===0?'home_goals':'away_goals']) $parsed['warnings'][]='Os gols dos lances não correspondem ao placar de cada time.';
    }
    $parsed['warnings']=array_values(array_unique($parsed['warnings']));
    return $parsed;
}

function dreamteam_parse_summary(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
    if ($raw === '') {
        throw new RuntimeException('Cole a súmula completa do DreamTeam.');
    }
    $compact = dreamteam_parse_compact_summary($raw);
    if ($compact !== null) return $compact;
    if (preg_match_all('/\bID:\s*DT-[A-Z0-9-]+/i', $raw) !== 1) {
        throw new RuntimeException('Cole exatamente uma partida completa por vez.');
    }
    $lines = array_values(array_map('trim', explode("\n", $raw)));
    $resultIndex = null;
    $result = null;
    foreach ($lines as $index => $line) {
        if (preg_match('/^(.+?)\s+(\d+)\s*x\s*(\d+)\s+(.+?)$/ui', $line, $match)) {
            $resultIndex = $index;
            $result = [
                'home_name' => trim($match[1]),
                'home_goals' => (int) $match[2],
                'away_goals' => (int) $match[3],
                'away_name' => trim($match[4]),
            ];
            break;
        }
    }
    if (!$result || $resultIndex === null) {
        throw new RuntimeException('Não foi possível identificar a linha do placar final.');
    }

    $finishedIndex = null;
    $duration = null;
    foreach ($lines as $index => $line) {
        if (preg_match('/PARTIDA FINALIZADA\s*-\s*(\d+)\'/ui', $line, $match)) {
            $finishedIndex = $index;
            $duration = (int) $match[1];
            break;
        }
    }
    $stadium = $finishedIndex !== null ? dreamteam_clean_line($lines[$finishedIndex + 1] ?? '') : '';
    $weather = $finishedIndex !== null ? dreamteam_clean_line($lines[$finishedIndex + 2] ?? '') : '';

    preg_match('/\bID:\s*(DT-[A-Z0-9-]+)/i', $raw, $idMatch);
    preg_match('/Man of the Match:\s*(?::[\w-]+:\s*)?(.+?)\s*\(([A-Z0-9]+)\)/ui', $raw, $motmMatch);
    preg_match('/⭐\s*Nota:\s*([0-9]+(?:[.,][0-9]+)?)/u', $raw, $ratingMatch);

    $teams = [];
    for ($index = $resultIndex + 1; $index < count($lines); $index++) {
        $line = $lines[$index];
        if (!preg_match('/^[A-Z0-9]{2,4}$/', $line) || !str_contains($lines[$index + 1] ?? '', 'Finaliza')) {
            continue;
        }
        $code = $line;
        $block = implode("\n", array_slice($lines, $index + 1, 12));
        $stats = [];
        foreach (
            [
                'shots' => 'Finalizações',
                'shots_on_target' => 'No gol',
                'saves' => 'Defesas',
                'corners' => 'Escanteios',
                'possession' => 'Posse',
                'fouls_suffered' => 'Faltas Sofridas',
                'yellow_cards' => 'Amarelos',
                'red_cards' => 'Vermelhos',
            ] as $key => $label
        ) {
            $stats[$key] = preg_match('/' . preg_quote($label, '/') . ':\s*(\d+)/ui', $block, $value) ? (int) $value[1] : null;
        }
        $scorers = [];
        for ($markerIndex = $index + 1; $markerIndex < min(count($lines), $index + 22); $markerIndex++) {
            if ($lines[$markerIndex] !== 'Marcadores:') continue;
            for ($scorerIndex = $markerIndex + 1; $scorerIndex < count($lines); $scorerIndex++) {
                $scorerLine = $lines[$scorerIndex];
                if ($scorerLine === '' || $scorerLine === 'Nenhum gol') break;
                if (preg_match('/(?::[\w-]+:\s*)?(.+?):\s*(\d+)\s+gol(?:s)?$/ui', $scorerLine, $scorerMatch)) {
                    $scorers[] = ['player' => trim($scorerMatch[1]), 'goals' => (int) $scorerMatch[2]];
                }
            }
            break;
        }
        $teams[] = ['code' => $code, 'stats' => $stats, 'scorers' => $scorers];
        if (count($teams) === 2) {
            break;
        }
    }

    $events = [];
    $eventsStart = null;
    foreach ($lines as $index => $line) {
        if (str_contains($line, 'Lances da Partida')) {
            $eventsStart = $index + 1;
            break;
        }
    }
    if ($eventsStart !== null) {
        for ($index = $eventsStart; $index < count($lines); $index++) {
            $line = $lines[$index];
            if ($line === '' || str_starts_with($line, 'DreamTeam') || str_contains($line, 'PARTIDA FINALIZADA')) {
                continue;
            }
            if (preg_match('/Assistência de\s+(?::[\w-]+:\s*)?(.+)$/ui', $line, $match)) {
                for ($eventIndex = count($events) - 1; $eventIndex >= 0; $eventIndex--) {
                    if ($events[$eventIndex]['type'] === 'goal' && empty($events[$eventIndex]['cancelled'])) {
                        $events[$eventIndex]['assist'] = trim($match[1]);
                        break;
                    }
                }
                continue;
            }
            if (preg_match('/GOL ANULADO\s*-\s*(.+?)\s*\(([A-Z0-9]+)\)/ui', $line, $match)) {
                for ($eventIndex = count($events) - 1; $eventIndex >= 0; $eventIndex--) {
                    if ($events[$eventIndex]['type'] === 'goal' && !$events[$eventIndex]['cancelled'] && $events[$eventIndex]['team_code'] === $match[2] && dreamteam_player_key($events[$eventIndex]['player']) === dreamteam_player_key($match[1])) {
                        $events[$eventIndex]['cancelled'] = true;
                        break;
                    }
                }
                preg_match('/(\d+)\'/', $line, $minute);
                $events[] = ['type' => 'var_goal_cancelled', 'minute' => $minute[1] ?? '', 'player' => trim($match[1]), 'team_code' => $match[2]];
                continue;
            }
            if (preg_match('/PÊNALTI CANCELADO\s*-\s*(.+?)\s*\(([A-Z0-9]+)\)/ui', $line, $match)) {
                preg_match('/(\d+)\'/', $line, $minute);
                $events[] = ['type' => 'var_penalty_cancelled', 'minute' => $minute[1] ?? '', 'player' => trim($match[1]), 'team_code' => $match[2]];
                continue;
            }
            if (preg_match('/(\d+)\'\s+(.+?)\s*\(([A-Z0-9]+)\)(.*)$/u', $line, $match)) {
                $type = null;
                if (str_contains($line, ':00boladt:')) $type = 'goal';
                elseif (str_contains($line, ':00zamarelodt:')) $type = 'yellow_card';
                elseif (str_contains($line, ':00zvermelhodt:')) $type = 'red_card';
                elseif (str_contains($line, ':injury:')) $type = 'injury';
                if ($type) {
                    $event = ['type' => $type, 'minute' => $match[1], 'player' => trim($match[2]), 'team_code' => $match[3], 'description' => trim(ltrim($match[4], ' -'))];
                    if ($type === 'goal') {
                        $event['goal_type'] = dreamteam_goal_type($event['description']);
                        $event['assist'] = null;
                        $event['cancelled'] = false;
                    }
                    if ($type === 'yellow_card') $event['via_var'] = str_contains(mb_strtolower($event['description']), 'var');
                    $events[] = $event;
                    continue;
                }
            }
            if (preg_match('/(\d+)\'\s+Sai\s+(.+?)\s+entra\s+(.+?)\s*\(([A-Z0-9]+)\)/ui', $line, $match)) {
                $events[] = ['type' => 'substitution', 'minute' => $match[1], 'player_out' => trim($match[2]), 'player_in' => trim($match[3]), 'team_code' => $match[4]];
            }
        }
    }

    if ($eventsStart === null) {
        throw new RuntimeException('A seção “Lances da Partida” é obrigatória para importar a súmula.');
    }
    $activeGoals = array_values(array_filter($events, fn(array $event): bool => $event['type'] === 'goal' && empty($event['cancelled'])));
    $codes = array_column($teams, 'code');
    $goalCounts = [];
    foreach ($activeGoals as $goal) $goalCounts[$goal['team_code']] = ($goalCounts[$goal['team_code']] ?? 0) + 1;
    $warnings = [];
    if (count($teams) !== 2) $warnings[] = 'As estatísticas dos dois times não foram identificadas por completo.';
    if (count($codes) === 2 && (($goalCounts[$codes[0]] ?? 0) !== $result['home_goals'] || ($goalCounts[$codes[1]] ?? 0) !== $result['away_goals'])) {
        $warnings[] = 'A quantidade de gols válidos nos lances não corresponde ao placar final.';
    }
    if (count($teams) === 2 && (($teams[0]['stats']['possession'] ?? 0) + ($teams[1]['stats']['possession'] ?? 0) !== 100)) {
        $warnings[] = 'A soma da posse de bola não corresponde a 100%.';
    }

    return $result + [
        'dreamteam_id' => $idMatch[1] ?? null,
        'duration' => $duration,
        'stadium' => $stadium,
        'weather' => $weather,
        'man_of_match' => $motmMatch[1] ?? null,
        'man_of_match_team_code' => $motmMatch[2] ?? null,
        'man_of_match_rating' => isset($ratingMatch[1]) ? (float) str_replace(',', '.', $ratingMatch[1]) : null,
        'teams' => $teams,
        'events' => $events,
        'goals' => $activeGoals,
        'warnings' => $warnings,
    ];
}
