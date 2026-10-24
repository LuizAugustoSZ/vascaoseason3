<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
master_required();
header('Cache-Control: private, no-store, max-age=0');
try {
    json_response(['ok'=>true,'identidades'=>competition_admin_identities(db())]);
} catch(Throwable $error){
    error_log('Falha ao carregar modelos do painel: '.$error->getMessage());
    json_response(['ok'=>false,'message'=>'Não foi possível consultar os modelos de competição. Tente novamente.'],500);
}
