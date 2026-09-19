<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
master_required();
try {
    $pdo=db(); competition_identities_seed($pdo);
    $items=$pdo->query("SELECT i.id,i.chave,i.nome,COUNT(DISTINCT c.id) edicoes FROM competicao_identidades i LEFT JOIN campeonatos c ON c.identidade_id=i.id GROUP BY i.id,i.chave,i.nome ORDER BY i.nome")->fetchAll();
    $titles=$pdo->query('SELECT t.titulo,i.chave identidade_chave FROM titulos t LEFT JOIN campeonatos c ON c.id=t.campeonato_id LEFT JOIN competicao_identidades i ON i.id=c.identidade_id')->fetchAll();
    foreach($items as &$item){
        $item['id']=(int)$item['id'];$item['edicoes']=(int)$item['edicoes'];
        $item['titulos']=count(array_filter($titles,static fn(array $title):bool=>(($title['identidade_chave']?:competition_identity_match((string)$title['titulo']))===$item['chave'])));
        $item['logo_url']='../api/competicao-imagem.php?identidade_id='.$item['id'].'&tipo=logo';
        $item['trofeu_url']='../api/competicao-imagem.php?identidade_id='.$item['id'].'&tipo=trofeu';
    }
    unset($item); json_response(['ok'=>true,'identidades'=>$items]);
} catch(Throwable $error){json_response(['ok'=>false,'message'=>$error->getMessage()],500);}
