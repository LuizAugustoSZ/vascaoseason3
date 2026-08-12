<?php
require __DIR__ . "/../includes/bootstrap.php";
master_required();

$id = (int) ($_GET["id"] ?? 0);
if ($id < 1) {
    json_response(["ok" => false, "message" => "Usuário inválido."], 422);
}
$stmt = db()->prepare(
    "SELECT id,participante_id,nome,email,eh_admin,ativo,trocar_senha FROM contas WHERE id=? LIMIT 1",
);
$stmt->execute([$id]);
$conta = $stmt->fetch();
if (!$conta) {
    json_response(["ok" => false, "message" => "Usuário não encontrado."], 404);
}
json_response(["ok" => true, "conta" => $conta]);
