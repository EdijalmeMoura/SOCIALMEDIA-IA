<?php
/**
 * 404 do módulo: cliente inexistente ou de outro workspace.
 *
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="empty-state">
  <div class="empty-illustration"><div class="empty-circle">⌕</div></div>
  <div class="eyebrow muted-eyebrow">NÃO ENCONTRADO</div>
  <h2>Esse cliente não existe neste workspace</h2>
  <p>O perfil pode ter sido removido ou pertence a outra agência.</p>
  <a class="button primary" href="<?= e($config->url('/clients')) ?>">Ver todos os clientes</a>
</div>
<?php
$content = ob_get_clean();
$title = 'Cliente não encontrado';
require __DIR__ . '/../layouts/app.php';
