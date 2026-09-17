<?php require __DIR__.'/includes/bootstrap.php';require __DIR__.'/includes/public-layout.php'; ?>
<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>COMPETIÇÃO | Vascão</title><link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="dedicated-page competition-page"><?php public_navbar('competicao');?><link rel="stylesheet" href="assets/css/branding.css"><link rel="stylesheet" href="assets/css/bracket-v5.css"><link rel="stylesheet" href="assets/css/season3-update.css"><link rel="stylesheet" href="assets/css/games.css"><link rel="stylesheet" href="assets/css/shields.css"><link rel="stylesheet" href="assets/css/version-history.css"><link rel="stylesheet" href="assets/css/export-actions.css"><link rel="stylesheet" href="assets/css/public-states.css"><link rel="stylesheet" href="assets/css/socials.css"><link rel="stylesheet" href="assets/css/competition-pages.css?v=<?=filemtime(__DIR__.'/assets/css/competition-pages.css')?>"><main><header class="dedicated-hero container"><a href="index.php">Início</a><span> / COMPETIÇÃO</span><h1>COMPETIÇÃO</h1><p>Acompanhe a classificação, os jogos e as estatísticas da competição.</p></header><section id="competicao" class="section-pad">
            <div class="container">
                
                <ul class="nav competition-tabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pontos-corridos">Pontos corridos</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#mata-mata">Mata-mata</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#supercopa">Supercopa</button></li>
                </ul>
                <div class="tab-content pt-4">
                    <div class="tab-pane fade show active" id="pontos-corridos">
                        <div class="row g-4">
                            <div class="col-xl-8">
                                <div class="panel">
                                    <div class="panel-head">
                                        <h3><i data-lucide="table-2" aria-hidden="true"></i>Classificação</h3><span>Atualização automática</span>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table ranking-table mb-0">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Time</th>
                                                    <th>Técnico</th>
                                                    <th>PTS</th>
                                                    <th>J</th>
                                                    <th>V</th>
                                                    <th>E</th>
                                                    <th>D</th>
                                                    <th>SG</th>
                                                </tr>
                                            </thead>
                                            <tbody id="standings-body"></tbody>
                                        </table>
                                    </div>
                                    <div class="standings-legend" aria-label="Legenda da classificação">
                                        <span><i class="standings-legend-color" aria-hidden="true"></i> Libertadores · 1º ao 4º</span>
                                        <span><i class="standings-legend-color sulamericana" aria-hidden="true"></i> Sul-Americana · 5º ao 8º</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-4">
                                <div class="panel h-100">
                                    <div class="panel-head">
                                        <h3><i data-lucide="swords" aria-hidden="true"></i>Jogos</h3><span id="round-status">Selecione a rodada</span>
                                    </div>
                                    <div class="game-tools"><label class="form-label" for="round-select">Rodada</label>
                                        <div class="round-navigation">
                                        <button type="button" id="round-prev" class="round-arrow" aria-label="Rodada anterior" title="Rodada anterior" disabled>‹</button>
                                        <select id="round-select" class="form-select">
                                            <option value="all">Todas as rodadas</option>
                                        </select>
                                        <button type="button" id="round-next" class="round-arrow" aria-label="Próxima rodada" title="Próxima rodada" disabled>›</button>
                                        </div>
                                        <div class="command-search game-search mt-2"><span>⌕</span><input id="game-search" type="search" placeholder="Buscar técnico ou time..." autocomplete="off"></div>
                                    </div>
                                    <div id="league-games" class="game-list"></div>
                                    <div id="league-pagination" class="game-pagination"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="mata-mata">
                        <div class="panel">
                            <div class="panel-head">
                                <h3><i data-lucide="git-fork" aria-hidden="true"></i>Chaveamento</h3><span>Da primeira fase até a final</span>
                            </div>
                            <div id="bracket" class="bracket"></div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="supercopa">
                        <div class="panel">
                            <div class="panel-head"><h3><i data-lucide="trophy" aria-hidden="true"></i>Decisão dos campeões</h3><span>Vagas automáticas</span></div>
                            <div id="supercup-bracket" class="bracket"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section></main><?php public_footer();?><script src="https://code.jquery.com/jquery-4.0.0.min.js"></script><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script src="assets/js/script.js?v=<?=filemtime(__DIR__.'/assets/js/script.js')?>"></script><script src="assets/js/competition-pages.js?v=<?=filemtime(__DIR__.'/assets/js/competition-pages.js')?>"></script></body></html>
