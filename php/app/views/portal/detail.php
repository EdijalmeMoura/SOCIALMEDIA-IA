<?php
/**
 * Portal: conteúdo aberto para decisão.
 *
 * A decisão só pode ser tomada uma vez. Depois disso a tela mostra o que foi
 * respondido, quando e por quem — o registro é o mesmo que a agência vê.
 *
 * @var array<string,mixed> $conta
 * @var array<string,mixed> $aprovacao
 * @var array<string,mixed> $snapshot
 * @var string|null $midia
 * @var array<int,array<string,mixed>> $comentarios
 * @var array<int,array<string,mixed>> $eventos
 * @var string|null $aviso
 * @var string|null $erro
 */
$statusRotulos = [
    'pending' => 'Aguardando você',
    'approved' => 'Aprovado',
    'changes_requested' => 'Alterações pedidas',
    'rejected' => 'Rejeitado',
];
$status = (string) $aprovacao['status'];
$pendente = $status === 'pending';
$acao = static fn (string $resto): string => '/portal/approvals/' . (int) $aprovacao['id'] . $resto;
ob_start();
?>
<a class="back-link" href="<?= e($config->url('/portal/approvals')) ?>">← <span>Voltar aos meus conteúdos</span></a>

<div class="approval-detail-head">
  <div>
    <div class="eyebrow muted-eyebrow">
      CICLO <?= (int) $aprovacao['cycle_number'] ?> ·
      <?= $snapshot['type'] === 'creative' ? 'ARTE' : 'PLANEJAMENTO' ?> ·
      ENVIADO EM <?= e(date('d/m/Y H:i', (int) strtotime((string) $aprovacao['submitted_at']))) ?>
    </div>
    <h1><?= e((string) $aprovacao['title']) ?></h1>
  </div>
  <span class="approval-status status-<?= e($status) ?> big"><?= e($statusRotulos[$status] ?? $status) ?></span>
</div>

<?php if (!empty($aviso)): ?>
  <div class="alert success-alert"><?= e($aviso) ?></div>
<?php endif; ?>
<?php if (!empty($erro)): ?>
  <div class="alert error-alert"><?= e($erro) ?></div>
<?php endif; ?>

<div class="approval-detail-grid">
  <section class="panel approval-preview">
    <?php if ($midia !== null): ?>
      <img src="<?= e($midia) ?>" alt="Prévia de <?= e((string) $aprovacao['title']) ?>">
    <?php else: ?>
      <div class="approval-no-media">Sem imagem: este conteúdo é um planejamento de pauta.</div>
    <?php endif; ?>

    <?php if ($snapshot['type'] === 'creative'): ?>
      <div class="approval-meta">
        <span><?= e((string) ($snapshot['format'] ?? 'Arte')) ?></span>
        <span><?= (int) ($snapshot['width'] ?? 0) ?> × <?= (int) ($snapshot['height'] ?? 0) ?> px</span>
        <span class="color-chip" style="background: <?= e((string) ($snapshot['background_color'] ?? '#ffffff')) ?>"></span>
      </div>
      <div class="approval-copy">
        <h3>Texto da arte</h3>
        <p><b><?= e((string) ($snapshot['content']['title'] ?? '')) ?></b></p>
        <p><?= e((string) ($snapshot['content']['subtitle'] ?? '')) ?></p>
        <p class="muted"><?= e((string) ($snapshot['content']['cta'] ?? '')) ?></p>
        <?php if (!empty($snapshot['content']['caption'])): ?>
          <h3>Legenda sugerida</h3>
          <p><?= nl2br(e((string) $snapshot['content']['caption'])) ?></p>
          <p class="muted"><?= e((string) ($snapshot['content']['hashtags'] ?? '')) ?></p>
        <?php endif; ?>
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
    <?php if ($pendente): ?>
      <section class="panel approval-actions">
        <div class="panel-heading">
          <div>
            <h3>Sua decisão</h3>
            <p>Uma resposta por conteúdo. Explique o que precisa mudar quando for o caso.</p>
          </div>
        </div>

        <form method="post" action="<?= e($config->url($acao('/respond'))) ?>" enctype="multipart/form-data" class="approval-respond">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <label class="field">Comentário (obrigatório ao pedir alterações)
            <textarea name="body" rows="4" maxlength="3000" placeholder="Ex.: troque a foto por uma de treino funcional e cite o desconto de 20%."></textarea>
          </label>
          <label class="field">Anexar referência (opcional)
            <input type="file" name="file" accept="image/png,image/jpeg,image/webp">
          </label>
          <div class="approval-buttons">
            <button class="button primary" type="submit" name="status" value="approved">✓ Aprovar</button>
            <button class="button outline" type="submit" name="status" value="changes_requested">✎ Pedir alterações</button>
            <button class="button danger-outline" type="submit" name="status" value="rejected"
              data-confirm="Rejeitar este conteúdo? A agência precisará enviar um novo ciclo.">Rejeitar</button>
          </div>
        </form>
      </section>

      <section class="panel">
        <div class="panel-heading"><div><h3>Só um comentário</h3><p>Sem decidir agora — a dúvida vai para a agência.</p></div></div>
        <form method="post" action="<?= e($config->url($acao('/comment'))) ?>" enctype="multipart/form-data" class="approval-respond">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <label class="field">Mensagem
            <textarea name="body" rows="3" maxlength="3000" placeholder="Alguma dúvida sobre este material?"></textarea>
          </label>
          <label class="field">Anexar imagem (opcional)
            <input type="file" name="file" accept="image/png,image/jpeg,image/webp">
          </label>
          <button class="button outline" type="submit">Enviar comentário</button>
        </form>
      </section>
    <?php else: ?>
      <section class="panel approval-resolved">
        <div class="panel-heading"><div><h3>Resposta registrada</h3><p>Esta revisão já foi respondida.</p></div></div>
        <p class="approval-decision decision-<?= e($status) ?>"><?= e($statusRotulos[$status] ?? $status) ?></p>
        <?php if (!empty($aprovacao['resolved_at'])): ?>
          <p class="muted">Em <?= e(date('d/m/Y H:i', (int) strtotime((string) $aprovacao['resolved_at']))) ?></p>
        <?php endif; ?>
        <p class="muted">Precisa falar mais alguma coisa? Use o comentário abaixo — a agência recebe o aviso.</p>
        <form method="post" action="<?= e($config->url($acao('/comment'))) ?>" enctype="multipart/form-data" class="approval-respond">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <label class="field">Mensagem
            <textarea name="body" rows="3" maxlength="3000" placeholder="Escreva para a equipe"></textarea>
          </label>
          <label class="field">Anexar imagem (opcional)
            <input type="file" name="file" accept="image/png,image/jpeg,image/webp">
          </label>
          <button class="button outline" type="submit">Enviar comentário</button>
        </form>
      </section>
    <?php endif; ?>
  </aside>
</div>

<section class="panel approval-history" id="historico">
  <div class="panel-heading">
    <div>
      <h3>Histórico</h3>
      <p>Tudo o que aconteceu nesta revisão, em ordem.</p>
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

  <?php if ($comentarios !== []): ?>
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
            <a class="comment-attachment" href="<?= e($config->url($acao('/media/' . $comentario['attachment_path']))) ?>" target="_blank" rel="noopener">
              <img src="<?= e($config->url($acao('/media/' . $comentario['attachment_path']))) ?>" alt="<?= e((string) ($comentario['attachment_name'] ?? 'Anexo')) ?>">
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
$title = (string) $aprovacao['title'];
require __DIR__ . '/../layouts/portal.php';
