<?php

declare(strict_types=1);

function knockout_prompt_response(PDO $pdo, int $championshipId, string $championship, string $phase): never
{
    $stmt = $pdo->prepare("SELECT fase,COUNT(*) jogos,SUM(status='finalizado') finalizados,MAX(status='finalizado') tem_resultado FROM jogos_mata_mata WHERE campeonato_id=? AND ativo=1 GROUP BY fase ORDER BY FIELD(fase,'Oitavas','Quartas','Semifinal','Terceiro lugar','Final'),MIN(id)");
    $stmt->execute([$championshipId]);
    $phases = array_map(static fn(array $row): array => [
        'fase' => (string)$row['fase'],
        'jogos' => (int)$row['jogos'],
        'finalizados' => (int)$row['finalizados'],
        'tem_resultado' => (bool)$row['tem_resultado'],
    ], $stmt->fetchAll());

    $currentPhase = '';
    foreach ($phases as $item) if ($item['tem_resultado']) $currentPhase = $item['fase'];
    if ($currentPhase === '' && $phases) $currentPhase = $phases[0]['fase'];
    if ($phase === '') {
        prompt_json(['ok'=>true,'tipo'=>'mata_mata','fases'=>$phases,'fase_atual'=>$currentPhase,'contexto'=>$currentPhase ? "Fase selecionada: {$currentPhase}" : 'Nenhuma fase cadastrada']);
    }
    if (!in_array($phase, array_column($phases, 'fase'), true)) throw new RuntimeException('Fase não encontrada neste campeonato.');

    $stmt = $pdo->prepare("SELECT j.ordem,j.jogo,j.status,j.gols_a,j.gols_b,j.penaltis_a,j.penaltis_b,a.time_nome time_a,a.nome tecnico_a,b.time_nome time_b,b.nome tecnico_b,w.time_nome vencedor,s.estadio,s.clima,s.duracao,s.craque,s.craque_nota,s.dados_json,s.texto_original FROM jogos_mata_mata j JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id LEFT JOIN participantes w ON w.id=j.vencedor_id LEFT JOIN sumulas_dreamteam s ON s.origem='mata' AND s.jogo_mata_mata_id=j.id WHERE j.campeonato_id=? AND j.fase=? AND j.ativo=1 ORDER BY j.ordem,j.jogo,j.id");
    $stmt->execute([$championshipId,$phase]);
    $matches = $stmt->fetchAll();
    if (!$matches) throw new RuntimeException('Nenhuma partida cadastrada nesta fase.');

    $facts=[]; $scorers=[]; $finished=0; $champion='';
    foreach ($matches as $match) {
        if ($match['status']==='finalizado') $finished++;
        if ($phase==='Final' && $match['vencedor']) $champion=(string)$match['vencedor'];
        $score=$match['gols_a']===null || $match['gols_b']===null ? 'placar ainda não informado' : (int)$match['gols_a'].' x '.(int)$match['gols_b'];
        $lines=[sprintf('Confronto %d, jogo %d: %s (técnico: %s) %s %s (técnico: %s) — status: %s',(int)$match['ordem'],(int)$match['jogo'],$match['time_a'],$match['tecnico_a'],$score,$match['time_b'],$match['tecnico_b'],$match['status'])];
        if ($match['penaltis_a']!==null && $match['penaltis_b']!==null) $lines[]='Disputa de pênaltis: '.(int)$match['penaltis_a'].' x '.(int)$match['penaltis_b'];
        if ($match['vencedor']) $lines[]='Vencedor do confronto: '.$match['vencedor'];
        foreach (['estadio'=>'Estádio','clima'=>'Clima'] as $key=>$label) if ($match[$key]) $lines[]=$label.': '.$match[$key];
        if ($match['duracao']) $lines[]='Duração: '.(int)$match['duracao'].' minutos';
        if ($match['craque']) $lines[]='Craque: '.$match['craque'].($match['craque_nota']!==null?' (nota '.$match['craque_nota'].')':'');
        $data=$match['dados_json'] ? json_decode((string)$match['dados_json'],true) : null;
        if (is_array($data)) {
            foreach (($data['teams']??[]) as $team) if (!empty($team['stats'])) $lines[]='Estatísticas de '.($team['name']??$team['code']??'time').': '.json_encode($team['stats'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
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
                $lines[]=$minute.' — '.$detail;
                if ($type==='goal' && empty($event['cancelled']) && ($event['goal_type']??'')!=='contra') { $player=trim((string)($event['player']??'')); if ($player!=='') $scorers[$player]=($scorers[$player]??0)+1; }
            }
        } else $lines[]='Súmula detalhada não importada para esta partida.';
        if ($match['texto_original']) $lines[]="SÚMULA ORIGINAL COMPLETA:\n".trim((string)$match['texto_original']);
        $facts[]=implode("\n",$lines);
    }
    arsort($scorers);
    $scorerText=$scorers ? implode(', ',array_map(static fn($name,$goals)=>$name.' ('.$goals.')',array_keys($scorers),$scorers)) : 'nenhum artilheiro identificado nas súmulas';
    $status=$finished===count($matches)?'fase encerrada':"fase em andamento ({$finished} de ".count($matches).' partidas encerradas)';
    $championText=$champion ? "\nCAMPEÃO: {$champion}" : '';
    $prompt="Você é um jornalista esportivo responsável pela cobertura do campeonato {$championship}.\n\nEscreva uma notícia completa sobre a fase {$phase} do mata-mata usando EXCLUSIVAMENTE os dados fornecidos. Não invente fatos, falas, números ou acontecimentos. Destaque classificação, eliminação, placares agregados quando houver ida e volta, pênaltis, gols, assistências, cartões, VAR, estatísticas, craques e notas somente quando constarem nos dados.\n\nPADRÃO EDITORIAL OBRIGATÓRIO:\n1. TÍTULO: manchete forte e informativa, com até 180 caracteres.\n2. RESUMO: um único parágrafo de até 500 caracteres.\n3. DESCRIÇÃO: repita o título na abertura e organize a matéria com subtítulos em negrito e emojis pertinentes. Analise todos os confrontos, explique quem avançou ou foi eliminado apenas quando os dados permitirem e encerre com a lista completa dos resultados. Se for a Final, dê destaque especial ao campeão. Use emojis apenas nos subtítulos.\n\nENTREGUE EXATAMENTE SEPARADO ASSIM:\nTÍTULO:\n[texto]\n\nRESUMO:\n[texto]\n\nDESCRIÇÃO:\n[matéria completa]\n\nCONTEXTO DO CAMPEONATO:\nCAMPEONATO: {$championship}\nFORMATO: mata-mata\nFASE ANALISADA: {$phase}\nSTATUS: {$status}{$championText}\nARTILHEIROS DA FASE: {$scorerText}\n\nDADOS DAS PARTIDAS:\n\n".implode("\n\n---\n\n",$facts);
    prompt_json(['ok'=>true,'tipo'=>'mata_mata','prompt'=>$prompt,'partidas'=>count($matches),'fase'=>$phase,'contexto'=>"{$phase} · {$status}"]);
}
