<?php
declare(strict_types=1);
require __DIR__ . '/../includes/sync.php';

$config = ['app' => ['base_url' => ''], 'sync' => ['source_url' => '']];
putenv('SYNC_SOURCE_URL=https://staging.vascaoseason3.online');
foreach (['vascaoseason3.online', 'www.vascaoseason3.online', SYNC_PRODUCTION_HOST] as $host) {
    $_SERVER['HTTP_HOST'] = $host . ':443';
    if (sync_source_url() !== '') throw new RuntimeException('Produção aceitou origem de staging.');
}
$_SERVER['HTTP_HOST'] = 'preview.hostingersite.com';
$config['app']['environment'] = 'production';
if (sync_source_url() !== '') throw new RuntimeException('Preview identificado como produção aceitou origem de staging.');
$config['app']['environment'] = 'staging';
$config['app']['base_url'] = 'https://vascaoseason3.online';
if (sync_source_url() !== '') throw new RuntimeException('Preview de produção aceitou origem de staging.');
$config['app']['base_url'] = '';
foreach ([SYNC_HOSTINGER_HOMOLOG_HOST => 'https://vascaoseason3.online', SYNC_HOMOLOG_HOST => 'https://' . SYNC_PRODUCTION_HOST] as $host => $source) {
    $_SERVER['HTTP_HOST'] = strtoupper($host);
    if (sync_source_url() !== $source) throw new RuntimeException('Origem incorreta na homologação.');
}
$_SERVER['HTTP_HOST'] = 'preview.hostingersite.com';
putenv('SYNC_SOURCE_URL=https://vascaoseason3-ironhaven.up.railway.app');
if (sync_source_url() !== 'https://' . SYNC_PRODUCTION_HOST) throw new RuntimeException('Fallback do preview incorreto.');
putenv('SYNC_SOURCE_URL');
echo "Isolamento de produção, staging, preview e compatibilidade Railway: OK\n";
