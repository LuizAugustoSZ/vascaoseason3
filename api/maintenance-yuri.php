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

function build_matchings(array $matches, array $participants): array
{
    $byId = [];
    foreach ($matches as $match) {
        $byId[(int)$match['id']] = $match;
    }

    $search = function (array $remaining, int $roundsLeft) use (&$search, $participants): ?array {
        if ($roundsLeft === 0) {
            return $remaining === [] ? [] : null;
        }

        $degrees = array_fill_keys($participants, 0);
        foreach ($remaining as $match) {
            $degrees[(int)$match['mandante_id']]++;
            $degrees[(int)$match['visitante_id']]++;
        }
        foreach ($degrees as $degree) {
            if ($degree !== $roundsLeft) return null;
        }

        $generate = function (array $available, array $edges, array $chosen = []) use (&$generate): array {
            if ($available === []) return [$chosen];
            $first = $available[0];
            $rest = array_slice($available, 1);
            $options = [];
            foreach ($edges as $id => $match) {
                $home = (int)$match['mandante_id'];
                $away = (int)$match['visitante_id'];
                if ($home !== $first && $away !== $first) continue;
                $opponent = $home === $first ? $away : $home;
                if (!in_array($opponent, $rest, true)) continue;
                $options[$opponent] ??= (int)$id;
            }
            $result = [];
            foreach ($options as $opponent => $id) {
                $nextAvailable = array_values(array_filter(
                    $rest,
                    static fn(int $participant): bool => $participant !== (int)$opponent,
                ));
                foreach ($generate($nextAvailable, $edges, [...$chosen, $id]) as $matching) {
                    $result[] = $matching;
                }
            }
            return $result;
        };

        $candidateMatchings = $generate($participants, $remaining);
        usort($candidateMatchings, static function (array $a, array $b) use ($remaining): int {
            $score = static function (array $ids) use ($remaining): int {
                $roundCounts = [];
                foreach ($ids as $id) {
                    $round = (int)$remaining[$id]['rodada'];
                    $roundCounts[$round] = ($roundCounts[$round] ?? 0) + 1;
                }
                return max($roundCounts ?: [0]);
            };
            return $score($b) <=> $score($a);
        });

        foreach ($candidateMatchings as $matching) {
            $next = $remaining;
            foreach ($matching as $id) unset($next[$id]);
            $tail = $search($next, $roundsLeft - 1);
            if ($tail !== null) return [$matching, ...$tail];
        }
        return null;
    };

    $result = $search($byId, 13);
    if ($result === null) {
        throw new RuntimeException('Não foi possível montar 13 rodadas completas.');
    }
    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $participantId = 4;
    $championshipId = 2;
    $participant = $pdo->query("SELECT id,nome,time_nome,ativo FROM participantes WHERE id=4")->fetch();
    if (!$participant || !str_contains(mb_strtolower((string)$participant['nome'], 'UTF-8'), 'yuri')) {
        throw new RuntimeException('O participante esperado não foi encontrado.');
    }
    $championship = $pdo->query("SELECT id,nome,status FROM campeonatos WHERE id=2")->fetch();
    if (!$championship || $championship['status'] !== 'ativo' || !str_contains(mb_strtolower((string)$championship['nome'], 'UTF-8'), 'brasileir')) {
        throw new RuntimeException('O Brasileirão ativo esperado não foi encontrado.');
    }

    $futureStmt = $pdo->prepare(
        "SELECT id,rodada,turno,mandante_id,visitante_id FROM partidas
         WHERE campeonato_id=? AND ativo=1 AND status='agendada'
           AND mandante_id<>? AND visitante_id<>?
         ORDER BY rodada,id"
    );
    $futureStmt->execute([$championshipId, $participantId, $participantId]);
    $futureMatches = $futureStmt->fetchAll();
    $participants = [];
    foreach ($futureMatches as $match) {
        $participants[(int)$match['mandante_id']] = true;
        $participants[(int)$match['visitante_id']] = true;
    }
    $participants = array_keys($participants);
    sort($participants);
    if (count($participants) !== 10 || count($futureMatches) !== 65) {
        throw new RuntimeException('A grade futura não possui os 10 times e 65 jogos esperados.');
    }
    $rounds = build_matchings($futureMatches, $participants);

    $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_backups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tag VARCHAR(100) NOT NULL UNIQUE,
        payload LONGTEXT NOT NULL,
        criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->beginTransaction();
    try {
        $backup = [
            'participant' => $participant,
            'accounts' => $pdo->query("SELECT * FROM contas WHERE participante_id=4")->fetchAll(),
            'matches' => $pdo->query("SELECT * FROM partidas WHERE campeonato_id=2 ORDER BY id")->fetchAll(),
        ];
        $backupStmt = $pdo->prepare("INSERT IGNORE INTO maintenance_backups(tag,payload) VALUES(?,?)");
        $backupStmt->execute([
            'remove_yuri_brasileirao_20260816',
            json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $pdo->prepare("UPDATE contas SET ativo=0 WHERE participante_id=?")->execute([$participantId]);
        $pdo->prepare("UPDATE participantes SET ativo=0 WHERE id=?")->execute([$participantId]);
        $pdo->prepare(
            "UPDATE partidas SET ativo=0
             WHERE campeonato_id=? AND (mandante_id=? OR visitante_id=?)"
        )->execute([$championshipId, $participantId, $participantId]);

        $updateRound = $pdo->prepare("UPDATE partidas SET rodada=? WHERE id=? AND campeonato_id=? AND ativo=1 AND status='agendada'");
        foreach ($rounds as $offset => $matching) {
            $roundNumber = 7 + $offset;
            foreach ($matching as $matchId) {
                $updateRound->execute([$roundNumber, $matchId, $championshipId]);
                if ($updateRound->rowCount() !== 1) {
                    throw new RuntimeException("Não foi possível mover a partida {$matchId}.");
                }
            }
        }

        $verification = $pdo->query(
            "SELECT r.rodada,r.jogos,COUNT(*) times
             FROM (
                 SELECT rodada,COUNT(*) jogos
                 FROM partidas
                 WHERE campeonato_id=2 AND ativo=1 AND status='agendada'
                 GROUP BY rodada
             ) r
             JOIN (
                 SELECT rodada,mandante_id participante_id FROM partidas
                 WHERE campeonato_id=2 AND ativo=1 AND status='agendada'
                 UNION ALL
                 SELECT rodada,visitante_id participante_id FROM partidas
                 WHERE campeonato_id=2 AND ativo=1 AND status='agendada'
             ) participantes_rodada ON participantes_rodada.rodada=r.rodada
             GROUP BY r.rodada,r.jogos
             HAVING COUNT(*)=COUNT(DISTINCT participantes_rodada.participante_id)
             ORDER BY r.rodada"
        )->fetchAll();
        if (count($verification) !== 13) {
            throw new RuntimeException('A verificação não encontrou as 13 rodadas futuras.');
        }
        foreach ($verification as $round) {
            if ((int)$round['jogos'] !== 5 || (int)$round['times'] !== 10) {
                throw new RuntimeException('Uma rodada futura não possui cinco jogos e dez times.');
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Yuri desativado e Brasileirão reorganizado.',
        'future_rounds' => $verification,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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
    'future_non_yuri' => array_values(array_filter(
        $matches,
        static fn(array $match): bool =>
            (int)$match['campeonato_id'] === 2 &&
            $match['status'] === 'agendada' &&
            (int)$match['ativo'] === 1 &&
            (int)$match['mandante_id'] !== 4 &&
            (int)$match['visitante_id'] !== 4,
    )),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
