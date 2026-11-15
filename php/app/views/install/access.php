<?php
/**
 * Acesso restrito: o instalador exige SOCIALMEDIA_INSTALL_TOKEN.
 *
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="install-head">
  <div class="install-kicker install-kicker-fail">ACESSO RESTRITO</div>
  <h1>Esta instalação está protegida.</h1>
  <p>O servidor exige um token para abrir o instalador. Use o endereço completo com
    <code>?access_token=…</code> — o valor está em <code>SOCIALMEDIA_INSTALL_TOKEN</code>
    (arquivo <code>.env</code> ou painel do cPanel).</p>
</div>

<section class="install-card">
  <div class="install-card-head">
    <div><h2>Onde encontrar o token</h2><p>Ele não aparece no navegador e não pode ser adivinhado.</p></div>
    <span class="install-badge badge-off">403</span>
  </div>
  <ul class="check-list">
    <li class="check"><span class="check-icon">1</span><div><b>Arquivo .env</b><small>Procure a linha <code>SOCIALMEDIA_INSTALL_TOKEN=</code>.</small></div></li>
    <li class="check"><span class="check-icon">2</span><div><b>cPanel</b><small>Setup Python App / variáveis de ambiente da aplicação PHP.</small></div></li>
    <li class="check"><span class="check-icon">3</span><div><b>Depois de instalar</b><small>Apague a variável: o instalador se fecha sozinho (410).</small></div></li>
  </ul>
</section>
<?php
$content = ob_get_clean();
$title = 'Acesso restrito';
$step = 1;
$topNote = 'Token obrigatório';
require __DIR__ . '/../layouts/install.php';
