<?php
/**
 * Listagem de clientes: grade de cartões com identidade visual de cada marca.
 *
 * @var array<int,array<string,mixed>> $clientes
 * @var array{total:int,ativos:int} $resumo
 * @var bool $pode_editar
 * @var bool $pode_excluir
 * @var string $csrf
 * @var array<int,array{type:string,message:string}> $flashes
 * @var \Smia\Support\Config $config
 */
ob_start();
?>
<div class="page-title-row">
  <div>
    <div class="eyebrow muted-eyebrow">SEU PORTFÓLIO</div>
    <h1>Clientes <span class="title-count"><?= count($clientes) ?></span></h1>
    <p class="page-subtitle">Cada marca, com sua identidade e informações sempre à mão.</p>
  </div>
  <?php if ($pode_editar): ?>
    <a class="button primary" href="<?= e($config->url('/clients/new')) ?>">＋ <span>Novo cliente</span></a>
  <?php endif; ?>
</div>

<?php if ($resumo['total'] > 0): ?>
  <div class="list-toolbar">
    <div class="search-box">
      <span>⌕</span>
      <input id="clientSearch" type="search" placeholder="Buscar por nome ou segmento...">
    </div>
    <div class="toolbar-right">
      <span><?= (int) $resumo['ativos'] ?> ativo(s) · <?= (int) $resumo['total'] ?> no total</span>
      <span class="view-toggle">▦</span>
    </div>
  </div>

  <div class="client-grid" id="clientGrid">
    <?php foreach ($clientes as $cliente): ?>
      <?php
      $cor = \Smia\Clients\ClientRepository::corValida((string) ($cliente['primary_color'] ?? ''))
          ? (string) $cliente['primary_color']
          : '#3158d8';
      $busca = mb_strtolower(trim((string) $cliente['company_name'] . ' ' . (string) ($cliente['trade_name'] ?? '') . ' ' . (string) ($cliente['segment'] ?? '')));
      $logo = (string) ($cliente['logo_path'] ?? '');
      ?>
      <a class="client-card" href="<?= e($config->url('/clients/' . $cliente['id'])) ?>" data-search="<?= e($busca) ?>">
        <div class="client-card-cover" style="--client-color:<?= e($cor) ?>">
          <span class="cover-shape shape-a"></span>
          <span class="cover-shape shape-b"></span>
          <span class="cover-label">BRAND PROFILE</span>
          <div class="client-logo">
            <?php if ($logo !== ''): ?>
              <img src="<?= e($config->url('/clients/file?file=' . rawurlencode($logo))) ?>" alt="Logo <?= e((string) $cliente['company_name']) ?>">
            <?php else: ?>
              <?= e(mb_strtoupper(mb_substr((string) $cliente['company_name'], 0, 1))) ?>
            <?php endif; ?>
          </div>
          <span class="active-tag"><?= $cliente['status'] === 'active' ? 'Ativo' : 'Inativo' ?></span>
        </div>
        <div class="client-card-content">
          <div class="card-name-row">
            <div>
              <h3><?= e((string) ($cliente['trade_name'] ?: $cliente['company_name'])) ?></h3>
              <span><?= e((string) ($cliente['trade_name'] ? $cliente['company_name'] : ($cliente['segment'] ?: 'Empresa'))) ?></span>
            </div>
            <span class="row-arrow">↗</span>
          </div>
          <div class="card-divider"></div>
          <div class="card-meta">
            <span class="meta-dot"></span><?= e((string) ($cliente['segment'] ?: 'Segmento não informado')) ?>
            <span class="card-date"><?= e(mb_substr((string) $cliente['created_at'], 0, 10)) ?></span>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <div id="noSearchResults" class="empty-state" hidden>
    <span class="empty-icon">⌕</span>
    <b>Nenhum resultado encontrado</b>
    <p>Tente buscar por outro nome ou segmento.</p>
  </div>
<?php else: ?>
  <div class="empty-state clients-empty">
    <div class="empty-illustration">
      <div class="empty-circle">♧</div>
      <span class="empty-star">✦</span>
      <span class="empty-spark">✳</span>
    </div>
    <div class="eyebrow muted-eyebrow">UM BOM COMEÇO</div>
    <h2>Marcas incríveis começam<br>com uma boa parceria.</h2>
    <p>Cadastre seu primeiro cliente e construa um perfil completo com informações do negócio e identidade visual.</p>
    <?php if ($pode_editar): ?>
      <a class="button primary" href="<?= e($config->url('/clients/new')) ?>">＋ <span>Cadastrar primeiro cliente</span></a>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Clientes';
require __DIR__ . '/../layouts/app.php';
