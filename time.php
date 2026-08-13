<?php
declare(strict_types=1);
require __DIR__ . "/includes/bootstrap.php";
require __DIR__ . "/includes/public-layout.php";
$id = (int) ($_GET["id"] ?? 0);
$time = null;
$databaseUnavailable = false;
$artilheiros = [];
$jogadas = [];
$proximas = [];
$titulos = [];
$responsavel = null;
try {
    $pdo = db();
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
    }
} catch (Throwable $e) {
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
    $prefix = $side === "home" ? "mandante_" : "visitante_";
    $name = $j[$prefix === "mandante_" ? "mandante" : "visitante"];
    return '<a class="match-team' .
        ($showName ? '' : ' match-team--shield-only') .
        '" href="time.php?id=' .
        (int) $j[$prefix . "id"] .
        '" aria-label="' . e($name) . '" title="' . e($name) .
        '">' .
        (!$showName
            ? shield($j, $prefix)
            : ($side === "away"
            ? "<span>" .
                e($name) .
                "</span>" .
                shield($j, $prefix)
            : shield($j, $prefix) .
                "<span>" .
                e($name) .
                "</span>")) .
        "</a>";
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
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e(
    $time ? $time["time_nome"] . " | Vascão S3" : "Time não encontrado",
) ?></title><link rel="icon" href="favicon.ico"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/branding.css?v=5"><link rel="stylesheet" href="assets/css/team-profile.css?v=<?= filemtime(
    __DIR__ . "/assets/css/team-profile.css",
) ?>"><link rel="stylesheet" href="assets/css/socials.css?v=<?= filemtime(__DIR__ . "/assets/css/socials.css") ?>"></head><body>
<?php public_navbar(); ?>
<?php if (
    !$time
): ?><main class="wide-container error-page"><h1><?= $databaseUnavailable
    ? "Dados indisponíveis"
    : "Time não encontrado" ?></h1><a class="btn btn-danger" href="index.php#participantes">Ver participantes</a></main><?php else: ?>
<header class="club-hero"><div class="wide-container club-hero-grid"><div class="club-main-shield"><?php if (
    $time["escudo_url"]
): ?><img src="<?= e($time["escudo_url"]) ?>" alt="Escudo de <?= e(
    $time["time_nome"],
) ?>"><?php else: ?><span><?= e(
    $time["sigla"],
) ?></span><?php endif; ?></div><div class="club-copy"><a class="club-back-link" href="index.php#participantes" aria-label="Voltar para todos os times"><span aria-hidden="true">←</span> Todos os times</a><h1><?= e(
    $time["time_nome"],
) ?></h1><p><b><?= e($time["sigla"]) ?></b><i></i>Técnico: <strong><?= e(
    $time["nome"],
) ?></strong></p><small>Participante da Season 3</small><div><?= e(
    $time["descricao"] ?: "Participante da Vascão Season 3.",
) ?></div></div><?php if (!$responsavel): ?><aside class="claim-card"><small>Essa página tem dono?</small><h2>Página não vinculada</h2><p>A associação desta página é confirmada pela administração.</p><?php if (
    !account_logged_in()
): ?><a class="btn btn-outline-danger" href="cadastro.php">Criar conta</a><?php endif; ?></aside><?php endif; ?><?php if (
    $time["escudo_url"]
): ?><img class="club-watermark" src="<?= e(
    $time["escudo_url"],
) ?>" alt="" aria-hidden="true"><?php endif; ?></div></header>
<main class="wide-container club-page"><section class="club-stats"><?php foreach (
    $stats
    as $label => $value
): ?><div><small><?= e($label) ?></small><strong><?= ($label === "Saldo" &&
$value > 0
    ? "+"
    : "") .
    $value ?></strong></div><?php endforeach; ?></section><small class="auto-label">Atualizado automaticamente pelas competições</small>
<section class="overview-grid"><article class="overview-card recent-card" data-card-pages="3"><h3>Últimos jogos</h3><div class="card-page-items"><?php foreach (
    $jogadas
    as $j
): ?><div class="compact-match"><?= match_team($j, "home") ?><b><?= match_score(
    $j,
) ?></b><?= match_team(
    $j,
    "away",
) ?></div><?php endforeach; ?></div><?php if (
    !$jogadas
): ?><p class="empty-copy">Nenhum resultado.</p><?php endif; ?><nav class="card-pages"></nav></article><article class="overview-card next-card" data-card-pages="1"><h3>Próximo confronto</h3><div class="card-page-items"><?php foreach (
    $proximas
    as $j
): ?><div class="next-item"><div class="versus"><?= match_team(
    $j,
    "home",
    false,
) ?><b>VS</b><?= match_team($j, "away", false) ?></div><p><?= e(
    $j["origem"] === "pontos" ? "Rodada " . $j["etapa"] : $j["etapa"],
) ?><br><?= $j["data_jogo"]
    ? e(date("d/m • H:i", strtotime($j["data_jogo"])))
    : "Data a definir" ?></p></div><?php endforeach; ?></div><?php if (
    !$proximas
): ?><p class="empty-copy">Nenhum confronto agendado.</p><?php endif; ?><nav class="card-pages"></nav></article><article class="overview-card rivalry-card"><h3>Confronto direto</h3><?php if (
    $rival
): ?><div class="versus"><?= shield($time) ?><b>VS</b><?= shield(
    $rival,
) ?></div><strong><?= e($rival["nome"]) ?></strong><p><?= $rival[
    "jogos"
] ?> jogos • <?= $rival["v"] ?> vitórias • <?= $rival[
     "e"
 ] ?> empates • <?= $rival[
     "d"
 ] ?> derrotas</p><?php else: ?><p class="empty-copy">Sem histórico disponível.</p><?php endif; ?></article><article class="overview-card scorers-card" data-card-pages="3"><h3>Artilheiros</h3><div class="card-page-items"><?php
foreach ($artilheiros as $pos => $a): ?><div><b><?= str_pad(
    (string) ($pos + 1),
    2,
    "0",
    STR_PAD_LEFT,
) ?></b><span><?= e($a["jogador"]) ?></span><strong><?= $a[
    "gols"
] ?></strong></div><?php endforeach;
if (
    !$artilheiros
): ?><p class="empty-copy">Nenhum gol registrado.</p><?php endif;
?></div><nav class="card-pages"></nav></article></section>
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
<section class="future-label">Conteúdo mantido pelo técnico</section>
<section class="future-grid">
  <article class="lineup-placeholder">
    <h3>Escalação atual</h3>
    <div class="empty-pitch">
      <span></span><span></span><span></span><span></span>
      <span></span><span></span><span></span><span></span>
      <span></span><span></span><span></span>
      <p>Escalação ainda não informada</p>
    </div>
  </article>
  <article class="treasury-module">
    <h3>Cofre do clube</h3>
    <strong>R$ —</strong>
    <p>Saldo ainda não informado.</p>
  </article>
  <article class="transfers-module">
    <h3>Últimas contratações</h3>
    <div class="module-empty">Nenhuma movimentação registrada.</div>
  </article>
  <article class="wall-module">
    <h3>Mural do clube</h3>
    <blockquote>Nenhuma publicação do clube.</blockquote>
  </article>
  <article class="about-module">
    <h3>Sobre o clube</h3>
    <p><?= e(
    $time["descricao"] ?:
    "As informações do clube ainda não foram publicadas pelo responsável.",
) ?></p>
  </article>
</section>
</main>
<?php endif; ?><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script src="assets/js/team-page.js?v=<?= filemtime(
    __DIR__ . "/assets/js/team-page.js",
) ?>"></script></body></html>
