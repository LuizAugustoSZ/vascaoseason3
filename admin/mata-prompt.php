<?php

declare(strict_types=1);

function knockout_prompt_response(PDO $pdo, int $championshipId, string $championship, string $phase): never
{
    $stmt = $pdo->prepare("SELECT fase,COUNT(*) jogos,SUM(status='finalizado') finalizados,MAX(status='finalizado') tem_resultado FROM jogos_mata_mata WHERE campeonato_id=? AND ativo=1 GROUP BY fase ORDER BY FIELD(fase,'Preliminar','Oitavas','Quartas','Semifinal','Terceiro lugar','Final'),MIN(id)");
    $stmt->execute([$championshipId]);
    $phases = array_map(static fn(array $row): array => [
        'fase' => (string)$row['fase'],
        'jogos' => (int)$row['jogos'],
        'finalizados' => (int)$row['finalizados'],
        'tem_resultado' => (bool)$row['tem_resultado'],
    ], $stmt->fetchAll());

    $allFinished = $phases && array_sum(array_column($phases, 'jogos')) === array_sum(array_column($phases, 'finalizados'));
    array_unshift($phases, [
        'fase' => 'Campeonato completo',
        'label' => 'Campeonato completo',
        'jogos' => array_sum(array_column($phases, 'jogos')),
        'finalizados' => array_sum(array_column($phases, 'finalizados')),
        'tem_resultado' => (bool)array_sum(array_column($phases, 'finalizados')),
    ]);

    $currentPhase = '';
    foreach ($phases as $item) if ($item['tem_resultado']) $currentPhase = $item['fase'];
    if ($allFinished) $currentPhase = 'Campeonato completo';
    if ($currentPhase === '' && $phases) $currentPhase = $phases[0]['fase'];
    if ($phase === '') {
        prompt_json(['ok'=>true,'tipo'=>'mata_mata','fases'=>$phases,'fase_atual'=>$currentPhase,'contexto'=>$currentPhase ? "Fase selecionada: {$currentPhase}": 'Nenhuma fase cadastrada']);
    }
    if (!in_array($phase, array_column($phases, 'fase'), true)) throw new RuntimeException('Fase não encontrada neste campeonato.');

    $isComplete = $phase === 'Campeonato completo';
    $phaseFilter = $isComplete ? '': ' AND j.fase=?';
    $stmt = $pdo->prepare("SELECT j.fase,j.ordem,j.jogo,j.status,j.gols_a,j.gols_b,j.penaltis_a,j.penaltis_b,a.time_nome time_a,a.nome tecnico_a,b.time_nome time_b,b.nome tecnico_b,w.id vencedor_id,w.time_nome vencedor,s.estadio,s.clima,s.duracao,s.craque,s.craque_nota,s.dados_json,s.texto_original FROM jogos_mata_mata j JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id LEFT JOIN participantes w ON w.id=j.vencedor_id LEFT JOIN sumulas_dreamteam s ON s.origem='mata' AND s.jogo_mata_mata_id=j.id WHERE j.campeonato_id=?{$phaseFilter} AND j.ativo=1 ORDER BY FIELD(j.fase,'Preliminar','Oitavas','Quartas','Semifinal','Terceiro lugar','Final'),j.ordem,j.jogo,j.id");
    $stmt->execute($isComplete ? [$championshipId]: [$championshipId,$phase]);
    $matches = $stmt->fetchAll();
    if (!$matches) throw new RuntimeException('Nenhuma partida cadastrada nesta fase.');

    $facts=[]; $scorers=[]; $assists=[]; $finished=0; $champion=''; $championId=0;
    $totals=['gols'=>0,'penaltis_convertidos'=>0,'disputas_penaltis'=>0,'vars'=>0,'faltas_sofridas'=>0,'amarelos'=>0,'vermelhos'=>0,'finalizacoes'=>0,'chutes_no_gol'=>0,'defesas'=>0,'escanteios'=>0];
    foreach ($matches as $match) {
        if ($match['status']==='finalizado') $finished++;
        if ($match['fase']==='Final' && $match['vencedor']) { $champion=(string)$match['vencedor']; $championId=(int)$match['vencedor_id']; }
        if ($match['gols_a']!==null && $match['gols_b']!==null) $totals['gols']+=(int)$match['gols_a']+(int)$match['gols_b'];
        if ($match['penaltis_a']!==null && $match['penaltis_b']!==null) { $totals['disputas_penaltis']++; $totals['penaltis_convertidos']+=(int)$match['penaltis_a']+(int)$match['penaltis_b']; }
        $score=$match['gols_a']===null || $match['gols_b']===null ? 'placar ainda não informado': (int)$match['gols_a'].' x '.(int)$match['gols_b'];
        $lines=[sprintf('%s, confronto %d, jogo %d: %s (técnico: %s) %s %s (técnico: %s). Status: %s',$match['fase'],(int)$match['ordem'],(int)$match['jogo'],$match['time_a'],$match['tecnico_a'],$score,$match['time_b'],$match['tecnico_b'],$match['status'])];
        if ($match['penaltis_a']!==null && $match['penaltis_b']!==null) $lines[]='Disputa de pênaltis: '.(int)$match['penaltis_a'].' x '.(int)$match['penaltis_b'];
        if ($match['vencedor']) $lines[]='Vencedor do confronto: '.$match['vencedor'];
        foreach (['estadio'=>'Estádio','clima'=>'Clima'] as $key=>$label) if ($match[$key]) $lines[]=$label.': '.$match[$key];
        if ($match['duracao']) $lines[]='Duração: '.(int)$match['duracao'].' minutos';
        if ($match['craque']) $lines[]='Craque: '.$match['craque'].($match['craque_nota']!==null?' (nota '.$match['craque_nota'].')':'');
        $data=$match['dados_json'] ? json_decode((string)$match['dados_json'],true): null;
        if (is_array($data)) {
            foreach (($data['teams']??[]) as $team) if (!empty($team['stats'])) {
                $stats=$team['stats'];
                $totals['faltas_sofridas']+=(int)($stats['fouls_suffered']??0); $totals['amarelos']+=(int)($stats['yellow_cards']??0); $totals['vermelhos']+=(int)($stats['red_cards']??0);
                $totals['finalizacoes']+=(int)($stats['shots']??0); $totals['chutes_no_gol']+=(int)($stats['shots_on_target']??0); $totals['defesas']+=(int)($stats['saves']??0); $totals['escanteios']+=(int)($stats['corners']??0);
                $lines[]='Estatísticas de '.($team['name']??$team['code']??'time').': '.json_encode($stats,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            }
            foreach (($data['events']??[]) as $event) {
                $type=(string)($event['type']??'evento'); $minute=($event['minute']??'')!==''?(string)$event['minute']."'":'minuto não informado';
                $detail=match($type) {
                    'goal'=>'Gol de '.($event['player']??'não informado').(!empty($event['assist'])?', assistência de '.$event['assist']:'').', tipo '.($event['goal_type']??'normal').(!empty($event['cancelled'])?' (ANULADO)':''),
                    'yellow_card'=>'Cartão amarelo para '.($event['player']??'não informado'),
                    'red_card'=>'Cartão vermelho para '.($event['player']??'não informado'),
                    'var_goal_cancelled'=>'VAR anulou gol de '.($event['player']??'não informado'),
                    'injury'=>'Lesão de '.($event['player']??'não informado'),
                    'substitution'=>'Substituição: saiu '.($event['player_out']??'não informado').', entrou '.($event['player_in']??'não informado'),
                    default=>ucfirst(str_replace('_',' ',$type)).': '.($event['description']??''),
                };
                $lines[]=$minute.': '.$detail;
                if (in_array($type,['var_goal_cancelled','var_penalty_cancelled'],true) || ($type==='yellow_card' && !empty($event['via_var']))) $totals['vars']++;
                if ($type==='goal' && empty($event['cancelled'])) {
                    if (($event['goal_type']??'')==='penalti') $totals['penaltis_convertidos']++;
                    if (($event['goal_type']??'')!=='contra') { $player=trim((string)($event['player']??'')); if ($player!=='') $scorers[$player]=($scorers[$player]??0)+1; }
                    $assist=trim((string)($event['assist']??'')); if ($assist!=='') $assists[$assist]=($assists[$assist]??0)+1;
                }
            }
        } else $lines[]='Súmula detalhada não importada para esta partida.';
        if ($match['texto_original']) $lines[]="SÚMULA ORIGINAL COMPLETA:\n".trim((string)$match['texto_original']);
        $facts[]=implode("\n",$lines);
    }
    arsort($scorers); arsort($assists);
    $topScorers=array_slice($scorers,0,3,true); $topAssists=array_slice($assists,0,3,true);
    $scorerText=$topScorers ? implode(', ',array_map(static fn($name,$goals)=>$name.' ('.$goals.')',array_keys($topScorers),$topScorers)): 'nenhum artilheiro identificado nas súmulas';
    $assistText=$topAssists ? implode(', ',array_map(static fn($name,$value)=>$name.' ('.$value.')',array_keys($topAssists),$topAssists)): 'nenhuma assistência identificada nas súmulas';
    $status=$finished===count($matches)?($isComplete?'campeonato encerrado':'fase encerrada'):($isComplete?"campeonato em andamento ({$finished} de ".count($matches).' partidas encerradas)':"fase em andamento ({$finished} de ".count($matches).' partidas encerradas)');
    $championText=$champion ? "\nCAMPEÃO: {$champion}": '';
    $titleHistory='Nenhum histórico de títulos confirmado para o campeão deste recorte.';
    if ($championId > 0) {
        $titleStmt=$pdo->prepare("SELECT titulo,temporada,conquistado_em FROM titulos WHERE participante_id=? ORDER BY conquistado_em,id");
        $titleStmt->execute([$championId]);
        $registeredTitles=$titleStmt->fetchAll();
        if ($registeredTitles) {
            $titleHistory=count($registeredTitles).' título(s) cadastrado(s): '.implode('; ',array_map(static fn(array $title): string => $title['titulo'].': '.$title['temporada'].($title['conquistado_em'] ? ' em '.date('d/m/Y',strtotime((string)$title['conquistado_em'])): ''),$registeredTitles));
        }
    }
    $scope=$isComplete?'todo o campeonato mata-mata, da primeira fase à decisão':"a fase {$phase} do mata-mata";
    $specialTitle=$isComplete && str_contains(mb_strtolower($champion),'locomotiva') ? "\nFATO EDITORIAL CONFIRMADO PELO ORGANIZADOR: este foi o primeiro título do Locomotiva na Season 3. Dê grande destaque a essa conquista, sem inventar declarações.": '';
    $prompt="Você é um jornalista esportivo responsável pela cobertura do campeonato {$championship}.\n\nEscreva uma notícia especial e completa sobre {$scope} usando EXCLUSIVAMENTE os dados fornecidos. Não invente fatos, falas, números ou acontecimentos. Conte a trajetória do campeão fase a fase, destaque classificação, eliminações, placares agregados quando houver ida e volta, pênaltis, gols, assistências, cartões, VAR, faltas, estatísticas, craques e notas somente quando constarem nos dados. Faça uma leitura editorial automática de todo o material e destaque sequências comprovadas: vitórias consecutivas, invencibilidade, títulos em competições diferentes, bicampeonato, tricampeonato ou títulos consecutivos. Informe sempre a quantidade exata e as competições envolvidas. Não espere que o usuário peça esses destaques e nunca chame um feito de consecutivo apenas porque há vários títulos; a ordem dos registros precisa comprová-lo.{$specialTitle}\n\nPADRÃO EDITORIAL OBRIGATÓRIO:\n1. TÍTULO: manchete forte e informativa, com até 180 caracteres.\n2. RESUMO: um único parágrafo de até 500 caracteres.\n3. DESCRIÇÃO: repita o título na abertura e organize a matéria com subtítulos em negrito e emojis pertinentes. Inclua blocos sobre a campanha do campeão, decisões, top 3 de artilheiros, top 3 de assistências e números gerais. Analise os confrontos decisivos e encerre com o caminho completo até o título. Use emojis apenas nos subtítulos.\n\nENTREGUE EXATAMENTE SEPARADO ASSIM:\nTÍTULO:\n[texto]\n\nRESUMO:\n[texto]\n\nDESCRIÇÃO:\n[matéria completa]\n\nCONTEXTO DO CAMPEONATO:\nCAMPEONATO: {$championship}\nFORMATO: mata-mata\nRECORTE ANALISADO: {$phase}\nSTATUS: {$status}{$championText}\nHISTÓRICO DE TÍTULOS DO CAMPEÃO: {$titleHistory}\nTOP 3 ARTILHEIROS: {$scorerText}\nTOP 3 ASSISTÊNCIAS: {$assistText}\nNÚMEROS GERAIS: {$totals['gols']} gols; {$totals['penaltis_convertidos']} pênaltis convertidos (incluindo disputas); {$totals['disputas_penaltis']} disputas por pênaltis; {$totals['vars']} intervenções de VAR identificadas; {$totals['faltas_sofridas']} faltas sofridas; {$totals['amarelos']} amarelos; {$totals['vermelhos']} vermelhos; {$totals['finalizacoes']} finalizações; {$totals['chutes_no_gol']} chutes no gol; {$totals['defesas']} defesas; {$totals['escanteios']} escanteios.\n\nDADOS DAS PARTIDAS:\n\n".implode("\n\n---\n\n",$facts);
    prompt_json(['ok'=>true,'tipo'=>'mata_mata','prompt'=>$prompt,'partidas'=>count($matches),'fase'=>$phase,'contexto'=>"{$phase} · {$status}"]);
}
