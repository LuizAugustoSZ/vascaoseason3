<?php
// Entrega os gols detalhados da partida para preencher o formulário de edição.
require __DIR__ . "/../includes/bootstrap.php";
admin_required();
try {
    $id = (int) ($_GET["id"] ?? 0);
    $stmt = db()->prepare(
        "SELECT g.id,g.participante_id,g.jogador,g.minuto,g.tipo FROM gols_partida g JOIN partidas p ON p.id=g.partida_id WHERE g.partida_id=? AND p.ativo=1 ORDER BY g.id",
    );
    $stmt->execute([$id]);
    json_response(["ok" => true, "gols" => $stmt->fetchAll()]);
} catch (Throwable $error) {
    json_response(
        [
            "ok" => false,
            "message" => "Não foi possível carregar os gols da partida.",
        ],
        500,
    );
}
