<?php require __DIR__.'/includes/bootstrap.php';require __DIR__.'/includes/public-layout.php'; ?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>JOGADORES | Vascão</title><link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="dedicated-page players-page"><?php public_navbar('artilharia');?><link rel="stylesheet" href="assets/css/competition-pages.css?v=<?=filemtime(__DIR__.'/assets/css/competition-pages.css')?>"><main><header class="dedicated-hero container"><a href="index.php">Início</a><span> / JOGADORES</span><h1>JOGADORES</h1><p>Os craques que fazem a diferença em campo.</p></header><section id="artilharia" class="section-pad">
            <div class="container">
                
                <div class="panel p-3 mb-3"><label class="form-label small" for="scorers-championship-select">CAMPEONATO</label><select id="scorers-championship-select" class="form-select"></select><small id="scorers-championship-title" class="text-secondary"></small></div>
                <div class="panel">
                    <div class="panel-head">
                        <div class="player-ranking-tabs" role="tablist" aria-label="Ranking de jogadores"><button type="button" class="active" data-ranking="goals" role="tab" aria-selected="true">Artilheiros</button><button type="button" data-ranking="assists" role="tab" aria-selected="false">Assistências</button></div><button id="scorers-download" class="competition-download" type="button" title="Baixar ranking completo como PNG" aria-label="Baixar ranking completo como PNG"><svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 3v12m0 0 5-5m-5 5-5-5M5 19h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg></button>
                    </div>
                    <div id="scorers-list" class="scorers-list"></div>
                    <div id="scorers-pagination" class="scorers-pagination"></div>
                </div>
            </div>
        </section></main><?php public_footer();?><script src="https://code.jquery.com/jquery-4.0.0.min.js"></script><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script src="assets/js/script.js?v=<?=filemtime(__DIR__.'/assets/js/script.js')?>"></script><script src="assets/js/competition-pages.js?v=<?=filemtime(__DIR__.'/assets/js/competition-pages.js')?>"></script></body></html>