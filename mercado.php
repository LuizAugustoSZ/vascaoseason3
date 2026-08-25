<?php

declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/public-layout.php';
require __DIR__ . '/includes/mercado.php';
require __DIR__ . '/includes/elenco-geral.php';
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
    elenco_geral_garantir_estrutura($pdo);
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
$campeonatos = $pdo->query("SELECT id,nome,tipo FROM campeonatos WHERE ativo=1 AND status='ativo' AND tipo='pontos_corridos' ORDER BY id DESC")->fetchAll();
$campeonatoValido = false;
foreach ($campeonatos as $campeonatoDisponivel) {
    if ((int)$campeonatoDisponivel['id'] === $campeonatoId) $campeonatoValido = true;
}
if (!$campeonatoValido) {
    $campeonatoId = $_SERVER['REQUEST_METHOD'] === 'POST' ? 0 : (int)($campeonatos[0]['id'] ?? 0);
}
$campeonatoUsaCiclo = true;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (!$campeonatoId) throw new RuntimeException('Esta competição não está disponível para gestão.');
        if (!$participantId) throw new RuntimeException('Sua conta precisa estar vinculada a um time.');
        $rodada = $campeonatoUsaCiclo ? mercado_rodada_atual($pdo, $campeonatoId, $participantId) : 1;
        $clube = mercado_clube($pdo, $campeonatoId, $participantId);
        if (!(bool)($clube['cofre_configurado'] ?? false)) {
            throw new RuntimeException('Informe primeiro o saldo inicial usando o lápis do Cofre do clube.');
        }
        $montagemInicial = !(bool)$clube['elenco_confirmado'] && $rodada === 1;
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'atualizar_inscricao_geral') {
            if (!$isMasterManagement && $campeonatoUsaCiclo && !mercado_pode_editar($clube, $rodada)) throw new RuntimeException('A inscrição desta competição está congelada neste ciclo.');
            $inscritos = array_values(array_unique(array_map('intval', (array)($_POST['inscrito_id'] ?? []))));
            $titulares = array_values(array_unique(array_map('intval', (array)($_POST['titular_geral_id'] ?? []))));
            if (count($titulares) !== 11) throw new RuntimeException('Selecione exatamente 11 titulares.');
            if (count($inscritos) < 11 || count($inscritos) > 26) throw new RuntimeException('A inscrição aceita 11 titulares e no máximo 15 reservas.');
            if (array_diff($titulares, $inscritos)) throw new RuntimeException('Todo titular precisa estar inscrito.');
            $placeholders = implode(',', array_fill(0, count($inscritos), '?'));
            $check = $pdo->prepare("SELECT COUNT(*) FROM jogadores_gerais WHERE participante_id=? AND ativo=1 AND id IN ($placeholders)");
            $check->execute(array_merge([$participantId], $inscritos));
            if ((int)$check->fetchColumn() !== count($inscritos)) throw new RuntimeException('A inscrição contém jogador que não pertence ao Elenco Geral.');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE jogadores_elenco SET ativo=0,saiu_em=NOW() WHERE campeonato_id=? AND participante_id=? AND ativo=1")->execute([$campeonatoId,$participantId]);
            $select = $pdo->prepare("SELECT id,nome,overall,posicao FROM jogadores_gerais WHERE participante_id=? AND ativo=1 AND id IN ($placeholders) ORDER BY nome");
            $select->execute(array_merge([$participantId],$inscritos));
            $insert = $pdo->prepare("INSERT INTO jogadores_elenco(campeonato_id,participante_id,jogador_geral_id,nome,overall,posicao,grupo,ordem) VALUES(?,?,?,?,?,?,?,?)");
            $titularMap=array_flip($titulares);$ordemTitular=$ordemBanco=0;
            foreach($select->fetchAll() as $player){$grupo=isset($titularMap[(int)$player['id']])?'titular':'banco';$ordem=$grupo==='titular'?++$ordemTitular:++$ordemBanco;$insert->execute([$campeonatoId,$participantId,$player['id'],$player['nome'],$player['overall'],$player['posicao'],$grupo,$ordem]);}
            $pdo->prepare("UPDATE clubes_campeonato SET elenco_confirmado=1 WHERE id=?")->execute([$clube['id']]);
            $pdo->commit();$message='Inscrição salva: 11 titulares e '.(count($inscritos)-11).' reservas.';
        } elseif ($action === 'importar_elenco_campeonato') {
            throw new RuntimeException('A importação agora alimenta exclusivamente o Elenco Geral.');
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
            // A janela protege a inscrição da competição, não a organização dos jogadores já inscritos.
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
        } elseif (in_array($action, ['editar_movimentacao', 'desfazer_movimentacao'], true)) {
            $movimentacaoId = (int)($_POST['movimentacao_id'] ?? 0);
            if ($movimentacaoId < 1) throw new RuntimeException('Movimentação inválida.');

            $pdo->beginTransaction();
            $movimentoStmt = $pdo->prepare("SELECT * FROM movimentacoes_elenco WHERE id=? AND campeonato_id=? AND participante_id=? FOR UPDATE");
            $movimentoStmt->execute([$movimentacaoId, $campeonatoId, $participantId]);
            $movimento = $movimentoStmt->fetch();
            if (!$movimento) throw new RuntimeException('Essa movimentação não existe mais. Atualize a página.');

            $jogadorStmt = $pdo->prepare("SELECT * FROM jogadores_elenco WHERE id=? AND campeonato_id=? AND participante_id=? FOR UPDATE");
            $jogadorStmt->execute([(int)$movimento['jogador_id'], $campeonatoId, $participantId]);
            $jogadorMovimentado = $jogadorStmt->fetch();
            if (!$jogadorMovimentado) throw new RuntimeException('O jogador vinculado a essa movimentação não foi encontrado.');

            $cofres = $pdo->prepare("SELECT * FROM clubes_campeonato WHERE participante_id=? ORDER BY id FOR UPDATE");
            $cofres->execute([$participantId]);
            $cofresDoClube = $cofres->fetchAll();
            $saldoAtual = null;
            foreach ($cofresDoClube as $cofreDoCampeonato) {
                if ((int)$cofreDoCampeonato['campeonato_id'] === $campeonatoId) $saldoAtual = (float)$cofreDoCampeonato['saldo'];
            }
            if ($saldoAtual === null) throw new RuntimeException('O cofre deste campeonato não foi encontrado.');

            $impactoAnterior = (float)$movimento['saldo_posterior'] - (float)$movimento['saldo_anterior'];
            if ($action === 'desfazer_movimentacao') {
                if ($movimento['tipo'] === 'compra') {
                    if (!(bool)$jogadorMovimentado['ativo']) throw new RuntimeException('Não é possível desfazer: esse jogador já não está no elenco.');
                    $pdo->prepare("UPDATE jogadores_elenco SET ativo=0,saiu_em=NOW() WHERE id=?")->execute([$jogadorMovimentado['id']]);
                } else {
                    if ((bool)$jogadorMovimentado['ativo']) throw new RuntimeException('Não é possível desfazer: esse jogador já voltou ao elenco.');
                    $pdo->prepare("UPDATE jogadores_elenco SET ativo=1,grupo='banco',saiu_em=NULL WHERE id=?")->execute([$jogadorMovimentado['id']]);
                }
                $novoSaldo = $saldoAtual - $impactoAnterior;
                if ($novoSaldo < 0) throw new RuntimeException('Não é possível desfazer esta venda porque o cofre ficaria negativo.');
                $pdo->prepare("UPDATE clubes_campeonato SET saldo=?,cofre_configurado=1 WHERE participante_id=?")->execute([$novoSaldo, $participantId]);
                $pdo->prepare("DELETE FROM movimentacoes_elenco WHERE id=?")->execute([$movimentacaoId]);
                mercado_ordenar_elenco($pdo, $campeonatoId, $participantId);
                $pdo->commit();
                $message = $movimento['tipo'] === 'compra' ? 'Contratação desfeita. O jogador saiu do elenco e o cofre foi corrigido.' : 'Venda desfeita. O jogador voltou para o banco e o cofre foi corrigido.';
            } else {
                $nome = trim((string)($_POST['nome'] ?? ''));
                $overall = (int)($_POST['overall'] ?? 0);
                $posicao = (string)($_POST['posicao'] ?? '');
                if ($nome === '' || $overall < 1 || $overall > 99 || !in_array($posicao, MERCADO_POSICOES, true)) throw new RuntimeException('Preencha nome, overall e posição corretamente.');

                $origem = $movimento['tipo'] === 'venda' ? 'venda' : (string)($_POST['origem'] ?? 'compra_direta');
                $origemDetalhe = $valorOrigem = $moedaOrigem = null;
                $valor = 0.0;
                if ($movimento['tipo'] === 'venda' || $origem === 'compra_direta') {
                    $valor = mercado_parse_valor((string)($_POST['valor'] ?? ''));
                } elseif ($origem === 'pack') {
                    $pack = MERCADO_PACKS[(string)($_POST['pack'] ?? '')] ?? null;
                    if (!$pack) throw new RuntimeException('Selecione o pack recebido.');
                    if ($overall < $pack['min'] || $overall > $pack['max']) throw new RuntimeException(sprintf('%s aceita jogadores com OVR entre %d e %d.', $pack['nome'], $pack['min'], $pack['max']));
                    $origemDetalhe = $pack['nome'];
                    $valorOrigem = (float)$pack['dream_points'];
                    $moedaOrigem = 'DreamPoints';
                } elseif (!in_array($origem, ['passe', 'sorteio', 'prancheta'], true)) {
                    throw new RuntimeException('Selecione uma origem válida para o jogador.');
                }

                $novoImpacto = $movimento['tipo'] === 'venda' ? $valor : ($origem === 'compra_direta' ? -$valor : 0.0);
                $novoSaldo = $saldoAtual - $impactoAnterior + $novoImpacto;
                if ($novoSaldo < 0) throw new RuntimeException('Essa correção deixaria o cofre com saldo negativo.');
                $novoSaldoPosterior = (float)$movimento['saldo_anterior'] + $novoImpacto;
                $pdo->prepare("UPDATE clubes_campeonato SET saldo=?,cofre_configurado=1 WHERE participante_id=?")->execute([$novoSaldo, $participantId]);
                $pdo->prepare("UPDATE jogadores_elenco SET nome=?,overall=?,posicao=? WHERE id=?")->execute([$nome, $overall, $posicao, $jogadorMovimentado['id']]);
                $pdo->prepare("UPDATE movimentacoes_elenco SET origem=?,origem_detalhe=?,valor_origem=?,moeda_origem=?,jogador_nome=?,jogador_overall=?,jogador_posicao=?,valor=?,saldo_posterior=?,conta_id=? WHERE id=?")
                    ->execute([$origem, $origemDetalhe, $valorOrigem, $moedaOrigem, $nome, $overall, $posicao, $valor, $novoSaldoPosterior, (int)$_SESSION['conta_id'], $movimentacaoId]);
                mercado_ordenar_elenco($pdo, $campeonatoId, $participantId);
                $pdo->commit();
                $message = $movimento['tipo'] === 'compra' ? 'Contratação corrigida com sucesso.' : 'Venda corrigida com sucesso.';
            }
        } elseif (in_array($action, ['comprar', 'vender'], true)) {
            throw new RuntimeException('Contratações e vendas agora são feitas exclusivamente no Elenco Geral.');
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
$rodada = $campeonatoUsaCiclo && $campeonatoId && $participantId ? mercado_rodada_atual($pdo, $campeonatoId, $participantId) : 1;
$ciclo = $campeonatoUsaCiclo && $campeonatoId && $participantId
    ? mercado_estado_clube($pdo, $campeonatoId, $participantId)
    : mercado_estado_ciclo(1) + ['partidas_concluidas' => 0, 'proxima_partida' => 1];
$elenco = [];
$historico = [];
$totalTitularesAtual = 0;
$campeonatosComElenco = [];
$elencoGeral = [];
$inscritosGerais = $titularesGerais = [];
$podeEditarMercado = $clube ? (!$campeonatoUsaCiclo || mercado_pode_editar($clube, $rodada)) : false;
$podeEditarInscricao = $clube ? ($isMasterManagement || $podeEditarMercado) : false;
$montagemInicial = $clube ? (!(bool)$clube['elenco_confirmado'] && $rodada === 1) : false;
if ($clube) {
    $s = $pdo->prepare("SELECT * FROM jogadores_elenco WHERE campeonato_id=? AND participante_id=? AND ativo=1 ORDER BY grupo='titular' DESC,ordem,nome");
    $s->execute([$campeonatoId, $participantId]);
    $elenco = $s->fetchAll();
    $elencoGeral = elenco_geral_do_clube($pdo, $participantId);
    foreach ($elenco as $jogadorInscrito) {
        $geralId = (int)($jogadorInscrito['jogador_geral_id'] ?? 0);
        if ($geralId < 1) continue;
        $inscritosGerais[$geralId] = true;
        if ($jogadorInscrito['grupo'] === 'titular') $titularesGerais[$geralId] = true;
    }
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
    <title>Gestão da Competição | Vascão S3</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/market.css">
</head>

<body><?php public_navbar('mercado'); ?><main class="container market-page" data-market-editable="<?= $podeEditarMercado ? '1' : '0' ?>"><span class="eyebrow"><?= $isMasterManagement ? 'Gestão Master' : 'Gestão do clube' ?></span>
        <h1>GESTÃO DA COMPETIÇÃO</h1><?php if ($managedTeam): ?><p class="market-managed-team">Gerenciando inscrição e escalação de <strong><?= e($managedTeam['time_nome']) ?></strong> · Técnico <?= e($managedTeam['nome']) ?></p><?php endif; ?><?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?><?php if ($campeonatos): ?><form method="get" class="mb-4"><?php if ($isMasterManagement): ?><input type="hidden" name="participante_id" value="<?= $participantId ?>"><?php endif; ?><label class="form-label">Competição que deseja gerenciar</label><select class="form-select" name="campeonato_id" onchange="this.form.submit()"><?php foreach ($campeonatos as $c): ?><option value="<?= $c['id'] ?>" <?= $campeonatoId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></form><?php else: ?><div class="alert alert-info mb-4">Nenhuma competição de pontos corridos está ativa para gestão.</div><?php endif; ?>
        <?php if (!$participantId): ?><div class="panel p-4">A conta precisa estar associada a um time.</div><?php elseif ($clube && !(bool)($clube['cofre_configurado'] ?? false)): ?><section class="panel p-4 market-treasury-required"><span class="eyebrow">Primeira etapa obrigatória</span><h2>INFORME O SALDO DO COFRE</h2><p>Antes de montar o elenco ou registrar qualquer movimentação, informe o valor atual do cofre. O saldo pode ser zero, mas precisa ser confirmado pelo responsável.</p><a class="btn btn-danger" href="time.php?id=<?= $participantId ?>&editar_perfil=1">Abrir perfil e informar cofre</a></section><?php elseif ($clube): ?><section class="market-summary">
                <div><small><?= $campeonatoUsaCiclo ? 'Próxima rodada do clube' : 'Formato da competição' ?></small><strong><?= $campeonatoUsaCiclo ? $rodada.'ª' : 'MATA-MATA' ?></strong></div>
                <div><small><?= $campeonatoUsaCiclo ? 'Ciclo '.$ciclo['ciclo'] : 'Regra de inscrição' ?></small><strong><?= !$campeonatoUsaCiclo || $ciclo['aberto'] ? 'INSCRIÇÃO LIBERADA' : 'INSCRIÇÃO TRAVADA' ?></strong></div>
            </section>
            <?php if ($campeonatoUsaCiclo && $rodada >= 9 && $rodada <= 13): ?><div class="alert alert-warning mb-4" role="status">
                <strong>Inscrição travada após a 8ª rodada.</strong> Novos jogadores podem continuar entrando no Elenco Geral, mas só poderão ser inscritos aqui quando a janela reabrir na 14ª rodada. Formação, titulares e banco dos já inscritos continuam editáveis. Folgas contam normalmente como rodada cumprida.
            </div><?php elseif ($campeonatoUsaCiclo && $rodada === 14): ?><div class="alert alert-success mb-4" role="status">
                <strong>Inscrição liberada para a 14ª rodada.</strong> Todos os jogadores ativos do Elenco Geral já aparecem como opções para montar a nova lista da competição.
            </div><?php endif; ?>
            <section class="market-help-grid" aria-label="Ajuda para gestão do elenco">
                <article><span>01</span><div><strong><?= $campeonatoUsaCiclo ? 'Janela de inscrição' : 'Mata-mata sem ciclo' ?></strong><p><?= $campeonatoUsaCiclo ? 'Este clube cumpriu '.$ciclo['etapas_concluidas'].' rodada(s), incluindo '.$ciclo['folgas'].' folga(s). A inscrição abre após a 5ª e fica liberada na 6ª, 7ª e 8ª rodadas do ciclo.' : 'Esta competição não usa janela por rodadas. Os jogadores do Elenco Geral permanecem disponíveis para montar a inscrição.' ?></p></div></article>
                <article><span>02</span><div><strong>Titulares automáticos</strong><p>Marque somente os 11 titulares. Ao salvar, todos os jogadores não selecionados serão definidos automaticamente como banco.</p></div></article>
                <article><span>03</span><div><strong>Formação e ordem automáticas</strong><p>Os titulares precisam respeitar os setores da formação. O sistema ordena ataque, meio, defesa e deixa o goleiro sempre por último.</p></div></article>
                <article><span>04</span><div><strong>Inscrição e escalação</strong><p>A janela controla quais jogadores fazem parte da competição. Formação, titulares e banco podem ser reorganizados a qualquer momento entre os jogadores inscritos.</p></div></article>
            </section>
            <?php if ($montagemInicial): ?><section class="panel p-4 mb-4 market-config-panel">
                    <h2>CONFIGURAÇÃO INICIAL</h2>
                    <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="configurar_inicial">
                        <div class="col-md-6 formation-control"><label class="form-label">Formação</label><select class="form-select" name="formacao"><?php foreach (MERCADO_FORMACOES as $f): ?><option value="<?= e($f) ?>" <?= $clube['formacao'] === $f ? 'selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?><option value="__custom__" <?= !in_array($clube['formacao'], MERCADO_FORMACOES, true) ? 'selected' : '' ?>>Formação customizada</option></select><input class="form-control mt-2" name="formacao_custom" inputmode="numeric" maxlength="14" placeholder="Ex.: 433 ou 4-3-3" value="<?= !in_array($clube['formacao'], MERCADO_FORMACOES, true) && preg_match('/([1-9])-([1-9])-([1-9])/', $clube['formacao'], $formacaoAtual) ? e($formacaoAtual[1] . '-' . $formacaoAtual[2] . '-' . $formacaoAtual[3]) : '' ?>"><small class="text-secondary">O sistema adiciona “Custom” automaticamente.</small></div>
                        <div><button class="btn btn-danger">Salvar configuração</button></div>
                    </form>
                </section><?php endif; ?>
            <div id="gestao-competicao" class="market-anchor" aria-hidden="true"></div>
            <?php if ($podeEditarInscricao): ?><section class="panel p-4 mb-4"><span class="eyebrow"><?= $isMasterManagement && !$podeEditarMercado ? 'Acesso Master · ciclo ignorado' : 'Janela aberta · todos os jogadores disponíveis' ?></span><h2>INSCRIÇÃO NA COMPETIÇÃO</h2><p class="text-secondary">Todos os jogadores ativos do Elenco Geral aparecem abaixo automaticamente. Escolha exatamente 11 titulares e até 15 reservas; quem não for marcado permanece somente no Geral.</p><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="atualizar_inscricao_geral"><?php if($isMasterManagement): ?><input type="hidden" name="participante_id" value="<?= $participantId ?>"><?php endif; ?><div class="roster-grid"><?php foreach($elencoGeral as $j): $gid=(int)$j['id']; ?><article class="roster-select-card"><label><input type="checkbox" name="inscrito_id[]" value="<?= $gid ?>" <?= isset($inscritosGerais[$gid])?'checked':'' ?>> Inscrito</label><label><input type="checkbox" name="titular_geral_id[]" value="<?= $gid ?>" <?= isset($titularesGerais[$gid])?'checked':'' ?>> Titular</label><b><?= e($j['nome']) ?></b><strong><?= (int)$j['overall'] ?></strong><span><?= e($j['posicao']) ?></span></article><?php endforeach; ?></div><button class="btn btn-danger mt-3">Salvar inscrição</button></form></section><?php else: ?><div class="alert alert-warning mb-4"><strong>Inscrição congelada.</strong> Os jogadores contratados agora ficam no Elenco Geral e aparecerão automaticamente aqui quando a próxima janela abrir. A escalação dos já inscritos continua editável abaixo.</div><?php endif; ?>
            <section class="panel p-4 mb-4" id="elenco">
                <div class="d-flex justify-content-between">
                    <h2>ELENCO</h2>
                </div><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="atualizar_escalacao"><div class="formation-control mb-3"><label class="form-label">Formação</label><select class="form-select" name="formacao"><?php foreach (MERCADO_FORMACOES as $f): ?><option value="<?= e($f) ?>" <?= $clube['formacao'] === $f ? 'selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?><option value="__custom__" <?= !in_array($clube['formacao'], MERCADO_FORMACOES, true) ? 'selected' : '' ?>>Formação customizada</option></select><input class="form-control mt-2" name="formacao_custom" inputmode="numeric" maxlength="14" placeholder="Ex.: 433 ou 4-3-3" value="<?= !in_array($clube['formacao'], MERCADO_FORMACOES, true) && preg_match('/([1-9])-([1-9])-([1-9])/', $clube['formacao'], $formacaoAtual) ? e($formacaoAtual[1] . '-' . $formacaoAtual[2] . '-' . $formacaoAtual[3]) : '' ?>"><small class="text-secondary">Três números que somem 10; “Custom” será adicionado automaticamente.</small></div>
                        <div class="lineup-selection-status"><strong><span data-selected-starters>0</span>/11 titulares selecionados</strong><small>Use <code>..time @seu_usuario</code> no Discord para visualizar apenas a imagem do seu time e conferir os titulares. Quem não estiver marcado será banco.</small></div><div class="lineup-limit-warning" role="alert" aria-live="assertive" hidden>Você já selecionou os 11 titulares. Desmarque um jogador antes de escolher outro.</div>
                        <div class="roster-grid"><?php foreach ($elenco as $j): ?><article class="roster-select-card<?= $j['grupo'] === 'titular' ? ' is-starter' : '' ?>"><input type="hidden" name="jogador_id[]" value="<?= $j['id'] ?>"><label class="starter-toggle"><input type="checkbox" name="titular_id[]" value="<?= $j['id'] ?>" <?= $j['grupo'] === 'titular' ? 'checked' : '' ?>><span>Titular</span></label><b><?= e($j['nome']) ?></b><strong><?= $j['overall'] ?></strong><span><?= e($j['posicao']) ?></span></article><?php endforeach; ?></div><button class="btn btn-danger mt-3" <?= !(bool)$clube['elenco_confirmado'] ? 'name="confirmar_elenco" value="1"' : '' ?>><?= !(bool)$clube['elenco_confirmado'] ? 'Salvar e confirmar 11 titulares' : 'Salvar escalação' ?></button>
                    </form>
            </section>
            <?php if (false): ?><section class="panel p-4 market-history" data-market-history data-items-per-page="4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h2>HISTÓRICO</h2><div class="history-filters" role="group" aria-label="Filtrar histórico"><button class="active" type="button" data-history-filter="todas">Todas</button><button type="button" data-history-filter="compra">Compras</button><button type="button" data-history-filter="venda">Vendas</button></div></div>
                <div class="history-items"><?php foreach ($historico as $m): ?><?php $packMovimento = ''; foreach (MERCADO_PACKS as $packId => $packDados) { if (($m['origem_detalhe'] ?? '') === $packDados['nome']) { $packMovimento = $packId; break; } } ?><article data-history-type="<?= e($m['tipo']) ?>"><span class="history-kind <?= $m['tipo'] === 'compra' ? 'is-purchase' : 'is-sale' ?>"><?= e(mercado_rotulo_origem($m)) ?></span><div><strong><?= e($m['jogador_nome']) ?></strong><small><?= (int)$m['jogador_overall'] ?> · <?= e($m['jogador_posicao']) ?> · rodada <?= $m['rodada'] ?><?= !empty($m['origem_detalhe']) ? ' · ' . e($m['origem_detalhe']) : '' ?> · <?= e(format_datetime_br((string)$m['criado_em'])) ?></small></div><b><?= e(mercado_valor_movimento($m)) ?></b><div class="history-actions"><button type="button" class="btn btn-sm btn-outline-light" data-edit-movement data-movement-id="<?= (int)$m['id'] ?>" data-movement-type="<?= e($m['tipo']) ?>" data-player-name="<?= e($m['jogador_nome']) ?>" data-player-overall="<?= (int)$m['jogador_overall'] ?>" data-player-position="<?= e($m['jogador_posicao']) ?>" data-movement-origin="<?= e($m['origem']) ?>" data-movement-pack="<?= e($packMovimento) ?>" data-movement-value="<?= (float)$m['valor'] ?>">Editar</button><button type="button" class="btn btn-sm btn-outline-danger" data-undo-movement data-movement-id="<?= (int)$m['id'] ?>" data-movement-type="<?= e($m['tipo']) ?>" data-player-name="<?= e($m['jogador_nome']) ?>">Desfazer</button></div></article><?php endforeach; ?><?php if (!$historico): ?><p class="text-secondary">Nenhuma movimentação.</p><?php endif; ?></div><nav class="history-pages card-pages"></nav>
            </section>
            <div class="modal fade market-movement-modal" id="market-movement-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><small class="eyebrow">Corrigir histórico</small><h2 class="modal-title">EDITAR MOVIMENTAÇÃO</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><form method="post"><div class="modal-body"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><?php if ($isMasterManagement): ?><input type="hidden" name="participante_id" value="<?= $participantId ?>"><?php endif; ?><input type="hidden" name="action" value="editar_movimentacao"><input type="hidden" name="movimentacao_id"><div class="row g-3"><div class="col-12"><label class="form-label">Jogador</label><input class="form-control" name="nome" required></div><div class="col-6"><label class="form-label">Overall</label><input class="form-control" type="number" min="1" max="99" name="overall" required></div><div class="col-6"><label class="form-label">Posição</label><select class="form-select" name="posicao"><?php foreach (MERCADO_POSICOES as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?></option><?php endforeach; ?></select></div><div class="col-12 movement-origin-field"><label class="form-label">Origem da contratação</label><select class="form-select" name="origem"><option value="compra_direta">Compra direta</option><option value="pack">Recebido em pack</option><option value="passe">Recebido no passe</option><option value="sorteio">Ganho em sorteio</option><option value="prancheta">Recebido pela prancheta</option></select></div><div class="col-12 movement-pack-field" hidden><label class="form-label">Pack recebido</label><select class="form-select" name="pack"><option value="">Selecione o pack</option><?php foreach (MERCADO_PACKS as $packId => $pack): ?><option value="<?= e($packId) ?>"><?= e($pack['nome']) ?> · <?= number_format((float)$pack['dream_points'], 0, ',', '.') ?> DP</option><?php endforeach; ?></select></div><div class="col-12 movement-value-field"><label class="form-label">Valor em reais</label><input class="form-control" type="number" min="0" step="1" name="valor"></div><div class="col-12"><div class="alert alert-info mb-0 movement-edit-note"></div></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger">Salvar correção</button></div></form></div></div></div>
            <div class="modal fade market-movement-modal" id="market-undo-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><small class="eyebrow">Ação definitiva</small><h2 class="modal-title">DESFAZER MOVIMENTAÇÃO</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><form method="post"><div class="modal-body"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><?php if ($isMasterManagement): ?><input type="hidden" name="participante_id" value="<?= $participantId ?>"><?php endif; ?><input type="hidden" name="action" value="desfazer_movimentacao"><input type="hidden" name="movimentacao_id"><p class="movement-undo-copy"></p><div class="alert alert-warning mb-0 movement-undo-detail"></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Voltar</button><button class="btn btn-danger">Sim, desfazer</button></div></form></div></div></div><?php endif; ?>
            <?php endif; ?>
    </main><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
