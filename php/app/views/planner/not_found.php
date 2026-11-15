<?php
/**
 * 404 do planejamento.
 *
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="empty-state">
  <div class="empty-illustration"><div class="empty-circle">⌕</div></div>
  <div class="eyebrow muted-eyebrow">NÃO ENCONTRADO</div>
  <h2>Esse planejamento não existe neste workspace</h2>
  <p>O calendário pode ter sido removido ou pertence a outra agência.</p>
  <a class="button primary" href="<?= e($config->url('/planner')) ?>">Ver planejamentos</a>
</div>
<?php
$content = ob_get_clean();
$title = 'Planejamento não encontrado';
require __DIR__ . '/../layouts/app.php';
