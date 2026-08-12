<?php
// Lista campeonatos ativos para os formulários do painel.
require __DIR__ . '/../includes/bootstrap.php';
admin_required();
$items=db()->query("SELECT id,nome,tipo,status FROM campeonatos WHERE ativo=1 ORDER BY criado_em DESC,id DESC")->fetchAll();
json_response(['ok'=>true,'campeonatos'=>$items]);
