<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require __DIR__.'/includes/public-layout.php';
require __DIR__.'/includes/statistics.php';

$pdo=db();
$championshipId=max(0,(int)($_GET['campeonato_id']??0));
$clubId=max(0,(int)($_GET['clube_id']??0));
$championships=$pdo->query("SELECT id,nome FROM campeonatos ORDER BY COALESCE(data_inicio,'1970-01-01') DESC,id DESC")->fetchAll();
$clubs=$pdo->query("SELECT id,time_nome,sigla,escudo_url FROM participantes ORDER BY time_nome")->fetchAll();
$matches=statistics_matches($pdo,$championshipId,$clubId);
$teams=statistics_team_aggregates($matches);
$players=statistics_players($pdo,$championshipId,$clubId);
$finance=statistics_finance($pdo,$clubId,$championshipId);
$sequences=statistics_sequences($matches);
$pairs=statistics_head_to_head($matches);
$titleWhere=[];$titleParams=[];
if($championshipId){$titleWhere[]='t.campeonato_id=?';$titleParams[]=$championshipId;}
if($clubId){$titleWhere[]='t.participante_id=?';$titleParams[]=$clubId;}
$titleStmt=$pdo->prepare("SELECT COALESCE(p.time_nome,t.time_nome,t.tecnico_nome,'Registro histórico') name,p.id club_id,p.sigla,p.escudo_url,COUNT(*) titles FROM titulos t LEFT JOIN participantes p ON p.id=t.participante_id".($titleWhere?' WHERE '.implode(' AND ',$titleWhere):'')." GROUP BY COALESCE(p.time_nome,t.time_nome,t.tecnico_nome,'Registro histórico'),p.id,p.sigla,p.escudo_url ORDER BY titles DESC,name");
$titleStmt->execute($titleParams);$titleRanking=$titleStmt->fetchAll();

$totalGoals=array_sum(array_column($matches,'gols_a'))+array_sum(array_column($matches,'gols_b'));
$totalCompetitions=count(array_unique(array_column($matches,'campeonato_id')));
$transferVolume=array_sum(array_map(static fn($m)=>(float)$m['valor'],$finance['moves']));
$formatMoney=static fn(mixed $v):string=>'R$ '.number_format((float)$v,0,',','.');
$teamRank=static fn(string $field,bool $asc=false):array=>statistics_sort($teams,$field,$asc);
$playerRank=static fn(string $field):array=>statistics_sort($players,$field);
$financeRank=static fn(string $field):array=>statistics_sort($finance['clubs'],$field);
$matchRows=[];foreach($matches as $m){$m['margin']=abs((int)$m['gols_a']-(int)$m['gols_b']);$m['total']=(int)$m['gols_a']+(int)$m['gols_b'];$m['name']=$m['time_a'].' '.(int)$m['gols_a'].' x '.(int)$m['gols_b'].' '.$m['time_b'];$matchRows[]=$m;}
$byMargin=statistics_sort($matchRows,'margin');$byTotal=statistics_sort($matchRows,'total');$draws=statistics_sort(array_values(array_filter($matchRows,static fn($m)=>(int)$m['gols_a']===(int)$m['gols_b'])),'total');
$mostPlayed=statistics_sort($pairs,'games');$dominance=statistics_sort(array_values(array_filter($pairs,static fn($p)=>$p['games']>=2)),'wins_gap');
$richest=statistics_sort($finance['wealth'],'saldo');
$financialSort=static function(array $rows):array{usort($rows,static fn($a,$b):int=>(float)$b['valor']<=>(float)$a['valor']?:strcmp((string)$a['criado_em'],(string)$b['criado_em'])?:((int)$a['id']<=>(int)$b['id']));return $rows;};
$purchases=$financialSort(array_values(array_filter($finance['moves'],static fn($m)=>$m['tipo']==='compra')));
$sales=$financialSort(array_values(array_filter($finance['moves'],static fn($m)=>$m['tipo']==='venda')));

$cards=[];$details=[];
$add=function(string $section,string $key,string $label,array $rows,string $nameField,string $valueField,string $suffix='',?callable $valueFormat=null,string $note='')use(&$cards,&$details):void{
    $rows=array_slice($rows,0,10);$first=$rows[0]??null;if(!$first)return;
    $format=$valueFormat??static fn($v)=>(string)$v;
    $cards[$section][]=compact('key','label','note')+['name'=>(string)($first[$nameField]??'Sem dados'),'value'=>$format($first[$valueField]??0).$suffix];
    $details[$key]=['title'=>$label,'note'=>$note,'rows'=>array_map(static fn($row)=>['name'=>(string)($row[$nameField]??'Sem dados'),'context'=>(string)($row['club']??$row['campeonato']??$row['etapa']??''),'value'=>$format($row[$valueField]??0).$suffix],$rows)];
};
$add('Clubes','team-wins','Mais vitórias',$teamRank('wins'),'name','wins',' vitórias');
$add('Clubes','team-games','Mais jogos',$teamRank('games'),'name','games',' jogos');
$add('Clubes','team-goals','Melhor ataque histórico',$teamRank('gf'),'name','gf',' gols');
$add('Clubes','team-defense','Melhor defesa histórica',$teamRank('ga',true),'name','ga',' sofridos',null,'Considera clubes com partidas finalizadas neste recorte.');
$add('Clubes','team-gd','Melhor saldo de gols',$teamRank('gd'),'name','gd',' gols');
$eligiblePct=array_values(array_filter($teams,static fn($t)=>$t['games']>=3));
$add('Clubes','team-pct','Maior percentual de vitórias',statistics_sort($eligiblePct,'win_pct'),'name','win_pct','%',null,'Mínimo de 3 jogos para evitar recordes enganosos.');
$add('Títulos','titles','Maior campeão',$titleRanking,'name','titles',' títulos');
$add('Jogadores','goals','Maior artilheiro',$playerRank('goals'),'name','goals',' gols');
$add('Jogadores','assists','Maior assistente',$playerRank('assists'),'name','assists',' assistências');
$add('Jogadores','contributions','Participações em gols',$playerRank('contributions'),'name','contributions',' participações');
$add('Disciplina','yellow','Mais cartões amarelos',$playerRank('yellow'),'name','yellow',' amarelos');
$add('Disciplina','red','Mais cartões vermelhos',$playerRank('red'),'name','red',' vermelhos');
$add('Gols','penalties','Mais gols de pênalti',$playerRank('penalties'),'name','penalties',' gols');
$add('Gols','free-kicks','Mais gols de falta',$playerRank('free_kicks'),'name','free_kicks',' gols');
$add('Partidas','biggest-win','Maior goleada',$byMargin,'name','margin',' gols de diferença');
$add('Partidas','most-goals','Jogo com mais gols',$byTotal,'name','total',' gols');
$add('Partidas','draw-goals','Empate com mais gols',$draws,'name','total',' gols');
$add('Retrospectos','most-played','Confronto mais disputado',$mostPlayed,'leader','games',' jogos');
$add('Retrospectos','dominance','Maior freguesia histórica',$dominance,'leader','wins_gap',' vitórias de vantagem',null,'Ordenado pela diferença de vitórias, com mínimo de 2 confrontos.');
$add('Financeiro','wealth','Maior cofre atual',$richest,'name','saldo','',$formatMoney);
$add('Financeiro','spent','Mais gastos em compras',$financeRank('spent'),'name','spent','',$formatMoney);
$add('Financeiro','revenue','Maior receita com vendas',$financeRank('revenue'),'name','revenue','',$formatMoney);
$add('Mercado','activity','Clube mais ativo',$financeRank('volume'),'name','volume','',$formatMoney);
$add('Mercado','purchase','Contratação mais cara',$purchases,'jogador_nome','valor','',$formatMoney,'Empates são ordenados pela ocorrência mais antiga.');
$add('Mercado','sale','Venda mais cara',$sales,'jogador_nome','valor','',$formatMoney,'Empates são ordenados pela ocorrência mais antiga.');
foreach($sequences as $type=>$rows)$add('Sequências','sequence-'.$type,$rows[0]['label'],$rows,'name','value',' jogos');

$pairJson=[];foreach($pairs as $pair){$compact=['a_id'=>$pair['a_id'],'b_id'=>$pair['b_id'],'a'=>$pair['a'],'b'=>$pair['b'],'games'=>$pair['games'],'a_wins'=>$pair['a_wins'],'b_wins'=>$pair['b_wins'],'draws'=>$pair['draws'],'a_goals'=>$pair['a_goals'],'b_goals'=>$pair['b_goals']];$pairJson[$pair['a_id'].':'.$pair['b_id']]=$compact;$pairJson[$pair['b_id'].':'.$pair['a_id']]=['a_id'=>$pair['b_id'],'b_id'=>$pair['a_id'],'a'=>$pair['b'],'b'=>$pair['a'],'games'=>$pair['games'],'a_wins'=>$pair['b_wins'],'b_wins'=>$pair['a_wins'],'draws'=>$pair['draws'],'a_goals'=>$pair['b_goals'],'b_goals'=>$pair['a_goals']];}
?><!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="description" content="Central histórica de estatísticas e recordes do Vascão Season 3."><title>Estatísticas e Recordes | Vascão S3</title><link rel="icon" href="favicon.ico?v=5"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css?v=<?=filemtime(__DIR__.'/assets/css/style.css')?>"><link rel="stylesheet" href="assets/css/statistics.css?v=<?=filemtime(__DIR__.'/assets/css/statistics.css')?>"></head><body><?php public_navbar('estatisticas'); ?>
<main class="statistics-page section-pad"><div class="container"><header class="statistics-hero"><span class="eyebrow">Tudo que já aconteceu, transformado em números</span><h1>ESTATÍSTICAS & RECORDES</h1><p>Uma central histórica construída somente com partidas finalizadas, súmulas e movimentações oficiais cadastradas no Vascão.</p></header>
<form class="statistics-filters" method="get"><label>Competição<select class="form-select" name="campeonato_id"><option value="0">Histórico geral</option><?php foreach($championships as $c):?><option value="<?=$c['id']?>" <?=$championshipId===(int)$c['id']?'selected':''?>><?=e($c['nome'])?></option><?php endforeach;?></select></label><label>Clube<select class="form-select" name="clube_id"><option value="0">Todos os clubes</option><?php foreach($clubs as $club):?><option value="<?=$club['id']?>" <?=$clubId===(int)$club['id']?'selected':''?>><?=e($club['time_nome'])?></option><?php endforeach;?></select></label><button class="btn btn-danger">Aplicar filtros</button><a class="btn btn-outline-light" href="estatisticas.php">Limpar</a></form>
<section class="statistics-global" aria-label="Resumo histórico"><article><strong><?=count($matches)?></strong><span>partidas finalizadas</span></article><article><strong><?=$totalGoals?></strong><span>gols registrados</span></article><article><strong><?=$totalCompetitions?></strong><span>competições no recorte</span></article><article><strong><?=count($players)?></strong><span>jogadores com eventos</span></article><article><strong><?=count($finance['moves'])?></strong><span>transferências</span></article><article><strong><?=e($formatMoney($transferVolume))?></strong><span>movimentados</span></article></section>
<?php foreach($cards as $section=>$sectionCards):?><section class="statistics-section"><div class="statistics-section-title"><span><?=e(mb_strtoupper($section))?></span><h2><?=e($section)?></h2></div><div class="statistics-grid"><?php foreach($sectionCards as $card):?><button class="statistics-card" type="button" data-stat-card="<?=e($card['key'])?>"><small><?=e($card['label'])?></small><strong><?=e($card['name'])?></strong><b><?=e($card['value'])?></b><span><?=e($card['note']?:'Ver ranking e detalhes')?> →</span></button><?php endforeach;?></div></section><?php endforeach;?>
<section class="statistics-section"><div class="statistics-section-title"><span>CONSULTA HISTÓRICA</span><h2>Confronto direto</h2></div><div class="head-to-head-search"><label>Clube A<select class="form-select" data-h2h-a><option value="">Selecione</option><?php foreach($clubs as $club):?><option value="<?=$club['id']?>"><?=e($club['time_nome'])?></option><?php endforeach;?></select></label><span>×</span><label>Clube B<select class="form-select" data-h2h-b><option value="">Selecione</option><?php foreach($clubs as $club):?><option value="<?=$club['id']?>"><?=e($club['time_nome'])?></option><?php endforeach;?></select></label><button class="btn btn-danger" type="button" data-h2h-search>Ver retrospecto</button></div><div class="head-to-head-result" data-h2h-result><p>Selecione dois clubes para consultar jogos, vitórias, empates e gols.</p></div></section>
<?php if(!$matches):?><div class="statistics-empty">Sem dados disponíveis para os filtros selecionados.</div><?php endif;?></div></main>
<div class="modal fade statistics-modal" id="statistics-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><small>Central histórica</small><h2 class="modal-title" data-stat-title>Ranking</h2></div><button class="btn-close btn-close-white" type="button" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><p class="statistics-modal-note" data-stat-note></p><div class="statistics-ranking" data-stat-ranking></div></div></div></div></div>
<?php public_footer();?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script>window.statisticsDetails=<?=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;window.statisticsPairs=<?=json_encode($pairJson,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script><script src="assets/js/statistics.js?v=<?=filemtime(__DIR__.'/assets/js/statistics.js')?>"></script></body></html>
