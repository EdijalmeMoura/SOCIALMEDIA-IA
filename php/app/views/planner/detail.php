<?php
/**
 * Calendário editorial do plano: grade por semana + lista de publicações.
 *
 * @var array<string,mixed> $plano
 * @var array<int,array<string,mixed>> $posts
 * @var array<int,array<int,array<string,mixed>>> $semanas
 * @var bool $pode_editar
 * @var bool $pode_excluir
 * @var bool $ia_configurada
 * @var string|null $aviso
 * @var string|null $erro
 * @var string $csrf
 * @var \Smia\Support\Config $config
 */
use Smia\Planner\PlannerRepository;

$marca = trim((string) ($plano['trade_name'] ?? '')) !== '' ? (string) $plano['trade_name'] : (string) $plano['company_name'];
$rascunhoLocal = (string) $plano['ai_model'] === 'local-draft';
ob_start();
?>
<div class="detail-top">
  <div>
    <a class="back-link" href="<?= e($config->url('/planner')) ?>">← <span>Todos os planejamentos</span></a>
    <div class="eyebrow muted-eyebrow">CALENDÁRIO EDITORIAL · <?= (int) $plano['period_days'] ?> DIAS</div>
    <h1><?= e($marca) ?></h1>
    <p class="page-subtitle">
      <?= e((string) $plano['objective']) ?> · início <?= e(date('d/m/Y', strtotime((string) $plano['start_date']))) ?>
      <span class="badge <?= $plano['status'] === 'in_production' ? 'badge-live' : '' ?>">
        <?= $plano['status'] === 'in_production' ? 'Em produção' : 'Rascunho' ?>
      </span>
      <?php if ($rascunhoLocal): ?><span class="badge">Rascunho local (sem IA)</span><?php endif; ?>
    </p>
  </div>
  <?php if ($pode_editar): ?>
    <div class="detail-actions">
      <?php if ($pode_excluir): ?>
        <form method="post" action="<?= e($config->url('/planner/' . $plano['id'] . '/delete')) ?>"
              data-confirm="Excluir este planejamento e todas as publicações dele?">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <button class="button danger-outline" type="submit">Excluir plano</button>
        </form>
      <?php endif; ?>
      <?php if ($plano['status'] === 'draft'): ?>
        <form method="post" action="<?= e($config->url('/planner/' . $plano['id'] . '/production')) ?>"
              data-confirm="Encaminhar todas as publicações para produção?">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <button class="button primary" type="submit">Enviar para produção →</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($aviso !== null): ?>
  <div class="toast-message"><?= e($aviso) ?></div>
<?php endif; ?>
<?php if ($erro !== null && $erro !== ''): ?>
  <div class="alert error-alert"><?= e($erro) ?></div>
<?php endif; ?>

<div class="plan-facts">
  <div><span>CLIENTE</span><b><?= e($marca) ?></b></div>
  <div><span>OBJETIVO</span><b><?= e((string) $plano['objective']) ?></b></div>
  <div><span>PUBLICAÇÕES</span><b><?= count($posts) ?> conteúdos</b></div>
  <div><span>REDES</span><b><?= e(implode(', ', PlannerRepository::redes($plano['networks_json']))) ?></b></div>
</div>

<?php if (trim((string) $plano['campaign_context']) !== ''): ?>
  <div class="context-note">
    <b>◈ Contexto da campanha</b>
    <p><?= nl2br(e((string) $plano['campaign_context'])) ?></p>
  </div>
<?php endif; ?>

<section class="panel">
  <div class="panel-heading">
    <div>
      <h3>Calendário editorial</h3>
      <p>Uma visão por semana. Selecione um post para editar e revisar todos os detalhes.</p>
    </div>
  </div>

  <div class="week-grid">
    <?php foreach ($semanas as $indice => $semana): ?>
      <div class="week-block">
        <div class="week-title">SEMANA <?= $indice + 1 ?></div>
        <div class="week-days">
          <?php foreach ($semana as $dia): ?>
            <div class="day-cell <?= $dia['posts'] !== [] ? 'has-posts' : '' ?>">
              <div class="day-head">
                <span><?= e(mb_strtoupper($dia['weekday'])) ?></span>
                <b><?= e($dia['label']) ?>/<?= e($dia['mes']) ?></b>
              </div>
              <?php foreach ($dia['posts'] as $post): ?>
                <?php if ($pode_editar): ?>
                  <a class="post-chip" href="<?= e($config->url('/planner/posts/' . $post['id'] . '/edit')) ?>">
                    <b><?= e(mb_substr((string) $post['theme'], 0, 42)) ?></b>
                    <small><?= e((string) $post['network']) ?></small>
                  </a>
                <?php else: ?>
                  <span class="post-chip">
                    <b><?= e(mb_substr((string) $post['theme'], 0, 42)) ?></b>
                    <small><?= e((string) $post['network']) ?></small>
                  </span>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel">
  <div class="panel-heading">
    <div>
      <h3>Conteúdo do planejamento</h3>
      <p>Textos, CTAs e briefings visuais gerados para esta marca.</p>
    </div>
    <span class="tag"><?= count($posts) ?> publicações</span>
  </div>

  <?php if ($posts === []): ?>
    <div class="empty-state" style="padding:28px 0"><p class="page-subtitle">Nenhuma publicação neste plano.</p></div>
  <?php else: ?>
    <div class="post-list">
      <?php foreach ($posts as $post): ?>
        <article class="post-card">
          <header>
            <div>
              <span class="post-date"><?= e(date('d/m', strtotime((string) $post['publication_date']))) ?></span>
              <b><?= e((string) $post['theme']) ?></b>
            </div>
            <div class="post-card-actions">
              <span class="tag"><?= e((string) $post['network']) ?></span>
              <span class="tag tag-soft"><?= e((string) $post['content_type']) ?></span>
              <?php if ($pode_editar): ?>
                <a class="mini-link" href="<?= e($config->url('/planner/posts/' . $post['id'] . '/edit')) ?>">Editar</a>
                <form method="post" action="<?= e($config->url('/planner/posts/' . $post['id'] . '/duplicate')) ?>">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <button class="mini-link" type="submit">Duplicar</button>
                </form>
                <form method="post" action="<?= e($config->url('/planner/posts/' . $post['id'] . '/delete')) ?>"
                      data-confirm="Remover esta publicação do planejamento?">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <button class="mini-link danger" type="submit">Remover</button>
                </form>
              <?php endif; ?>
            </div>
          </header>
          <div class="post-body">
            <div class="post-field">
              <span>OBJETIVO ESTRATÉGICO</span>
              <p><?= e((string) $post['strategic_objective']) ?></p>
            </div>
            <div class="post-field">
              <span>LEGENDA</span>
              <p><?= nl2br(e((string) $post['caption'])) ?></p>
            </div>
            <?php if (trim((string) $post['hashtags']) !== ''): ?>
              <div class="post-field"><span>HASHTAGS</span><p class="mono"><?= e((string) $post['hashtags']) ?></p></div>
            <?php endif; ?>
            <?php if (trim((string) $post['cta']) !== ''): ?>
              <div class="post-field"><span>CHAMADA PARA AÇÃO</span><p><?= e((string) $post['cta']) ?></p></div>
            <?php endif; ?>
            <div class="post-field">
              <span>BRIEFING VISUAL</span>
              <p><?= e((string) $post['visual_brief']) ?></p>
            </div>
            <div class="post-field">
              <span>SUGESTÃO DE IMAGEM</span>
              <p><?= e((string) $post['image_suggestion']) ?></p>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = (string) $plano['title'];
require __DIR__ . '/../layouts/app.php';
