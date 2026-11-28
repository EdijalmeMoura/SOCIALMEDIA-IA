<?php
/**
 * Visão do workspace: estado real da instância e fila de módulos.
 *
 * @var array<string,mixed> $user
 * @var array<string,mixed>|null $installation
 * @var int $team_count
 * @var array<int,array<string,string>> $audit
 * @var array<int,array<string,mixed>> $modules
 * @var int $client_total
 * @var array{total:int,ativos:int} $client_summary
 * @var array<int,array<string,mixed>> $recent_clients
 * @var string $csrf
 * @var array<int,array{type:string,message:string}> $flashes
 * @var \Smia\Support\Config $config
 * @var array{posts_futuros:int, artes_em_rascunho:int, aprovacoes_pendentes:int, aprovacoes_paradas:int, aprovadas_sem_agenda:int, agenda_ativa:int, agenda_atrasada:int, publicadas:int} $numeros
 * @var array<int,array<string,mixed>> $proximas
 * @var array<int,array<string,mixed>> $pendentes
 * @var array<int,array<string,mixed>> $agenda
 * @var array<int,array{slug:string,name:string,path:string,icon:string,count:int,alerta:bool}> $atalhos
 */
// A área do dono só existe para quem é dono: o card de módulos não pode
// anunciar uma porta que a conta não abre. A trava real é no controller.
$modulosVisiveis = array_values(array_filter(
    $modules,
    static fn (array $m): bool => $m['slug'] !== 'admin' || (int) ($user['is_super'] ?? 0) === 1
));
$prontos = count(array_filter($modulosVisiveis, static fn ($m) => $m['ready']));
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
  <a class="stat-card stat-link" href="<?= e($config->url('/planner')) ?>">
    <div class="stat-head"><span class="stat-icon indigo">▤</span><span class="stat-caption">NO PLANEJAMENTO</span></div>
    <div class="stat-value"><?= (int) $numeros['posts_futuros'] ?> <small>post(s) à frente</small></div>
    <div class="stat-bottom"><span class="stat-note">publicações com data de hoje em diante</span></div>
  </a>
  <a class="stat-card stat-link" href="<?= e($config->url('/approvals')) ?>">
    <div class="stat-head"><span class="stat-icon <?= $numeros['aprovacoes_paradas'] > 0 ? 'coral' : 'mint' ?>">◷</span><span class="stat-caption">COM O CLIENTE</span></div>
    <div class="stat-value"><?= (int) $numeros['aprovacoes_pendentes'] ?> <small>revisão(ões)</small></div>
    <div class="stat-bottom">
      <?php if ($numeros['aprovacoes_paradas'] > 0): ?>
        <span class="stat-note is-late"><?= (int) $numeros['aprovacoes_paradas'] ?> parada(s) há mais de 3 dias</span>
      <?php else: ?>
        <span class="stat-note">nenhuma parada há mais de 3 dias</span>
      <?php endif; ?>
    </div>
  </a>
  <a class="stat-card stat-link" href="<?= e($config->url('/schedule')) ?>">
    <div class="stat-head"><span class="stat-icon <?= $numeros['agenda_atrasada'] > 0 ? 'coral' : 'amber' ?>">◔</span><span class="stat-caption">NA AGENDA</span></div>
    <div class="stat-value"><?= (int) $numeros['agenda_ativa'] ?> <small>horário(s)</small></div>
    <div class="stat-bottom">
      <?php if ($numeros['agenda_atrasada'] > 0): ?>
        <span class="stat-note is-late"><?= (int) $numeros['agenda_atrasada'] ?> com horário vencido</span>
      <?php else: ?>
        <span class="stat-note"><?= (int) $numeros['publicadas'] ?> publicada(s) via Meta</span>
      <?php endif; ?>
    </div>
  </a>
  <a class="stat-card stat-link" href="<?= e($config->url('/studio')) ?>">
    <div class="stat-head"><span class="stat-icon mint">✦</span><span class="stat-caption">NO ESTÚDIO</span></div>
    <div class="stat-value"><?= (int) $numeros['artes_em_rascunho'] ?> <small>arte(s)</small></div>
    <div class="stat-bottom"><span class="stat-note">rascunhos ainda não publicados</span></div>
  </a>
</div>

<?php
// "O que pede atenção": só entra o que está de fato travado. Sem nada
// travado, o bloco inteiro desaparece — dashboard não é mural de zeros.
$alertas = [];
if ($numeros['agenda_atrasada'] > 0) {
    $alertas[] = [
        'titulo' => $numeros['agenda_atrasada'] . ' ' . ($numeros['agenda_atrasada'] === 1 ? 'horário vencido' : 'horários vencidos') . ' na agenda',
        'texto' => 'O horário passou e ninguém decidiu. Publicar via Meta ou cancelar e reagendar.',
        'url' => $config->url('/schedule?status=ativos'),
        'acao' => 'Resolver na agenda',
    ];
}
if ($numeros['aprovacoes_paradas'] > 0) {
    $alertas[] = [
        'titulo' => $numeros['aprovacoes_paradas'] . ' ' . ($numeros['aprovacoes_paradas'] === 1 ? 'revisão parada' : 'revisões paradas') . ' com o cliente',
        'texto' => 'Enviada há mais de 3 dias sem resposta. Vale um lembrete antes de virar atraso.',
        'url' => $config->url('/approvals'),
        'acao' => 'Ver revisões',
    ];
}
if ($client_total === 0) {
    $alertas[] = [
        'titulo' => 'Nenhuma marca cadastrada',
        'texto' => 'O produto começa pela identidade do cliente: sem ela, a IA não escreve no tom certo.',
        'url' => $config->url('/clients/new'),
        'acao' => 'Cadastrar cliente',
    ];
}
?>
<?php if ($alertas !== []): ?>
  <section class="panel attention-panel">
    <div class="panel-heading">
      <div><h3>Pede atenção agora</h3><p>Só o que está travado — o resto da operação segue.</p></div>
    </div>
    <ul class="attention-list">
      <?php foreach ($alertas as $alerta): ?>
        <li class="attention-item">
          <span class="attention-dot" aria-hidden="true">!</span>
          <div>
            <b><?= e((string) $alerta['titulo']) ?></b>
            <small><?= e((string) $alerta['texto']) ?></small>
          </div>
          <a class="button outline small-button" href="<?= e((string) $alerta['url']) ?>"><?= e((string) $alerta['acao']) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<div class="dashboard-grid">
  <section class="panel">
    <div class="panel-heading">
      <div><h3>Próximas publicações</h3><p>O que o planejamento tem pela frente.</p></div>
      <a class="text-link" href="<?= e($config->url('/planner')) ?>">Abrir calendário <span>→</span></a>
    </div>
    <?php if ($proximas === []): ?>
      <div class="empty-state small-empty">
        <div class="empty-icon">▤</div>
        <b>Nada planejado à frente</b>
        <p>Gere um planejamento a partir do objetivo do cliente.</p>
      </div>
    <?php else: ?>
      <ul class="dashboard-list">
        <?php foreach ($proximas as $post): ?>
          <li>
            <div class="dashboard-list-main">
              <b><a href="<?= e($config->url('/planner/posts/' . (int) $post['id'] . '/edit')) ?>"><?= e((string) $post['theme']) ?></a></b>
              <small><?= e((string) $post['cliente']) ?> · <?= e((string) $post['network']) ?></small>
            </div>
            <?php if ((int) ($post['creative_id'] ?? 0) > 0): ?>
              <span class="dashboard-tag">com arte</span>
            <?php endif; ?>
            <time datetime="<?= e((string) $post['publication_date']) ?>"><?= e(date('d/m', strtotime((string) $post['publication_date']))) ?></time>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-heading">
      <div><h3>Na fila da agenda</h3><p>Na ordem em que vai acontecer.</p></div>
      <a class="text-link" href="<?= e($config->url('/schedule')) ?>">Abrir agenda <span>→</span></a>
    </div>
    <?php if ($agenda === []): ?>
      <div class="empty-state small-empty">
        <div class="empty-icon">◔</div>
        <b>Nenhum horário na fila</b>
        <p>
          <?php $prontosParaAgendar = (int) $numeros['aprovadas_sem_agenda']; ?>
          <?php if ($prontosParaAgendar > 0): ?>
            <?= $prontosParaAgendar ?> <?= $prontosParaAgendar === 1 ? 'conteúdo aprovado ainda não tem' : 'conteúdos aprovados ainda não têm' ?> horário marcado.
          <?php else: ?>
            Depois da aprovação do cliente, o conteúdo entra na agenda.
          <?php endif; ?>
        </p>
      </div>
    <?php else: ?>
      <ul class="dashboard-list">
        <?php foreach ($agenda as $linha): ?>
          <li>
            <div class="dashboard-list-main">
              <b><?= e((string) $linha['title']) ?></b>
              <small><?= e((string) $linha['cliente']) ?> · <?= e((string) $linha['network']) ?></small>
            </div>
            <span class="dashboard-tag <?= $linha['vencido'] ? 'is-late' : '' ?>">
              <?= e((string) $linha['dia']) ?> · <?= e(substr((string) $linha['quando'], -5)) ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-heading">
      <div><h3>Esperando o cliente</h3><p>As mais antigas primeiro.</p></div>
      <a class="text-link" href="<?= e($config->url('/approvals')) ?>">Ver todas <span>→</span></a>
    </div>
    <?php if ($pendentes === []): ?>
      <div class="empty-state small-empty">
        <div class="empty-icon">✓</div>
        <b>Nada esperando resposta</b>
        <p>Nenhuma revisão pendente neste workspace.</p>
      </div>
    <?php else: ?>
      <ul class="dashboard-list">
        <?php foreach ($pendentes as $revisao): ?>
          <li>
            <div class="dashboard-list-main">
              <b><a href="<?= e($config->url('/approvals/' . (int) $revisao['id'])) ?>"><?= e((string) $revisao['title']) ?></a></b>
              <small><?= e((string) $revisao['cliente']) ?><?= (string) $revisao['status'] === 'changes_requested' ? ' · pediu alteração' : '' ?></small>
            </div>
            <span class="dashboard-tag <?= (int) $revisao['dias'] >= 3 ? 'is-late' : '' ?>">
              <?= (int) $revisao['dias'] ?> <?= (int) $revisao['dias'] === 1 ? 'dia' : 'dias' ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<div class="shortcut-row">
  <?php foreach ($atalhos as $atalho): ?>
    <a class="shortcut <?= $atalho['alerta'] ? 'has-alert' : '' ?>" href="<?= e($config->url($atalho['path'])) ?>">
      <span class="shortcut-icon"><?= e($atalho['icon']) ?></span>
      <span><?= e($atalho['name']) ?></span>
      <?php if ($atalho['alerta']): ?><i class="shortcut-dot" title="Precisa de atenção"></i><?php endif; ?>
      <b><?= (int) $atalho['count'] ?></b>
    </a>
  <?php endforeach; ?>
  <a class="shortcut" href="<?= e($config->url('/reports')) ?>">
    <span class="shortcut-icon">◑</span><span>Relatórios</span><b>CSV</b>
  </a>
</div>

<div class="lower-grid">
  <section class="panel">
    <div class="panel-heading">
      <div><h3>Clientes recentes</h3><p>Últimos perfis cadastrados neste workspace.</p></div>
      <a class="button outline small-button" href="<?= e($config->url('/clients')) ?>">Ver todos</a>
    </div>
    <?php if (empty($recent_clients)): ?>
      <div class="empty-state" style="padding:26px 0">
        <p class="page-subtitle">Nenhum cliente ainda. Comece cadastrando a primeira marca.</p>
        <a class="button primary small-button" href="<?= e($config->url('/clients/new')) ?>">＋ Novo cliente</a>
      </div>
    <?php else: ?>
      <ul class="check-list" style="margin-top:14px">
        <?php foreach ($recent_clients as $cliente): ?>
          <li class="check">
            <span class="check-icon">◫</span>
            <div>
              <b><a href="<?= e($config->url('/clients/' . $cliente['id'])) ?>" style="color:inherit"><?= e((string) ($cliente['trade_name'] ?: $cliente['company_name'])) ?></a></b>
              <small><?= e((string) ($cliente['segment'] ?: 'Segmento não informado')) ?> · <?= e(mb_substr((string) $cliente['created_at'], 0, 10)) ?></small>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <div class="panel-heading" style="margin-top:18px">
      <div><h3>Módulos do produto</h3><p>Mesma estrutura de dados do produto Python; cada um entra em uma entrega.</p></div>
    </div>
    <ul class="check-list" style="margin-top:10px">
      <?php foreach ($modulosVisiveis as $modulo): ?>
        <li class="check">
          <span class="check-icon" style="<?= $modulo['ready'] ? '' : 'background:#f1f2f7;color:#9aa0b0' ?>"><?= $modulo['ready'] ? '✓' : '·' ?></span>
          <div><b><?= $modulo['ready'] ? '<a href="' . e($config->url((string) $modulo['path'])) . '" style="color:inherit">' . e($modulo['name']) . '</a>' : e($modulo['name']) ?></b><small><?= e($modulo['hint']) ?></small></div>
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
<section class="panel instance-panel">
  <div class="panel-heading">
    <div><h3>Ficha da instância</h3><p>Para quando alguém precisa conferir o servidor, não para o dia a dia.</p></div>
  </div>
  <dl class="instance-grid">
    <div><dt>Agência</dt><dd><?= e((string) $user['company_name']) ?></dd></div>
    <div><dt>Proprietário</dt><dd><?= e((string) $user['email']) ?></dd></div>
    <div><dt>Equipe</dt><dd><?= (int) $team_count ?> usuário(s)</dd></div>
    <div><dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd></div>
    <div><dt>Instalada em</dt><dd><?= e((string) ($installation['installed_at'] ?? '—')) ?> UTC</dd></div>
    <div><dt>Módulos prontos</dt><dd><?= $prontos ?> de <?= count($modulosVisiveis) ?></dd></div>
  </dl>
</section>
<?php
$content = ob_get_clean();
$title = 'Visão geral';
require __DIR__ . '/../layouts/app.php';
