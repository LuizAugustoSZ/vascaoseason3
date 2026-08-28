<?php
declare(strict_types=1);
// Carrega as funções e abre o acesso ao banco.
require __DIR__ . "/../includes/bootstrap.php";

try {
    $pdo = db();
    competition_identities_seed($pdo);
    $campeonatos = $pdo
        ->query(
            "SELECT c.id,c.nome,c.identidade_id,c.tipo,c.formato,c.status,c.criado_em,
                CASE WHEN EXISTS(SELECT 1 FROM partidas p WHERE p.campeonato_id=c.id AND p.ativo=1 AND(p.status IN('finalizada','wo') OR(p.gols_mandante IS NOT NULL AND p.gols_visitante IS NOT NULL)))
                    OR EXISTS(SELECT 1 FROM jogos_mata_mata j WHERE j.campeonato_id=c.id AND j.ativo=1 AND(j.status IN('finalizado','wo') OR(j.gols_a IS NOT NULL AND j.gols_b IS NOT NULL))) THEN 1 ELSE 0 END iniciado,
                GREATEST(
                    COALESCE((SELECT MAX(p.data_partida) FROM partidas p WHERE p.campeonato_id=c.id AND p.ativo=1 AND p.status IN('finalizada','wo')), '1970-01-01 00:00:00'),
                    COALESCE((SELECT MAX(s.criado_em) FROM sumulas_dreamteam s
                        LEFT JOIN partidas sp ON s.origem='pontos' AND sp.id=s.partida_id
                        LEFT JOIN jogos_mata_mata sj ON s.origem='mata' AND sj.id=s.jogo_mata_mata_id
                        WHERE COALESCE(sp.campeonato_id,sj.campeonato_id)=c.id), '1970-01-01 00:00:00')
                ) ultima_partida
            FROM campeonatos c WHERE c.ativo=1
            ORDER BY (c.status='ativo' AND iniciado=1) DESC,(c.status='ativo') DESC,ultima_partida DESC,c.criado_em DESC,c.id DESC",
        )
        ->fetchAll();
    foreach ($campeonatos as &$competitionItem) {
        $competitionItem['logo_url'] = !empty($competitionItem['identidade_id']) ? competition_image_url((int)$competitionItem['id'], 'logo') : null;
        $competitionItem['trofeu_url'] = !empty($competitionItem['identidade_id']) ? competition_image_url((int)$competitionItem['id'], 'trofeu') : null;
    }
    unset($competitionItem);
    $requested = (int) ($_GET["campeonato_id"] ?? 0);
    $campeonato = null;
    foreach ($campeonatos as $item) {
        if ((int) $item["id"] === $requested) {
            $campeonato = $item;
            break;
        }
    }
    if ($campeonato === null) {
        $campeonato = $campeonatos[0] ?? null;
    }
    $campeonatoId = (int) ($campeonato["id"] ?? 0);
    // Lista os participantes mostrados no site.
    $participantes = $pdo
        ->query(
            "SELECT id, nome, time_nome, sigla, escudo_url, descricao FROM participantes WHERE ativo=1 ORDER BY time_nome",
        )
        ->fetchAll();
    // Busca as partidas dos pontos corridos.
    $partidaStmt = $pdo->prepare(
        "SELECT p.*,m.time_nome mandante,m.nome tecnico_mandante,m.sigla mandante_sigla,m.escudo_url mandante_escudo,v.time_nome visitante,v.nome tecnico_visitante,v.sigla visitante_sigla,v.escudo_url visitante_escudo FROM partidas p JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id WHERE p.campeonato_id=? AND p.ativo=1 ORDER BY p.rodada,p.data_partida",
    );
    $partidaStmt->execute([$campeonatoId]);
    $partidas = $partidaStmt->fetchAll();
    // Mantém as vagas das Supercopas atualizadas também no acesso público.
    // O vice é calculado silenciosamente quando a regra de campeão repetido exigir.
    sync_supercup_slots($pdo);
    // Entrega a taça assim que o campeão por pontos estiver matematicamente definido.
    competition_sync_champion_title($pdo, $campeonatoId);
    // Busca os confrontos do chaveamento.
    $mataStmt = $pdo->prepare(
        "SELECT j.*,a.time_nome time_a,a.nome tecnico_a,a.sigla sigla_a,a.escudo_url escudo_a,a.ativo time_a_ativo,b.time_nome time_b,b.nome tecnico_b,b.sigla sigla_b,b.escudo_url escudo_b,b.ativo time_b_ativo,w.time_nome vencedor FROM jogos_mata_mata j LEFT JOIN participantes a ON a.id=j.time_a_id LEFT JOIN participantes b ON b.id=j.time_b_id LEFT JOIN participantes w ON w.id=j.vencedor_id WHERE j.campeonato_id=? AND j.ativo=1 ORDER BY FIELD(j.fase,'Preliminar','Oitavas','Quartas','Semifinal','Terceiro lugar','Final'),j.ordem,j.jogo,j.id",
    );
    $mataStmt->execute([$campeonatoId]);
    $mataMata = $mataStmt->fetchAll();
    // Explica de qual competição cada finalista da Supercopa se classificou.
    if (($campeonato["tipo"] ?? "") === "supercopa") {
        $supercupStmt = $pdo->prepare(
            "SELECT s.*,a.nome origem_a_nome,b.nome origem_b_nome
             FROM supercopas s
             JOIN campeonatos a ON a.id=s.origem_a_campeonato_id
             JOIN campeonatos b ON b.id=s.origem_b_campeonato_id
             WHERE s.campeonato_id=? LIMIT 1",
        );
        $supercupStmt->execute([$campeonatoId]);
        $supercup = $supercupStmt->fetch();
        if ($supercup) {
            $championA = competition_champion_id($pdo, (int) $supercup["origem_a_campeonato_id"]);
            $championB = competition_champion_id($pdo, (int) $supercup["origem_b_campeonato_id"]);
            $labelA = "Campeão: " . $supercup["origem_a_nome"];
            $labelB = "Campeão: " . $supercup["origem_b_nome"];
            if ($championA && $championB && $championA === $championB) {
                if ($supercup["regra_mesmo_campeao"] === "vice_origem_b") {
                    $labelB = "Vice: " . $supercup["origem_b_nome"];
                } else {
                    $labelA = "Vice: " . $supercup["origem_a_nome"];
                }
            }
            foreach ($mataMata as &$game) {
                $game["classificacao_a"] = (int) $game["jogo"] === 2 ? $labelB : $labelA;
                $game["classificacao_b"] = (int) $game["jogo"] === 2 ? $labelA : $labelB;
            }
            unset($game);
        }
    }
    // Busca o ranking de artilheiros.
    $artilhariaStmt = $pdo->prepare(
        "SELECT a.id,a.campeonato_id,a.participante_id,a.jogador,a.gols,p.time_nome participante,p.nome tecnico FROM artilharia a JOIN participantes p ON p.id=a.participante_id WHERE a.campeonato_id=? ORDER BY a.gols DESC,a.jogador LIMIT 10",
    );
    $artilhariaStmt->execute([$campeonatoId]);
    $artilharia = $artilhariaStmt->fetchAll();
    // Soma as assistências registradas nas súmulas e preserva o clube de cada jogador.
    $assistenciasStmt = $pdo->prepare(
        "SELECT s.dados_json,p.mandante_id time_a_id,p.visitante_id time_b_id,m.time_nome time_a,v.time_nome time_b FROM sumulas_dreamteam s JOIN partidas p ON s.origem='pontos' AND p.id=s.partida_id JOIN participantes m ON m.id=p.mandante_id JOIN participantes v ON v.id=p.visitante_id WHERE p.campeonato_id=? UNION ALL SELECT s.dados_json,j.time_a_id,j.time_b_id,a.time_nome,b.time_nome FROM sumulas_dreamteam s JOIN jogos_mata_mata j ON s.origem='mata' AND j.id=s.jogo_mata_mata_id JOIN participantes a ON a.id=j.time_a_id JOIN participantes b ON b.id=j.time_b_id WHERE j.campeonato_id=?",
    );
    $assistenciasStmt->execute([$campeonatoId, $campeonatoId]);
    $assistenciasAgrupadas = [];
    $normalizarJogador = static function (string $nome): string {
        $nome = mb_strtolower(trim($nome), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);
        return preg_replace('/[^a-z0-9]+/', '', $ascii !== false ? $ascii : $nome) ?? '';
    };
    foreach ($assistenciasStmt->fetchAll() as $row) {
        $sumula = json_decode((string)$row['dados_json'], true);
        if (!is_array($sumula)) continue;
        $times = array_values($sumula['teams'] ?? []);
        foreach (($sumula['events'] ?? []) as $evento) {
            $jogador = trim((string)($evento['assist'] ?? ''));
            if (($evento['type'] ?? '') !== 'goal' || !empty($evento['cancelled']) || $jogador === '') continue;
            $codigo = (string)($evento['team_code'] ?? '');
            $indice = null;
            foreach ($times as $i => $timeSumula) if ((string)($timeSumula['code'] ?? '') === $codigo) $indice = $i;
            if ($indice !== 0 && $indice !== 1) continue;
            $participanteId = (int)$row[$indice === 0 ? 'time_a_id' : 'time_b_id'];
            $timeNome = (string)$row[$indice === 0 ? 'time_a' : 'time_b'];
            $chave = $participanteId . ':' . $normalizarJogador($jogador);
            if (!isset($assistenciasAgrupadas[$chave])) $assistenciasAgrupadas[$chave] = ['participante_id' => $participanteId, 'jogador' => $jogador, 'participante' => $timeNome, 'assistencias' => 0];
            $assistenciasAgrupadas[$chave]['assistencias']++;
        }
    }
    $assistencias = array_values($assistenciasAgrupadas);
    usort($assistencias, static fn(array $a, array $b): int => $b['assistencias'] <=> $a['assistencias'] ?: strcasecmp($a['jogador'], $b['jogador']));
    $assistencias = array_slice($assistencias, 0, 10);
    // Busca as conquistas dos técnicos.
    $titulos = $pdo
        ->query(
            "SELECT t.id,t.participante_id,t.titulo,t.temporada,t.descricao,t.conquistado_em,CASE WHEN t.imagem_base64 IS NOT NULL AND t.imagem_base64<>'' THEN 1 ELSE 0 END tem_imagem,COALESCE(p.nome,t.tecnico_nome) tecnico,COALESCE(p.time_nome,t.time_nome) time_nome,COALESCE(p.ativo,0) participante_ativo FROM titulos t LEFT JOIN participantes p ON p.id=t.participante_id ORDER BY participante_ativo DESC,FIELD(t.temporada,'Season 3','Season 2','Season 1'),t.conquistado_em DESC,t.titulo",
        )
        ->fetchAll();
    foreach ($titulos as &$titleItem) {
        $titleKey = competition_identity_match((string)$titleItem['titulo']);
        $titleItem['trofeu_url'] = $titleKey ? 'api/competicao-imagem.php?chave=' . rawurlencode($titleKey) . '&tipo=trofeu' : (!empty($titleItem['tem_imagem']) ? 'api/titulo-imagem.php?titulo_id=' . (int)$titleItem['id'] : null);
        if ($titleKey && !$titleItem['trofeu_url']) {
            foreach ($campeonatos as $competitionItem) {
                if (!empty($competitionItem['identidade_id']) && competition_identity_match((string)$competitionItem['nome']) === $titleKey) {
                    $titleItem['trofeu_url'] = competition_image_url((int)$competitionItem['id'], 'trofeu');
                    break;
                }
            }
        }
    }
    unset($titleItem);
    // Busca os vídeos publicados.
    $videos = $pdo
        ->query(
            "SELECT id, titulo, youtube_url FROM videos WHERE ativo=1 ORDER BY criado_em DESC",
        )
        ->fetchAll();
    $finalizadas =
        count(
            array_filter(
                $partidas,
                fn($jogo) => in_array(
                    $jogo["status"],
                    ["finalizada", "wo"],
                    true,
                ),
            ),
        ) +
        count(
            array_filter(
                $mataMata,
                fn($jogo) => in_array($jogo["status"], ["finalizado", "wo"], true),
            ),
        );
    $classification = standings($pdo, $campeonatoId);
    $leagueTitle = ($campeonato["tipo"] ?? "") === "pontos_corridos"
        ? league_title_status($pdo, $campeonatoId, $classification)
        : null;
    $enrolled = [];
    foreach ($partidas as $game) {
        $enrolled[(int) $game["mandante_id"]] = true;
        $enrolled[(int) $game["visitante_id"]] = true;
    }
    foreach ($mataMata as $game) {
        if ($game["time_a_id"] !== null) {
            $enrolled[(int) $game["time_a_id"]] = true;
        }
        if ($game["time_b_id"] !== null) {
            $enrolled[(int) $game["time_b_id"]] = true;
        }
    }
    $temCampeonatoAtivo =
        count(
            array_filter(
                $campeonatos,
                fn($item) => $item["status"] === "ativo",
            ),
        ) > 0;
    $statusTemporada = $temCampeonatoAtivo
        ? "Em disputa"
        : ($campeonatos
            ? "Aguardando próxima competição"
            : "Novidades em breve");
    $resumo = [
        "status" => $statusTemporada,
        "participantes" => count($enrolled),
        "partidas_finalizadas" => $finalizadas,
    ];
    // Entrega todos os dados em JSON para o script.js.
    json_response([
        "ok" => true,
        "campeonatos" => $campeonatos,
        "campeonato" => $campeonato,
        "resumo" => $resumo,
        "classificacao" => $classification,
        "titulo_liga" => $leagueTitle,
        "participantes" => $participantes,
        "partidas" => $partidas,
        "mata_mata" => $mataMata,
        "artilharia" => $artilharia,
        "assistencias" => $assistencias,
        "titulos" => $titulos,
        "videos" => $videos,
    ]);
} catch (Throwable $error) {
    // Entrega todos os dados em JSON para o script.js.
    json_response(
        [
            "ok" => false,
            "message" =>
                "Banco de dados indisponível. Confira config/config.php e importe database.sql.",
        ],
        500,
    );
}
