<?php
// Carrega a conexão, a sessão e protege o painel administrativo.
require __DIR__ . "/../includes/bootstrap.php";
require __DIR__ . "/../includes/sync.php";
admin_required();
$pdo = db();
$notice = $_SESSION["notice"] ?? "";
unset($_SESSION["notice"]);
function is_ajax_request(): bool
{
    return isset($_POST["_ajax"]) ||
        strtolower((string) ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "")) ===
            "xmlhttprequest";
}
function redirect_notice(string $message, string $tab = ""): never
{
    if (is_ajax_request()) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(
            ["ok" => true, "message" => $message, "tab" => $tab],
            JSON_UNESCAPED_UNICODE,
        );
        exit();
    }
    $_SESSION["notice"] = $message;
    header(
        "Location: index.php" . ($tab !== "" ? "?tab=" . urlencode($tab) : ""),
    );
    exit();
}
// Impede finalizar uma rodada futura enquanto ainda existem jogos pendentes nas anteriores.
function require_previous_rounds(
    PDO $pdo,
    int $championshipId,
    int $round,
): void {
    if ($round <= 1) {
        return;
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM partidas WHERE campeonato_id=? AND ativo=1 AND rodada<? AND status NOT IN ('finalizada','wo')",
    );
    $stmt->execute([$championshipId, $round]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new RuntimeException(
            "Finalize todos os jogos das rodadas anteriores primeiro.",
        );
    }
}

// Substitui os gols detalhados da partida e aplica somente a diferença na artilharia.
function sync_match_goals(
    PDO $pdo,
    int $matchId,
    int $championshipId,
    int $homeId,
    int $awayId,
    ?int $homeGoals,
    ?int $awayGoals,
    array $post,
): void {
    $teams = $post["gol_time"] ?? [];
    $players = $post["gol_jogador"] ?? [];
    $minutes = $post["gol_minuto"] ?? [];
    $types = $post["gol_tipo"] ?? [];
    $expected = ($homeGoals ?? 0) + ($awayGoals ?? 0);
    if (count($players) !== $expected) {
        throw new RuntimeException(
            "Cadastre exatamente um registro para cada gol do placar.",
        );
    }
    $oldStmt = $pdo->prepare(
        "SELECT participante_id,jogador,tipo FROM gols_partida WHERE partida_id=?",
    );
    $oldStmt->execute([$matchId]);
    $old = $oldStmt->fetchAll();
    $new = [];
    $homeCount = 0;
    $awayCount = 0;
    foreach ($players as $index => $player) {
        $teamId = (int) ($teams[$index] ?? 0);
        $player = trim((string) $player);
        $minute = trim((string) ($minutes[$index] ?? ""));
        $type = (string) ($types[$index] ?? "normal");
        if (!in_array($teamId, [$homeId, $awayId], true)) {
            throw new RuntimeException(
                "Um dos gols está associado a um time inválido.",
            );
        }
        if ($player === "" || $minute === "") {
            throw new RuntimeException(
                "Informe o jogador e o tempo de todos os gols.",
            );
        }
        if (
            !in_array(
                $type,
                ["normal", "penalti", "falta", "olimpico", "contra"],
                true,
            )
        ) {
            throw new RuntimeException("Tipo de gol inválido.");
        }
        $teamId === $homeId ? $homeCount++ : $awayCount++;
        $new[] = [
            "participante_id" => $teamId,
            "jogador" => $player,
            "minuto" => $minute,
            "tipo" => $type,
        ];
    }
    if ($homeCount !== ($homeGoals ?? 0) || $awayCount !== ($awayGoals ?? 0)) {
        throw new RuntimeException(
            "A quantidade de gols de cada time precisa ser igual ao placar informado.",
        );
    }
    $deltas = [];
    foreach ($old as $goal) {
        if ($goal["tipo"] !== "contra") {
            $key =
                $goal["participante_id"] .
                "|" .
                mb_strtolower($goal["jogador"]);
            $deltas[$key] = [
                "participante_id" => (int) $goal["participante_id"],
                "jogador" => $goal["jogador"],
                "delta" => ($deltas[$key]["delta"] ?? 0) - 1,
            ];
        }
    }
    foreach ($new as $goal) {
        if ($goal["tipo"] !== "contra") {
            $key =
                $goal["participante_id"] .
                "|" .
                mb_strtolower($goal["jogador"]);
            $deltas[$key] = [
                "participante_id" => $goal["participante_id"],
                "jogador" => $goal["jogador"],
                "delta" => ($deltas[$key]["delta"] ?? 0) + 1,
            ];
        }
    }
    $pdo->prepare("DELETE FROM gols_partida WHERE partida_id=?")->execute([
        $matchId,
    ]);
    $insertGoal = $pdo->prepare(
        "INSERT INTO gols_partida(partida_id,participante_id,jogador,minuto,tipo) VALUES(?,?,?,?,?)",
    );
    foreach ($new as $goal) {
        $insertGoal->execute([
            $matchId,
            $goal["participante_id"],
            $goal["jogador"],
            $goal["minuto"],
            $goal["tipo"],
        ]);
    }
    foreach ($deltas as $item) {
        if ($item["delta"] === 0) {
            continue;
        }
        if ($item["delta"] > 0) {
            $stmt = $pdo->prepare(
                "INSERT INTO artilharia(campeonato_id,jogador,participante_id,gols) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE gols=gols+VALUES(gols)",
            );
            $stmt->execute([
                $championshipId,
                $item["jogador"],
                $item["participante_id"],
                $item["delta"],
            ]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE artilharia SET gols=GREATEST(0,gols+?) WHERE campeonato_id=? AND jogador=? AND participante_id=?",
            );
            $stmt->execute([
                $item["delta"],
                $championshipId,
                $item["jogador"],
                $item["participante_id"],
            ]);
        }
    }
}

// Substitui os gols detalhados de um jogo do mata-mata sem recalcular registros antigos.
function sync_knockout_goals(
    PDO $pdo,
    int $matchId,
    int $championshipId,
    int $teamAId,
    int $teamBId,
    ?int $goalsA,
    ?int $goalsB,
    array $post,
): void {
    $teams = $post["mata_gol_time"] ?? [];
    $players = $post["mata_gol_jogador"] ?? [];
    $minutes = $post["mata_gol_minuto"] ?? [];
    $types = $post["mata_gol_tipo"] ?? [];
    $expected = ($goalsA ?? 0) + ($goalsB ?? 0);
    if (count($players) !== $expected) {
        throw new RuntimeException(
            "Cadastre exatamente um registro para cada gol do mata-mata.",
        );
    }
    $oldStmt = $pdo->prepare(
        "SELECT participante_id,jogador,tipo FROM gols_mata_mata WHERE jogo_mata_mata_id=?",
    );
    $oldStmt->execute([$matchId]);
    $old = $oldStmt->fetchAll();
    $new = [];
    $countA = 0;
    $countB = 0;
    foreach ($players as $index => $player) {
        $teamId = (int) ($teams[$index] ?? 0);
        $player = trim((string) $player);
        $minute = trim((string) ($minutes[$index] ?? ""));
        $type = (string) ($types[$index] ?? "normal");
        if (!in_array($teamId, [$teamAId, $teamBId], true)) {
            throw new RuntimeException(
                "Um dos gols do mata-mata está associado a um time inválido.",
            );
        }
        if ($player === "" || $minute === "") {
            throw new RuntimeException(
                "Informe o jogador e o tempo de todos os gols do mata-mata.",
            );
        }
        if (
            !in_array(
                $type,
                ["normal", "penalti", "falta", "olimpico", "contra"],
                true,
            )
        ) {
            throw new RuntimeException("Tipo de gol inválido.");
        }
        $teamId === $teamAId ? $countA++ : $countB++;
        $new[] = [
            "participante_id" => $teamId,
            "jogador" => $player,
            "minuto" => $minute,
            "tipo" => $type,
        ];
    }
    if ($countA !== ($goalsA ?? 0) || $countB !== ($goalsB ?? 0)) {
        throw new RuntimeException(
            "Os gols detalhados do mata-mata precisam corresponder ao placar.",
        );
    }
    $deltas = [];
    foreach ($old as $goal) {
        if ($goal["tipo"] !== "contra") {
            $key =
                $goal["participante_id"] .
                "|" .
                mb_strtolower($goal["jogador"]);
            $deltas[$key] = [
                "participante_id" => (int) $goal["participante_id"],
                "jogador" => $goal["jogador"],
                "delta" => ($deltas[$key]["delta"] ?? 0) - 1,
            ];
        }
    }
    foreach ($new as $goal) {
        if ($goal["tipo"] !== "contra") {
            $key =
                $goal["participante_id"] .
                "|" .
                mb_strtolower($goal["jogador"]);
            $deltas[$key] = [
                "participante_id" => $goal["participante_id"],
                "jogador" => $goal["jogador"],
                "delta" => ($deltas[$key]["delta"] ?? 0) + 1,
            ];
        }
    }
    $pdo->prepare(
        "DELETE FROM gols_mata_mata WHERE jogo_mata_mata_id=?",
    )->execute([$matchId]);
    $insert = $pdo->prepare(
        "INSERT INTO gols_mata_mata(jogo_mata_mata_id,participante_id,jogador,minuto,tipo) VALUES(?,?,?,?,?)",
    );
    foreach ($new as $goal) {
        $insert->execute([
            $matchId,
            $goal["participante_id"],
            $goal["jogador"],
            $goal["minuto"],
            $goal["tipo"],
        ]);
    }
    foreach ($deltas as $item) {
        if ($item["delta"] === 0) {
            continue;
        }
        if ($item["delta"] > 0) {
            $stmt = $pdo->prepare(
                "INSERT INTO artilharia(campeonato_id,jogador,participante_id,gols) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE gols=gols+VALUES(gols)",
            );
            $stmt->execute([
                $championshipId,
                $item["jogador"],
                $item["participante_id"],
                $item["delta"],
            ]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE artilharia SET gols=GREATEST(0,gols+?) WHERE campeonato_id=? AND jogador=? AND participante_id=?",
            );
            $stmt->execute([
                $item["delta"],
                $championshipId,
                $item["jogador"],
                $item["participante_id"],
            ]);
        }
    }
}
// Soma os jogos da chave e envia automaticamente o classificado para a próxima fase.
function advance_knockout(
    PDO $pdo,
    int $championshipId,
    string $phase,
    int $order,
): void {
    $stmt = $pdo->prepare(
        "SELECT id,time_a_id,time_b_id,gols_a,gols_b,penaltis_a,penaltis_b,status FROM jogos_mata_mata WHERE campeonato_id=? AND fase=? AND ordem=? AND ativo=1 ORDER BY jogo,id",
    );
    $stmt->execute([$championshipId, $phase, $order]);
    $matches = $stmt->fetchAll();
    if (!$matches) {
        return;
    }
    $scores = [];
    $penalties = [];
    $allFinished = true;
    foreach ($matches as $match) {
        if ($match["time_a_id"] === null || $match["time_b_id"] === null) {
            return;
        }
        if (
            $match["status"] !== "finalizado" ||
            $match["gols_a"] === null ||
            $match["gols_b"] === null
        ) {
            $allFinished = false;
            continue;
        }
        $a = (int) $match["time_a_id"];
        $b = (int) $match["time_b_id"];
        $scores[$a] = ($scores[$a] ?? 0) + (int) $match["gols_a"];
        $scores[$b] = ($scores[$b] ?? 0) + (int) $match["gols_b"];
        if ($match["penaltis_a"] !== null && $match["penaltis_b"] !== null) {
            $penalties[$a] = (int) $match["penaltis_a"];
            $penalties[$b] = (int) $match["penaltis_b"];
        }
    }
    // Uma disputa de pênaltis registrada encerra imediatamente o confronto.
    if (count($penalties) < 2 && !$allFinished) {
        return;
    }
    arsort($scores);
    $ids = array_keys($scores);
    $winner = null;
    if (count($penalties) >= 2) {
        $penaltyIds = array_keys($penalties);
        $winner =
            $penalties[$penaltyIds[0]] > $penalties[$penaltyIds[1]]
                ? (int) $penaltyIds[0]
                : (int) $penaltyIds[1];
    } elseif (count($ids) >= 2 && $scores[$ids[0]] > $scores[$ids[1]]) {
        $winner = (int) $ids[0];
    }
    if ($winner === null) {
        $pdo->prepare(
            "UPDATE jogos_mata_mata SET vencedor_id=NULL WHERE campeonato_id=? AND fase=? AND ordem=? AND ativo=1",
        )->execute([$championshipId, $phase, $order]);
        return;
    }
    $loser = null;
    foreach ($scores as $teamId => $score) {
        if ((int) $teamId !== $winner) {
            $loser = (int) $teamId;
            break;
        }
    }
    $pdo->prepare(
        "UPDATE jogos_mata_mata SET vencedor_id=? WHERE campeonato_id=? AND fase=? AND ordem=? AND ativo=1",
    )->execute([$winner, $championshipId, $phase, $order]);
    $pdo->prepare(
        "UPDATE jogos_mata_mata SET time_a_id=IF(origem_a_tipo='perdedor',?,?) WHERE campeonato_id=? AND origem_a_fase=? AND origem_a_ordem=? AND ativo=1",
    )->execute([$loser, $winner, $championshipId, $phase, $order]);
    $pdo->prepare(
        "UPDATE jogos_mata_mata SET time_b_id=IF(origem_b_tipo='perdedor',?,?) WHERE campeonato_id=? AND origem_b_fase=? AND origem_b_ordem=? AND ativo=1",
    )->execute([$loser, $winner, $championshipId, $phase, $order]);
}

// Processa os formulários enviados pelas abas do painel.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    $action = $_POST["action"] ?? "";
    try {
        // O Editor da Competição pode alterar competições e administrar o jornal.
        $editorActions = [
            "partida",
            "desativar_partida",
            "mata_mata",
            "desativar_mata",
            "artilharia",
            "salvar_noticia",
            "desativar_noticia",
        ];
        if (account_is_editor() && !in_array($action, $editorActions, true)) {
            throw new RuntimeException(
                "Seu perfil não possui permissão para realizar esta ação.",
            );
        }
        // Publica ou atualiza uma notícia dentro da aba do painel.
        if ($action === "salvar_noticia") {
            $id = (int) ($_POST["noticia_id"] ?? 0);
            $title = trim((string) ($_POST["titulo"] ?? ""));
            $summary = trim((string) ($_POST["resumo"] ?? ""));
            $cover = (string) ($_POST["capa_base64"] ?? "");
            $content = sanitize_news_html((string) ($_POST["conteudo"] ?? ""));
            if (
                $title === "" ||
                $summary === "" ||
                trim(strip_tags($content)) === ""
            ) {
                throw new RuntimeException(
                    "Preencha título, resumo e conteúdo da matéria.",
                );
            }
            if (
                !preg_match("#^data:image/(?:jpeg|png|webp);base64,#i", $cover)
            ) {
                throw new RuntimeException(
                    "Selecione uma imagem de capa válida.",
                );
            }
            if (strlen($cover) > 1600000 || strlen($content) > 4500000) {
                throw new RuntimeException(
                    "As imagens deixaram a notícia muito pesada. Reduza os arquivos e tente novamente.",
                );
            }
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE noticias SET titulo=?,resumo=?,capa_base64=?,conteudo=?,atualizado_em=NOW() WHERE id=? AND ativo=1",
                );
                $stmt->execute([$title, $summary, $cover, $content, $id]);
                redirect_notice("Notícia atualizada.", "noticias");
            }
            $stmt = $pdo->prepare(
                "INSERT INTO noticias(titulo,resumo,capa_base64,conteudo,autor) VALUES(?,?,?,?,?)",
            );
            $stmt->execute([
                $title,
                $summary,
                $cover,
                $content,
                $_SESSION["conta_nome"] ?? "Administração",
            ]);
            redirect_notice("Notícia publicada.", "noticias");
        }
        // Soft delete: mantém a notícia no banco e apenas a oculta do site.
        if ($action === "desativar_noticia") {
            $stmt = $pdo->prepare(
                "UPDATE noticias SET ativo=0 WHERE id=? AND ativo=1",
            );
            $stmt->execute([(int) ($_POST["noticia_id"] ?? 0)]);
            redirect_notice("Notícia removida do jornal.", "noticias");
        }
        // Ativa ou desativa um participante sem remover partidas, títulos ou histórico.
        if ($action === "status_participante") {
            $participantId = (int) ($_POST["participante_id"] ?? 0);
            $novoStatus = (int) ($_POST["ativo"] ?? 0) === 1 ? 1 : 0;
            if ($participantId <= 0) {
                throw new RuntimeException("Participante inválido.");
            }
            $stmt = $pdo->prepare(
                "UPDATE participantes SET ativo=? WHERE id=?",
            );
            $stmt->execute([$novoStatus, $participantId]);
            redirect_notice(
                $novoStatus === 1
                    ? "Técnico e time reativados."
                    : "Técnico e time desativados e ocultados do site.",
                "times",
            );
        }
        // Cadastra o técnico e os dados do seu time.
        if ($action === "participante") {
            $participantId = (int) ($_POST["participante_id"] ?? 0);
            $shield = trim($_POST["escudo_url"] ?? "");
            if (
                $shield !== "" &&
                !preg_match(
                    "#^(?:https?://|data:image/(?:jpeg|png|webp);base64,)#i",
                    $shield,
                )
            ) {
                throw new RuntimeException("Envie um escudo válido.");
            }
            if (strlen($shield) > 800000) {
                throw new RuntimeException(
                    "O arquivo do escudo ficou muito grande.",
                );
            }
            if ($participantId > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE participantes SET nome=?,time_nome=?,sigla=?,escudo_url=?,descricao=? WHERE id=?",
                );
                $stmt->execute([
                    trim($_POST["nome"]),
                    trim($_POST["time_nome"]),
                    strtoupper(trim($_POST["sigla"])),
                    $shield,
                    trim($_POST["descricao"]),
                    $participantId,
                ]);
                redirect_notice("Técnico, time e escudo atualizados.", "times");
            }
            $stmt = $pdo->prepare(
                "INSERT INTO participantes(nome,time_nome,sigla,escudo_url,descricao) VALUES(?,?,?,?,?)",
            );
            $stmt->execute([
                trim($_POST["nome"]),
                trim($_POST["time_nome"]),
                strtoupper(trim($_POST["sigla"])),
                $shield,
                trim($_POST["descricao"]),
            ]);
            redirect_notice("Técnico e time cadastrados.", "times");
        }
        // Cadastra uma conta de acesso; somente administradores chegam a esta tela.
        if ($action === "conta") {
            $contaId = (int) ($_POST["conta_id"] ?? 0);
            $nome = trim((string) ($_POST["nome"] ?? ""));
            $email = mb_strtolower(trim((string) ($_POST["email"] ?? "")));
            $senha = (string) ($_POST["senha"] ?? "");
            $confirmacao = (string) ($_POST["confirmar_senha"] ?? "");
            $participanteId = (int) ($_POST["participante_id"] ?? 0);
            $ehAdmin = (int) ($_POST["nivel_acesso"] ?? 0);
            if (!in_array($ehAdmin, [0, 1, 2], true)) {
                throw new RuntimeException("Nível de acesso inválido.");
            }
            if ($nome === "") {
                throw new RuntimeException("Informe o nome do usuário.");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("Informe um e-mail válido.");
            }
            if ($participanteId > 0) {
                $linked = $pdo->prepare(
                    "SELECT id,nome FROM contas WHERE participante_id=? AND id<>? LIMIT 1",
                );
                $linked->execute([$participanteId, $contaId]);
                if ($other = $linked->fetch()) {
                    throw new RuntimeException(
                        "Este time já está associado à conta de " .
                            $other["nome"] .
                            ".",
                    );
                }
            }
            if ($contaId > 0) {
                if (
                    $contaId === (int) $_SESSION["conta_id"] &&
                    $ehAdmin !== 1
                ) {
                    throw new RuntimeException(
                        "Você não pode remover o próprio acesso de Admin Master.",
                    );
                }
                $duplicada = $pdo->prepare(
                    "SELECT id FROM contas WHERE email=? AND id<>? LIMIT 1",
                );
                $duplicada->execute([$email, $contaId]);
                if ($duplicada->fetch()) {
                    throw new RuntimeException(
                        "Já existe outra conta com este e-mail.",
                    );
                }
                if ($senha !== "" || $confirmacao !== "") {
                    if (strlen($senha) < 8) {
                        throw new RuntimeException(
                            "A senha temporária precisa ter pelo menos 8 caracteres.",
                        );
                    }
                    if ($senha !== $confirmacao) {
                        throw new RuntimeException(
                            "A confirmação da senha não confere.",
                        );
                    }
                    $stmt = $pdo->prepare(
                        "UPDATE contas SET participante_id=?,nome=?,email=?,eh_admin=?,senha_hash=?,trocar_senha=1 WHERE id=?",
                    );
                    $stmt->execute([
                        $participanteId > 0 ? $participanteId : null,
                        $nome,
                        $email,
                        $ehAdmin,
                        password_hash($senha, PASSWORD_DEFAULT),
                        $contaId,
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        "UPDATE contas SET participante_id=?,nome=?,email=?,eh_admin=? WHERE id=?",
                    );
                    $stmt->execute([
                        $participanteId > 0 ? $participanteId : null,
                        $nome,
                        $email,
                        $ehAdmin,
                        $contaId,
                    ]);
                }
                redirect_notice("Dados do usuário atualizados.", "usuarios");
            }
            if (strlen($senha) < 8) {
                throw new RuntimeException(
                    "A senha temporária precisa ter pelo menos 8 caracteres.",
                );
            }
            if ($senha !== $confirmacao) {
                throw new RuntimeException(
                    "A confirmação da senha não confere.",
                );
            }
            $stmt = $pdo->prepare(
                "INSERT INTO contas(participante_id,nome,email,senha_hash,eh_admin,trocar_senha) VALUES(?,?,?,?,?,1)",
            );
            $stmt->execute([
                $participanteId > 0 ? $participanteId : null,
                $nome,
                $email,
                password_hash($senha, PASSWORD_DEFAULT),
                $ehAdmin,
            ]);
            redirect_notice(
                "Usuário cadastrado. Ele deverá criar uma senha própria no primeiro acesso.",
                "usuarios",
            );
        }
        // Confirma uma sugestão de vínculo entre uma conta já criada e um participante.
        if ($action === "vincular_conta") {
            $contaId = (int) ($_POST["conta_id"] ?? 0);
            $participanteId = (int) ($_POST["participante_id"] ?? 0);
            if ($contaId < 1 || $participanteId < 1) {
                throw new RuntimeException("Conta ou time inválido.");
            }
            $linked = $pdo->prepare(
                "SELECT id FROM contas WHERE participante_id=? AND id<>? LIMIT 1",
            );
            $linked->execute([$participanteId, $contaId]);
            if ($linked->fetch()) {
                throw new RuntimeException(
                    "Este time já está associado a outra conta.",
                );
            }
            $stmt = $pdo->prepare(
                "UPDATE contas SET participante_id=? WHERE id=? AND ativo=1",
            );
            $stmt->execute([$participanteId, $contaId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    "Não foi possível associar esta conta.",
                );
            }
            redirect_notice("Conta associada ao time.", "usuarios");
        }
        // Ativa ou desativa uma conta sem remover seu histórico.
        if ($action === "status_conta") {
            $contaId = (int) ($_POST["conta_id"] ?? 0);
            $novoStatus = (int) ($_POST["ativo"] ?? 0) === 1 ? 1 : 0;
            if ($contaId === (int) $_SESSION["conta_id"] && $novoStatus === 0) {
                throw new RuntimeException(
                    "Você não pode desativar a própria conta.",
                );
            }
            $stmt = $pdo->prepare("UPDATE contas SET ativo=? WHERE id=?");
            $stmt->execute([$novoStatus, $contaId]);
            redirect_notice(
                $novoStatus === 1
                    ? "Usuário reativado."
                    : "Usuário desativado.",
                "usuarios",
            );
        }
        // Finaliza ou reabre um campeonato diretamente pelo painel principal.
        if ($action === "status_campeonato") {
            $campeonatoId = (int) ($_POST["campeonato_id"] ?? 0);
            $status = $_POST["status"] ?? "";
            if (!in_array($status, ["ativo", "finalizado"], true)) {
                throw new RuntimeException("Status de campeonato inválido.");
            }
            $stmt = $pdo->prepare(
                "UPDATE campeonatos SET status=? WHERE id=? AND ativo=1",
            );
            $stmt->execute([$status, $campeonatoId]);
            redirect_notice(
                $status === "finalizado"
                    ? "Campeonato finalizado."
                    : "Campeonato reaberto.",
                "campeonatos",
            );
        }
        // Atualiza uma partida sorteada ou cria uma partida manual.
        if ($action === "partida") {
            $partidaId = (int) ($_POST["partida_id"] ?? 0);
            $homeGoals =
                $_POST["gols_mandante"] === ""
                    ? null
                    : (int) $_POST["gols_mandante"];
            $awayGoals =
                $_POST["gols_visitante"] === ""
                    ? null
                    : (int) $_POST["gols_visitante"];
            if (($homeGoals === null) !== ($awayGoals === null)) {
                throw new RuntimeException("Informe os dois lados do placar.");
            }
            if (
                ($_POST["status"] ?? "") === "finalizada" &&
                $homeGoals === null
            ) {
                throw new RuntimeException(
                    "Informe o placar para finalizar a partida.",
                );
            }
            if ($partidaId > 0) {
                if (
                    in_array($_POST["status"] ?? "", ["finalizada", "wo"], true)
                ) {
                    $roundStmt = $pdo->prepare(
                        "SELECT campeonato_id,rodada FROM partidas WHERE id=? AND ativo=1",
                    );
                    $roundStmt->execute([$partidaId]);
                    $roundGame = $roundStmt->fetch();
                    require_previous_rounds(
                        $pdo,
                        (int) $roundGame["campeonato_id"],
                        (int) $roundGame["rodada"],
                    );
                }
                // UPDATE altera o jogo escolhido sem criar outro registro.
                $matchStmt = $pdo->prepare(
                    "SELECT campeonato_id,mandante_id,visitante_id FROM partidas WHERE id=? AND ativo=1",
                );
                $matchStmt->execute([$partidaId]);
                $match = $matchStmt->fetch();
                if (!$match) {
                    throw new RuntimeException("Partida não encontrada.");
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    "UPDATE partidas SET gols_mandante=?,gols_visitante=?,data_partida=?,status=?,comprovacao_url=? WHERE id=? AND ativo=1",
                );
                $stmt->execute([
                    $homeGoals,
                    $awayGoals,
                    $_POST["data_partida"] ?: null,
                    $_POST["status"],
                    trim($_POST["comprovacao_url"]),
                    $partidaId,
                ]);
                sync_match_goals(
                    $pdo,
                    $partidaId,
                    (int) $match["campeonato_id"],
                    (int) $match["mandante_id"],
                    (int) $match["visitante_id"],
                    $_POST["status"] === "wo" ? null : $homeGoals,
                    $_POST["status"] === "wo" ? null : $awayGoals,
                    $_POST,
                );
                $pdo->commit();
                redirect_notice(
                    "Resultado da partida atualizado e classificação recalculada.",
                );
            }
            // INSERT continua disponível para cadastrar um jogo manualmente.
            if ($_POST["mandante_id"] === $_POST["visitante_id"]) {
                throw new RuntimeException("Escolha participantes diferentes.");
            }
            $championshipId = (int) ($_POST["campeonato_id"] ?? 0);
            if ($championshipId < 1) {
                throw new RuntimeException("Selecione o campeonato.");
            }
            if (in_array($_POST["status"] ?? "", ["finalizada", "wo"], true)) {
                require_previous_rounds(
                    $pdo,
                    $championshipId,
                    (int) $_POST["rodada"],
                );
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "INSERT INTO partidas(campeonato_id,rodada,mandante_id,visitante_id,gols_mandante,gols_visitante,data_partida,status,comprovacao_url) VALUES(?,?,?,?,?,?,?,?,?)",
            );
            $stmt->execute([
                $championshipId,
                (int) $_POST["rodada"],
                (int) $_POST["mandante_id"],
                (int) $_POST["visitante_id"],
                $homeGoals,
                $awayGoals,
                $_POST["data_partida"] ?: null,
                $_POST["status"],
                trim($_POST["comprovacao_url"]),
            ]);
            $partidaId = (int) $pdo->lastInsertId();
            sync_match_goals(
                $pdo,
                $partidaId,
                $championshipId,
                (int) $_POST["mandante_id"],
                (int) $_POST["visitante_id"],
                $_POST["status"] === "wo" ? null : $homeGoals,
                $_POST["status"] === "wo" ? null : $awayGoals,
                $_POST,
            );
            $pdo->commit();
            redirect_notice("Partida manual cadastrada.");
        }
        // Oculta uma partida dos pontos corridos sem apagar seu histórico do banco.
        if ($action === "desativar_partida") {
            $partidaId = (int) ($_POST["partida_id"] ?? 0);
            $stmt = $pdo->prepare(
                "SELECT campeonato_id,mandante_id,visitante_id FROM partidas WHERE id=? AND ativo=1",
            );
            $stmt->execute([$partidaId]);
            $match = $stmt->fetch();
            if (!$match) {
                throw new RuntimeException("Partida não encontrada.");
            }
            $pdo->beginTransaction();
            sync_match_goals(
                $pdo,
                $partidaId,
                (int) $match["campeonato_id"],
                (int) $match["mandante_id"],
                (int) $match["visitante_id"],
                null,
                null,
                [],
            );
            $pdo->prepare(
                "UPDATE partidas SET ativo=0 WHERE id=? AND ativo=1",
            )->execute([$partidaId]);
            $pdo->commit();
            redirect_notice("Partida apagada do site e da classificação.");
        }
        // Atualiza um confronto sorteado ou cria um confronto manual.
        if ($action === "mata_mata") {
            $jogoId = (int) ($_POST["jogo_mata_id"] ?? 0);
            $statusMata = $_POST["status"] ?? "";
            if (!in_array($statusMata, ["agendado", "finalizado"], true)) {
                throw new RuntimeException(
                    "Selecione um status válido para o confronto.",
                );
            }
            $goalsA = $_POST["gols_a"] === "" ? null : (int) $_POST["gols_a"];
            $goalsB = $_POST["gols_b"] === "" ? null : (int) $_POST["gols_b"];
            $penaltiesA =
                ($_POST["penaltis_a"] ?? "") === ""
                    ? null
                    : (int) $_POST["penaltis_a"];
            $penaltiesB =
                ($_POST["penaltis_b"] ?? "") === ""
                    ? null
                    : (int) $_POST["penaltis_b"];
            if (($penaltiesA === null) !== ($penaltiesB === null)) {
                throw new RuntimeException(
                    "Informe os dois placares dos pênaltis.",
                );
            }
            if ($penaltiesA !== null && $penaltiesA === $penaltiesB) {
                throw new RuntimeException(
                    "A disputa de pênaltis precisa ter um vencedor.",
                );
            }
            if ($goalsA !== null && $goalsB !== null) {
                $statusMata = "finalizado";
            } elseif ($statusMata === "finalizado") {
                throw new RuntimeException(
                    "Informe os dois placares para finalizar o confronto.",
                );
            }
            $winner = $_POST["vencedor_id"] ?: null;
            if ($jogoId > 0) {
                // Confirma o vencedor diretamente pelos times e pelo placar do confronto salvo.
                if (
                    $goalsA !== null &&
                    $goalsB !== null &&
                    $goalsA !== $goalsB
                ) {
                    $teamsStmt = $pdo->prepare(
                        "SELECT time_a_id,time_b_id FROM jogos_mata_mata WHERE id=? AND ativo=1",
                    );
                    $teamsStmt->execute([$jogoId]);
                    $matchTeams = $teamsStmt->fetch();
                    if (!$matchTeams) {
                        throw new RuntimeException("Confronto não encontrado.");
                    }
                    $winner =
                        $goalsA > $goalsB
                            ? $matchTeams["time_a_id"]
                            : $matchTeams["time_b_id"];
                }
                // UPDATE grava o resultado no confronto que já existe no chaveamento.
                $tieStmt = $pdo->prepare(
                    "SELECT campeonato_id,fase,ordem,time_a_id,time_b_id FROM jogos_mata_mata WHERE id=? AND ativo=1",
                );
                $tieStmt->execute([$jogoId]);
                $tie = $tieStmt->fetch();
                if (!$tie) {
                    throw new RuntimeException("Confronto não encontrado.");
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    "UPDATE jogos_mata_mata SET gols_a=?,gols_b=?,penaltis_a=?,penaltis_b=?,vencedor_id=?,status=? WHERE id=? AND ativo=1",
                );
                $stmt->execute([
                    $goalsA,
                    $goalsB,
                    $penaltiesA,
                    $penaltiesB,
                    $winner,
                    $statusMata,
                    $jogoId,
                ]);
                sync_knockout_goals(
                    $pdo,
                    $jogoId,
                    (int) $tie["campeonato_id"],
                    (int) $tie["time_a_id"],
                    (int) $tie["time_b_id"],
                    $goalsA,
                    $goalsB,
                    $_POST,
                );
                advance_knockout(
                    $pdo,
                    (int) $tie["campeonato_id"],
                    $tie["fase"],
                    (int) $tie["ordem"],
                );
                $pdo->commit();
                redirect_notice("Resultado do mata-mata atualizado.");
            }
            // INSERT é usado somente quando nenhum confronto sorteado foi escolhido.
            if ($_POST["time_a_id"] === $_POST["time_b_id"]) {
                throw new RuntimeException("Escolha participantes diferentes.");
            }
            if ($goalsA !== null && $goalsB !== null && $goalsA !== $goalsB) {
                $winner =
                    $goalsA > $goalsB
                        ? (int) $_POST["time_a_id"]
                        : (int) $_POST["time_b_id"];
            }
            $championshipId = (int) ($_POST["campeonato_id"] ?? 0);
            if ($championshipId < 1) {
                throw new RuntimeException("Selecione o campeonato.");
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "INSERT INTO jogos_mata_mata(campeonato_id,fase,ordem,time_a_id,time_b_id,gols_a,gols_b,penaltis_a,penaltis_b,vencedor_id,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
            );
            $stmt->execute([
                $championshipId,
                $_POST["fase"],
                (int) $_POST["ordem"],
                (int) $_POST["time_a_id"],
                (int) $_POST["time_b_id"],
                $goalsA,
                $goalsB,
                $penaltiesA,
                $penaltiesB,
                $winner,
                $statusMata,
            ]);
            $jogoId = (int) $pdo->lastInsertId();
            sync_knockout_goals(
                $pdo,
                $jogoId,
                $championshipId,
                (int) $_POST["time_a_id"],
                (int) $_POST["time_b_id"],
                $goalsA,
                $goalsB,
                $_POST,
            );
            $pdo->commit();
            redirect_notice("Confronto manual cadastrado.");
        }
        // Oculta um confronto do mata-mata sem remover definitivamente seu registro.
        if ($action === "desativar_mata") {
            $jogoId = (int) ($_POST["jogo_mata_id"] ?? 0);
            $stmt = $pdo->prepare(
                "SELECT campeonato_id,time_a_id,time_b_id FROM jogos_mata_mata WHERE id=? AND ativo=1",
            );
            $stmt->execute([$jogoId]);
            $match = $stmt->fetch();
            if (!$match) {
                throw new RuntimeException("Confronto não encontrado.");
            }
            $pdo->beginTransaction();
            sync_knockout_goals(
                $pdo,
                $jogoId,
                (int) $match["campeonato_id"],
                (int) $match["time_a_id"],
                (int) $match["time_b_id"],
                null,
                null,
                [],
            );
            $pdo->prepare(
                "UPDATE jogos_mata_mata SET ativo=0 WHERE id=? AND ativo=1",
            )->execute([$jogoId]);
            $pdo->commit();
            redirect_notice("Confronto apagado do chaveamento.");
        }
        // Insere ou atualiza um jogador na artilharia.
        if ($action === "artilharia") {
            $artilheiroId = (int) ($_POST["artilheiro_id"] ?? 0);
            $campeonatoId = (int) ($_POST["campeonato_id"] ?? 0);
            $jogador = trim((string) ($_POST["jogador"] ?? ""));
            if ($campeonatoId <= 0) {
                throw new RuntimeException("Selecione o campeonato.");
            }
            if ($jogador === "") {
                throw new RuntimeException("Informe o nome do jogador.");
            }
            if ($artilheiroId > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE artilharia SET campeonato_id=?,jogador=?,participante_id=?,gols=? WHERE id=?",
                );
                $stmt->execute([
                    $campeonatoId,
                    $jogador,
                    (int) $_POST["participante_id"],
                    (int) $_POST["gols"],
                    $artilheiroId,
                ]);
            } else {
                $existing = $pdo->prepare(
                    "SELECT id,participante_id,gols FROM artilharia WHERE campeonato_id=? AND jogador=? AND participante_id=? LIMIT 1",
                );
                $existing->execute([
                    $campeonatoId,
                    $jogador,
                    (int) $_POST["participante_id"],
                ]);
                $found = $existing->fetch();
                if ($found && empty($_POST["confirmar_existente"])) {
                    throw new RuntimeException(
                        "Este jogador já está cadastrado neste campeonato. Confirme a atualização no formulário.",
                    );
                }
                if ($found) {
                    $stmt = $pdo->prepare(
                        "UPDATE artilharia SET participante_id=?,gols=? WHERE id=?",
                    );
                    $stmt->execute([
                        (int) $_POST["participante_id"],
                        (int) $_POST["gols"],
                        (int) $found["id"],
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO artilharia(campeonato_id,jogador,participante_id,gols) VALUES(?,?,?,?)",
                    );
                    $stmt->execute([
                        $campeonatoId,
                        $jogador,
                        (int) $_POST["participante_id"],
                        (int) $_POST["gols"],
                    ]);
                }
            }
            redirect_notice("Artilharia do campeonato atualizada.", "extra");
        }
        // Registra um título de participante atual ou um título histórico.
        if ($action === "titulo") {
            $temporadas = ["Season 1", "Season 2", "Season 3"];
            if (!in_array($_POST["temporada"] ?? "", $temporadas, true)) {
                throw new RuntimeException("Selecione uma temporada válida.");
            }
            $origem = $_POST["origem_titulo"] ?? "atual";
            $participanteId = null;
            $tecnicoHistorico = null;
            $timeHistorico = null;
            if ($origem === "atual") {
                // Título atual continua vinculado ao cadastro da tabela participantes.
                $participanteId = (int) ($_POST["participante_id"] ?? 0);
                if ($participanteId <= 0) {
                    throw new RuntimeException(
                        "Selecione o participante atual.",
                    );
                }
            } elseif ($origem === "historico") {
                // Título antigo guarda os nomes porque o técnico não participa mais da Season atual.
                $tecnicoHistorico = trim($_POST["tecnico_historico"] ?? "");
                $timeHistorico = trim($_POST["time_historico"] ?? "");
                if ($tecnicoHistorico === "") {
                    throw new RuntimeException(
                        "Informe o nome do técnico histórico.",
                    );
                }
                $timeHistorico = $timeHistorico === "" ? null : $timeHistorico;
            } else {
                throw new RuntimeException(
                    "Selecione uma origem válida para o título.",
                );
            }
            $stmt = $pdo->prepare(
                "INSERT INTO titulos(participante_id,titulo,temporada,descricao,conquistado_em,tecnico_nome,time_nome) VALUES(?,?,?,?,?,?,?)",
            );
            $stmt->execute([
                $participanteId,
                trim($_POST["titulo"]),
                $_POST["temporada"],
                trim($_POST["descricao"]),
                $_POST["conquistado_em"] ?: null,
                $tecnicoHistorico,
                $timeHistorico,
            ]);
            redirect_notice("Título adicionado à história da competição.");
        }
        // Publica um vídeo na área de mídia.
        if ($action === "video") {
            $stmt = $pdo->prepare(
                "INSERT INTO videos(titulo,youtube_url) VALUES(?,?)",
            );
            $stmt->execute([
                trim($_POST["titulo"]),
                trim($_POST["youtube_url"]),
            ]);
            redirect_notice("Vídeo publicado.");
        }
        if ($action === "configuracoes") {
            $allowed = [
                "footer_nome",
                "footer_projeto",
                "discord_url",
                "youtube_url",
                "ordem_secoes",
            ];
            $stmt = $pdo->prepare(
                "INSERT INTO configuracoes_site(chave,valor) VALUES(?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)",
            );
            foreach ($allowed as $key) {
                $stmt->execute([$key, trim((string) ($_POST[$key] ?? ""))]);
            }
            redirect_notice(
                "Configurações públicas atualizadas.",
                "configuracoes",
            );
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_ajax_request()) {
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
// Reconcilia resultados antigos ao abrir o painel e preenche as próximas chaves.
foreach (
    $pdo
        ->query(
            "SELECT DISTINCT campeonato_id,fase,ordem FROM jogos_mata_mata WHERE ativo=1",
        )
        ->fetchAll()
    as $tieToAdvance
) {
    advance_knockout(
        $pdo,
        (int) $tieToAdvance["campeonato_id"],
        $tieToAdvance["fase"],
        (int) $tieToAdvance["ordem"],
    );
}
// Busca os participantes ativos usados nos selects.
$teams = $pdo
    ->query(
        "SELECT id,nome,time_nome FROM participantes WHERE ativo=1 ORDER BY time_nome",
    )
    ->fetchAll();
$participantsAdmin = $pdo
    ->query(
        "SELECT id,nome,time_nome,sigla,escudo_url,descricao,ativo FROM participantes ORDER BY ativo DESC,time_nome,nome",
    )
    ->fetchAll();
$accounts = account_is_master()
    ? $pdo
        ->query(
            "SELECT c.id,c.participante_id,c.nome,c.email,c.eh_admin,c.ativo,c.ultimo_acesso_em,p.time_nome FROM contas c LEFT JOIN participantes p ON p.id=c.participante_id ORDER BY c.ativo DESC,c.nome",
        )
        ->fetchAll()
    : [];
if (account_is_master()) {
    foreach ($accounts as &$account) {
        $account["sugestao"] = null;
        if ($account["participante_id"] === null) {
            foreach ($teams as $team) {
                if (
                    mb_strtolower(trim($team["nome"])) ===
                    mb_strtolower(trim($account["nome"]))
                ) {
                    $account["sugestao"] = [
                        "id" => (int) $team["id"],
                        "time_nome" => $team["time_nome"],
                        "tecnico" => $team["nome"],
                    ];
                    break;
                }
            }
        }
    }
    unset($account);
}
$championshipsAdmin = $pdo
    ->query(
        "SELECT c.*,(SELECT COUNT(*) FROM partidas p WHERE p.campeonato_id=c.id AND p.ativo=1)+(SELECT COUNT(*) FROM jogos_mata_mata j WHERE j.campeonato_id=c.id AND j.ativo=1) jogos FROM campeonatos c WHERE c.ativo=1 ORDER BY c.criado_em DESC,c.id DESC",
    )
    ->fetchAll();
$scorersAdmin = $pdo
    ->query(
        "SELECT a.id,a.campeonato_id,a.jogador,a.participante_id,a.gols,c.nome campeonato,p.nome tecnico,p.time_nome FROM artilharia a JOIN campeonatos c ON c.id=a.campeonato_id JOIN participantes p ON p.id=a.participante_id ORDER BY c.status='ativo' DESC,c.criado_em DESC,a.gols DESC,a.jogador",
    )
    ->fetchAll();
$videosAdmin = $pdo
    ->query(
        "SELECT id,titulo,youtube_url,ativo,criado_em FROM videos ORDER BY criado_em DESC,id DESC",
    )
    ->fetchAll();
$newsAdmin = $pdo
    ->query(
        "SELECT id,titulo,resumo,capa_base64,conteudo,autor,publicado_em FROM noticias WHERE ativo=1 ORDER BY publicado_em DESC,id DESC",
    )
    ->fetchAll();
$siteConfig = [
    "footer_nome" => "Vascão dos Gigantes • Season 3",
    "footer_projeto" => "Projeto independente para a comunidade DreamTeam",
    "discord_url" => "https://discord.gg/nkDynjHbMM",
    "youtube_url" => "https://www.youtube.com/@DreamBotSeason2",
    "ordem_secoes" =>
        "noticias,competicao,participantes,artilharia,titulos,midia",
];
try {
    foreach (
        $pdo->query("SELECT chave,valor FROM configuracoes_site")->fetchAll()
        as $row
    ) {
        $siteConfig[$row["chave"]] = $row["valor"];
    }
} catch (Throwable $ignored) {
}
$games = $pdo
    ->query(
        "SELECT p.id,p.rodada,p.mandante_id,p.visitante_id,m.time_nome mandante,v.time_nome visitante,p.gols_mandante,p.gols_visitante,p.status FROM partidas p JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id WHERE p.ativo=1 ORDER BY p.id DESC",
    )
    ->fetchAll();
// Busca os confrontos existentes para permitir editar os jogos sorteados.
$mataGames = $pdo
    ->query(
        "SELECT j.id,j.fase,j.ordem,j.jogo,j.time_a_id,j.time_b_id,a.time_nome time_a,b.time_nome time_b,j.gols_a,j.gols_b,j.penaltis_a,j.penaltis_b,j.status FROM jogos_mata_mata j JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id WHERE j.ativo=1 AND j.time_a_id IS NOT NULL AND j.time_b_id IS NOT NULL ORDER BY FIELD(j.fase,'Oitavas','Quartas','Semifinal','Terceiro lugar','Final'),j.ordem,j.jogo,j.id",
    )
    ->fetchAll();
// Monta as opções dos selects de técnicos e times.
function team_options(array $teams): string
{
    $out = "";
    foreach ($teams as $t) {
        $out .=
            '<option value="' .
            (int) $t["id"] .
            '">' .
            e($t["time_nome"]) .
            " — Técnico " .
            e($t["nome"]) .
            "</option>";
    }
    return $out;
}
// Monta a lista de partidas sorteadas que podem receber resultado.
function game_options(array $games): string
{
    $out = '<option value="">Cadastrar partida manual</option>';
    foreach ($games as $g) {
        $out .=
            '<option value="' .
            (int) $g["id"] .
            '">Rodada ' .
            (int) $g["rodada"] .
            " — " .
            e($g["mandante"]) .
            " x " .
            e($g["visitante"]) .
            "</option>";
    }
    return $out;
}
// Monta a lista de confrontos sorteados que podem ser atualizados.
function mata_options(array $games): string
{
    $out = '<option value="">Cadastrar confronto manual</option>';
    foreach ($games as $g) {
        $out .=
            '<option value="' .
            (int) $g["id"] .
            '">' .
            e($g["fase"]) .
            " " .
            $g["ordem"] .
            " — " .
            e($g["time_a"]) .
            " x " .
            e($g["time_b"]) .
            "</option>";
    }
    return $out;
}
?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Painel | Season 3</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="../assets/css/branding.css?v=5"><link rel="stylesheet" href="../assets/css/news.css?v=<?= filemtime(
    __DIR__ . "/../assets/css/news.css",
) ?>"><style>.admin-shell{padding:95px 0 60px}.form-control,.form-select{background:#0b0c0e;border-color:#343941}.admin-form{padding:1.25rem}.admin-form h2{font:800 1.5rem 'Barlow Condensed',sans-serif;text-transform:uppercase}.nav-pills .nav-link.active{background:#d71920}.editor-role #tab-campeonatos,.editor-role #tab-times,.editor-role #tab-titulos,.editor-role #tab-videos,.editor-role #tab-configuracoes,.editor-role #tab-usuarios,.editor-role #tab-extra .col-lg-5>form:nth-of-type(2){display:none!important}</style></head><body class="<?= account_is_editor()
    ? "editor-role"
    : "master-role" ?>">
<nav class="navbar fixed-top navbar-dark"><div class="container"><a class="navbar-brand" href="../index.php"><img class="brand-mark d-inline-block me-2" src="../assets/img/logo-season3.webp?v=5" alt="Vascao Season 3"> PAINEL S3</a><div><span class="text-secondary me-3 d-none d-md-inline">Olá, <?= e(
    $_SESSION["conta_nome"] ?? "",
) ?></span><a href="../logout.php" class="btn btn-outline-light btn-sm">Sair</a></div></div></nav>
<main class="admin-shell"><div class="container"><div class="d-flex justify-content-between align-items-end mb-4"><div><span class="eyebrow">Central de atualização</span><h1 class="display-4 fw-bold"><?= account_is_master()
    ? "ADMINISTRAÇÃO"
    : "EDITOR DA COMPETIÇÃO" ?></h1></div><div class="d-flex gap-2"><?php if (
    account_is_master()
): ?><a class="btn btn-danger" href="sorteador.php">Sorteador</a><?php endif; ?><a class="btn btn-outline-light" href="../index.php" target="_blank">Abrir site</a></div></div>
<?php if ($notice): ?><div class="alert alert-info"><?= e(
    $notice,
) ?></div><?php endif; ?>
<?php if (
    sync_user_allowed()
): ?><div id="database-sync-control" class="alert alert-warning d-none flex-wrap align-items-center justify-content-between gap-3" data-csrf="<?= e(
    csrf_token(),
) ?>"><div><strong>Homologação desatualizada</strong><div class="database-sync-status small">A produção possui dados mais recentes.</div></div><button type="button" class="btn btn-warning fw-bold">Sincronizar agora</button></div><?php endif; ?>
<ul class="nav nav-pills gap-2 mb-4"><li><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-jogos">Pontos corridos</button></li><li><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-mata">Mata-mata</button></li><li><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-sumula">Importar súmula</button></li><?php if (
    account_is_master()
): ?><li><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-campeonatos">Campeonatos</button></li><li><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-times">Técnicos e times</button></li><li><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-titulos">Títulos</button></li><?php endif; ?><li><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-extra">Artilharia</button></li><?php if (
    account_is_master()
): ?><li><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-videos">Vídeos</button></li><li><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-configuracoes">Configurações</button></li><li><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-usuarios">Usuários</button></li><?php endif; ?><li><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-noticias">Notícias</button></li></ul>
<div class="tab-content">
<section id="tab-sumula" class="tab-pane fade"><div class="row g-4"><div class="col-xl-6"><div class="panel admin-form"><span class="eyebrow">DreamTeam</span><h2 class="mt-2">Importar súmula do Discord</h2><p class="text-secondary">Cole a mensagem completa, incluindo a seção Lances da Partida. O sistema analisa tudo antes de salvar.</p><label class="form-label" for="dreamteam-summary-text">Texto completo da súmula</label><textarea id="dreamteam-summary-text" class="form-control" rows="18" placeholder="Cole aqui a súmula completa..."></textarea><input id="dreamteam-summary-csrf" type="hidden" value="<?= e(csrf_token()) ?>"><button id="dreamteam-analyze" type="button" class="btn btn-danger mt-3">Analisar súmula</button></div></div><div class="col-xl-6"><div id="dreamteam-preview" class="panel admin-form"><h2>Prévia da importação</h2><p class="text-secondary mb-0">Nenhum dado será salvo antes da sua confirmação.</p></div></div></div></section>
<section id="tab-jogos" class="tab-pane fade show active"><div class="row g-4"><div class="col-lg-5"><form id="form-partida" class="panel admin-form" method="post"><h2>Registrar ou editar partida</h2><input type="hidden" name="partida_id" value=""><div id="partida-edicao" class="alert alert-info d-none justify-content-between align-items-center"><span></span><button type="button" class="btn btn-sm btn-outline-info cancelar-edicao" data-form="form-partida">Cancelar edição</button></div><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="partida"><div class="row g-2"><div class="col-4"><label class="form-label">Rodada</label><input class="form-control" type="number" min="1" name="rodada" required></div><div class="col-8"><label class="form-label">Data</label><input class="form-control" type="datetime-local" name="data_partida"></div><div class="col-6"><label class="form-label">Mandante</label><select class="form-select" name="mandante_id" required><?= team_options(
    $teams,
) ?></select></div><div class="col-6"><label class="form-label">Visitante</label><select class="form-select" name="visitante_id" required><?= team_options(
    $teams,
) ?></select></div><div class="col-6"><label class="form-label">Gols mandante</label><input class="form-control" type="number" min="0" name="gols_mandante"></div><div class="col-6"><label class="form-label">Gols visitante</label><input class="form-control" type="number" min="0" name="gols_visitante"></div><div class="col-12"><div id="match-goals-editor" class="mt-2"></div></div><div class="col-12"><label class="form-label">Status</label><select class="form-select" name="status"><option value="agendada">Agendada</option><option value="finalizada">Finalizada</option><option value="wo">W.O.</option></select></div><div class="col-12"><label class="form-label">Link da comprovação</label><input class="form-control" type="url" name="comprovacao_url"></div></div><button class="btn btn-danger mt-3">Salvar partida</button></form></div><div class="col-lg-7"><div class="panel"><div class="panel-head"><h3>Últimos jogos</h3></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>R</th><th>Jogo</th><th>Placar</th><th>Status</th><th>Ação</th></tr></thead><tbody><?php foreach (
    $games
    as $g
): ?><tr><td><?= $g["rodada"] ?></td><td><?= e($g["mandante"]) ?> × <?= e(
     $g["visitante"],
 ) ?></td><td><?= e((string) $g["gols_mandante"]) ?> × <?= e(
     (string) $g["gols_visitante"],
 ) ?></td><td><?= e(
    $g["status"],
) ?></td><td><div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-light editar-partida" data-id="<?= $g[
    "id"
] ?>" data-rodada="<?= $g["rodada"] ?>" data-mandante="<?= e(
    $g["mandante"],
) ?>" data-visitante="<?= e($g["visitante"]) ?>" data-gols-mandante="<?= e(
    (string) $g["gols_mandante"],
) ?>" data-gols-visitante="<?= e(
    (string) $g["gols_visitante"],
) ?>" data-status="<?= e(
    $g["status"],
) ?>">Editar</button><form method="post" onsubmit="return confirm('Apagar esta partida dos pontos corridos?')"><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="desativar_partida"><input type="hidden" name="partida_id" value="<?= $g[
    "id"
] ?>"><button class="btn btn-sm btn-outline-danger">Apagar</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></div></div></div></section>
<section id="tab-mata" class="tab-pane fade"><form id="form-mata" class="panel admin-form" method="post"><h2>Registrar ou editar mata-mata</h2><input type="hidden" name="jogo_mata_id" value=""><div id="mata-edicao" class="alert alert-info d-none justify-content-between align-items-center"><span></span><button type="button" class="btn btn-sm btn-outline-info cancelar-edicao" data-form="form-mata">Cancelar edição</button></div><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="mata_mata"><div class="row g-2"><div class="col-md-3"><label class="form-label">Fase</label><select name="fase" class="form-select"><option>Oitavas</option><option>Quartas</option><option>Semifinal</option><option>Final</option></select></div><div class="col-md-2"><label class="form-label">Ordem</label><input class="form-control" type="number" name="ordem" value="1" min="1"></div><div class="col-md-3"><label class="form-label">Time A</label><select name="time_a_id" class="form-select"><?= team_options(
    $teams,
) ?></select></div><div class="col-md-3"><label class="form-label">Time B</label><select name="time_b_id" class="form-select"><?= team_options(
    $teams,
) ?></select></div><div class="col-md-2"><label class="form-label">Gols A</label><input class="form-control" type="number" min="0" name="gols_a"></div><div class="col-md-2"><label class="form-label">Gols B</label><input class="form-control" type="number" min="0" name="gols_b"></div><div class="col-md-4"><label class="form-label">Vencedor (opcional)</label><select name="vencedor_id" class="form-select"><option value="">Ainda não definido</option><?= team_options(
    $teams,
) ?></select></div><div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select"><option value="agendado">Agendado</option><option value="finalizado">Finalizado</option></select></div></div><button class="btn btn-danger mt-3">Salvar no chaveamento</button></form><div class="panel mt-4"><div class="panel-head"><h3>Partidas do mata-mata</h3></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Fase</th><th>Confronto</th><th>Placar</th><th>Status</th><th>Ação</th></tr></thead><tbody><?php foreach (
    $mataGames
    as $g
): ?><tr><td><?= e($g["fase"]) ?> <?= $g["ordem"] ?></td><td><?= e(
    $g["time_a"],
) ?> × <?= e($g["time_b"]) ?></td><td><?= e((string) $g["gols_a"]) ?> × <?= e(
     (string) $g["gols_b"],
 ) ?></td><td><?= e(
    $g["status"],
) ?></td><td><div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-light editar-mata" data-id="<?= $g[
    "id"
] ?>" data-fase="<?= e($g["fase"]) ?>" data-ordem="<?= $g[
    "ordem"
] ?>" data-time-a="<?= e($g["time_a"]) ?>" data-time-b="<?= e(
    $g["time_b"],
) ?>" data-gols-a="<?= e((string) $g["gols_a"]) ?>" data-gols-b="<?= e(
    (string) $g["gols_b"],
) ?>" data-status="<?= e(
    $g["status"],
) ?>">Editar</button><form method="post" onsubmit="return confirm('Apagar este confronto do mata-mata?')"><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="desativar_mata"><input type="hidden" name="jogo_mata_id" value="<?= $g[
    "id"
] ?>"><button class="btn btn-sm btn-outline-danger">Apagar</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></div></section>
<section id="tab-campeonatos" class="tab-pane fade"><div class="panel"><div class="panel-head"><h3>Campeonatos</h3><span><?= count(
    $championshipsAdmin,
) ?> cadastrados</span></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Nome</th><th>Modalidade</th><th>Formato</th><th>Jogos</th><th>Status</th><th>Ação</th></tr></thead><tbody><?php
 foreach ($championshipsAdmin as $championship): ?><tr><td><strong><?= e(
    $championship["nome"],
) ?></strong></td><td><?= $championship["tipo"] === "mata_mata"
    ? "Mata-mata"
    : "Pontos corridos" ?></td><td><?= e(
    ucfirst(str_replace("_", " e ", $championship["formato"])),
) ?></td><td><?= $championship["jogos"] ?></td><td><?= e(
    $championship["status"],
) ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="status_campeonato"><input type="hidden" name="campeonato_id" value="<?= $championship[
    "id"
] ?>"><input type="hidden" name="status" value="<?= $championship["status"] ===
"ativo"
    ? "finalizado"
    : "ativo" ?>"><button class="btn btn-sm <?= $championship["status"] ===
"ativo"
    ? "btn-outline-danger"
    : "btn-outline-light" ?>"><?= $championship["status"] === "ativo"
    ? "Finalizar"
    : "Reabrir" ?></button></form></td></tr><?php endforeach;
 if (
     !$championshipsAdmin
 ): ?><tr><td colspan="6" class="text-center text-secondary py-4">Nenhuma competição criada até o momento.</td></tr><?php endif;
 ?></tbody></table></div></div></section>
<section id="tab-times" class="tab-pane fade"><div class="row g-4"><div class="col-lg-6"><form id="form-participante" class="panel admin-form" method="post"><h2 id="participante-form-title">Novo técnico e time</h2><input type="hidden" name="participante_id" value=""><div id="participante-edicao" class="alert alert-info d-none justify-content-between align-items-center"><span></span><button type="button" class="btn btn-sm btn-outline-info cancelar-participante">Cancelar edição</button></div><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="participante"><div class="row g-2"><div class="col-md-6"><label class="form-label">Nome do técnico</label><input class="form-control" name="nome" required></div><div class="col-md-6"><label class="form-label">Nome do time</label><input class="form-control" name="time_nome" required></div><div class="col-md-4"><label class="form-label">Sigla do time</label><input class="form-control" name="sigla" maxlength="5" required></div><div class="col-md-8"><label class="form-label">Escudo do time (opcional)</label><input class="form-control" type="url" name="escudo_url"></div><div class="col-12"><label class="form-label">Descrição</label><textarea class="form-control" name="descricao" rows="2"></textarea></div></div><button id="participante-submit" class="btn btn-danger mt-3">Cadastrar técnico</button></form></div><div class="col-lg-6"><div class="panel"><div class="panel-head"><h3>Técnicos cadastrados</h3><span><?= count(
    $participantsAdmin,
) ?> ativos</span></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Escudo</th><th>Técnico / Time</th><th>Sigla</th><th>Ação</th></tr></thead><tbody><?php foreach (
     $participantsAdmin
     as $participant
 ): ?><tr><td><?php if ($participant["escudo_url"]): ?><img src="<?= e(
    $participant["escudo_url"],
) ?>" alt="" style="width:38px;height:38px;object-fit:contain"><?php else: ?><span class="team-badge"><?= e(
    $participant["sigla"],
) ?></span><?php endif; ?></td><td><strong><?= e(
    $participant["nome"],
) ?></strong><small class="d-block text-secondary"><?= e(
    $participant["time_nome"],
) ?></small></td><td><?= e(
    $participant["sigla"],
) ?></td><td><button type="button" class="btn btn-sm btn-outline-light editar-participante" data-id="<?= $participant[
    "id"
] ?>">Editar</button></td></tr><?php endforeach; ?></tbody></table></div></div></div></div><script id="participants-admin-data" type="application/json"><?= json_encode(
    $participantsAdmin,
    JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES,
) ?></script></section>
<section id="tab-titulos" class="tab-pane fade"><form class="panel admin-form" method="post"><h2>Adicionar título à história</h2><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="titulo"><div class="row g-2"><div class="col-md-4"><label class="form-label">Tipo de registro</label><select class="form-select" name="origem_titulo" id="origem_titulo"><option value="atual">Participante atual</option><option value="historico">Técnico histórico</option></select></div><div class="col-md-8 titulo-atual"><label class="form-label">Participante atual</label><select class="form-select" name="participante_id"><option value="">Selecione o técnico e o time</option><?= team_options(
    $teams,
) ?></select></div><div class="col-md-6 titulo-historico d-none"><label class="form-label">Nome do técnico histórico</label><input class="form-control" name="tecnico_historico" placeholder="Ex.: Técnico da Season 1"></div><div class="col-md-6 titulo-historico d-none"><label class="form-label">Nome do time histórico (opcional)</label><input class="form-control" name="time_historico" placeholder="Deixe vazio se usavam apenas o técnico"></div><div class="col-md-5"><label class="form-label">Título conquistado</label><input class="form-control" name="titulo" placeholder="Ex.: Campeão de pontos corridos" required></div><div class="col-md-3"><label class="form-label">Season</label><select class="form-select" name="temporada" required><option value="Season 1">Season 1</option><option value="Season 2">Season 2</option><option value="Season 3" selected>Season 3</option></select></div><div class="col-md-4"><label class="form-label">Data da conquista</label><input class="form-control" type="date" name="conquistado_em"></div><div class="col-12"><label class="form-label">Descrição (opcional)</label><input class="form-control" name="descricao"></div></div><button class="btn btn-danger mt-3">Adicionar título</button></form></section>
<section id="tab-extra" class="tab-pane fade"><div class="row g-4"><div class="col-lg-5"><form id="form-artilharia" class="panel admin-form" method="post"><h2>Registrar ou editar artilheiro</h2><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="artilharia"><input type="hidden" name="artilheiro_id" value=""><div id="artilheiro-edicao" class="alert alert-info d-none justify-content-between align-items-center"><span></span><button type="button" class="btn btn-sm btn-outline-info cancelar-artilheiro">Cancelar edição</button></div><label class="form-label">Campeonato</label><select id="artilheiro-campeonato" class="form-select mb-2" name="campeonato_id" required><option value="">Selecione</option><?php foreach (
    $championshipsAdmin
    as $championship
): ?><option value="<?= $championship["id"] ?>"><?= e(
    $championship["nome"],
) ?> — <?= $championship["status"] === "ativo"
     ? "Em andamento"
     : "Finalizado" ?></option><?php endforeach; ?></select><label class="form-label">Jogador</label><input class="form-control mb-2" name="jogador" required><label class="form-label">Time / técnico</label><select class="form-select mb-2" name="participante_id" required><?= team_options(
    $teams,
) ?></select><label class="form-label">Total de gols</label><input class="form-control" type="number" min="0" name="gols" required><button id="artilheiro-submit" class="btn btn-danger mt-3">Salvar artilheiro</button></form><form class="panel admin-form mt-4" method="post"><h2>Publicar vídeo</h2><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="video"><label class="form-label">Título</label><input class="form-control mb-2" name="titulo" required><label class="form-label">URL do YouTube</label><input class="form-control" type="url" name="youtube_url" required><button class="btn btn-danger mt-3">Publicar</button></form></div><div class="col-lg-7"><div class="panel"><div class="panel-head"><h3>Artilheiros cadastrados</h3><span id="artilheiros-admin-count"><?= count(
    $scorersAdmin,
) ?> registros</span></div><div class="p-3 border-bottom"><label class="form-label small">Filtrar por campeonato</label><select id="artilheiros-admin-filter" class="form-select"><option value="">Todos os campeonatos</option><?php foreach (
     $championshipsAdmin
     as $championship
 ): ?><option value="<?= $championship["id"] ?>"><?= e(
    $championship["nome"],
) ?></option><?php endforeach; ?></select></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>#</th><th>Jogador</th><th>Time</th><th>Gols</th><th>Ação</th></tr></thead><tbody id="artilheiros-admin-body"><?php foreach (
    $scorersAdmin
    as $scorer
): ?><tr data-campeonato="<?= $scorer[
    "campeonato_id"
] ?>"><td class="artilheiro-posicao"></td><td><strong><?= e(
    $scorer["jogador"],
) ?></strong><small class="d-block text-secondary"><?= e(
    $scorer["campeonato"],
) ?></small></td><td><?= e(
    $scorer["time_nome"],
) ?><small class="d-block text-secondary"><?= e(
    $scorer["tecnico"],
) ?></small></td><td><strong><?= $scorer[
    "gols"
] ?></strong></td><td><button type="button" class="btn btn-sm btn-outline-light editar-artilheiro" data-id="<?= $scorer[
    "id"
] ?>" data-campeonato="<?= $scorer["campeonato_id"] ?>" data-jogador="<?= e(
    $scorer["jogador"],
) ?>" data-participante="<?= $scorer[
    "participante_id"
] ?>" data-gols="<?= $scorer[
    "gols"
] ?>">Editar</button></td></tr><?php endforeach; ?></tbody></table></div><div id="artilheiros-admin-empty" class="text-center text-secondary py-4 d-none">Nenhum artilheiro neste campeonato.</div></div></div></div></section>
<section id="tab-usuarios" class="tab-pane fade"><div class="row g-4"><div class="col-lg-5"><form class="panel admin-form" method="post"><h2>Cadastrar usuário</h2><p class="text-secondary">O cadastro fica restrito à administração. Contas comuns não acessam o painel.</p><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="conta"><label class="form-label">Nome</label><input class="form-control mb-2" name="nome" maxlength="120" required><label class="form-label">E-mail</label><input class="form-control mb-2" type="email" name="email" maxlength="190" required><label class="form-label">Vincular ao participante (opcional)</label><select class="form-select mb-2" name="participante_id"><option value="">Nenhum participante</option><?= team_options(
    $teams,
) ?></select><div class="row g-2"><div class="col-md-6"><label class="form-label">Senha</label><input class="form-control" type="password" name="senha" minlength="8" autocomplete="new-password" required></div><div class="col-md-6"><label class="form-label">Confirmar senha</label><input class="form-control" type="password" name="confirmar_senha" minlength="8" autocomplete="new-password" required></div></div><div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="eh_admin" value="1" id="usuario-eh-admin"><label class="form-check-label" for="usuario-eh-admin">Este usuário é administrador</label></div><button class="btn btn-danger mt-3">Cadastrar usuário</button></form></div><div class="col-lg-7"><div class="panel"><div class="panel-head"><h3>Contas cadastradas</h3><span><?= count(
    $accounts,
) ?> contas</span></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Usuário</th><th>Vínculo</th><th>Acesso</th><th>Status</th><th>Ação</th></tr></thead><tbody><?php foreach (
     $accounts
     as $account
 ): ?><tr><td><strong><?= e(
    $account["nome"],
) ?></strong><small class="d-block text-secondary"><?= e(
    $account["email"],
) ?></small></td><td><?= e(
    $account["time_nome"] ?: "Sem time",
) ?></td><td><?= $account["eh_admin"]
    ? "Administrador"
    : "Usuário" ?></td><td><?= $account["ativo"]
    ? "Ativo"
    : "Inativo" ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="status_conta"><input type="hidden" name="conta_id" value="<?= $account[
    "id"
] ?>"><input type="hidden" name="ativo" value="<?= $account["ativo"]
    ? 0
    : 1 ?>"><button class="btn btn-sm <?= $account["ativo"]
    ? "btn-outline-danger"
    : "btn-outline-light" ?>" <?= $account["id"] == ($_SESSION["conta_id"] ?? 0)
    ? "disabled"
    : "" ?>><?= $account["ativo"]
    ? "Desativar"
    : "Reativar" ?></button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div></div></section>
<?php require __DIR__ .
    "/noticias-tab.php"; ?><section id="tab-videos" class="tab-pane fade"><div class="row g-4"><div class="col-lg-5"><form class="panel admin-form" method="post"><h2>Publicar vídeo</h2><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="video"><label class="form-label">Título</label><input class="form-control mb-2" name="titulo" required><label class="form-label">URL do YouTube</label><input class="form-control" type="url" name="youtube_url" required><button class="btn btn-danger mt-3">Publicar</button></form></div><div class="col-lg-7"><div class="panel"><div class="panel-head"><h3>Vídeos publicados</h3><span><?= count(
    $videosAdmin,
) ?> registros</span></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Título</th><th>Link</th><th>Status</th></tr></thead><tbody><?php foreach (
     $videosAdmin
     as $video
 ): ?><tr><td><?= e($video["titulo"]) ?></td><td><a href="<?= e(
    $video["youtube_url"],
) ?>" target="_blank" rel="noopener">Abrir vídeo</a></td><td><?= $video["ativo"]
    ? "Publicado"
    : "Oculto" ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></div></section>
<section id="tab-configuracoes" class="tab-pane fade"><form class="panel admin-form" method="post"><h2>Configurações do site</h2><p class="text-secondary">Personalize o rodapé e a ordem das seções da página inicial.</p><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="configuracoes"><div class="row g-3"><div class="col-md-6"><label class="form-label">Texto esquerdo do rodapé</label><input class="form-control" name="footer_nome" value="<?= e(
    $siteConfig["footer_nome"],
) ?>"></div><div class="col-md-6"><label class="form-label">Texto direito do rodapé</label><input class="form-control" name="footer_projeto" value="<?= e(
    $siteConfig["footer_projeto"],
) ?>"></div><div class="col-md-6"><label class="form-label">Convite do Discord</label><input class="form-control" type="url" name="discord_url" value="<?= e(
    $siteConfig["discord_url"],
) ?>"></div><div class="col-md-6"><label class="form-label">Canal do YouTube</label><input class="form-control" type="url" name="youtube_url" value="<?= e(
    $siteConfig["youtube_url"],
) ?>"></div><div class="col-12"><label class="form-label">Ordem das seções</label><input class="form-control" name="ordem_secoes" value="<?= e(
    $siteConfig["ordem_secoes"],
) ?>"><small class="text-secondary">Use os nomes separados por vírgula: noticias, competicao, participantes, artilharia, titulos, midia.</small></div></div><button class="btn btn-danger mt-3">Salvar configurações</button></form></section>
</div></div></main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script src="../assets/js/news-editor.js?v=<?= filemtime(
    __DIR__ . "/../assets/js/news-editor.js",
) ?>"></script><script src="../assets/js/sumula-importer.js?v=<?= filemtime(
    __DIR__ . "/../assets/js/sumula-importer.js",
) ?>"></script><script src="../assets/js/admin.js?v=<?= filemtime(
    __DIR__ . "/../assets/js/admin.js",
) ?>"></script><?php if (
    sync_user_allowed()
): ?><script src="../assets/js/sync-admin.js?v=<?= filemtime(
    __DIR__ . "/../assets/js/sync-admin.js",
) ?>"></script><?php endif; ?></body></html>
