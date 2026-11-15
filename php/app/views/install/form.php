<?php
/**
 * Passo 1: ambiente detectado + dados da agência e do proprietário.
 *
 * Classes seguem static/install.css (compartilhado com a versão Python):
 * check/check-icon, field-grid, install-field, span-6/span-12.
 *
 * @var array<int,array{label:string,status:string,detail:string}> $checks
 * @var array<int,string> $errors
 * @var array<string,mixed> $old
 * @var string $origin
 * @var string $csrf
 * @var bool $token_required
 * @var string $token
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="install-head">
  <div class="install-kicker">AMBIENTE DETECTADO</div>
  <h1>Vamos criar a sua instância.</h1>
  <p>Confira as verificações abaixo e informe o primeiro acesso. Nada é gravado antes da confirmação final.</p>
</div>

<section class="install-card">
  <div class="install-card-head">
    <div><h2>Verificações do servidor</h2><p>Feitas agora, neste processo PHP.</p></div>
    <span class="install-badge">PHP <?= e(PHP_VERSION) ?></span>
  </div>
  <ul class="check-list">
    <?php foreach ($checks as $check): ?>
      <li class="check check-<?= e($check['status']) ?>">
        <span class="check-icon"><?= $check['status'] === 'ok' ? '✓' : ($check['status'] === 'warn' ? '!' : '×') ?></span>
        <div><b><?= e($check['label']) ?></b><small><?= e($check['detail']) ?></small></div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>

<?php if (!empty($errors)): ?>
  <div class="install-errors" role="alert">
    <ul>
      <?php foreach ($errors as $erro): ?>
        <li><?= e($erro) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form class="install-form" method="post" action="<?= e($config->url('/install/validate')) ?>" data-installer-form>
  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
  <?php if ($token_required): ?>
    <input type="hidden" name="access_token" value="<?= e($token) ?>">
  <?php endif; ?>

  <section class="install-card">
    <div class="install-card-head">
      <div>
        <h2><span class="card-step">01</span> Workspace e proprietário</h2>
        <p>Esta conta será a proprietária da agência e poderá convidar o restante da equipe.</p>
      </div>
    </div>
    <div class="field-grid">
      <label class="install-field span-6">Nome da agência <em>*</em>
        <input name="agency_name" required maxlength="120" autocomplete="organization"
               value="<?= e((string) ($old['agency_name'] ?? '')) ?>" placeholder="Ex.: Estúdio Aurora">
      </label>
      <label class="install-field span-6">Seu nome <em>*</em>
        <input name="admin_name" required maxlength="100" autocomplete="name"
               value="<?= e((string) ($old['admin_name'] ?? '')) ?>" placeholder="Ex.: Ana Souza">
      </label>
      <label class="install-field span-12">E-mail de acesso <em>*</em>
        <input type="email" name="admin_email" required maxlength="160" autocomplete="email"
               value="<?= e((string) ($old['admin_email'] ?? '')) ?>" placeholder="voce@suaagencia.com">
      </label>
      <label class="install-field span-6">Senha <em>*</em>
        <span class="password-wrap"><input type="password" name="admin_password" required minlength="8"
              autocomplete="new-password" placeholder="8 caracteres ou mais">
          <button type="button" class="password-toggle" data-password-toggle aria-label="Mostrar senha">◉</button></span>
      </label>
      <label class="install-field span-6">Confirmar senha <em>*</em>
        <span class="password-wrap"><input type="password" name="admin_password_confirm" required minlength="8"
              autocomplete="new-password" placeholder="Repita a senha">
          <button type="button" class="password-toggle" data-password-toggle aria-label="Mostrar senha">◉</button></span>
      </label>
      <label class="install-field span-12">URL pública da instância <em>*</em>
        <input type="url" name="public_origin" required maxlength="200"
               value="<?= e((string) ($old['public_origin'] ?? $origin)) ?>">
        <small>Inclua a subpasta quando existir — é ela que gera o callback da Meta.</small>
      </label>
    </div>
  </section>

  <div class="install-actions">
    <span class="install-caution">A senha só é gravada como hash (<?= e(defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt') ?>). Nada é enviado a terceiros.</span>
    <button class="button primary install-submit" type="submit">Revisar instalação <span>→</span></button>
  </div>
</form>
<?php
$content = ob_get_clean();
$title = 'Configurar instância';
$step = 1;
require __DIR__ . '/../layouts/install.php';
