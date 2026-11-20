<?php
/**
 * Passo 3 do assistente: "Instância pronta".
 *
 * Duas entradas, mesma tela:
 *  - `$concluida` = true: acabou de instalar (200). Fim feliz do assistente.
 *  - `$concluida` = false: alguém reabriu /install numa instância já instalada
 *    (410). O texto explica por que não há mais o que instalar.
 *
 * @var array<string,mixed>|null $record
 * @var \Smia\Support\Config $config
 * @var bool $concluida
 */
$concluida = $concluida ?? false;
ob_start();
?>
<div class="install-head">
  <div class="install-kicker install-kicker-ok">INSTALAÇÃO CONCLUÍDA</div>
  <?php if ($concluida): ?>
    <h1>Instância pronta.</h1>
    <p>Acesso liberado e instalador encerrado. A conta do proprietário já existe —
      entre com o e-mail e a senha que você acabou de definir.</p>
  <?php else: ?>
    <h1>Esta instância está protegida.</h1>
    <p>O instalador existe apenas enquanto não há conta. Para continuar, entre com o
      e-mail e a senha do proprietário.</p>
  <?php endif; ?>
</div>

<section class="install-card">
  <div class="install-card-head">
    <div><h2>Registro da instalação</h2><p>Guardado no banco local desta instância.</p></div>
    <span class="install-badge badge-ok"><?= $concluida ? 'OK' : '410' ?></span>
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
$title = $concluida ? 'Instância pronta' : 'Instalador encerrado';
$step = 3;
$topNote = $concluida ? 'Acesso liberado e instalador encerrado' : 'Instalador já encerrado';
require __DIR__ . '/../layouts/install.php';
