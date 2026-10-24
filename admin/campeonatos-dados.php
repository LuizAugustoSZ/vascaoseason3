<?php
// Lista campeonatos ativos para os formulários do painel.
require __DIR__ . "/../includes/bootstrap.php";
admin_required();
header('Cache-Control: private, no-store, max-age=0');
try {
$pdo = db();
$items = $pdo
    ->query(
        "SELECT c.id,c.nome,c.tipo,c.status,c.data_inicio," . competition_schedule_priority_sql("c") . " prioridade FROM campeonatos c WHERE c.ativo=1 ORDER BY " . competition_schedule_order_sql("c") . "",
    )
    ->fetchAll();
json_response(["ok" => true, "campeonatos" => $items]);
} catch (Throwable $error) {
    error_log('Falha ao carregar campeonatos do painel: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'Não foi possível consultar os campeonatos. Tente novamente.'], 500);
}
