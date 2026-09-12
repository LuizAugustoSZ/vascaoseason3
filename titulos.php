<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require __DIR__.'/includes/public-layout.php';
require __DIR__.'/includes/trophy-order.php';
$identities=[];$titles=[];$canOrder=false;
try {
    $pdo=db(); competition_identities_seed($pdo);
    $canOrder=trophy_order_allowed($pdo);
    $identities=$pdo->query("SELECT i.id,i.chave,i.nome,COALESCE(i.logo_base64,'')<>'' tem_logo,COALESCE(i.trofeu_base64,'')<>'' tem_trofeu FROM competicao_identidades i ORDER BY i.ordem_exibicao IS NULL,i.ordem_exibicao,i.nome,i.id")->fetchAll();
    $titles=$pdo->query("SELECT t.id,t.titulo,t.temporada,t.conquistado_em,COALESCE(p.nome,t.tecnico_nome) tecnico,COALESCE(p.time_nome,t.time_nome) clube,p.escudo_url,p.id participante_id,COALESCE(p.ativo,0) participante_ativo FROM titulos t LEFT JOIN participantes p ON p.id=t.participante_id ORDER BY t.id")->fetchAll();
} catch(Throwable $ignored) {}
$grouped=[];
foreach($titles as $title){$key=competition_identity_match((string)$title['titulo']);if($key)$grouped[$key][]=$title;}
function title_edition_number(string $title): int {
    if (!preg_match('/\b([IVXLCDM]+)$/i',trim($title),$match)) return 1;
    $roman=strtoupper($match[1]);$values=['I'=>1,'V'=>5,'X'=>10,'L'=>50,'C'=>100,'D'=>500,'M'=>1000];$number=0;$previous=0;
    for($index=strlen($roman)-1;$index>=0;$index--){$current=$values[$roman[$index]]??0;$number+=$current<$previous?-$current:$current;$previous=max($previous,$current);}
    return max(1,$number);
}
foreach($grouped as &$champions) usort($champions,static fn(array $a,array $b):int=>title_edition_number((string)$b['titulo'])<=>title_edition_number((string)$a['titulo'])?:((int)$b['id']<=>(int)$a['id']));
unset($champions);
?><!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vitrine de Títulos | Vascão S3</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/branding.css?v=5"><link rel="stylesheet" href="assets/css/titles-showcase.css?v=<?= filemtime(__DIR__.'/assets/css/titles-showcase.css') ?>"></head><body>
<?php public_navbar('titulos'); ?>
<main class="titles-page"><div class="container"><header class="titles-hero"><span class="eyebrow">Salão de conquistas</span><h1>VITRINE DE TAÇAS</h1><p>Cada competição tem sua própria história. Explore as taças em tamanho grande e revele os campeões de todas as edições.</p></header>
<?php if($canOrder): ?>
<div class="trophy-order-toolbar" data-csrf="<?= e(csrf_token()) ?>">
<button type="button" class="btn btn-outline-light" id="trophy-order-toggle">Ordenar taças</button>
<div id="trophy-order-actions" hidden><span>Mova as taças para a posição desejada.</span><button type="button" class="btn btn-danger" id="trophy-order-save">Salvar ordem</button><button type="button" class="btn btn-outline-light" id="trophy-order-cancel">Cancelar</button></div>
<p id="trophy-order-status" role="status" aria-live="polite"></p>
</div>
<?php endif; ?>
<section class="trophy-showcase">
<?php foreach($identities as $identity): $champions=$grouped[$identity['chave']]??[]; ?><article class="trophy-card" data-identity-id="<?= (int)$identity['id'] ?>">
<?php if($canOrder): ?><div class="trophy-order-controls" hidden><span class="trophy-order-position"></span><button type="button" class="btn btn-sm btn-outline-light" data-move="-1" aria-label="Mover <?= e($identity['nome']) ?> para antes">← Antes</button><button type="button" class="btn btn-sm btn-outline-light" data-move="1" aria-label="Mover <?= e($identity['nome']) ?> para depois">Depois →</button></div><?php endif; ?>
<div class="trophy-stage"><?php if($identity['tem_trofeu']): ?><img src="api/competicao-imagem.php?identidade_id=<?= (int)$identity['id'] ?>&tipo=trofeu" alt="Taça <?= e($identity['nome']) ?>"><?php else: ?><span class="trophy-coming" aria-label="Taça aguardando arte">🏆</span><?php endif; ?></div><div class="trophy-copy"><?php if($identity['tem_logo']): ?><img class="trophy-logo" src="api/competicao-imagem.php?identidade_id=<?= (int)$identity['id'] ?>&tipo=logo" alt="Logo <?= e($identity['nome']) ?>"><?php else: ?><span class="trophy-logo-coming">ARTE EM BREVE</span><?php endif; ?><h2><?= e($identity['nome']) ?></h2><small class="text-secondary"><?= count($champions) ?> título<?= count($champions)===1?'':'s' ?> registrado<?= count($champions)===1?'':'s' ?></small><button class="btn btn-outline-light show-champions" type="button" data-key="<?= e($identity['chave']) ?>" data-name="<?= e($identity['nome']) ?>">VER CAMPEÕES</button></div></article><?php endforeach; ?>
</section></div></main>
<div class="modal fade champions-modal" id="champions-modal" tabindex="-1" aria-labelledby="champions-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><small class="eyebrow">Galeria de vencedores</small><h2 class="modal-title" id="champions-modal-title">CAMPEÕES</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><div id="champions-modal-list" class="champions-modal-list"></div></div></div></div></div>
<?php public_footer(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script>const champions=<?= json_encode($grouped,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,escapeHtml=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char])),initials=value=>String(value||'?').split(/\s+/).slice(0,2).map(word=>word[0]||'').join('').toUpperCase(),modalElement=document.getElementById('champions-modal'),modal=bootstrap.Modal.getOrCreateInstance(modalElement),list=document.getElementById('champions-modal-list'),title=document.getElementById('champions-modal-title');document.querySelectorAll('.show-champions').forEach(button=>button.addEventListener('click',()=>{const rows=champions[button.dataset.key]||[];title.textContent=button.dataset.name;list.innerHTML=rows.length?rows.map(item=>{const current=Number(item.participante_id)>0&&Number(item.participante_ativo)===1,href=`time.php?id=${Number(item.participante_id)}`,shield=item.escudo_url?`<img src="${escapeHtml(item.escudo_url)}" alt="">`:`<span>${escapeHtml(initials(item.clube||item.tecnico))}</span>`,shieldHtml=current?`<a class="champion-shield" href="${href}" aria-label="Abrir página de ${escapeHtml(item.clube||item.tecnico)}">${shield}</a>`:`<div class="champion-shield">${shield}</div>`,nameHtml=current?`<a class="champion-team-link" href="${href}"><small>VER PÁGINA DO TIME</small><strong>${escapeHtml(item.clube||item.tecnico)}</strong></a>`:`<strong>${escapeHtml(item.clube||item.tecnico)}</strong>`;return `<article class="champion-modal-row">${shieldHtml}<div class="champion-modal-copy">${nameHtml}<small>Técnico ${escapeHtml(item.tecnico||'Não informado')}</small><span>${escapeHtml(item.titulo)}</span></div><b>${escapeHtml(item.temporada)}</b></article>`}).join(''):'<p class="empty-champion text-center py-4">Nenhum campeão cadastrado ainda.</p>';modal.show()}));</script><?php if($canOrder): ?><script src="assets/js/trophy-order.js?v=<?= filemtime(__DIR__.'/assets/js/trophy-order.js') ?>"></script><?php endif; ?></body></html>
