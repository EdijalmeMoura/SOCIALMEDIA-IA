<?php
/**
 * Acesso restrito: o instalador exige SOCIALMEDIA_INSTALL_TOKEN.
 *
 * Esta tela também é a porta de entrada de quem abriu `/install` sem token
 * (link salvo, histórico, redirecionamento antigo). Por isso ela tem um campo
 * para colar o token — antes só mandava procurar no `.env`, sem saída.
 *
 * @var \Smia\Support\Config $config
 * @var string $csrf
 * @var string $next caminho interno a abrir depois do token (ex.: /install/run)
 * @var array<int,string> $errors
 */
$errors = $errors ?? [];
$next = $next ?? '/install';
ob_start();
?>
<div class="install-head">
  <div class="install-kicker install-kicker-fail">ACESSO RESTRITO</div>
  <h1>Esta instalação está protegida.</h1>
  <p>O servidor exige um token para abrir o instalador. Use o endereço completo com
    <code>?access_token=…</code> — o valor está em <code>SOCIALMEDIA_INSTALL_TOKEN</code>
    (arquivo <code>.env</code> ou painel do cPanel).</p>
</div>

<?php if (!empty($errors)): ?>
  <div class="install-errors" role="alert">
    <ul><?php foreach ($errors as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<section class="install-card">
  <div class="install-card-head">
    <div><h2>Já tem o token? Cole aqui</h2><p>Ele fica no <code>.env</code>, na linha <code>SOCIALMEDIA_INSTALL_TOKEN=</code>.</p></div>
  </div>
  <form method="post" action="<?= e($config->url('/install/access')) ?>" data-installer-form class="install-form">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label class="install-field span-12">Token de instalação
      <input type="password" name="access_token" autocomplete="off" data-no-autofill
             placeholder="cole o valor de SOCIALMEDIA_INSTALL_TOKEN" required>
    </label>
    <button class="button primary install-submit" type="submit">Entrar no instalador <span>→</span></button>
  </form>
</section>

<section class="install-card">
  <div class="install-card-head">
    <div><h2>Onde encontrar o token</h2><p>Ele não aparece no navegador e não pode ser adivinhado.</p></div>
    <span class="install-badge badge-off">403</span>
  </div>
  <ul class="check-list">
    <li class="check"><span class="check-icon">1</span><div><b>Arquivo .env</b><small>Procure a linha <code>SOCIALMEDIA_INSTALL_TOKEN=</code>.</small></div></li>
    <li class="check"><span class="check-icon">2</span><div><b>cPanel</b><small>Setup Python App / variáveis de ambiente da aplicação PHP.</small></div></li>
    <li class="check"><span class="check-icon">3</span><div><b>Depois de instalar</b><small>Apague a variável: o instalador se fecha sozinho (410). A conta já criada continua funcionando em <code>/login</code>.</small></div></li>
  </ul>
</section>
<?php
$content = ob_get_clean();
$title = 'Acesso restrito';
$step = 1;
$topNote = 'Token obrigatório';
require __DIR__ . '/../layouts/install.php';
