<?php
// Render the real Elenco Geral template with synthetic data; no database or writes.
require __DIR__ . '/../includes/mercado.php';
require __DIR__ . '/../includes/elenco-troca.php';
function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'fixture'; }
function account_is_master(): bool { return false; }
function site_favicon(): void {}
function public_navbar(string $page): void {}
function public_footer(): void {}
function format_datetime_br(string $value): string { return $value; }
$participantId = $sessionParticipantId = 1;
$_SESSION = ['conta_id' => 1];
$instantSave = false;
$message = $error = '';
$time = ['time_nome' => 'Clube de teste'];
$clube = ['saldo' => 10000];
$bloqueiosVenda = [];
$jogadores = $origensJogadores = $historico = [];
$variant = $argv[1] ?? '';
for ($id = 1; $id <= 26; $id++) {
    if ($variant === 'sold' && $id === 25) continue;
    $name = $variant === 'edited' && $id === 25 ? 'COURTOIS EDITADO' : 'JOGADOR ' . $id;
    $jogadores[] = ['id'=>$id,'nome'=>$name,'overall'=>93,'posicao'=>'GOL'];
    $origensJogadores[$id] = ['origem'=>'passe','origem_detalhe'=>null];
}
for ($id = 1; $id <= 10; $id++) {
    $historico[] = ['id'=>$id,'fonte'=>'geral','tipo'=>'compra','origem'=>'passe','origem_detalhe'=>null,'jogador_nome'=>'JOGADOR '.$id,'jogador_overall'=>93,'jogador_posicao'=>'GOL','valor'=>0,'criado_em'=>'2026-10-11 12:00:00'];
}
$source = file_get_contents(__DIR__ . '/../elenco-geral.php');
$template = substr($source, strpos($source, '?><!doctype html>') + 2);
$template = str_replace('__DIR__', var_export(dirname(__DIR__), true), $template);
eval('?>' . $template);
