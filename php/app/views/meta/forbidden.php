<?php
/**
 * 403 das conexões Meta: papel insuficiente.
 *
 * @var \Smia\Support\Config $config
 * @var string|null $motivo
 */
ob_start();
?>
<div class="empty-state meta-forbidden">
  <div class="empty-illustration"><div class="empty-circle">◎</div></div>
  <div class="eyebrow muted-eyebrow">ACESSO RESTRITO</div>
  <h2>Conexões Meta são da gestão</h2>
  <p><?= e((string) ($motivo ?? 'Seu papel atual não permite ver nem alterar conexões Meta.')) ?></p>
  <a class="button primary" href="<?= e($config->url('/dashboard')) ?>">Voltar ao painel</a>
</div>
<?php
$content = ob_get_clean();
$title = 'Acesso restrito';
require __DIR__ . '/../layouts/app.php';
