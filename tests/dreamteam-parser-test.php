<?php
require __DIR__.'/../includes/dreamteam-parser.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$raw = <<<'REPORT'
PARTIDA FINALIZADA - 92'
Estádio Riyadh Air Metropolitano Garoa · 7 °C Arbitragem: Imprevisível Locomotiva FC 1x3 Criatividade FC
Man of the Match: Cristiano Ronaldo 2 gols Nota: 8,01 Destaques: finalização.
Locomotiva FC Finalizações: 4 No gol: 3 Defesas: 4 Escanteios: 0 Posse: 44% Faltas Sofridas: 0 Amarelos: 0 Vermelhos: 0 xG: 1,94 Marcadores: Gabigol: 1 gol
Criatividade FC Finalizações: 8 No gol: 7 Defesas: 2 Escanteios: 0 Posse: 56% Faltas Sofridas: 1 Amarelos: 0 Vermelhos: 0 xG: 3,12 Marcadores: Cristiano Ronaldo: 2 gols Joshua Kimmich: 1 gol
dreamteam.futbol - Partida entre Locomotiva FC e Criatividade FC
Lances da Partida
`35'` **Gol** - Gabigol [LOC] Assistência de Ronaldinho Gaucho [LOC]`45'` **Gol** - Cristiano Ronaldo [CFC] Assistência de Joshua Kimmich [CFC]`57'` **Gol** - Joshua Kimmich [CFC] `69'` **Gol** - Cristiano Ronaldo [CFC] Assistência de Neymar [CFC]`69'` **Substituição** - Sai Gabigol, entra Cantona [LOC]
REPORT;
$p=dreamteam_bind_team_codes(dreamteam_parse_summary($raw), [['sigla'=>'LOC'],['sigla'=>'CFC']]);
check($p['warnings']===[], implode(' ', $p['warnings']));
check(count($p['goals'])===4 && count($p['events'])===5, 'Adjacent events');
check(array_column($p['goals'],'assist')===['Ronaldinho Gaucho','Joshua Kimmich',null,'Neymar'], 'Assists');
check($p['man_of_match']==='Cristiano Ronaldo', 'MOTM');
check($p['weather']==='Garoa · 7 °C', 'Weather');
$zero = <<<'REPORT'
**PARTIDA FINALIZADA - 90'**
**Estádio do Dragão**&#x20;**&#x20;Garoa · 23 °C** Arbitragem: Rigoroso **Criatividade FC** **`0x1`** **Locomotiva FC**
**Man of the Match:** Adson 1 gol **Nota:** 8,15 Destaques: jogo com bola.
Criatividade FC Finalizações: 2 No gol: 1 Defesas: 2 Escanteios: 0 Posse: 59% Faltas Sofridas: 1 Amarelos: 0 Vermelhos: 0 xG: 0,93 Marcadores: Nenhum gol
Locomotiva FC Finalizações: 3 No gol: 3 Defesas: 1 Escanteios: 1 Posse: 41% Faltas Sofridas: 1 Amarelos: 1 Vermelhos: 0 xG: 1,33 Marcadores:
> &#x20;Adson: **1** gol
**dreamteam.futbol - Partida entre Criatividade FC e Locomotiva FC**
**Lances da Partida**
`6'` **Cartão amarelo** - Trent Alexander-Arnold [LOC] · entrada temerária `26'` **Gol** - Adson [LOC] `65'` **Substituição** - Sai Gabigol, entra Cantona [LOC]
REPORT;
$p=dreamteam_bind_team_codes(dreamteam_parse_summary($zero), [['sigla'=>'CFC'],['sigla'=>'LOC']]);
check($p['warnings']===[], implode(' ', $p['warnings']));
check(array_column($p['teams'],'code')===['CFC','LOC'], 'Missing home code');
check($p['events'][0]['description']==='entrada temerária', 'Card description');
$rejected=false;
try { dreamteam_parse_summary($raw."\n".$zero); } catch (RuntimeException $e) { $rejected=true; }
check($rejected, 'Multiple matches rejected');
echo "DreamTeam parser tests passed.\n";
