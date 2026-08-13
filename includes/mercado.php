<?php
declare(strict_types=1);

const MERCADO_FORMACOES = ['4-3-3','4-3-3O Custom','4-4-2','4-2-3-1','3-5-2','3-4-3','5-3-2','5-4-1'];
const MERCADO_POSICOES = ['GOL','LD','LE','ZAG','VOL','MC','MEI','PD','PE','ATA'];

function mercado_rodada_atual(PDO $pdo, int $campeonatoId): int
{
    $stmt = $pdo->prepare("SELECT MIN(rodada) FROM partidas WHERE campeonato_id=? AND ativo=1 AND status NOT IN ('finalizada','wo')");
    $stmt->execute([$campeonatoId]);
    $rodada = $stmt->fetchColumn();
    if ($rodada !== false && $rodada !== null) return max(1,(int)$rodada);
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(rodada),0)+1 FROM partidas WHERE campeonato_id=? AND ativo=1");
    $stmt->execute([$campeonatoId]);
    return max(1,(int)$stmt->fetchColumn());
}

function mercado_aberto_na_rodada(int $rodada): bool
{
    $posicaoNoCiclo = (($rodada - 1) % 8) + 1;
    return $posicaoNoCiclo >= 6;
}

function mercado_estado_ciclo(int $rodada): array
{
    $posicao = (($rodada - 1) % 8) + 1;
    $ciclo = intdiv($rodada - 1,8) + 1;
    return ['ciclo'=>$ciclo,'posicao'=>$posicao,'aberto'=>$posicao >= 6,'restantes'=>$posicao >= 6 ? 9-$posicao : 6-$posicao];
}

function mercado_clube(PDO $pdo, int $campeonatoId, int $participanteId, bool $lock=false): array
{
    $pdo->prepare("INSERT IGNORE INTO clubes_campeonato(campeonato_id,participante_id) VALUES(?,?)")->execute([$campeonatoId,$participanteId]);
    $stmt = $pdo->prepare("SELECT * FROM clubes_campeonato WHERE campeonato_id=? AND participante_id=?".($lock?' FOR UPDATE':''));
    $stmt->execute([$campeonatoId,$participanteId]);
    return $stmt->fetch();
}

function mercado_pode_editar(array $clube, int $rodada): bool
{
    return !(bool)$clube['elenco_confirmado'] || mercado_aberto_na_rodada($rodada);
}
