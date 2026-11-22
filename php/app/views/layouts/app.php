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

// Seletor de agência: a lista vem do autenticador (fonte única da permissão).
$seletorAgencias = $agencias ?? [];
$agenciaAtiva = $agencia_ativa ?? [
    'id' => (int) $user['company_id'],
    'name' => (string) ($user['company_name'] ?? ''),
    'slug' => (string) ($user['company_slug'] ?? ''),
];
$podeTrocarAgencia = count($seletorAgencias) > 1;
$voltarParaAgencia = (string) ($current_path ?? '/dashboard');
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
  <?php if (in_array(($active ?? null), ['meta', 'schedule'], true)): ?>
    <link rel="stylesheet" href="<?= e(smia_asset('/assets/meta.css')) ?>">
  <?php endif; ?>
  <script src="<?= e(smia_asset('/assets/app.js')) ?>" defer></script>
</head>
<body>
<div class="app-shell">
  <div class="sidebar-scrim" id="sidebarScrim" aria-hidden="true"></div>
  <aside class="sidebar" id="sidebar" aria-label="Menu principal">
    <button class="sidebar-close" id="sidebarClose" type="button" aria-label="Fechar menu">✕</button>
    <a class="brand" href="<?= e($config->url('/dashboard')) ?>">
      <span class="brand-mark"><svg viewBox="0 0 32 32"><path d="M5 21.5 12.4 14l5.3 5.3L27 9.8"/><path d="M19.8 9.8H27v7.1"/><circle cx="7" cy="25" r="2"/></svg></span>
      <span class="brand-copy">SocialMedia<span>IA</span><small>PLATAFORMA</small></span>
    </a>
    <?php /* Cartão da agência. É sempre um <details>: com mais de uma agência
             liberada ele lista as outras; com uma só, mostra o "Workspace" e
             explica por que não há o que trocar. Usar o mesmo elemento nos
             dois casos evita o cartão mudar de forma conforme a conta. */ ?>
    <details class="workspace-switch workspace-picker" data-menu>
      <summary class="workspace-summary" aria-label="Agência em uso">
        <span class="workspace-icon"><?= e(mb_strtoupper(mb_substr((string) $agenciaAtiva['name'], 0, 1))) ?></span>
        <span class="workspace-text">
          <b><?= e((string) $agenciaAtiva['name']) ?></b>
          <small><?= $podeTrocarAgencia ? 'Trocar agência · ' . count($seletorAgencias) . ' disponíveis' : 'Workspace' ?></small>
        </span>
        <span class="chevron" aria-hidden="true">›</span>
      </summary>
      <?php if ($podeTrocarAgencia): ?>
        <ul class="workspace-list">
          <?php foreach ($seletorAgencias as $agenciaOpcao): ?>
            <li>
              <?php if ($agenciaOpcao['id'] === $agenciaAtiva['id']): ?>
                <span class="workspace-option is-current">
                  <span class="workspace-option-mark">✓</span>
                  <span><b><?= e($agenciaOpcao['name']) ?></b><small>em uso</small></span>
                </span>
              <?php else: ?>
                <form method="post" action="<?= e($config->url('/trocar-agencia')) ?>">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <input type="hidden" name="company_id" value="<?= (int) $agenciaOpcao['id'] ?>">
                  <input type="hidden" name="voltar" value="<?= e($voltarParaAgencia) ?>">
                  <button class="workspace-option" type="submit">
                    <span class="workspace-option-mark"><?= e(mb_strtoupper(mb_substr($agenciaOpcao['name'], 0, 1))) ?></span>
                    <span><b><?= e($agenciaOpcao['name']) ?></b><small>abrir esta agência</small></span>
                  </button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="workspace-only">
          Esta conta trabalha com uma agência só.
          <?php if ((int) ($user['is_super'] ?? 0) === 1): ?>
            Cadastre outra agência para poder alternar.
          <?php endif; ?>
        </p>
      <?php endif; ?>
    </details>
    <p class="nav-label">OPERAÇÃO</p>
    <nav class="nav-links">
      <a class="nav-link <?= ($active ?? null) === null ? 'active' : '' ?>" href="<?= e($config->url('/dashboard')) ?>"><span class="nav-icon">◧</span> Visão geral</a>
      <?php
        // O menu do dono só aparece para o dono. A trava real é no controller;
        // aqui é para não oferecer uma porta que a conta não pode abrir.
        $souDonoInstalacao = (int) ($user['is_super'] ?? 0) === 1;
        foreach (($modules ?? []) as $modulo):
            if ($modulo['slug'] === 'admin' && !$souDonoInstalacao) {
                continue;
            }
      ?>
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
        <?php /* O avatar abre o menu da conta: é o lugar onde as pessoas
                 procuram "sair". O botão de sair continua visível ao lado,
                 para quem prefere um clique. */ ?>
        <details class="account-menu" id="accountMenu" data-menu>
          <summary class="avatar avatar-button" aria-label="Menu da conta" title="Menu da conta">
            <?= e($iniciais !== '' ? $iniciais : 'U') ?>
          </summary>
          <div class="account-panel">
            <div class="account-head">
              <span class="avatar"><?= e($iniciais !== '' ? $iniciais : 'U') ?></span>
              <span class="user-info">
                <b><?= e((string) $user['name']) ?></b>
                <small><?= e((string) $user['email']) ?></small>
              </span>
            </div>
            <div class="account-company">
              <span class="account-company-dot"></span>
              <?= e((string) $agenciaAtiva['name']) ?>
              <small><?= e(ucfirst((string) $user['role'])) ?></small>
            </div>
            <a class="account-link" href="<?= e($config->url('/settings')) ?>?aba=conta">Minha conta</a>
            <a class="account-link" href="<?= e($config->url('/settings')) ?>?aba=ia">Configurações</a>
            <form method="post" action="<?= e($config->url('/logout')) ?>" class="account-logout">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <button class="button danger-outline" type="submit">Sair da conta</button>
            </form>
          </div>
        </details>
        <span class="user-info user-info-text">
          <b><?= e((string) $user['name']) ?></b>
          <small><?= e(ucfirst((string) $user['role'])) ?></small>
        </span>
        <form method="post" action="<?= e($config->url('/logout')) ?>" class="user-logout">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <button class="icon-button" type="submit" title="Sair" aria-label="Sair">⏻</button>
        </form>
      </div>
    </div>
  </aside>

  <div class="main-area">
    <header class="topbar">
      <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Abrir menu" aria-expanded="false" aria-controls="sidebar">☰</button>
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
