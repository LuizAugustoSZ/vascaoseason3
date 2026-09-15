<?php
declare(strict_types=1);
require __DIR__ . '/../includes/mercado.php';

function check_pack(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pack = mercado_packs_para_data('2026-09-15 12:00:00')['aniversario'];
check_pack($pack['min'] === 92 && $pack['max'] === 92, 'Aniversário deve aceitar somente OVR 92.');
check_pack(mercado_pack_preco($pack) === '100 DD', 'Preço do aniversário incorreto.');
check_pack(mercado_valor_movimento(['origem' => 'pack', 'valor_origem' => mercado_pack_valor($pack), 'moeda_origem' => mercado_pack_moeda($pack)]) === '100 DD', 'Histórico deve preservar DD.');
foreach (MERCADO_PACKS as $id => $other) {
    if ($id === 'aniversario') continue;
    check_pack(mercado_pack_moeda($other) === 'DP', 'Outros packs devem continuar em DP.');
    check_pack(mercado_pack_valor($other) === (float)$other['dream_points'], 'Preço anterior alterado.');
}
foreach (['DP', 'DreamPoints', null] as $currency) {
    check_pack(mercado_valor_movimento(['origem' => 'pack', 'valor_origem' => 200, 'moeda_origem' => $currency]) === '200 DP', 'Compatibilidade com histórico em DP.');
}
check_pack(!isset(mercado_packs_para_data('2026-08-26 12:00:00')['aniversario']), 'Packs antigos devem ser preservados.');
check_pack(mercado_valor_movimento(['origem' => 'compra_direta', 'valor' => 100]) === 'R$ 100', 'Compra em reais alterada.');
echo "Packs: OK\n";
