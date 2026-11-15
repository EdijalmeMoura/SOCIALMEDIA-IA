<?php
/**
 * Edição de uma publicação do calendário.
 *
 * @var array<string,mixed> $post
 * @var array<string,mixed> $plano
 * @var string|null $erro
 * @var string $csrf
 * @var \Smia\Support\Config $config
 */
$v = static fn (string $campo): string => (string) ($post[$campo] ?? '');
$inicio = new DateTimeImmutable((string) $plano['start_date']);
$fim = $inicio->modify('+' . ((int) $plano['period_days'] - 1) . ' days');
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

<form method="post" action="<?= e($config->url('/planner/posts/' . $post['id'] . '/edit')) ?>" class="client-form">
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
        <select name="network" required>
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
        <textarea name="caption" rows="5" maxlength="5000" required><?= e($v('caption')) ?></textarea>
      </label>
      <label class="field span-12">Texto de apoio / roteiro <em>*</em>
        <textarea name="main_text" rows="4" maxlength="5000" required><?= e($v('main_text')) ?></textarea>
      </label>
      <label class="field span-6">Hashtags
        <input name="hashtags" maxlength="5000" value="<?= e($v('hashtags')) ?>" placeholder="#marca #segmento">
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
<?php
$content = ob_get_clean();
$title = 'Editar publicação';
require __DIR__ . '/../layouts/app.php';
