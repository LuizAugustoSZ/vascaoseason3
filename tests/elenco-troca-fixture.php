<?php
// Synthetic UI only; does not connect to a database or accept mutations.
require __DIR__.'/../includes/mercado.php';
require __DIR__.'/../includes/elenco-troca.php';
function e(?string $s): string{return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8');}
function csrf_token(): string{return 'fixture';}
function account_is_master(): bool{return false;}
$participantId=$sessionParticipantId=1;
$jogadores=[['id'=>1,'nome'=>'THIBAUT COURTOIS','overall'=>93,'posicao'=>'GOL'],['id'=>2,'nome'=>'EMERSON ROYAL','overall'=>92,'posicao'=>'LD'],['id'=>3,'nome'=>'LACROIX','overall'=>92,'posicao'=>'ZAG'],['id'=>4,'nome'=>'PASSE BLOQUEADO','overall'=>93,'posicao'=>'ATA'],['id'=>5,'nome'=>'RECEBIDO POR TROCA','overall'=>91,'posicao'=>'MEI']];
$origensJogadores=[1=>['origem'=>'passe','origem_detalhe'=>null],2=>['origem'=>'pack','origem_detalhe'=>'Pack aniversário'],3=>['origem'=>'compra_direta','origem_detalhe'=>null],4=>['origem'=>'passe','origem_detalhe'=>null],5=>['origem'=>'troca_passe','origem_detalhe'=>null]];
$bloqueiosVenda=[4=>['Brasileirão II']];
$source=file_get_contents(__DIR__.'/../elenco-geral.php');
$roster=substr($source,strpos($source,'<section class="panel p-4 mb-4"><div class="general-list-heading">'));
$roster=substr($roster,0,strpos($roster,'<section class="panel p-4 general-history">'));
$modal=substr($source,strpos($source,'<div class="modal fade general-dark-modal" id="general-trade-modal"'));
$modal=substr($modal,0,strpos($modal,'<div class="modal fade" id="general-sale-modal"'));
?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"><link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="../assets/css/elenco-geral.css"></head><body><main class="container py-4">
<form hidden><select name="origem"><option value="compra_direta">Compra direta</option></select><input name="nome"><input name="overall"><div class="general-pack-field"><select name="pack"></select></div><div class="general-value-field"><input name="valor"></div></form>
<?php eval('?>'.$roster);eval('?>'.$modal); ?>
</main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script src="../assets/js/elenco-geral.js"></script></body></html>
