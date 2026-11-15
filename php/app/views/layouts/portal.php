<?php
/**
 * Layout do portal do cliente.
 *
 * Deliberadamente separado do layout da agência: quem entra aqui é o cliente
 * e não deve ver menu, workspace nem nome de outros clientes.
 *
 * @var callable $content
 * @var string $title
 * @var \\Smia\\Support\\Config $config
 * @var array<string,mixed>|null $conta
 * @var string|null $csrf
 */
ob_start();
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title ?? 'Portal do cliente') ?> · SocialMedia IA</title>
  <link rel="stylesheet" href="<?= e(smia_asset('/assets/app.css')) ?>">
  <link rel="stylesheet" href="<?= e(smia_asset('/assets/approvals.css')) ?>">
  <script src="<?= e(smia_asset('/assets/app.js')) ?>" defer></script>
</head>
<body class="portal-body">
<header class="portal-bar">
  <a class="portal-brand" href="<?= e($config->url('/portal/approvals')) ?>">
    <span class="brand-mark"><svg viewBox="0 0 32 32"><path d="M5 21.5 12.4 14l5.3 5.3L27 9.8"/><path d="M19.8 9.8H27v7.1"/><circle cx="7" cy="25" r="2"/></svg></span>
    <span class="portal-brand-copy">SocialMedia<span>IA</span><small>PORTAL DO CLIENTE</small></span>
  </a>
  <?php if (!empty($conta)): ?>
    <div class="portal-account">
      <span class="portal-client"><?= e(trim((string) ($conta['trade_name'] ?? '')) ?: (string) $conta['company_name']) ?></span>
      <span class="portal-user"><?= e((string) $conta['name']) ?></span>
      <form method="post" action="<?= e($config->url('/portal/logout')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e((string) ($csrf ?? '')) ?>">
        <button class="button outline tiny" type="submit">Sair</button>
      </form>
    </div>
  <?php endif; ?>
</header>
<main class="portal-content">
<?= $content ?>
</main>
<footer class="portal-footer">
  <span>Revisão de conteúdo<?= !empty($conta) ? ' · ' . e((string) $conta['company_name']) : '' ?></span>
  <span>Aprovações ficam registradas com data e autor.</span>
</footer>
</body>
</html>
