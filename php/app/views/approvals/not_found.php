<?php
/**
 * 404 de aprovações.
 *
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="empty-state">
  <div class="empty-illustration"><div class="empty-circle">⌕</div></div>
  <div class="eyebrow muted-eyebrow">NÃO ENCONTRADO</div>
  <h2>Essa revisão não existe neste workspace</h2>
  <p>Ela pode ter sido removida junto com o conteúdo ou pertence a outra agência.</p>
  <a class="button primary" href="<?= e($config->url('/approvals')) ?>">Ver aprovações</a>
</div>
<?php
$content = ob_get_clean();
$title = 'Revisão não encontrada';
require __DIR__ . '/../layouts/app.php';
