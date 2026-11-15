<?php
/**
 * Visão do workspace: estado real da instância e fila de módulos.
 *
 * @var array<string,mixed> $user
 * @var array<string,mixed>|null $installation
 * @var int $team_count
 * @var array<int,array<string,string>> $audit
 * @var array<int,array<string,mixed>> $modules
 * @var string $csrf
 * @var array<int,array{type:string,message:string}> $flashes
 * @var \Smia\Support\Config $config
 */
$prontos = count(array_filter($modules, static fn ($m) => $m['ready']));
ob_start();
?>
<div class="welcome-row">
  <div>
    <p class="eyebrow"><span class="sparkle">✦</span> VISÃO DO WORKSPACE</p>
    <h1>Olá, <?= e(explode(' ', (string) $user['name'])[0]) ?> <span class="wave">◡</span></h1>
    <p class="page-subtitle">Instância em PHP <?= e(PHP_VERSION) ?> — dados no seu próprio servidor.</p>
  </div>
</div>

<div class="stats-grid" style="margin-top:24px">
  <article class="stat-card">
    <div class="stat-head"><span class="stat-icon indigo">◧</span><span class="stat-caption">AGÊNCIA</span></div>
    <div class="stat-value"><?= e((string) $user['company_name']) ?></div>
    <div class="stat-bottom"><span class="stat-note">proprietário: <?= e((string) $user['email']) ?></span></div>
  </article>
  <article class="stat-card">
    <div class="stat-head"><span class="stat-icon mint">◔</span><span class="stat-caption">EQUIPE</span></div>
    <div class="stat-value"><?= (int) $team_count ?> <small>usuário(s)</small></div>
    <div class="stat-bottom"><span class="stat-note">convites entram no módulo de configurações</span></div>
  </article>
  <article class="stat-card">
    <div class="stat-head"><span class="stat-icon amber">⌂</span><span class="stat-caption">INSTALADA EM</span></div>
    <div class="stat-value" style="font-size:15px"><?= e((string) ($installation['installed_at'] ?? '—')) ?></div>
    <div class="stat-bottom"><span class="stat-note">UTC · instalador fechado (410)</span></div>
  </article>
  <article class="stat-card">
    <div class="stat-head"><span class="stat-icon coral">◆</span><span class="stat-caption">MÓDULOS</span></div>
    <div class="stat-value"><?= (int) $prontos ?><small>/<?= count($modules) ?></small></div>
    <div class="stat-bottom"><span class="stat-note">port do Python em andamento</span></div>
  </article>
</div>

<div class="lower-grid">
  <section class="panel">
    <div class="panel-heading">
      <div><h3>Módulos do produto</h3><p>Mesma estrutura de dados do produto Python; cada um entra em uma entrega.</p></div>
    </div>
    <ul class="check-list" style="margin-top:14px">
      <?php foreach ($modules as $modulo): ?>
        <li class="check">
          <span class="check-icon" style="<?= $modulo['ready'] ? '' : 'background:#f1f2f7;color:#9aa0b0' ?>"><?= $modulo['ready'] ? '✓' : '·' ?></span>
          <div><b><?= e($modulo['name']) ?></b><small><?= e($modulo['hint']) ?></small></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="panel">
    <div class="panel-heading">
      <div><h3>Últimos eventos</h3><p>Registro local de auditoria desta instância.</p></div>
    </div>
    <?php if (empty($audit)): ?>
      <div class="empty-state"><p class="page-subtitle">Nenhum evento registrado ainda.</p></div>
    <?php else: ?>
      <ul class="check-list" style="margin-top:14px">
        <?php foreach ($audit as $evento): ?>
          <li class="check">
            <span class="check-icon">•</span>
            <div><b><?= e((string) $evento['action']) ?></b>
              <small><?= e((string) ($evento['detail'] ?? '')) ?> · <?= e((string) $evento['created_at']) ?> UTC</small></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
<?php
$content = ob_get_clean();
$title = 'Visão geral';
require __DIR__ . '/../layouts/app.php';
