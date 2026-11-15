<?php
/**
 * Estúdio: formulário de criação + galeria de artes.
 *
 * @var array<int,array<string,mixed>> $clientes
 * @var array<int,array<string,mixed>> $artes
 * @var array<string,array<string,mixed>> $formatos
 * @var bool $ia_configurada
 * @var bool $pode_editar
 * @var array<string,mixed> $form
 * @var string|null $erro
 */
$valor = static fn (string $campo, string $padrao = ''): string => (string) ($form[$campo] ?? $padrao);
$redesSelecionadas = [];
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
  <form method="post" action="<?= e($config->url('/studio/generate')) ?>" class="studio-create-form">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <div class="form-grid">
      <label class="field span-6">Cliente <em>*</em>
        <select name="client_id" required>
          <option value="">Selecione um cliente</option>
          <?php foreach ($clientes as $cliente): ?>
            <option value="<?= (int) $cliente['id'] ?>" <?= $valor('client_id') === (string) $cliente['id'] ? 'selected' : '' ?>>
              <?= e(trim((string) ($cliente['trade_name'] ?? '')) ?: (string) $cliente['company_name']) ?>
              <?= $cliente['segment'] ? ' · ' . e((string) $cliente['segment']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="field span-6">Assunto da arte <em>*</em>
        <input name="subject" required minlength="3" maxlength="300"
               placeholder="Ex.: Aula experimental gratuita em março"
               value="<?= e($valor('subject')) ?>">
      </label>
    </div>

    <div class="field-group">
      <span class="field-label">Formato <em>*</em></span>
      <div class="format-grid">
        <?php foreach ($formatos as $chave => $formato): ?>
          <label class="format-card">
            <input type="radio" name="format_key" value="<?= e($chave) ?>"
              <?= $valor('format_key', 'square') === $chave ? 'checked' : '' ?> required>
            <span class="format-preview" style="aspect-ratio: <?= (int) $formato['width'] ?> / <?= (int) $formato['height'] ?>"></span>
            <b><?= e((string) $formato['label']) ?></b>
            <small><?= (int) $formato['width'] ?> × <?= (int) $formato['height'] ?> px</small>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-actions">
      <button class="button primary" type="submit">✦ Criar arte</button>
      <span class="form-hint">A IA escreve título, legenda, CTA e briefing com base na marca do cliente.</span>
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
      <p><?= count($artes) ?> <?= count($artes) === 1 ? 'arte' : 'artes' ?> neste workspace</p>
    </div>
  </div>

  <?php if ($artes === []): ?>
    <div class="empty-state compact">
      <div class="empty-circle">✦</div>
      <h2>Nenhuma arte ainda</h2>
      <p>Escolha um cliente, escreva o assunto e o estúdio monta a primeira versão.</p>
    </div>
  <?php else: ?>
    <div class="creative-grid">
      <?php foreach ($artes as $arte): ?>
        <?php $conteudo = json_decode((string) $arte['content_json'], true) ?: []; ?>
        <a class="creative-card" href="<?= e($config->url('/studio/posts/' . (int) $arte['id'])) ?>">
          <div class="creative-thumb"
               style="aspect-ratio: <?= (int) $arte['canvas_width'] ?> / <?= (int) $arte['canvas_height'] ?>; background: <?= e((string) $arte['background_color']) ?>">
            <?php if ($arte['image_path']): ?>
              <img src="<?= e($config->url('/studio/files?file=' . rawurlencode((string) $arte['image_path']))) ?>" alt="" loading="lazy">
            <?php else: ?>
              <span class="creative-thumb-text"><?= e(mb_substr((string) ($conteudo['title'] ?? $arte['subject']), 0, 48)) ?></span>
            <?php endif; ?>
            <?php if ($arte['status'] === 'exported'): ?><span class="creative-flag">exportada</span><?php endif; ?>
          </div>
          <div class="creative-meta">
            <b><?= e((string) $arte['subject']) ?></b>
            <small><?= e(trim((string) ($arte['trade_name'] ?? '')) ?: (string) $arte['company_name']) ?>
              · <?= e(\Smia\Studio\Layout::rotulo((string) $arte['format_key'])) ?></small>
            <small class="muted">
              <?= (int) $arte['version_count'] ?> <?= (int) $arte['version_count'] === 1 ? 'versão' : 'versões' ?>
              · <?= e(substr((string) $arte['created_at'], 0, 10)) ?>
            </small>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = 'Estúdio';
require __DIR__ . '/../layouts/app.php';
