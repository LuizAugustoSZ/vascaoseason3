<?php

declare(strict_types=1);
require __DIR__ . "/includes/bootstrap.php";
require __DIR__ . "/includes/public-layout.php";
require __DIR__ . "/includes/mercado.php";
require __DIR__ . "/includes/elenco-geral.php";
require __DIR__ . "/includes/proximo-confronto.php";
require __DIR__ . "/includes/lineup-image.php";
$id = (int) ($_GET["id"] ?? 0);
if ($id <= 0 && account_logged_in()) {
    $linkedParticipantId = (int)(account_participant_id() ?? 0);
    if ($linkedParticipantId > 0) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header('Location: time.php?id=' . $linkedParticipantId);
            exit;
        }
        $id = $linkedParticipantId;
    }
}
$time = null;
$databaseUnavailable = false;
$artilheiros = [];
$assistentes = [];
$campeonatosRanking = [];
$jogadas = [];
$proximas = [];
$titulos = [];
$responsavel = null;
$elencoPublico = [];
$clubePublico = null;
$lineupImagePath = null;
$jogadorFavorito = null;
$jogadorFavoritoGols = 0;
$jogadorFavoritoAssistencias = 0;
$transferenciasPublicas = [];
$canEditClubProfile = account_logged_in() && (
    account_is_master() || (int)(account_participant_id() ?? 0) === $id
);
$profileNotice = isset($_GET['perfil']) ? 'Perfil do clube atualizado.' : '';
$lineupImageError = mb_substr(trim((string)($_GET['erro_imagem'] ?? '')), 0, 300);
$requestTooLarge = $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
if ($requestTooLarge) {
    header('Location: time.php?id=' . $id . '&erro_imagem=' . rawurlencode('A imagem ultrapassa o limite de 12 MB.') . '#conteudo-clube');
    exit;
}
try {
    $pdo = db();
    competition_identities_seed($pdo);
    mercado_garantir_estrutura($pdo);
    elenco_geral_garantir_estrutura($pdo);
    lineup_image_ensure_schema($pdo);
    $profileAction = (string)($_POST['action'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($profileAction, ['salvar_imagem_escalacao', 'remover_imagem_escalacao'], true)) {
        try {
        verify_csrf();
        if (!$canEditClubProfile) throw new RuntimeException('Apenas o responsável associado pode alterar a imagem da escalação.');
        $championshipId = (int)($_POST['campeonato_id'] ?? 0);
        $clubCheck = $pdo->prepare("SELECT COUNT(*) FROM clubes_campeonato WHERE campeonato_id=? AND participante_id=?");
        $clubCheck->execute([$championshipId, $id]);
        if (!(int)$clubCheck->fetchColumn()) throw new RuntimeException('O clube não participa deste campeonato.');
        $oldImage = $pdo->prepare("SELECT caminho FROM imagens_escalacao WHERE campeonato_id=? AND participante_id=? LIMIT 1");
        $oldImage->execute([$championshipId, $id]);
        $oldPath = $oldImage->fetchColumn() ?: null;
        if ($profileAction === 'remover_imagem_escalacao') {
            $pdo->prepare("DELETE FROM imagens_escalacao WHERE campeonato_id=? AND participante_id=?")->execute([$championshipId, $id]);
            lineup_image_delete_file($oldPath);
        } else {
            $newPath = lineup_image_store($_FILES['imagem_escalacao'] ?? [], $championshipId, $id);
            try {
                $pdo->prepare("INSERT INTO imagens_escalacao(campeonato_id,participante_id,caminho) VALUES(?,?,?) ON DUPLICATE KEY UPDATE caminho=VALUES(caminho),atualizado_em=CURRENT_TIMESTAMP")->execute([$championshipId, $id, $newPath]);
            } catch (Throwable $error) { lineup_image_delete_file($newPath); throw $error; }
            lineup_image_delete_file($oldPath);
        }
        header('Location: time.php?id=' . $id . '&imagem_escalacao=salva'); exit;
        } catch (RuntimeException $error) {
            header('Location: time.php?id=' . $id . '&erro_imagem=' . rawurlencode($error->getMessage()) . '#conteudo-clube'); exit;
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($profileAction, ['atualizar_perfil_clube', 'atualizar_sobre_clube', 'atualizar_cofre_clube', 'atualizar_heroi_clube'], true)) {
        verify_csrf();
        if (!$canEditClubProfile) {
            throw new RuntimeException('Apenas o responsável associado pode editar este clube.');
        }
        $campeonatoPerfilId = (int)($_POST['campeonato_id'] ?? 0);
        $clubCheck = $pdo->prepare("SELECT COUNT(*) FROM clubes_campeonato WHERE campeonato_id=? AND participante_id=?");
        $clubCheck->execute([$campeonatoPerfilId, $id]);
        if (!(int)$clubCheck->fetchColumn()) {
            throw new RuntimeException('O clube não participa deste campeonato.');
        }
        mercado_clube($pdo, $campeonatoPerfilId, $id, true);
        if (in_array($profileAction, ['atualizar_perfil_clube', 'atualizar_sobre_clube'], true)) {
            $descricao = mb_substr(trim((string)($_POST['descricao'] ?? '')), 0, 1200);
            $pdo->prepare("UPDATE participantes SET descricao=? WHERE id=? AND ativo=1")->execute([$descricao ?: null, $id]);
        }
        if (in_array($profileAction, ['atualizar_perfil_clube', 'atualizar_cofre_clube'], true)) {
            $saldo = mercado_parse_valor((string)($_POST['saldo'] ?? '0'));
            if ($saldo < 0) throw new RuntimeException('O saldo do cofre não pode ser negativo.');
            $pdo->prepare("UPDATE clubes_campeonato SET saldo=?,cofre_configurado=1 WHERE participante_id=?")->execute([$saldo, $id]);
            $pdo->prepare("UPDATE clubes_gerais SET saldo=?,cofre_configurado=1 WHERE participante_id=?")->execute([$saldo, $id]);
        }
        if (in_array($profileAction, ['atualizar_perfil_clube', 'atualizar_heroi_clube'], true)) {
            $favoritoId = (int)($_POST['jogador_favorito_id'] ?? 0);
            if ($favoritoId > 0) {
                $favoriteStmt = $pdo->prepare("SELECT COUNT(*) FROM jogadores_elenco WHERE id=? AND campeonato_id=? AND participante_id=? AND ativo=1");
                $favoriteStmt->execute([$favoritoId, $campeonatoPerfilId, $id]);
                if (!(int)$favoriteStmt->fetchColumn()) throw new RuntimeException('Escolha um jogador ativo do seu próprio elenco.');
            }
            $pdo->prepare("UPDATE clubes_campeonato SET jogador_favorito_id=? WHERE campeonato_id=? AND participante_id=?")->execute([$favoritoId ?: null, $campeonatoPerfilId, $id]);
        }
        header('Location: time.php?id=' . $id . '&perfil=salvo');
        exit;
    }
    $stmt = $pdo->prepare(
        "SELECT id,nome,time_nome,sigla,escudo_url,descricao FROM participantes WHERE id=? AND ativo=1 LIMIT 1",
    );
    $stmt->execute([$id]);
    $time = $stmt->fetch() ?: null;
    if ($time) {
        $stmt = $pdo->prepare(
            "SELECT nome FROM contas WHERE participante_id=? AND ativo=1 LIMIT 1",
        );
        $stmt->execute([$id]);
        $responsavel = $stmt->fetchColumn() ?: null;
        $stmt = $pdo->prepare(
            "SELECT a.jogador,a.gols,a.campeonato_id,c.nome campeonato FROM artilharia a JOIN campeonatos c ON c.id=a.campeonato_id WHERE a.participante_id=? AND a.gols>0 ORDER BY a.gols DESC,a.jogador",
        );
        $stmt->execute([$id]);
        $artilheiros = $stmt->fetchAll();
        foreach ($artilheiros as $row) $campeonatosRanking[(int)$row['campeonato_id']] = (string)$row['campeonato'];
        try {
            $summaryStmt = $pdo->prepare("SELECT s.dados_json,c.id campeonato_id,c.nome campeonato FROM sumulas_dreamteam s JOIN partidas p ON s.origem='pontos' AND p.id=s.partida_id JOIN campeonatos c ON c.id=p.campeonato_id WHERE p.mandante_id=? OR p.visitante_id=? UNION ALL SELECT s.dados_json,c.id,c.nome FROM sumulas_dreamteam s JOIN jogos_mata_mata j ON s.origem='mata' AND j.id=s.jogo_mata_mata_id JOIN campeonatos c ON c.id=j.campeonato_id WHERE j.time_a_id=? OR j.time_b_id=?");
            $summaryStmt->execute([$id, $id, $id, $id]);
            $assistMap = [];
            foreach ($summaryStmt->fetchAll() as $summaryRow) {
                $summary = json_decode((string)$summaryRow['dados_json'], true);
                if (!is_array($summary)) continue;
                $championshipId = (int)$summaryRow['campeonato_id'];
                $campeonatosRanking[$championshipId] = (string)$summaryRow['campeonato'];
                $teamCode = null;
                $normalize = static function (string $value): string {
                    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($value), 'UTF-8'));
                    return preg_replace('/[^a-z0-9]+/', '', $ascii !== false ? $ascii : $value) ?? '';
                };
                if ($normalize((string)($summary['home_name'] ?? '')) === $normalize((string)$time['time_nome'])) $teamCode = $summary['teams'][0]['code'] ?? null;
                if ($normalize((string)($summary['away_name'] ?? '')) === $normalize((string)$time['time_nome'])) $teamCode = $summary['teams'][1]['code'] ?? null;
                if (!$teamCode) continue;
                foreach (($summary['events'] ?? []) as $event) {
                    if (($event['type'] ?? '') !== 'goal' || !empty($event['cancelled']) || ($event['team_code'] ?? '') !== $teamCode) continue;
                    $player = trim((string)($event['assist'] ?? ''));
                    if ($player === '') continue;
                    $key = $championshipId . '|' . mb_strtolower($player, 'UTF-8');
                    if (!isset($assistMap[$key])) $assistMap[$key] = ['jogador' => $player, 'assistencias' => 0, 'campeonato_id' => $championshipId, 'campeonato' => $summaryRow['campeonato']];
                    $assistMap[$key]['assistencias']++;
                }
            }
            $assistentes = array_values($assistMap);
            usort($assistentes, static fn($a, $b) => $b['assistencias'] <=> $a['assistencias'] ?: strcasecmp($a['jogador'], $b['jogador']));
        } catch (Throwable $ignored) {
            // A página continua disponível antes da instalação do módulo de súmulas.
        }
        $stmt = $pdo->prepare(
            "SELECT * FROM (SELECT p.id,p.campeonato_id,c.nome campeonato,COALESCE(p.data_partida,s.criado_em) data_jogo,p.rodada etapa,p.status,m.id mandante_id,m.time_nome mandante,m.sigla mandante_sigla,m.escudo_url mandante_escudo,v.id visitante_id,v.time_nome visitante,v.sigla visitante_sigla,v.escudo_url visitante_escudo,p.gols_mandante gols_a,p.gols_visitante gols_b,NULL penaltis_a,NULL penaltis_b,'pontos' origem FROM partidas p JOIN campeonatos c ON c.id=p.campeonato_id JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id LEFT JOIN sumulas_dreamteam s ON s.origem='pontos' AND s.partida_id=p.id WHERE p.ativo=1 AND(p.mandante_id=? OR p.visitante_id=?) UNION ALL SELECT j.id,j.campeonato_id,c.nome,s.criado_em,j.fase,j.status,a.id,a.time_nome,a.sigla,a.escudo_url,b.id,b.time_nome,b.sigla,b.escudo_url,j.gols_a,j.gols_b,j.penaltis_a,j.penaltis_b,'mata' FROM jogos_mata_mata j JOIN campeonatos c ON c.id=j.campeonato_id JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id LEFT JOIN sumulas_dreamteam s ON s.origem='mata' AND s.jogo_mata_mata_id=j.id WHERE j.ativo=1 AND(j.time_a_id=? OR j.time_b_id=?)) jogos",
        );
        $stmt->execute([$id, $id, $id, $id]);
        foreach ($stmt->fetchAll() as $jogo) {
            if (
                in_array(
                    $jogo["status"],
                    ["finalizada", "finalizado", "wo"],
                    true,
                )
            ) {
                $jogadas[] = $jogo;
            } else {
                $proximas[] = $jogo;
            }
        }
        usort($jogadas, function ($a, $b) {
            $da = strtotime((string) ($a["data_jogo"] ?? "")) ?: null;
            $db = strtotime((string) ($b["data_jogo"] ?? "")) ?: null;
            if ($da !== null || $db !== null) {
                if ($da === null) return 1;
                if ($db === null) return -1;
                if ($da !== $db) return $db <=> $da;
            }

            // Quando não existe data, preserva a cronologia de cadastro:
            // o maior ID representa o registro mais recente dentro da origem.
            if ($a["origem"] !== $b["origem"]) return strcmp((string)$a["origem"], (string)$b["origem"]);
            return (int) $b["id"] <=> (int) $a["id"];
        });
        $proximas = ordenar_proximos_confrontos($proximas, $jogadas);
        $stmt = $pdo->prepare(
            "SELECT id,titulo,temporada,CASE WHEN imagem_base64 IS NOT NULL AND imagem_base64<>'' THEN 1 ELSE 0 END tem_imagem FROM titulos WHERE participante_id=? ORDER BY conquistado_em DESC,id DESC",
        );
        $stmt->execute([$id]);
        $titulos = $stmt->fetchAll();
        try {
            $identityCompetitions = $pdo->query('SELECT c.id,c.nome FROM campeonatos c WHERE c.ativo=1 AND c.identidade_id IS NOT NULL ORDER BY c.id DESC')->fetchAll();
            foreach ($titulos as &$titleItem) {
                $titleKey = competition_identity_match((string)$titleItem['titulo']);
                $titleItem['trofeu_url'] = $titleKey ? 'api/competicao-imagem.php?chave=' . rawurlencode($titleKey) . '&tipo=trofeu' : (!empty($titleItem['tem_imagem']) ? 'api/titulo-imagem.php?titulo_id=' . (int)$titleItem['id'] : null);
                foreach ($identityCompetitions as $identityCompetition) {
                    if (!$titleItem['trofeu_url'] && $titleKey && competition_identity_match((string)$identityCompetition['nome']) === $titleKey) {
                        $titleItem['trofeu_url'] = competition_image_url((int)$identityCompetition['id'], 'trofeu');
                        break;
                    }
                }
            }
            unset($titleItem);
        } catch (Throwable $ignored) {
        }
        try {
            $stmt = $pdo->prepare("SELECT cc.saldo,cc.cofre_configurado,cc.formacao,cc.campeonato_id,cc.mural,cc.jogador_favorito_id,c.nome campeonato
                FROM clubes_campeonato cc
                JOIN campeonatos c ON c.id=cc.campeonato_id
                LEFT JOIN competicao_identidades ci ON ci.id=c.identidade_id
                WHERE cc.participante_id=? AND c.ativo=1 AND c.tipo='pontos_corridos'
                  AND (ci.chave='brasileirao' OR c.nome LIKE '%Brasileir%')
                ORDER BY c.status='ativo' DESC,c.id DESC LIMIT 1");
            $stmt->execute([$id]);
            $clubePublico = $stmt->fetch() ?: null;
            if ($clubePublico) {
                $lineupImageStmt = $pdo->prepare("SELECT caminho FROM imagens_escalacao WHERE campeonato_id=? AND participante_id=? LIMIT 1");
                $lineupImageStmt->execute([(int)$clubePublico['campeonato_id'], $id]);
                $lineupImagePath = $lineupImageStmt->fetchColumn() ?: null;
                $stmt = $pdo->prepare("SELECT id,nome,overall,posicao,grupo,ordem,campo_x,campo_y FROM jogadores_elenco WHERE campeonato_id=? AND participante_id=? AND ativo=1 ORDER BY grupo='titular' DESC,ordem,nome");
                $stmt->execute([(int)$clubePublico['campeonato_id'], $id]);
                $elencoPublico = $stmt->fetchAll();
                $stmt = $pdo->prepare("SELECT tipo,origem,origem_detalhe,valor_origem,moeda_origem,jogador_nome,jogador_overall,jogador_posicao,valor,criado_em
                    FROM movimentacoes_elenco_geral WHERE participante_id=? AND tipo IN ('compra','venda')
                    UNION ALL
                    SELECT tipo,origem,origem_detalhe,valor_origem,moeda_origem,jogador_nome,jogador_overall,jogador_posicao,valor,criado_em
                    FROM movimentacoes_elenco WHERE participante_id=? AND tipo IN ('compra','venda')
                    ORDER BY criado_em DESC");
                $stmt->execute([$id, $id]);
                $transferenciasPublicas = $stmt->fetchAll();
                foreach ($elencoPublico as $jogadorElenco) {
                    if ((int)$jogadorElenco['id'] === (int)$clubePublico['jogador_favorito_id']) {
                        $jogadorFavorito = $jogadorElenco;
                        break;
                    }
                }
                if ($jogadorFavorito) {
                    foreach ($artilheiros as $row) if (mb_strtolower((string)$row['jogador']) === mb_strtolower((string)$jogadorFavorito['nome'])) $jogadorFavoritoGols += (int)$row['gols'];
                    foreach ($assistentes as $row) if (mb_strtolower((string)$row['jogador']) === mb_strtolower((string)$jogadorFavorito['nome'])) $jogadorFavoritoAssistencias += (int)$row['assistencias'];
                }
            }
        } catch (Throwable $ignored) {
            // Mantém compatibilidade enquanto a migration v8.9 ainda não foi aplicada.
        }
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $databaseUnavailable = true;
}
if (!$time) {
    http_response_code($databaseUnavailable ? 503 : 404);
}
$stats = [
    "Jogos" => 0,
    "Vitórias" => 0,
    "Empates" => 0,
    "Derrotas" => 0,
    "Gols pró" => 0,
    "Gols contra" => 0,
    "Saldo" => 0,
];
$opponents = [];
foreach ($jogadas as $j) {
    $home = (int) $j["mandante_id"] === $id;
    $gf = (int) ($home ? $j["gols_a"] : $j["gols_b"]);
    $ga = (int) ($home ? $j["gols_b"] : $j["gols_a"]);
    $stats["Jogos"]++;
    $stats["Gols pró"] += $gf;
    $stats["Gols contra"] += $ga;
    if ($gf > $ga) {
        $stats["Vitórias"]++;
    } elseif ($gf === $ga) {
        $stats["Empates"]++;
    } else {
        $stats["Derrotas"]++;
    }
    $oid = (int) ($home ? $j["visitante_id"] : $j["mandante_id"]);
    if (!isset($opponents[$oid])) {
        $opponents[$oid] = [
            "id" => $oid,
            "nome" => $home ? $j["visitante"] : $j["mandante"],
            "sigla" => $home ? $j["visitante_sigla"] : $j["mandante_sigla"],
            "escudo" => $home ? $j["visitante_escudo"] : $j["mandante_escudo"],
            "jogos" => 0,
            "v" => 0,
            "e" => 0,
            "d" => 0,
        ];
    }
    $opponents[$oid]["jogos"]++;
    if ($gf > $ga) {
        $opponents[$oid]["v"]++;
    } elseif ($gf === $ga) {
        $opponents[$oid]["e"]++;
    } else {
        $opponents[$oid]["d"]++;
    }
}
$stats["Saldo"] = $stats["Gols pró"] - $stats["Gols contra"];
$rival = null;
if ($proximas) {
    $nextMatch = $proximas[0];
    $teamIsHome = (int) $nextMatch["mandante_id"] === $id;
    $opponentId = (int) ($teamIsHome
        ? $nextMatch["visitante_id"]
        : $nextMatch["mandante_id"]);

    $rival = $opponents[$opponentId] ?? [
        "id" => $opponentId,
        "nome" => $teamIsHome
            ? $nextMatch["visitante"]
            : $nextMatch["mandante"],
        "sigla" => $teamIsHome
            ? $nextMatch["visitante_sigla"]
            : $nextMatch["mandante_sigla"],
        "escudo" => $teamIsHome
            ? $nextMatch["visitante_escudo"]
            : $nextMatch["mandante_escudo"],
        "jogos" => 0,
        "v" => 0,
        "e" => 0,
        "d" => 0,
    ];
}
function shield(array $team, string $prefix = ""): string
{
    $url = $team[$prefix . "escudo"] ?? ($team["escudo_url"] ?? "");
    $sigla = $team[$prefix . "sigla"] ?? ($team["sigla"] ?? "?");
    return $url
        ? '<span class="match-shield"><img src="' . e($url) . '" alt=""></span>'
        : '<span class="match-shield match-shield--fallback">' .
        e($sigla) .
        "</span>";
}
function match_team(array $j, string $side, bool $showName = true): string
{
    global $id;
    $prefix = $side === "home" ? "mandante_" : "visitante_";
    $name = $j[$prefix === "mandante_" ? "mandante" : "visitante"];
    $teamId = (int) $j[$prefix . "id"];
    $classes = 'match-team' .
        ($showName ? '' : ' match-team--shield-only') .
        ($teamId === $id ? ' match-team--current' : '');
    $content = !$showName
        ? shield($j, $prefix)
        : ($side === "away"
            ? "<span>" . e($name) . "</span>" . shield($j, $prefix)
            : shield($j, $prefix) . "<span>" . e($name) . "</span>");
    if ($teamId === $id) {
        return '<span class="' . $classes . '" data-current-team aria-current="page">' . $content . '</span>';
    }
    return '<a class="' . $classes . '" href="time.php?id=' . $teamId . '" aria-label="' . e($name) . '" title="' . e($name) . '">' . $content . '</a>';
}
function match_score(array $j): string
{
    $home = $j["gols_a"] ?? "-";
    $away = $j["gols_b"] ?? "-";
    $homePenalties = $j["penaltis_a"];
    $awayPenalties = $j["penaltis_b"];
    return e((string) $home) .
        ($homePenalties !== null ? "(" . (int) $homePenalties . ")" : "") .
        " × " .
        e((string) $away) .
        ($awayPenalties !== null ? "(" . (int) $awayPenalties . ")" : "");
}
?>
<!doctype html>
<html lang="pt-BR" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e(
                $time ? $time["time_nome"] . " | Vascão S3" : "Time não encontrado",
            ) ?></title>
    <link rel="icon" href="favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/branding.css?v=5">
    <link rel="stylesheet" href="assets/css/team-profile.css?v=<?= filemtime(
                                                                    __DIR__ . "/assets/css/team-profile.css",
                                                                ) ?>">
    <link rel="stylesheet" href="assets/css/socials.css?v=<?= filemtime(__DIR__ . "/assets/css/socials.css") ?>">
</head>

<body>
    <?php public_navbar('time'); ?>
    <?php if (
        !$time
    ): ?><main class="wide-container error-page">
            <h1><?= $databaseUnavailable
                    ? "Dados indisponíveis"
                    : "Time não encontrado" ?></h1><a class="btn btn-danger" href="index.php#participantes">Ver participantes</a>
        </main><?php else: ?>
        <header class="club-hero">
            <div class="wide-container club-hero-grid">
                <div class="club-main-shield"><?php if (
                                                    $time["escudo_url"]
                                                ): ?><img src="<?= e($time["escudo_url"]) ?>" alt="Escudo de <?= e(
                                                                                                                    $time["time_nome"],
                                                                                                                ) ?>"><?php else: ?><span><?= e(
                                                                                                $time["sigla"],
                                                                                            ) ?></span><?php endif; ?></div>
                <div class="club-copy"><a class="club-back-link" href="index.php#participantes" aria-label="Voltar para todos os times"><span aria-hidden="true">←</span> Todos os times</a>
                    <h1><?= e(
                            $time["time_nome"],
                        ) ?></h1>
                    <p><b><?= e($time["sigla"]) ?></b><i></i>Técnico: <strong><?= e(
                                                                                    $time["nome"],
                                                                                ) ?></strong></p><small>Participante da Season 3</small>
                </div><?php if (!$responsavel): ?><aside class="claim-card"><small>Essa página tem dono?</small>
                        <h2>Página não vinculada</h2>
                        <p>A associação desta página é confirmada pela administração.</p><?php if (
                                                                                                !account_logged_in()
                                                                                            ): ?><a class="btn btn-outline-danger" href="cadastro.php">Criar conta</a><?php endif; ?>
                    </aside><?php endif; ?><?php if (
                                                $time["escudo_url"]
                                            ): ?><img class="club-watermark" src="<?= e(
                                                                                        $time["escudo_url"],
                                                                                    ) ?>" alt="" aria-hidden="true"><?php endif; ?>
            </div>
        </header>
        <main class="wide-container club-page">
            <?php if ($profileNotice): ?><div class="alert alert-success club-profile-notice"><?= e($profileNotice) ?></div><?php endif; ?>
            <?php if ($lineupImageError): ?><div class="alert alert-danger club-profile-notice" role="alert"><?= e($lineupImageError) ?></div><?php endif; ?>
            <section class="club-stats"><?php foreach (
                                            $stats
                                            as $label => $value
                                        ): ?><div><small><?= e($label) ?></small><strong><?= ($label === "Saldo" &&
                                                                                                $value > 0
                                                                                                ? "+"
                                                                                                : "") .
                                                                                                $value ?></strong></div><?php endforeach; ?></section><small class="auto-label">Atualizado automaticamente pelas competições</small>
            <section class="overview-grid">
                <article class="overview-card recent-card" data-card-pages="3">
                    <h3>Últimos jogos</h3>
                    <div class="card-page-items"><?php foreach (
                                                        $jogadas
                                                        as $j
                                                    ): ?><div class="compact-match match-open" tabindex="0" role="button" data-match-type="<?= $j["origem"] === "mata" ? "mata" : "pontos" ?>" data-match-id="<?= (int) $j["id"] ?>"><?= match_team($j, "home") ?><b><?= match_score(
                                                                                                                                                                                                                                                                            $j,
                                                                                                                                                                                                                                                                        ) ?></b><?= match_team(
                                                                                                                                                                                                                                $j,
                                                                                                                                                                                                                                "away",
                                                                                                                                                                                                                            ) ?></div><?php endforeach; ?></div><?php if (
                                                                                                !$jogadas
                                                                                            ): ?><p class="empty-copy">Nenhum resultado.</p><?php endif; ?><nav class="card-pages"></nav>
                </article>
                <article class="overview-card next-card" data-card-pages="1">
                    <h3>Próximo confronto</h3>
                    <div class="card-page-items"><?php foreach (
                                                        $proximas
                                                        as $j
                                                    ): ?><div class="next-item match-open" tabindex="0" role="button" data-match-type="<?= $j["origem"] === "mata" ? "mata" : "pontos" ?>" data-match-id="<?= (int) $j["id"] ?>">
                                <div class="versus"><?= match_team(
                                                            $j,
                                                            "home",
                                                            false,
                                                        ) ?><b>VS</b><?= match_team($j, "away", false) ?></div>
                                <p><?= e(
                                                            ($j["origem"] === "pontos" ? "Rodada " . $j["etapa"] : $j["etapa"]) . " - " . $j["campeonato"],
                                                        ) ?><br><?= $j["data_jogo"]
                                                                    ? e(date("d/m • H:i", strtotime($j["data_jogo"])))
                                                                    : "Data a definir" ?></p>
                            </div><?php endforeach; ?></div><?php if (
                                                                !$proximas
                                                            ): ?><p class="empty-copy">Nenhum confronto agendado.</p><?php endif; ?><nav class="card-pages"></nav>
                </article>
                <article class="overview-card rivalry-card<?= $rival ? ' rivalry-open' : '' ?>" <?= $rival ? 'tabindex="0" role="button" data-bs-toggle="modal" data-bs-target="#rivalry-history-modal"' : '' ?>>
                    <h3>Confronto direto</h3><?php if (
                                                    $rival
                                                ): ?><div class="versus"><span class="match-team match-team--shield-only match-team--current" aria-current="page"><?= shield($time) ?></span><b>VS</b><a class="match-team match-team--shield-only" href="time.php?id=<?= (int)$rival['id'] ?>" aria-label="<?= e($rival['nome']) ?>" title="<?= e($rival['nome']) ?>"><?= shield($rival) ?></a></div><a class="rival-team-link" href="time.php?id=<?= (int)$rival['id'] ?>"><?= e($rival["nome"]) ?></a>
                        <p><?= $rival["jogos"] ?> jogos • <?= $rival["v"] ?> vitórias • <?= $rival["e"] ?> empates • <?= $rival["d"] ?> derrotas</p><?php else: ?><p class="empty-copy">Sem histórico disponível.</p><?php endif; ?>
                </article>
                <article class="overview-card scorers-card" data-player-ranking data-team-id="<?= $id ?>" data-scorers="<?= e(json_encode($artilheiros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" data-assists="<?= e(json_encode($assistentes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                    <div class="ranking-tabs" role="tablist"><button class="active" type="button" data-ranking="goals">Artilheiros</button><button type="button" data-ranking="assists">Assistências</button></div>
                    <select class="ranking-championship" aria-label="Filtrar ranking por campeonato"><option value="all">Todos os campeonatos</option><?php foreach ($campeonatosRanking as $championshipId => $championshipName): ?><option value="<?= (int)$championshipId ?>" <?= (int)($clubePublico['campeonato_id'] ?? 0) === (int)$championshipId ? 'selected' : '' ?>><?= e($championshipName) ?></option><?php endforeach; ?></select>
                    <div class="card-page-items ranking-items"></div>
                    <nav class="card-pages"></nav>
                </article>
            </section>
            <?php if ($rival): ?><div class="modal fade compact-stats-modal" id="rivalry-history-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><small>Confronto direto</small><h2 class="modal-title"><?= e($time['time_nome']) ?> × <?= e($rival['nome']) ?></h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body rivalry-history-list"><?php $rivalGames = array_values(array_filter($jogadas, static fn($game) => in_array((int)$rival['id'], [(int)$game['mandante_id'], (int)$game['visitante_id']], true))); foreach ($rivalGames as $game): ?><div class="rivalry-history-game match-open" tabindex="0" role="button" data-match-type="<?= $game['origem'] === 'mata' ? 'mata' : 'pontos' ?>" data-match-id="<?= (int)$game['id'] ?>"><small><?= e(($game['origem'] === 'pontos' ? 'Rodada ' . $game['etapa'] : $game['etapa']) . ' - ' . $game['campeonato']) ?></small><div><?= match_team($game, 'home') ?><b><?= match_score($game) ?></b><?= match_team($game, 'away') ?></div></div><?php endforeach; ?><?php if (!$rivalGames): ?><p class="empty-copy">Nenhum confronto finalizado entre os times.</p><?php endif; ?></div></div></div></div><?php endif; ?>
            <?php if ($titulos): ?>
                <section class="titles-strip">
                    <h3>Títulos e campanhas</h3>
                    <?php foreach ($titulos as $titulo): ?>
                        <div>
                            <?php if (!empty($titulo['trofeu_url'])): ?><img src="<?= e($titulo['trofeu_url']) ?>" alt="Taça <?= e($titulo['titulo']) ?>"><?php else: ?><span>🏆</span><?php endif; ?>
                            <b><?= e($titulo["titulo"]) ?></b>
                            <small><?= e($titulo["temporada"]) ?></small>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
            <section class="future-label" id="conteudo-clube"><span>Conteúdo mantido pelo técnico</span></section>
            <section class="future-grid">
                <article class="lineup-placeholder">
                    <div class="lineup-module-head">
                        <h3>Escalação atual</h3><?php if ($canEditClubProfile): ?><div class="lineup-actions"><a class="lineup-edit-button" href="mercado.php<?= $clubePublico ? '?campeonato_id=' . (int)$clubePublico['campeonato_id'] : '' ?>">Editar escalação</a><?php if ($clubePublico): ?><button class="lineup-image-button" type="button" data-bs-toggle="modal" data-bs-target="#lineup-image-modal">Imagem</button><?php endif; ?></div><?php endif; ?>
                    </div>
                    <?php if ($clubePublico): ?><div class="lineup-tabs" role="tablist" aria-label="Visualização da escalação"><button type="button" role="tab" data-lineup-tab="image" aria-selected="<?= $lineupImagePath ? 'true' : 'false' ?>" <?= $lineupImagePath ? 'class="active"' : '' ?> <?= $lineupImagePath ? '' : 'disabled' ?>>Escalação</button><button type="button" role="tab" data-lineup-tab="players" aria-selected="<?= $lineupImagePath ? 'false' : 'true' ?>" <?= $lineupImagePath ? '' : 'class="active"' ?>>Ver jogadores</button></div>
                        <div class="lineup-panel" data-lineup-panel="image" <?= $lineupImagePath ? '' : 'hidden' ?>><?php if ($lineupImagePath): ?><img class="lineup-image" src="<?= e($lineupImagePath) ?>" alt="Escalação visual de <?= e($time['time_nome']) ?>"><?php endif; ?></div>
                        <div class="lineup-panel" data-lineup-panel="players" <?= $lineupImagePath ? 'hidden' : '' ?>><strong class="public-formation"><?= e($clubePublico['formacao']) ?></strong>
                        <div class="public-roster"><?php foreach ($elencoPublico as $jogador): if ($jogador['grupo'] !== 'titular') continue; ?><button class="player-open" type="button" data-player-name="<?= e($jogador['nome']) ?>" data-player-team="<?= $id ?>"><b><?= e($jogador['nome']) ?></b><span><?= (int)$jogador['overall'] ?> · <?= e($jogador['posicao']) ?></span></button><?php endforeach; ?></div></div><?php else: ?>
                        <div class="empty-pitch">
                            <span></span><span></span><span></span><span></span>
                            <span></span><span></span><span></span><span></span>
                            <span></span><span></span><span></span>
                            <p>Escalação ainda não informada</p>
                        </div>
                    <?php endif; ?>
                </article>
                <article class="treasury-module">
                    <div class="club-card-heading"><h3>Cofre do clube</h3><?php if ($canEditClubProfile && $clubePublico): ?><button class="club-card-edit" type="button" data-bs-toggle="modal" data-bs-target="#club-treasury-modal" aria-label="Editar cofre" title="Editar cofre">✎</button><?php endif; ?></div>
                    <strong><?= $clubePublico ? 'R$ ' . number_format((float)$clubePublico['saldo'], 0, ',', '.') : 'R$ —' ?></strong>
                    <p><?= $clubePublico ? 'Saldo atualizado do clube.' : 'Saldo ainda não informado.' ?></p>
                </article>
                <article class="transfer-history-module" data-transfer-module data-items-per-page="6">
                    <h3>Transferências</h3>
                    <div class="transfer-filters" role="group" aria-label="Filtrar transferências"><button class="active" type="button" data-transfer-filter="todas">Todas</button><button type="button" data-transfer-filter="compra">Compras</button><button type="button" data-transfer-filter="venda">Vendas</button></div>
                    <div class="transfer-page-items"><?php foreach ($transferenciasPublicas as $transferencia): ?><button type="button" class="transfer-entry player-open" data-transfer-type="<?= e($transferencia['tipo']) ?>" data-player-name="<?= e($transferencia['jogador_nome']) ?>" data-player-team="<?= $id ?>"><span class="transfer-kind <?= $transferencia['tipo'] === 'compra' ? 'is-purchase' : 'is-sale' ?>"><?= e(($transferencia['tipo'] === 'venda' ? 'Venda' : (($transferencia['origem'] ?? '') === 'pack' ? 'Pack' : mercado_rotulo_origem($transferencia)))) ?></span><b><?= e($transferencia['jogador_nome']) ?></b><small><?= (int)$transferencia['jogador_overall'] ?> · <?= e($transferencia['jogador_posicao']) ?><?= !empty($transferencia['origem_detalhe']) ? ' · ' . e($transferencia['origem_detalhe']) : '' ?> · <?= e(format_datetime_br((string)$transferencia['criado_em'])) ?></small><strong><?= e(mercado_valor_movimento($transferencia)) ?></strong></button><?php endforeach; ?><?php if (!$transferenciasPublicas): ?><p class="module-empty">Nenhuma transferência registrada.</p><?php endif; ?></div>
                    <nav class="transfer-pages card-pages"></nav>
                </article>
                <article class="reserves-module" data-card-pages="5">
                    <h3>Banco de reservas</h3>
                    <div class="card-page-items"><?php $reservas = array_values(array_filter($elencoPublico, fn($j) => $j['grupo'] === 'banco'));
                                                    foreach ($reservas as $jogador): ?><button class="player-open reserve-player" type="button" data-player-name="<?= e($jogador['nome']) ?>" data-player-team="<?= $id ?>"><strong><?= e($jogador['nome']) ?></strong> · <?= (int)$jogador['overall'] ?> · <?= e($jogador['posicao']) ?></button><?php endforeach; ?><?php if (!$reservas): ?><div class="module-empty">Nenhum reserva informado.</div><?php endif; ?></div>
                    <nav class="card-pages"></nav>
                </article>
                <article class="favorite-player-module">
                    <div class="club-card-heading"><h3>Herói do time</h3><?php if ($canEditClubProfile && $clubePublico): ?><button class="club-card-edit" type="button" data-bs-toggle="modal" data-bs-target="#club-hero-modal" aria-label="Editar herói do time" title="Editar herói do time">✎</button><?php endif; ?></div>
                    <?php if ($jogadorFavorito): ?><button class="player-open favorite-player-button" type="button" data-player-name="<?= e($jogadorFavorito['nome']) ?>" data-player-team="<?= $id ?>"><strong><?= e($jogadorFavorito['nome']) ?></strong><span><?= (int)$jogadorFavorito['overall'] ?> · <?= e($jogadorFavorito['posicao']) ?></span><small><?= $jogadorFavoritoGols ?> gols · <?= $jogadorFavoritoAssistencias ?> assistências</small></button><?php else: ?><p class="module-empty">Nenhum jogador escolhido.</p><?php endif; ?>
                </article>
                <article class="about-module">
                    <div class="club-card-heading"><h3>Sobre o clube</h3><?php if ($canEditClubProfile && $clubePublico): ?><button class="club-card-edit" type="button" data-bs-toggle="modal" data-bs-target="#club-about-modal" aria-label="Editar sobre o clube" title="Editar sobre o clube">✎</button><?php endif; ?></div>
                    <p><?= e(
                            $time["descricao"] ?:
                                "As informações do clube ainda não foram publicadas pelo responsável.",
                        ) ?></p>
                </article>
            </section>
            <?php if ($canEditClubProfile && $clubePublico): ?><div class="modal fade" id="club-profile-edit-modal" tabindex="-1" aria-labelledby="club-profile-edit-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><div class="modal-header"><div><small class="eyebrow">Conteúdo e finanças do clube</small><h2 class="modal-title" id="club-profile-edit-title">EDITAR PERFIL</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="atualizar_perfil_clube"><input type="hidden" name="campeonato_id" value="<?= (int)$clubePublico['campeonato_id'] ?>"><div class="mb-3"><label class="form-label" for="club-about">Sobre o clube</label><textarea class="form-control" id="club-about" name="descricao" maxlength="1200" rows="4" placeholder="Conte a história e a identidade do clube..."><?= e($time['descricao']) ?></textarea><small class="text-secondary">Este texto aparece publicamente no card Sobre o clube.</small></div><div class="mb-3"><label class="form-label" for="club-treasury">Cofre do clube</label><div class="input-group"><span class="input-group-text">R$</span><input class="form-control" id="club-treasury" name="saldo" inputmode="numeric" value="<?= e(number_format((float)$clubePublico['saldo'], 0, ',', '.')) ?>" required></div><small class="text-secondary">O cofre pode ser corrigido a qualquer momento.</small></div><div><label class="form-label" for="club-favorite">Herói do time</label><select class="form-select" id="club-favorite" name="jogador_favorito_id"><option value="">Nenhum jogador</option><?php foreach ($elencoPublico as $jogador): ?><option value="<?= (int)$jogador['id'] ?>" <?= (int)$jogador['id'] === (int)($clubePublico['jogador_favorito_id'] ?? 0) ? 'selected' : '' ?>><?= e($jogador['nome']) ?> · <?= (int)$jogador['overall'] ?> · <?= e($jogador['posicao']) ?></option><?php endforeach; ?></select><small class="text-secondary">Escolha entre os jogadores ativos do elenco quem representa o clube.</small></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger">Salvar perfil</button></div></form></div></div></div><?php endif; ?>
            <?php if ($canEditClubProfile && $clubePublico): ?>
                <div class="modal fade club-card-modal" id="lineup-image-modal" tabindex="-1" aria-labelledby="lineup-image-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                    <form method="post" enctype="multipart/form-data" data-lineup-upload-form><div class="modal-header"><div><small class="eyebrow">Somente visual</small><h2 class="modal-title" id="lineup-image-title">IMAGEM DA ESCALAÇÃO</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="salvar_imagem_escalacao"><input type="hidden" name="campeonato_id" value="<?= (int)$clubePublico['campeonato_id'] ?>"><label class="lineup-dropzone" for="lineup-image-input" data-lineup-dropzone><input id="lineup-image-input" type="file" name="imagem_escalacao" accept="image/png,image/jpeg,image/webp" required><img data-lineup-preview <?= $lineupImagePath ? 'src="' . e($lineupImagePath) . '"' : 'hidden' ?> alt="Prévia da escalação"><span data-lineup-drop-copy><b>Arraste a imagem aqui</b><small>ou clique para escolher · PNG, JPEG ou WebP · até 12 MB</small></span></label><p class="lineup-upload-help">Use a imagem quadrada gerada pelo bot, como no exemplo. Ela não altera os titulares cadastrados.</p></div><div class="modal-footer"><?php if ($lineupImagePath): ?><button class="btn btn-outline-danger me-auto" type="submit" form="lineup-image-remove-form">Remover imagem</button><?php endif; ?><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger" type="submit"><?= $lineupImagePath ? 'Substituir imagem' : 'Enviar imagem' ?></button></div></form>
                    <?php if ($lineupImagePath): ?><form id="lineup-image-remove-form" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remover_imagem_escalacao"><input type="hidden" name="campeonato_id" value="<?= (int)$clubePublico['campeonato_id'] ?>"></form><?php endif; ?>
                </div></div></div>
                <div class="modal fade club-card-modal" id="club-about-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><div class="modal-header"><h2 class="modal-title">EDITAR SOBRE O CLUBE</h2><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="atualizar_sobre_clube"><input type="hidden" name="campeonato_id" value="<?= (int)$clubePublico['campeonato_id'] ?>"><label class="form-label" for="club-about-only">Sobre o clube</label><textarea class="form-control" id="club-about-only" name="descricao" maxlength="1200" rows="8" placeholder="Conte a história e a identidade do clube..."><?= e($time['descricao']) ?></textarea></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger">Salvar sobre</button></div></form></div></div></div>
                <div class="modal fade club-card-modal" id="club-treasury-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><div class="modal-header"><h2 class="modal-title">EDITAR COFRE</h2><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="atualizar_cofre_clube"><input type="hidden" name="campeonato_id" value="<?= (int)$clubePublico['campeonato_id'] ?>"><label class="form-label" for="club-treasury-only">Saldo do cofre</label><div class="input-group"><span class="input-group-text">R$</span><input class="form-control" id="club-treasury-only" name="saldo" inputmode="numeric" value="<?= e(number_format((float)$clubePublico['saldo'], 0, ',', '.')) ?>" required></div><small class="text-secondary">Confirme o saldo antes de iniciar a gestão do elenco.</small></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger">Salvar cofre</button></div></form></div></div></div>
                <div class="modal fade club-card-modal" id="club-hero-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><div class="modal-header"><h2 class="modal-title">EDITAR HERÓI DO TIME</h2><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="atualizar_heroi_clube"><input type="hidden" name="campeonato_id" value="<?= (int)$clubePublico['campeonato_id'] ?>"><label class="form-label" for="club-hero-only">Herói do time</label><select class="form-select" id="club-hero-only" name="jogador_favorito_id"><option value="">Nenhum jogador</option><?php foreach ($elencoPublico as $jogador): ?><option value="<?= (int)$jogador['id'] ?>" <?= (int)$jogador['id'] === (int)($clubePublico['jogador_favorito_id'] ?? 0) ? 'selected' : '' ?>><?= e($jogador['nome']) ?> · <?= (int)$jogador['overall'] ?> · <?= e($jogador['posicao']) ?></option><?php endforeach; ?></select></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger">Salvar herói</button></div></form></div></div></div>
            <?php endif; ?>
        </main>
        <?php endif; ?><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
        <script src="assets/js/team-page.js?v=<?= filemtime(
                                                    __DIR__ . "/assets/js/team-page.js",
                                                ) ?>"></script>
</body>

</html>
