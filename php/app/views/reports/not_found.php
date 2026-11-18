<?php
/**
 * 404 de relatórios (cliente de outra empresa no recorte).
 *
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="empty-state report-missing">
  <div class="empty-illustration"><div class="empty-circle">⌕</div></div>
  <div class="eyebrow muted-eyebrow">NÃO ENCONTRADO</div>
  <h2>Esse cliente não está neste workspace</h2>
  <p>O relatório não mostra dados de outra agência — escolha um cliente da sua lista.</p>
  <a class="button primary" href="<?= e($config->url('/reports')) ?>">Ver relatório</a>
</div>
<?php
$content = ob_get_clean();
$title = 'Cliente não encontrado';
require __DIR__ . '/../layouts/app.php';
