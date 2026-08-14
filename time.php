<?php

declare(strict_types=1);
require __DIR__ . "/includes/bootstrap.php";
require __DIR__ . "/includes/public-layout.php";
require __DIR__ . "/includes/mercado.php";
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
$jogadas = [];
$proximas = [];
$titulos = [];
$responsavel = null;
$elencoPublico = [];
$clubePublico = null;
$jogadorFavorito = null;
$transferenciasPublicas = [];
$canEditClubProfile = account_logged_in() && (
    account_is_master() || (int)(account_participant_id() ?? 0) === $id
);
$profileNotice = isset($_GET['perfil']) ? 'Perfil do clube atualizado.' : '';
try {
    $pdo = db();
    mercado_garantir_estrutura($pdo);
    $profileAction = (string)($_POST['action'] ?? '');
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
            $pdo->prepare("UPDATE clubes_campeonato SET saldo=?,cofre_configurado=1 WHERE campeonato_id=? AND participante_id=?")->execute([$saldo, $campeonatoPerfilId, $id]);
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
            "SELECT a.jogador,SUM(a.gols) gols,GROUP_CONCAT(DISTINCT c.nome ORDER BY c.criado_em DESC SEPARATOR ', ') campeonatos FROM artilharia a JOIN campeonatos c ON c.id=a.campeonato_id WHERE a.participante_id=? AND a.gols>0 GROUP BY a.jogador ORDER BY gols DESC,a.jogador LIMIT 10",
        );
        $stmt->execute([$id]);
        $artilheiros = $stmt->fetchAll();
        $stmt = $pdo->prepare(
            "SELECT * FROM (SELECT p.id,p.campeonato_id,c.nome campeonato,p.data_partida data_jogo,p.rodada etapa,p.status,m.id mandante_id,m.time_nome mandante,m.sigla mandante_sigla,m.escudo_url mandante_escudo,v.id visitante_id,v.time_nome visitante,v.sigla visitante_sigla,v.escudo_url visitante_escudo,p.gols_mandante gols_a,p.gols_visitante gols_b,NULL penaltis_a,NULL penaltis_b,'pontos' origem FROM partidas p JOIN campeonatos c ON c.id=p.campeonato_id JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id WHERE p.ativo=1 AND(p.mandante_id=? OR p.visitante_id=?) UNION ALL SELECT j.id,j.campeonato_id,c.nome,NULL,j.fase,j.status,a.id,a.time_nome,a.sigla,a.escudo_url,b.id,b.time_nome,b.sigla,b.escudo_url,j.gols_a,j.gols_b,j.penaltis_a,j.penaltis_b,'mata' FROM jogos_mata_mata j JOIN campeonatos c ON c.id=j.campeonato_id JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id WHERE j.ativo=1 AND(j.time_a_id=? OR j.time_b_id=?)) jogos",
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
            if ($da && $db && $da !== $db) {
                return $db <=> $da;
            }
            if ((int) $a["campeonato_id"] !== (int) $b["campeonato_id"]) {
                return (int) $b["campeonato_id"] <=> (int) $a["campeonato_id"];
            }
            return (int) $b["id"] <=> (int) $a["id"];
        });
        usort($proximas, function ($a, $b) {
            $da = strtotime((string) ($a["data_jogo"] ?? "")) ?: PHP_INT_MAX;
            $db = strtotime((string) ($b["data_jogo"] ?? "")) ?: PHP_INT_MAX;
            return $da === $db
                ? (int) $a["id"] <=> (int) $b["id"]
                : $da <=> $db;
        });
        $stmt = $pdo->prepare(
            "SELECT titulo,temporada FROM titulos WHERE participante_id=? ORDER BY conquistado_em DESC,id DESC",
        );
        $stmt->execute([$id]);
        $titulos = $stmt->fetchAll();
        try {
            $stmt = $pdo->prepare("SELECT cc.saldo,cc.cofre_configurado,cc.formacao,cc.campeonato_id,cc.mural,cc.jogador_favorito_id,c.nome campeonato FROM clubes_campeonato cc JOIN campeonatos c ON c.id=cc.campeonato_id WHERE cc.participante_id=? ORDER BY c.status='ativo' DESC,c.id DESC LIMIT 1");
            $stmt->execute([$id]);
            $clubePublico = $stmt->fetch() ?: null;
            if ($clubePublico) {
                $stmt = $pdo->prepare("SELECT id,nome,overall,posicao,grupo,ordem,campo_x,campo_y FROM jogadores_elenco WHERE campeonato_id=? AND participante_id=? AND ativo=1 ORDER BY grupo='titular' DESC,ordem,nome");
                $stmt->execute([(int)$clubePublico['campeonato_id'], $id]);
                $elencoPublico = $stmt->fetchAll();
                $stmt = $pdo->prepare("SELECT tipo,origem,origem_detalhe,valor_origem,moeda_origem,jogador_nome,jogador_overall,jogador_posicao,valor,criado_em FROM movimentacoes_elenco WHERE campeonato_id=? AND participante_id=? AND tipo IN ('compra','venda') ORDER BY id DESC");
                $stmt->execute([(int)$clubePublico['campeonato_id'], $id]);
                $transferenciasPublicas = $stmt->fetchAll();
                foreach ($elencoPublico as $jogadorElenco) {
                    if ((int)$jogadorElenco['id'] === (int)$clubePublico['jogador_favorito_id']) {
                        $jogadorFavorito = $jogadorElenco;
                        break;
                    }
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
                    <div><?= e(
                                $time["descricao"] ?: "Participante da Vascão Season 3.",
                            ) ?></div>
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
                                                            $j["origem"] === "pontos" ? "Rodada " . $j["etapa"] : $j["etapa"],
                                                        ) ?><br><?= $j["data_jogo"]
                                                                    ? e(date("d/m • H:i", strtotime($j["data_jogo"])))
                                                                    : "Data a definir" ?></p>
                            </div><?php endforeach; ?></div><?php if (
                                                                !$proximas
                                                            ): ?><p class="empty-copy">Nenhum confronto agendado.</p><?php endif; ?><nav class="card-pages"></nav>
                </article>
                <article class="overview-card rivalry-card">
                    <h3>Confronto direto</h3><?php if (
                                                    $rival
                                                ): ?><div class="versus"><span class="match-team match-team--shield-only match-team--current" data-current-team aria-current="page"><?= shield($time) ?></span><b>VS</b><a class="match-team match-team--shield-only" href="time.php?id=<?= (int)$rival['id'] ?>" aria-label="<?= e($rival['nome']) ?>" title="<?= e($rival['nome']) ?>"><?= shield($rival) ?></a></div><a class="rival-team-link" href="time.php?id=<?= (int)$rival['id'] ?>"><?= e($rival["nome"]) ?></a>
                        <p><?= $rival["jogos"] ?> jogos • <?= $rival["v"] ?> vitórias • <?= $rival["e"] ?> empates • <?= $rival["d"] ?> derrotas</p><?php else: ?><p class="empty-copy">Sem histórico disponível.</p><?php endif; ?>
                </article>
                <article class="overview-card scorers-card" data-card-pages="3">
                    <h3>Artilheiros</h3>
                    <div class="card-page-items"><?php
                                                    foreach ($artilheiros as $pos => $a): ?><div><b><?= str_pad(
                                                                                                        (string) ($pos + 1),
                                                                                                        2,
                                                                                                        "0",
                                                                                                        STR_PAD_LEFT,
                                                                                                    ) ?></b><span><?= e($a["jogador"]) ?></span><strong><?= $a["gols"] ?></strong></div><?php endforeach;
                                                                                                                                        if (
                                                                                                                                            !$artilheiros
                                                                                                                                        ): ?><p class="empty-copy">Nenhum gol registrado.</p><?php endif;
                                                                                                            ?></div>
                    <nav class="card-pages"></nav>
                </article>
            </section>
            <?php if ($titulos): ?>
                <section class="titles-strip">
                    <h3>Títulos e campanhas</h3>
                    <?php foreach ($titulos as $titulo): ?>
                        <div>
                            <span>🏆</span>
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
                        <h3>Escalação atual</h3><?php if ($canEditClubProfile): ?><a class="lineup-edit-button" href="mercado.php<?= $clubePublico ? '?campeonato_id=' . (int)$clubePublico['campeonato_id'] : '' ?>">Editar escalação</a><?php endif; ?>
                    </div>
                    <?php if ($clubePublico): ?><strong class="public-formation"><?= e($clubePublico['formacao']) ?></strong>
                        <div class="public-roster"><?php foreach ($elencoPublico as $jogador): if ($jogador['grupo'] !== 'titular') continue; ?><div><b><?= e($jogador['nome']) ?></b><span><?= (int)$jogador['overall'] ?> · <?= e($jogador['posicao']) ?></span></div><?php endforeach; ?></div><?php else: ?>
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
                    <div class="transfer-page-items"><?php foreach ($transferenciasPublicas as $transferencia): ?><div class="transfer-entry" data-transfer-type="<?= e($transferencia['tipo']) ?>"><span class="transfer-kind <?= $transferencia['tipo'] === 'compra' ? 'is-purchase' : 'is-sale' ?>"><?= e(($transferencia['tipo'] === 'venda' ? 'Venda' : (($transferencia['origem'] ?? '') === 'pack' ? 'Pack' : mercado_rotulo_origem($transferencia)))) ?></span><b><?= e($transferencia['jogador_nome']) ?></b><small><?= (int)$transferencia['jogador_overall'] ?> · <?= e($transferencia['jogador_posicao']) ?><?= !empty($transferencia['origem_detalhe']) ? ' · ' . e($transferencia['origem_detalhe']) : '' ?></small><strong><?= e(mercado_valor_movimento($transferencia)) ?></strong></div><?php endforeach; ?><?php if (!$transferenciasPublicas): ?><p class="module-empty">Nenhuma transferência registrada.</p><?php endif; ?></div>
                    <nav class="transfer-pages card-pages"></nav>
                </article>
                <article class="reserves-module" data-card-pages="5">
                    <h3>Banco de reservas</h3>
                    <div class="card-page-items"><?php $reservas = array_values(array_filter($elencoPublico, fn($j) => $j['grupo'] === 'banco'));
                                                    foreach ($reservas as $jogador): ?><p><strong><?= e($jogador['nome']) ?></strong> · <?= (int)$jogador['overall'] ?> · <?= e($jogador['posicao']) ?></p><?php endforeach; ?><?php if (!$reservas): ?><div class="module-empty">Nenhum reserva informado.</div><?php endif; ?></div>
                    <nav class="card-pages"></nav>
                </article>
                <article class="favorite-player-module">
                    <div class="club-card-heading"><h3>Herói do time</h3><?php if ($canEditClubProfile && $clubePublico): ?><button class="club-card-edit" type="button" data-bs-toggle="modal" data-bs-target="#club-hero-modal" aria-label="Editar herói do time" title="Editar herói do time">✎</button><?php endif; ?></div>
                    <?php if ($jogadorFavorito): ?><strong><?= e($jogadorFavorito['nome']) ?></strong><p><?= (int)$jogadorFavorito['overall'] ?> · <?= e($jogadorFavorito['posicao']) ?></p><?php else: ?><p class="module-empty">Nenhum jogador escolhido.</p><?php endif; ?>
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
