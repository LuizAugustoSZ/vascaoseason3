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
check($p['teams'][0]['stats']['xg']===1.94 && $p['teams'][1]['stats']['xg']===3.12, 'xG');
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
$penalties = <<<'REPORT'
PARTIDA FINALIZADA - 92'
🏟️ Estádio Municipal de Portimão
🌦️ Tempo aberto · 13 °C
⚖️ Arbitragem: Imprevisível
COMPARSAS FC 2x3 Lords FC
&#x20;
:coroa: Man of the Match: :CopaSudamericana: Marcos Antônio
:00boladt: 1 gol
⭐ Nota: 7,63
Destaques: jogo com bola e posicionamento.
COMPARSAS FC
Finalizações: 6 No gol: 6 Defesas: 3 Escanteios: 0 Posse: 57% Faltas Sofridas: 2 Amarelos: 1 Vermelhos: 0 xG: 2,35
Marcadores:
:00boladt: :FutebolArte: Lionel Messi: 1 gol
:00boladt: :UFC: Diego Costa: 1 gol
&#x20;
Lords FC
Finalizações: 7 No gol: 6 Defesas: 4 Escanteios: 0 Posse: 43% Faltas Sofridas: 2 Amarelos: 0 Vermelhos: 0 xG: 3,03
Marcadores:
:00boladt: :UFC: Hristo Stoichkov: 1 gol
:00boladt: :CopaSudamericana: Marcos Antônio: 1 gol
:00boladt: :CDB: Ramón Sosa: 1 gol
PARTIDA FINALIZADA - 92'
Imagem
dreamteam.futbol - Partida entre COMPARSAS FC e Lords FC
🎙️ Lances da Partida
9' Revisão do VAR
9' Cartão amarelo - :UFC: Roy Keane [COM] · entrada temerária
9' Gol de pênalti - :UFC: Hristo Stoichkov [LOR]
41' Gol - :CopaSudamericana: Marcos Antônio [LOR]
Assistência de :SurpresasMundiais: Vozinha [LOR]
44' Gol - :FutebolArte: Lionel Messi [COM]
Assistência de :CDB: Jonathan Jesus [COM]
62' Revisão do VAR
62' Substituição - Sai :CopaSudamericana: Marcos Antônio, entra :SurpresasMundiais: Puerta [LOR]
62' Substituição - Sai :FutebolArte: Ronaldinho Gaucho, entra :FutebolArte: Rayan Cherki [LOR]
62' Substituição - Sai :UFC: Júnior Baiano, entra :CopaSudamericana: Emmanuel oliveira [LOR]
62' Substituição - Sai :CopaLibertadores: Pedro, entra :CopaSudamericana: Gabigol [LOR]
62' Pênalti defendido - :CopaDoMundo: David Raya [COM]
69' Gol - :UFC: Diego Costa [COM]
76' Substituição - Sai :UFC: Hristo Stoichkov, entra :CopaLibertadores: Jhon Arias [LOR]
86' Gol - :CDB: Ramón Sosa [LOR]
REPORT;
foreach ([$penalties, str_replace('pênalti', 'penalti', str_replace('Pênalti', 'Penalti', $penalties))] as $report) {
    $p=dreamteam_bind_team_codes(dreamteam_parse_summary($report), [['sigla'=>'COM'],['sigla'=>'LOR']]);
    check($p['warnings']===[], implode(' ', $p['warnings']));
    check(count($p['goals'])===5 && count($p['events'])===14, 'Penalty report totals');
    check($p['goals'][0]['goal_type']==='penalti' && $p['goals'][0]['player']==='Hristo Stoichkov', 'Penalty scorer');
    check($p['events'][10]['type']==='penalty_saved' && $p['events'][10]['player']==='David Raya', 'Saved penalty is not a goal');
    check($p['events'][0]['type']==='var_review' && $p['events'][5]['type']==='var_review', 'Standalone VAR reviews');
    check(array_column($p['teams'],'code')===['COM','LOR'], 'Penalty report team codes');
}
$p=dreamteam_parse_summary(str_replace('Gol de pênalti', 'Lance desconhecido', $penalties));
check(count($p['warnings'])>=2, 'Unknown events and score mismatch still require review');
echo "DreamTeam parser tests passed.\n";
