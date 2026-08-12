<?php
declare(strict_types=1);

// Inicia a sessão usada no login, CSRF e mensagens.
session_start();
$localConfig = __DIR__ . '/../config/config.php';
$config = require (is_file($localConfig)
    ? $localConfig
    : __DIR__ . '/../config/config.example.php');
date_default_timezone_set($config['app']['timezone']);

// Cria e reutiliza a conexão PDO com o MySQL.
function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;

    $db = $config['db'];
    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

// Escapa textos antes de exibir no HTML.
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Remove tags, atributos e URLs perigosas do conteúdo das notícias.
function sanitize_news_html(string $html): string
{
    if (trim($html)==='') return '';
    $document=new DOMDocument('1.0','UTF-8');
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="news-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $allowed=['div','p','br','h2','h3','strong','b','em','i','u','ul','ol','li','blockquote','img','a'];
    $walk=function(DOMNode $node) use (&$walk,$allowed): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag=strtolower($child->tagName);
                if (!in_array($tag,$allowed,true)) {
                    while ($child->firstChild) $node->insertBefore($child->firstChild,$child);
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $name=strtolower($attribute->name);
                    if (($tag==='img' && in_array($name,['src','alt'],true)) || ($tag==='a' && $name==='href')) continue;
                    $child->removeAttribute($attribute->name);
                }
                if ($tag==='img' && !preg_match('#^data:image/(?:jpeg|png|webp|gif);base64,#i',(string)$child->getAttribute('src'))) $child->removeAttribute('src');
                if ($tag==='a') {
                    $href=(string)$child->getAttribute('href');
                    if (!preg_match('#^https?://#i',$href)) $child->removeAttribute('href');
                    else { $child->setAttribute('target','_blank'); $child->setAttribute('rel','noopener noreferrer'); }
                }
            }
            $walk($child);
        }
    };
    $root=$document->getElementById('news-root');
    if (!$root) return '';
    $walk($root);
    $safe=''; foreach ($root->childNodes as $child) $safe.=$document->saveHTML($child);
    return $safe;
}

// Cria o token de proteção dos formulários.
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

// Confere se o formulário veio da sessão atual.
function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) $token)) {
        http_response_code(419);
        exit('Sessão expirada. Atualize a página e tente novamente.');
    }
}

// Informa se existe uma conta autenticada na sessão atual.
function account_logged_in(): bool
{
    return !empty($_SESSION['conta_id']);
}

// Informa se a conta ainda precisa substituir a senha temporária.
function account_must_change_password(): bool
{
    return !empty($_SESSION['conta_trocar_senha']);
}

// Informa se a conta autenticada possui acesso administrativo.
function account_is_admin(): bool
{
    return in_array((int)($_SESSION['conta_eh_admin'] ?? 0), [1, 2], true);
}

// Informa se a conta possui acesso irrestrito de Admin Master.
function account_is_master(): bool
{
    return (int)($_SESSION['conta_eh_admin'] ?? 0) === 1;
}

// Informa se a conta é um Editor da Competição com acesso limitado.
function account_is_editor(): bool
{
    return (int)($_SESSION['conta_eh_admin'] ?? 0) === 2;
}

// Bloqueia recursos exclusivos do Admin Master.
function master_required(): void
{
    admin_required();
    if (!account_is_master()) {
        http_response_code(403);
        exit('Este recurso é exclusivo do Admin Master.');
    }
}

// Bloqueia páginas administrativas sem uma conta do tipo administrador.
function admin_required(): void
{
    if (!account_logged_in()) {
        header('Location: ../login.php');
        exit;
    }

    if (account_must_change_password()) {
        header('Location: ../trocar-senha.php');
        exit;
    }

    if (!account_is_admin()) {
        http_response_code(403);
        exit('Esta conta não possui permissão para acessar a administração.');
    }
}

// Retorna JSON e encerra a API.
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Calcula a classificação a partir dos jogos encerrados.
function standings(PDO $pdo, ?int $championshipId=null): array
{
    if($championshipId===null)return [];
    $teamStmt=$pdo->prepare("SELECT DISTINCT p.id,p.nome,p.time_nome,p.sigla,p.escudo_url FROM participantes p JOIN (SELECT mandante_id participante_id FROM partidas WHERE campeonato_id=? AND ativo=1 UNION SELECT visitante_id FROM partidas WHERE campeonato_id=? AND ativo=1) inscritos ON inscritos.participante_id=p.id WHERE p.ativo=1 ORDER BY p.time_nome");
    $teamStmt->execute([$championshipId,$championshipId]);$teams=$teamStmt->fetchAll();
    $rows = [];
    foreach ($teams as $team) {
        $rows[(int)$team['id']] = $team + ['j'=>0,'v'=>0,'e'=>0,'d'=>0,'gp'=>0,'gc'=>0,'sg'=>0,'pts'=>0];
    }
    $gameStmt=$pdo->prepare("SELECT mandante_id,visitante_id,gols_mandante,gols_visitante FROM partidas WHERE campeonato_id=? AND ativo=1 AND status IN ('finalizada','wo')");$gameStmt->execute([$championshipId]);$games=$gameStmt->fetchAll();
    foreach ($games as $game) {
        $home = (int)$game['mandante_id']; $away = (int)$game['visitante_id'];
        if (!isset($rows[$home], $rows[$away])) continue;
        $hg = (int)$game['gols_mandante']; $ag = (int)$game['gols_visitante'];
        $rows[$home]['j']++; $rows[$away]['j']++;
        $rows[$home]['gp'] += $hg; $rows[$home]['gc'] += $ag;
        $rows[$away]['gp'] += $ag; $rows[$away]['gc'] += $hg;
        if ($hg > $ag) { $rows[$home]['v']++; $rows[$home]['pts'] += 3; $rows[$away]['d']++; }
        elseif ($hg < $ag) { $rows[$away]['v']++; $rows[$away]['pts'] += 3; $rows[$home]['d']++; }
        else { $rows[$home]['e']++; $rows[$away]['e']++; $rows[$home]['pts']++; $rows[$away]['pts']++; }
    }
    foreach ($rows as &$row) $row['sg'] = $row['gp'] - $row['gc'];
    unset($row);
    $rows = array_values($rows);
    // Ordena por pontos, vitórias, saldo e gols marcados.
    usort($rows, function (array $a, array $b): int {
        foreach (['pts', 'v', 'sg', 'gp'] as $key) {
            if ($a[$key] !== $b[$key]) return $b[$key] <=> $a[$key];
        }
        return $a['nome'] <=> $b['nome'];
    });
    foreach ($rows as $i => &$row) $row['posicao'] = $i + 1;
    return $rows;
}
