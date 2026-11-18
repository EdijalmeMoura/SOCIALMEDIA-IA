<?php
/**
 * Layout autenticado (sidebar + topbar), igual ao produto Python.
 *
 * @var callable $content
 * @var string $title
 * @var array<string,mixed> $user
 * @var string $csrf
 * @var array<int,array{type:string,message:string}> $flashes
 * @var array<int,array<string,mixed>> $modules
 * @var string|null $active slug do módulo ativo (para marcar o menu)
 * @var \Smia\Support\Config $config
 */
$iniciais = mb_strtoupper(mb_substr((string) $user['name'], 0, 1) . mb_substr((string) (explode(' ', (string) $user['name'])[1] ?? ''), 0, 1));
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title ?? 'Workspace') ?> · SocialMedia IA</title>
  <link rel="stylesheet" href="<?= e(smia_asset('/assets/app.css')) ?>">
  <?php if (($active ?? null) === 'planner'): ?>
    <link rel="stylesheet" href="<?= e(smia_asset('/assets/planner.css')) ?>">
  <?php endif; ?>
  <?php if (($active ?? null) === 'studio'): ?>
    <link rel="stylesheet" href="<?= e(smia_asset('/assets/studio.css')) ?>">
  <?php endif; ?>
  <?php if (($active ?? null) === 'approvals'): ?>
    <link rel="stylesheet" href="<?= e(smia_asset('/assets/approvals.css')) ?>">
  <?php endif; ?>
  <?php if (($active ?? null) === 'feed'): ?>
    <link rel="stylesheet" href="<?= e(smia_asset('/assets/feed.css')) ?>">
  <?php endif; ?>
  <?php if (($active ?? null) === 'reports'): ?>
    <link rel="stylesheet" href="<?= e(smia_asset('/assets/reports.css')) ?>">
  <?php endif; ?>
  <script src="<?= e(smia_asset('/assets/app.js')) ?>" defer></script>
</head>
<body>
<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= e($config->url('/dashboard')) ?>">
      <span class="brand-mark"><svg viewBox="0 0 32 32"><path d="M5 21.5 12.4 14l5.3 5.3L27 9.8"/><path d="M19.8 9.8H27v7.1"/><circle cx="7" cy="25" r="2"/></svg></span>
      <span class="brand-copy">SocialMedia<span>IA</span><small>PLATAFORMA</small></span>
    </a>
    <div class="workspace-switch">
      <span class="workspace-icon"><?= e(mb_strtoupper(mb_substr((string) $user['company_name'], 0, 1))) ?></span>
      <span><?= e((string) $user['company_name']) ?><small>Workspace</small></span>
      <span class="chevron">›</span>
    </div>
    <p class="nav-label">OPERAÇÃO</p>
    <nav class="nav-links">
      <a class="nav-link <?= ($active ?? null) === null ? 'active' : '' ?>" href="<?= e($config->url('/dashboard')) ?>"><span class="nav-icon">◧</span> Visão geral</a>
      <?php foreach (($modules ?? []) as $modulo): ?>
        <?php if ($modulo['ready'] && $modulo['path'] !== null): ?>
          <a class="nav-link <?= ($active ?? null) === $modulo['slug'] ? 'active' : '' ?>" href="<?= e($config->url($modulo['path'])) ?>">
            <span class="nav-icon"><?= e($modulo['icon']) ?></span> <?= e($modulo['name']) ?>
          </a>
        <?php else: ?>
          <span class="nav-link muted-link is-soon">
            <span class="nav-icon">◻</span> <?= e($modulo['name']) ?>
            <span class="soon">EM BREVE</span>
          </span>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-bottom">
      <div class="sidebar-help">
        <span class="help-orb">✦</span>
        <b>Versão PHP</b>
        <p>Port em andamento. Os módulos entram um a um, com o mesmo banco e as mesmas regras.</p>
        <a href="<?= e($config->url('/dashboard')) ?>">Ver estado <span>→</span></a>
      </div>
      <div class="user-card">
        <span class="avatar"><?= e($iniciais !== '' ? $iniciais : 'U') ?></span>
        <span class="user-info"><b><?= e((string) $user['name']) ?></b><small><?= e(ucfirst((string) $user['role'])) ?></small></span>
        <form method="post" action="<?= e($config->url('/logout')) ?>">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <button class="icon-button" type="submit" title="Sair" aria-label="Sair">⏻</button>
        </form>
      </div>
    </div>
  </aside>

  <div class="main-area">
    <header class="topbar">
      <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Menu">☰</button>
      <div class="breadcrumb"><a href="<?= e($config->url('/dashboard')) ?>">Workspace</a><span>/</span><?= e($title ?? 'Visão geral') ?></div>
      <div class="top-actions">
        <span class="status-pill"><i></i> Instância local</span>
        <span class="top-avatar"><?= e($iniciais !== '' ? $iniciais : 'U') ?></span>
      </div>
    </header>
    <main class="page-content">
      <?php foreach ($flashes as $flash): ?>
        <div class="alert <?= $flash['type'] === 'error' ? 'error-alert' : '' ?>"><?= e($flash['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>
</body>
</html>
