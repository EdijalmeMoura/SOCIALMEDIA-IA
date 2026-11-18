<?php
/**
 * Relatórios operacionais: recorte, números, quebra por rede e registros.
 *
 * A tela deixa explícito o que o relatório NÃO tem: métricas de rede. Melhor
 * um número honesto a menos do que um gráfico inventado.
 *
 * @var array<int,array<string,mixed>> $clientes
 * @var array<string,mixed>|null $selecionado
 * @var string $inicio
 * @var string $fim
 * @var array<string,mixed> $relatorio
 * @var array<int,array<string,mixed>> $consumo
 * @var int $feed_total
 * @var string|null $erro
 */
$metricas = $relatorio['metrics'];
$etiqueta = static fn (string $data): string => (string) \DateTimeImmutable::createFromFormat('!Y-m-d', $data, new \DateTimeZone('UTC'))->format('d/m/Y');
$consulta = '?start_date=' . rawurlencode($inicio) . '&end_date=' . rawurlencode($fim) . ($selecionado !== null ? '&client_id=' . (int) $selecionado['id'] : '');
ob_start();
?>
<div class="page-head report-head">
  <div>
    <div class="eyebrow">VISÃO OPERACIONAL</div>
    <h1>Relatórios</h1>
    <p class="page-sub">O que a agência produziu no período, a partir dos registros do próprio produto.</p>
  </div>
  <div class="page-actions">
    <a class="button outline" href="<?= e($config->url('/reports/export.csv' . $consulta)) ?>">⇩ Exportar CSV</a>
  </div>
</div>

<div class="report-truth">
  <span>ⓘ</span>
  <p><b>Sem métricas de rede.</b> Este relatório conta registros internos: pautas, artes, aprovações e consumo de IA.
    Alcance, curtidas, impressões e cliques não entram — eles só existiriam conectando as contas, e o produto
    nunca apresenta um número que não tenha medido.</p>
</div>

<?php if (!empty($erro)): ?>
  <div class="alert error-alert"><?= e($erro) ?></div>
<?php endif; ?>

<form method="get" action="<?= e($config->url('/reports')) ?>" class="panel report-filter">
  <label class="field">Início
    <input type="date" name="start_date" value="<?= e($inicio) ?>" required>
  </label>
  <label class="field">Fim
    <input type="date" name="end_date" value="<?= e($fim) ?>" required>
  </label>
  <label class="field report-client">Cliente
    <select name="client_id">
      <option value="">Todos os clientes</option>
      <?php foreach ($clientes as $cliente): ?>
        <option value="<?= (int) $cliente['id'] ?>" <?= $selecionado !== null && (int) $selecionado['id'] === (int) $cliente['id'] ? 'selected' : '' ?>>
          <?= e(trim((string) ($cliente['trade_name'] ?? '')) ?: (string) $cliente['company_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="button primary" type="submit">Aplicar período</button>
  <span class="report-period"><?= e($etiqueta($inicio)) ?> — <?= e($etiqueta($fim)) ?> · datas de registro em UTC</span>
</form>

<div class="report-metrics">
  <article class="report-card">
    <span class="report-icon blue">▦</span>
    <span class="report-label">POSTS PLANEJADOS</span>
    <b><?= (int) $metricas['planned_posts'] ?></b>
    <small>no período selecionado</small>
  </article>
  <article class="report-card">
    <span class="report-icon coral">✦</span>
    <span class="report-label">ARTES EXPORTADAS</span>
    <b><?= (int) $metricas['exported_creatives'] ?> <i>/ <?= (int) $metricas['creatives'] ?></i></b>
    <small>exportadas / criadas</small>
  </article>
  <article class="report-card">
    <span class="report-icon mint">✓</span>
    <span class="report-label">APROVAÇÕES ENVIADAS</span>
    <b><?= (int) $metricas['approvals'] ?></b>
    <small><?= (int) $metricas['approval_approved'] ?> aprovadas · <?= (int) $metricas['approval_pending'] ?> aguardando agora</small>
  </article>
  <article class="report-card">
    <span class="report-icon violet">▤</span>
    <span class="report-label">NA PRÉVIA DO FEED</span>
    <b><?= (int) $feed_total ?></b>
    <small>itens organizados (total atual)</small>
  </article>
</div>

<div class="report-grid">
  <section class="panel report-panel">
    <div class="report-panel-head">
      <div>
        <h2>Status das aprovações</h2>
        <p>Estado atual dos ciclos enviados no período.</p>
      </div>
      <span class="report-chip"><?= (int) $metricas['approvals'] ?> ciclo(s)</span>
    </div>
    <ul class="report-status-list">
      <li><span class="dot pending"></span>Aguardando cliente<b><?= (int) $metricas['approval_pending'] ?></b></li>
      <li><span class="dot approved"></span>Aprovado<b><?= (int) $metricas['approval_approved'] ?></b></li>
      <li><span class="dot changes"></span>Alterações solicitadas<b><?= (int) $metricas['approval_changes'] ?></b></li>
      <li><span class="dot rejected"></span>Rejeitado<b><?= (int) $metricas['approval_rejected'] ?></b></li>
    </ul>
    <p class="report-note">
      Decisões registradas no período: <?= (int) $metricas['approved_events'] ?> aprovações ·
      <?= (int) $metricas['changes_events'] ?> pedidos de alteração ·
      <?= (int) $metricas['rejected_events'] ?> rejeições.
    </p>
  </section>

  <section class="panel report-panel">
    <div class="report-panel-head">
      <div>
        <h2>Atividade por rede</h2>
        <p>Pautas planejadas no período.</p>
      </div>
    </div>
    <?php if ($relatorio['network_rows'] === []): ?>
      <p class="report-note">Sem registros por rede neste período.</p>
    <?php else: ?>
      <div class="report-networks">
        <div class="report-network-head"><span>REDE</span><span>PLANEJADOS</span><span>%</span></div>
        <?php $totalRedes = max(1, (int) $metricas['planned_posts']); ?>
        <?php foreach ($relatorio['network_rows'] as $linha): ?>
          <div class="report-network-row">
            <b><?= e((string) $linha['network']) ?></b>
            <span><?= (int) $linha['posts'] ?></span>
            <span><?= (int) round(100 * (int) $linha['posts'] / $totalRedes) ?>%</span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<section class="panel report-panel report-consumption">
  <div class="report-panel-head">
    <div>
      <h2>Consumo de IA</h2>
      <p>Chamadas e tokens registrados no período — o que foi usado, não o que foi estimado.</p>
    </div>
    <span class="report-chip"><?= count($consumo) ?> combinação(ões)</span>
  </div>
  <?php if ($consumo === []): ?>
    <p class="report-note">Nenhuma chamada de IA no período (rascunho local não consome tokens).</p>
  <?php else: ?>
    <div class="report-ia">
      <div class="report-ia-head"><span>RECURSO</span><span>MODELO</span><span>CHAMADAS</span><span>TOKENS</span></div>
      <?php $recursos = ['studio_copy' => 'Estúdio · texto', 'studio_image' => 'Estúdio · imagem', 'studio_variation' => 'Estúdio · variação', 'weekly_planning' => 'Planejamento']; ?>
      <?php foreach ($consumo as $linha): ?>
        <div class="report-ia-row">
          <b><?= e($recursos[(string) $linha['feature']] ?? (string) $linha['feature']) ?></b>
          <span><?= e((string) $linha['model']) ?></span>
          <span><?= (int) $linha['chamadas'] ?></span>
          <span><?= number_format((int) $linha['entrada'] + (int) $linha['saida'], 0, ',', '.') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="panel report-panel report-activity">
  <div class="report-panel-head">
    <div>
      <h2>Registros do período</h2>
      <p>Da atividade mais recente para a mais antiga.</p>
    </div>
    <span class="report-chip"><?= (int) $relatorio['activity_count'] ?> registro(s)</span>
  </div>

  <?php if ($relatorio['activity'] === []): ?>
    <div class="empty-state small-empty">
      <h2>Nenhuma atividade neste período</h2>
      <p>Amplie o intervalo ou selecione outro cliente.</p>
    </div>
  <?php else: ?>
    <div class="report-table">
      <div class="report-table-head">
        <span>DATA (UTC)</span><span>CLIENTE</span><span>TIPO / CONTEÚDO</span><span>REDE</span><span>STATUS INTERNO</span>
      </div>
      <?php foreach ($relatorio['activity'] as $linha): ?>
        <div class="report-table-row">
          <span><?= e(date('d/m/Y H:i', (int) strtotime((string) $linha['date'] . ' UTC'))) ?></span>
          <span><?= e((string) $linha['client']) ?></span>
          <span class="report-content"><small><?= e((string) $linha['category']) ?></small><b><?= e((string) $linha['title']) ?></b></span>
          <span><?= e((string) $linha['network']) ?></span>
          <span><em class="report-state state-<?= e(mb_strtolower(str_replace(' ', '-', (string) $linha['status']))) ?>"><?= e((string) $linha['status']) ?></em></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($relatorio['truncated']): ?>
      <p class="report-note">Mostrando os 300 registros mais recentes. O CSV tem o mesmo limite.</p>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = 'Relatórios';
require __DIR__ . '/../layouts/app.php';
