<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
master_required();
$pdo = db();
audit_ensure_schema();
$embedded = isset($_GET['embed']);

$days = max(7, min(90, (int)($_GET['dias'] ?? 30)));
$event = trim((string)($_GET['evento'] ?? ''));
$module = trim((string)($_GET['modulo'] ?? ''));
$search = trim((string)($_GET['busca'] ?? ''));
$page = max(1, (int)($_GET['pagina'] ?? 1));
$limit = 20; $offset = ($page - 1) * $limit;
$where = ['criado_em >= DATE_SUB(NOW(), INTERVAL ? DAY)']; $params = [$days];
if ($event !== '') { $where[] = 'evento=?'; $params[] = $event; }
if ($module !== '') { $where[] = 'modulo=?'; $params[] = $module; }
if ($search !== '') { $where[] = '(conta_nome LIKE ? OR descricao LIKE ? OR recurso_id LIKE ? OR detalhes_json LIKE ?)'; $like = "%$search%"; array_push($params, $like, $like, $like, $like); }
$whereSql = implode(' AND ', $where);

$summary = $pdo->prepare("SELECT COUNT(*) eventos, COUNT(DISTINCT conta_id) usuarios, SUM(evento='login') logins, SUM(evento IN ('cadastro','edicao','remocao')) alteracoes FROM audit_logs WHERE criado_em >= DATE_SUB(NOW(), INTERVAL ? DAY)");
$summary->execute([$days]); $metrics = $summary->fetch() ?: [];
$daily = $pdo->prepare("SELECT DATE(criado_em) dia, SUM(evento='login') logins, SUM(evento IN ('cadastro','edicao','remocao')) alteracoes FROM audit_logs WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(criado_em) ORDER BY dia");
$daily->execute([$days]);
$dailyByDate = [];
foreach ($daily->fetchAll() as $item) $dailyByDate[$item['dia']] = $item;
$dailyRows = [];
$periodStart = new DateTimeImmutable('today -' . ($days - 1) . ' days');
for ($index = 0; $index < $days; $index++) {
    $date = $periodStart->modify("+$index days")->format('Y-m-d');
    $dailyRows[] = $dailyByDate[$date] ?? ['dia' => $date, 'logins' => 0, 'alteracoes' => 0];
}
$byModule = $pdo->prepare("SELECT modulo,COUNT(*) total FROM audit_logs WHERE criado_em >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY modulo ORDER BY total DESC LIMIT 10");
$byModule->execute([$days]); $moduleRows = $byModule->fetchAll();
$count = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE $whereSql"); $count->execute($params); $total = (int)$count->fetchColumn();
$logs = $pdo->prepare("SELECT * FROM audit_logs WHERE $whereSql ORDER BY criado_em DESC,id DESC LIMIT $limit OFFSET $offset"); $logs->execute($params); $rows = $logs->fetchAll();
$events = $pdo->query("SELECT DISTINCT evento FROM audit_logs ORDER BY evento")->fetchAll(PDO::FETCH_COLUMN);
$modules = $pdo->query("SELECT DISTINCT modulo FROM audit_logs ORDER BY modulo")->fetchAll(PDO::FETCH_COLUMN);
$pages = max(1, (int)ceil($total / $limit));
function dash_url(array $changes): string { return '?' . http_build_query(array_merge($_GET, $changes)); }
function audit_details_summary(array $row): string {
    $data = json_decode((string)($row['detalhes_json'] ?? ''), true);
    if (!is_array($data)) return '';
    $labels = [
        'action'=>'Ação', 'nome'=>'Jogador/nome', 'jogador'=>'Jogador', 'jogador_id'=>'Jogador ID',
        'campeonato_id'=>'Campeonato ID', 'participante_id'=>'Clube ID', 'movimentacao_id'=>'Movimentação ID',
        'partida_id'=>'Partida ID', 'noticia_id'=>'Notícia ID', 'valor'=>'Valor', 'overall'=>'OVR',
        'posicao'=>'Posição', 'grupo'=>'Grupo', 'origem'=>'Origem', 'formato'=>'Formato',
        'titular_id_total'=>'Titulares', 'participantes_total'=>'Participantes',
    ];
    $parts = [];
    foreach ($labels as $key => $label) {
        if (!isset($data[$key]) || (string)$data[$key] === '') continue;
        $parts[] = $label . ': ' . (string)$data[$key];
    }
    return implode(' · ', $parts);
}
?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard Master | Vascão S3</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="../assets/css/branding.css?v=5"><style>
body{background:#08090b}.dash{padding:<?= $embedded ? '0 0 40px' : '42px 0 70px' ?>}.dash-top,.metric-grid,.chart-grid{display:grid;gap:16px}.dash-top{grid-template-columns:1fr auto;align-items:end}.metric-grid{grid-template-columns:repeat(4,1fr);margin:24px 0}.metric,.chart-card,.log-panel{background:#111318;border:1px solid #292d35;border-radius:16px}.metric{padding:20px}.metric small{color:#9299a5;text-transform:uppercase}.metric strong{display:block;font:800 2.2rem 'Barlow Condensed',sans-serif}.chart-grid{grid-template-columns:2fr 1fr;margin-bottom:24px}.chart-card{padding:20px;min-width:0}.chart-canvas{position:relative;width:100%;height:280px}.filters{display:grid;grid-template-columns:120px 1fr 1fr 2fr auto;gap:10px;padding:16px}.event{font-weight:700;text-transform:capitalize}.details{max-width:360px;color:#aeb4bf}.empty{padding:50px;text-align:center;color:#9299a5}@media(max-width:900px){.metric-grid{grid-template-columns:repeat(2,1fr)}.chart-grid{grid-template-columns:1fr}.filters{grid-template-columns:1fr}.dash-top{grid-template-columns:1fr}.chart-canvas{height:240px}}
</style></head><body><main class="dash"><div class="<?= $embedded ? 'container-fluid px-0' : 'container' ?>"><div class="dash-top"><div><span class="eyebrow">Admin Master</span><h1 class="display-4 fw-bold mb-0">DASHBOARD</h1><p class="text-secondary mb-0">Atividade, acessos e alterações registradas no sistema.</p></div><?php if (!$embedded): ?><div class="d-flex gap-2"><a class="btn btn-outline-danger" href="sorteador.php">Sorteador</a><a class="btn btn-outline-light" href="index.php">Voltar ao Admin</a></div><?php endif; ?></div>
<div class="metric-grid"><article class="metric"><small>Eventos</small><strong><?= (int)($metrics['eventos'] ?? 0) ?></strong></article><article class="metric"><small>Usuários ativos</small><strong><?= (int)($metrics['usuarios'] ?? 0) ?></strong></article><article class="metric"><small>Logins</small><strong><?= (int)($metrics['logins'] ?? 0) ?></strong></article><article class="metric"><small>Alterações</small><strong><?= (int)($metrics['alteracoes'] ?? 0) ?></strong></article></div>
<div class="chart-grid"><section class="chart-card"><h2 class="h5">Atividade por dia</h2><div class="chart-canvas"><canvas id="daily-chart"></canvas></div></section><section class="chart-card"><h2 class="h5">Eventos por módulo</h2><div class="chart-canvas"><canvas id="module-chart"></canvas></div></section></div>
<section class="log-panel"><form class="filters"><?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?><select class="form-select" name="dias"><?php foreach([7,30,60,90] as $d): ?><option value="<?= $d ?>" <?= $days===$d?'selected':'' ?>><?= $d ?> dias</option><?php endforeach; ?></select><select class="form-select" name="evento"><option value="">Todos os eventos</option><?php foreach($events as $item): ?><option <?= $event===$item?'selected':'' ?>><?= e($item) ?></option><?php endforeach; ?></select><select class="form-select" name="modulo"><option value="">Todos os módulos</option><?php foreach($modules as $item): ?><option <?= $module===$item?'selected':'' ?>><?= e($item) ?></option><?php endforeach; ?></select><input class="form-control" name="busca" value="<?= e($search) ?>" placeholder="Buscar usuário, descrição ou registro"><button class="btn btn-danger">Filtrar</button></form><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Data</th><th>Evento</th><th>Módulo</th><th>Usuário</th><th>Registro</th><th>Detalhes</th></tr></thead><tbody><?php foreach($rows as $row): ?><?php $detailSummary = audit_details_summary($row); ?><tr><td class="text-nowrap"><?= e(format_datetime_br($row['criado_em'])) ?></td><td><span class="event"><?= e(str_replace('_',' ',$row['evento'])) ?></span></td><td><?= e($row['modulo']) ?></td><td><?= e($row['conta_nome'] ?: 'Visitante') ?></td><td><?= e(trim(($row['recurso_tipo'] ?: '') . ' #' . ($row['recurso_id'] ?: ''), ' #')) ?: '—' ?></td><td class="details"><strong><?= e($row['descricao']) ?></strong><?= $detailSummary !== '' ? '<small class="d-block mt-1">'.e($detailSummary).'</small>' : '' ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="6" class="empty">Nenhum evento encontrado neste período.</td></tr><?php endif; ?></tbody></table></div><div class="d-flex justify-content-between align-items-center p-3"><small class="text-secondary"><?= $total ?> registro(s)</small><div class="btn-group"><?php if($page>1): ?><a class="btn btn-sm btn-outline-light" href="<?= e(dash_url(['pagina'=>$page-1])) ?>">Anterior</a><?php endif; ?><span class="btn btn-sm btn-dark"><?= $page ?>/<?= $pages ?></span><?php if($page<$pages): ?><a class="btn btn-sm btn-outline-light" href="<?= e(dash_url(['pagina'=>$page+1])) ?>">Próxima</a><?php endif; ?></div></div></section></div></main>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.0/dist/chart.umd.min.js"></script><script>
const daily=<?= json_encode($dailyRows, JSON_UNESCAPED_UNICODE) ?>, modules=<?= json_encode($moduleRows, JSON_UNESCAPED_UNICODE) ?>;
Chart.defaults.color='#b8bec8';Chart.defaults.borderColor='#2b3038';
new Chart(document.getElementById('daily-chart'),{type:'line',data:{labels:daily.map(x=>{const [y,m,d]=x.dia.split('-');return `${d}/${m}`}),datasets:[{label:'Logins',data:daily.map(x=>+x.logins),borderColor:'#d71920',backgroundColor:'#d7192033',fill:true,tension:.3,pointRadius:3},{label:'Alterações',data:daily.map(x=>+x.alteracoes),borderColor:'#f3b33d',tension:.3,pointRadius:3}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{ticks:{maxTicksLimit:10,maxRotation:0}}}}});
new Chart(document.getElementById('module-chart'),{type:'doughnut',data:{labels:modules.map(x=>x.modulo),datasets:[{data:modules.map(x=>+x.total),backgroundColor:['#d71920','#f3b33d','#5b8def','#39b982','#9b6bdf','#ef6c8f','#76808f']}]},options:{responsive:true,maintainAspectRatio:false}});
</script></body></html>
