<?php
/**
 * Feed: perfil + grade 3×3 + fontes (artes e pautas fora da grade).
 *
 * A grade mostra o que o seguidor veria; o rodapé deixa explícito que nada é
 * agendado aqui — é prévia interna.
 *
 * @var array<int,array<string,mixed>> $clientes
 * @var array<string,mixed>|null $selecionado
 * @var array<string,mixed> $marca
 * @var array<int,array<string,mixed>> $itens
 * @var array<int,array<string,mixed>> $artes
 * @var array<int,array<string,mixed>> $pautas
 * @var bool $pode_editar
 * @var string|null $aviso
 * @var string|null $erro
 * @var string $base_url prefixo público (o fetch precisa dele na subpasta)
 */
$nome = static fn (array $linha): string => trim((string) ($linha['trade_name'] ?? '')) ?: (string) $linha['company_name'];
$cor = (string) ($marca['primary_color'] ?? '#3158d8');
$logo = trim((string) ($marca['logo_path'] ?? ''));
$totalFontes = count($artes) + count($pautas);
ob_start();
?>
<div class="page-head feed-head">
  <div>
    <div class="eyebrow">VISUALIZAÇÃO</div>
    <h1>Feed</h1>
    <p class="page-sub">Monte a sequência como o seguidor vê: arraste para reordenar e confira o texto de cada publicação.</p>
  </div>
  <?php if ($clientes !== []): ?>
    <div class="page-actions">
      <form method="get" action="<?= e($config->url('/feed')) ?>" class="feed-client-select">
        <label for="feedClient">Marca</label>
        <select name="client_id" id="feedClient" onchange="this.form.submit()">
          <?php foreach ($clientes as $cliente): ?>
            <option value="<?= (int) $cliente['id'] ?>" <?= $selecionado !== null && (int) $selecionado['id'] === (int) $cliente['id'] ? 'selected' : '' ?>>
              <?= e($nome($cliente)) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="button outline tiny" type="submit">Trocar</button></noscript>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php if (!empty($aviso)): ?>
  <div class="alert success-alert"><?= e($aviso) ?></div>
<?php endif; ?>
<?php if (!empty($erro)): ?>
  <div class="alert error-alert"><?= e($erro) ?></div>
<?php endif; ?>

<?php if ($clientes === []): ?>
  <div class="panel empty-state">
    <div class="empty-illustration"><div class="empty-circle">▦</div></div>
    <div class="eyebrow muted-eyebrow">SEM MARCA</div>
    <h2>Cadastre um cliente para visualizar o feed</h2>
    <p>A prévia usa as artes do estúdio e as pautas do planejamento de cada marca.</p>
    <a class="button primary" href="<?= e($config->url('/clients/new')) ?>">Cadastrar cliente</a>
  </div>
<?php else: ?>
  <section class="feed-profile">
    <div class="ig-avatar" style="--brand-color: <?= e($cor) ?>">
      <?php if ($logo !== ''): ?>
        <img src="<?= e($config->url('/clients/file?file=' . rawurlencode($logo))) ?>" alt="">
      <?php else: ?>
        <span><?= e(mb_strtoupper(mb_substr($nome($selecionado), 0, 1))) ?></span>
      <?php endif; ?>
    </div>
    <div class="ig-identity">
      <b><?= e($nome($selecionado)) ?></b>
      <span><?= e(trim((string) ($selecionado['instagram'] ?? '')) !== '' ? (string) $selecionado['instagram'] : 'Instagram não informado') ?></span>
      <small><?= e(trim((string) ($selecionado['segment'] ?? '')) !== '' ? (string) $selecionado['segment'] : 'Marca') ?></small>
    </div>
    <div class="ig-stats">
      <div><b><?= count($itens) ?></b><span>na prévia</span></div>
      <div><b><?= $totalFontes ?></b><span>para organizar</span></div>
      <div><b>3 × 3</b><span>visualização</span></div>
    </div>
    <span class="feed-private"><i></i> Prévia privada</span>
  </section>

  <div class="feed-layout" data-feed-client="<?= (int) $selecionado['id'] ?>"
       data-base-url="<?= e((string) ($base_url ?? '')) ?>"
       data-can-edit="<?= $pode_editar ? 'true' : 'false' ?>">
    <input type="hidden" class="feed-csrf" value="<?= e($csrf) ?>">

    <section class="feed-main">
      <div class="feed-grid-head">
        <div>
          <h2>Grade do Instagram</h2>
          <p><?= $pode_editar ? 'Arraste para mudar a sequência. Só grava quando você confirmar.' : 'Sequência atual das publicações desta marca.' ?></p>
        </div>
        <span class="feed-count"><?= count($itens) ?> item(ns)</span>
      </div>

      <div class="feed-grid" id="grade">
        <?php foreach ($itens as $indice => $item): ?>
          <?php $midia = $item['media_path'] !== null ? $config->url('/studio/files?file=' . rawurlencode((string) $item['media_path'])) : null; ?>
          <article class="feed-tile <?= !empty($item['missing']) ? 'is-missing' : '' ?>"
                   data-feed-id="<?= (int) $item['feed_id'] ?>"
                   data-title="<?= e((string) $item['title']) ?>"
                   data-caption="<?= e(trim((string) $item['caption'] . ' ' . (string) $item['hashtags'])) ?>"
                   data-network="<?= e((string) $item['network']) ?>"
                   data-status="<?= e((string) $item['status_label']) ?>"
                   data-date="<?= e((string) ($item['publish_date'] ?? '')) ?>"
                   data-brief="<?= e((string) ($item['visual_brief'] ?? '')) ?>"
                   draggable="<?= $pode_editar ? 'true' : 'false' ?>">
            <button class="feed-tile-preview" type="button" aria-label="Abrir detalhes de <?= e((string) $item['title']) ?>">
              <?php if ($item['item_type'] === 'creative' && $midia !== null): ?>
                <img class="feed-art" src="<?= e($midia) ?>" alt="" loading="lazy">
                <?php if ($item['is_exported']): ?>
                  <span class="tile-badge exported">ARTE EXPORTADA</span>
                <?php else: ?>
                  <span class="feed-art-copy"><b><?= e((string) $item['title']) ?></b><small><?= e((string) $item['status_label']) ?></small></span>
                  <span class="tile-badge draft">RASCUNHO</span>
                <?php endif; ?>
              <?php elseif ($item['item_type'] === 'creative'): ?>
                <span class="feed-art-placeholder" style="--brand-color: <?= e($cor) ?>">
                  <i>✦</i><b><?= e((string) $item['title']) ?></b><small><?= e((string) $item['status_label']) ?></small>
                </span>
              <?php else: ?>
                <span class="feed-text-placeholder">
                  <span class="placeholder-network"><?= e((string) $item['network']) ?></span>
                  <b><?= e((string) $item['title']) ?></b>
                  <small><?= e((string) ($item['content_type'] ?? '')) ?> · <?= e((string) $item['status_label']) ?></small>
                  <em><?= e(mb_substr((string) ($item['visual_brief'] ?? ''), 0, 140)) ?></em>
                </span>
              <?php endif; ?>
              <span class="tile-order"><?= $indice + 1 ?></span>
            </button>

            <?php if ($pode_editar): ?>
              <form method="post" action="<?= e($config->url('/feed/' . (int) $selecionado['id'] . '/remove/' . (int) $item['feed_id'])) ?>"
                    data-confirm="Remover este item da prévia? O conteúdo original não é excluído.">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="tile-remove" type="submit" title="Remover da prévia" aria-label="Remover da prévia">×</button>
              </form>
            <?php endif; ?>

            <div class="tile-meta">
              <span><?= e((string) $item['network']) ?><?= !empty($item['publish_date']) ? ' · ' . e((string) $item['publish_date']) : '' ?></span>
              <i class="<?= $item['is_exported'] ? 'exported' : 'draft' ?>"></i>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($itens === []): ?>
        <div class="panel empty-state feed-empty">
          <div class="empty-circle small">▦</div>
          <h2>A grade está vazia</h2>
          <p>Adicione artes do estúdio e pautas do planejamento na coluna ao lado para montar a sequência.</p>
        </div>
      <?php endif; ?>

      <div class="feed-order-bar" id="feedOrderBar" hidden>
        <span>↕ Nova ordem pronta para salvar</span>
        <button class="button primary tiny" type="button" id="feedSaveOrder">Salvar ordem</button>
        <button class="feed-cancel" type="button" id="feedCancelOrder">Cancelar</button>
        <small id="feedSaveMessage" role="status"></small>
      </div>

      <p class="feed-footnote"><span>ⓘ</span> A grade é uma prévia interna: datas editoriais são recomendações e nada aqui é
        agendado ou publicado automaticamente.</p>
    </section>

    <aside class="feed-sources">
      <section class="panel source-panel">
        <div class="source-head">
          <div>
            <span class="source-icon art">✦</span>
            <div><h3>Artes do estúdio</h3><p>Criações desta marca fora da grade.</p></div>
          </div>
          <a href="<?= e($config->url('/studio')) ?>">Estúdio →</a>
        </div>
        <?php foreach ($artes as $arte): ?>
          <div class="source-row">
            <span class="source-thumb"><?= e(mb_substr((string) $arte['subject'], 0, 1)) ?></span>
            <div class="source-info">
              <b><?= e((string) $arte['subject']) ?></b>
              <small><?= e(\Smia\Studio\Layout::rotulo((string) $arte['format_key'])) ?> · <?= (int) $arte['exported'] === 1 ? 'exportada' : 'rascunho' ?></small>
            </div>
            <?php if ($pode_editar): ?>
              <form method="post" action="<?= e($config->url('/feed/' . (int) $selecionado['id'] . '/add/creative/' . (int) $arte['id'])) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button type="submit" title="Adicionar à prévia" aria-label="Adicionar <?= e((string) $arte['subject']) ?> à prévia">＋</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($artes === []): ?>
          <div class="source-empty">Nenhuma arte fora da grade. <a href="<?= e($config->url('/studio')) ?>">Criar arte</a></div>
        <?php endif; ?>
      </section>

      <section class="panel source-panel">
        <div class="source-head">
          <div>
            <span class="source-icon idea">▦</span>
            <div><h3>Pautas planejadas</h3><p>Posts ainda sem arte final.</p></div>
          </div>
          <a href="<?= e($config->url('/planner')) ?>">Planejamento →</a>
        </div>
        <?php foreach ($pautas as $pauta): ?>
          <div class="source-row">
            <span class="source-thumb idea"><?= e(mb_strtoupper(mb_substr((string) $pauta['network'], 0, 1))) ?></span>
            <div class="source-info">
              <b><?= e((string) $pauta['theme']) ?></b>
              <small><?= e((string) $pauta['network']) ?> · <?= e(date('d/m/Y', (int) strtotime((string) $pauta['publication_date']))) ?></small>
            </div>
            <?php if ($pode_editar): ?>
              <form method="post" action="<?= e($config->url('/feed/' . (int) $selecionado['id'] . '/add/post/' . (int) $pauta['id'])) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button type="submit" title="Adicionar à prévia" aria-label="Adicionar <?= e((string) $pauta['theme']) ?> à prévia">＋</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($pautas === []): ?>
          <div class="source-empty">Nenhuma pauta fora da grade. <a href="<?= e($config->url('/planner')) ?>">Planejar posts</a></div>
        <?php endif; ?>
      </section>
    </aside>
  </div>

  <dialog class="feed-modal" id="feedPreview">
    <button class="modal-close" id="feedPreviewClose" type="button" aria-label="Fechar">×</button>
    <div class="modal-network" id="feedModalNetwork"></div>
    <h2 id="feedModalTitle"></h2>
    <p class="modal-date" id="feedModalDate"></p>
    <p class="modal-caption" id="feedModalCaption"></p>
    <div class="modal-status" id="feedModalStatus"></div>
    <div class="modal-brief" id="feedModalBrief"></div>
  </dialog>

  <script src="<?= e(smia_asset('/assets/feed.js')) ?>" defer></script>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Feed';
require __DIR__ . '/../layouts/app.php';
