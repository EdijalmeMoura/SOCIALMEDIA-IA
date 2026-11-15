<?php
/**
 * Login (lado direito do layout de autenticação do produto).
 *
 * @var string|null $error
 * @var string $email
 * @var bool $installed
 * @var string $csrf
 * @var array<int,array{type:string,message:string}> $flashes
 * @var \Smia\Support\Config $config
 */
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Entrar · SocialMedia IA</title>
  <link rel="stylesheet" href="<?= e(smia_asset('/assets/app.css')) ?>">
</head>
<body class="auth-body">
<div class="auth-layout">
  <section class="auth-visual">
    <a class="brand light-brand" href="<?= e($config->url('/')) ?>">
      <span class="brand-mark"><svg viewBox="0 0 32 32"><path d="M5 21.5 12.4 14l5.3 5.3L27 9.8"/><path d="M19.8 9.8H27v7.1"/><circle cx="7" cy="25" r="2"/></svg></span>
      <span class="brand-copy">SocialMedia<span>IA</span><small>PLATAFORMA</small></span>
    </a>
    <div class="visual-main">
      <p class="eyebrow"><i></i> PLATAFORMA DE CONTEÚDO</p>
      <h1>Uma agência inteira<br><em>em um só lugar.</em></h1>
      <p>Clientes, planejamento, criação e aprovação do cliente no mesmo fluxo — do briefing ao post publicado.</p>
      <div class="visual-card">
        <div class="visual-card-top"><span>PRÉVIA DO FEED</span><span class="live-dot"></span></div>
        <div class="visual-card-art"><span class="mock-sun"></span><span class="mock-stamp">2026</span><span class="mock-label">CAMPANHA<br>VERÃO</span></div>
        <div class="visual-card-foot"><div class="mini-avatars"><i>A</i><i>B</i><i>C</i><i>+</i></div><span>3 aprovações hoje</span></div>
      </div>
    </div>
    <footer class="visual-footer">Instância local <span>·</span> dados no seu servidor</footer>
  </section>

  <section class="auth-panel">
    <span class="mobile-auth-brand">SocialMedia<span>IA</span></span>
    <div class="auth-form-wrap">
      <p class="auth-kicker">ACESSO DA EQUIPE</p>
      <h2>Entrar no workspace</h2>
      <p class="auth-subtitle">Use o e-mail e a senha do proprietário criados na instalação.</p>

      <?php if ($installed): ?>
        <div class="alert" style="background:#eaf8f2;border:1px solid #cdeee2;color:#197a5c">Instância criada com sucesso. Entre para começar.</div>
      <?php endif; ?>
      <?php foreach ($flashes as $flash): ?>
        <div class="alert <?= $flash['type'] === 'error' ? 'error-alert' : '' ?>"><?= e($flash['message']) ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert error-alert" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <form class="auth-form" method="post" action="<?= e($config->url('/login')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <label>E-mail
          <span class="input-wrap"><span>✉</span>
            <input type="email" name="email" required autocomplete="username" autofocus
                   value="<?= e($email) ?>" placeholder="voce@suaagencia.com">
          </span>
        </label>
        <label>Senha
          <span class="input-wrap"><span>⌘</span>
            <input type="password" name="password" required autocomplete="current-password" placeholder="sua senha">
            <button type="button" class="password-toggle" data-password-toggle aria-label="Mostrar senha">◉</button>
          </span>
        </label>
        <button class="button primary full-button" type="submit">Entrar <span>→</span></button>
      </form>

      <p class="auth-secure"><span>●</span> Sessão protegida · cookies HttpOnly · instalador encerrado</p>
    </div>
    <footer class="auth-panel-footer">SocialMedia IA · PHP <?= e(PHP_VERSION) ?></footer>
  </section>
</div>
<script src="<?= e(smia_asset('/assets/install.js')) ?>" defer></script>
</body>
</html>
