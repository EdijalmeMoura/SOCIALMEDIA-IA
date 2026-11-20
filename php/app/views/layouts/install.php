<?php
/**
 * Layout do instalador — mesma estrutura visual do produto Python.
 *
 * @var callable $content
 * @var string $title
 * @var string $step
 * @var \Smia\Support\Config $config
 */
$passos = [
    1 => ['Ambiente e workspace', 'Verificações e primeiro acesso'],
    2 => ['Revisão e confirmação', 'Segredos mascarados, sem escrita'],
    3 => ['Instância pronta', 'Registro da instalação e acesso liberado'],
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#303b8c">
  <title><?= e($title ?? 'Instalação') ?> · SocialMedia IA</title>
  <link rel="stylesheet" href="<?= e(smia_asset('/assets/app.css')) ?>">
  <link rel="stylesheet" href="<?= e(smia_asset('/assets/install.css')) ?>">
</head>
<body class="install-body">
<div class="install-shell">
  <aside class="install-rail">
    <a class="install-brand" href="<?= e($config->url('/install')) ?>">
      <span class="brand-mark"><svg viewBox="0 0 32 32"><path d="M5 21.5 12.4 14l5.3 5.3L27 9.8"/><path d="M19.8 9.8H27v7.1"/><circle cx="7" cy="25" r="2"/></svg></span>
      <span class="brand-copy">SocialMedia<span>IA</span><small>INSTALADOR</small></span>
    </a>
    <p class="rail-intro">Configure esta instância direto pelo navegador. Nenhum comando, nenhum segredo no navegador.</p>
    <ol class="install-steps">
      <?php foreach ($passos as $numero => [$titulo, $apoio]): ?>
        <?php $classe = $numero < ($step ?? 1) ? 'done' : ($numero === ($step ?? 1) ? 'current' : ''); ?>
        <li class="<?= e($classe) ?>">
          <span><?= $numero < ($step ?? 1) ? '✓' : str_pad((string) $numero, 2, '0', STR_PAD_LEFT) ?></span>
          <div><b><?= e($titulo) ?></b><small><?= e($apoio) ?></small></div>
        </li>
      <?php endforeach; ?>
    </ol>
    <div class="rail-foot">
      <b>Privacidade por padrão</b>
      <p>O instalador só existe enquanto a instância não tem conta. Senhas viram hash, chaves ficam no servidor e a página nunca é indexada.</p>
    </div>
  </aside>
  <main class="install-main">
    <header class="install-top">
      <span class="install-tag">INSTALAÇÃO PELO NAVEGADOR · PHP <?= e(PHP_VERSION) ?> · v<?= e(\Smia\Support\Config::VERSAO) ?></span>
      <span class="install-top-note"><?= e($topNote ?? 'Nada foi gravado ainda') ?></span>
    </header>
    <?php
    // Mensagens de uma requisição anterior (flash). O instalador controla o
    // próprio fluxo por sessão, então sem isto um aviso como "sessão expirada"
    // simplesmente desaparecia e a pessoa repetia o mesmo erro.
    $flashInstalador = $flashes ?? [];
    if ($flashInstalador === [] && isset($session)) {
        $flashInstalador = $session->takeFlashes();
    }
    if ($flashInstalador !== []): ?>
      <div class="install-flashes">
        <?php foreach ($flashInstalador as $aviso): ?>
          <div class="install-flash install-flash-<?= e((string) ($aviso['type'] ?? 'info')) ?>" role="status">
            <?= e((string) ($aviso['message'] ?? '')) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?= $content ?>
    <footer class="install-footer">SocialMedia IA · instalador local · nada é enviado a terceiros</footer>
  </main>
</div>
<script src="<?= e(smia_asset('/assets/install.js')) ?>" defer></script>
</body>
</html>
