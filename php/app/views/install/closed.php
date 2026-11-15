<?php
/**
 * Instalador encerrado (410): a instância já tem conta.
 *
 * @var array<string,mixed>|null $record
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="install-head">
  <div class="install-kicker install-kicker-ok">INSTALAÇÃO CONCLUÍDA</div>
  <h1>Esta instância está protegida.</h1>
  <p>O instalador existe apenas enquanto não há conta. Para continuar, entre com o
    e-mail e a senha do proprietário.</p>
</div>

<section class="install-card">
  <div class="install-card-head">
    <div><h2>Registro da instalação</h2><p>Guardado no banco local desta instância.</p></div>
    <span class="install-badge badge-ok">410</span>
  </div>
  <dl class="review-list">
    <?php if ($record): ?>
      <dt>Agência</dt><dd><?= e((string) $record['agency_name']) ?></dd>
      <dt>Proprietário</dt><dd><?= e((string) $record['owner_email']) ?></dd>
      <dt>Instalada em</dt><dd><?= e((string) $record['installed_at']) ?> UTC</dd>
      <dt>URL pública</dt><dd><?= e((string) $record['public_origin']) ?></dd>
    <?php else: ?>
      <dt>Estado</dt><dd>Conta existente, criada fora do instalador.</dd>
    <?php endif; ?>
  </dl>
</section>

<div class="install-actions">
  <a class="button primary" href="<?= e($config->url('/login')) ?>">Ir para o login <span>→</span></a>
</div>
<?php
$content = ob_get_clean();
$title = 'Instalador encerrado';
$step = 3;
$topNote = 'Acesso liberado';
require __DIR__ . '/../layouts/install.php';
