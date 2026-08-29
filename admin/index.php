<?php
// Carrega a conexão, a sessão e protege o painel administrativo.
require __DIR__ . "/../includes/bootstrap.php";
require __DIR__ . "/../includes/public-layout.php";
require __DIR__ . "/../includes/sync.php";
require __DIR__ . "/../includes/knockout.php";
admin_required();
$pdo = db();
$adminPublicSections = [
    'noticias' => ['../index.php#noticias', 'Notícias'],
    'competicao' => ['../index.php#competicao', 'Competição'],
    'participantes' => ['../index.php#participantes', 'Participantes'],
    'artilharia' => ['../index.php#artilharia', 'Jogadores'],
    'titulos' => ['../index.php#titulos', 'Títulos'],
    'midia' => ['../index.php#midia', 'Vídeos'],
];
$adminPublicOrder = array_filter(array_map('trim', explode(',', (string)(public_site_config()['ordem_secoes'] ?? ''))));
ensure_supercup_schema($pdo);
ensure_knockout_wo_schema($pdo);
ensure_league_penalty_schema($pdo);
try {
    competition_identities_seed($pdo);
} catch (Throwable $ignored) {
    // O painel continua disponível até a migration de identidades ser aplicada.
}
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
    audit_post_success($tab !== "" ? $tab: "admin", $message);
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
        "Location: index.php" . ($tab !== "" ? "?tab=" . urlencode($tab): ""),
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
        "SELECT COUNT(*) FROM partidas WHERE campeonato_id=? AND ativo=1 AND rodada<? AND status NOT IN ('finalizada','wo','penalidade')",
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
        $teamId === $homeId ? $homeCount++: $awayCount++;
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
        $teamId === $teamAId ? $countA++: $countB++;
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
            ensure_news_summary_schema($pdo);
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
            if (mb_strlen($summary, "UTF-8") > 500) {
                throw new RuntimeException(
                    "O resumo deve ter no máximo 500 caracteres.",
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
            $novoStatus = (int) ($_POST["ativo"] ?? 0) === 1 ? 1: 0;
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
                        $participanteId > 0 ? $participanteId: null,
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
                        $participanteId > 0 ? $participanteId: null,
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
                $participanteId > 0 ? $participanteId: null,
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
            $novoStatus = (int) ($_POST["ativo"] ?? 0) === 1 ? 1: 0;
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
            if ($status === 'finalizado') competition_sync_champion_title($pdo, $campeonatoId);
            redirect_notice(
                $status === "finalizado"
                    ? "Campeonato finalizado."
                   : "Campeonato reaberto.",
                "campeonatos",
            );
        }
        // Edita a fonte única de logo e taça usada por todas as edições e títulos relacionados.
        if ($action === 'editar_identidade_competicao') {
            master_required();
            $identityId = (int)($_POST['identidade_id'] ?? 0);
            $name = mb_substr(trim((string)($_POST['nome'] ?? '')), 0, 150);
            if ($identityId <= 0 || $name === '') throw new RuntimeException('Selecione um campeonato padrão válido.');
            $logo = competition_posted_data_url('logo_base64') ?? competition_uploaded_data_url('logo');
            $trophy = competition_posted_data_url('trofeu_base64') ?? competition_uploaded_data_url('trofeu');
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE competicao_identidades SET nome=? WHERE id=?')->execute([$name, $identityId]);
            if ($logo !== null) $pdo->prepare('UPDATE competicao_identidades SET logo_base64=? WHERE id=?')->execute([$logo, $identityId]);
            if ($trophy !== null) $pdo->prepare('UPDATE competicao_identidades SET trofeu_base64=? WHERE id=?')->execute([$trophy, $identityId]);
            $pdo->commit();
            redirect_notice('Campeonato padrão atualizado em todas as edições, títulos e vitrines.', 'campeonatos');
        }
        // Edita a apresentação da competição e a identidade compartilhada por suas edições.
        if ($action === 'editar_campeonato') {
            master_required();
            $campeonatoId = (int)($_POST['campeonato_id'] ?? 0);
            $nome = mb_substr(trim((string)($_POST['nome'] ?? '')), 0, 150);
            $status = (string)($_POST['status'] ?? 'ativo');
            if ($campeonatoId <= 0 || $nome === '') throw new RuntimeException('Informe uma competição e um nome válido.');
            if (!in_array($status, ['ativo', 'finalizado'], true)) throw new RuntimeException('Status de campeonato inválido.');
            $stmt = $pdo->prepare('SELECT identidade_id FROM campeonatos WHERE id=? AND ativo=1 LIMIT 1');
            $stmt->execute([$campeonatoId]);
            $identityId = (int)($stmt->fetchColumn() ?: 0);
            $logo = competition_posted_data_url('logo_base64') ?? competition_uploaded_data_url('logo');
            $trophy = competition_posted_data_url('trofeu_base64') ?? competition_uploaded_data_url('trofeu');
            $pdo->beginTransaction();
            if ($identityId <= 0) {
                $key = competition_identity_match($nome) ?? competition_identity_key($nome);
                $identity = $pdo->prepare('SELECT id FROM competicao_identidades WHERE chave=? LIMIT 1');
                $identity->execute([$key]);
                $identityId = (int)($identity->fetchColumn() ?: 0);
                if ($identityId <= 0) {
                    $pdo->prepare('INSERT INTO competicao_identidades(chave,nome) VALUES(?,?)')->execute([$key, $nome]);
                    $identityId = (int)$pdo->lastInsertId();
                }
            }
            $pdo->prepare('UPDATE campeonatos SET nome=?,status=?,identidade_id=? WHERE id=? AND ativo=1')->execute([$nome, $status, $identityId, $campeonatoId]);
            if ($logo !== null) $pdo->prepare('UPDATE competicao_identidades SET logo_base64=? WHERE id=?')->execute([$logo, $identityId]);
            if ($trophy !== null) $pdo->prepare('UPDATE competicao_identidades SET trofeu_base64=? WHERE id=?')->execute([$trophy, $identityId]);
            $pdo->commit();
            if ($status === 'finalizado') competition_sync_champion_title($pdo, $campeonatoId);
            redirect_notice('Competição e identidade visual atualizadas.', 'campeonatos');
        }
        // Cria uma decisão entre os campeões confirmados de duas competições.
        if ($action === "criar_supercopa") {
            $name = trim((string) ($_POST["nome"] ?? ""));
            $identityId = (int)($_POST['identidade_id'] ?? 0);
            $sourceA = (int) ($_POST["origem_a_campeonato_id"] ?? 0);
            $sourceB = (int) ($_POST["origem_b_campeonato_id"] ?? 0);
            $format = (string) ($_POST["formato"] ?? "unico");
            if ($name === "") throw new RuntimeException("Informe o nome da Supercopa.");
            if ($identityId <= 0) {
                $identityStmt = $pdo->prepare("SELECT id FROM competicao_identidades WHERE chave='supercopa r' LIMIT 1");
                $identityStmt->execute();
                $identityId = (int)($identityStmt->fetchColumn() ?: 0);
            }
            if ($identityId <= 0) throw new RuntimeException('Selecione um campeonato padrão para esta edição.');
            if ($sourceA <= 0 || $sourceB <= 0 || $sourceA === $sourceB) throw new RuntimeException("Selecione duas competições de origem diferentes.");
            if (!in_array($format, ["unico", "ida_volta"], true)) throw new RuntimeException("Formato inválido.");
            $sameChampionRule = (string)($_POST["regra_mesmo_campeao"] ?? "vice_origem_a");
            if (!in_array($sameChampionRule, ["vice_origem_a", "vice_origem_b"], true)) throw new RuntimeException("Regra de substituição inválida.");
            $teamA = competition_champion_id($pdo, $sourceA);
            $teamB = competition_champion_id($pdo, $sourceB);
            if ($teamA && $teamB && $teamA === $teamB) {
                if ($sameChampionRule === "vice_origem_b") $teamB = competition_runner_up_id($pdo, $sourceB);
                else $teamA = competition_runner_up_id($pdo, $sourceA);
            }
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO campeonatos(nome,identidade_id,tipo,formato) VALUES(?,?,'supercopa',?)")->execute([$name, $identityId, $format]);
            $championshipId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO supercopas(campeonato_id,origem_a_campeonato_id,origem_b_campeonato_id,regra_mesmo_campeao) VALUES(?,?,?,?)")->execute([$championshipId, $sourceA, $sourceB, $sameChampionRule]);
            $game = $pdo->prepare("INSERT INTO jogos_mata_mata(campeonato_id,fase,ordem,jogo,time_a_id,time_b_id,gols_a,gols_b,vencedor_id,status) VALUES(?,'Final',1,?,?,?,NULL,NULL,NULL,'agendado')");
            $game->execute([$championshipId, 1, $teamA, $teamB]);
            if ($format === "ida_volta") $game->execute([$championshipId, 2, $teamB, $teamA]);
            $pdo->commit();
            redirect_notice("Supercopa criada com os campeões classificados.", "supercopa");
        }
        // Atualiza uma partida sorteada ou cria uma partida manual.
        if ($action === "partida") {
            $partidaId = (int) ($_POST["partida_id"] ?? 0);
            $statusPartida = (string) ($_POST["status"] ?? "");
            if (!in_array($statusPartida, ["agendada", "finalizada", "wo", "penalidade"], true)) {
                throw new RuntimeException("Selecione um status válido para a partida.");
            }
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
            if ($statusPartida === "finalizada" && $homeGoals === null) {
                throw new RuntimeException(
                    "Informe o placar para finalizar a partida.",
                );
            }
            if ($partidaId > 0) {
                if (
                    in_array($_POST["status"] ?? "", ["finalizada", "wo", "penalidade"], true)
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
                if ($statusPartida === "wo") {
                    $woWinner = (int) ($_POST["vencedor_wo_id"] ?? 0);
                    if (!in_array($woWinner, [(int)$match["mandante_id"], (int)$match["visitante_id"]], true)) {
                        throw new RuntimeException("Escolha o time vencedor do W.O.");
                    }
                    $homeGoals = $woWinner === (int)$match["mandante_id"] ? 3: 0;
                    $awayGoals = $woWinner === (int)$match["visitante_id"] ? 3: 0;
                }
                if ($statusPartida === "penalidade") {
                    $penalized = (int) ($_POST["penalizado_id"] ?? 0);
                    if (!in_array($penalized, [(int)$match["mandante_id"], (int)$match["visitante_id"]], true)) {
                        throw new RuntimeException("Escolha o time que sofreu a penalidade.");
                    }
                    $homeGoals = $penalized === (int)$match["mandante_id"] ? 0 : 3;
                    $awayGoals = $penalized === (int)$match["visitante_id"] ? 0 : 3;
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    "UPDATE partidas SET gols_mandante=?,gols_visitante=?,data_partida=?,status=?,comprovacao_url=? WHERE id=? AND ativo=1",
                );
                $stmt->execute([
                    $homeGoals,
                    $awayGoals,
                    $_POST["data_partida"] ?: null,
                    $statusPartida,
                    trim($_POST["comprovacao_url"]),
                    $partidaId,
                ]);
                sync_match_goals(
                    $pdo,
                    $partidaId,
                    (int) $match["campeonato_id"],
                    (int) $match["mandante_id"],
                    (int) $match["visitante_id"],
                    in_array($statusPartida, ["wo", "penalidade"], true) ? null: $homeGoals,
                    in_array($statusPartida, ["wo", "penalidade"], true) ? null: $awayGoals,
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
            if ($statusPartida === "wo") {
                $woWinner = (int) ($_POST["vencedor_wo_id"] ?? 0);
                $homeId = (int)$_POST["mandante_id"];
                $awayId = (int)$_POST["visitante_id"];
                if (!in_array($woWinner, [$homeId, $awayId], true)) throw new RuntimeException("Escolha o time vencedor do W.O.");
                $homeGoals = $woWinner === $homeId ? 3: 0;
                $awayGoals = $woWinner === $awayId ? 3: 0;
            }
            if ($statusPartida === "penalidade") {
                $penalized = (int) ($_POST["penalizado_id"] ?? 0);
                $homeId = (int)$_POST["mandante_id"];
                $awayId = (int)$_POST["visitante_id"];
                if (!in_array($penalized, [$homeId, $awayId], true)) throw new RuntimeException("Escolha o time que sofreu a penalidade.");
                $homeGoals = $penalized === $homeId ? 0 : 3;
                $awayGoals = $penalized === $awayId ? 0 : 3;
            }
            if (in_array($_POST["status"] ?? "", ["finalizada", "wo", "penalidade"], true)) {
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
                $statusPartida,
                trim($_POST["comprovacao_url"]),
            ]);
            $partidaId = (int) $pdo->lastInsertId();
            sync_match_goals(
                $pdo,
                $partidaId,
                $championshipId,
                (int) $_POST["mandante_id"],
                (int) $_POST["visitante_id"],
                in_array($statusPartida, ["wo", "penalidade"], true) ? null: $homeGoals,
                in_array($statusPartida, ["wo", "penalidade"], true) ? null: $awayGoals,
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
            if (!in_array($statusMata, ["agendado", "finalizado", "wo"], true)) {
                throw new RuntimeException(
                    "Selecione um status válido para o confronto.",
                );
            }
            $goalsA = $_POST["gols_a"] === "" ? null: (int) $_POST["gols_a"];
            $goalsB = $_POST["gols_b"] === "" ? null: (int) $_POST["gols_b"];
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
                if ($statusMata === "wo") {
                    $winner = (int)($_POST["vencedor_id"] ?? 0);
                    if (!in_array($winner, [(int)$tie["time_a_id"], (int)$tie["time_b_id"]], true)) {
                        throw new RuntimeException("Escolha o time vencedor do W.O.");
                    }
                    $pdo->beginTransaction();
                    $legsStmt = $pdo->prepare("SELECT id,time_a_id,time_b_id FROM jogos_mata_mata WHERE campeonato_id=? AND fase=? AND ordem=? AND ativo=1 FOR UPDATE");
                    $legsStmt->execute([(int)$tie["campeonato_id"], $tie["fase"], (int)$tie["ordem"]]);
                    foreach ($legsStmt->fetchAll() as $leg) {
                        if (!in_array($winner, [(int)$leg["time_a_id"], (int)$leg["time_b_id"]], true)) {
                            throw new RuntimeException("O vencedor escolhido não participa de uma das partidas deste confronto.");
                        }
                        $legGoalsA = $winner === (int)$leg["time_a_id"] ? 3: 0;
                        $legGoalsB = $winner === (int)$leg["time_b_id"] ? 3: 0;
                        $pdo->prepare("UPDATE jogos_mata_mata SET gols_a=?,gols_b=?,penaltis_a=NULL,penaltis_b=NULL,vencedor_id=?,status='wo' WHERE id=?")
                            ->execute([$legGoalsA, $legGoalsB, $winner, (int)$leg["id"]]);
                        sync_knockout_goals($pdo, (int)$leg["id"], (int)$tie["campeonato_id"], (int)$leg["time_a_id"], (int)$leg["time_b_id"], null, null, []);
                    }
                    advance_knockout($pdo, (int)$tie["campeonato_id"], $tie["fase"], (int)$tie["ordem"]);
                    $pdo->commit();
                    redirect_notice("Confronto encerrado por W.O. com vitória por 3 a 0 em cada partida.");
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
            if ($statusMata === "wo") {
                $winner = (int)($_POST["vencedor_id"] ?? 0);
                $teamAId = (int)$_POST["time_a_id"];
                $teamBId = (int)$_POST["time_b_id"];
                if (!in_array($winner, [$teamAId, $teamBId], true)) throw new RuntimeException("Escolha o time vencedor do W.O.");
                $goalsA = $winner === $teamAId ? 3: 0;
                $goalsB = $winner === $teamBId ? 3: 0;
                $penaltiesA = $penaltiesB = null;
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
                $statusMata === "wo" ? null: $goalsA,
                $statusMata === "wo" ? null: $goalsB,
                $statusMata === "wo" ? []: $_POST,
            );
            advance_knockout($pdo, $championshipId, (string)$_POST["fase"], (int)$_POST["ordem"]);
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
            $titleId = (int)($_POST['titulo_id'] ?? 0);
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
                $timeHistorico = $timeHistorico === "" ? null: $timeHistorico;
            } else {
                throw new RuntimeException(
                    "Selecione uma origem válida para o título.",
                );
            }
            $titleImage = competition_posted_data_url('titulo_imagem_base64');
            $values = [
                $participanteId,
                trim($_POST["titulo"]),
                $_POST["temporada"],
                trim($_POST["descricao"]),
                $_POST["conquistado_em"] ?: null,
                $tecnicoHistorico,
                $timeHistorico,
            ];
            if ($titleId > 0) {
                $stmt = $pdo->prepare('UPDATE titulos SET participante_id=?,titulo=?,temporada=?,descricao=?,conquistado_em=?,tecnico_nome=?,time_nome=?' . ($titleImage !== null ? ',imagem_base64=?': '') . ' WHERE id=?');
                if ($titleImage !== null) $values[] = $titleImage;
                $values[] = $titleId;
                $stmt->execute($values);
            } else {
                $stmt = $pdo->prepare('INSERT INTO titulos(participante_id,titulo,temporada,descricao,conquistado_em,tecnico_nome,time_nome,imagem_base64) VALUES(?,?,?,?,?,?,?,?)');
                $values[] = $titleImage;
                $stmt->execute($values);
            }
            redirect_notice($titleId > 0 ? 'Título histórico atualizado.': 'Título adicionado à história da competição.', 'titulos');
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
reconcile_knockout_summaries($pdo);
sync_supercup_slots($pdo);
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
$supercupSources = $championshipsAdmin;
$supercupsAdmin = [];
try {
    $supercupsAdmin = $pdo->query("SELECT s.id,c.nome,c.status,oa.nome origem_a,ob.nome origem_b,a.time_nome time_a,b.time_nome time_b FROM supercopas s JOIN campeonatos c ON c.id=s.campeonato_id JOIN campeonatos oa ON oa.id=s.origem_a_campeonato_id JOIN campeonatos ob ON ob.id=s.origem_b_campeonato_id LEFT JOIN jogos_mata_mata j ON j.campeonato_id=c.id AND j.fase='Final' AND j.jogo=1 AND j.ativo=1 LEFT JOIN participantes a ON a.id=j.time_a_id LEFT JOIN participantes b ON b.id=j.time_b_id ORDER BY s.id DESC")->fetchAll();
} catch (Throwable $ignored) {
}
$marketChampionshipsAdmin = array_values(array_filter(
    $championshipsAdmin,
    fn(array $championship): bool => $championship["tipo"] === "pontos_corridos",
));
$marketDefaultChampionshipId = (int)($marketChampionshipsAdmin[0]["id"] ?? 0);
$scorersAdmin = $pdo
    ->query(
        "SELECT a.id,a.campeonato_id,a.jogador,a.participante_id,a.gols,c.nome campeonato,p.nome tecnico,p.time_nome FROM artilharia a JOIN campeonatos c ON c.id=a.campeonato_id JOIN participantes p ON p.id=a.participante_id ORDER BY c.status='ativo' DESC,c.criado_em DESC,a.gols DESC,a.jogador",
    )
    ->fetchAll();
$titlesAdmin = $pdo->query("SELECT t.id,t.participante_id,t.titulo,t.temporada,t.descricao,t.conquistado_em,t.tecnico_nome,t.time_nome,CASE WHEN t.imagem_base64 IS NOT NULL AND t.imagem_base64<>'' THEN 1 ELSE 0 END tem_imagem,COALESCE(p.nome,t.tecnico_nome) tecnico,COALESCE(p.time_nome,t.time_nome) clube FROM titulos t LEFT JOIN participantes p ON p.id=t.participante_id ORDER BY t.conquistado_em DESC,t.id DESC")->fetchAll();
foreach ($titlesAdmin as &$titleAdminRow) if (competition_identity_match((string)$titleAdminRow['titulo'])) $titleAdminRow['tem_imagem'] = 1;
unset($titleAdminRow);
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
        "SELECT j.id,j.fase,j.ordem,j.jogo,j.time_a_id,j.time_b_id,a.time_nome time_a,b.time_nome time_b,j.gols_a,j.gols_b,j.penaltis_a,j.penaltis_b,j.status FROM jogos_mata_mata j JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id WHERE j.ativo=1 AND j.time_a_id IS NOT NULL AND j.time_b_id IS NOT NULL ORDER BY FIELD(j.fase,'Preliminar','Oitavas','Quartas','Semifinal','Terceiro lugar','Final'),j.ordem,j.jogo,j.id",
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
            ": Técnico " .
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
            ": " .
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
            ": " .
            e($g["time_a"]) .
            " x " .
            e($g["time_b"]) .
            "</option>";
    }
    return $out;
}
$adminProfile = ['time_nome' => '', 'sigla' => 'S3', 'escudo_url' => ''];
$adminParticipantId = (int)(account_participant_id() ?? 0);
if ($adminParticipantId > 0) {
    try {
        $adminProfileStmt = db()->prepare('SELECT time_nome,sigla,escudo_url FROM participantes WHERE id=? AND ativo=1 LIMIT 1');
        $adminProfileStmt->execute([$adminParticipantId]);
        $adminProfile = $adminProfileStmt->fetch() ?: $adminProfile;
    } catch (Throwable $ignored) {}
}

function admin_nav_icon(string $name): string
{
    $paths = [
        'dashboard' => '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>',
        'jogos' => '<path d="M4 6h16v12H4zM8 6v12m8-12v12M4 10h4m8 4h4"/>',
        'mata' => '<path d="M5 4h5v5H5zm9 0h5v5h-5zM5 15h5v5H5zm9 0h5v5h-5zM10 6.5h4M7.5 9v6m9-6v6M10 17.5h4"/>',
        'sumula' => '<path d="M6 3.5h9l3 3V20H6zM15 3.5V7h3M9 11h6M9 14h6M9 17h4"/>',
        'campeonatos' => '<path d="M8 4h8v4a4 4 0 0 1-8 0V4Zm0 2H5v1a4 4 0 0 0 4 4m7-5h3v1a4 4 0 0 1-4 4m-3 1v4m-4 3h8"/>',
        'supercopa' => '<path d="m12 3 2.2 4.5 5 .7-3.6 3.5.9 5-4.5-2.4-4.5 2.4.9-5-3.6-3.5 5-.7L12 3Z"/>',
        'artilharia' => '<circle cx="12" cy="8" r="3.5"/><path d="M5.5 19c.7-4 3-6 6.5-6s5.8 2 6.5 6"/>',
        'sorteador' => '<path d="M4 7h4l8 10h4M4 17h4l3-3.8M15 7h5m-3-2 3 2-3 2m0 6 3 2-3 2"/>',
        'times' => '<path d="M12 3 19 6v5c0 4.4-2.3 7.5-7 10-4.7-2.5-7-5.6-7-10V6l7-3Z"/>',
        'usuarios' => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3.5 19c.5-4 2.4-6 5.5-6s5 2 5.5 6m0-5c3.2 0 5 1.7 5.5 5"/>',
        'mercado' => '<path d="M4 8h13m-3-3 3 3-3 3m6 5H7m3-3-3 3 3 3"/>',
        'titulos' => '<circle cx="12" cy="14" r="5"/><path d="m9 9-3-5h4l2 4 2-4h4l-3 5m-5 5 1.4 1.1L13 13"/>',
        'videos' => '<rect x="3.5" y="5" width="17" height="14" rx="2"/><path d="m10 9 5 3-5 3Z"/>',
        'configuracoes' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v2m0 14v2M3 12h2m14 0h2M5.6 5.6 7 7m10 10 1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4"/>',
        'noticias' => '<path d="M4 5.5h16v13H4zM7 9h4v3H7zm7 0h3M14 12h3M7 15h10"/>',
        'site' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9S14.5 18.5 12 21c-2.5-2.5-3.5-5.5-3.5-9S9.5 5.5 12 3Z"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($paths[$name] ?? $paths['dashboard']) . '</svg>';
}
?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Painel | Season 3</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>"><link rel="stylesheet" href="../assets/css/branding.css?v=5"><link rel="stylesheet" href="../assets/css/news.css?v=<?= filemtime(
    __DIR__ . "/../assets/css/news.css",
) ?>"><style>.admin-shell{padding:95px 0 60px}.form-control,.form-select{background:#0b0c0e;border-color:#343941}.admin-form{padding:1.25rem}.admin-form h2{font:800 1.5rem 'Barlow Condensed',sans-serif;text-transform:uppercase}.nav-pills .nav-link.active{background:#d71920}.editor-role #tab-campeonatos,.editor-role #tab-times,.editor-role #tab-titulos,.editor-role #tab-videos,.editor-role #tab-configuracoes,.editor-role #tab-usuarios,.editor-role #tab-extra .col-lg-5>form:nth-of-type(2){display:none!important}</style></head><body class="<?= account_is_editor()
    ? "editor-role"
   : "master-role" ?>"><script>if(innerWidth>=768){document.body.classList.add('admin-nav-collapsed');try{if(sessionStorage.getItem('admin-sidebar-state')==='expanded')document.body.classList.remove('admin-nav-collapsed')}catch(error){}}</script>
<style>
.admin-navigation{position:fixed;z-index:1061;inset:0 auto 0 0;display:flex;flex-direction:column;width:264px;height:100vh;padding:16px 12px;border-right:1px solid #30343c;background:linear-gradient(160deg,#17191e,#090a0c 65%);overflow:hidden;transition:width .22s ease,padding .22s ease}.admin-side-head{display:flex;align-items:center;justify-content:space-between;height:54px;padding-bottom:14px;border-bottom:1px solid #2b2e35}.admin-side-brand{display:flex;align-items:center;gap:9px;min-width:0;overflow:hidden;color:#fff;text-decoration:none;font:800 1.08rem 'Barlow Condensed',sans-serif;letter-spacing:.03em}.admin-side-brand img{flex:0 0 42px;width:42px;height:42px;object-fit:contain}.admin-side-brand span{min-width:135px}.admin-side-brand b{display:block;color:#d71920;font-size:.7rem;letter-spacing:.14em}.admin-sidebar-collapse{flex:0 0 30px;width:30px;height:30px;padding:0;border:0;background:transparent;color:#bbc0c9;font-size:1.5rem;line-height:1}.admin-sidebar-collapse:hover{color:#fff}.admin-side-profile{display:grid;grid-template-columns:48px 1fr;align-items:center;gap:10px;margin:14px 0 9px;padding:9px;border:1px solid #30343c;border-radius:14px;background:rgba(255,255,255,.035);overflow:hidden}.admin-sidebar-shield{display:grid;place-items:center;width:48px;height:48px;border:1px solid #353941;border-radius:11px;background:#090a0c;overflow:hidden;font:800 .8rem 'Barlow Condensed',sans-serif}.admin-sidebar-shield img{width:42px;height:42px;object-fit:contain}.admin-side-profile-text{min-width:145px}.admin-side-profile small,.admin-side-profile strong,.admin-side-profile span{display:block}.admin-side-profile small{color:#d71920;font-size:.6rem;font-weight:800;letter-spacing:.12em}.admin-side-profile strong{font-size:.92rem}.admin-side-profile span{color:#9198a3;font-size:.74rem}.admin-side-nav{flex:1;min-height:0;margin-top:4px;padding-right:3px;overflow-x:hidden;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#343941 transparent}.admin-nav-group+.admin-nav-group{margin-top:9px;padding-top:9px;border-top:1px solid #25282e}.admin-side-label{display:block;height:20px;padding:0 12px 6px;color:#6f7580;font-size:.62rem;font-weight:800;letter-spacing:.17em;white-space:nowrap}.admin-nav-group ul{list-style:none;margin:0;padding:0}.admin-nav-group .nav-link{display:grid;grid-template-columns:34px 1fr 12px;align-items:center;gap:7px;width:100%;height:39px;padding:2px 7px;border:0;border-radius:8px;background:transparent;color:#c8ccd3;text-align:left;text-decoration:none;font-size:.86rem;font-weight:600;white-space:nowrap;overflow:hidden}.admin-nav-group .nav-link i{display:grid;place-items:center;width:30px;height:30px;border:1px solid #31353d;border-radius:8px;color:#aeb4be;font-style:normal}.admin-nav-group .nav-link svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}.admin-nav-group .nav-link b{color:#626873;font-size:1.2rem}.admin-nav-group .nav-link:hover{background:#202329;color:#fff}.admin-nav-group .nav-link.active{background:linear-gradient(90deg,rgba(215,25,32,.2),transparent);color:#fff;box-shadow:inset 3px 0 #d71920}.admin-nav-group .nav-link.active i{border-color:rgba(215,25,32,.55);color:#ff646a}.admin-side-actions{display:grid;grid-template-columns:1fr auto;align-items:center;gap:10px;padding:8px 4px 0}.admin-side-actions a{color:#aeb3bc;text-decoration:none;font-size:.78rem;font-weight:700;white-space:nowrap}.admin-side-actions .admin-open-site{display:grid;place-items:center;min-height:43px;padding:8px 14px;border-radius:9px;background:#d71920;color:#fff}.admin-menu-toggle{display:none}.admin-embedded-frame{display:block;width:100%;min-height:1100px;border:0;background:#08090b}.admin-shell{margin-left:264px;transition:margin-left .22s ease}.admin-shell>.container{max-width:calc(100% - 34px)}
@media(min-width:768px){.admin-nav-collapsed .admin-navigation{width:68px;padding-inline:0}.admin-nav-collapsed .admin-shell{margin-left:68px}.admin-nav-collapsed .admin-side-head{width:68px}.admin-nav-collapsed .admin-side-brand{width:68px;justify-content:center}.admin-nav-collapsed .admin-side-brand span{display:none}.admin-nav-collapsed .admin-sidebar-collapse{position:absolute;z-index:2;top:25px;left:58px;width:20px;height:26px;border:1px solid #3a3f48;border-radius:7px;background:#202329;box-shadow:0 3px 12px rgba(0,0,0,.35);transform:rotate(180deg)}.admin-nav-collapsed .admin-side-profile{width:48px;margin-inline:10px;padding:0;border-color:transparent;background:transparent}.admin-nav-collapsed .admin-side-profile-text,.admin-nav-collapsed .admin-side-label,.admin-nav-collapsed .admin-side-actions{opacity:0;pointer-events:none}.admin-nav-collapsed .admin-side-nav{width:68px;padding-right:0}.admin-nav-collapsed .admin-nav-group .nav-link{display:flex;justify-content:center;width:44px;height:40px;margin-left:12px;padding:0}.admin-nav-collapsed .admin-nav-group .nav-link span,.admin-nav-collapsed .admin-nav-group .nav-link b{display:none}.navbar .admin-menu-toggle{display:none}}
@media(max-width:767.98px){.admin-navigation{width:min(370px,94vw);padding:17px;transform:translateX(-105%);box-shadow:20px 0 55px #000;transition:transform .25s ease}.admin-nav-open{overflow:hidden}.admin-nav-open .admin-navigation{transform:none}.admin-sidebar-collapse{font-size:0;width:39px;height:39px;border:1px solid #353942;border-radius:50%}.admin-sidebar-collapse:after{content:'×';font-size:1.55rem}.admin-shell{margin-left:0}.admin-shell>.container{max-width:100%}.navbar .admin-menu-toggle{display:inline-flex}.admin-heading{align-items:stretch!important}.admin-heading h1{font-size:2.5rem}.admin-embedded-frame{min-height:1500px}}
.master-role>.navbar,.editor-role>.navbar{left:264px;transition:left .22s ease}@media(min-width:768px){.admin-navigation{overflow:visible}.admin-nav-collapsed>.navbar{left:68px}}@media(max-width:767.98px){.master-role>.navbar,.editor-role>.navbar{left:0}}
</style>
<div class="site-loading-screen admin-loading-screen" role="status" aria-live="polite" aria-label="Carregando painel"><img src="../assets/img/logo-season3.webp?v=5" alt="" aria-hidden="true"><span class="site-loading-spinner"></span><strong data-loading-label>CARREGANDO DADOS</strong></div>
<nav class="navbar fixed-top navbar-dark"><div class="container-fluid px-3 px-lg-4"><div class="global-nav-links" aria-label="Seções públicas"><?php foreach ($adminPublicOrder as $sectionKey): if (!isset($adminPublicSections[$sectionKey])) continue; [$sectionHref, $sectionLabel] = $adminPublicSections[$sectionKey]; ?><a href="<?= e($sectionHref) ?>"><?= e($sectionLabel) ?></a><?php endforeach; ?></div><div class="d-flex align-items-center gap-3"><span class="text-secondary d-none d-md-inline">Olá, <?= e($_SESSION["conta_nome"] ?? "") ?></span><button type="button" class="btn btn-outline-light btn-sm admin-menu-toggle" aria-label="Recolher ou abrir menu do painel" aria-expanded="true">☰ Menu</button></div></div></nav>
<style>.admin-competition-logo{width:68px;height:44px;object-fit:contain}.competition-image-preview{display:grid;place-items:center;height:150px;margin-bottom:.5rem;border:1px solid #343941;background:#090a0c}.competition-image-preview img{max-width:100%;height:140px;object-fit:contain}#tab-campeonatos td:last-child form{display:inline-block}</style>
<style>.competition-art-editor[hidden]{display:none}.competition-art-editor{position:fixed;inset:0;z-index:2100;display:grid;place-items:center;padding:18px;background:rgba(0,0,0,.82)}.competition-art-editor-card{width:min(620px,100%);padding:20px;border:1px solid #3b414b;border-radius:14px;background:#111318;box-shadow:0 20px 70px #000}.competition-art-crop{position:relative;padding:5px;border:3px solid #ed1b2f;background:#ed1b2f;box-shadow:0 0 0 2px #fff,0 0 28px rgba(237,27,47,.45)}.competition-art-crop:before{content:'ÁREA QUE SERÁ SALVA';position:absolute;z-index:2;top:10px;left:50%;transform:translateX(-50%);padding:5px 9px;border-radius:4px;background:rgba(0,0,0,.82);color:#fff;font-size:.66rem;font-weight:900;letter-spacing:.08em;white-space:nowrap;pointer-events:none}.competition-art-crop:after{content:'';position:absolute;inset:5px;border:2px dashed rgba(255,255,255,.9);pointer-events:none}.competition-art-editor canvas{display:block;width:100%;max-height:52vh;object-fit:contain;touch-action:none;cursor:grab;background-color:#e7e7e7;background-image:linear-gradient(45deg,#ccc 25%,transparent 25%),linear-gradient(-45deg,#ccc 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#ccc 75%),linear-gradient(-45deg,transparent 75%,#ccc 75%);background-size:20px 20px;background-position:0 0,0 10px,10px -10px,-10px 0}.competition-art-editor canvas:active{cursor:grabbing}.competition-art-note{margin:.75rem 0 0;color:#aab0ba;font-size:.72rem}.competition-art-note strong{color:#fff}.admin-title-image{width:54px;height:58px;object-fit:contain}.title-image-preview{height:180px}.title-image-preview img{height:170px}@media(max-width:575px){.competition-art-editor-card{padding:14px}.competition-art-editor canvas{max-height:45vh}}</style>
<main class="admin-shell"><div class="container"><div class="admin-heading d-flex justify-content-between align-items-end mb-4"><div><span class="eyebrow">Central de atualização</span><h1 class="display-4 fw-bold"><?= account_is_master()
    ? "ADMINISTRAÇÃO"
   : "EDITOR DA COMPETIÇÃO" ?></h1></div></div>
<?php if ($notice): ?><div class="alert alert-info"><?= e(
    $notice,
) ?></div><?php endif; ?>
<?php if (
    sync_user_allowed()
): ?><div id="database-sync-control" class="alert alert-warning d-none flex-wrap align-items-center justify-content-between gap-3" data-csrf="<?= e(
    csrf_token(),
) ?>"><div><strong>Homologação desatualizada</strong><div class="database-sync-status small">A produção possui dados mais recentes.</div></div><button type="button" class="btn btn-warning fw-bold">Sincronizar agora</button></div><?php endif; ?>
<aside class="admin-navigation" aria-label="Navegação administrativa">
    <div class="admin-side-head"><a class="admin-side-brand" href="index.php"><img src="../assets/img/logo-season3.webp?v=5" alt=""><span>PAINEL <b>SEASON 3</b></span></a><button type="button" class="admin-sidebar-collapse" aria-label="Recolher menu" title="Expandir ou recolher">‹</button></div>
    <div class="admin-side-profile"><div class="admin-sidebar-shield"><?php if (!empty($adminProfile['escudo_url'])): ?><img src="<?= e($adminProfile['escudo_url']) ?>" alt="Escudo do <?= e($adminProfile['time_nome']) ?>"><?php else: ?><?= e($adminProfile['sigla'] ?: 'S3') ?><?php endif; ?></div><div class="admin-side-profile-text"><small>CONTA ADMINISTRATIVA</small><strong><?= e($_SESSION['conta_nome'] ?? 'Usuário') ?></strong><span><?= e($adminProfile['time_nome'] ?: (account_is_master() ? 'Admin Master' : 'Editor da competição')) ?></span></div></div>
    <nav class="admin-side-nav" role="tablist" aria-label="Áreas do painel">
        <?php if (account_is_master()): ?><section class="admin-nav-group"><span class="admin-side-label">VISÃO GERAL</span><ul><li><button class="nav-link" data-bs-target="#tab-dashboard" title="Dashboard"><i><?= admin_nav_icon('dashboard') ?></i><span>Dashboard</span><b>›</b></button></li></ul></section><?php endif; ?>
        <section class="admin-nav-group"><span class="admin-side-label">COMPETIÇÃO</span><ul>
            <li><button class="nav-link active" data-bs-target="#tab-jogos" title="Pontos corridos"><i><?= admin_nav_icon('jogos') ?></i><span>Pontos corridos</span><b>›</b></button></li>
            <li><button class="nav-link" data-bs-target="#tab-mata" title="Mata-mata"><i><?= admin_nav_icon('mata') ?></i><span>Mata-mata</span><b>›</b></button></li>
            <li><button class="nav-link" data-bs-target="#tab-sumula" title="Importar súmula"><i><?= admin_nav_icon('sumula') ?></i><span>Importar súmula</span><b>›</b></button></li>
            <?php if (account_is_master()): ?><li><button class="nav-link" data-bs-target="#tab-campeonatos" title="Campeonatos"><i><?= admin_nav_icon('campeonatos') ?></i><span>Campeonatos</span><b>›</b></button></li><li><button class="nav-link" data-bs-target="#tab-supercopa" title="Supercopa"><i><?= admin_nav_icon('supercopa') ?></i><span>Supercopa</span><b>›</b></button></li><?php endif; ?>
            <li><button class="nav-link" data-bs-target="#tab-extra" title="Artilharia"><i><?= admin_nav_icon('artilharia') ?></i><span>Artilharia</span><b>›</b></button></li>
            <?php if (account_is_master()): ?><li><button class="nav-link" data-bs-target="#tab-sorteador" title="Sorteador"><i><?= admin_nav_icon('sorteador') ?></i><span>Sorteador</span><b>›</b></button></li><?php endif; ?>
        </ul></section>
        <?php if (account_is_master()): ?><section class="admin-nav-group"><span class="admin-side-label">PESSOAS &amp; CLUBES</span><ul><li><button class="nav-link" data-bs-target="#tab-times" title="Técnicos e times"><i><?= admin_nav_icon('times') ?></i><span>Técnicos e times</span><b>›</b></button></li><li><button class="nav-link" data-bs-target="#tab-usuarios" title="Usuários"><i><?= admin_nav_icon('usuarios') ?></i><span>Usuários</span><b>›</b></button></li><li><button class="nav-link" data-bs-target="#tab-mercado" title="Mercado"><i><?= admin_nav_icon('mercado') ?></i><span>Mercado</span><b>›</b></button></li><li><button class="nav-link" data-bs-target="#tab-titulos" title="Títulos"><i><?= admin_nav_icon('titulos') ?></i><span>Títulos</span><b>›</b></button></li></ul></section><?php endif; ?>
        <section class="admin-nav-group"><span class="admin-side-label">CONTEÚDO &amp; SITE</span><ul><?php if (account_is_master()): ?><li><button class="nav-link" data-bs-target="#tab-videos" title="Vídeos"><i><?= admin_nav_icon('videos') ?></i><span>Vídeos</span><b>›</b></button></li><li><button class="nav-link" data-bs-target="#tab-configuracoes" title="Configurações"><i><?= admin_nav_icon('configuracoes') ?></i><span>Configurações</span><b>›</b></button></li><?php endif; ?><li><button class="nav-link" data-bs-target="#tab-noticias" title="Notícias"><i><?= admin_nav_icon('noticias') ?></i><span>Notícias</span><b>›</b></button></li></ul></section>
    </nav>
    <div class="admin-side-actions"><a class="admin-open-site" href="../index.php" target="_blank" rel="noopener">Abrir site</a><a href="../logout.php">Sair</a></div>
</aside>
<div class="tab-content">
<?php if (account_is_master()): ?><section id="tab-dashboard" class="tab-pane fade"><iframe class="admin-embedded-frame" data-admin-frame src="dashboard.php?embed=1" title="Dashboard administrativo" loading="lazy"></iframe></section><section id="tab-sorteador" class="tab-pane fade"><iframe class="admin-embedded-frame" data-admin-frame src="sorteador.php?embed=1" title="Sorteador de competições" loading="lazy"></iframe></section><?php endif; ?>
<?php if (account_is_master()): ?><section id="tab-mercado" class="tab-pane fade"><div class="panel"><div class="panel-head"><div><span class="eyebrow">Gestão Master</span><h3 class="mb-0">Mercado dos clubes</h3></div><span><?= count($teams) ?> clubes</span></div><div class="p-3 border-bottom text-secondary">Acesse o elenco, cofre, escalação e histórico de qualquer clube. Toda alteração continuará registrada com a sua conta administrativa.</div><?php if ($marketDefaultChampionshipId): ?><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Time</th><th>Técnico</th><th>Campeonato</th><th>Ação</th></tr></thead><tbody><?php foreach ($teams as $team): ?><tr><td><strong><?= e($team["time_nome"]) ?></strong></td><td><?= e($team["nome"]) ?></td><td colspan="2"><form class="row g-2" method="get" action="../mercado.php"><input type="hidden" name="participante_id" value="<?= (int)$team["id"] ?>"><div class="col-md-8"><select class="form-select form-select-sm" name="campeonato_id"><?php foreach ($marketChampionshipsAdmin as $marketChampionship): ?><option value="<?= (int)$marketChampionship["id"] ?>"><?= e($marketChampionship["nome"]) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><button class="btn btn-sm btn-outline-danger w-100">Gerenciar</button></div></form></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="alert alert-warning m-3">Cadastre um campeonato de pontos corridos para habilitar a gestão de mercado.</div><?php endif; ?></div></section><?php endif; ?>
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
] ?>" data-rodada="<?= $g["rodada"] ?>" data-mandante-id="<?= (int)$g["mandante_id"] ?>" data-visitante-id="<?= (int)$g["visitante_id"] ?>" data-mandante="<?= e(
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
) ?>"><input type="hidden" name="action" value="mata_mata"><div class="row g-2"><div class="col-md-3"><label class="form-label">Fase</label><select name="fase" class="form-select"><option>Preliminar</option><option>Oitavas</option><option>Quartas</option><option>Semifinal</option><option>Final</option></select></div><div class="col-md-2"><label class="form-label">Ordem</label><input class="form-control" type="number" name="ordem" value="1" min="1"></div><div class="col-md-3"><label class="form-label">Time A</label><select name="time_a_id" class="form-select"><?= team_options(
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
 foreach ($championshipsAdmin as $championship): ?><tr><td><div class="d-flex align-items-center gap-2"><?php if (!empty($championship['identidade_id'])): ?><img class="admin-competition-logo" src="../api/competicao-imagem.php?campeonato_id=<?= (int)$championship['id'] ?>&tipo=logo" alt=""><?php endif; ?><strong><?= e(
    $championship["nome"],
) ?></strong></div></td><td><?= match ($championship["tipo"]) {
    "mata_mata" => "Mata-mata",
    "supercopa" => "Supercopa",
    default => "Pontos corridos",
} ?></td><td><?= e(
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
   : "Reabrir" ?></button></form><button type="button" class="btn btn-sm btn-outline-warning editar-campeonato ms-1" data-bs-toggle="modal" data-bs-target="#competition-edit-modal" data-id="<?= (int)$championship['id'] ?>" data-name="<?= e($championship['nome']) ?>" data-status="<?= e($championship['status']) ?>">Editar</button></td></tr><?php endforeach;
 if (
     !$championshipsAdmin
 ): ?><tr><td colspan="6" class="text-center text-secondary py-4">Nenhuma competição criada até o momento.</td></tr><?php endif;
 ?></tbody></table></div></div>
<div class="modal fade" id="competition-edit-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="competition-edit-form" method="post" enctype="multipart/form-data"><div class="modal-header"><div><small class="eyebrow">Identidade da competição</small><h2 class="modal-title">EDITAR COMPETIÇÃO</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="editar_campeonato"><input type="hidden" name="campeonato_id"><label class="form-label">Nome</label><input class="form-control mb-3" name="nome" maxlength="150" required><label class="form-label">Status</label><select class="form-select mb-3" name="status"><option value="ativo">Em andamento</option><option value="finalizado">Finalizado</option></select><div class="row g-3"><div class="col-6"><label class="form-label">Logo</label><div class="competition-image-preview"><img data-preview="logo" alt="Logo atual"></div><input type="hidden" name="logo_base64"><input class="form-control form-control-sm competition-art-file" type="file" name="logo" data-art-type="logo" accept="image/png,image/webp,image/jpeg"></div><div class="col-6"><label class="form-label">Taça da vitrine</label><div class="competition-image-preview"><img data-preview="trofeu" alt="Taça atual"></div><input type="hidden" name="trofeu_base64"><input class="form-control form-control-sm competition-art-file" type="file" name="trofeu" data-art-type="trofeu" accept="image/png,image/webp,image/jpeg"></div></div><small class="text-secondary d-block mt-3">Ao selecionar, ajuste zoom e posição. Alterar uma arte atualiza todas as edições ligadas à mesma identidade.</small></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger">Salvar alterações</button></div></form></div></div></div>
</section>
<section id="tab-supercopa" class="tab-pane fade"><div class="row g-4"><div class="col-lg-5"><form class="panel admin-form" method="post"><span class="eyebrow">Confronto entre campeões</span><h2 class="mt-2">Criar Supercopa</h2><p class="text-secondary">As vagas são preenchidas automaticamente, inclusive quando um dos campeões ainda não foi definido.</p><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="criar_supercopa"><label class="form-label">Nome da competição</label><input class="form-control mb-3" name="nome" maxlength="150" placeholder="Ex.: Recopa dos Gigantes" required><label class="form-label">Campeão da primeira competição</label><select class="form-select mb-3" name="origem_a_campeonato_id" required><option value="">Selecione</option><?php foreach ($supercupSources as $source): ?><option value="<?= (int) $source['id'] ?>"><?= e($source['nome']) ?>: <?= $source['status'] === 'finalizado' ? 'campeão definido': 'aguardando campeão' ?></option><?php endforeach; ?></select><label class="form-label">Campeão da segunda competição</label><select class="form-select mb-3" name="origem_b_campeonato_id" required><option value="">Selecione</option><?php foreach ($supercupSources as $source): ?><option value="<?= (int) $source['id'] ?>"><?= e($source['nome']) ?>: <?= $source['status'] === 'finalizado' ? 'campeão definido': 'aguardando campeão' ?></option><?php endforeach; ?></select><label class="form-label">Se o mesmo clube vencer as duas</label><select class="form-select mb-3" name="regra_mesmo_campeao"><option value="vice_origem_a">Entra o vice da primeira competição</option><option value="vice_origem_b">Entra o vice da segunda competição</option></select><label class="form-label">Formato da decisão</label><select class="form-select" name="formato"><option value="unico">Jogo único</option><option value="ida_volta">Ida e volta</option></select><button class="btn btn-danger mt-3">Criar confronto</button></form></div><div class="col-lg-7"><div class="panel"><div class="panel-head"><h3>Supercopas cadastradas</h3><span><?= count($supercupsAdmin) ?> registros</span></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Competição</th><th>Vaga 1</th><th>Vaga 2</th><th>Status</th></tr></thead><tbody><?php foreach ($supercupsAdmin as $supercup): ?><tr><td><strong><?= e($supercup['nome']) ?></strong></td><td><?= $supercup['time_a'] ? e($supercup['time_a']): '<span class="text-secondary">Aguardando campeão de '.e($supercup['origem_a']).'</span>' ?></td><td><?= $supercup['time_b'] ? e($supercup['time_b']): '<span class="text-secondary">Aguardando campeão de '.e($supercup['origem_b']).'</span>' ?></td><td><?= e($supercup['status']) ?></td></tr><?php endforeach; ?><?php if (!$supercupsAdmin): ?><tr><td colspan="4" class="text-center text-secondary py-4">Nenhuma Supercopa criada.</td></tr><?php endif; ?></tbody></table></div></div><div class="panel p-3 mt-4"><strong>Sugestões:</strong><span class="text-secondary"> Recopa, Derby das Américas, Desafio dos Campeões, Taça dos Gigantes ou Copa Intercontinental.</span></div></div></div></section>
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
<section id="tab-titulos" class="tab-pane fade"><div class="row g-4"><div class="col-lg-5"><form id="form-titulo" class="panel admin-form" method="post"><h2>Adicionar ou editar título</h2><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="titulo"><input type="hidden" name="titulo_id"><input type="hidden" name="titulo_imagem_base64"><div class="row g-2"><div class="col-md-4"><label class="form-label">Tipo de registro</label><select class="form-select" name="origem_titulo" id="origem_titulo"><option value="atual">Participante atual</option><option value="historico">Técnico histórico</option></select></div><div class="col-md-8 titulo-atual"><label class="form-label">Participante atual</label><select class="form-select" name="participante_id"><option value="">Selecione o técnico e o time</option><?= team_options($teams) ?></select></div><div class="col-md-6 titulo-historico d-none"><label class="form-label">Nome do técnico histórico</label><input class="form-control" name="tecnico_historico" placeholder="Ex.: Técnico da Season 1"></div><div class="col-md-6 titulo-historico d-none"><label class="form-label">Nome do time histórico (opcional)</label><input class="form-control" name="time_historico" placeholder="Deixe vazio se usavam apenas o técnico"></div><div class="col-md-5"><label class="form-label">Título conquistado</label><input class="form-control" name="titulo" placeholder="Ex.: Mundial de Clubes" required></div><div class="col-md-3"><label class="form-label">Season</label><select class="form-select" name="temporada" required><option value="Season 1">Season 1</option><option value="Season 2">Season 2</option><option value="Season 3" selected>Season 3</option></select></div><div class="col-md-4"><label class="form-label">Data da conquista</label><input class="form-control" type="date" name="conquistado_em"></div><div class="col-12"><label class="form-label">Descrição (opcional)</label><input class="form-control" name="descricao"></div><div class="col-12"><label class="form-label">Imagem própria do título (opcional)</label><div class="competition-image-preview title-image-preview"><img data-title-preview class="d-none" alt="Imagem do título"></div><input class="form-control form-control-sm title-art-file" type="file" data-art-type="titulo" accept="image/png,image/webp,image/jpeg"><small class="text-secondary">Ao selecionar, abre o editor de posição e zoom. Títulos iniciados por Mundial já recebem a taça automaticamente.</small></div></div><button class="btn btn-danger mt-3">Salvar título</button><button type="button" class="btn btn-outline-light mt-3 ms-2" data-title-cancel>Limpar</button></form></div><div class="col-lg-7"><div class="panel"><div class="panel-head"><h3>Títulos cadastrados</h3><span><?= count($titlesAdmin) ?> registros</span></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Imagem</th><th>Título</th><th>Técnico / time</th><th>Season</th><th>Ação</th></tr></thead><tbody><?php foreach ($titlesAdmin as $titleAdmin): ?><tr><td><?php if ($titleAdmin['tem_imagem']): ?><img class="admin-title-image" src="../api/titulo-imagem.php?titulo_id=<?= (int)$titleAdmin['id'] ?>" alt=""><?php elseif (competition_identity_match((string)$titleAdmin['titulo']) === 'mundial'): ?><img class="admin-title-image" src="../api/competicao-imagem.php?chave=mundial&tipo=trofeu" alt=""><?php else: ?><span class="text-secondary">:</span><?php endif; ?></td><td><strong><?= e($titleAdmin['titulo']) ?></strong></td><td><?= e(trim((string)$titleAdmin['tecnico'] . ($titleAdmin['clube'] ? ' / ' . $titleAdmin['clube']: ''))) ?></td><td><?= e($titleAdmin['temporada']) ?></td><td><button type="button" class="btn btn-sm btn-outline-warning editar-titulo" data-id="<?= (int)$titleAdmin['id'] ?>" data-participant="<?= (int)($titleAdmin['participante_id'] ?? 0) ?>" data-title="<?= e($titleAdmin['titulo']) ?>" data-season="<?= e($titleAdmin['temporada']) ?>" data-date="<?= e((string)$titleAdmin['conquistado_em']) ?>" data-description="<?= e((string)$titleAdmin['descricao']) ?>" data-coach="<?= e((string)$titleAdmin['tecnico_nome']) ?>" data-team="<?= e((string)$titleAdmin['time_nome']) ?>" data-has-image="<?= (int)$titleAdmin['tem_imagem'] ?>">Editar</button></td></tr><?php endforeach; ?></tbody></table></div></div></div></div></section>
<section id="tab-extra" class="tab-pane fade"><div class="row g-4"><div class="col-lg-5"><form id="form-artilharia" class="panel admin-form" method="post"><h2>Registrar ou editar artilheiro</h2><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="artilharia"><input type="hidden" name="artilheiro_id" value=""><div id="artilheiro-edicao" class="alert alert-info d-none justify-content-between align-items-center"><span></span><button type="button" class="btn btn-sm btn-outline-info cancelar-artilheiro">Cancelar edição</button></div><label class="form-label">Campeonato</label><select id="artilheiro-campeonato" class="form-select mb-2" name="campeonato_id" required><option value="">Selecione</option><?php foreach (
    $championshipsAdmin
    as $championship
): ?><option value="<?= $championship["id"] ?>"><?= e(
    $championship["nome"],
) ?>: <?= $championship["status"] === "ativo"
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
) ?>"><small class="text-secondary">Use os nomes separados por vírgula: noticias, competicao, participantes, artilharia, titulos, midia. A ordem controla a exibição; remova uma chave para ocultar a seção da página inicial e da navegação pública.</small></div></div><button class="btn btn-danger mt-3">Salvar configurações</button></form></section>
</div></div></main><div id="competition-art-editor" class="competition-art-editor" hidden><div class="competition-art-editor-card" role="dialog" aria-modal="true" aria-labelledby="competition-art-editor-title"><div class="d-flex justify-content-between align-items-start gap-3"><div><small class="eyebrow">Ajustar imagem</small><h3 id="competition-art-editor-title">ENQUADRAR ARTE</h3></div><button type="button" class="btn-close btn-close-white" data-art-cancel aria-label="Fechar"></button></div><p class="text-secondary small">Arraste para posicionar e use o controle para ampliar ou reduzir.</p><div class="competition-art-crop"><canvas width="720" height="480" aria-label="Prévia do enquadramento"></canvas></div><p class="competition-art-note"><strong>Tudo dentro da moldura será salvo.</strong> O quadriculado indica transparência; fundos sólidos ainda permanecem na imagem.</p><label class="form-label mt-3" for="competition-art-zoom">Zoom</label><input id="competition-art-zoom" class="form-range" type="range" min="0.5" max="3" value="1" step="0.01"><div class="d-flex justify-content-end gap-2 mt-3"><button type="button" class="btn btn-outline-light" data-art-cancel>Cancelar</button><button type="button" class="btn btn-danger" data-art-apply>Aplicar enquadramento</button></div></div></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script src="../assets/js/admin-loading.js?v=<?= filemtime(
    __DIR__ . "/../assets/js/admin-loading.js",
) ?>"></script><script src="../assets/js/news-editor.js?v=<?= filemtime(
    __DIR__ . "/../assets/js/news-editor.js",
) ?>"></script><script src="../assets/js/news-round-prompt.js?v=<?= filemtime(
    __DIR__ . "/../assets/js/news-round-prompt.js",
) ?>"></script><script src="../assets/js/sumula-importer.js?v=<?= filemtime(
    __DIR__ . "/../assets/js/sumula-importer.js",
) ?>"></script><script src="../assets/js/admin.js?v=<?= filemtime(
    __DIR__ . "/../assets/js/admin.js",
) ?>"></script><?php if (
    sync_user_allowed()
): ?><script src="../assets/js/sync-admin.js?v=<?= filemtime(
    __DIR__ . "/../assets/js/sync-admin.js",
) ?>"></script><?php endif; ?></body></html>
