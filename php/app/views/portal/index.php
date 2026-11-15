<?php
/**
 * Portal do cliente: fila de revisões.
 *
 * @var array<string,mixed> $conta
 * @var array<int,array<string,mixed>> $aprovacoes
 * @var array<string,int> $contagem
 * @var array<int,array<string,mixed>> $avisos
 * @var string|null $aviso
 */
$statusRotulos = [
    'pending' => 'Aguardando você',
    'approved' => 'Aprovado',
    'changes_requested' => 'Alterações pedidas',
    'rejected' => 'Rejeitado',
];
$nomeCliente = trim((string) ($conta['trade_name'] ?? '')) ?: (string) $conta['company_name'];
ob_start();
?>
<div class="page-head portal-head">
  <div>
    <div class="eyebrow">REVISÃO</div>
    <h1>Conteúdos de <?= e($nomeCliente) ?></h1>
    <p class="page-sub">Veja o material, aprove ou diga exatamente o que precisa mudar. Nada é publicado sem o seu aval.</p>
  </div>
  <div class="page-actions">
    <span class="badge <?= $contagem['pending'] > 0 ? 'badge-warn' : 'badge-live' ?>">
      <?= (int) $contagem['pending'] ?> aguardando
    </span>
  </div>
</div>

<?php if (!empty($aviso)): ?>
  <div class="alert success-alert"><?= e($aviso) ?></div>
<?php endif; ?>

<div class="approval-stats">
  <div class="approval-stat"><b><?= (int) $contagem['pending'] ?></b><span>Aguardando você</span></div>
  <div class="approval-stat"><b><?= (int) $contagem['changes_requested'] ?></b><span>Alterações pedidas</span></div>
  <div class="approval-stat"><b><?= (int) $contagem['approved'] ?></b><span>Aprovados</span></div>
  <div class="approval-stat"><b><?= (int) $contagem['total'] ?></b><span>Total</span></div>
</div>

<?php if ($aprovacoes === []): ?>
  <div class="panel empty-state">
    <div class="empty-illustration"><div class="empty-circle">◷</div></div>
    <h2>Nada para revisar agora</h2>
    <p>Quando a agência enviar um conteúdo, ele aparece aqui — e você recebe um aviso nesta página.</p>
  </div>
<?php else: ?>
  <div class="approval-grid">
    <?php foreach ($aprovacoes as $item): ?>
      <?php
        $fotografia = json_decode((string) $item['snapshot_json'], true) ?: [];
        $capa = (string) ($fotografia['export_path'] ?? $fotografia['image_path'] ?? '');
        $status = (string) $item['status'];
      ?>
      <a class="approval-card is-<?= e($status) ?>" href="<?= e($config->url('/portal/approvals/' . (int) $item['id'])) ?>">
        <div class="approval-card-cover">
          <?php if ($capa !== ''): ?>
            <img src="<?= e($config->url('/portal/approvals/' . (int) $item['id'] . '/media/' . $capa)) ?>" alt="" loading="lazy">
          <?php else: ?>
            <span class="approval-cover-badge"><?= e((string) ($fotografia['type'] === 'creative' ? ($fotografia['format'] ?? 'Arte') : ($fotografia['network'] ?? 'Planejamento'))) ?></span>
          <?php endif; ?>
          <span class="approval-status status-<?= e($status) ?>"><?= e($statusRotulos[$status] ?? $status) ?></span>
        </div>
        <div class="approval-card-body">
          <b><?= e((string) $item['title']) ?></b>
          <small>
            Ciclo <?= (int) $item['cycle_number'] ?> ·
            enviado em <?= e(date('d/m/Y H:i', (int) strtotime((string) $item['submitted_at']))) ?>
          </small>
          <span class="approval-card-foot">
            <?= (int) $item['comment_count'] ?> comentário(s)
            <span>abrir →</span>
          </span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($avisos !== []): ?>
  <section class="panel portal-notices">
    <div class="panel-heading">
      <div>
        <h3>Avisos</h3>
        <p>O que mudou desde a sua última visita.</p>
      </div>
      <form method="post" action="<?= e($config->url('/portal/notifications/read')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="text-link" type="submit">Marcar como lidos</button>
      </form>
    </div>
    <ul class="notice-list">
      <?php foreach ($avisos as $avisoItem): ?>
        <li class="<?= $avisoItem['read_at'] === null ? 'is-new' : '' ?>">
          <a href="<?= e($config->url((string) $avisoItem['target_url'])) ?>">
            <b><?= e((string) $avisoItem['title']) ?></b>
            <span><?= e((string) $avisoItem['body']) ?></span>
            <small><?= e(date('d/m/Y H:i', (int) strtotime((string) $avisoItem['created_at']))) ?></small>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Meus conteúdos';
require __DIR__ . '/../layouts/portal.php';
