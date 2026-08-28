<?php

declare(strict_types=1);

const MERCADO_FORMACOES = [
    '3-3-4',
    '3-4-3',
    '3-5-2',
    '4-2-3-1',
    '4-2-4 Ofensivo',
    '4-2-4 Defensivo',
    '4-3-3',
    '4-3-3 Ofensivo',
    '4-3-3 Equilibrado',
    '4-3-3 Defensivo',
    '4-4-2',
    '4-4-2 Ofensivo',
    '4-4-2 Equilibrado',
    '4-4-2 Defensivo',
    '4-5-1 Ofensivo',
    '4-5-1 Defensivo',
    '5-3-2',
    '5-4-1',
];
const MERCADO_POSICOES = ['GOL', 'LD', 'LE', 'ZAG', 'VOL', 'MC', 'MEI', 'PD', 'PE', 'ATA'];
const MERCADO_PACKS_VIGENCIA_ATUAL = '2026-08-27 00:00:00';
const MERCADO_PACKS_ANTIGOS = [
    'reforco' => ['nome' => 'Pack Reforço', 'min' => 87, 'max' => 88, 'dream_points' => 200],
    'competitivo' => ['nome' => 'Pack Competitivo', 'min' => 87, 'max' => 89, 'dream_points' => 280],
    'elite' => ['nome' => 'Pack Elite', 'min' => 88, 'max' => 89, 'dream_points' => 420],
    'pre_meta' => ['nome' => 'Pack Pré-Meta', 'min' => 88, 'max' => 90, 'dream_points' => 650],
    'quase_meta' => ['nome' => 'Pack Quase Meta', 'min' => 89, 'max' => 90, 'dream_points' => 900],
    'meta' => ['nome' => 'Pack Meta', 'min' => 90, 'max' => 90, 'dream_points' => 1200],
    'meta_posicional' => ['nome' => 'Pack Meta Posicional', 'min' => 90, 'max' => 90, 'dream_points' => 1700],
];
const MERCADO_PACKS = [
    'reforco' => ['nome' => 'Pack Reforço', 'min' => 88, 'max' => 89, 'dream_points' => 200],
    'competitivo' => ['nome' => 'Pack Competitivo', 'min' => 88, 'max' => 90, 'dream_points' => 280],
    'elite' => ['nome' => 'Pack Elite', 'min' => 89, 'max' => 90, 'dream_points' => 420],
    'pre_meta' => ['nome' => 'Pack Pré-Meta', 'min' => 89, 'max' => 91, 'dream_points' => 650],
    'quase_meta' => ['nome' => 'Pack Quase Meta', 'min' => 90, 'max' => 91, 'dream_points' => 900],
    'meta' => ['nome' => 'Pack Meta', 'min' => 91, 'max' => 91, 'dream_points' => 1200],
    'meta_posicional' => ['nome' => 'Pack Meta Posicional', 'min' => 91, 'max' => 91, 'dream_points' => 1700],
];

function mercado_packs_para_data(?string $data): array
{
    if ($data !== null && $data !== '' && $data < MERCADO_PACKS_VIGENCIA_ATUAL) return MERCADO_PACKS_ANTIGOS;
    return MERCADO_PACKS;
}

function mercado_rotulo_origem(array $movimento): string
{
    if (($movimento['tipo'] ?? '') === 'venda') return 'Venda';
    return match ($movimento['origem'] ?? 'compra_direta') {
        'pack' => (string)($movimento['origem_detalhe'] ?: 'Pack'),
        'passe' => 'Passe',
        'sorteio' => 'Sorteio',
        'prancheta' => 'Prancheta',
        'obter' => '/obter',
        'importacao' => 'Importação',
        default => 'Compra',
    };
}

function mercado_valor_movimento(array $movimento): string
{
    if (($movimento['origem'] ?? '') === 'pack') {
        return number_format((float)($movimento['valor_origem'] ?? 0), 0, ',', '.') . ' DP';
    }
    if (in_array(($movimento['origem'] ?? ''), ['passe', 'sorteio', 'prancheta', 'obter', 'importacao'], true)) return 'Sem custo';
    return 'R$ ' . number_format((float)($movimento['valor'] ?? 0), 0, ',', '.');
}

function mercado_normalizar_formacao(string $formacao, string $custom = ''): string
{
    if (in_array($formacao, MERCADO_FORMACOES, true)) return $formacao;
    if ($formacao !== '__custom__') throw new RuntimeException('Selecione uma formação válida.');

    $valor = trim($custom);
    if (preg_match('/^([1-9])([1-9])([1-9])$/', $valor, $partes)) {
        $valor = $partes[1] . '-' . $partes[2] . '-' . $partes[3];
    }
    if (!preg_match('/^([1-9])-([1-9])-([1-9])(?:\s+Custom)?$/i', $valor, $partes)) {
        throw new RuntimeException('A formação custom deve seguir o padrão 4-3-3 ou 433.');
    }
    if ((int)$partes[1] + (int)$partes[2] + (int)$partes[3] !== 10) {
        throw new RuntimeException('A formação custom precisa totalizar 10 jogadores de linha.');
    }
    return $partes[1] . '-' . $partes[2] . '-' . $partes[3] . ' Custom';
}

function mercado_prioridade_posicao(string $posicao): int
{
    return match ($posicao) {
        'PE' => 10,
        'ATA' => 20,
        'PD' => 30,
        'MEI' => 40,
        'MC' => 50,
        'VOL' => 60,
        'LE' => 70,
        'ZAG' => 80,
        'LD' => 90,
        'GOL' => 100,
        default => 65,
    };
}

function mercado_ordenar_elenco(PDO $pdo, int $campeonatoId, int $participanteId): void
{
    $stmt = $pdo->prepare("SELECT id,nome,posicao,grupo,ordem FROM jogadores_elenco WHERE campeonato_id=? AND participante_id=? AND ativo=1");
    $stmt->execute([$campeonatoId, $participanteId]);
    $grupos = ['titular' => [], 'banco' => []];
    foreach ($stmt->fetchAll() as $jogador) {
        $grupos[$jogador['grupo'] === 'titular' ? 'titular' : 'banco'][] = $jogador;
    }
    $update = $pdo->prepare("UPDATE jogadores_elenco SET ordem=? WHERE id=? AND campeonato_id=? AND participante_id=?");
    foreach ($grupos as $jogadores) {
        usort($jogadores, static function (array $a, array $b): int {
            $prioridade = mercado_prioridade_posicao((string)$a['posicao']) <=> mercado_prioridade_posicao((string)$b['posicao']);
            if ($prioridade !== 0) return $prioridade;
            $ordemAnterior = (int)$a['ordem'] <=> (int)$b['ordem'];
            return $ordemAnterior !== 0 ? $ordemAnterior : strcasecmp((string)$a['nome'], (string)$b['nome']);
        });
        foreach ($jogadores as $indice => $jogador) {
            $update->execute([$indice + 1, (int)$jogador['id'], $campeonatoId, $participanteId]);
        }
    }
}

function mercado_validar_titulares_formacao(PDO $pdo, int $campeonatoId, int $participanteId, string $formacao): void
{
    if (!preg_match('/^([1-9](?:-[1-9]){2,3})/', $formacao, $match)) {
        throw new RuntimeException('Não foi possível interpretar os setores da formação.');
    }
    $linhas = array_map('intval', explode('-', $match[1]));
    $esperado = [
        'defesa' => $linhas[0],
        'meio' => array_sum(array_slice($linhas, 1, -1)),
        'ataque' => $linhas[array_key_last($linhas)],
    ];
    $stmt = $pdo->prepare("SELECT posicao,COUNT(*) total FROM jogadores_elenco WHERE campeonato_id=? AND participante_id=? AND ativo=1 AND grupo='titular' GROUP BY posicao");
    $stmt->execute([$campeonatoId, $participanteId]);
    $atual = ['goleiro' => 0, 'defesa' => 0, 'meio' => 0, 'ataque' => 0];
    foreach ($stmt->fetchAll() as $linha) {
        $setor = match ((string)$linha['posicao']) {
            'GOL' => 'goleiro',
            'LE', 'ZAG', 'LD' => 'defesa',
            'VOL', 'MC', 'MEI' => 'meio',
            'PE', 'ATA', 'PD' => 'ataque',
            default => 'meio',
        };
        $atual[$setor] += (int)$linha['total'];
    }
    if ($atual['goleiro'] !== 1 || $atual['defesa'] !== $esperado['defesa'] || $atual['meio'] !== $esperado['meio'] || $atual['ataque'] !== $esperado['ataque']) {
        throw new RuntimeException(sprintf(
            'A formação %s exige 1 goleiro, %d defensores, %d meias e %d atacantes.',
            $formacao,
            $esperado['defesa'],
            $esperado['meio'],
            $esperado['ataque'],
        ));
    }
}

function mercado_parse_valor(string $valor): float
{
    $limpo = preg_replace('/\D/', '', trim($valor)) ?? '';
    if ($limpo === '') throw new RuntimeException('Informe um valor inteiro em reais.');
    return round((float)$limpo, 0);
}

function mercado_garantir_estrutura(PDO $pdo): void
{
    $migration = file_get_contents(__DIR__ . '/../sql/atualizacao-v8.9-mercado-transferencias.sql');
    if ($migration === false) throw new RuntimeException('Migration do mercado não encontrada.');
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $migration, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
        $pdo->exec(trim($statement));
    }
    $descriptionColumn = $pdo->query("SHOW COLUMNS FROM participantes LIKE 'descricao'")->fetch();
    if ($descriptionColumn && !preg_match('/^(?:tiny|medium|long)?text$/i', (string)$descriptionColumn['Type'])) {
        $pdo->exec("ALTER TABLE participantes MODIFY descricao TEXT NULL");
    }
    $columns = $pdo->query("SHOW COLUMNS FROM clubes_campeonato")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('cofre_configurado', $columns, true)) {
        $pdo->exec("ALTER TABLE clubes_campeonato ADD cofre_configurado TINYINT(1) NOT NULL DEFAULT 0 AFTER saldo");
        $pdo->exec("UPDATE clubes_campeonato SET cofre_configurado=1 WHERE saldo<>0");
    }
    if (!in_array('mural', $columns, true)) {
        $pdo->exec("ALTER TABLE clubes_campeonato ADD mural TEXT NULL AFTER elenco_confirmado");
    }
    if (!in_array('jogador_favorito_id', $columns, true)) {
        $pdo->exec("ALTER TABLE clubes_campeonato ADD jogador_favorito_id INT UNSIGNED NULL AFTER mural");
    }
    $movementColumns = $pdo->query("SHOW COLUMNS FROM movimentacoes_elenco")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('origem', $movementColumns, true)) {
        $pdo->exec("ALTER TABLE movimentacoes_elenco ADD origem VARCHAR(30) NOT NULL DEFAULT 'compra_direta' AFTER tipo");
    }
    if (!in_array('origem_detalhe', $movementColumns, true)) {
        $pdo->exec("ALTER TABLE movimentacoes_elenco ADD origem_detalhe VARCHAR(120) NULL AFTER origem");
    }
    if (!in_array('valor_origem', $movementColumns, true)) {
        $pdo->exec("ALTER TABLE movimentacoes_elenco ADD valor_origem DECIMAL(12,2) NULL AFTER origem_detalhe");
    }
    if (!in_array('moeda_origem', $movementColumns, true)) {
        $pdo->exec("ALTER TABLE movimentacoes_elenco ADD moeda_origem VARCHAR(20) NULL AFTER valor_origem");
    }
}

function mercado_partidas_concluidas(PDO $pdo, int $campeonatoId, int $participanteId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM partidas WHERE campeonato_id=? AND ativo=1 AND status IN ('finalizada','wo') AND (mandante_id=? OR visitante_id=?)");
    $stmt->execute([$campeonatoId, $participanteId, $participanteId]);
    return (int)$stmt->fetchColumn();
}

function mercado_rodada_atual(PDO $pdo, int $campeonatoId, int $participanteId): int
{
    return mercado_progresso_clube($pdo, $campeonatoId, $participanteId)['proxima_rodada'];
}

/**
 * Calcula a rodada efetiva do clube. Uma rodada sem jogo entre dois compromissos
 * do time é uma folga e conta normalmente no ciclo. Uma partida pendente sempre
 * interrompe a progressão, mesmo que existam jogos cadastrados em rodadas futuras.
 */
function mercado_progresso_clube(PDO $pdo, int $campeonatoId, int $participanteId): array
{
    $stmt = $pdo->prepare("SELECT rodada,status FROM partidas WHERE campeonato_id=? AND ativo=1 AND (mandante_id=? OR visitante_id=?) ORDER BY rodada,id");
    $stmt->execute([$campeonatoId, $participanteId, $participanteId]);
    $porRodada = [];
    foreach ($stmt->fetchAll() as $partida) {
        $numero = (int)$partida['rodada'];
        if ($numero < 1) continue;
        $porRodada[$numero][] = (string)$partida['status'];
    }

    if (!$porRodada) {
        return ['proxima_rodada' => 1, 'etapas_concluidas' => 0, 'partidas_concluidas' => 0, 'folgas' => 0];
    }

    $ultimaAgendada = max(array_keys($porRodada));
    $etapas = $partidas = $folgas = 0;
    for ($rodada = 1; $rodada <= $ultimaAgendada; $rodada++) {
        if (!isset($porRodada[$rodada])) {
            // Há compromisso posterior: esta lacuna é a folga oficial do clube.
            $etapas++;
            $folgas++;
            continue;
        }
        $concluidas = array_filter(
            $porRodada[$rodada],
            static fn(string $status): bool => in_array($status, ['finalizada', 'wo'], true)
        );
        if (count($concluidas) !== count($porRodada[$rodada])) {
            return [
                'proxima_rodada' => $rodada,
                'etapas_concluidas' => $etapas,
                'partidas_concluidas' => $partidas,
                'folgas' => $folgas,
            ];
        }
        $etapas++;
        $partidas += count($concluidas);
    }

    return [
        'proxima_rodada' => $ultimaAgendada + 1,
        'etapas_concluidas' => $etapas,
        'partidas_concluidas' => $partidas,
        'folgas' => $folgas,
    ];
}

function mercado_aberto_na_rodada(int $rodada): bool
{
    $posicaoNoCiclo = (($rodada - 1) % 8) + 1;
    return $posicaoNoCiclo >= 6;
}

function mercado_estado_ciclo(int $rodada): array
{
    $posicao = (($rodada - 1) % 8) + 1;
    $ciclo = intdiv($rodada - 1, 8) + 1;
    return ['ciclo' => $ciclo, 'posicao' => $posicao, 'aberto' => $posicao >= 6, 'restantes' => $posicao >= 6 ? 9 - $posicao : 6 - $posicao];
}

function mercado_estado_clube(PDO $pdo, int $campeonatoId, int $participanteId): array
{
    $progresso = mercado_progresso_clube($pdo, $campeonatoId, $participanteId);
    $stmt = $pdo->prepare("SELECT c.status,
        COUNT(p.id) total_partidas,
        COALESCE(SUM(CASE WHEN p.id IS NOT NULL AND p.status NOT IN ('finalizada','wo') THEN 1 ELSE 0 END),0) partidas_pendentes
        FROM campeonatos c
        LEFT JOIN partidas p ON p.campeonato_id=c.id AND p.ativo=1 AND (p.mandante_id=? OR p.visitante_id=?)
        WHERE c.id=? GROUP BY c.id,c.status");
    $stmt->execute([$participanteId, $participanteId, $campeonatoId]);
    $agenda = $stmt->fetch() ?: ['status' => '', 'total_partidas' => 0, 'partidas_pendentes' => 0];
    $participacaoConcluida = (string)$agenda['status'] === 'finalizado'
        || ((int)$agenda['total_partidas'] > 0 && (int)$agenda['partidas_pendentes'] === 0);
    $estado = mercado_estado_ciclo($progresso['proxima_rodada']);
    if ($participacaoConcluida) {
        $estado['aberto'] = true;
        $estado['restantes'] = 0;
    }
    return $estado + [
        'partidas_concluidas' => $progresso['partidas_concluidas'],
        'etapas_concluidas' => $progresso['etapas_concluidas'],
        'folgas' => $progresso['folgas'],
        'proxima_partida' => $progresso['proxima_rodada'],
        'participacao_concluida' => $participacaoConcluida,
        'partidas_pendentes' => (int)$agenda['partidas_pendentes'],
    ];
}

function mercado_clube(PDO $pdo, int $campeonatoId, int $participanteId, bool $lock = false): array
{
    $saldoAnterior = $pdo->prepare("SELECT saldo,cofre_configurado FROM clubes_campeonato WHERE participante_id=? AND cofre_configurado=1 ORDER BY atualizado_em DESC,id DESC LIMIT 1");
    $saldoAnterior->execute([$participanteId]);
    $cofre = $saldoAnterior->fetch() ?: ['saldo' => 0, 'cofre_configurado' => 0];
    $pdo->prepare("INSERT IGNORE INTO clubes_campeonato(campeonato_id,participante_id,saldo,cofre_configurado) VALUES(?,?,?,?)")
        ->execute([$campeonatoId, $participanteId, $cofre['saldo'], $cofre['cofre_configurado']]);
    if ((bool)$cofre['cofre_configurado']) {
        $pdo->prepare("UPDATE clubes_campeonato SET saldo=?,cofre_configurado=1 WHERE campeonato_id=? AND participante_id=? AND cofre_configurado=0")
            ->execute([$cofre['saldo'], $campeonatoId, $participanteId]);
    }
    $stmt = $pdo->prepare("SELECT * FROM clubes_campeonato WHERE campeonato_id=? AND participante_id=?" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$campeonatoId, $participanteId]);
    return $stmt->fetch();
}

function mercado_pode_editar(array $clube, int $rodada, ?array $estado = null): bool
{
    // Antes do início da competição, a montagem inicial permanece disponível.
    // Depois disso, até elenco ainda não confirmado obedece à janela do ciclo.
    return (!(bool)$clube['elenco_confirmado'] && $rodada === 1)
        || (bool)($estado['participacao_concluida'] ?? false)
        || mercado_aberto_na_rodada($rodada);
}
