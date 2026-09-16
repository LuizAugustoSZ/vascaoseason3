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
$campeonatos = mercado_campeonatos_do_participante($pdo, $participantId);
$campeonatoValido = false;
foreach ($campeonatos as $campeonatoDisponivel) {
    if ((int)$campeonatoDisponivel['id'] === $campeonatoId) $campeonatoValido = true;
}
if (!$campeonatoValido) {
    // Quando o clube disputa uma unica liga ativa, ela e inequivoca. Isso
    // tambem recupera formularios abertos antes de uma troca de ID/deploy.
    $campeonatoId = count($campeonatos) === 1
        ? (int)$campeonatos[0]['id']
        : ($_SERVER['REQUEST_METHOD'] === 'POST' ? 0 : (int)($campeonatos[0]['id'] ?? 0));
}
$campeonatoUsaCiclo = true;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (!$campeonatoId) throw new RuntimeException('Seu time não está inscrito nesta competição ou ela não está disponível para gestão.');
        if (!$participantId) throw new RuntimeException('Sua conta precisa estar vinculada a um time.');
        $rodada = $campeonatoUsaCiclo ? mercado_rodada_atual($pdo, $campeonatoId, $participantId) : 1;
        $estadoClube = $campeonatoUsaCiclo ? mercado_estado_clube($pdo, $campeonatoId, $participantId) : null;
        $clube = mercado_clube($pdo, $campeonatoId, $participantId);
        if (!(bool)($clube['cofre_configurado'] ?? false)) {
            throw new RuntimeException('Informe primeiro o saldo inicial usando o lápis do Cofre do clube.');
        }
        $montagemInicial = !(bool)$clube['elenco_confirmado'] && $rodada === 1;
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'atualizar_inscricao_geral') {
            if (!$isMasterManagement && $campeonatoUsaCiclo && !mercado_pode_editar($clube, $rodada, $estadoClube)) throw new RuntimeException('A inscrição desta competição está congelada neste ciclo.');
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
            if (!mercado_pode_editar($clube, $rodada, $estadoClube)) throw new RuntimeException('O elenco está travado nesta rodada. Só é possível visualizar.');
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
                    $packsDaMovimentacao = mercado_packs_para_data((string)($movimento['criado_em'] ?? ''));
                    $pack = $packsDaMovimentacao[(string)($_POST['pack'] ?? '')] ?? null;
                    if (!$pack) throw new RuntimeException('Selecione o pack recebido.');
                    if ($overall < $pack['min'] || $overall > $pack['max']) throw new RuntimeException(sprintf('%s aceita jogadores com OVR entre %d e %d.', $pack['nome'], $pack['min'], $pack['max']));
                    $origemDetalhe = $pack['nome'];
                    $valorOrigem = mercado_pack_valor($pack);
                    $moedaOrigem = mercado_pack_moeda($pack);
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
            if (!mercado_pode_editar($clube, $rodada, $estadoClube) || $montagemInicial) throw new RuntimeException('O mercado está indisponível nesta rodada.');
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
                    $valorOrigem = mercado_pack_valor($pack);
                    $moedaOrigem = mercado_pack_moeda($pack);
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
$podeEditarMercado = $clube ? (!$campeonatoUsaCiclo || mercado_pode_editar($clube, $rodada, $ciclo)) : false;
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

    <style>
        /* Estilos exclusivos do conteúdo de Gestão da Competição. */
        .competition-roster .registration-heading{display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap}
        .competition-roster .registration-heading h2{margin:5px 0 10px}
        .competition-roster .registration-legend{display:flex;flex-wrap:wrap;gap:16px;margin:0 0 12px;padding:0;list-style:none}
        .competition-roster [data-state="starter"]{--state-color:#ed2338;--state-tint:rgba(237,35,56,.13)}
        .competition-roster [data-state="reserve"]{--state-color:#087ff5;--state-tint:rgba(8,127,245,.12)}
        .competition-roster [data-state="out"]{--state-color:#566570;--state-tint:rgba(86,101,112,.08)}
        .competition-roster .registration-legend li{display:flex;gap:9px;align-items:flex-start;font-size:.8rem}
        .competition-roster .registration-legend i{width:13px;height:13px;border-radius:50%;background:var(--state-color);margin-top:3px;flex-shrink:0}
        .competition-roster .registration-legend small{display:block;color:#a5b0bd;font-size:.7rem}
        .competition-roster .registration-counts{display:flex;align-items:center;flex-wrap:wrap;gap:8px 20px;margin:14px 0;color:#c5cfda;font-size:.85rem}
        .competition-roster .registration-counts strong{color:#fff}
        .competition-roster .registration-counts small{margin-left:auto;color:#a5b0bd}
        .competition-roster .registration-grid{grid-template-columns:repeat(auto-fill,minmax(min(100%,190px),1fr));gap:12px}
        .competition-roster .registration-card{min-width:0;padding:11px 9px 8px;border:1px solid var(--state-color);border-radius:10px;background:linear-gradient(135deg,var(--state-tint),#101418);display:flex;flex-direction:column;gap:9px;transition:border-color .15s,background .15s}
        .competition-roster .registration-badge{align-self:flex-start;border-radius:20px;padding:4px 10px;background:var(--state-color);color:#fff;font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.02em}
        .competition-roster .registration-name{font-size:.85rem;line-height:1.25;min-height:2.5em;overflow-wrap:anywhere;text-transform:uppercase;color:#fff;padding:0 3px}
        .competition-roster .registration-rating{display:flex;gap:10px;align-items:center;margin-top:auto;padding:0 3px}
        .competition-roster .registration-rating strong{font:800 2rem/1 'Barlow Condensed',sans-serif;color:#ef3940}
        .competition-roster .registration-rating span{font-size:.7rem;color:#a5b0bd}
        .competition-roster .registration-options{display:grid;grid-template-columns:1.2fr 1fr 1fr;gap:2px;border:1px solid #303b45;border-radius:6px;background:#151b21;padding:2px}
        .competition-roster .registration-options button{min-width:0;padding:7px 2px;min-height:34px;border:0;border-radius:4px;background:transparent;color:#bcc8d4;font-size:.65rem;cursor:pointer;transition:background .15s,color .15s}
        .competition-roster .registration-options button[aria-pressed="true"]{background:var(--state-color);color:#fff;font-weight:700}
        .competition-roster .registration-options button:hover{color:#fff;background:#303b45}
        .competition-roster .registration-options button:focus-visible{outline:2px solid #fff;outline-offset:2px}
        .market-page #elenco .roster-select-card.is-starter{border-color:#ed2338;background:linear-gradient(135deg,rgba(237,35,56,.13),#101418)}
        .market-page #elenco .roster-select-card b{overflow-wrap:anywhere;min-width:0}
        @media(min-width:1400px){.competition-roster .registration-grid{grid-template-columns:repeat(6,minmax(0,1fr))}}
        @media(max-width:575px){.competition-roster{padding:16px!important}.competition-roster .registration-legend{gap:10px}.competition-roster .registration-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.competition-roster .registration-counts small{width:100%;margin:0}.competition-roster .registration-options{grid-template-columns:1fr}.competition-roster .registration-options button{min-height:36px}}
        @media(max-width:359px){.competition-roster .registration-grid{grid-template-columns:minmax(0,1fr)}.competition-roster .registration-options{grid-template-columns:1.2fr 1fr 1fr}}
        @media(prefers-reduced-motion:reduce){.competition-roster .registration-card,.competition-roster .registration-options button{transition:none}}

        .competition-roster .roster-sector-status{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0;color:#b9c6d4;font-size:.75rem}
        .competition-roster .roster-sector-status span{padding:5px 9px;border:1px solid #36424f;border-radius:6px}
        .competition-roster .roster-sector-status .is-full{color:#fff;border-color:#668077}
        .competition-roster .roster-sector-status .is-over{color:#ffadb7;border-color:#ed2338}
        .competition-roster .roster-sector-status small{flex-basis:100%;color:#aebbc9}
        .competition-roster .roster-tools{display:grid;grid-template-columns:minmax(140px,1.4fr) minmax(120px,1fr) minmax(170px,1.2fr) auto;gap:10px;align-items:end;margin:16px 0 12px}
        .competition-roster .roster-tools label{display:grid;gap:5px;color:#aebbc9;font-size:.75rem;min-width:0}
        .competition-roster .roster-tools .form-control,.competition-roster .roster-tools .form-select{min-width:0;font-size:.8rem;min-height:40px}
        .competition-roster .roster-tools [data-roster-clear]{width:100%;height:40px;white-space:nowrap}
        .competition-roster .roster-position-filters{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}
        .competition-roster .roster-position-filters button{display:flex;align-items:center;gap:7px;min-height:38px;padding:7px 11px;border:1px solid #36424f;border-radius:7px;color:#c3ccd7;background:#151b21;font-size:.75rem;cursor:pointer}
        .competition-roster .roster-position-filters button[aria-pressed="true"]{border-color:#ed2338;background:#3a1720;color:#fff}
        .competition-roster .roster-position-filters button:hover{border-color:#aebbc9}
        .competition-roster .roster-position-filters small{color:#aebbc9;font-size:.65rem}
        .competition-roster .roster-position-filters button:focus-visible{outline:2px solid #fff;outline-offset:2px}
        .competition-roster .roster-filter-summary{margin:0 0 12px;color:#aebbc9;font-size:.75rem}
        .competition-roster .registration-card[hidden]{display:none!important}
        .competition-roster .registration-rating span{padding:3px 7px;border:1px solid #35414d;border-radius:4px;color:#d5deea;font-weight:700}
        .competition-roster .registration-options.lineup-options{grid-template-columns:1fr 1fr}
        .competition-roster .roster-filter-empty{padding:20px;border:1px dashed #44515e;border-radius:8px;color:#c3ccd7}
        @media(max-width:767px){.competition-roster .roster-tools{grid-template-columns:1fr 1fr}.competition-roster .roster-tools>label:first-child{grid-column:1/-1}.competition-roster .roster-tools>button{grid-column:1/-1}}
    </style>
</head>

<body><?php public_navbar('mercado'); ?><main class="container market-page" data-market-editable="<?= $podeEditarMercado ? '1' : '0' ?>"><span class="eyebrow"><?= $isMasterManagement ? 'Gestão Master' : 'Gestão do clube' ?></span>
        <h1>GESTÃO DA COMPETIÇÃO</h1><?php if ($managedTeam): ?><p class="market-managed-team">Gerenciando inscrição e escalação de <strong><?= e($managedTeam['time_nome']) ?></strong> · Técnico <?= e($managedTeam['nome']) ?></p><?php endif; ?><?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?><?php if ($campeonatos): ?><form method="get" class="mb-4"><?php if ($isMasterManagement): ?><input type="hidden" name="participante_id" value="<?= $participantId ?>"><?php endif; ?><label class="form-label">Competição que deseja gerenciar</label><select class="form-select" name="campeonato_id" onchange="this.form.submit()"><?php foreach ($campeonatos as $c): ?><option value="<?= $c['id'] ?>" <?= $campeonatoId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></form><?php else: ?><div class="alert alert-info mb-4">Nenhuma competição de pontos corridos está ativa para gestão.</div><?php endif; ?>
        <?php if (!$participantId): ?><div class="panel p-4">A conta precisa estar associada a um time.</div><?php elseif (!$campeonatos): ?><div class="panel p-4">Este time não está inscrito em nenhuma competição de pontos corridos ativa.</div><?php elseif ($clube && !(bool)($clube['cofre_configurado'] ?? false)): ?><section class="panel p-4 market-treasury-required"><span class="eyebrow">Primeira etapa obrigatória</span><h2>INFORME O SALDO DO COFRE</h2><p>Antes de montar o elenco ou registrar qualquer movimentação, informe o valor atual do cofre. O saldo pode ser zero, mas precisa ser confirmado pelo responsável.</p><a class="btn btn-danger" href="time.php?id=<?= $participantId ?>&editar_perfil=1">Abrir perfil e informar cofre</a></section><?php elseif ($clube): ?><section class="market-summary">
                <div><small><?= $campeonatoUsaCiclo ? 'Próxima rodada do clube' : 'Formato da competição' ?></small><strong><?= $campeonatoUsaCiclo ? $rodada.'ª' : 'MATA-MATA' ?></strong></div>
                <div><small><?= $campeonatoUsaCiclo ? 'Ciclo '.$ciclo['ciclo'] : 'Regra de inscrição' ?></small><strong><?= !$campeonatoUsaCiclo || $ciclo['aberto'] ? 'INSCRIÇÃO LIBERADA' : 'INSCRIÇÃO TRAVADA' ?></strong></div>
            </section>
            <?php if ($campeonatoUsaCiclo && !($ciclo['participacao_concluida'] ?? false) && ($ciclo['pre_estreia'] ?? false)): ?><div class="alert alert-success mb-4" role="status">
                <strong>Inscrição liberada até a estreia.</strong> O ciclo de cinco rodadas travadas começa somente depois da primeira partida disputada pelo clube.
            </div><?php elseif ($campeonatoUsaCiclo && !($ciclo['participacao_concluida'] ?? false) && !$ciclo['aberto'] && $ciclo['ciclo'] > 1): ?><div class="alert alert-warning mb-4" role="status">
                <strong>Inscrição travada neste ciclo.</strong> Novos jogadores podem continuar entrando no Elenco Geral, mas só poderão ser inscritos quando a janela reabrir. Formação, titulares e banco dos já inscritos continuam editáveis. Folgas após a estreia contam normalmente como rodada cumprida.
            </div><?php elseif ($campeonatoUsaCiclo && ($ciclo['participacao_concluida'] ?? false)): ?><div class="alert alert-success mb-4" role="status">
                <strong>Participação concluída.</strong> O clube já cumpriu todas as partidas desta competição; vendas, edições e alterações de inscrição estão liberadas, mesmo que os demais times ainda tenham jogos pendentes.
            </div><?php elseif ($campeonatoUsaCiclo && $ciclo['aberto']): ?><div class="alert alert-success mb-4" role="status">
                <strong>Janela de inscrição liberada.</strong> Todos os jogadores ativos do Elenco Geral já aparecem como opções para montar a nova lista da competição.
            </div><?php endif; ?>
            <section class="market-help-grid" aria-label="Ajuda para gestão do elenco">
                <article><div><strong><?= $campeonatoUsaCiclo ? 'Janela de inscrição' : 'Mata-mata sem ciclo' ?></strong><p><?= $campeonatoUsaCiclo ? e(mercado_descricao_janela($ciclo)) : 'Esta competição não usa janela por rodadas. Os jogadores do Elenco Geral permanecem disponíveis para montar a inscrição.' ?></p></div></article>
                <article><div><strong>Titulares automáticos</strong><p>Marque somente os 11 titulares. Ao salvar, todos os jogadores não selecionados serão definidos automaticamente como banco.</p></div></article>
                <article><div><strong>Formação e ordem automáticas</strong><p>Os titulares precisam respeitar os setores da formação. O sistema ordena ataque, meio, defesa e deixa o goleiro sempre por último.</p></div></article>
                <article><div><strong>Inscrição e escalação</strong><p>A janela controla quais jogadores fazem parte da competição. Formação, titulares e banco podem ser reorganizados a qualquer momento entre os jogadores inscritos.</p></div></article>
            </section>
            <?php if ($montagemInicial): ?><section class="panel p-4 mb-4 market-config-panel">
                    <h2>CONFIGURAÇÃO INICIAL</h2>
                    <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="configurar_inicial">
                        <div class="col-md-6 formation-control"><label class="form-label">Formação</label><select class="form-select" name="formacao"><?php foreach (MERCADO_FORMACOES as $f): ?><option value="<?= e($f) ?>" <?= $clube['formacao'] === $f ? 'selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?><option value="__custom__" <?= !in_array($clube['formacao'], MERCADO_FORMACOES, true) ? 'selected' : '' ?>>Formação customizada</option></select><input class="form-control mt-2" name="formacao_custom" inputmode="numeric" maxlength="14" placeholder="Ex.: 433 ou 4-3-3" value="<?= !in_array($clube['formacao'], MERCADO_FORMACOES, true) && preg_match('/([1-9])-([1-9])-([1-9])/', $clube['formacao'], $formacaoAtual) ? e($formacaoAtual[1] . '-' . $formacaoAtual[2] . '-' . $formacaoAtual[3]) : '' ?>"><small class="text-secondary">O sistema adiciona “Custom” automaticamente.</small></div>
                        <div><button class="btn btn-danger">Salvar configuração</button></div>
                    </form>
                </section><?php endif; ?>
            <div id="gestao-competicao" class="market-anchor" aria-hidden="true"></div>
            <?php if ($podeEditarInscricao): ?><section class="panel p-4 mb-4 competition-roster">
                <div class="registration-heading">
                    <div><span class="eyebrow"><?= $isMasterManagement && !$podeEditarMercado ? 'Acesso Master · ciclo ignorado' : 'Janela aberta · todos os jogadores disponíveis' ?></span><h2>INSCRIÇÃO NA COMPETIÇÃO</h2></div>
                    <ul class="registration-legend" aria-label="Estados de inscrição">
                        <li data-state="starter"><i aria-hidden="true"></i><div><b>Titular</b><small>Equipe inicial · máximo de 11</small></div></li>
                        <li data-state="reserve"><i aria-hidden="true"></i><div><b>Reserva</b><small>Inscrito e disponível no banco</small></div></li>
                        <li data-state="out"><i aria-hidden="true"></i><div><b>Não inscrito</b><small>Fora desta competição</small></div></li>
                    </ul>
                </div>
                <p class="text-secondary">Escolha exatamente 11 titulares e até 15 reservas do Elenco Geral. As alterações serão aplicadas ao salvar a inscrição.</p>
                <form method="post" data-registration-form data-roster-form="registration" data-saved-formation="<?= e($clube['formacao']) ?>">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="atualizar_inscricao_geral"><?php if($isMasterManagement): ?><input type="hidden" name="participante_id" value="<?= $participantId ?>"><?php endif; ?>
                    <div class="registration-counts" role="status" aria-live="polite"><strong><span data-registration-starters><?= count($titularesGerais) ?></span>/11 titulares selecionados</strong><span><span data-registration-reserves><?= count($inscritosGerais) - count($titularesGerais) ?></span>/15 reservas</span><small data-registration-unsaved>Inscrição atual</small></div>
                    <noscript><p class="alert alert-warning">Ative o JavaScript para alterar os estados e salvar a inscrição.</p></noscript>
                    <div class="roster-grid registration-grid">
                        <?php foreach($elencoGeral as $j): $gid=(int)$j['id']; $estado=isset($titularesGerais[$gid])?'starter':(isset($inscritosGerais[$gid])?'reserve':'out'); ?>
                        <article class="registration-card" data-state="<?= $estado ?>" data-position="<?= e($j['posicao']) ?>" data-overall="<?= (int)$j['overall'] ?>">
                            <!-- Campos legados preservados para o backend e o modal de confirmação. -->
                            <input hidden type="checkbox" name="inscrito_id[]" value="<?= $gid ?>" <?= isset($inscritosGerais[$gid])?'checked':'' ?>>
                            <input hidden type="checkbox" name="titular_geral_id[]" value="<?= $gid ?>" <?= isset($titularesGerais[$gid])?'checked':'' ?>>
                            <span class="registration-badge"><?= ['starter'=>'Titular','reserve'=>'Reserva','out'=>'Não inscrito'][$estado] ?></span>
                            <b class="registration-name" id="registration-player-<?= $gid ?>"><?= e($j['nome']) ?></b>
                            <div class="registration-rating"><strong><?= (int)$j['overall'] ?></strong><span><?= e($j['posicao']) ?></span></div>
                            <div class="registration-options" role="group" aria-labelledby="registration-player-<?= $gid ?>">
                                <?php foreach(['out'=>'Não inscrito','reserve'=>'Reserva','starter'=>'Titular'] as $valor=>$rotulo): ?><button type="button" data-registration-state="<?= $valor ?>" aria-pressed="<?= $estado===$valor?'true':'false' ?>"><?= $rotulo ?></button><?php endforeach; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$elencoGeral): ?><p class="text-secondary">Nenhum jogador ativo no Elenco Geral.</p><?php endif; ?>
                    <button class="btn btn-danger mt-3" data-registration-save disabled>Salvar inscrição</button>
                </form>
            </section><?php else: ?><div class="alert alert-warning mb-4"><strong>Inscrição congelada.</strong> Os jogadores contratados agora ficam no Elenco Geral e aparecerão automaticamente aqui quando a próxima janela abrir. A escalação dos já inscritos continua editável abaixo.</div><?php endif; ?>
            <section class="panel p-4 mb-4 competition-roster" id="elenco">
                <div class="d-flex justify-content-between">
                    <h2>ELENCO</h2>
                </div><form method="post" data-roster-form="lineup"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><input type="hidden" name="action" value="atualizar_escalacao"><div class="formation-control mb-3"><label class="form-label">Formação</label><select class="form-select" name="formacao"><?php foreach (MERCADO_FORMACOES as $f): ?><option value="<?= e($f) ?>" <?= $clube['formacao'] === $f ? 'selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?><option value="__custom__" <?= !in_array($clube['formacao'], MERCADO_FORMACOES, true) ? 'selected' : '' ?>>Formação customizada</option></select><input class="form-control mt-2" name="formacao_custom" inputmode="numeric" maxlength="14" placeholder="Ex.: 433 ou 4-3-3" value="<?= !in_array($clube['formacao'], MERCADO_FORMACOES, true) && preg_match('/([1-9])-([1-9])-([1-9])/', $clube['formacao'], $formacaoAtual) ? e($formacaoAtual[1] . '-' . $formacaoAtual[2] . '-' . $formacaoAtual[3]) : '' ?>"><small class="text-secondary">Três números que somem 10; “Custom” será adicionado automaticamente.</small></div>
                        <div class="lineup-selection-status"><strong hidden><span data-selected-starters>0</span>/11 titulares selecionados</strong><small>Use <code>..time @seu_usuario</code> no Discord para visualizar apenas a imagem do seu time e conferir os titulares. Quem não estiver marcado será banco.</small></div><div class="lineup-limit-warning" role="alert" aria-live="assertive" hidden>Você já selecionou os 11 titulares. Desmarque um jogador antes de escolher outro.</div>
                        <p class="text-secondary">Selecione os 11 titulares. Os demais inscritos ficam na reserva.</p>
                        <div class="registration-counts" role="status" aria-live="polite"><strong><span data-registration-starters><?= $totalTitularesAtual ?></span>/11 titulares selecionados</strong><span><span data-registration-reserves><?= count($elenco) - $totalTitularesAtual ?></span> reservas</span><small data-registration-unsaved>Escalação atual</small></div>
                        <div class="roster-grid registration-grid">
                            <?php foreach ($elenco as $j): $estado=$j['grupo']==='titular'?'starter':'reserve'; ?>
                            <article class="roster-select-card registration-card<?= $estado==='starter'?' is-starter':'' ?>" data-state="<?= $estado ?>" data-position="<?= e($j['posicao']) ?>" data-overall="<?= (int)$j['overall'] ?>">
                                <input type="hidden" name="jogador_id[]" value="<?= (int)$j['id'] ?>">
                                <input hidden type="checkbox" name="titular_id[]" value="<?= (int)$j['id'] ?>" <?= $estado==='starter'?'checked':'' ?>>
                                <span class="registration-badge"><?= $estado==='starter'?'Titular':'Reserva' ?></span>
                                <b class="registration-name" id="lineup-player-<?= (int)$j['id'] ?>"><?= e($j['nome']) ?></b>
                                <div class="registration-rating"><strong><?= (int)$j['overall'] ?></strong><span><?= e($j['posicao']) ?></span></div>
                                <div class="registration-options lineup-options" role="group" aria-labelledby="lineup-player-<?= (int)$j['id'] ?>">
                                    <?php foreach(['reserve'=>'Reserva','starter'=>'Titular'] as $valor=>$rotulo): ?><button type="button" data-registration-state="<?= $valor ?>" aria-pressed="<?= $estado===$valor?'true':'false' ?>"><?= $rotulo ?></button><?php endforeach; ?>
                                </div>
                            </article>
                            <?php endforeach; ?>
                        </div><button class="btn btn-danger mt-3" <?= !(bool)$clube['elenco_confirmado'] ? 'name="confirmar_elenco" value="1"' : '' ?>><?= !(bool)$clube['elenco_confirmado'] ? 'Salvar e confirmar 11 titulares' : 'Salvar escalação' ?></button>
                    </form>
            </section>
            <?php if (false): ?><section class="panel p-4 market-history" data-market-history data-items-per-page="4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h2>HISTÓRICO</h2><div class="history-filters" role="group" aria-label="Filtrar histórico"><button class="active" type="button" data-history-filter="todas">Todas</button><button type="button" data-history-filter="compra">Compras</button><button type="button" data-history-filter="venda">Vendas</button></div></div>
                <div class="history-items"><?php foreach ($historico as $m): ?><?php $packMovimento = ''; foreach (MERCADO_PACKS as $packId => $packDados) { if (($m['origem_detalhe'] ?? '') === $packDados['nome']) { $packMovimento = $packId; break; } } ?><article data-history-type="<?= e($m['tipo']) ?>"><span class="history-kind <?= $m['tipo'] === 'compra' ? 'is-purchase' : 'is-sale' ?>"><?= e(mercado_rotulo_origem($m)) ?></span><div><strong><?= e($m['jogador_nome']) ?></strong><small><?= (int)$m['jogador_overall'] ?> · <?= e($m['jogador_posicao']) ?> · rodada <?= $m['rodada'] ?><?= !empty($m['origem_detalhe']) ? ' · ' . e($m['origem_detalhe']) : '' ?> · <?= e(format_datetime_br((string)$m['criado_em'])) ?></small></div><b><?= e(mercado_valor_movimento($m)) ?></b><div class="history-actions"><button type="button" class="btn btn-sm btn-outline-light" data-edit-movement data-movement-id="<?= (int)$m['id'] ?>" data-movement-type="<?= e($m['tipo']) ?>" data-player-name="<?= e($m['jogador_nome']) ?>" data-player-overall="<?= (int)$m['jogador_overall'] ?>" data-player-position="<?= e($m['jogador_posicao']) ?>" data-movement-origin="<?= e($m['origem']) ?>" data-movement-pack="<?= e($packMovimento) ?>" data-movement-value="<?= (float)$m['valor'] ?>">Editar</button><button type="button" class="btn btn-sm btn-outline-danger" data-undo-movement data-movement-id="<?= (int)$m['id'] ?>" data-movement-type="<?= e($m['tipo']) ?>" data-player-name="<?= e($m['jogador_nome']) ?>">Desfazer</button></div></article><?php endforeach; ?><?php if (!$historico): ?><p class="text-secondary">Nenhuma movimentação.</p><?php endif; ?></div><nav class="history-pages card-pages"></nav>
            </section>
            <div class="modal fade market-movement-modal" id="market-movement-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><small class="eyebrow">Corrigir histórico</small><h2 class="modal-title">EDITAR MOVIMENTAÇÃO</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><form method="post"><div class="modal-body"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><?php if ($isMasterManagement): ?><input type="hidden" name="participante_id" value="<?= $participantId ?>"><?php endif; ?><input type="hidden" name="action" value="editar_movimentacao"><input type="hidden" name="movimentacao_id"><div class="row g-3"><div class="col-12"><label class="form-label">Jogador</label><input class="form-control" name="nome" required></div><div class="col-6"><label class="form-label">Overall</label><input class="form-control" type="number" min="1" max="99" name="overall" required></div><div class="col-6"><label class="form-label">Posição</label><select class="form-select" name="posicao"><?php foreach (MERCADO_POSICOES as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?></option><?php endforeach; ?></select></div><div class="col-12 movement-origin-field"><label class="form-label">Origem da contratação</label><select class="form-select" name="origem"><option value="compra_direta">Compra direta</option><option value="pack">Recebido em pack</option><option value="passe">Recebido no passe</option><option value="sorteio">Ganho em sorteio</option><option value="prancheta">Recebido pela prancheta</option></select></div><div class="col-12 movement-pack-field" hidden><label class="form-label">Pack recebido</label><select class="form-select" name="pack"><option value="">Selecione o pack</option><?php foreach (MERCADO_PACKS as $packId => $pack): ?><option value="<?= e($packId) ?>"><?= e($pack['nome']) ?> · <?= e(mercado_pack_preco($pack)) ?></option><?php endforeach; ?></select></div><div class="col-12 movement-value-field"><label class="form-label">Valor em reais</label><input class="form-control" type="number" min="0" step="1" name="valor"></div><div class="col-12"><div class="alert alert-info mb-0 movement-edit-note"></div></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger">Salvar correção</button></div></form></div></div></div>
            <div class="modal fade market-movement-modal" id="market-undo-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><small class="eyebrow">Ação definitiva</small><h2 class="modal-title">DESFAZER MOVIMENTAÇÃO</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><form method="post"><div class="modal-body"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="campeonato_id" value="<?= $campeonatoId ?>"><?php if ($isMasterManagement): ?><input type="hidden" name="participante_id" value="<?= $participantId ?>"><?php endif; ?><input type="hidden" name="action" value="desfazer_movimentacao"><input type="hidden" name="movimentacao_id"><p class="movement-undo-copy"></p><div class="alert alert-warning mb-0 movement-undo-detail"></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Voltar</button><button class="btn btn-danger">Sim, desfazer</button></div></form></div></div></div><?php endif; ?>
            <?php endif; ?>
    </main><?php public_footer(); ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const positions = ['ATA', 'PD', 'PE', 'MEI', 'MC', 'VOL', 'LE', 'LD', 'ZAG', 'GOL'];
    const positionNames = {ATA:'Atacantes', PD:'Pontas direitas', PE:'Pontas esquerdas', MEI:'Meias ofensivos', MC:'Meias centrais', VOL:'Volantes', LE:'Laterais esquerdos', LD:'Laterais direitos', ZAG:'Zagueiros', GOL:'Goleiros'};
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR');
    document.querySelectorAll('[data-roster-form]').forEach(form => {
        const registration = form.dataset.rosterForm === 'registration';
        const cards = [...form.querySelectorAll('.registration-card')];
        const grid = form.querySelector('.registration-grid');
        const labels = {out: 'Não inscrito', reserve: 'Reserva', starter: 'Titular'};
        const counts = () => ({starters: cards.filter(card => card.dataset.state === 'starter').length, reserves: cards.filter(card => card.dataset.state === 'reserve').length});
        const snapshot = () => cards.map(card => card.dataset.state).join(',') + '|' + (form.querySelector('[name="formacao"]')?.value || '') + '|' + (form.querySelector('[name="formacao_custom"]')?.value || '');
        const initial = snapshot();
        const notify = message => askClubConfirmation(registration ? 'REVISE A INSCRIÇÃO' : 'REVISE A ESCALAÇÃO', message, 'Entendi');
        const sectorNames = {ataque: 'Ataque', meio: 'Meio', defesa: 'Defesa', goleiro: 'Goleiro'};
        const sectorOf = card => ({ATA:'ataque', PD:'ataque', PE:'ataque', MEI:'meio', MC:'meio', VOL:'meio', LE:'defesa', LD:'defesa', ZAG:'defesa', GOL:'goleiro'}[card.dataset.position] || 'meio');
        const formationSelect = form.querySelector('[name="formacao"]');
        const formationCustom = form.querySelector('[name="formacao_custom"]');
        function formationLimits() {
            let value = registration ? form.dataset.savedFormation : formationSelect?.value;
            if (value === '__custom__') value = formationCustom.value.trim().replace(/^([1-9])([1-9])([1-9])$/, '$1-$2-$3');
            const match = (value || '').match(/^([1-9](?:-[1-9]){2,3})/);
            if (!match) return null;
            const lines = match[1].split('-').map(Number);
            if (lines.reduce((a, b) => a + b, 0) !== 10) return null;
            return {ataque:lines[lines.length - 1], meio:lines.slice(1,-1).reduce((a,b) => a+b,0), defesa:lines[0], goleiro:1};
        }
        const sectorCounts = () => cards.filter(card => card.dataset.state === 'starter').reduce((total, card) => {total[sectorOf(card)]++; return total;}, {ataque:0, meio:0, defesa:0, goleiro:0});
        const sectorStatus = document.createElement('div');
        sectorStatus.className = 'roster-sector-status';
        sectorStatus.setAttribute('role', 'status');
        form.querySelector('.registration-counts').after(sectorStatus);
        function updateSectors() {
            const limits = formationLimits();
            const selected = sectorCounts();
            sectorStatus.replaceChildren();
            if (!limits) { sectorStatus.textContent = 'Escolha uma formação válida para conferir os limites por setor.'; return; }
            Object.keys(sectorNames).forEach(sector => {
                const item = document.createElement('span');
                item.textContent = sectorNames[sector] + ' ' + selected[sector] + '/' + limits[sector];
                item.className = selected[sector] > limits[sector] ? 'is-over' : selected[sector] === limits[sector] ? 'is-full' : '';
                sectorStatus.append(item);
            });
            const hint = document.createElement('small');
            hint.textContent = registration ? 'Limites da formação salva: ' + form.dataset.savedFormation + '. Para mudá-la, salve a formação no elenco abaixo.' : 'Limites por setor da formação selecionada. Os demais inscritos ficam na reserva.';
            sectorStatus.append(hint);
        }
        formationSelect?.addEventListener('change', updateCounts);
        formationCustom?.addEventListener('input', updateCounts);
        const tools = document.createElement('div');
        tools.className = 'roster-tools';
        tools.innerHTML = '<label>Buscar jogador<input class="form-control" type="search" placeholder="Nome do jogador" data-roster-search></label><label>Estado<select class="form-select" data-roster-status><option value="all">Todos os estados</option><option value="starter">Titulares</option><option value="reserve">Reservas</option>' + (registration ? '<option value="out">Não inscritos</option>' : '') + '</select></label><label>Ordenar dentro da posição<select class="form-select" data-roster-sort><option value="desc">Maior overall</option><option value="asc">Menor overall</option><option value="name">Nome: A → Z</option></select></label><button class="btn btn-outline-light btn-sm" type="button" data-roster-clear>Limpar filtros</button>';
        const positionFilters = document.createElement('div');
        positionFilters.className = 'roster-position-filters';
        positionFilters.setAttribute('role', 'group');
        positionFilters.setAttribute('aria-label', 'Filtrar por posição, do ataque ao gol');
        const availablePositions = [...positions, ...new Set(cards.map(card => card.dataset.position).filter(position => !positions.includes(position)))];
        ['all', ...availablePositions.filter(position => cards.some(card => card.dataset.position === position))].forEach(position => {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.positionFilter = position;
            button.setAttribute('aria-pressed', String(position === 'all'));
            const count = position === 'all' ? cards.length : cards.filter(card => card.dataset.position === position).length;
            button.setAttribute('aria-label', (position === 'all' ? 'Todas as posições' : positionNames[position] || position) + ' (' + count + ')');
            button.textContent = position === 'all' ? 'Todas' : position;
            const total = document.createElement('small');
            total.textContent = count;
            total.setAttribute('aria-hidden', 'true');
            button.append(total);
            positionFilters.append(button);
        });
        const summary = document.createElement('p');
        summary.className = 'roster-filter-summary';
        summary.setAttribute('role', 'status');
        const empty = document.createElement('p');
        empty.className = 'roster-filter-empty';
        empty.textContent = 'Nenhum jogador encontrado. Limpe os filtros para ver o elenco completo.';
        empty.hidden = true;
        grid.before(tools, positionFilters, summary);
        grid.after(empty);
        let selectedPosition = 'all';
        const search = tools.querySelector('[data-roster-search]');
        const status = tools.querySelector('[data-roster-status]');
        const sort = tools.querySelector('[data-roster-sort]');
        function filterAndSort() {
            const focused = document.activeElement;
            const query = normalize(search.value.trim());
            const rank = card => { const index = positions.indexOf(card.dataset.position); return index < 0 ? positions.length : index; };
            const ordered = [...cards].sort((a, b) => {
                const position = rank(a) - rank(b) || a.dataset.position.localeCompare(b.dataset.position, 'pt-BR');
                const overall = sort.value === 'name' ? 0 : (Number(b.dataset.overall) - Number(a.dataset.overall)) * (sort.value === 'asc' ? -1 : 1);
                return position || overall || a.querySelector('.registration-name').textContent.localeCompare(b.querySelector('.registration-name').textContent, 'pt-BR');
            });
            let visible = 0;
            ordered.forEach(card => {
                card.hidden = (selectedPosition !== 'all' && card.dataset.position !== selectedPosition) || (status.value !== 'all' && card.dataset.state !== status.value) || !normalize(card.querySelector('.registration-name').textContent).includes(query);
                if (!card.hidden) visible++;
                grid.append(card);
            });
            summary.textContent = visible + ' de ' + cards.length + ' jogadores · Posição primeiro, do ataque ao gol · Filtros não alteram a seleção';
            empty.hidden = visible !== 0;
            if (grid.contains(focused) && !focused.closest('.registration-card').hidden) focused.focus({preventScroll:true});
        }
        tools.addEventListener('input', filterAndSort);
        tools.addEventListener('change', filterAndSort);
        positionFilters.addEventListener('click', event => {
            const button = event.target.closest('[data-position-filter]');
            if (!button) return;
            selectedPosition = button.dataset.positionFilter;
            positionFilters.querySelectorAll('button').forEach(option => option.setAttribute('aria-pressed', String(option === button)));
            filterAndSort();
        });
        tools.querySelector('[data-roster-clear]').addEventListener('click', () => {
            search.value = ''; status.value = 'all'; sort.value = 'desc'; selectedPosition = 'all';
            positionFilters.querySelectorAll('button').forEach(option => option.setAttribute('aria-pressed', String(option.dataset.positionFilter === 'all')));
            filterAndSort();
        });
        function updateCounts() {
            updateSectors();
            const count = counts();
            form.querySelector('[data-registration-starters]').textContent = count.starters;
            form.querySelector('[data-registration-reserves]').textContent = count.reserves;
            form.querySelector('[data-registration-unsaved]').textContent = snapshot() === initial ? (registration ? 'Inscrição atual' : 'Escalação atual') : 'Alterações não salvas';
        }
        function setState(card, state) {
            card.dataset.state = state;
            const registered = card.querySelector('input[name="inscrito_id[]"]');
            if (registered) registered.checked = state !== 'out';
            const starter = card.querySelector(registration ? 'input[name="titular_geral_id[]"]' : 'input[name="titular_id[]"]');
            starter.checked = state === 'starter';
            if (!registration) starter.dispatchEvent(new Event('change', {bubbles: true}));
            card.querySelector('.registration-badge').textContent = labels[state];
            card.querySelectorAll('[data-registration-state]').forEach(option => option.setAttribute('aria-pressed', String(option.dataset.registrationState === state)));
            delete form.dataset.confirmedSubmit;
        }
        form.addEventListener('click', event => {
            const button = event.target.closest('[data-registration-state]');
            if (!button) return;
            const card = button.closest('.registration-card');
            const state = button.dataset.registrationState;
            if (state === card.dataset.state) return;
            const count = counts();
            if (state === 'starter' && count.starters >= 11) {
                notify('Você já selecionou os 11 titulares. Coloque um titular na reserva antes de escolher outro.');
                return;
            }
            if (state === 'starter') {
                const limits = formationLimits();
                const sector = sectorOf(card);
                if (!limits) { notify('Escolha uma formação válida antes de selecionar os titulares.'); return; }
                if (sectorCounts()[sector] >= limits[sector]) {
                    notify('Limite máximo de ' + sectorNames[sector].toLocaleLowerCase('pt-BR') + ': ' + limits[sector] + '. Coloque um titular desse setor na reserva antes de escolher outro.');
                    return;
                }
            }
            if (registration && state === 'reserve' && count.reserves >= 15) {
                notify('O limite é de 15 reservas. Mude o estado de um reserva antes de escolher outro.');
                return;
            }
            setState(card, state);
            updateCounts();
            filterAndSort();
            if (card.hidden) status.focus();
        });
        form.addEventListener('submit', event => {
            const count = counts();
            const limits = formationLimits();
            const sectors = sectorCounts();
            const validSectors = limits && Object.keys(sectorNames).every(sector => sectors[sector] === limits[sector]);
            if (count.starters === 11 && (!registration || count.reserves <= 15) && validSectors) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            delete form.dataset.confirmedSubmit;
            notify(count.starters !== 11 ? 'Selecione exatamente 11 titulares antes de salvar.' : registration && count.reserves > 15 ? 'A inscrição permite no máximo 15 reservas.' : 'Ajuste os titulares aos limites de ataque, meio, defesa e goleiro da formação.');
        }, true);
        updateCounts();
        filterAndSort();
        const save = form.querySelector('[data-registration-save]');
        if (save) save.disabled = false;
    });
});
</script>
</body>

</html>
