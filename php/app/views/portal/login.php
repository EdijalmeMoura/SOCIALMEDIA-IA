<?php
/**
 * Entrada do portal do cliente.
 *
 * @var string|null $erro
 * @var string $email
 */
ob_start();
?>
<div class="portal-login">
  <div class="portal-login-card">
    <div class="eyebrow">PORTAL DO CLIENTE</div>
    <h1>Revisar conteúdos</h1>
    <p class="page-sub">Entre com o acesso enviado pela sua agência para aprovar, pedir alterações ou comentar.</p>

    <?php if (!empty($erro)): ?>
      <div class="alert error-alert"><?= e($erro) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e($config->url('/portal/login')) ?>" class="portal-login-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <label class="field">E-mail
        <input type="email" name="email" required autocomplete="username" value="<?= e($email) ?>" placeholder="voce@empresa.com.br">
      </label>
      <label class="field">Senha
        <input type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
      </label>
      <button class="button primary" type="submit">Entrar no portal</button>
    </form>

    <p class="portal-help">Esqueceu a senha? Peça um novo acesso à agência — ela redefine em segundos.</p>
  </div>
  <p class="portal-back"><a href="<?= e($config->url('/login')) ?>">Sou da agência</a></p>
</div>
<?php
$content = ob_get_clean();
$title = 'Entrar';
$conta = null;
$csrf = $csrf ?? '';
require __DIR__ . '/../layouts/portal.php';
