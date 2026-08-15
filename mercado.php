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
$sessionParticipantId = (int)(account_participant_id() ?? 0);
$requestedParticipantId = (int)($_GET['participante_id'] ?? $_POST['participante_id'] ?? 0);
$participantId = account_is_master() && $requestedParticipantId > 0
    ? $requestedParticipantId
    : $sessionParticipantId;
$managedTeam = null;
if ($participantId > 0) {
    $teamStmt = $pdo->prepare("SELECT id,time_nome,nome FROM participantes WHERE id=? AND ativo=1 LIMIT 1");
    $teamStmt->execute([$participantId]);
    $managedTeam = $teamStmt->fetch() ?: null;
    if (!$managedTeam) {
        http_response_code(404);
        exit('Clube não encontrado.');
    }
}
$isMasterManagement = account_is_master() && $participantId !== $sessionParticipantId;
$message = $error = '';
$campeonatoId = (int)($_GET['campeonato_id'] ?? $_POST['campeonato_id'] ?? 0);
$campeonatos = $pdo->query("SELECT id,nome FROM campeonatos WHERE ativo=1 AND tipo='pontos_corridos' ORDER BY status='ativo' DESC,id DESC")->fetchAll();
if (!$campeonatoId && $campeonatos) $campeonatoId = (int)$campeonatos[0]['id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (!$participantId) throw new RuntimeException('Sua conta precisa estar vinculada a um time.');
        $rodada = mercado_rodada_atual($pdo, $campeonatoId, $participantId);
        $clube = mercado_clube($pdo, $campeonatoId, $participantId);
        if (!(bool)($clube['cofre_configurado'] ?? false)) {
            throw new RuntimeException('Informe primeiro o saldo inicial usando o lápis do Cofre do clube.');
        }
        $montagemInicial = !(bool)$clube['elenco_confirmado'] && $rodada === 1;
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'importar_elenco_campeonato') {
            if (!$montagemInicial) throw new RuntimeException('A importação de outro campeonato está disponível somente na montagem inicial.');
            $campeonatoOrigemId = (int)($_POST['campeonato_origem_id'] ?? 0);
            if ($campeonatoOrigemId < 1 || $campeonatoOrigemId === $campeonatoId) throw new RuntimeException('Selecione um campeonato de origem válido.');
            $origem = $pdo->prepare("SELECT c.nome,COUNT(j.id) total
                FROM campeonatos c
                JOIN jogadores_elenco j ON j.campeonato_id=c.id AND j.participante_id=? AND j.ativo=1
                WHERE c.id=? AND c.ativo=1 GROUP BY c.id,c.nome");
            $origem->execute([$participantId, $campeonatoOrigemId]);
            $dadosOrigem = $origem->fetch();
            if (!$dadosOrigem || (int)$dadosOrigem['total'] < 1) throw new RuntimeException('Esse clube não possui jogadores ativos no campeonato escolhido.');

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE jogadores_elenco SET ativo=0,saiu_em=NOW() WHERE campeonato_id=? AND participante_id=? AND ativo=1")
                ->execute([$campeonatoId, $participantId]);
            $copiar = $pdo->prepare("INSERT INTO jogadores_elenco(campeonato_id,participante_id,nome,overall,posicao,grupo,ordem)
                SELECT ?,participante_id,nome,overall,posicao,'banco',ordem
                FROM jogadores_elenco WHERE campeonato_id=? AND participante_id=? AND ativo=1 ORDER BY ordem,id");
            $copiar->execute([$campeonatoId, $campeonatoOrigemId, $participantId]);
            mercado_ordenar_elenco($pdo, $campeonatoId, $participantId);
            $pdo->prepare("UPDATE clubes_campeonato SET elenco_confirmado=0 WHERE id=?")->execute([$clube['id']]);
            $pdo->commit();
            $message = sprintf('%d jogadores importados de %s. Agora escolha os 11 titulares.', (int)$dadosOrigem['total'], (string)$dadosOrigem['nome']);
        } elseif ($action === 'configurar_inicial') {
            if (!$montagemInicial) throw new RuntimeException('A configuração inicial só pode ser alterada antes da primeira rodada.');
            $formacao = mercado_normalizar_formacao((string)($_POST['formacao'] ?? '4-3-3'), (string)($_POST['formacao_custom'] ?? ''));
            $pdo->prepare("UPDATE clubes_campeonato SET formacao=? WHERE campeonato_id=? AND participante_id=?")->execute([$formacao, $campeonatoId, $participantId]);
            $message = 'Formação inicial configurada.';
        } elseif ($action === 'confirmar_elenco') {
            if (!mercado_pode_editar($clube, $rodada)) throw new RuntimeException('O elenco está travado nesta rodada. Só é possível visualizar.');
            $total = contar_titulares($pdo, $campeonatoId, $participantId);
            if ($total !== 11) throw new RuntimeException('Defina exatamente 11 titulares antes de confirmar.');
            mercado_validar_titulares_formacao($pdo, $campeonatoId, $participantId, (string)$clube['formacao']);
            $pdo->prepare("UPDATE clubes_campeonato SET elenco_confirmado=1 WHERE campeonato_id=? AND participante_id=?")->execute([$campeonatoId, $participantId]);
            $message = 'Elenco confirmado e ciclo iniciado.';
        } elseif ($action === 'atualizar_escalacao') {
            if (!mercado_pode_editar($clube, $rodada)) throw new RuntimeException('A escalação está travada nesta rodada.');
            $formacao = mercado_normalizar_formacao((string)($_POST['formacao'] ?? ''), (string)($_POST['formacao_custom'] ?? ''));
            $titulares = array_values(array_unique(array_filter(
                array_map('intval', (array)($_POST['titular_id'] ?? [])),
                static fn(int $id): bool => $id > 0,
            )));
            if (count($titulares) !== 11) throw new RuntimeException('Selecione exatamente 11 titulares. Todos os demais serão definidos como banco.');

            $placeholders = implode(',', array_fill(0, count($titulares), '?'));
            $validarTitulares = $pdo->prepare("SELECT COUNT(*) FROM jogadores_elenco WHERE campeonato_id=? AND participante_id=? AND ativo=1 AND id IN ($placeholders)");
            $validarTitulares->execute([$campeonatoId, $participantId, ...$titulares]);
            if ((int)$validarTitulares->fetchColumn() !== 11) {
                throw new RuntimeException('Um dos titulares selecionados não está mais no elenco ativo. Atualize a página e selecione os 11 novamente.');
            }

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE jogadores_elenco SET grupo='banco' WHERE campeonato_id=? AND participante_id=? AND ativo=1")
                ->execute([$campeonatoId, $participantId]);
            $definirTitulares = $pdo->prepare("UPDATE jogadores_elenco SET grupo='titular' WHERE campeonato_id=? AND participante_id=? AND ativo=1 AND id IN ($placeholders)");
            $definirTitulares->execute([$campeonatoId, $participantId, ...$titulares]);
            if (contar_titulares($pdo, $campeonatoId, $participantId) !== 11) throw new RuntimeException('A escalação precisa ter exatamente 11 titulares.');
            mercado_validar_titulares_formacao($pdo, $campeonatoId, $participantId, $formacao);
            mercado_ordenar_elenco($pdo, $campeonatoId, $participantId);
            $pdo->prepare("UPDATE clubes_campeonato SET formacao=? WHERE id=?")->execute([$formacao, $clube['id']]);
            $confirmarAposSalvar = !(bool)$clube['elenco_confirmado'] && isset($_POST['confirmar_elenco']);
            if ($confirmarAposSalvar) {
                $pdo->prepare("UPDATE clubes_campeonato SET elenco_confirmado=1 WHERE id=?")->execute([$clube['id']]);
            }
            $pdo->commit();
            $message = $confirmarAposSalvar ? 'Escalação salva e elenco confirmado.' : 'Escalação atualizada.';
        } elseif (in_array($action, ['comprar', 'vender'], true)) {
            if (!mercado_pode_editar($clube, $rodada) || $montagemInicial) throw new RuntimeException('O mercado está indisponível nesta rodada.');
            $pdo->beginTransaction();
            $cofres = $pdo->prepare("SELECT * FROM clubes_campeonato WHERE participante_id=? ORDER BY id FOR UPDATE");
            $cofres->execute([$participantId]);
            foreach ($cofres->fetchAll() as $cofreDoCampeonato) {
                if ((int)$cofreDoCampeonato['campeonato_id'] === $campeonatoId) $clube = $cofreDoCampeonato;
            }
            $antes = (float)$clube['saldo'];
            $origem = 'venda';
            $origemDetalhe = null;
            $valorOrigem = null;
            $moedaOrigem = null;
            if ($action === 'comprar') {
                $origem = (string)($_POST['origem'] ?? 'compra_direta');
                if (!in_array($origem, ['compra_direta', 'pack', 'passe', 'sorteio', 'prancheta'], true)) throw new RuntimeException('Selecione uma origem válida para o jogador.');
                $valor = 0.0;
                if ($origem === 'compra_direta') {
                    $valor = mercado_parse_valor((string)($_POST['valor'] ?? ''));
                } elseif ($origem === 'pack') {
                    $packId = (string)($_POST['pack'] ?? '');
                    $pack = MERCADO_PACKS[$packId] ?? null;
                    if (!$pack) throw new RuntimeException('Selecione o pack recebido.');
                    $overall = (int)($_POST['overall'] ?? 0);
                    if ($overall < $pack['min'] || $overall > $pack['max']) {
                        throw new RuntimeException(sprintf('%s aceita jogadores com OVR entre %d e %d.', $pack['nome'], $pack['min'], $pack['max']));
                    }
                    $origemDetalhe = $pack['nome'];
                    $valorOrigem = (float)$pack['dream_points'];
                    $moedaOrigem = 'DreamPoints';
                }
                if ($valor > $antes) throw new RuntimeException('Saldo insuficiente no cofre.');
                if (($_POST['grupo'] ?? 'banco') === 'titular') {
                    $totalTitularesAntes = contar_titulares($pdo, $campeonatoId, $participantId);
                    if ($totalTitularesAntes > 11) {
                        throw new RuntimeException("A escalação já possui $totalTitularesAntes titulares. Corrija-a para 11 antes de contratar outro titular.");
                    }
                    if ($totalTitularesAntes === 11) {
                        $substituidoId = (int)($_POST['substituir_titular_id'] ?? 0);
                        $substituido = $pdo->prepare("SELECT id FROM jogadores_elenco WHERE id=? AND campeonato_id=? AND participante_id=? AND ativo=1 AND grupo='titular' FOR UPDATE");
                        $substituido->execute([$substituidoId, $campeonatoId, $participantId]);
                        if (!$substituido->fetchColumn()) {
                            throw new RuntimeException('Escolha qual titular será substituído pelo novo jogador.');
                        }
                        $pdo->prepare("UPDATE jogadores_elenco SET grupo='banco' WHERE id=?")->execute([$substituidoId]);
                    }
                }
                $jogador = salvar_jogador($pdo, $campeonatoId, $participantId, $_POST);
                if (contar_titulares($pdo, $campeonatoId, $participantId) > 11) {
                    throw new RuntimeException('A contratação não pode deixar a escalação com mais de 11 titulares.');
                }
                $depois = $antes - $valor;
            } else {
                $valor = mercado_parse_valor((string)($_POST['valor'] ?? ''));
                $jogador = (int)($_POST['jogador_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM jogadores_elenco WHERE id=? AND campeonato_id=? AND participante_id=? AND ativo=1 AND grupo='banco' FOR UPDATE");
                $stmt->execute([$jogador, $campeonatoId, $participantId]);
                $dados = $stmt->fetch();
                if (!$dados) throw new RuntimeException('Somente jogadores que estão no banco de reservas podem ser vendidos.');
                $pdo->prepare("UPDATE jogadores_elenco SET ativo=0,saiu_em=NOW() WHERE id=?")->execute([$jogador]);
                mercado_ordenar_elenco($pdo, $campeonatoId, $participantId);
                $depois = $antes + $valor;
                $_POST = $dados + $_POST;
            }
            $pdo->prepare("UPDATE clubes_campeonato SET saldo=?,cofre_configurado=1 WHERE participante_id=?")
                ->execute([$depois, $participantId]);
            $pdo->prepare("INSERT INTO movimentacoes_elenco(campeonato_id,participante_id,jogador_id,tipo,origem,origem_detalhe,valor_origem,moeda_origem,jogador_nome,jogador_overall,jogador_posicao,valor,saldo_anterior,saldo_posterior,rodada,conta_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$campeonatoId, $participantId, $jogador, $action === 'comprar' ? 'compra' : 'venda', $origem, $origemDetalhe, $valorOrigem, $moedaOrigem, trim((string)$_POST['nome']), (int)$_POST['overall'], (string)$_POST['posicao'], $valor, $antes, $depois, $rodada, (int)$_SESSION['conta_id']]);
            $pdo->commit();
            $message = $action === 'comprar'
                ? match ($origem) {
                    'pack' => 'Jogador recebido por pack registrado sem alterar o cofre.',
                    'passe' => 'Jogador recebido pelo passe registrado sem alterar o cofre.',
                    'sorteio' => 'Jogador ganho em sorteio registrado sem alterar o cofre.',
                    'prancheta' => 'Jogador recebido pela prancheta registrado sem alterar o cofre.',
                    default => 'Contratação registrada.',
                }
                : 'Venda registrada.';
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
    $id = (int)$pdo->lastInsertId();
    mercado_ordenar_elenco($pdo, $campeonato, $participante);
    return $id;
}
function contar_titulares(PDO $pdo, int $campeonato, int $participante): int
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM jogadores_elenco WHERE campeonato_id=? AND participante_id=? AND ativo=1 AND grupo='titular'");
    $s->execute([$campeonato, $participante]);
    return (int)$s->fetchColumn();
}

$clube = $participantId && $campeonatoId ? mercado_clube($pdo, $campeonatoId, $participantId) : null;
$rodada = $campeonatoId && $participantId ? mercado_rodada_atual($pdo, $campeonatoId, $participantId) : 1;
$ciclo = $campeonatoId && $participantId
    ? mercado_estado_clube($pdo, $campeonatoId, $participantId)
    : mercado_estado_ciclo(1) + ['partidas_concluidas' => 0, 'proxima_partida' => 1];
$elenco = [];
$historico = [];
$totalTitularesAtual = 0;
$campeonatosComElenco = [];
$podeEditarMercado = $clube ? mercado_pode_editar($clube, $rodada) : false;
$montagemInicial = $clube ? (!(bool)$clube['elenco_confirmado'] && $rodada === 1) : false;
if ($clube) {
    $s = $pdo->prepare("SELECT * FROM jogadores_elenco WHERE campeonato_id=? AND participante_id=? AND ativo=1 ORDER BY grupo='titular' DESC,ordem,nome");
    $s->execute([$campeonatoId, $participantId]);
    $elenco = $s->fetchAll();
    $totalTitularesAtual = count(array_filter($elenco, static fn(array $jogador): bool => $jogador['grupo'] === 'titular'));
    $s = $pdo->prepare("SELECT * FROM movimentacoes_elenco WHERE campeonato_id=? AND participante_id=? ORDER BY id DESC");
    $s->execute([$campeonatoId, $participantId]);
    $historico = $s->fetchAll();
    if ($montagemInicial) {
        $s = $pdo->prepare("SELECT c.id,c.nome,COUNT(j.id) total
            FROM campeonatos c
            JOIN jogadores_elenco j ON j.campeonato_id=c.id AND j.participante_id=? AND j.ativo=1
            WHERE c.ativo=1 AND c.id<>? GROUP BY c.id,c.nome ORDER BY c.id DESC");
        $s->execute([$participantId, $campeonatoId]);
        $campeonatosComElenco = $s->fetchAll();
    }
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

<body><?php public_navbar('mercado'); ?><main class="container market-page" data-market-editable="<?= $podeEditarMercado ? '1' : '0' ?>"><span class="eyebrow"><?= $isMasterManagement ? 'Gestão Master' : 'Gestão do clube' ?></span>
        <h1>GESTÃO DO ELENCO</h1><?php if ($managedTeam): ?><p class="market-managed-team">Gerenciando <strong><?= e($managedTeam['time_nome']) ?></strong> · Técnico <?= e($managedTeam['nome']) ?></p><?php endif; ?><?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?><form method="get" class="mb-4"><?php if ($isMasterManagement): ?><input type="hidden" name="participante_id" value="<?= $participantId ?>"><?php endif; ?><select class="form-select" name="campeonato_id" onchange="this.form.submit()"><?php foreach ($campeonatos as $c): ?><option value="<?= $c['id'] ?>" <?= $campeonatoId === $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></form>
        <?php if (!$participantId): ?><div class="panel p-4">A conta precisa estar associada a um time.</div><?php elseif ($clube && !(bool)($clube['cofre_configurado'] ?? false)): ?><section class="panel p-4 market-treasury-required"><span class="eyebrow">Primeira etapa obrigatória</span><h2>INFORME O SALDO DO COFRE</h2><p>Antes de montar o elenco ou registrar qualquer movimentação, informe o valor atual do cofre. O saldo pode ser zero, mas precisa ser confirmado pelo responsável.</p><a class="btn btn-danger" href="time.php?id=<?= $participantId ?>&editar_perfil=1">Abrir perfil e informar cofre</a></section><?php elseif ($clube): ?><section class="market-summary">
                <div><small>Próxima partida do clube</small><strong><?= $rodada ?>ª</strong></div>
                <div><small>Ciclo <?= $ciclo['ciclo'] ?></small><strong><?= $ciclo['aberto'] ? 'ALTERAÇÕES LIBERADAS' : 'ELENCO TRAVADO' ?></strong></div>
            </section>
            <section class="market-help-grid" aria-label="Ajuda para gestão do elenco">
                <article><span>01</span><div><strong>Ciclo individual</strong><p>Este clube cumpriu <?= $ciclo['etapas_concluidas'] ?> rodada(s): <?= $ciclo['partidas_concluidas'] ?> jogo(s) e <?= $ciclo['folgas'] ?> folga(s). O mercado abre após a 5ª e fica liberado na 6ª, 7ª e 8ª rodadas do próprio ciclo.</p></div></article>
                <article><span>02</span><div><strong>Titulares automáticos</strong><p>Marque somente os 11 titulares. Ao salvar, todos os jogadores não selecionados serão definidos automaticamente como banco.</p></div></article>
                <article><span>03</span><div><strong>Formação e ordem automáticas</strong><p>Os titulares precisam respeitar os setores da formação. O sistema ordena ataque, meio, defesa e deixa o goleiro sempre por último.</p></div></article>
                <article><span>04</span><div><strong>Cofre e janela</strong><p>Use <code>..cofre</code> no Discord para consultar o saldo. A correção fica no lápis do card Cofre do clube; contratações, vendas e escalação dependem da janela.</p></div></article>
            </section>
            <?php if ($montagemInicial): ?><section class="panel p-4 mb-4 market-config-panel">
                    <h2>CONFIGURAÇÃO INICIAL</h2>
                    <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="configurar_inicial">
                        <div class="col-md-6 formation-control"><label class="form-label">Formação</label><select class="form-select" name="formacao"><?php foreach (MERCADO_FORMACOES as $f): ?><option value="<?= e($f) ?>" <?= $clube['formacao'] === $f ? 'selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?><option value="__custom__" <?= !in_array($clube['formacao'], MERCADO_FORMACOES, true) ? 'selected' : '' ?>>Formação customizada</option></select><input class="form-control mt-2" name="formacao_custom" inputmode="numeric" maxlength="14" placeholder="Ex.: 433 ou 4-3-3" value="<?= !in_array($clube['formacao'], MERCADO_FORMACOES, true) && preg_match('/([1-9])-([1-9])-([1-9])/', $clube['formacao'], $formacaoAtual) ? e($formacaoAtual[1] . '-' . $formacaoAtual[2] . '-' . $formacaoAtual[3]) : '' ?>"><small class="text-secondary">O sistema adiciona “Custom” automaticamente.</small></div>
                        <div><button class="btn btn-danger">Salvar configuração</button></div>
                    </form>
                </section>
                <section class="panel p-4 mb-4 market-config-panel">
                    <span class="eyebrow">Mesmo clube, novo campeonato</span>
                    <h2>IMPORTAR ELENCO ANTERIOR</h2>
                    <?php if ($campeonatosComElenco): ?><p class="text-secondary">Copie os jogadores ativos de outro campeonato. Todos entrarão no banco para você escolher novamente os 11 titulares.</p><form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="importar_elenco_campeonato"><?php if ($isMasterManagement): ?><input type="hidden" name="participante_id" value="<?= $participantId ?>"><?php endif; ?><div class="col-md-8"><label class="form-label">Campeonato de origem</label><select class="form-select" name="campeonato_origem_id" required><option value="">Selecione o campeonato</option><?php foreach ($campeonatosComElenco as $origem): ?><option value="<?= (int)$origem['id'] ?>"><?= e($origem['nome']) ?> · <?= (int)$origem['total'] ?> jogadores</option><?php endforeach; ?></select></div><div class="col-md-4 d-flex align-items-end"><button class="btn btn-outline-danger w-100">Importar elenco</button></div></form><?php else: ?><p class="text-secondary mb-0">Este clube ainda não possui elenco ativo em outro campeonato.</p><?php endif; ?>
                </section><?php endif; ?>
            <div id="mercado-transferencias" class="market-anchor" aria-hidden="true"></div>
            <?php if ($podeEditarMercado && !$montagemInicial): ?><section class="panel p-4 mb-4 market-contract-panel">
                    <h2>ADICIONAR / CONTRATAR JOGADOR</h2>
                    <div class="market-inline-help"><strong>Como registrar:</strong> use <code>/contratar</code> no Discord e copie nome, OVR, posição e valor. Compra direta desconta Reais do cofre; Pack registra DP; Passe, Sorteio e Prancheta entram sem custo em Reais.</div>
                    <form method="post" class="row g-3" data-current-starters="<?= $totalTitularesAtual ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="comprar">
                        <div class="col-md-4"><input class="form-control" name="nome" placeholder="Nome do jogador" required></div>
                        <div class="col-md-2"><input class="form-control" type="number" min="1" max="99" name="overall" placeholder="Overall" required></div>
                        <div class="col-md-2"><select class="form-select" name="posicao"><?php foreach (MERCADO_POSICOES as $p): ?><option><?= $p ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><select class="form-select" name="grupo">
                                <option value="titular">Titular</option>
                                <option value="banco">Banco</option>
                        </select></div><div class="col-md-4 acquisition-replacement-field" hidden><label class="form-label">Titular que sairá</label><select class="form-select" name="substituir_titular_id"><option value="">Selecione o substituído</option><?php foreach ($elenco as $j): ?><?php if ($j['grupo'] !== 'titular') continue; ?><option value="<?= $j['id'] ?>"><?= e($j['nome']) ?> · <?= e($j['posicao']) ?></option><?php endforeach; ?></select></div><?php if ((bool)$clube['elenco_confirmado']): ?><div class="col-md-4"><label class="form-label">Origem do jogador</label><select class="form-select" name="origem"><option value="compra_direta">Compra direta</option><option value="pack">Recebido em pack</option><option value="passe">Recebido no passe</option><option value="sorteio">Ganho em sorteio</option><option value="prancheta">Recebido pela prancheta</option></select></div><div class="col-md-5 acquisition-pack-field" hidden><label class="form-label">Pack recebido</label><select class="form-select" name="pack"><option value="">Selecione o pack</option><?php foreach (MERCADO_PACKS as $packId => $pack): ?><option value="<?= e($packId) ?>" data-min-ovr="<?= $pack['min'] ?>" data-max-ovr="<?= $pack['max'] ?>"><?= e($pack['nome']) ?> · <?= number_format((float)$pack['dream_points'], 0, ',', '.') ?> DP · <?= $pack['min'] ?><?= $pack['min'] !== $pack['max'] ? '–' . $pack['max'] : '' ?> OVR</option><?php endforeach; ?></select></div><div class="col-md-3 acquisition-value-field"><label class="form-label">Valor da compra</label><input class="form-control" type="number" step="1" min="0" name="valor" placeholder="R$ 0" required></div><div class="col-12 acquisition-note alert alert-info mb-0" hidden></div><?php endif; ?><div><button class="btn btn-danger">Contratar jogador</button></div>
                        <?php if (!(bool)$clube['elenco_confirmado'] && !$montagemInicial): ?><div class="col-md-4"><label class="form-label">Origem do jogador</label><select class="form-select" name="origem"><option value="compra_direta">Compra direta</option><option value="pack">Recebido em pack</option><option value="passe">Recebido no passe</option><option value="sorteio">Ganho em sorteio</option><option value="prancheta">Recebido pela prancheta</option></select></div><div class="col-md-5 acquisition-pack-field" hidden><label class="form-label">Pack recebido</label><select class="form-select" name="pack"><option value="">Selecione o pack</option><?php foreach (MERCADO_PACKS as $packId => $pack): ?><option value="<?= e($packId) ?>" data-min-ovr="<?= $pack['min'] ?>" data-max-ovr="<?= $pack['max'] ?>"><?= e($pack['nome']) ?> · <?= number_format((float)$pack['dream_points'], 0, ',', '.') ?> DP · <?= $pack['min'] ?><?= $pack['min'] !== $pack['max'] ? '–' . $pack['max'] : '' ?> OVR</option><?php endforeach; ?></select></div><div class="col-md-3 acquisition-value-field"><label class="form-label">Valor da compra</label><input class="form-control" type="number" step="1" min="0" name="valor" placeholder="R$ 0" required></div><div class="col-12 acquisition-note alert alert-info mb-0" hidden></div><?php endif; ?>
                    </form>
                </section><?php endif; ?>
            <section class="panel p-4 mb-4" id="elenco">
                <div class="d-flex justify-content-between">
                    <h2>ELENCO</h2>
                </div><?php if (mercado_pode_editar($clube, $rodada)): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="atualizar_escalacao"><div class="formation-control mb-3"><label class="form-label">Formação</label><select class="form-select" name="formacao"><?php foreach (MERCADO_FORMACOES as $f): ?><option value="<?= e($f) ?>" <?= $clube['formacao'] === $f ? 'selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?><option value="__custom__" <?= !in_array($clube['formacao'], MERCADO_FORMACOES, true) ? 'selected' : '' ?>>Formação customizada</option></select><input class="form-control mt-2" name="formacao_custom" inputmode="numeric" maxlength="14" placeholder="Ex.: 433 ou 4-3-3" value="<?= !in_array($clube['formacao'], MERCADO_FORMACOES, true) && preg_match('/([1-9])-([1-9])-([1-9])/', $clube['formacao'], $formacaoAtual) ? e($formacaoAtual[1] . '-' . $formacaoAtual[2] . '-' . $formacaoAtual[3]) : '' ?>"><small class="text-secondary">Três números que somem 10; “Custom” será adicionado automaticamente.</small></div>
                        <div class="lineup-selection-status"><strong><span data-selected-starters>0</span>/11 titulares selecionados</strong><small>Use <code>..time @seu_usuario</code> no Discord para visualizar apenas a imagem do seu time e conferir os titulares. Quem não estiver marcado será banco.</small></div><div class="lineup-limit-warning" role="alert" aria-live="assertive" hidden>Você já selecionou os 11 titulares. Desmarque um jogador antes de escolher outro.</div>
                        <div class="roster-grid"><?php foreach ($elenco as $j): ?><article class="roster-select-card<?= $j['grupo'] === 'titular' ? ' is-starter' : '' ?>"><input type="hidden" name="jogador_id[]" value="<?= $j['id'] ?>"><label class="starter-toggle"><input type="checkbox" name="titular_id[]" value="<?= $j['id'] ?>" <?= $j['grupo'] === 'titular' ? 'checked' : '' ?>><span>Titular</span></label><b><?= e($j['nome']) ?></b><strong><?= $j['overall'] ?></strong><span><?= e($j['posicao']) ?></span></article><?php endforeach; ?></div><button class="btn btn-danger mt-3" <?= !(bool)$clube['elenco_confirmado'] ? 'name="confirmar_elenco" value="1"' : '' ?>><?= !(bool)$clube['elenco_confirmado'] ? 'Salvar e confirmar 11 titulares' : 'Salvar escalação' ?></button>
                    </form><?php else: ?><div class="roster-grid"><?php foreach ($elenco as $j): ?><article><b><?= e($j['nome']) ?></b><strong><?= $j['overall'] ?></strong><span><?= e($j['posicao']) ?> · <?= e($j['grupo']) ?></span></article><?php endforeach; ?></div><?php endif; ?><?php if (!$montagemInicial && $ciclo['aberto']): ?>
                    <hr>
                    <h2>VENDER JOGADOR</h2>
                    <div class="market-inline-help market-sale-help"><span><strong>Regra da venda:</strong> somente jogadores do banco de reservas podem ser vendidos. Para vender um titular, primeiro mova-o para o banco e salve a escalação.</span><button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#lineup-management-modal">Mover titular para o banco</button></div>
                    <form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="vender">
                        <div class="col-md-7"><select class="form-select" name="jogador_id" required><option value="">Selecione um jogador do banco</option><?php foreach ($elenco as $j): ?><?php if ($j['grupo'] !== 'banco') continue; ?><option value="<?= $j['id'] ?>"><?= e($j['nome']) ?> · <?= $j['overall'] ?> · <?= e($j['posicao']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-3"><input class="form-control" type="number" step="1" min="0" name="valor" placeholder="Valor da venda" required></div>
                        <div class="col-md-2"><button class="btn btn-outline-danger w-100">Vender</button></div>
                    </form><?php endif; ?>
            </section>
            <section class="panel p-4 market-history" data-market-history data-items-per-page="4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h2>HISTÓRICO</h2><div class="history-filters" role="group" aria-label="Filtrar histórico"><button class="active" type="button" data-history-filter="todas">Todas</button><button type="button" data-history-filter="compra">Compras</button><button type="button" data-history-filter="venda">Vendas</button></div></div>
                <div class="history-items"><?php foreach ($historico as $m): ?><article data-history-type="<?= e($m['tipo']) ?>"><span class="history-kind <?= $m['tipo'] === 'compra' ? 'is-purchase' : 'is-sale' ?>"><?= e(mercado_rotulo_origem($m)) ?></span><div><strong><?= e($m['jogador_nome']) ?></strong><small><?= (int)$m['jogador_overall'] ?> · <?= e($m['jogador_posicao']) ?> · rodada <?= $m['rodada'] ?><?= !empty($m['origem_detalhe']) ? ' · ' . e($m['origem_detalhe']) : '' ?></small></div><b><?= e(mercado_valor_movimento($m)) ?></b></article><?php endforeach; ?><?php if (!$historico): ?><p class="text-secondary">Nenhuma movimentação.</p><?php endif; ?></div><nav class="history-pages card-pages"></nav>
            </section>
            <?php endif; ?>
    </main><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
