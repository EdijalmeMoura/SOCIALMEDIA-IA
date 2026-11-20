<?php
/**
 * Passo 2: revisão. Nada foi gravado — só o rascunho privado em disco.
 *
 * @var array{id:string,data:array<string,mixed>} $draft
 * @var string $csrf
 * @var bool $token_required
 * @var string $token
 * @var array<int,string> $errors
 * @var \Smia\Support\Config $config
 */
$dados = $draft['data'];
// Deriva da configuração, não de uma variável opcional: esquecer de passar
// `$token_required` fazia o formulário perder o token e a confirmação virar
// 403 "recusa:token_invalido".
$token_required = $token_required ?? ($config->installToken !== null);
$token = $token ?? '';
$errors = $errors ?? [];
ob_start();
?>
<div class="install-head">
  <div class="install-kicker">REVISÃO</div>
  <h1>Confira antes de criar a instância.</h1>
  <p>Nada foi gravado até agora. Segredos aparecem mascarados; o valor completo nunca volta para o navegador.</p>
</div>

<?php if (!empty($errors)): ?>
  <div class="install-errors" role="alert">
    <ul><?php foreach ($errors as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="review-grid">
  <section class="install-card">
    <div class="install-card-head">
      <div><h2>Workspace</h2><p>Acesso do proprietário criado nesta confirmação.</p></div>
      <span class="install-badge badge-ok">Pronto</span>
    </div>
    <dl class="review-list">
      <dt>Agência</dt><dd><?= e((string) $dados['agency_name']) ?></dd>
      <dt>Administrador</dt><dd><?= e((string) $dados['admin_name']) ?></dd>
      <dt>E-mail de acesso</dt><dd><?= e((string) $dados['admin_email']) ?></dd>
      <dt>Senha</dt><dd>definida e armazenada apenas com hash</dd>
      <dt>URL pública</dt><dd><?= e((string) $dados['public_origin']) ?></dd>
    </dl>
  </section>

  <section class="install-card">
    <div class="install-card-head">
      <div><h2>Segurança e arquivos</h2><p>Gerado no servidor durante a confirmação.</p></div>
    </div>
    <dl class="review-list">
      <dt>Arquivo de configuração</dt><dd><?= e($config->envFile) ?></dd>
      <dt>Permissão do arquivo</dt><dd>0600 (somente o dono do processo)</dd>
      <dt>Banco de dados</dt><dd><?= e($config->dbPath) ?></dd>
      <dt>Chave de sessão</dt><dd>SECRET_KEY nova e persistente</dd>
      <dt>Cookies</dt><dd><?= $config->cookieSecure ? 'Secure ativo' : 'Sem Secure (configure HTTPS)' ?></dd>
    </dl>
  </section>

  <section class="install-card">
    <div class="install-card-head">
      <div><h2>Inteligência artificial</h2><p><?= \Smia\Support\Config::env('OPENAI_API_KEY') ? 'Chave ativa para planejamento e estúdio.' : 'Não configurada: recursos de IA ficam desabilitados.' ?></p></div>
      <span class="install-badge <?= \Smia\Support\Config::env('OPENAI_API_KEY') ? 'badge-ok' : 'badge-off' ?>"><?= \Smia\Support\Config::env('OPENAI_API_KEY') ? 'Ativa' : 'Desligada' ?></span>
    </div>
    <?php if (!\Smia\Support\Config::env('OPENAI_API_KEY')): ?>
      <p class="review-empty">Você pode informar <code>OPENAI_API_KEY</code> depois no <code>.env</code> ou no painel do cPanel.</p>
    <?php endif; ?>
  </section>

  <section class="install-card">
    <div class="install-card-head">
      <div><h2>Integração Meta</h2><p><?= \Smia\Support\Config::env('META_APP_ID') ? 'App configurado.' : 'Desativada até configurar META_*.' ?></p></div>
      <span class="install-badge <?= \Smia\Support\Config::env('META_APP_ID') ? 'badge-ok' : 'badge-off' ?>"><?= \Smia\Support\Config::env('META_APP_ID') ? 'Pronta' : 'Pendente' ?></span>
    </div>
    <?php if (!\Smia\Support\Config::env('META_APP_ID')): ?>
      <p class="review-empty">Callback previsto: <code><?= e(rtrim((string) $dados['public_origin'], '/') . '/integrations/meta/callback') ?></code></p>
    <?php endif; ?>
  </section>
</div>

<section class="install-card install-commit">
  <h2>O que acontece ao confirmar</h2>
  <ul class="commit-list">
    <li><span>1</span><div><b>Configuração privada</b><small><code><?= e($config->envFile) ?></code> escrito com permissão 0600.</small></div></li>
    <li><span>2</span><div><b>Conta proprietária</b><small>Agência e usuário criados em uma única transação no SQLite.</small></div></li>
    <li><span>3</span><div><b>Instalador encerrado</b><small>A rota <code>/install</code> fecha e passa a responder 410 nesta instância.</small></div></li>
  </ul>
</section>

<div class="install-actions">
  <form method="post" action="<?= e($config->url('/install/cancel')) ?>" class="install-cancel-form">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <?php if ($token_required): ?><input type="hidden" name="access_token" value="<?= e($token) ?>"><?php endif; ?>
    <input type="hidden" name="draft" value="<?= e($draft['id']) ?>">
    <button class="button outline" type="submit">← Corrigir dados</button>
  </form>
  <form method="post" action="<?= e($config->url('/install/run')) ?>" data-installer-form>
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <?php if ($token_required): ?><input type="hidden" name="access_token" value="<?= e($token) ?>"><?php endif; ?>
    <input type="hidden" name="draft" value="<?= e($draft['id']) ?>">
    <button class="button primary install-submit" type="submit">Criar instância agora <span>→</span></button>
  </form>
</div>
<p class="install-caution">Esta ação grava configuração e cria a conta. Revise o e-mail e a senha antes de prosseguir.</p>
<?php
$content = ob_get_clean();
$title = 'Revisar instalação';
$step = 2;
$topNote = 'Nada foi gravado ainda';
require __DIR__ . '/../layouts/install.php';
