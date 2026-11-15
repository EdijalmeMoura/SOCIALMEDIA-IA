<?php
/**
 * 404 do estúdio.
 *
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="empty-state">
  <div class="empty-illustration"><div class="empty-circle">⌕</div></div>
  <div class="eyebrow muted-eyebrow">NÃO ENCONTRADO</div>
  <h2>Essa arte não existe neste workspace</h2>
  <p>A criação pode ter sido excluída ou pertence a outra agência.</p>
  <a class="button primary" href="<?= e($config->url('/studio')) ?>">Ver estúdio</a>
</div>
<?php
$content = ob_get_clean();
$title = 'Arte não encontrada';
require __DIR__ . '/../layouts/app.php';
