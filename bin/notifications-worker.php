<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/bootstrap.php';
// A configuração privada também pode definir as flags por putenv().
if (getenv('NOTIFICATIONS_WORKER_ENABLED') !== false && !filter_var(getenv('NOTIFICATIONS_WORKER_ENABLED'), FILTER_VALIDATE_BOOLEAN)) exit(0);
require __DIR__.'/../includes/notifications.php';
session_write_close();

// O lock existente no banco continua protegendo coleta e entrega; este lock
// também impede sobreposição de execuções cron no mesmo servidor.
$lock = fopen(sys_get_temp_dir() . '/vascao-worker-' . hash('sha256', __DIR__) . '.lock', 'c');
if ($lock === false) {fwrite(STDERR, "Não foi possível abrir o lock do worker.\n"); exit(1);}
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);
do {
    try {notifications_collect(db());notifications_deliver(db());}catch(Throwable $error){error_log('Notification worker failed: ' . get_class($error)); if(in_array('--once',$argv,true))exit(1);}
    if(in_array('--once',$argv,true))break;
    sleep(60);
}while(true);
