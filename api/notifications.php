<?php
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/notifications.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(!account_logged_in()){http_response_code(401);echo json_encode(['error'=>'Entre na sua conta.']);exit;}
$pdo=db();notifications_schema($pdo);$account=(int)$_SESSION['conta_id'];
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    if(($_POST['action']??'')==='preferences'){
        $pdo->prepare('INSERT INTO notification_preferences(account_id,news_email,market_email) VALUES(?,?,?) ON DUPLICATE KEY UPDATE news_email=VALUES(news_email),market_email=VALUES(market_email)')->execute([$account,isset($_POST['news_email'])?1:0,isset($_POST['market_email'])?1:0]);
    }else{
        $through=(int)($_POST['through']??0);
        if($through>0){
            $pdo->prepare('UPDATE notifications SET read_at=NOW() WHERE account_id=? AND read_at IS NULL AND id<=?')->execute([$account,$through]);
        }else{
            $pdo->prepare('UPDATE notifications SET read_at=NOW() WHERE account_id=? AND read_at IS NULL')->execute([$account]);
        }
    }
}
$q=$pdo->prepare('SELECT id,kind,title,body,url,created_at,read_at FROM notifications WHERE account_id=? ORDER BY id DESC LIMIT 30');$q->execute([$account]);$items=$q->fetchAll();
$q=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE account_id=? AND read_at IS NULL');$q->execute([$account]);
echo json_encode(['items'=>$items,'unread'=>(int)$q->fetchColumn()],JSON_UNESCAPED_UNICODE);
