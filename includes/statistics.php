<?php

declare(strict_types=1);

function statistics_matches(PDO $pdo, int $championshipId = 0, int $clubId = 0): array
{
    $sql = "SELECT 'pontos' origem,p.id,p.campeonato_id,c.nome campeonato,CONCAT('Rodada ',p.rodada) etapa,
                   p.mandante_id time_a_id,p.visitante_id time_b_id,p.gols_mandante gols_a,p.gols_visitante gols_b,
                   p.data_partida data_jogo,m.time_nome time_a,m.sigla sigla_a,m.escudo_url escudo_a,
                   v.time_nome time_b,v.sigla sigla_b,v.escudo_url escudo_b
            FROM partidas p JOIN campeonatos c ON c.id=p.campeonato_id
            JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id
            WHERE p.ativo=1 AND p.status IN('finalizada','wo','penalidade') AND p.gols_mandante IS NOT NULL AND p.gols_visitante IS NOT NULL
            UNION ALL
            SELECT 'mata',j.id,j.campeonato_id,c.nome,CONCAT(j.fase,IF(j.ordem>0,CONCAT(' ',j.ordem),'')),
                   j.time_a_id,j.time_b_id,j.gols_a,j.gols_b,COALESCE(c.data_inicio,j.criado_em),
                   a.time_nome,a.sigla,a.escudo_url,b.time_nome,b.sigla,b.escudo_url
            FROM jogos_mata_mata j JOIN campeonatos c ON c.id=j.campeonato_id
            JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id
            WHERE j.ativo=1 AND j.status IN('finalizado','wo') AND j.gols_a IS NOT NULL AND j.gols_b IS NOT NULL";
    $rows = $pdo->query($sql)->fetchAll();
    return array_values(array_filter($rows, static function (array $row) use ($championshipId, $clubId): bool {
        return (!$championshipId || (int)$row['campeonato_id'] === $championshipId)
            && (!$clubId || (int)$row['time_a_id'] === $clubId || (int)$row['time_b_id'] === $clubId);
    }));
}

function statistics_team_aggregates(array $matches): array
{
    $teams = [];
    foreach ($matches as $match) {
        $a=(int)$match['time_a_id'];$b=(int)$match['time_b_id'];$ga=(int)$match['gols_a'];$gb=(int)$match['gols_b'];
        foreach ([[$a,'a',$ga,$gb],[$b,'b',$gb,$ga]] as [$id,$side,$gf,$gc]) {
            if (!isset($teams[$id])) $teams[$id]=['id'=>$id,'name'=>$match['time_'.$side],'sigla'=>$match['sigla_'.$side],'shield'=>$match['escudo_'.$side],'games'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'gf'=>0,'ga'=>0];
            $teams[$id]['games']++;$teams[$id]['gf']+=$gf;$teams[$id]['ga']+=$gc;
            $teams[$id][$gf>$gc?'wins':($gf<$gc?'losses':'draws')]++;
        }
    }
    foreach ($teams as &$team) {
        $team['gd']=$team['gf']-$team['ga'];
        $team['win_pct']=$team['games'] ? round($team['wins']/$team['games']*100,1) : 0;
        $team['points_pct']=$team['games'] ? round(($team['wins']*3+$team['draws'])/($team['games']*3)*100,1) : 0;
        $team['goals_avg']=$team['games'] ? round($team['gf']/$team['games'],2) : 0;
    }
    unset($team);
    return array_values($teams);
}

function statistics_sequences(array $matches): array
{
    usort($matches,static fn($a,$b):int=>strcmp((string)$a['data_jogo'],(string)$b['data_jogo'])?:((int)$a['id']<=>(int)$b['id']));
    $records=[];$current=[];
    $types=['wins'=>'Vitórias seguidas','unbeaten'=>'Invencibilidade','losses'=>'Derrotas seguidas','winless'=>'Sem vencer','scoring'=>'Marcando gols','clean'=>'Sem sofrer gols'];
    foreach ($matches as $match) foreach (['a','b'] as $side) {
        $other=$side==='a'?'b':'a';$id=(int)$match['time_'.$side.'_id'];$gf=(int)$match['gols_'.$side];$ga=(int)$match['gols_'.$other];
        $name=(string)$match['time_'.$side];$checks=['wins'=>$gf>$ga,'unbeaten'=>$gf>=$ga,'losses'=>$gf<$ga,'winless'=>$gf<=$ga,'scoring'=>$gf>0,'clean'=>$ga===0];
        foreach ($checks as $type=>$continues) {
            $current[$id][$type]=$continues?(($current[$id][$type]??0)+1):0;
            $value=$current[$id][$type];
            if ($value>0 && (!isset($records[$type]) || $value>$records[$type][0]['value'])) $records[$type]=[['id'=>$id,'name'=>$name,'value'=>$value]];
            elseif ($value>0 && isset($records[$type]) && $value===$records[$type][0]['value'] && !in_array($id,array_column($records[$type],'id'),true)) $records[$type][]=['id'=>$id,'name'=>$name,'value'=>$value];
        }
    }
    foreach ($records as $type=>&$rows) foreach ($rows as &$row) $row['label']=$types[$type];
    return $records;
}

function statistics_head_to_head(array $matches): array
{
    $pairs=[];
    foreach ($matches as $m) {
        $a=(int)$m['time_a_id'];$b=(int)$m['time_b_id'];$key=min($a,$b).':'.max($a,$b);
        if (!isset($pairs[$key])) $pairs[$key]=['a_id'=>$a,'b_id'=>$b,'a'=>$m['time_a'],'b'=>$m['time_b'],'games'=>0,'a_wins'=>0,'b_wins'=>0,'draws'=>0,'a_goals'=>0,'b_goals'=>0,'matches'=>[]];
        $p=&$pairs[$key];
        $same=(int)$p['a_id']===$a;$ga=(int)$m['gols_a'];$gb=(int)$m['gols_b'];$pa=$same?$ga:$gb;$pb=$same?$gb:$ga;
        $p['games']++;$p['a_goals']+=$pa;$p['b_goals']+=$pb;$p[$pa>$pb?'a_wins':($pa<$pb?'b_wins':'draws')]++;
        $p['matches'][]=$m;unset($p);
    }
    foreach ($pairs as &$p) {$p['leader']=$p['a_wins']>=$p['b_wins']?$p['a']:$p['b'];$p['wins_gap']=abs($p['a_wins']-$p['b_wins']);$p['total_goals']=$p['a_goals']+$p['b_goals'];}
    unset($p);return array_values($pairs);
}

function statistics_players(PDO $pdo, int $championshipId = 0, int $clubId = 0): array
{
    $whereP=$championshipId?' AND p2.campeonato_id='.(int)$championshipId:'';$whereM=$championshipId?' AND j.campeonato_id='.(int)$championshipId:'';
    $clubP=$clubId?' AND g.participante_id='.(int)$clubId:'';
    $goals=$pdo->query("SELECT g.jogador,g.participante_id,p.time_nome clube,g.tipo FROM gols_partida g JOIN partidas p2 ON p2.id=g.partida_id JOIN participantes p ON p.id=g.participante_id WHERE p2.ativo=1 AND p2.status IN('finalizada','wo','penalidade')$whereP$clubP UNION ALL SELECT g.jogador,g.participante_id,p.time_nome,g.tipo FROM gols_mata_mata g JOIN jogos_mata_mata j ON j.id=g.jogo_mata_mata_id JOIN participantes p ON p.id=g.participante_id WHERE j.ativo=1 AND j.status IN('finalizado','wo')$whereM$clubP")->fetchAll();
    $players=[];
    foreach($goals as $goal){$key=mb_strtolower(trim($goal['jogador']),'UTF-8').'|'.$goal['participante_id'];if(!isset($players[$key]))$players[$key]=['name'=>$goal['jogador'],'club'=>$goal['clube'],'club_id'=>(int)$goal['participante_id'],'goals'=>0,'assists'=>0,'yellow'=>0,'red'=>0,'penalties'=>0,'penalty_saves'=>0,'free_kicks'=>0,'olympic'=>0];$players[$key]['goals']++;if($goal['tipo']==='penalti')$players[$key]['penalties']++;if($goal['tipo']==='falta')$players[$key]['free_kicks']++;if($goal['tipo']==='olimpico')$players[$key]['olympic']++;}
    $summarySql="SELECT s.dados_json,p.mandante_id a_id,p.visitante_id b_id,a.sigla a_code,b.sigla b_code,a.time_nome a_name,b.time_nome b_name,p.campeonato_id FROM sumulas_dreamteam s JOIN partidas p ON s.origem='pontos' AND p.id=s.partida_id JOIN participantes a ON a.id=p.mandante_id JOIN participantes b ON b.id=p.visitante_id WHERE p.ativo=1 AND p.status IN('finalizada','wo','penalidade') UNION ALL SELECT s.dados_json,j.time_a_id,j.time_b_id,a.sigla,b.sigla,a.time_nome,b.time_nome,j.campeonato_id FROM sumulas_dreamteam s JOIN jogos_mata_mata j ON s.origem='mata' AND j.id=s.jogo_mata_mata_id JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id WHERE j.ativo=1 AND j.status IN('finalizado','wo')";
    foreach($pdo->query($summarySql)->fetchAll() as $row){if($championshipId&&(int)$row['campeonato_id']!==$championshipId)continue;$data=json_decode((string)$row['dados_json'],true);if(!is_array($data))continue;foreach(($data['events']??[])as $event){$code=(string)($event['team_code']??'');$teamId=strcasecmp($code,(string)$row['a_code'])===0?(int)$row['a_id']:(strcasecmp($code,(string)$row['b_code'])===0?(int)$row['b_id']:0);if(!$teamId||($clubId&&$teamId!==$clubId))continue;$club=$teamId===(int)$row['a_id']?$row['a_name']:$row['b_name'];$playerMetric=match((string)($event['type']??'')){'yellow_card'=>'yellow','red_card'=>'red','penalty_saved'=>'penalty_saves',default=>''};foreach([['assist','assists'],['player',$playerMetric]] as [$field,$metric]){if(!$metric||empty($event[$field]))continue;$name=trim((string)$event[$field]);$key=mb_strtolower($name,'UTF-8').'|'.$teamId;if(!isset($players[$key]))$players[$key]=['name'=>$name,'club'=>$club,'club_id'=>$teamId,'goals'=>0,'assists'=>0,'yellow'=>0,'red'=>0,'penalties'=>0,'penalty_saves'=>0,'free_kicks'=>0,'olympic'=>0];$players[$key][$metric]++;}}}
    foreach($players as &$player)$player['contributions']=$player['goals']+$player['assists'];unset($player);return array_values($players);
}

function statistics_finance(PDO $pdo, int $clubId = 0, int $championshipId = 0): array
{
    $where=$clubId?' WHERE m.participante_id='.(int)$clubId:'';
    if($championshipId){$where.=($where?' AND':' WHERE').' m.campeonato_id='.(int)$championshipId;$movementTable='movimentacoes_elenco';}
    else $movementTable='movimentacoes_elenco_geral';
    $moves=$pdo->query("SELECT m.id,m.participante_id,p.time_nome clube,m.tipo,m.jogador_nome,m.valor,m.criado_em FROM $movementTable m JOIN participantes p ON p.id=m.participante_id$where ORDER BY m.criado_em,m.id")->fetchAll();
    $clubs=[];foreach($moves as $m){$id=(int)$m['participante_id'];if(!isset($clubs[$id]))$clubs[$id]=['id'=>$id,'name'=>$m['clube'],'purchases'=>0,'sales'=>0,'spent'=>0.0,'revenue'=>0.0,'volume'=>0.0];$amount=(float)$m['valor'];$clubs[$id][$m['tipo']==='compra'?'purchases':'sales']++;$clubs[$id][$m['tipo']==='compra'?'spent':'revenue']+=$amount;$clubs[$id]['volume']+=$amount;}
    $wealthTable=$championshipId?'clubes_campeonato':'clubes_gerais';$wealthWhere=[];
    if($championshipId)$wealthWhere[]='g.campeonato_id='.(int)$championshipId;if($clubId)$wealthWhere[]='g.participante_id='.(int)$clubId;
    $wealth=$pdo->query("SELECT g.participante_id id,p.time_nome name,p.sigla,p.escudo_url shield,g.saldo FROM $wealthTable g JOIN participantes p ON p.id=g.participante_id".($wealthWhere?' WHERE '.implode(' AND ',$wealthWhere):'')." ORDER BY g.saldo DESC")->fetchAll();
    return ['moves'=>$moves,'clubs'=>array_values($clubs),'wealth'=>$wealth];
}

function statistics_sort(array $rows, string $field, bool $asc = false): array
{
    usort($rows,static fn($a,$b):int=>($asc?1:-1)*(($a[$field]??0)<=>($b[$field]??0))?:strcasecmp((string)($a['name']??''),(string)($b['name']??'')));
    return $rows;
}
