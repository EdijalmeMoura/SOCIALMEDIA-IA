<?php
/** @var string $motivo */
$motivo = $motivo ?? 'Esta área é restrita ao proprietário da instalação.';
ob_start();
?>
<div class="page-head">
  <div>
    <div class="eyebrow">ACESSO RESTRITO</div>
    <h1>Área do dono</h1>
    <p class="page-sub"><?= e($motivo) ?></p>
  </div>
</div>
<section class="panel">
  <p>O que você pode fazer daqui:</p>
  <ul class="plain-list">
    <li><a href="<?= e($config->url('/settings')) ?>">Configurações</a> — chave de IA, modelos e equipe da sua agência.</li>
    <li><a href="<?= e($config->url('/dashboard')) ?>">Visão geral</a> — o dia a dia do seu workspace.</li>
  </ul>
  <p class="muted">Se você precisa gerenciar outras agências, peça ao dono da instalação para marcar a sua conta como “dono da instalação”.</p>
</section>
<?php
$content = ob_get_clean();
$title = 'Acesso restrito';
require __DIR__ . '/../layouts/app.php';
