<?php
/**
 * 403 do feed.
 *
 * @var string $mensagem
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="empty-state">
  <div class="empty-illustration"><div class="empty-circle">⚿</div></div>
  <div class="eyebrow muted-eyebrow">ACESSO RESTRITO</div>
  <h2>Seu perfil não pode montar a grade</h2>
  <p><?= e($mensagem) ?></p>
  <a class="button outline" href="<?= e($config->url('/feed')) ?>">← Ver a prévia</a>
</div>
<?php
$content = ob_get_clean();
$title = 'Acesso restrito';
require __DIR__ . '/../layouts/app.php';
