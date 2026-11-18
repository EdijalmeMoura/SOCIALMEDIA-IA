<?php
/**
 * 404 das conexões e tentativas Meta.
 *
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="empty-state meta-forbidden">
  <div class="empty-illustration"><div class="empty-circle">◎</div></div>
  <div class="eyebrow muted-eyebrow">NÃO ENCONTRADO</div>
  <h2>Esse registro não está nesta agência</h2>
  <p>Conexões e tentativas de publicação são isoladas por workspace.</p>
  <a class="button primary" href="<?= e($config->url('/meta')) ?>">Abrir Conexões Meta</a>
</div>
<?php
$content = ob_get_clean();
$title = 'Registro não encontrado';
require __DIR__ . '/../layouts/app.php';
