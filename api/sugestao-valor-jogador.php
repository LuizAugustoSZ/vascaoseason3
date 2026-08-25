<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/mercado.php';
require __DIR__ . '/../includes/elenco-geral.php';

try {
    if (!account_logged_in()) {
        json_response(['ok' => false, 'message' => 'Faça login para consultar valores.'], 401);
    }

    $nome = trim((string)($_GET['nome'] ?? ''));
    $overall = (int)($_GET['overall'] ?? 0);
    if ($nome === '' || mb_strlen($nome) > 150 || $overall < 1 || $overall > 99) {
        throw new RuntimeException('Informe o nome e o OVR do jogador.');
    }

    $pdo = db();
    mercado_garantir_estrutura($pdo);
    elenco_geral_garantir_estrutura($pdo);
    $stmt = $pdo->prepare("SELECT tipo,valor,criado_em FROM (
        SELECT tipo,origem,jogador_nome,jogador_overall,valor,criado_em FROM movimentacoes_elenco_geral
        UNION ALL
        SELECT tipo,origem,jogador_nome,jogador_overall,valor,criado_em FROM movimentacoes_elenco
    ) historico
    WHERE TRIM(jogador_nome)=TRIM(?)
      AND jogador_overall=?
      AND valor>0
      AND (tipo='venda' OR (tipo='compra' AND origem='compra_direta'))
    ORDER BY criado_em DESC
    LIMIT 1");
    $stmt->execute([$nome, $overall]);
    $sugestao = $stmt->fetch();

    json_response([
        'ok' => true,
        'found' => (bool)$sugestao,
        'value' => $sugestao ? (int)round((float)$sugestao['valor']) : null,
        'type' => $sugestao['tipo'] ?? null,
        'date' => $sugestao['criado_em'] ?? null,
    ]);
} catch (Throwable $error) {
    json_response(['ok' => false, 'message' => $error->getMessage()], 422);
}
