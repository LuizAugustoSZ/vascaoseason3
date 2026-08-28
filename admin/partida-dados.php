<?php
// Entrega os gols detalhados da partida para preencher o formulário de edição.
require __DIR__ . "/../includes/bootstrap.php";
admin_required();
try {
    $id = (int) ($_GET["id"] ?? 0);
    $matchStmt = db()->prepare("SELECT mandante_id,visitante_id FROM partidas WHERE id=? AND ativo=1");
    $matchStmt->execute([$id]);
    $match = $matchStmt->fetch();
    if (!$match) throw new RuntimeException("Partida não encontrada.");
    $stmt = db()->prepare(
        "SELECT g.id,g.participante_id,g.jogador,g.minuto,g.tipo FROM gols_partida g JOIN partidas p ON p.id=g.partida_id WHERE g.partida_id=? AND p.ativo=1 ORDER BY g.id",
    );
    $stmt->execute([$id]);
    json_response(["ok" => true, "gols" => $stmt->fetchAll()] + $match);
} catch (Throwable $error) {
    json_response(
        [
            "ok" => false,
            "message" => "Não foi possível carregar os gols da partida.",
        ],
        500,
    );
}
