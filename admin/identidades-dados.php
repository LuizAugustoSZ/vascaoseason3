<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
master_required();
try {
    $pdo=db(); competition_identities_seed($pdo);
    $items=$pdo->query("SELECT i.id,i.chave,i.nome,COUNT(DISTINCT c.id) edicoes FROM competicao_identidades i LEFT JOIN campeonatos c ON c.identidade_id=i.id GROUP BY i.id ORDER BY i.nome")->fetchAll();
    $titles=$pdo->query('SELECT titulo FROM titulos')->fetchAll(PDO::FETCH_COLUMN);
    foreach($items as &$item){
        $item['id']=(int)$item['id'];$item['edicoes']=(int)$item['edicoes'];
        $item['titulos']=count(array_filter($titles,static fn(string $title):bool=>competition_identity_match($title)===$item['chave']));
        $item['logo_url']='../api/competicao-imagem.php?identidade_id='.$item['id'].'&tipo=logo';
        $item['trofeu_url']='../api/competicao-imagem.php?identidade_id='.$item['id'].'&tipo=trofeu';
    }
    unset($item); json_response(['ok'=>true,'identidades'=>$items]);
} catch(Throwable $error){json_response(['ok'=>false,'message'=>$error->getMessage()],500);}
