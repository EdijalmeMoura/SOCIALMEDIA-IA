<?php
/**
 * 404 do portal.
 *
 * @var array<string,mixed> $conta
 */
ob_start();
?>
<div class="empty-state">
  <div class="empty-illustration"><div class="empty-circle">⌕</div></div>
  <div class="eyebrow muted-eyebrow">NÃO ENCONTRADO</div>
  <h2>Esse conteúdo não está disponível para você</h2>
  <p>Ele pode ter sido removido ou pertence a outro cliente.</p>
  <a class="button primary" href="<?= e($config->url('/portal/approvals')) ?>">Ver meus conteúdos</a>
</div>
<?php
$content = ob_get_clean();
$title = 'Conteúdo não encontrado';
require __DIR__ . '/../layouts/portal.php';
