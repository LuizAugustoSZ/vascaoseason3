<?php

declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/public-layout.php';
require __DIR__ . '/includes/mercado.php';
if (!account_logged_in()) {
    header('Location: login.php');
    exit;
}
if (account_must_change_password()) {
    header('Location: trocar-senha.php');
    exit;
}
$pdo = db();
try {
    mercado_garantir_estrutura($pdo);
} catch (Throwable $migrationError) {
    http_response_code(503);
    exit('O módulo de mercado está sendo preparado na homologação. Tente novamente em instantes.');
}
$participantId = (int)($_SESSION['participante_id'] ?? 0);
$message = $error = '';
$campeonatoId = (int)($_GET['campeonato_id'] ?? $_POST['campeonato_id'] ?? 0);
$campeonatos = $pdo->query("SELECT id,nome FROM campeonatos WHERE ativo=1 AND tipo='pontos_corridos' ORDER BY status='ativo' DESC,id DESC")->fetchAll();
if (!$campeonatoId && $campeonatos) $campeonatoId = (int)$campeonatos[0]['id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (!$participantId) throw new RuntimeException('Sua conta precisa estar vinculada a um time.');
        $rodada = mercado_rodada_atual($pdo, $campeonatoId);
        $clube = mercado_clube($pdo, $campeonatoId, $participantId);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'configurar_inicial') {
            if ((bool)$clube['elenco_confirmado']) throw new RuntimeException('O elenco inicial já foi confirmado.');
            $saldo = mercado_parse_valor((string)($_POST['saldo'] ?? '0'));
            $formacao = (string)($_POST['formacao'] ?? '4-3-3');
            if ($saldo < 0 || !in_array($formacao, MERCADO_FORMACOES, true)) throw new RuntimeException('Configuração inicial inválida.');
            $pdo->prepare("UPDATE clubes_campeonato SET saldo=?,formacao=? WHERE campeonato_id=? AND participante_id=?")->execute([$saldo, $formacao, $campeonatoId, $participantId]);
            $message = 'Cofre e formação configurados.';
        } elseif ($action === 'adicionar_inicial') {
            if ((bool)$clube['elenco_confirmado']) throw new RuntimeException('O elenco está travado. Aguarde a janela.');
            salvar_jogador($pdo, $campeonatoId, $participantId, $_POST);
            $message = 'Jogador adicionado ao elenco inicial.';
        } elseif ($action === 'confirmar_elenco') {
            $total = contar_titulares($pdo, $campeonatoId, $participantId);
            if ($total !== 11) throw new RuntimeException('Defina exatamente 11 titulares antes de confirmar.');
            $pdo->prepare("UPDATE clubes_campeonato SET elenco_confirmado=1 WHERE campeonato_id=? AND participante_id=?")->execute([$campeonatoId, $participantId]);
            $message = 'Elenco confirmado e ciclo iniciado.';
        } elseif ($action === 'atualizar_escalacao') {
            if (!mercado_pode_editar($clube, $rodada)) throw new RuntimeException('A escalação está travada nesta rodada.');
            $formacao = (string)($_POST['formacao'] ?? '');
            if (!in_array($formacao, MERCADO_FORMACOES, true)) throw new RuntimeException('Formação inválida.');
            $ids = $_POST['jogador_id'] ?? [];
            $grupos = $_POST['grupo'] ?? [];
            $ordens = $_POST['ordem'] ?? [];
            $pdo->beginTransaction();
            $update = $pdo->prepare("UPDATE jogadores_elenco SET grupo=?,ordem=? WHERE id=? AND campeonato_id=? AND participante_id=? AND ativo=1");
            foreach ($ids as $i => $id) {
                $grupo = (string)($grupos[$i] ?? 'banco');
                if (!in_array($grupo, ['titular', 'banco'], true)) $grupo = 'banco';
                $update->execute([$grupo, max(1, (int)($ordens[$i] ?? 1)), (int)$id, $campeonatoId, $participantId]);
            }
            if (contar_titulares($pdo, $campeonatoId, $participantId) !== 11) throw new RuntimeException('A escalação precisa ter exatamente 11 titulares.');
            $pdo->prepare("UPDATE clubes_campeonato SET formacao=? WHERE id=?")->execute([$formacao, $clube['id']]);
            $pdo->commit();
            $message = 'Escalação atualizada.';
        } elseif (in_array($action, ['comprar', 'vender'], true)) {
            if (!mercado_pode_editar($clube, $rodada) || !(bool)$clube['elenco_confirmado']) throw new RuntimeException('O elenco está travado nesta rodada.');
            $pdo->beginTransaction();
            $clube = mercado_clube($pdo, $campeonatoId, $participantId, true);
            $antes = (float)$clube['saldo'];
            $valor = mercado_parse_valor((string)($_POST['valor'] ?? '0'));
            if ($valor < 0) throw new RuntimeException('Informe um valor válido.');
            if ($action === 'comprar') {
                if ($valor > $antes) throw new RuntimeException('Saldo insuficiente no cofre.');
                $jogador = salvar_jogador($pdo, $campeonatoId, $participantId, $_POST);
                $depois = $antes - $valor;
            } else {
                $jogador = (int)($_POST['jogador_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM jogadores_elenco WHERE id=? AND campeonato_id=? AND participante_id=? AND ativo=1 FOR UPDATE");
                $stmt->execute([$jogador, $campeonatoId, $participantId]);
                $dados = $stmt->fetch();
                if (!$dados) throw new RuntimeException('Jogador não encontrado no seu elenco.');
                $pdo->prepare("UPDATE jogadores_elenco SET ativo=0,saiu_em=NOW() WHERE id=?")->execute([$jogador]);
                $depois = $antes + $valor;
                $_POST = $dados + $_POST;
            }
            $pdo->prepare("UPDATE clubes_campeonato SET saldo=? WHERE id=?")->execute([$depois, $clube['id']]);
            $pdo->prepare("INSERT INTO movimentacoes_elenco(campeonato_id,participante_id,jogador_id,tipo,jogador_nome,jogador_overall,jogador_posicao,valor,saldo_anterior,saldo_posterior,rodada,conta_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$campeonatoId, $participantId, $jogador, $action === 'comprar' ? 'compra' : 'venda', trim((string)$_POST['nome']), (int)$_POST['overall'], (string)$_POST['posicao'], $valor, $antes, $depois, $rodada, (int)$_SESSION['conta_id']]);
            $pdo->commit();
            $message = $action === 'comprar' ? 'Contratação registrada.' : 'Venda registrada.';
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
}

function salvar_jogador(PDO $pdo, int $campeonato, int $participante, array $data): int
{
    $nome = trim((string)($data['nome'] ?? ''));
    $overall = (int)($data['overall'] ?? 0);
    $posicao = (string)($data['posicao'] ?? '');
    $grupo = (string)($data['grupo'] ?? 'banco');
    if ($nome === '' || $overall < 1 || $overall > 99 || !in_array($posicao, MERCADO_POSICOES, true) || !in_array($grupo, ['titular', 'banco'], true)) throw new RuntimeException('Preencha nome, overall, posição e grupo corretamente.');
    $stmt = $pdo->prepare("INSERT INTO jogadores_elenco(campeonato_id,participante_id,nome,overall,posicao,grupo,ordem) VALUES(?,?,?,?,?,?,?)");
    $stmt->execute([$campeonato, $participante, $nome, $overall, $posicao, $grupo, max(1, (int)($data['ordem'] ?? 1))]);
    return (int)$pdo->lastInsertId();
}
function contar_titulares(PDO $pdo, int $campeonato, int $participante): int
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM jogadores_elenco WHERE campeonato_id=? AND participante_id=? AND ativo=1 AND grupo='titular'");
    $s->execute([$campeonato, $participante]);
    return (int)$s->fetchColumn();
}

$clube = $participantId && $campeonatoId ? mercado_clube($pdo, $campeonatoId, $participantId) : null;
$rodada = $campeonatoId ? mercado_rodada_atual($pdo, $campeonatoId) : 1;
$ciclo = mercado_estado_ciclo($rodada);
$elenco = [];
$historico = [];
if ($clube) {
    $s = $pdo->prepare("SELECT * FROM jogadores_elenco WHERE campeonato_id=? AND participante_id=? AND ativo=1 ORDER BY grupo='titular' DESC,ordem,nome");
    $s->execute([$campeonatoId, $participantId]);
    $elenco = $s->fetchAll();
    $s = $pdo->prepare("SELECT * FROM movimentacoes_elenco WHERE campeonato_id=? AND participante_id=? ORDER BY id DESC LIMIT 20");
    $s->execute([$campeonatoId, $participantId]);
    $historico = $s->fetchAll();
}
?>
<!doctype html>
<html lang="pt-BR" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Meu elenco | Vascão S3</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/market.css">
</head>

<body><?php public_navbar('mercado'); ?><main class="container market-page"><span class="eyebrow">Gestão do clube</span>
        <h1>ELENCO E COFRE</h1><?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?><form method="get" class="mb-4"><select class="form-select" name="campeonato_id" onchange="this.form.submit()"><?php foreach ($campeonatos as $c): ?><option value="<?= $c['id'] ?>" <?= $campeonatoId === $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></form>
        <?php if (!$participantId): ?><div class="panel p-4">A conta precisa estar associada a um time.</div><?php elseif ($clube): ?><section class="market-summary">
                <div><small>Cofre</small><strong>R$ <?= number_format((float)$clube['saldo'], 2, ',', '.') ?></strong></div>
                <div><small>Rodada</small><strong><?= $rodada ?></strong></div>
                <div><small>Ciclo <?= $ciclo['ciclo'] ?></small><strong><?= $ciclo['aberto'] ? 'ALTERAÇÕES LIBERADAS' : 'ELENCO TRAVADO' ?></strong></div>
                <div><small>Formação</small><strong><?= e($clube['formacao']) ?></strong></div>
            </section>
            <?php if (!(bool)$clube['elenco_confirmado']): ?><section class="panel p-4 mb-4">
                    <h2>CONFIGURAÇÃO INICIAL</h2>
                    <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="configurar_inicial">
                        <div class="col-md-6"><label class="form-label">Saldo inicial</label><input class="form-control" type="number" step=".01" min="0" name="saldo" value="<?= $clube['saldo'] ?>"></div>
                        <div class="col-md-6"><label class="form-label">Formação</label><select class="form-select" name="formacao"><?php foreach (MERCADO_FORMACOES as $f): ?><option <?= $clube['formacao'] === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?></select></div>
                        <div><button class="btn btn-danger">Salvar configuração</button></div>
                    </form>
                </section><?php endif; ?>
            <?php if (mercado_pode_editar($clube, $rodada)): ?><section class="panel p-4 mb-4">
                    <h2><?= !(bool)$clube['elenco_confirmado'] ? 'ADICIONAR JOGADOR' : 'CONTRATAR JOGADOR' ?></h2>
                    <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="<?= !(bool)$clube['elenco_confirmado'] ? 'adicionar_inicial' : 'comprar' ?>">
                        <div class="col-md-4"><input class="form-control" name="nome" placeholder="Nome do jogador" required></div>
                        <div class="col-md-2"><input class="form-control" type="number" min="1" max="99" name="overall" placeholder="Overall" required></div>
                        <div class="col-md-2"><select class="form-select" name="posicao"><?php foreach (MERCADO_POSICOES as $p): ?><option><?= $p ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><select class="form-select" name="grupo">
                                <option value="titular">Titular</option>
                                <option value="banco">Banco</option>
                            </select></div><?php if ((bool)$clube['elenco_confirmado']): ?><div class="col-md-2"><input class="form-control" type="number" step=".01" min="0" name="valor" placeholder="Valor" required></div><?php endif; ?><div><button class="btn btn-danger">Salvar jogador</button></div>
                    </form>
                </section><?php endif; ?>
            <section class="panel p-4 mb-4">
                <div class="d-flex justify-content-between">
                    <h2>ELENCO</h2><?php if (!(bool)$clube['elenco_confirmado']): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="confirmar_elenco"><button class="btn btn-success">Confirmar 11 titulares</button></form><?php endif; ?>
                </div><?php if (mercado_pode_editar($clube, $rodada)): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="atualizar_escalacao"><label class="form-label">Formação</label><select class="form-select mb-3" name="formacao"><?php foreach (MERCADO_FORMACOES as $f): ?><option <?= $clube['formacao'] === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?></select>
                        <div class="roster-grid"><?php foreach ($elenco as $j): ?><article><input type="hidden" name="jogador_id[]" value="<?= $j['id'] ?>"><b><?= e($j['nome']) ?></b><strong><?= $j['overall'] ?></strong><span><?= e($j['posicao']) ?></span><select class="form-select form-select-sm" name="grupo[]">
                                        <option value="titular" <?= $j['grupo'] === 'titular' ? 'selected' : '' ?>>Titular</option>
                                        <option value="banco" <?= $j['grupo'] === 'banco' ? 'selected' : '' ?>>Banco</option>
                                    </select><input class="form-control form-control-sm mt-2" type="number" min="1" max="99" name="ordem[]" value="<?= $j['ordem'] ?>" aria-label="Ordem"></article><?php endforeach; ?></div><button class="btn btn-danger mt-3">Salvar escalação</button>
                    </form><?php else: ?><div class="roster-grid"><?php foreach ($elenco as $j): ?><article><b><?= e($j['nome']) ?></b><strong><?= $j['overall'] ?></strong><span><?= e($j['posicao']) ?> · <?= e($j['grupo']) ?></span></article><?php endforeach; ?></div><?php endif; ?><?php if ((bool)$clube['elenco_confirmado'] && $ciclo['aberto']): ?>
                    <hr>
                    <h2>VENDER JOGADOR</h2>
                    <form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="vender">
                        <div class="col-md-7"><select class="form-select" name="jogador_id"><?php foreach ($elenco as $j): ?><option value="<?= $j['id'] ?>"><?= e($j['nome']) ?> · <?= $j['overall'] ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-3"><input class="form-control" type="number" step=".01" min="0" name="valor" placeholder="Valor da venda" required></div>
                        <div class="col-md-2"><button class="btn btn-outline-danger w-100">Vender</button></div>
                    </form><?php endif; ?>
            </section>
            <section class="panel p-4">
                <h2>HISTÓRICO</h2><?php foreach ($historico as $m): ?><p class="mb-2"><strong><?= e($m['jogador_nome']) ?></strong> · <?= e($m['tipo']) ?> · R$ <?= number_format((float)$m['valor'], 2, ',', '.') ?> · rodada <?= $m['rodada'] ?></p><?php endforeach; ?><?php if (!$historico): ?><p class="text-secondary">Nenhuma movimentação.</p><?php endif; ?>
            </section><?php endif; ?>
    </main><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>