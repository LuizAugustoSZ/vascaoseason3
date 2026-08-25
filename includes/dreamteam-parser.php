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

function dreamteam_parse_summary(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
    if ($raw === '') {
        throw new RuntimeException('Cole a súmula completa do DreamTeam.');
    }
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
