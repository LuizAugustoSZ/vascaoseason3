<?php
declare(strict_types=1);
require __DIR__ . '/../includes/mercado.php';

function check_pack(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$packAniversario = mercado_packs_para_data('2026-09-15 12:00:00')['aniversario'];
check_pack($packAniversario['min'] === 92 && $packAniversario['max'] === 92, 'Aniversário deve continuar válido no histórico.');
check_pack(mercado_pack_preco($packAniversario) === '100 DD', 'Preço histórico do aniversário incorreto.');
check_pack(mercado_valor_movimento(['origem' => 'pack', 'valor_origem' => mercado_pack_valor($packAniversario), 'moeda_origem' => mercado_pack_moeda($packAniversario)]) === '100 DD', 'Histórico deve preservar DD.');

$faixasAtuais = [
    'reforco' => [89, 90, 200],
    'competitivo' => [89, 91, 280],
    'elite' => [90, 91, 420],
    'pre_meta' => [90, 92, 650],
    'quase_meta' => [91, 92, 900],
    'meta' => [92, 92, 1200],
    'meta_posicional' => [92, 92, 1700],
];
foreach ($faixasAtuais as $id => [$min, $max, $preco]) {
    $other = MERCADO_PACKS[$id];
    check_pack($other['min'] === $min && $other['max'] === $max, "Faixa atual incorreta para {$id}.");
    check_pack(mercado_pack_valor($other) === (float)$preco, "Preço atual incorreto para {$id}.");
    check_pack(mercado_pack_moeda($other) === 'DP', 'Outros packs devem continuar em DP.');
}
check_pack(!isset(MERCADO_PACKS['aniversario']), 'Pack de Aniversário não deve aparecer nas escolhas atuais.');
foreach (['DP', 'DreamPoints', null] as $currency) {
    check_pack(mercado_valor_movimento(['origem' => 'pack', 'valor_origem' => 200, 'moeda_origem' => $currency]) === '200 DP', 'Compatibilidade com histórico em DP.');
}
check_pack(!isset(mercado_packs_para_data('2026-08-26 12:00:00')['aniversario']), 'Packs antigos devem ser preservados.');
check_pack(mercado_packs_para_data('2026-09-19 23:59:59')['reforco']['min'] === 88, 'Faixa anterior deve ser preservada até a nova vigência.');
check_pack(!isset(mercado_packs_para_data('2026-09-20 00:00:00')['aniversario']), 'Aniversário deve sair na nova vigência.');
check_pack(mercado_valor_movimento(['origem' => 'compra_direta', 'valor' => 100]) === 'R$ 100', 'Compra em reais alterada.');
echo "Packs: OK\n";
