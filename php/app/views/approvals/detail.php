<?php
/**
 * Aprovação: fotografia do conteúdo enviado + histórico da revisão.
 *
 * @var array<string,mixed> $aprovacao
 * @var array<string,mixed> $snapshot
 * @var string|null $midia
 * @var array<int,array<string,mixed>> $comentarios
 * @var array<int,array<string,mixed>> $eventos
 * @var bool $pode_editar
 * @var string|null $aviso
 * @var string|null $erro
 */
$statusRotulos = \Smia\Approvals\ApprovalRepository::STATUS;
$status = (string) $aprovacao['status'];
$pendente = $status === 'pending';
$nomeCliente = trim((string) ($aprovacao['trade_name'] ?? '')) ?: (string) $aprovacao['company_name'];
$base = '/approvals/' . (int) $aprovacao['id'];
ob_start();
?>
<a class="back-link" href="<?= e($config->url('/approvals')) ?>">← <span>Voltar às aprovações</span></a>

<div class="approval-detail-head">
  <div>
    <div class="eyebrow muted-eyebrow">
      <?= e($nomeCliente) ?> · CICLO <?= (int) $aprovacao['cycle_number'] ?> ·
      <?= $aprovacao['source_type'] === 'creative' ? 'ARTE' : 'PAUTA' ?> ·
      ENVIADO EM <?= e(date('d/m/Y H:i', (int) strtotime((string) $aprovacao['submitted_at']))) ?>
    </div>
    <h1><?= e((string) $aprovacao['title']) ?></h1>
  </div>
  <div class="detail-actions">
    <span class="approval-status status-<?= e($status) ?> big"><?= e($statusRotulos[$status] ?? $status) ?></span>
    <?php if ($pendente && $pode_editar): ?>
      <form method="post" action="<?= e($config->url($base . '/cancel')) ?>"
            data-confirm="Cancelar esta revisão? O conteúdo volta a ficar disponível para novo envio.">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="button danger-outline" type="submit">Cancelar revisão</button>
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

<?php if ($pendente): ?>
  <div class="alert info-alert">
    <b>Aguardando o cliente</b>
    <p>Ele vê exatamente o que foi enviado — editar a arte no estúdio agora não muda esta revisão.
      Se precisar mudar o conteúdo, cancele e envie um novo ciclo.</p>
  </div>
<?php endif; ?>

<div class="approval-detail-grid">
  <section class="panel approval-preview">
    <?php if ($midia !== null): ?>
      <img src="<?= e($midia) ?>" alt="Prévia de <?= e((string) $aprovacao['title']) ?>">
    <?php else: ?>
      <div class="approval-no-media">Pauta sem imagem: o cliente revisa o texto do planejamento.</div>
    <?php endif; ?>

    <?php if ($snapshot['type'] === 'creative'): ?>
      <div class="approval-meta">
        <span><?= e((string) ($snapshot['format'] ?? 'Arte')) ?></span>
        <span><?= (int) ($snapshot['width'] ?? 0) ?> × <?= (int) ($snapshot['height'] ?? 0) ?> px</span>
        <span class="color-chip" style="background: <?= e((string) ($snapshot['background_color'] ?? '#ffffff')) ?>"></span>
      </div>
      <div class="approval-copy">
        <h3>Título</h3>
        <p><?= e((string) ($snapshot['content']['title'] ?? '')) ?></p>
        <h3>Subtítulo</h3>
        <p><?= e((string) ($snapshot['content']['subtitle'] ?? '')) ?></p>
        <h3>Chamada para ação</h3>
        <p><?= e((string) ($snapshot['content']['cta'] ?? '')) ?></p>
        <h3>Legenda</h3>
        <p><?= nl2br(e((string) ($snapshot['content']['caption'] ?? ''))) ?></p>
        <p class="muted"><?= e((string) ($snapshot['content']['hashtags'] ?? '')) ?></p>
      </div>
    <?php else: ?>
      <div class="approval-copy">
        <div class="approval-meta">
          <span><?= e((string) ($snapshot['network'] ?? '')) ?></span>
          <span><?= e((string) ($snapshot['publication_date'] ?? '')) ?></span>
          <span><?= e((string) ($snapshot['content_type'] ?? '')) ?></span>
        </div>
        <?php if (!empty($snapshot['plan_title'])): ?>
          <p class="muted">Plano: <?= e((string) $snapshot['plan_title']) ?></p>
        <?php endif; ?>
        <h3>Objetivo estratégico</h3>
        <p><?= nl2br(e((string) ($snapshot['strategic_objective'] ?? ''))) ?></p>
        <h3>Texto principal</h3>
        <p><?= nl2br(e((string) ($snapshot['main_text'] ?? ''))) ?></p>
        <h3>Legenda</h3>
        <p><?= nl2br(e((string) ($snapshot['caption'] ?? ''))) ?></p>
        <p class="muted"><?= e((string) ($snapshot['hashtags'] ?? '')) ?></p>
        <h3>Chamada para ação</h3>
        <p><?= e((string) ($snapshot['cta'] ?? '')) ?></p>
        <h3>Briefing visual</h3>
        <p><?= nl2br(e((string) ($snapshot['visual_brief'] ?? ''))) ?></p>
        <h3>Sugestão de imagem</h3>
        <p><?= nl2br(e((string) ($snapshot['image_suggestion'] ?? ''))) ?></p>
      </div>
    <?php endif; ?>
  </section>

  <aside class="approval-side">
    <?php if ($pode_editar): ?>
      <section class="panel">
        <div class="panel-heading">
          <div>
            <h3>Falar com o cliente</h3>
            <p>O comentário aparece no portal e gera aviso.</p>
          </div>
        </div>
        <form method="post" action="<?= e($config->url($base . '/comment')) ?>" enctype="multipart/form-data" class="approval-respond">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <label class="field">Comentário
            <textarea name="body" rows="3" maxlength="3000" placeholder="Ex.: ajustei a foto e mantive a oferta."></textarea>
          </label>
          <label class="field">Anexar imagem (opcional)
            <input type="file" name="file" accept="image/png,image/jpeg,image/webp">
          </label>
          <button class="button outline" type="submit">Enviar comentário</button>
        </form>
      </section>
    <?php endif; ?>

    <section class="panel approval-facts">
      <div class="panel-heading"><div><h3>Resumo</h3><p>O que foi enviado.</p></div></div>
      <dl>
        <dt>Cliente</dt><dd><?= e($nomeCliente) ?></dd>
        <dt>Origem</dt>
        <dd>
          <?php if ($aprovacao['source_type'] === 'creative'): ?>
            <a class="text-link" href="<?= e($config->url('/studio/posts/' . (int) $aprovacao['creative_id'])) ?>">Abrir no estúdio <span>→</span></a>
          <?php else: ?>
            Pauta #<?= (int) $aprovacao['planned_post_id'] ?>
            <a class="text-link" href="<?= e($config->url('/planner')) ?>">planejamento <span>→</span></a>
          <?php endif; ?>
        </dd>
        <dt>Ciclo</dt><dd><?= (int) $aprovacao['cycle_number'] ?></dd>
        <dt>Enviado</dt><dd><?= e(date('d/m/Y H:i', (int) strtotime((string) $aprovacao['submitted_at']))) ?></dd>
        <?php if (!empty($aprovacao['resolved_at'])): ?>
          <dt>Respondido</dt><dd><?= e(date('d/m/Y H:i', (int) strtotime((string) $aprovacao['resolved_at']))) ?></dd>
        <?php endif; ?>
      </dl>
    </section>
  </aside>
</div>

<section class="panel approval-history" id="historico">
  <div class="panel-heading">
    <div>
      <h3>Histórico da revisão</h3>
      <p>Quem fez o quê, com data — o mesmo registro que o cliente vê.</p>
    </div>
  </div>

  <ul class="timeline">
    <?php foreach ($eventos as $evento): ?>
      <?php
        $autor = $evento['actor_type'] === 'client'
            ? ((string) ($evento['client_name'] ?? 'Cliente') . ' · cliente')
            : ((string) ($evento['agency_name'] ?? 'Equipe') . ' · agência');
      ?>
      <li class="timeline-item is-<?= e((string) $evento['new_status']) ?>">
        <span class="timeline-dot"></span>
        <div>
          <b><?= e($autor) ?></b>
          <span><?= e($statusRotulos[(string) $evento['new_status']] ?? (string) $evento['new_status']) ?></span>
          <?php if (trim((string) $evento['note']) !== ''): ?>
            <p><?= nl2br(e((string) $evento['note'])) ?></p>
          <?php endif; ?>
          <small><?= e(date('d/m/Y H:i', (int) strtotime((string) $evento['created_at']))) ?></small>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($comentarios === []): ?>
    <p class="form-hint">Nenhum comentário ainda.</p>
  <?php else: ?>
    <ul class="comment-list">
      <?php foreach ($comentarios as $comentario): ?>
        <li class="comment-item from-<?= e((string) $comentario['author_type']) ?>">
          <div class="comment-head">
            <b><?= e((string) ($comentario['agency_name'] ?? $comentario['client_name'] ?? 'Alguém')) ?></b>
            <span class="comment-role"><?= $comentario['author_type'] === 'client' ? 'cliente' : 'agência' ?></span>
            <small><?= e(date('d/m/Y H:i', (int) strtotime((string) $comentario['created_at']))) ?></small>
          </div>
          <?php if (trim((string) $comentario['body']) !== ''): ?>
            <p><?= nl2br(e((string) $comentario['body'])) ?></p>
          <?php endif; ?>
          <?php if (!empty($comentario['attachment_path'])): ?>
            <a class="comment-attachment" href="<?= e($config->url($base . '/media/' . $comentario['attachment_path'])) ?>" target="_blank" rel="noopener">
              <img src="<?= e($config->url($base . '/media/' . $comentario['attachment_path'])) ?>" alt="<?= e((string) ($comentario['attachment_name'] ?? 'Anexo')) ?>">
              <span><?= e((string) ($comentario['attachment_name'] ?? 'Anexo')) ?></span>
            </a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = 'Revisão · ' . (string) $aprovacao['title'];
require __DIR__ . '/../layouts/app.php';
