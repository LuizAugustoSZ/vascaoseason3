<?php
declare(strict_types=1);

function elenco_origens_jogadores(PDO $pdo, int $teamId): array
{
    $origins = [];
    $stmt = $pdo->prepare("SELECT jogador_geral_id,origem,origem_detalhe FROM movimentacoes_elenco_geral WHERE participante_id=? AND tipo='compra' ORDER BY criado_em DESC,id DESC");
    $stmt->execute([$teamId]);
    foreach ($stmt->fetchAll() as $row) if (!isset($origins[(int)$row['jogador_geral_id']])) $origins[(int)$row['jogador_geral_id']] = $row;
    $stmt = $pdo->prepare("SELECT e.jogador_geral_id,m.origem,m.origem_detalhe FROM movimentacoes_elenco m JOIN jogadores_elenco e ON e.id=m.jogador_id AND e.participante_id=m.participante_id WHERE m.participante_id=? AND m.tipo='compra' AND e.jogador_geral_id IS NOT NULL ORDER BY m.criado_em DESC,m.id DESC");
    $stmt->execute([$teamId]);
    foreach ($stmt->fetchAll() as $row) if (!isset($origins[(int)$row['jogador_geral_id']])) $origins[(int)$row['jogador_geral_id']] = $row;
    return $origins;
}

function elenco_carta_de_passe(string $origin): bool
{
    return in_array($origin, ['passe', 'troca_passe'], true);
}

/** Caller locks the club balance and owns the transaction. */
function elenco_trocar_passe(PDO $pdo, int $teamId, int $playerId, int $accountId, float $balance, ?array $replacement): string
{
    if (!$pdo->inTransaction()) throw new RuntimeException('A troca deve ser registrada em uma transação.');
    $stmt = $pdo->prepare('SELECT * FROM jogadores_gerais WHERE id=? AND participante_id=? AND ativo=1 FOR UPDATE');
    $stmt->execute([$playerId,$teamId]); $player = $stmt->fetch();
    if (!$player) throw new RuntimeException('Jogador não encontrado no clube.');
    $origin = elenco_origens_jogadores($pdo,$teamId)[$playerId]['origem'] ?? '';
    if (!elenco_carta_de_passe($origin)) throw new RuntimeException('Apenas cartas recebidas no passe ou por troca de passe podem ser trocadas.');
    $stmt = $pdo->prepare("SELECT DISTINCT e.campeonato_id,c.nome,c.tipo FROM jogadores_elenco e JOIN campeonatos c ON c.id=e.campeonato_id WHERE e.jogador_geral_id=? AND e.participante_id=? AND e.ativo=1");
    $stmt->execute([$playerId,$teamId]);
    foreach ($stmt->fetchAll() as $entry) if ($entry['tipo']==='pontos_corridos' && mercado_venda_bloqueada(mercado_estado_clube($pdo,(int)$entry['campeonato_id'],$teamId))) throw new RuntimeException($player['nome'].' está inscrito em '.$entry['nome'].', cujo ciclo está fechado.');
    $existing = null;
    if ($replacement !== null) {
        $replacement['nome'] = trim((string)($replacement['nome']??''));
        $replacement['overall'] = (int)($replacement['overall']??0);
        $replacement['posicao'] = (string)($replacement['posicao']??'');
        if ($replacement['nome']==='' || mb_strlen($replacement['nome'])>150 || $replacement['overall']<1 || $replacement['overall']>99 || !in_array($replacement['posicao'],MERCADO_POSICOES,true)) throw new RuntimeException('Informe nome completo, OVR e posição válidos para a carta recebida.');
        $stmt = $pdo->prepare('SELECT id,ativo FROM jogadores_gerais WHERE participante_id=? AND nome=? AND overall=? AND posicao=? FOR UPDATE');
        $stmt->execute([$teamId,$replacement['nome'],$replacement['overall'],$replacement['posicao']]); $existing=$stmt->fetch();
        if ($existing && (bool)$existing['ativo']) throw new RuntimeException('A carta recebida já está no Elenco Geral.');
    }
    $pdo->prepare('UPDATE jogadores_gerais SET ativo=0,saiu_em=NOW() WHERE id=? AND participante_id=?')->execute([$playerId,$teamId]);
    $pdo->prepare('UPDATE jogadores_elenco SET ativo=0,saiu_em=NOW() WHERE jogador_geral_id=? AND participante_id=? AND ativo=1')->execute([$playerId,$teamId]);
    $insert = $pdo->prepare("INSERT INTO movimentacoes_elenco_geral(participante_id,jogador_geral_id,tipo,origem,origem_detalhe,jogador_nome,jogador_overall,jogador_posicao,valor,saldo_anterior,saldo_posterior,conta_id) VALUES(?,?,?,?,?,?,?,?,0,?,?,?)");
    $insert->execute([$teamId,$playerId,'troca','troca_passe',$replacement ? mb_substr('Trocado por '.$replacement['nome'],0,120) : null,$player['nome'],$player['overall'],$player['posicao'],$balance,$balance,$accountId]);
    if ($replacement !== null) {
        if ($existing) {
            $newId=(int)$existing['id'];
            $pdo->prepare('UPDATE jogadores_gerais SET ativo=1,entrou_em=NOW(),saiu_em=NULL WHERE id=? AND participante_id=?')->execute([$newId,$teamId]);
        } else {
            $pdo->prepare('INSERT INTO jogadores_gerais(participante_id,nome,overall,posicao) VALUES(?,?,?,?)')->execute([$teamId,$replacement['nome'],$replacement['overall'],$replacement['posicao']]);
            $newId=(int)$pdo->lastInsertId();
        }
        $insert->execute([$teamId,$newId,'compra','troca_passe',mb_substr('Recebido pela troca de '.$player['nome'],0,120),$replacement['nome'],$replacement['overall'],$replacement['posicao'],$balance,$balance,$accountId]);
    }
    return $player['nome'].' saiu por troca de passe.'.($replacement ? ' '.$replacement['nome'].' entrou no Elenco Geral.' : '').' O cofre permanece igual.';
}
