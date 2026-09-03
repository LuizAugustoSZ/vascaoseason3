<section id="tab-noticias" class="tab-pane fade">
  <div class="panel news-prompt-generator mb-4">
    <div class="panel-head">
      <div><small>ASSISTENTE DE NOTÍCIAS</small><h3>GERAR PROMPT DA RODADA</h3></div>
      <span id="round-prompt-context">Identificando a rodada atual...</span>
    </div>
    <p class="text-secondary">O sistema reúne placares, estatísticas, artilheiros e todos os acontecimentos registrados nas súmulas. Depois é só copiar e colar no ChatGPT.</p>
    <div class="row g-3 align-items-end">
      <div class="col-lg-7"><label class="form-label" for="round-prompt-championship">Campeonato</label><select id="round-prompt-championship" class="form-select">
        <option value="">Selecione</option>
        <?php foreach ($championshipsAdmin as $championship): ?><option value="<?= (int) $championship['id'] ?>" <?= ($championship['status'] ?? '') === 'ativo' ? 'selected' : '' ?>><?= e($championship['nome']) ?></option><?php endforeach; ?>
      </select></div>
      <div class="col-lg-2"><label id="round-prompt-stage-label" class="form-label" for="round-prompt-round">Rodada</label><select id="round-prompt-round" class="form-select" disabled><option>Carregando...</option></select></div>
      <div class="col-lg-3"><button id="generate-round-prompt" type="button" class="btn btn-danger w-100" disabled>GERAR DADOS E PROMPT</button></div>
    </div>
    <div id="round-prompt-result" class="mt-3 d-none">
      <div class="round-prompt-summary"><strong id="round-prompt-title"></strong><span id="round-prompt-count"></span></div>
      <textarea id="round-prompt-output" class="form-control" rows="14" readonly></textarea>
      <div class="d-flex align-items-center gap-3 mt-3"><button id="copy-round-prompt" type="button" class="btn btn-outline-light">COPIAR PROMPT</button><span id="round-prompt-status" class="small text-secondary"></span></div>
    </div>
  </div>
  <div class="row g-4">
    <div class="col-xl-8">
      <form id="news-form" class="panel admin-news-form" method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="salvar_noticia">
        <input type="hidden" name="noticia_id" value="">
        <input id="cover-data" type="hidden" name="capa_base64" value="">
        <input id="news-content" type="hidden" name="conteudo">
        <div id="news-editing" class="alert alert-info d-none justify-content-between align-items-center"><span></span><button id="cancel-news-edit" type="button" class="btn btn-sm btn-outline-info">Cancelar edição</button></div>
        <label class="form-label">Título</label><input class="form-control" name="titulo" maxlength="180" required>
        <label class="form-label mt-3">Resumo</label><textarea class="form-control" name="resumo" rows="3" maxlength="500" required></textarea>
        <label class="form-label mt-3">Imagem de capa</label><input id="cover-file" class="form-control" type="file" accept="image/jpeg,image/png,image/webp"><img id="cover-preview" class="news-cover-preview d-none mt-3" alt="Prévia da capa">
        <label class="form-label mt-3">Conteúdo da matéria</label>
        <div class="editor-toolbar"><button type="button" data-command="bold"><strong>N</strong></button><button type="button" data-command="italic"><em>I</em></button><button type="button" data-block="h2">Título</button><button type="button" data-block="p">Texto</button><button type="button" id="insert-image">Inserir imagem</button><input id="body-image" class="d-none" type="file" accept="image/jpeg,image/png,image/webp"></div>
        <div id="news-editor" class="news-editor" contenteditable="true" data-placeholder="Escreva ou cole a matéria e encaixe imagens onde quiser." role="textbox" aria-multiline="true" aria-label="Conteúdo da matéria"></div>
        <p class="small text-secondary mt-2">As imagens são reduzidas e convertidas para WebP antes de serem salvas.</p><button id="news-submit" class="btn btn-danger mt-2">Publicar notícia</button>
      </form>
    </div>
    <div class="col-xl-4"><div class="panel"><div class="panel-head"><h3>Postagens</h3><span><?= count(
        $newsAdmin,
    ) ?> ativas</span></div><div class="admin-news-list">
      <?php foreach ($newsAdmin as $item): ?><article><h3><?= e(
    $item["titulo"],
) ?></h3><small><?= e(
    format_datetime_br($item["publicado_em"]),
) ?> • <?= e(
     $item["autor"],
 ) ?></small><div class="d-flex flex-wrap gap-2 mt-2"><a class="btn btn-sm btn-outline-info" href="../noticia.php?id=<?= (int)$item['id'] ?>" target="_blank" rel="noopener">Ver notícia</a><button type="button" class="btn btn-sm btn-outline-light editar-noticia" data-id="<?= $item[
    "id"
] ?>">Editar</button><form method="post"><input type="hidden" name="csrf" value="<?= e(
    csrf_token(),
) ?>"><input type="hidden" name="action" value="desativar_noticia"><input type="hidden" name="noticia_id" value="<?= $item[
    "id"
] ?>"><button class="btn btn-sm btn-outline-danger">Apagar</button></form></div></article><?php endforeach; ?>
      <?php if (
          !$newsAdmin
      ): ?><div class="empty-state">Nenhuma notícia publicada.</div><?php endif; ?>
    </div></div></div>
  </div>
  <script id="news-admin-data" type="application/json"><?= json_encode(
      $newsAdmin,
      JSON_HEX_TAG |
          JSON_HEX_AMP |
          JSON_HEX_APOS |
          JSON_HEX_QUOT |
          JSON_UNESCAPED_UNICODE |
          JSON_UNESCAPED_SLASHES,
  ) ?></script>
</section>
