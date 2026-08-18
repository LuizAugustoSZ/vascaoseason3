<?php

declare(strict_types=1);

// Inicia a sessão usada no login, CSRF e mensagens.
session_start();
$localConfig = __DIR__ . "/../config/config.php";
$config = require is_file($localConfig)
    ? $localConfig
    : __DIR__ . "/../config/config.example.php";
date_default_timezone_set($config["app"]["timezone"]);
require_once __DIR__ . '/competition-identities.php';

// Cria e reutiliza a conexão PDO com o MySQL.
function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = $config["db"];
    $dsn = "mysql:host={$db["host"]};port={$db["port"]};dbname={$db["name"]};charset={$db["charset"]}";
    $pdo = new PDO($dsn, $db["user"], $db["pass"], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $timezone = new DateTimeZone((string)$config["app"]["timezone"]);
    $offset = $timezone->getOffset(new DateTimeImmutable("now", $timezone));
    $sign = $offset < 0 ? "-" : "+";
    $offset = abs($offset);
    $pdo->exec("SET time_zone=" . $pdo->quote(sprintf("%s%02d:%02d", $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60))));
    return $pdo;
}

function format_datetime_br(string $value, string $format = "d/m/Y H:i"): string
{
    global $config;
    if ($value === "") return "";
    return (new DateTimeImmutable($value, new DateTimeZone((string)$config["app"]["timezone"])))->format($format);
}

// Escapa textos antes de exibir no HTML.
function e(?string $value): string
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

// Mantém uma trilha técnica enxuta das ações relevantes do sistema.
function audit_ensure_schema(): void
{
    static $ready = false;
    if ($ready) return;
    db()->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conta_id INT UNSIGNED NULL, conta_nome VARCHAR(120) NULL,
        evento VARCHAR(40) NOT NULL, modulo VARCHAR(60) NOT NULL,
        recurso_tipo VARCHAR(80) NULL, recurso_id VARCHAR(80) NULL,
        descricao VARCHAR(255) NOT NULL, detalhes_json JSON NULL,
        ip_hash CHAR(64) NULL, user_agent VARCHAR(255) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_audit_data (criado_em), KEY idx_audit_evento (evento, criado_em),
        KEY idx_audit_modulo (modulo, criado_em), KEY idx_audit_conta (conta_id, criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

function audit_event(string $evento, string $modulo, string $descricao, array $detalhes = []): void
{
    try {
        audit_ensure_schema();
        foreach (['csrf','senha','confirmar_senha','senha_atual','nova_senha','conteudo','capa_base64','sumula'] as $key) unset($detalhes[$key]);
        $resourceId = null;
        foreach (['id','conta_id','participante_id','campeonato_id','partida_id','jogo_mata_id','noticia_id','artilheiro_id','movimentacao_id'] as $key) {
            if (isset($detalhes[$key]) && (string)$detalhes[$key] !== '') { $resourceId = (string)$detalhes[$key]; break; }
        }
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $stmt = db()->prepare("INSERT INTO audit_logs(conta_id,conta_nome,evento,modulo,recurso_tipo,recurso_id,descricao,detalhes_json,ip_hash,user_agent) VALUES(?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            isset($_SESSION['conta_id']) ? (int)$_SESSION['conta_id'] : null, $_SESSION['conta_nome'] ?? null,
            mb_substr($evento, 0, 40), mb_substr($modulo, 0, 60),
            isset($detalhes['action']) ? mb_substr((string)$detalhes['action'], 0, 80) : null, $resourceId,
            mb_substr($descricao, 0, 255), $detalhes ? json_encode($detalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $ip !== '' ? hash('sha256', $ip) : null, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $ignored) {}
}

function audit_post_success(string $modulo, string $descricao): void
{
    $details = [];
    foreach ($_POST as $key => $value) {
        if (in_array($key, ['csrf','senha','confirmar_senha','senha_atual','nova_senha','conteudo','capa_base64','sumula'], true)) continue;
        if (is_scalar($value) && mb_strlen((string)$value) <= 160) $details[$key] = (string)$value;
        elseif (is_array($value)) $details[$key . '_total'] = count($value);
    }
    $action = (string)($_POST['action'] ?? 'salvar');
    $event = str_contains($action, 'desativar') || str_contains($action, 'desfazer') ? 'remocao' : (preg_match('/editar|atualizar|status|configur/', $action) ? 'edicao' : 'cadastro');
    audit_event($event, $modulo, $descricao, $details);
}

// Remove tags, atributos e URLs perigosas do conteúdo das notícias.
function sanitize_news_html(string $html): string
{
    if (trim($html) === "") {
        return "";
    }
    $document = new DOMDocument("1.0", "UTF-8");
    libxml_use_internal_errors(true);
    $document->loadHTML(
        '<?xml encoding="UTF-8"><div id="news-root">' . $html . "</div>",
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
    );
    libxml_clear_errors();
    $allowed = [
        "div",
        "p",
        "br",
        "h2",
        "h3",
        "strong",
        "b",
        "em",
        "i",
        "u",
        "ul",
        "ol",
        "li",
        "blockquote",
        "img",
        "a",
    ];
    $walk = function (DOMNode $node) use (&$walk, $allowed): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, $allowed, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $name = strtolower($attribute->name);
                    if (
                        ($tag === "img" &&
                            in_array($name, ["src", "alt"], true)) ||
                        ($tag === "a" && $name === "href")
                    ) {
                        continue;
                    }
                    $child->removeAttribute($attribute->name);
                }
                if (
                    $tag === "img" &&
                    !preg_match(
                        "#^data:image/(?:jpeg|png|webp|gif);base64,#i",
                        (string) $child->getAttribute("src"),
                    )
                ) {
                    $child->removeAttribute("src");
                }
                if ($tag === "a") {
                    $href = (string) $child->getAttribute("href");
                    if (!preg_match("#^https?://#i", $href)) {
                        $child->removeAttribute("href");
                    } else {
                        $child->setAttribute("target", "_blank");
                        $child->setAttribute("rel", "noopener noreferrer");
                    }
                }
            }
            $walk($child);
        }
    };
    $root = $document->getElementById("news-root");
    if (!$root) {
        return "";
    }
    $walk($root);
    $safe = "";
    foreach ($root->childNodes as $child) {
        $safe .= $document->saveHTML($child);
    }
    return $safe;
}

// Cria o token de proteção dos formulários.
function csrf_token(): string
{
    if (empty($_SESSION["csrf"])) {
        $_SESSION["csrf"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf"];
}

// Confere se o formulário veio da sessão atual.
function verify_csrf(): void
{
    $token = $_POST["csrf"] ?? ($_SERVER["HTTP_X_CSRF_TOKEN"] ?? "");
    if (!hash_equals($_SESSION["csrf"] ?? "", (string) $token)) {
        http_response_code(419);
        if (isset($_POST["_ajax"]) || str_contains(strtolower((string)($_SERVER["HTTP_ACCEPT"] ?? "")), "application/json")) {
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(["ok" => false, "message" => "Sessão expirada. Atualize a página e tente novamente."], JSON_UNESCAPED_UNICODE);
            exit();
        }
        exit("Sessão expirada. Atualize a página e tente novamente.");
    }
}

const REMEMBER_LOGIN_COOKIE = "vascao_remember";

function auth_fill_session(array $conta): void
{
    $_SESSION["conta_id"] = (int)$conta["id"];
    $_SESSION["conta_nome"] = $conta["nome"];
    $_SESSION["conta_email"] = $conta["email"];
    $_SESSION["conta_eh_admin"] = (int)$conta["eh_admin"];
    $_SESSION["conta_trocar_senha"] = (int)$conta["trocar_senha"];
    $_SESSION["participante_id"] = $conta["participante_id"] === null ? null : (int)$conta["participante_id"];
}

function auth_ensure_persistent_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS login_persistente (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conta_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expira_em DATETIME NOT NULL,
        criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_login_token (token_hash),
        KEY idx_login_conta (conta_id,expira_em),
        CONSTRAINT fk_login_conta FOREIGN KEY (conta_id) REFERENCES contas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function auth_set_remember_cookie(string $token, int $expires): void
{
    setcookie(REMEMBER_LOGIN_COOKIE, $token, [
        "expires" => $expires,
        "path" => "/",
        "secure" => !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
        "httponly" => true,
        "samesite" => "Lax",
    ]);
}

function auth_remember_login(int $contaId): void
{
    auth_ensure_persistent_table();
    $token = bin2hex(random_bytes(32));
    $expires = time() + 60 * 60 * 24 * 30;
    db()->prepare("DELETE FROM login_persistente WHERE expira_em<NOW()") ->execute();
    db()->prepare("INSERT INTO login_persistente(conta_id,token_hash,expira_em) VALUES(?,?,FROM_UNIXTIME(?))")
        ->execute([$contaId, hash("sha256", $token), $expires]);
    auth_set_remember_cookie($token, $expires);
}

function auth_forget_login(): void
{
    $token = (string)($_COOKIE[REMEMBER_LOGIN_COOKIE] ?? "");
    if ($token !== "") {
        try {
            auth_ensure_persistent_table();
            db()->prepare("DELETE FROM login_persistente WHERE token_hash=?")->execute([hash("sha256", $token)]);
        } catch (Throwable $ignored) {
        }
    }
    auth_set_remember_cookie("", time() - 3600);
}

function auth_restore_login(): void
{
    if (!empty($_SESSION["conta_id"])) return;
    $token = (string)($_COOKIE[REMEMBER_LOGIN_COOKIE] ?? "");
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return;
    try {
        auth_ensure_persistent_table();
        $stmt = db()->prepare("SELECT c.id,c.participante_id,c.nome,c.email,c.eh_admin,c.trocar_senha
            FROM login_persistente lp
            JOIN contas c ON c.id=lp.conta_id AND c.ativo=1
            WHERE lp.token_hash=? AND lp.expira_em>NOW() LIMIT 1");
        $stmt->execute([hash("sha256", $token)]);
        $conta = $stmt->fetch();
        if ($conta) {
            session_regenerate_id(true);
            auth_fill_session($conta);
            audit_event("login", "autenticacao", "Login persistente restaurado.");
            return;
        }
    } catch (Throwable $ignored) {
        return;
    }
    auth_set_remember_cookie("", time() - 3600);
}

auth_restore_login();

// Informa se existe uma conta autenticada na sessão atual.
function account_logged_in(): bool
{
    return !empty($_SESSION["conta_id"]);
}

// Atualiza o vínculo do clube a partir do banco para refletir associações sem exigir novo login.
function account_participant_id(): ?int
{
    if (!account_logged_in()) {
        return null;
    }
    static $participantId = false;
    if ($participantId !== false) {
        return $participantId;
    }
    try {
        $stmt = db()->prepare("SELECT participante_id FROM contas WHERE id=? AND ativo=1 LIMIT 1");
        $stmt->execute([(int)$_SESSION["conta_id"]]);
        $value = $stmt->fetchColumn();
        $participantId = $value === false || $value === null ? null : (int)$value;
        $_SESSION["participante_id"] = $participantId;
    } catch (Throwable $ignored) {
        $sessionValue = $_SESSION["participante_id"] ?? null;
        $participantId = $sessionValue === null ? null : (int)$sessionValue;
    }
    return $participantId;
}

// Informa se a conta ainda precisa substituir a senha temporária.
function account_must_change_password(): bool
{
    return !empty($_SESSION["conta_trocar_senha"]);
}

// Informa se a conta autenticada possui acesso administrativo.
function account_is_admin(): bool
{
    return in_array((int) ($_SESSION["conta_eh_admin"] ?? 0), [1, 2], true);
}

// Informa se a conta possui acesso irrestrito de Admin Master.
function account_is_master(): bool
{
    return (int) ($_SESSION["conta_eh_admin"] ?? 0) === 1;
}

// Informa se a conta é um Editor da Competição com acesso limitado.
function account_is_editor(): bool
{
    return (int) ($_SESSION["conta_eh_admin"] ?? 0) === 2;
}

// Bloqueia recursos exclusivos do Admin Master.
function master_required(): void
{
    admin_required();
    if (!account_is_master()) {
        http_response_code(403);
        exit("Este recurso é exclusivo do Admin Master.");
    }
}

// Bloqueia páginas administrativas sem uma conta do tipo administrador.
function admin_required(): void
{
    if (!account_logged_in()) {
        header("Location: ../login.php");
        exit();
    }

    if (account_must_change_password()) {
        header("Location: ../trocar-senha.php");
        exit();
    }

    if (!account_is_admin()) {
        http_response_code(403);
        exit("Esta conta não possui permissão para acessar a administração.");
    }
}

// Retorna JSON e encerra a API.
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// Calcula a classificação a partir dos jogos encerrados.
function standings(PDO $pdo, ?int $championshipId = null): array
{
    if ($championshipId === null) {
        return [];
    }
    $teamStmt = $pdo->prepare(
        "SELECT DISTINCT p.id,p.nome,p.time_nome,p.sigla,p.escudo_url FROM participantes p JOIN (SELECT mandante_id participante_id FROM partidas WHERE campeonato_id=? AND ativo=1 UNION SELECT visitante_id FROM partidas WHERE campeonato_id=? AND ativo=1) inscritos ON inscritos.participante_id=p.id WHERE p.ativo=1 ORDER BY p.time_nome",
    );
    $teamStmt->execute([$championshipId, $championshipId]);
    $teams = $teamStmt->fetchAll();
    $rows = [];
    foreach ($teams as $team) {
        $rows[(int) $team["id"]] = $team + [
            "j" => 0,
            "v" => 0,
            "e" => 0,
            "d" => 0,
            "gp" => 0,
            "gc" => 0,
            "sg" => 0,
            "pts" => 0,
        ];
    }
    $gameStmt = $pdo->prepare(
        "SELECT mandante_id,visitante_id,gols_mandante,gols_visitante FROM partidas WHERE campeonato_id=? AND ativo=1 AND status IN ('finalizada','wo')",
    );
    $gameStmt->execute([$championshipId]);
    $games = $gameStmt->fetchAll();
    foreach ($games as $game) {
        $home = (int) $game["mandante_id"];
        $away = (int) $game["visitante_id"];
        if (!isset($rows[$home], $rows[$away])) {
            continue;
        }
        $hg = (int) $game["gols_mandante"];
        $ag = (int) $game["gols_visitante"];
        $rows[$home]["j"]++;
        $rows[$away]["j"]++;
        $rows[$home]["gp"] += $hg;
        $rows[$home]["gc"] += $ag;
        $rows[$away]["gp"] += $ag;
        $rows[$away]["gc"] += $hg;
        if ($hg > $ag) {
            $rows[$home]["v"]++;
            $rows[$home]["pts"] += 3;
            $rows[$away]["d"]++;
        } elseif ($hg < $ag) {
            $rows[$away]["v"]++;
            $rows[$away]["pts"] += 3;
            $rows[$home]["d"]++;
        } else {
            $rows[$home]["e"]++;
            $rows[$away]["e"]++;
            $rows[$home]["pts"]++;
            $rows[$away]["pts"]++;
        }
    }
    foreach ($rows as &$row) {
        $row["sg"] = $row["gp"] - $row["gc"];
    }
    unset($row);
    $rows = array_values($rows);
    // Ordena por pontos, vitórias, saldo e gols marcados.
    usort($rows, function (array $a, array $b): int {
        foreach (["pts", "v", "sg", "gp"] as $key) {
            if ($a[$key] !== $b[$key]) {
                return $b[$key] <=> $a[$key];
            }
        }
        return $a["nome"] <=> $b["nome"];
    });
    foreach ($rows as $i => &$row) {
        $row["posicao"] = $i + 1;
    }
    return $rows;
}

// Retorna o campeão confirmado de qualquer competição que possa alimentar uma Supercopa.
function competition_champion_id(PDO $pdo, int $championshipId): ?int
{
    $stmt = $pdo->prepare("SELECT tipo,status FROM campeonatos WHERE id=? AND ativo=1");
    $stmt->execute([$championshipId]);
    $competition = $stmt->fetch();
    if (!$competition || $competition["status"] !== "finalizado") return null;
    if ($competition["tipo"] === "pontos_corridos") {
        $ranking = standings($pdo, $championshipId);
        return isset($ranking[0]["id"]) ? (int)$ranking[0]["id"] : null;
    }
    $winner = $pdo->prepare("SELECT vencedor_id FROM jogos_mata_mata WHERE campeonato_id=? AND fase='Final' AND ativo=1 AND status='finalizado' AND vencedor_id IS NOT NULL ORDER BY jogo DESC,id DESC LIMIT 1");
    $winner->execute([$championshipId]);
    $id = $winner->fetchColumn();
    return $id === false ? null : (int)$id;
}

// Completa de forma idempotente a migration da Supercopa em ambientes onde ela
// tenha sido executada apenas parcialmente.
function ensure_supercup_schema(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM campeonatos LIKE 'tipo'");
    $column = $stmt->fetch();
    if (!$column || !str_contains((string) $column["Type"], "'supercopa'")) {
        $pdo->exec("ALTER TABLE campeonatos MODIFY tipo ENUM('pontos_corridos','mata_mata','supercopa') NOT NULL");
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS supercopas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campeonato_id INT UNSIGNED NOT NULL,
            origem_a_campeonato_id INT UNSIGNED NOT NULL,
            origem_b_campeonato_id INT UNSIGNED NOT NULL,
            regra_mesmo_campeao ENUM('vice_origem_a','vice_origem_b') NOT NULL DEFAULT 'vice_origem_a',
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_supercopa_campeonato (campeonato_id),
            KEY idx_supercopa_origem_a (origem_a_campeonato_id),
            KEY idx_supercopa_origem_b (origem_b_campeonato_id),
            CONSTRAINT fk_supercopa_campeonato FOREIGN KEY (campeonato_id) REFERENCES campeonatos(id),
            CONSTRAINT fk_supercopa_origem_a FOREIGN KEY (origem_a_campeonato_id) REFERENCES campeonatos(id),
            CONSTRAINT fk_supercopa_origem_b FOREIGN KEY (origem_b_campeonato_id) REFERENCES campeonatos(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    );
}

function competition_runner_up_id(PDO $pdo, int $championshipId): ?int
{
    $stmt = $pdo->prepare("SELECT tipo,status FROM campeonatos WHERE id=? AND ativo=1");
    $stmt->execute([$championshipId]);
    $competition = $stmt->fetch();
    if (!$competition || $competition["status"] !== "finalizado") return null;
    if ($competition["tipo"] === "pontos_corridos") {
        $ranking = standings($pdo, $championshipId);
        return isset($ranking[1]["id"]) ? (int)$ranking[1]["id"] : null;
    }
    $final = $pdo->prepare("SELECT time_a_id,time_b_id,vencedor_id FROM jogos_mata_mata WHERE campeonato_id=? AND fase='Final' AND ativo=1 AND status='finalizado' AND vencedor_id IS NOT NULL ORDER BY jogo DESC,id DESC LIMIT 1");
    $final->execute([$championshipId]);
    $row = $final->fetch();
    if (!$row) return null;
    return (int)$row["time_a_id"] === (int)$row["vencedor_id"] ? (int)$row["time_b_id"] : (int)$row["time_a_id"];
}

// Preenche vagas pendentes sem recriar o confronto nem limitar a quantidade de Supercopas.
function sync_supercup_slots(PDO $pdo): void
{
    try {
        $rows = $pdo->query("SELECT s.*,c.formato FROM supercopas s JOIN campeonatos c ON c.id=s.campeonato_id WHERE c.ativo=1")->fetchAll();
    } catch (Throwable $ignored) {
        return; // Permite publicar o código antes de executar a migration.
    }
    foreach ($rows as $row) {
        $teamA = competition_champion_id($pdo, (int)$row["origem_a_campeonato_id"]);
        $teamB = competition_champion_id($pdo, (int)$row["origem_b_campeonato_id"]);
        if ($teamA && $teamB && $teamA === $teamB) {
            if ($row["regra_mesmo_campeao"] === "vice_origem_b") $teamB = competition_runner_up_id($pdo, (int)$row["origem_b_campeonato_id"]);
            else $teamA = competition_runner_up_id($pdo, (int)$row["origem_a_campeonato_id"]);
        }
        $games = $pdo->prepare("SELECT id,jogo,status FROM jogos_mata_mata WHERE campeonato_id=? AND fase='Final' AND ativo=1 ORDER BY jogo,id");
        $games->execute([(int)$row["campeonato_id"]]);
        foreach ($games->fetchAll() as $game) {
            if ($game["status"] === "finalizado") continue;
            $a = (int)$game["jogo"] === 2 ? $teamB : $teamA;
            $b = (int)$game["jogo"] === 2 ? $teamA : $teamB;
            $pdo->prepare("UPDATE jogos_mata_mata SET time_a_id=?,time_b_id=? WHERE id=?")->execute([$a ?: null, $b ?: null, (int)$game["id"]]);
        }
    }
}
