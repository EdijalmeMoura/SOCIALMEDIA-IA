<?php
/**
 * Editor de arte: prancheta à esquerda, controles à direita.
 *
 * O desenho acontece no navegador (canvas). O servidor manda o layout como
 * JSON e recebe de volta o PNG/JPG renderizado na exportação — nada de
 * biblioteca gráfica no PHP, então funciona em qualquer cPanel.
 *
 * @var array<string,mixed> $arte
 * @var array<string,mixed> $conteudo
 * @var array{elements:list<array<string,mixed>>} $layout
 * @var array<string,array<string,mixed>> $formatos
 * @var array<string,string> $rotulos
 * @var array<int,array<string,mixed>> $versoes
 * @var bool $ia_configurada
 * @var bool $pode_editar
 * @var string|null $imagem_url
 * @var string|null $logo_url
 * @var string|null $aviso
 */
$formato = $formatos[(string) $arte['format_key']] ?? ['label' => $arte['format_key'], 'width' => $arte['canvas_width'], 'height' => $arte['canvas_height']];
$erroQuery = $config->url('/studio/posts/' . (int) $arte['id']);
ob_start();
?>
<div class="studio-head">
  <div>
    <a class="back-link" href="<?= e($config->url('/studio')) ?>">← <span>Voltar ao estúdio</span></a>
    <div class="eyebrow muted-eyebrow">
      <?= e(trim((string) ($arte['trade_name'] ?? '')) ?: (string) $arte['company_name']) ?>
      · <?= e((string) $formato['label']) ?> · <?= (int) $arte['canvas_width'] ?> × <?= (int) $arte['canvas_height'] ?>
    </div>
    <h1><?= e((string) $arte['subject']) ?></h1>
  </div>
  <div class="studio-head-actions">
    <?php if ($pode_editar): ?>
      <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/variation')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="variation_note" value="Mantenha a mesma estratégia, com uma abordagem visual e textual alternativa.">
        <button class="button outline" type="submit">✦ Gerar outra versão</button>
      </form>
      <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/delete')) ?>"
            data-confirm="Excluir esta criação e todas as versões salvas?">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="button danger-outline" type="submit">Excluir criação</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($aviso)): ?>
  <div class="alert success-alert"><?= e($aviso) ?></div>
<?php endif; ?>
<?php if (!empty($erro)): ?>
  <div class="alert error-alert"><?= e($erro) ?></div>
<?php endif; ?>

<div class="studio-workspace">
  <section class="panel canvas-panel">
    <div class="canvas-toolbar">
      <div class="canvas-tool-title">
        <span>✦</span><b>Prancheta</b>
        <small><?= (int) $arte['canvas_width'] ?> × <?= (int) $arte['canvas_height'] ?></small>
      </div>
      <div class="canvas-actions">
        <?php if ($pode_editar): ?>
          <button type="button" class="canvas-export-button" data-export="png">↓ PNG</button>
          <button type="button" class="canvas-export-button" data-export="jpg">↓ JPG</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="canvas-stage">
      <div class="canvas-wrapper">
        <canvas id="studioCanvas"
                width="<?= (int) $arte['canvas_width'] ?>"
                height="<?= (int) $arte['canvas_height'] ?>"
                data-layout="<?= e((string) json_encode($layout, JSON_UNESCAPED_UNICODE)) ?>"
                data-bg-color="<?= e((string) $arte['background_color']) ?>"
                data-image-url="<?= e((string) ($imagem_url ?? '')) ?>"
                data-logo-url="<?= e((string) ($logo_url ?? '')) ?>"
                data-export-url="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/export')) ?>"
                data-csrf="<?= e($csrf) ?>"
                data-marca="<?= e(trim((string) ($arte['trade_name'] ?? '')) ?: (string) $arte['company_name']) ?>"
                data-editable="<?= $pode_editar ? '1' : '0' ?>"></canvas>
        <div class="canvas-no-image" id="canvasNoImage" <?= $imagem_url ? 'hidden' : '' ?>>
          <span>✦</span><b>Imagem de fundo</b>
          <small>Gere uma imagem com IA ou envie uma referência para começar.</small>
        </div>
      </div>
    </div>
    <div class="canvas-hint">
      <span>↗</span> Clique no título, subtítulo ou CTA para selecionar. Arraste para mover;
      use o puxador do canto para redimensionar.
    </div>
    <?php if ($arte['export_path']): ?>
      <a class="export-ready" href="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/download')) ?>">
        ✓ Arte exportada — baixar arquivo <span>↓</span>
      </a>
    <?php endif; ?>
  </section>

  <aside class="studio-controls">
    <?php if ($pode_editar): ?>
    <section class="panel studio-control-panel">
      <div class="control-heading">
        <div><h2>Editar arte</h2><p>Texto e composição da publicação.</p></div>
        <span class="edit-icon">✎</span>
      </div>
      <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/save')) ?>" id="studioSave">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="layout_json" id="layoutJson">
        <label class="field studio-field">Título
          <input name="title" data-layout-text="title" maxlength="1000" required
                 value="<?= e((string) ($conteudo['title'] ?? '')) ?>">
        </label>
        <label class="field studio-field">Subtítulo
          <textarea name="subtitle" data-layout-text="subtitle" rows="2" maxlength="1000"><?= e((string) ($conteudo['subtitle'] ?? '')) ?></textarea>
        </label>
        <label class="field studio-field">Chamada para ação
          <input name="cta" data-layout-text="cta" maxlength="1000" value="<?= e((string) ($conteudo['cta'] ?? '')) ?>">
        </label>

        <div class="selected-element-row">
          <div><span class="field-kicker">ELEMENTO SELECIONADO</span><b id="selectedLabel">Título</b></div>
          <label class="mini-size-label">Tamanho
            <input type="range" id="elementSize" min="12" max="180" step="1" value="48" aria-label="Tamanho do texto selecionado">
          </label>
          <label class="mini-color-label">Cor
            <input type="color" id="elementColor" value="#ffffff" aria-label="Cor do texto selecionado">
          </label>
        </div>

        <div class="control-divider"></div>

        <label class="field studio-field">Cor de fundo
          <input type="color" name="background_color" id="backgroundColor" value="<?= e((string) $arte['background_color']) ?>">
        </label>
        <?php foreach (['caption', 'hashtags', 'visual_brief', 'image_suggestion', 'composition'] as $campo): ?>
          <label class="field studio-field"><?= e($rotulos[$campo] ?? $campo) ?>
            <textarea name="<?= e($campo) ?>" rows="<?= $campo === 'caption' ? 4 : 2 ?>" maxlength="5000"
              <?= in_array($campo, ['caption', 'visual_brief', 'image_suggestion'], true) ? 'required' : '' ?>><?= e((string) ($conteudo[$campo] ?? '')) ?></textarea>
          </label>
        <?php endforeach; ?>
        <label class="field studio-field">Prompt da imagem
          <input name="image_prompt" id="imagePromptSave" maxlength="4000" value="<?= e((string) $arte['image_prompt']) ?>">
          <span class="field-helper">A imagem é gerada sem tipografia para evitar letras distorcidas.</span>
        </label>
        <button class="button primary save-creative-button" type="submit">Salvar alterações <span>✓</span></button>
      </form>
    </section>

    <section class="panel studio-control-panel image-control-panel">
      <div class="control-heading">
        <div><h2>Imagem de fundo</h2><p>Use a API de imagem real ou envie um arquivo.</p></div>
        <span class="image-icon">▧</span>
      </div>
      <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/image')) ?>" id="imageGenerationForm">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="image_prompt" id="imageGenerationPrompt" value="<?= e((string) $arte['image_prompt']) ?>">
        <button class="button primary full-button" type="submit" <?= $ia_configurada ? '' : 'disabled' ?>
          <?= $ia_configurada ? '' : 'title="Configure OPENAI_API_KEY no .env"' ?>>✦ Gerar imagem com IA</button>
      </form>
      <div class="upload-divider"><span>ou use sua imagem</span></div>
      <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/image/upload')) ?>"
            enctype="multipart/form-data" class="studio-upload-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <label class="button outline full-button">↑ Substituir imagem
          <input type="file" name="file" hidden required accept="image/png,image/jpeg,image/webp" data-image-upload>
        </label>
      </form>
      <small class="studio-image-note">PNG, JPG ou WEBP até 8 MB · a imagem é ajustada ao formato selecionado.</small>
    </section>
    <?php endif; ?>

    <section class="panel studio-control-panel version-panel">
      <div class="control-heading">
        <div><h2>Versões salvas</h2><p>Restaure uma edição anterior.</p></div>
        <span class="version-count"><?= count($versoes) ?></span>
      </div>
      <?php if ($versoes === []): ?>
        <p class="versions-empty">Nenhuma versão salva.</p>
      <?php else: ?>
        <?php foreach ($versoes as $indice => $versao): ?>
          <div class="version-row">
            <div class="version-dot"></div>
            <div class="version-info">
              <b><?= e((string) $versao['version_label']) ?></b>
              <small><?= e((string) $versao['created_at']) ?></small>
            </div>
            <?php if ($indice > 0 && $pode_editar): ?>
              <form method="post"
                    action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/versions/' . (int) $versao['id'] . '/restore')) ?>"
                    data-confirm="Restaurar esta versão? As alterações atuais ficam no histórico.">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button title="Restaurar versão" aria-label="Restaurar versão" type="submit">↶</button>
              </form>
            <?php elseif ($indice === 0): ?>
              <span class="current-version">Atual</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </aside>
</div>
<script src="<?= e(smia_asset('/assets/studio.js')) ?>" defer></script>
<?php
$content = ob_get_clean();
$title = 'Editor de arte';
$active = 'studio';
require __DIR__ . '/../layouts/app.php';
