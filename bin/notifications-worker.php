<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/notifications.php';
session_write_close();
do {
    try {notifications_collect(db());notifications_deliver(db());}catch(Throwable $error){error_log('Notification worker: '.$error->getMessage());}
    if(in_array('--once',$argv,true))break;
    sleep(60);
}while(true);
