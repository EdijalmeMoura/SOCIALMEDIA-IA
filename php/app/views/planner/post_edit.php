<?php
/**
 * Edição de uma publicação do calendário.
 *
 * A mesma tela mostra três coisas que antes viviam separadas: o texto que vai
 * ser publicado, o limite de caracteres da rede escolhida (que o calendário só
 * cobrava depois de exportar) e a arte que representa o post no Estúdio.
 *
 * @var array<string,mixed> $post
 * @var array<string,mixed> $plano
 * @var array<string,mixed>|null $arte arte vinculada (do mesmo cliente)
 * @var array<int,array<string,mixed>> $artes_do_cliente
 * @var string|null $erro
 * @var string|null $aviso
 * @var bool $pode_criar_arte
 * @var array<string,array{label:string,limite:int,tags:int}> $redes
 * @var array<string,string> $tons
 * @var string $csrf
 * @var \Smia\Support\Config $config
 */
$v = static fn (string $campo): string => (string) ($post[$campo] ?? '');
$inicio = new DateTimeImmutable((string) $plano['start_date']);
$fim = $inicio->modify('+' . ((int) $plano['period_days'] - 1) . ' days');
// Mesma tabela que o estúdio usa no contador da legenda: uma fonte só.
$redeChave = match (mb_strtolower(trim($v('network')))) {
    'instagram' => 'instagram',
    'facebook' => 'facebook',
    'tiktok' => 'tiktok',
    'linkedin' => 'linkedin',
    'whatsapp' => 'whatsapp',
    default => 'generico',
};
$redeInfo = $redes[$redeChave] ?? $redes['generico'];
$legenda = $v('caption');
$tags = $v('hashtags');
$arteUrl = $arte !== null && !empty($arte['export_path'])
    ? $config->url('/studio/posts/' . (int) $arte['id'] . '/download')
    : null;
ob_start();
?>
<div class="form-page-head">
  <a class="back-link" href="<?= e($config->url('/planner/' . $plano['id'])) ?>">← <span>Voltar ao calendário</span></a>
  <div class="eyebrow muted-eyebrow">PUBLICAÇÃO · <?= e(mb_strtoupper($v('network'))) ?></div>
  <h1><?= e($v('theme')) ?></h1>
  <p class="page-subtitle">
    Dentro do planejamento de <?= e((string) ($plano['trade_name'] ?: $plano['company_name'])) ?>
    · <?= e($inicio->format('d/m/Y')) ?> a <?= e($fim->format('d/m/Y')) ?>
  </p>
</div>

<?php if ($erro !== null && $erro !== ''): ?>
  <div class="alert error-alert"><?= e($erro) ?></div>
<?php endif; ?>
<?php if (!empty($aviso)): ?>
  <div class="alert success-alert"><?= e($aviso) ?></div>
<?php endif; ?>

<div class="post-edit-layout">
  <form method="post" action="<?= e($config->url('/planner/posts/' . $post['id'] . '/edit')) ?>" class="client-form post-edit-form">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <section class="form-section">
      <div class="form-section-title">
        <span class="section-number">01</span>
        <div><h2>Quando e onde</h2><p>Data e rede precisam estar dentro do planejamento.</p></div>
      </div>
      <div class="form-grid">
        <label class="field span-6">Data de publicação <em>*</em>
          <input type="date" name="publication_date" required
                 min="<?= e($inicio->format('Y-m-d')) ?>" max="<?= e($fim->format('Y-m-d')) ?>"
                 value="<?= e($v('publication_date')) ?>">
        </label>
        <label class="field span-6">Rede social <em>*</em>
          <select name="network" required data-post-network>
            <?php foreach ($plano['networks'] as $rede): ?>
              <option value="<?= e($rede) ?>" <?= $v('network') === $rede ? 'selected' : '' ?>><?= e($rede) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field span-6">Formato / tipo de conteúdo <em>*</em>
          <input name="content_type" maxlength="5000" required value="<?= e($v('content_type')) ?>" placeholder="Ex.: Carrossel, Reels, Post único">
        </label>
        <label class="field span-12">Tema <em>*</em>
          <input name="theme" maxlength="5000" required value="<?= e($v('theme')) ?>">
        </label>
      </div>
    </section>

    <section class="form-section">
      <div class="form-section-title">
        <span class="section-number">02</span>
        <div><h2>Texto e estratégia</h2><p>O que a marca publica e por quê.</p></div>
      </div>
      <div class="form-grid">
        <label class="field span-12">Objetivo estratégico <em>*</em>
          <textarea name="strategic_objective" rows="2" maxlength="5000" required><?= e($v('strategic_objective')) ?></textarea>
        </label>
        <label class="field span-12">Legenda <em>*</em>
          <?php // Contador com o limite da rede escolhida: o mesmo número do estúdio. ?>
          <textarea name="caption" id="postCaption" rows="5" maxlength="5000" required
                    data-caption-limit="<?= (int) $redeInfo['limite'] ?>"><?= e($legenda) ?></textarea>
          <span class="field-helper">
            <span data-counter-caption data-max="<?= (int) $redeInfo['limite'] ?>"><?= mb_strlen($legenda) ?></span>/<?= (int) $redeInfo['limite'] ?>
            · limite do <?= e($redeInfo['label']) ?>
            <span class="network-note" data-network-note <?= $legenda === '' ? 'hidden' : '' ?>></span>
          </span>
        </label>
        <label class="field span-12">Texto de apoio / roteiro <em>*</em>
          <textarea name="main_text" rows="4" maxlength="5000" required><?= e($v('main_text')) ?></textarea>
        </label>
        <label class="field span-6">Hashtags
          <input name="hashtags" id="postHashtags" maxlength="5000" value="<?= e($tags) ?>" placeholder="#marca #segmento"
                 data-tags-max="<?= (int) $redeInfo['tags'] ?>">
          <span class="field-helper">
            <span data-counter-tags><?= str_word_count($tags) ?></span>/<?= (int) $redeInfo['tags'] ?> hashtags
            · <?= e($redeInfo['label']) ?>
          </span>
        </label>
        <label class="field span-6">Chamada para ação
          <input name="cta" maxlength="5000" value="<?= e($v('cta')) ?>">
        </label>
      </div>
    </section>

    <section class="form-section">
      <div class="form-section-title">
        <span class="section-number">03</span>
        <div><h2>Direção visual</h2><p>O que a equipe de criação precisa saber para produzir a arte.</p></div>
      </div>
      <div class="form-grid">
        <label class="field span-12">Briefing visual <em>*</em>
          <textarea name="visual_brief" rows="3" maxlength="5000" required><?= e($v('visual_brief')) ?></textarea>
        </label>
        <label class="field span-12">Sugestão de imagem <em>*</em>
          <textarea name="image_suggestion" rows="3" maxlength="5000" required><?= e($v('image_suggestion')) ?></textarea>
        </label>
      </div>
    </section>

    <div class="form-actions sticky-actions">
      <span>Atualizado em <?= e(mb_substr($v('updated_at'), 0, 16)) ?> UTC</span>
      <a class="button outline" href="<?= e($config->url('/planner/' . $plano['id'])) ?>">Cancelar</a>
      <button class="button primary" type="submit">Salvar publicação <span>✓</span></button>
    </div>
  </form>

  <aside class="post-edit-side">
    <section class="panel post-art-panel">
      <div class="control-heading">
        <div><h2>Arte desta publicação</h2><p>A imagem que acompanha o texto.</p></div>
        <span class="post-art-icon">▧</span>
      </div>

      <?php if ($arte !== null): ?>
        <div class="post-art-preview">
          <?php if ($arteUrl !== null): ?>
            <img src="<?= e($arteUrl) ?>" alt="Arte exportada desta publicação">
          <?php else: ?>
            <?php // Sem exportação ainda: o retângulo assume a cor da arte. ?>
            <div class="post-art-placeholder" style="--post-art-color: <?= e((string) $arte['background_color']) ?>">
              <span><?= e((string) ($arte['subject'] ?? '')) ?></span>
              <small>ainda não exportada</small>
            </div>
          <?php endif; ?>
        </div>
        <dl class="post-art-meta">
          <dt>Formato</dt>
          <dd><?= e((string) ($formatos[(string) $arte['format_key']]['label'] ?? $arte['format_key'])) ?>
            · <?= (int) $arte['canvas_width'] ?> × <?= (int) $arte['canvas_height'] ?></dd>
          <dt>Estado</dt>
          <dd><?= $arte['status'] === 'exported' ? 'Exportada' : 'Em edição' ?></dd>
        </dl>
        <a class="button primary full-button" href="<?= e($config->url('/studio/posts/' . (int) $arte['id'])) ?>">Abrir no Estúdio →</a>
        <p class="field-helper">Editar no Estúdio não muda o texto desta publicação — são coisas separadas de propósito.</p>
      <?php elseif ($pode_criar_arte): ?>
        <div class="post-art-empty">
          <span>✦</span>
          <b>Nenhuma arte ligada</b>
          <small>O texto desta publicação vira título, legenda e briefing da arte.</small>
        </div>
        <form method="post" action="<?= e($config->url('/planner/posts/' . $post['id'] . '/arte')) ?>" class="post-art-create">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <label class="field">Formato da arte
            <select name="format_key">
              <?php foreach ($formatos as $chave => $info): ?>
                <option value="<?= e((string) $chave) ?>" <?= $chave === 'square' ? 'selected' : '' ?>>
                  <?= e((string) $info['label']) ?> · <?= (int) $info['width'] ?> × <?= (int) $info['height'] ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="button primary full-button" type="submit">✦ Criar arte desta publicação</button>
        </form>

        <?php if ($artes_do_cliente !== []): ?>
          <details class="post-art-link">
            <summary>Ou usar uma arte que já existe</summary>
            <form method="post" action="<?= e($config->url('/planner/posts/' . $post['id'] . '/arte/vincular')) ?>">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <label class="field">Arte deste cliente
                <select name="creative_id" required>
                  <?php foreach (array_slice($artes_do_cliente, 0, 20) as $opcao): ?>
                    <option value="<?= (int) $opcao['id'] ?>">
                      <?= e(mb_substr((string) $opcao['subject'], 0, 60)) ?>
                      · <?= e((string) $opcao['created_at']) ?>
                      <?= $opcao['status'] === 'exported' ? '· exportada' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button class="button outline full-button" type="submit">Vincular esta arte</button>
            </form>
          </details>
        <?php endif; ?>
      <?php else: ?>
        <p class="field-helper">O Estúdio não está disponível nesta instalação.</p>
      <?php endif; ?>
    </section>

    <section class="panel post-preview-panel">
      <div class="control-heading">
        <div><h2>Prévia da publicação</h2><p>Como o texto aparece no <?= e($redeInfo['label']) ?>.</p></div>
      </div>
      <?php // Prévia viva: o JS atualiza ao digitar. Sem JS, mostra o que foi salvo. ?>
      <article class="social-preview" data-social-preview>
        <header class="social-preview-head">
          <span class="social-avatar"><?= e(mb_strtoupper(mb_substr((string) ($plano['trade_name'] ?: $plano['company_name']), 0, 1))) ?></span>
          <div>
            <b><?= e((string) ($plano['trade_name'] ?: $plano['company_name'])) ?></b>
            <small><?= e($v('network')) ?> · <?= e($v('publication_date')) ?></small>
          </div>
        </header>
        <p class="social-preview-caption" data-preview-caption><?= nl2br(e($legenda)) ?></p>
        <p class="social-preview-tags" data-preview-tags><?= e($tags) ?></p>
        <?php // A CTA some quando vazia: o JS a esvazia junto com o campo. ?>
        <p class="social-preview-cta" data-preview-cta <?= $v('cta') === '' ? 'hidden' : '' ?>><?= e($v('cta')) ?></p>
      </article>
    </section>
  </aside>
</div>
<script src="<?= e(smia_asset('/assets/planner.js')) ?>" defer></script>
<?php
$content = ob_get_clean();
$title = 'Editar publicação';
require __DIR__ . '/../layouts/app.php';
