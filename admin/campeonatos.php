<?php
require __DIR__ . "/../includes/bootstrap.php";
master_required();
$pdo = db();
$notice = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    $id = (int) ($_POST["id"] ?? 0);
    $status = $_POST["status"] ?? "";
    if (in_array($status, ["ativo", "finalizado"], true)) {
        $stmt = $pdo->prepare(
            "UPDATE campeonatos SET status=? WHERE id=? AND ativo=1",
        );
        $stmt->execute([$status, $id]);
        $notice = "Status atualizado.";
    }
}
$items = $pdo
    ->query(
        "SELECT c.*,(SELECT COUNT(*) FROM partidas p WHERE p.campeonato_id=c.id AND p.ativo=1)+(SELECT COUNT(*) FROM jogos_mata_mata j WHERE j.campeonato_id=c.id AND j.ativo=1) jogos FROM campeonatos c WHERE c.ativo=1 ORDER BY c.criado_em DESC,c.id DESC",
    )
    ->fetchAll();
?><!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Campeonatos | S3</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/style.css"></head><body><main class="container py-5"><div class="d-flex justify-content-between mb-4"><div><span class="eyebrow">Histórico</span><h1>CAMPEONATOS</h1></div><div><a class="btn btn-outline-light" href="sorteador.php">Sorteador</a> <a class="btn btn-danger" href="index.php">Admin</a></div></div><?php if (
    $notice
): ?><div class="alert alert-info"><?= e(
    $notice,
) ?></div><?php endif; ?><div class="panel table-responsive"><table class="table mb-0"><thead><tr><th>Nome</th><th>Modalidade</th><th>Formato</th><th>Jogos</th><th>Status</th><th>Ação</th></tr></thead><tbody><?php foreach (
    $items
    as $item
): ?><tr><td><strong><?= e($item["nome"]) ?></strong></td><td><?= $item[
    "tipo"
] === "mata_mata"
    ? "Mata-mata"
    : "Pontos corridos" ?></td><td><?= e(
    str_replace("_", " e ", $item["formato"]),
) ?></td><td><?= $item["jogos"] ?></td><td><?= e(
    $item["status"],
) ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="id" value="<?= $item[
    "id"
] ?>"><input type="hidden" name="status" value="<?= $item["status"] === "ativo"
    ? "finalizado"
    : "ativo" ?>"><button class="btn btn-sm btn-outline-light"><?= $item[
    "status"
] === "ativo"
    ? "Finalizar"
    : "Reabrir" ?></button></form></td></tr><?php endforeach; ?></tbody></table></div></main></body></html>
