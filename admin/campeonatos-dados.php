<?php
// Lista campeonatos ativos para os formulários do painel.
require __DIR__ . "/../includes/bootstrap.php";
admin_required();
$pdo = db();
competition_schedule_ensure_schema($pdo);
$items = $pdo
    ->query(
        "SELECT c.id,c.nome,c.tipo,c.status,c.data_inicio," . competition_schedule_priority_sql("c") . " prioridade FROM campeonatos c WHERE c.ativo=1 ORDER BY " . competition_schedule_order_sql("c") . "",
    )
    ->fetchAll();
json_response(["ok" => true, "campeonatos" => $items]);
