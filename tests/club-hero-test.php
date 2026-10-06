<?php
require 'includes/club-hero.php';
$rows=club_hero_candidates([['id'=>7,'nome'=>'Robert Lewandowski']],[['id'=>7,'nome'=>'Outro jogador']]);
if(count($rows)!==2 || $rows[0]['hero_value']!=='geral:7' || $rows[1]['hero_value']!=='7') throw new RuntimeException('IDs de herÃ³is colidiram');
echo "OK: compra do elenco geral e IDs distintos.\n";

$rows=club_hero_candidates([['id'=>9,'nome'=>'Lewa']], [['id'=>2,'jogador_geral_id'=>9],['id'=>3,'jogador_geral_id'=>10],['id'=>4,'jogador_geral_id'=>null]]);
if(array_column($rows,'hero_value')!==['geral:9','4']) throw new RuntimeException('Carta duplicada ou vendida entrou na lista');
echo "OK: evita duplicados e cartas gerais vendidas; preserva legado.\n";
