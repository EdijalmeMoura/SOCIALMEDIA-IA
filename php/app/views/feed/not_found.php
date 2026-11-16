<?php
/**
 * 404 do feed.
 *
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="empty-state">
  <div class="empty-illustration"><div class="empty-circle">⌕</div></div>
  <div class="eyebrow muted-eyebrow">NÃO ENCONTRADO</div>
  <h2>Essa marca não está disponível</h2>
  <p>Ela pode estar inativa ou pertencer a outra agência.</p>
  <a class="button primary" href="<?= e($config->url('/feed')) ?>">Ver feed</a>
</div>
<?php
$content = ob_get_clean();
$title = 'Marca não encontrada';
require __DIR__ . '/../layouts/app.php';
