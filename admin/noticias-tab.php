<section id="tab-noticias" class="tab-pane fade">
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
        <div id="news-editor" class="news-editor" contenteditable="true"><p>Escreva a notícia aqui...</p></div>
        <p class="small text-secondary mt-2">As imagens são reduzidas e convertidas para WebP antes de serem salvas.</p><button id="news-submit" class="btn btn-danger mt-2">Publicar notícia</button>
      </form>
    </div>
    <div class="col-xl-4"><div class="panel"><div class="panel-head"><h3>Postagens</h3><span><?= count(
        $newsAdmin,
    ) ?> ativas</span></div><div class="admin-news-list">
      <?php foreach ($newsAdmin as $item): ?><article><h3><?= e(
    $item["titulo"],
) ?></h3><small><?= e(
    date("d/m/Y H:i", strtotime($item["publicado_em"])),
) ?> • <?= e(
     $item["autor"],
 ) ?></small><div class="d-flex gap-2 mt-2"><button type="button" class="btn btn-sm btn-outline-light editar-noticia" data-id="<?= $item[
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
