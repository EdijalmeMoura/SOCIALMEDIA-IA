<?php
/**
 * 403 do planejamento.
 *
 * @var string $mensagem
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="empty-state">
  <div class="empty-illustration"><div class="empty-circle">⚿</div></div>
  <div class="eyebrow muted-eyebrow">ACESSO RESTRITO</div>
  <h2>Seu perfil não pode fazer isso</h2>
  <p><?= e($mensagem) ?></p>
  <a class="button outline" href="<?= e($config->url('/planner')) ?>">← Voltar ao planejamento</a>
</div>
<?php
$content = ob_get_clean();
$title = 'Acesso restrito';
require __DIR__ . '/../layouts/app.php';
