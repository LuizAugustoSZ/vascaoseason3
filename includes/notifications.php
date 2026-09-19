<?php
declare(strict_types=1);

function notifications_schema(PDO $pdo): void {
    static $ready=false;if($ready)return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_state (state_key VARCHAR(160) PRIMARY KEY,state_value TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_preferences (account_id INT UNSIGNED PRIMARY KEY,news_email TINYINT NOT NULL DEFAULT 0,market_email TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_id INT UNSIGNED NOT NULL,event_key VARCHAR(190) NOT NULL,kind VARCHAR(20) NOT NULL,title VARCHAR(255) NOT NULL,body TEXT NOT NULL,url VARCHAR(255) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,read_at DATETIME NULL,email_status VARCHAR(20) NOT NULL DEFAULT 'disabled',attempts INT NOT NULL DEFAULT 0,next_attempt DATETIME NULL,UNIQUE KEY event_account(account_id,event_key),KEY inbox(account_id,read_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready=true;
}

function notification_emit(PDO $pdo,int $account,string $key,string $kind,string $title,string $body,string $url):void {
    $preference=$pdo->prepare('SELECT news_email,market_email FROM notification_preferences WHERE account_id=?');$preference->execute([$account]);$prefs=$preference->fetch()?:[];
    $enabled=(int)($prefs[$kind==='news'?'news_email':'market_email']??0)===1;
    $pdo->prepare('INSERT IGNORE INTO notifications(account_id,event_key,kind,title,body,url,email_status) VALUES(?,?,?,?,?,?,?)')->execute([$account,$key,$kind,mb_substr($title,0,255),$body,$url,$enabled?'pending':'disabled']);
}

function notifications_collect(PDO $pdo):void {
    notifications_schema($pdo);
    if(!(int)$pdo->query("SELECT GET_LOCK('vascao_notifications',0)")->fetchColumn())return;
    try {
        $pdo->beginTransaction();
        $state=$pdo->query('SELECT state_key,state_value FROM notification_state')->fetchAll(PDO::FETCH_KEY_PAIR);
        $accounts=$pdo->query('SELECT id,participante_id FROM contas WHERE ativo=1')->fetchAll();
        $save=$pdo->prepare('INSERT INTO notification_state(state_key,state_value) VALUES(?,?) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value)');
        $latest=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM noticias')->fetchColumn();
        if(isset($state['news_cursor'])){
            $news=$pdo->prepare('SELECT id,titulo,resumo FROM noticias WHERE ativo=1 AND id>? ORDER BY id');$news->execute([(int)$state['news_cursor']]);
            foreach($news as $item)foreach($accounts as $account)notification_emit($pdo,(int)$account['id'],'news-'.$item['id'],'news','Nova notícia: '.$item['titulo'],strip_tags($item['resumo']??''),'noticia.php?id='.$item['id']);
        }
        $save->execute(['news_cursor',(string)$latest]);
        require_once __DIR__.'/mercado.php';
        $entries=$pdo->query("SELECT DISTINCT c.id,c.nome,p.mandante_id club_id FROM campeonatos c JOIN partidas p ON p.campeonato_id=c.id WHERE c.ativo=1 AND c.status<>'finalizado' AND p.ativo=1 UNION SELECT DISTINCT c.id,c.nome,p.visitante_id FROM campeonatos c JOIN partidas p ON p.campeonato_id=c.id WHERE c.ativo=1 AND c.status<>'finalizado' AND p.ativo=1")->fetchAll();
        foreach($entries as $entry){
            $current=mercado_estado_clube($pdo,(int)$entry['id'],(int)$entry['club_id']);
            $key='market-'.$entry['id'].'-'.$entry['club_id'];
            $value=json_encode(['open'=>(bool)$current['aberto'],'cycle'=>(int)$current['ciclo'],'remaining'=>(int)$current['restantes']]);
            if(isset($state[$key])&&$state[$key]!==$value){
                $before=json_decode($state[$key],true);$after=json_decode($value,true);
                $transition=$before['open']!==$after['open'];
                $reminder=$after['open']&&$after['remaining']===1&&($before['remaining']!==1||$before['cycle']!==$after['cycle']);
                if($transition||$reminder){
                    $label=$transition?($after['open']?'Mercado aberto':'Mercado fechado'):'Mercado perto de fechar';
                    foreach($accounts as $account)if((int)$account['participante_id']===(int)$entry['club_id'])notification_emit($pdo,(int)$account['id'],$key.'-'.hash('sha256',$value.'|'.$state[$key]),'market',$label.' — '.$entry['nome'],mercado_descricao_janela($current),'mercado.php?campeonato_id='.$entry['id']);
                }
            }
            $save->execute([$key,$value]);
        }
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    finally{$pdo->query("SELECT RELEASE_LOCK('vascao_notifications')");}
}

function notifications_deliver(PDO $pdo):void {
    require_once __DIR__.'/email.php';
    $base=rtrim((string)(getenv('APP_URL')?:($_SERVER['APP_URL']??$_ENV['APP_URL']??'')),'/');
    if(!filter_var($base,FILTER_VALIDATE_URL))return;
    if(!(int)$pdo->query("SELECT GET_LOCK('vascao_email_delivery',0)")->fetchColumn())return;
    try{
        $rows=$pdo->query("SELECT n.*,c.email,p.news_email,p.market_email FROM notifications n JOIN contas c ON c.id=n.account_id AND c.ativo=1 LEFT JOIN notification_preferences p ON p.account_id=c.id WHERE n.email_status='pending' AND n.attempts<5 AND n.created_at>DATE_SUB(NOW(),INTERVAL 23 HOUR) AND (n.next_attempt IS NULL OR n.next_attempt<=NOW()) ORDER BY n.id LIMIT 20")->fetchAll();
        foreach($rows as $row){
            if(!(int)($row[$row['kind']==='news'?'news_email':'market_email']??0)||!filter_var($row['email'],FILTER_VALIDATE_EMAIL)){$pdo->prepare("UPDATE notifications SET email_status='disabled' WHERE id=?")->execute([$row['id']]);continue;}
            $message=$row['body']."\n\nAcesse: ".$base.'/'.$row['url']."\n\nPreferências de e-mail: ".$base.'/notificacoes.php';
            $success=system_email_send((string)$row['email'],(string)$row['title'],$message,'notification-'.$row['id']);
            $pdo->prepare("UPDATE notifications SET email_status=?,attempts=attempts+1,next_attempt=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE id=?")->execute([$success?'sent':((int)$row['attempts']>=4?'failed':'pending'),$row['id']]);
        }
        $pdo->exec("UPDATE notifications SET email_status='expired' WHERE email_status='pending' AND created_at<=DATE_SUB(NOW(),INTERVAL 23 HOUR)");
    }finally{$pdo->query("SELECT RELEASE_LOCK('vascao_email_delivery')");}
}
