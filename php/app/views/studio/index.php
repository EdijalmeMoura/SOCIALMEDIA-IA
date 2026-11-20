<?php
/**
 * Estúdio: formulário de criação + galeria de artes.
 *
 * @var array<int,array<string,mixed>> $clientes
 * @var array<int,array<string,mixed>> $artes
 * @var array<string,array<string,mixed>> $formatos
 * @var array<string,array{label:string,limite:int,tags:int}> $redes
 * @var array<string,string> $tons
 * @var array{client_id:int,format_key:string,status:string} $filtros
 * @var bool $filtros_ativos
 * @var bool $ia_configurada
 * @var bool $pode_editar
 * @var array<string,mixed> $form
 * @var string|null $erro
 * @var string|null $aviso
 */
$valor = static fn (string $campo, string $padrao = ''): string => (string) ($form[$campo] ?? $padrao);
$redeAtual = $valor('network', 'instagram');
$redeAtual = isset($redes[$redeAtual]) ? $redeAtual : 'instagram';
$tomAtual = $valor('tone', 'marca');
$tomAtual = isset($tons[$tomAtual]) ? $tomAtual : 'marca';
$formatoAtual = $valor('format_key', 'square');
$formatoAtual = isset($formatos[$formatoAtual]) ? $formatoAtual : 'square';
ob_start();
?>
<div class="page-head">
  <div>
    <div class="eyebrow">CRIAÇÃO</div>
    <h1>Estúdio</h1>
    <p class="page-sub">Um assunto vira arte pronta: texto, imagem de fundo, ajuste fino e exportação.</p>
  </div>
  <div class="page-actions">
    <span class="badge <?= $ia_configurada ? 'badge-live' : '' ?>">
      <?= $ia_configurada ? 'IA configurada' : 'Sem chave de IA' ?>
    </span>
  </div>
</div>

<?php if (!empty($aviso)): ?>
  <div class="alert success-alert"><?= e($aviso) ?></div>
<?php endif; ?>
<?php if (!empty($erro)): ?>
  <div class="alert error-alert"><?= e($erro) ?></div>
<?php endif; ?>

<?php if (!$ia_configurada): ?>
  <div class="alert setup-alert">
    <b>Sem chave de IA configurada</b>
    <p>O estúdio cria um <b>rascunho local</b> para você escrever por cima — nunca texto de IA
      apresentado como se fosse gerado. Para gerar textos e imagens de verdade, acrescente
      <code>OPENAI_API_KEY</code> no <code>.env</code>. Enviar sua própria imagem já funciona.</p>
  </div>
<?php endif; ?>

<?php if ($pode_editar): ?>
<section class="panel studio-create">
  <form method="post" action="<?= e($config->url('/studio/generate')) ?>" class="studio-create-form" id="studioCreateForm">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <div class="create-layout">
      <div class="create-main">
        <div class="create-step">
          <span class="create-step-number">1</span>
          <div class="create-step-body">
            <b class="create-step-title">Para quem é esta arte</b>
            <div class="form-grid">
              <label class="field span-6">Cliente <em>*</em>
                <select name="client_id" id="createClient" required>
                  <option value="">Selecione um cliente</option>
                  <?php foreach ($clientes as $cliente): ?>
                    <option value="<?= (int) $cliente['id'] ?>"
                            data-cor="<?= e((string) ($cliente['primary_color'] ?? '')) ?>"
                            data-marca="<?= e(trim((string) ($cliente['trade_name'] ?? '')) ?: (string) $cliente['company_name']) ?>"
                      <?= $valor('client_id') === (string) $cliente['id'] ? 'selected' : '' ?>>
                      <?= e(trim((string) ($cliente['trade_name'] ?? '')) ?: (string) $cliente['company_name']) ?>
                      <?= $cliente['segment'] ? ' · ' . e((string) $cliente['segment']) : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="field-helper">O estúdio usa a identidade visual e o tom cadastrados no cliente.</span>
              </label>

              <label class="field span-6">Assunto da arte <em>*</em>
                <input name="subject" id="createSubject" required minlength="3" maxlength="300"
                       placeholder="Ex.: Aula experimental gratuita em março"
                       value="<?= e($valor('subject')) ?>">
                <span class="field-helper">Uma frase sobre o que a publicação precisa dizer.</span>
              </label>
            </div>
          </div>
        </div>

        <div class="create-step">
          <span class="create-step-number">2</span>
          <div class="create-step-body">
            <b class="create-step-title">Onde vai publicar</b>
            <div class="network-row" role="radiogroup" aria-label="Rede social">
              <?php foreach ($redes as $chave => $rede): ?>
                <label class="network-pill">
                  <input type="radio" name="network" value="<?= e($chave) ?>" <?= $redeAtual === $chave ? 'checked' : '' ?>>
                  <span><?= e($rede['label']) ?></span>
                  <small>até <?= (int) $rede['limite'] ?> caracteres</small>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="create-note">O limite da legenda e a quantidade de hashtags seguem a rede escolhida.</p>
          </div>
        </div>

        <div class="create-step">
          <span class="create-step-number">3</span>
          <div class="create-step-body">
            <b class="create-step-title">Formato</b>
            <div class="format-grid">
              <?php foreach ($formatos as $chave => $formato): ?>
                <label class="format-card">
                  <input type="radio" name="format_key" value="<?= e($chave) ?>"
                    <?= $formatoAtual === $chave ? 'checked' : '' ?> required>
                  <span class="format-preview" style="aspect-ratio: <?= (int) $formato['width'] ?> / <?= (int) $formato['height'] ?>"></span>
                  <b><?= e((string) $formato['label']) ?></b>
                  <small><?= (int) $formato['width'] ?> × <?= (int) $formato['height'] ?> px</small>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="create-step">
          <span class="create-step-number">4</span>
          <div class="create-step-body">
            <b class="create-step-title">Como falar</b>
            <div class="form-grid">
              <label class="field span-6">Objetivo
                <input name="objective" maxlength="300" placeholder="Ex.: encher a primeira turma de março"
                       value="<?= e($valor('objective')) ?>">
              </label>
              <label class="field span-6">Tom
                <select name="tone">
                  <?php foreach ($tons as $chave => $rotuloTom): ?>
                    <option value="<?= e($chave) ?>" <?= $tomAtual === $chave ? 'selected' : '' ?>><?= e($rotuloTom) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="field span-12">Não pode faltar
                <input name="reference" maxlength="600" placeholder="Ex.: desconto de 30% para quem trouxer um amigo"
                       value="<?= e($valor('reference')) ?>">
                <span class="field-helper">Informação obrigatória (preço, data, endereço). O estúdio não inventa esses dados — se não estiver aqui, não aparece.</span>
              </label>
            </div>
          </div>
        </div>

        <div class="create-step">
          <span class="create-step-number">5</span>
          <div class="create-step-body">
            <b class="create-step-title">Imagem de fundo</b>
            <div class="image-choice">
              <label class="image-choice-card">
                <input type="radio" name="with_image" value="depois" <?= $valor('with_image', 'depois') === 'agora' ? '' : 'checked' ?>>
                <b>Depois, no editor</b>
                <small>Você vê o texto primeiro e escolhe a imagem com calma.</small>
              </label>
              <label class="image-choice-card <?= $ia_configurada ? '' : 'is-disabled' ?>">
                <input type="radio" name="with_image" value="agora" <?= $valor('with_image') === 'agora' ? 'checked' : '' ?>
                  <?= $ia_configurada ? '' : 'disabled' ?>>
                <b>Gerar agora com IA</b>
                <small><?= $ia_configurada ? 'A arte chega pronta, com fundo e texto.' : 'Precisa da chave de IA nos Configurações.' ?></small>
              </label>
            </div>
          </div>
        </div>

        <div class="form-actions create-actions">
          <button class="button primary" type="submit" data-create-submit>✦ Criar arte</button>
          <span class="form-hint" id="createHint" aria-live="polite">
            <?= $ia_configurada ? 'A IA escreve título, legenda, CTA e briefing com base na marca.' : 'Sem chave de IA, o estúdio monta um rascunho local para você escrever por cima.' ?>
          </span>
        </div>
      </div>

      <aside class="create-preview" aria-label="Pré-visualização da arte">
        <span class="field-kicker">PRÉ-VISUALIZAÇÃO</span>
        <div class="preview-frame" id="createPreviewFrame" style="aspect-ratio: <?= (int) $formatos[$formatoAtual]['width'] ?> / <?= (int) $formatos[$formatoAtual]['height'] ?>">
          <span class="preview-title" id="createPreviewTitle">Seu título aparece aqui</span>
          <span class="preview-cta" id="createPreviewCta">Saiba mais</span>
          <span class="preview-brand" id="createPreviewBrand">Marca</span>
        </div>
        <p class="preview-caption" id="createPreviewCaption">
          O título, a chamada e a cor vêm do cliente escolhido. O resto é escrito pela IA no editor.
        </p>
        <ul class="preview-facts">
          <li><span>Rede</span><b data-preview="rede"><?= e($redes[$redeAtual]['label']) ?></b></li>
          <li><span>Formato</span><b data-preview="formato"><?= e((string) $formatos[$formatoAtual]['label']) ?></b></li>
          <li><span>Imagem</span><b data-preview="imagem"><?= $valor('with_image') === 'agora' ? 'gerada na criação' : 'no editor' ?></b></li>
        </ul>
      </aside>
    </div>
  </form>
</section>
<?php else: ?>
  <div class="alert info-alert">Seu perfil pode visualizar as artes, mas não criar novas.</div>
<?php endif; ?>

<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Criações</h2>
      <p><?= count($artes) ?> <?= count($artes) === 1 ? 'arte' : 'artes' ?><?= $filtros_ativos ? ' no filtro' : ' neste workspace' ?></p>
    </div>
    <form method="get" action="<?= e($config->url('/studio')) ?>" class="gallery-filters">
      <label>Cliente
        <select name="cliente" onchange="this.form.requestSubmit()">
          <option value="0">Todos</option>
          <?php foreach ($clientes as $cliente): ?>
            <option value="<?= (int) $cliente['id'] ?>" <?= (int) ($filtros['client_id'] ?? 0) === (int) $cliente['id'] ? 'selected' : '' ?>>
              <?= e(trim((string) ($cliente['trade_name'] ?? '')) ?: (string) $cliente['company_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Formato
        <select name="formato" onchange="this.form.requestSubmit()">
          <option value="">Todos</option>
          <?php foreach ($formatos as $chave => $formato): ?>
            <option value="<?= e($chave) ?>" <?= (string) ($filtros['format_key'] ?? '') === $chave ? 'selected' : '' ?>><?= e((string) $formato['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Status
        <select name="status" onchange="this.form.requestSubmit()">
          <option value="">Todos</option>
          <option value="draft" <?= (string) ($filtros['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Rascunho</option>
          <option value="exported" <?= (string) ($filtros['status'] ?? '') === 'exported' ? 'selected' : '' ?>>Exportada</option>
        </select>
      </label>
      <?php if ($filtros_ativos): ?>
        <a class="gallery-clear" href="<?= e($config->url('/studio')) ?>">limpar</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($artes === []): ?>
    <div class="empty-state compact">
      <div class="empty-circle">✦</div>
      <h2><?= $filtros_ativos ? 'Nenhuma arte neste filtro' : 'Nenhuma arte ainda' ?></h2>
      <p><?= $filtros_ativos
            ? 'Troque o filtro ou limpe para ver todas as criações.'
            : 'Escolha um cliente, escreva o assunto e o estúdio monta a primeira versão.' ?></p>
    </div>
  <?php else: ?>
    <div class="creative-grid">
      <?php foreach ($artes as $arte): ?>
        <?php $conteudo = json_decode((string) $arte['content_json'], true) ?: []; ?>
        <article class="creative-card">
          <a class="creative-thumb"
             href="<?= e($config->url('/studio/posts/' . (int) $arte['id'])) ?>"
             style="aspect-ratio: <?= (int) $arte['canvas_width'] ?> / <?= (int) $arte['canvas_height'] ?>; background: <?= e((string) $arte['background_color']) ?>">
            <?php if ($arte['image_path']): ?>
              <img src="<?= e($config->url('/studio/files?file=' . rawurlencode((string) $arte['image_path']))) ?>" alt="" loading="lazy">
            <?php else: ?>
              <span class="creative-thumb-text"><?= e(mb_substr((string) ($conteudo['title'] ?? $arte['subject']), 0, 48)) ?></span>
            <?php endif; ?>
            <?php if ($arte['status'] === 'exported'): ?><span class="creative-flag">exportada</span><?php endif; ?>
          </a>
          <div class="creative-meta">
            <a class="creative-title" href="<?= e($config->url('/studio/posts/' . (int) $arte['id'])) ?>"><?= e((string) $arte['subject']) ?></a>
            <small><?= e(trim((string) ($arte['trade_name'] ?? '')) ?: (string) $arte['company_name']) ?>
              · <?= e(\Smia\Studio\Layout::rotulo((string) $arte['format_key'])) ?></small>
            <small class="muted">
              <?= (int) $arte['version_count'] ?> <?= (int) $arte['version_count'] === 1 ? 'versão' : 'versões' ?>
              · <?= e(substr((string) $arte['created_at'], 0, 10)) ?>
            </small>
          </div>
          <div class="creative-actions">
            <a class="quick-action" href="<?= e($config->url('/studio/posts/' . (int) $arte['id'])) ?>">✎ Editar</a>
            <?php if ($pode_editar): ?>
              <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/duplicate')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="quick-action" type="submit" title="Criar uma cópia independente">⧉ Duplicar</button>
              </form>
            <?php endif; ?>
            <?php if ($arte['export_path']): ?>
              <a class="quick-action" href="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/download')) ?>">↓ Baixar</a>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<script src="<?= e(smia_asset('/assets/studio-create.js')) ?>" defer></script>
<?php
$content = ob_get_clean();
$title = 'Estúdio';
require __DIR__ . '/../layouts/app.php';
