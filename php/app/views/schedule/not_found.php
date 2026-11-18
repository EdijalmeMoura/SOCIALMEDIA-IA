<?php
/**
 * 404 da agenda: horário de outra agência ou inexistente.
 *
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="empty-state schedule-missing">
  <div class="empty-illustration"><div class="empty-circle">◷</div></div>
  <div class="eyebrow muted-eyebrow">NÃO ENCONTRADO</div>
  <h2>Esse horário não está nesta agência</h2>
  <p>A agenda não mostra nem altera horários de outro workspace.</p>
  <a class="button primary" href="<?= e($config->url('/schedule')) ?>">Voltar à agenda</a>
</div>
<?php
$content = ob_get_clean();
$title = 'Horário não encontrado';
require __DIR__ . '/../layouts/app.php';
