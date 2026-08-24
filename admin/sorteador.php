<?php
// Carrega a estrutura compartilhada e exige login de administrador.
require __DIR__ . "/../includes/bootstrap.php";
master_required();
$pdo = db();
competition_identities_seed($pdo);
$embedded = isset($_GET["embed"]);
$notice = $_SESSION["notice"] ?? "";
unset($_SESSION["notice"]);
function draw_ajax(): bool
{
    return isset($_POST["_ajax"]) ||
        strtolower((string) ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "")) ===
            "xmlhttprequest";
}
function go(string $message): never
{
    audit_post_success("sorteador", $message);
    if (draw_ajax()) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(
            ["ok" => true, "message" => $message],
            JSON_UNESCAPED_UNICODE,
        );
        exit();
    }
    $_SESSION["notice"] = $message;
    header("Location: sorteador.php");
    exit();
}
// Valida os participantes escolhidos e embaralha seus IDs.
function selected(PDO $pdo): array
{
    $ids = array_values(
        array_unique(
            array_filter(array_map("intval", $_POST["participantes"] ?? [])),
        ),
    );
    if (count($ids) < 2) {
        throw new RuntimeException("Selecione pelo menos 2 participantes.");
    }
    $marks = implode(",", array_fill(0, count($ids), "?"));
    $stmt = $pdo->prepare(
        "SELECT id FROM participantes WHERE ativo=1 AND id IN ($marks)",
    );
    $stmt->execute($ids);
    $valid = array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($valid) !== count($ids)) {
        throw new RuntimeException("Participante inválido ou inativo.");
    }
    shuffle($valid);
    return $valid;
}
// Gera rodadas em que cada time enfrenta todos os outros.
function rounds(array $ids): array
{
    if (count($ids) % 2) {
        $ids[] = null;
    }
    $count = count($ids);
    $result = [];
    for ($r = 1; $r < $count; $r++) {
        $games = [];
        for ($i = 0; $i < $count / 2; $i++) {
            $a = $ids[$i];
            $b = $ids[$count - 1 - $i];
            if ($a !== null && $b !== null) {
                $games[] = $r % 2 ? [$a, $b]: [$b, $a];
            }
        }
        $result[] = $games;
        $fixed = array_shift($ids);
        $last = array_pop($ids);
        array_unshift($ids, $fixed);
        array_splice($ids, 1, 0, [$last]);
    }
    return $result;
}
// Retorna todas as fases que precisam existir no chaveamento sorteado.
function knockout_phases(int $count): array
{
    return match ($count) {
        4 => ["Semifinal", "Final"],
        8 => ["Quartas", "Semifinal", "Final"],
        10 => ["Preliminar", "Quartas", "Semifinal", "Final"],
        16 => ["Oitavas", "Quartas", "Semifinal", "Final"],
    };
}
// Executa o sorteio enviado por um dos formulários.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    try {
        $action = $_POST["action"] ?? "";
        $ids = selected($pdo);
        $championshipName = trim($_POST["nome_campeonato"] ?? "");
        $identityId = (int)($_POST['identidade_id'] ?? 0);
        if ($identityId > 0) {
            $identityCheck = $pdo->prepare('SELECT COUNT(*) FROM competicao_identidades WHERE id=?');
            $identityCheck->execute([$identityId]);
            if (!(int)$identityCheck->fetchColumn()) throw new RuntimeException('Modelo de competição inválido.');
        }
        if ($championshipName === "") {
            throw new RuntimeException("Informe o nome do campeonato.");
        }
        // Sorteia todas as rodadas dos pontos corridos.
        if ($action === "pontos") {
            $format = $_POST["formato"] ?? "ida";
            if (!in_array($format, ["ida", "ida_volta"], true)) {
                throw new RuntimeException("Formato inválido.");
            }
            $all = rounds($ids);
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO campeonatos(nome,identidade_id,tipo,formato) VALUES(?,?,'pontos_corridos',?)",
            )->execute([$championshipName, $identityId ?: null, $format]);
            $championshipId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare(
                "INSERT INTO partidas(campeonato_id,rodada,turno,mandante_id,visitante_id,gols_mandante,gols_visitante,data_partida,status,comprovacao_url) VALUES(?,?,?,?,?,NULL,NULL,NULL,'agendada','')",
            );
            foreach ($all as $r => $games) {
                foreach ($games as [$a, $b]) {
                    $stmt->execute([$championshipId, $r + 1, 1, $a, $b]);
                };
            }
            if ($format === "ida_volta") {
                $offset = count($all);
                foreach ($all as $r => $games) {
                    foreach ($games as [$a, $b]) {
                        $stmt->execute([
                            $championshipId,
                            $offset + $r + 1,
                            2,
                            $b,
                            $a,
                        ]);
                    };
                }
            }
            $pdo->commit();
            go("Pontos corridos sorteados. Os jogos já apareceram no site.");
        }
        // Sorteia a primeira fase do mata-mata.
        if ($action === "mata") {
            $count = count($ids);
            $format = $_POST["formato"] ?? "unico";
            $finalFormat = $_POST["formato_final"] ?? "unico";
            $thirdFormat = $_POST["formato_terceiro"] ?? "unico";
            if (!in_array($count, [4, 8, 10, 16], true)) {
                throw new RuntimeException(
                    "Selecione 4, 8, 10 ou 16 participantes.",
                );
            }
            foreach ([$format, $finalFormat, $thirdFormat] as $selectedFormat) {
                if (!in_array($selectedFormat, ["unico", "ida_volta"], true)) {
                    throw new RuntimeException("Formato inválido.");
                }
            }
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO campeonatos(nome,identidade_id,tipo,formato) VALUES(?,?,'mata_mata',?)",
            )->execute([$championshipName, $identityId ?: null, $format]);
            $championshipId = (int) $pdo->lastInsertId();
            $phases = knockout_phases($count);
            $stmt = $pdo->prepare(
                "INSERT INTO jogos_mata_mata(campeonato_id,fase,ordem,jogo,time_a_id,time_b_id,origem_a_fase,origem_a_ordem,origem_b_fase,origem_b_ordem,gols_a,gols_b,vencedor_id,status) VALUES(?,?,?,?,?,?,?,?,?,?,NULL,NULL,NULL,'agendado')",
            );
            foreach ($phases as $phaseIndex => $phase) {
                if ($count === 10 && $phase === "Preliminar") {
                    $ties = 2;
                } elseif ($count === 10) {
                    $ties = ["Quartas" => 4, "Semifinal" => 2, "Final" => 1][$phase];
                } else {
                    $ties = intdiv($count, 2 ** ($phaseIndex + 1));
                }
                for ($order = 1; $order <= $ties; $order++) {
                    if ($count === 10 && $phase === "Quartas") {
                        if ($order <= 2) {
                            $timeA = null;
                            $timeB = $ids[$order + 3];
                            $originPhase = "Preliminar";
                            $originA = $order;
                            $originB = null;
                        } else {
                            $offset = 2 + (($order - 3) * 2);
                            $timeA = $ids[$offset + 4];
                            $timeB = $ids[$offset + 5];
                            $originPhase = null;
                            $originA = null;
                            $originB = null;
                        }
                    } elseif ($phaseIndex === 0) {
                        $timeA = $ids[($order - 1) * 2];
                        $timeB = $ids[($order - 1) * 2 + 1];
                        $originPhase = null;
                        $originA = null;
                        $originB = null;
                    } else {
                        $timeA = null;
                        $timeB = null;
                        $originPhase = $phases[$phaseIndex - 1];
                        $originA = ($order - 1) * 2 + 1;
                        $originB = $originA + 1;
                    }
                    $stmt->execute([
                        $championshipId,
                        $phase,
                        $order,
                        1,
                        $timeA,
                        $timeB,
                        $originA !== null ? $originPhase: null,
                        $originA,
                        $originB !== null ? $originPhase: null,
                        $originB,
                    ]);
                    $phaseFormat = $phase === "Final" ? $finalFormat: $format;
                    if ($phaseFormat === "ida_volta") {
                        $stmt->execute([
                            $championshipId,
                            $phase,
                            $order,
                            2,
                            $timeB,
                            $timeA,
                            $originB !== null ? $originPhase: null,
                            $originB,
                            $originA !== null ? $originPhase: null,
                            $originA,
                        ]);
                    }
                }
            }
            // A disputa de terceiro lugar usa o formato escolhido independentemente das demais fases.
            $bronze = $pdo->prepare(
                "INSERT INTO jogos_mata_mata(campeonato_id,fase,ordem,jogo,time_a_id,time_b_id,origem_a_fase,origem_a_ordem,origem_a_tipo,origem_b_fase,origem_b_ordem,origem_b_tipo,gols_a,gols_b,vencedor_id,status) VALUES(?,'Terceiro lugar',1,1,NULL,NULL,'Semifinal',1,'perdedor','Semifinal',2,'perdedor',NULL,NULL,NULL,'agendado')",
            );
            $bronze->execute([$championshipId]);
            if ($thirdFormat === "ida_volta") {
                $bronzeVolta = $pdo->prepare(
                    "INSERT INTO jogos_mata_mata(campeonato_id,fase,ordem,jogo,time_a_id,time_b_id,origem_a_fase,origem_a_ordem,origem_a_tipo,origem_b_fase,origem_b_ordem,origem_b_tipo,gols_a,gols_b,vencedor_id,status) VALUES(?,'Terceiro lugar',1,2,NULL,NULL,'Semifinal',2,'perdedor','Semifinal',1,'perdedor',NULL,NULL,NULL,'agendado')",
                );
                $bronzeVolta->execute([$championshipId]);
            }
            $pdo->commit();
            go("Mata-mata sorteado. O chaveamento já apareceu no site.");
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (draw_ajax()) {
            header("Content-Type: application/json; charset=utf-8");
            http_response_code(422);
            echo json_encode(
                ["ok" => false, "message" => "Erro: " . $e->getMessage()],
                JSON_UNESCAPED_UNICODE,
            );
            exit();
        }
        $notice = "Erro: " . $e->getMessage();
    }
}
$teams = $pdo
    ->query(
        "SELECT id,nome,time_nome FROM participantes WHERE ativo=1 ORDER BY time_nome",
    )
    ->fetchAll();
$competitionModels = $pdo->query("SELECT i.id,i.nome,i.chave,MAX(CASE WHEN c.status<>'finalizado' THEN 1 ELSE 0 END) em_andamento FROM competicao_identidades i LEFT JOIN campeonatos c ON c.identidade_id=i.id AND c.ativo=1 GROUP BY i.id ORDER BY i.nome")->fetchAll();
$registeredNames = [];
foreach ($pdo->query("SELECT identidade_id,nome FROM campeonatos WHERE ativo=1") as $competition) {
    $registeredNames[(int)$competition['identidade_id']][] = (string)$competition['nome'];
}
$historicalNames = $pdo->query("SELECT titulo FROM titulos")->fetchAll(PDO::FETCH_COLUMN);
function draw_edition_number(string $name): int
{
    if (!preg_match('/\b([IVXLCDM]+)$/i', trim($name), $match)) return 1;
    $values = ['I'=>1,'V'=>5,'X'=>10,'L'=>50,'C'=>100,'D'=>500,'M'=>1000];
    $number = 0; $previous = 0;
    for ($index = strlen($match[1]) - 1; $index >= 0; $index--) {
        $current = $values[strtoupper($match[1][$index])] ?? 0;
        $number += $current < $previous ? -$current: $current;
        $previous = max($previous, $current);
    }
    return max(1, $number);
}
$availableModels = [];
foreach ($competitionModels as $model) {
    // Não permite criar a próxima edição enquanto a atual ainda está em andamento.
    if ((int)$model['em_andamento'] === 1) continue;
    $known = 0;
    foreach ($historicalNames as $historicalName) {
        if (competition_identity_match((string)$historicalName) === $model['chave']) {
            $known = max($known, draw_edition_number((string)$historicalName));
        }
    }
    foreach ($registeredNames[(int)$model['id']] ?? [] as $registeredName) {
        $known = max($known, draw_edition_number($registeredName));
    }
    $model['proxima_edicao'] = max(1, $known + 1);
    $availableModels[] = $model;
}
$competitionModels = $availableModels;
function checks(array $teams): string
{
    $out = "";
    foreach ($teams as $t) {
        $out .=
            '<div class="col-md-6"><label class="form-check sorteio-team"><input class="form-check-input" type="checkbox" name="participantes[]" value="' .
            (int) $t["id"] .
            '" checked><span><strong>' .
            e($t["time_nome"]) .
            "</strong><small>Técnico " .
            e($t["nome"]) .
            "</small></span></label></div>";
    }
    return $out;
}
?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sorteador | Season 3</title><link rel="icon" href="../favicon.ico" sizes="any"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="../assets/css/branding.css?v=5"><style>.admin-shell{padding:<?= $embedded ? '0 0 40px': '105px 0 60px' ?>}.sorteio-form{padding:1.4rem}.sorteio-form h2{font:800 1.8rem 'Barlow Condensed',sans-serif;text-transform:uppercase}.form-select{background:#0b0c0e;border-color:#343941}.sorteio-team{display:flex;gap:.7rem;height:100%;padding:1rem 1rem 1rem 2.3rem;border:1px solid #343941;cursor:pointer}.sorteio-team small{display:block;color:#8d98ad}.sorteio-team:has(input:checked){border-color:#ed1b2f;background:rgba(237,27,47,.08)}</style></head><body>
<?php if (!$embedded): ?><nav class="navbar fixed-top navbar-dark"><div class="container-fluid px-3 px-lg-4"><a class="navbar-brand" href="index.php"><img class="brand-mark d-inline-block me-2" src="../assets/img/logo-season3.webp?v=5" alt="Vascao Season 3"> SORTEADOR S3</a><div class="global-nav-links" aria-label="Seções públicas"><a href="../index.php#competicao">Competição</a><a href="../index.php#artilharia">Jogadores</a><a href="../index.php#participantes">Participantes</a><a href="../index.php#titulos">Títulos</a><a href="../index.php#midia">Vídeos</a></div><div><a href="index.php" class="btn btn-outline-light btn-sm me-2">Administração</a><a href="../index.php" class="btn btn-danger btn-sm" target="_blank">Abrir site</a></div></div></nav><?php endif; ?>
<main class="admin-shell"><div class="<?= $embedded ? 'container-fluid px-0': 'container' ?>"><span class="eyebrow">Sorteio automático</span><h1 class="display-4 fw-bold mb-4">CRIAR COMPETIÇÕES</h1><?php if (
    $notice
): ?><div class="alert alert-info"><?= e(
    $notice,
) ?></div><?php endif; ?><div class="row g-4">
<div class="col-xl-6"><form class="panel sorteio-form sorteio-pontos" method="post" onsubmit="return confirm('Sortear e gravar todos os jogos?')"><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="pontos"><h2>Pontos corridos</h2><p class="text-secondary">Todos contra todos, com rodadas organizadas automaticamente.</p><div class="alert alert-info small"><strong class="selected-count"></strong><br>Quantidade ímpar é permitida: com 7 participantes, cada rodada terá 3 jogos e 1 participante de folga. Em ida e volta, os confrontos se repetem com mando invertido.</div><div class="row g-2 mb-3"><?= checks(
    $teams,
) ?></div><label class="form-label">Formato</label><select class="form-select" name="formato"><option value="ida">Somente ida</option><option value="ida_volta">Ida e volta</option></select><button class="btn btn-danger mt-3">Iniciar sorteio</button></form></div>
<div class="col-xl-6"><form class="panel sorteio-form sorteio-mata" method="post" onsubmit="return confirm('Sortear e gravar o chaveamento?')"><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="mata"><h2>Mata-mata</h2><p class="text-secondary">Selecione exatamente 4, 8, 10 ou 16 participantes.</p><div class="alert alert-info small"><strong class="selected-count"></strong><br>Com 10 participantes, quatro disputam duas vagas na Preliminar e os outros seis avançam diretamente às Quartas. A Final e o 3º Lugar podem ter formatos diferentes das fases anteriores.</div><div class="row g-2 mb-3"><?= checks(
    $teams,
) ?></div><div class="row g-2"><div class="col-12"><label class="form-label">Formato das fases anteriores</label><select class="form-select" name="formato"><option value="unico">Jogo único</option><option value="ida_volta">Ida e volta</option></select></div><div class="col-md-6"><label class="form-label">Formato da Final</label><select class="form-select" name="formato_final"><option value="unico">Jogo único</option><option value="ida_volta">Ida e volta</option></select></div><div class="col-md-6"><label class="form-label">Formato do 3º Lugar</label><select class="form-select" name="formato_terceiro"><option value="unico">Jogo único</option><option value="ida_volta">Ida e volta</option></select></div></div><button class="btn btn-danger mt-3">Iniciar sorteio</button></form></div>
</div></div></main><script>const competitionModels=<?= json_encode($competitionModels,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) ?>;const roman=n=>{const map=[[1000,'M'],[900,'CM'],[500,'D'],[400,'CD'],[100,'C'],[90,'XC'],[50,'L'],[40,'XL'],[10,'X'],[9,'IX'],[5,'V'],[4,'IV'],[1,'I']];let out='';for(const [value,symbol] of map)while(n>=value){out+=symbol;n-=value}return out};document.querySelectorAll('.sorteio-form').forEach(form=>{const title=form.querySelector('h2');title.insertAdjacentHTML('afterend',`<label class="form-label mt-2">Modelo da competição</label><select class="form-select mb-2 competition-model" name="identidade_id"><option value="">Criar uma nova competição</option>${competitionModels.map(item=>`<option value="${item.id}">${item.nome}: próxima edição ${item.proxima_edicao}</option>`).join('')}</select><label class="form-label">Nome desta edição</label><input class="form-control mb-3" name="nome_campeonato" maxlength="150" placeholder="Ex.: Copa Vascão S3" required>`);const model=form.querySelector('.competition-model');model.addEventListener('change',()=>{const item=competitionModels.find(entry=>String(entry.id)===model.value);if(!item)return;form.nome_campeonato.value=item.nome+(item.proxima_edicao>1?' '+roman(item.proxima_edicao):'')});const update=()=>{const total=form.querySelectorAll('input[name="participantes[]"]:checked').length;form.querySelector('.selected-count').textContent=`${total} participante${total===1?'':'s'} selecionado${total===1?'':'s'}.`;};form.addEventListener('change',update);update();form.addEventListener('submit',async event=>{if(event.defaultPrevented)return;event.preventDefault();const button=event.submitter||form.querySelector('button');const old=button.textContent;button.disabled=true;button.textContent='Sorteando...';try{const payload=new FormData(form);payload.set('_ajax','1');const response=await fetch('sorteador.php',{method:'POST',body:payload,headers:{'Accept':'application/json'},credentials:'same-origin'});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'Não foi possível sortear.');form.nome_campeonato.value='';alert(data.message);}catch(error){alert(error.message);}finally{button.disabled=false;button.textContent=old;}});});</script></body></html>
