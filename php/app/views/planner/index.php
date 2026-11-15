<?php
/**
 * Planejamento editorial: formulário de geração + lista de calendários.
 *
 * @var array<int,array<string,mixed>> $clientes
 * @var array<int,array<string,mixed>> $planos
 * @var bool $ia_configurada
 * @var string $modelo
 * @var array{geracoes:int,entrada:int,saida:int} $consumo
 * @var bool $pode_editar
 * @var string $hoje
 * @var array<string,mixed> $form
 * @var string|null $erro
 * @var string $csrf
 * @var \Smia\Support\Config $config
 */
$v = static fn (string $campo, string $padrao = ''): string => (string) ($form[$campo] ?? $padrao);
$redesEscolhidas = $form['networks'] ?? ['Instagram'];
$redesEscolhidas = is_array($redesEscolhidas) ? $redesEscolhidas : [$redesEscolhidas];
ob_start();
?>
<div class="page-title-row">
  <div>
    <div class="eyebrow muted-eyebrow">ETAPA 02 · ESTRATÉGIA</div>
    <h1>Planejamento editorial</h1>
    <p class="page-subtitle">Estratégia feita para cada cliente, com base no negócio e na identidade da marca.</p>
  </div>
  <span class="status-pill <?= $ia_configurada ? '' : 'muted-pill' ?>">
    <i></i> <?= $ia_configurada ? 'IA configurada · ' . e($modelo) : 'IA não configurada' ?>
  </span>
</div>

<?php if ($erro !== null && $erro !== ''): ?>
  <div class="alert error-alert"><?= e($erro) ?></div>
<?php endif; ?>

<?php if (!$ia_configurada): ?>
  <div class="alert setup-alert">
    <b>✦ Sem chave de IA configurada</b>
    <p>
      Defina <code>OPENAI_API_KEY</code> no <code>.env</code> (e opcionalmente <code>OPENAI_MODEL</code>)
      para gerar calendários com IA. Enquanto isso, o botão abaixo cria um
      <b>rascunho local</b>: pauta, formatos e briefings montados a partir do segmento e do objetivo do
      cliente — sem texto de IA, para a equipe editar.
    </p>
  </div>
<?php endif; ?>

<?php if ($clientes === []): ?>
  <div class="empty-state">
    <div class="empty-illustration"><div class="empty-circle">◫</div></div>
    <div class="eyebrow muted-eyebrow">PRIMEIRO O CLIENTE</div>
    <h2>Cadastre uma marca antes de planejar</h2>
    <p>O planejamento usa o perfil e a identidade visual do cliente como base.</p>
    <a class="button primary" href="<?= e($config->url('/clients/new')) ?>">＋ Novo cliente</a>
  </div>
<?php elseif ($pode_editar): ?>
  <section class="panel planner-builder">
    <div class="panel-heading">
      <div>
        <h3>✦ Começar um planejamento</h3>
        <p>Escolha o cliente. Os dados da marca são incluídos automaticamente.</p>
      </div>
    </div>

    <form method="post" action="<?= e($config->url('/planner/generate')) ?>" class="planner-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

      <div class="form-grid">
        <label class="field span-6">Cliente <em>*</em>
          <select name="client_id" required>
            <option value="">Selecione um cliente</option>
            <?php foreach ($clientes as $cliente): ?>
              <option value="<?= (int) $cliente['id'] ?>" <?= (int) $v('client_id') === (int) $cliente['id'] ? 'selected' : '' ?>>
                <?= e((string) ($cliente['trade_name'] ?: $cliente['company_name'])) ?><?= $cliente['segment'] ? ' · ' . e((string) $cliente['segment']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="field span-3">Data de início
          <input type="date" name="start_date" value="<?= e($v('start_date', $hoje)) ?>" required>
        </label>

        <label class="field span-3">Quantidade de publicações <em>*</em>
          <input type="number" name="target_posts" min="1" max="45" value="<?= e($v('target_posts', '7')) ?>" required>
          <small>De 1 a 45 no período.</small>
        </label>

        <div class="field span-6">
          <span class="field-label">Período</span>
          <div class="segmented">
            <?php foreach ([7, 15, 30] as $dias): ?>
              <label>
                <input type="radio" name="period_days" value="<?= $dias ?>" <?= (int) $v('period_days', '7') === $dias ? 'checked' : '' ?>>
                <span><?= $dias ?> dias</span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="field span-6">
          <span class="field-label">Redes sociais <em>*</em></span>
          <div class="network-chips">
            <?php foreach (\Smia\Planner\PlanGenerator::REDES as $rede): ?>
              <label class="chip">
                <input type="checkbox" name="networks[]" value="<?= e($rede) ?>" <?= in_array($rede, $redesEscolhidas, true) ? 'checked' : '' ?>>
                <span><?= e($rede) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <label class="field span-12">Objetivo estratégico <em>*</em>
          <input name="objective" maxlength="500" required placeholder="Ex.: aumentar pedidos no delivery e fortalecer a marca no bairro" value="<?= e($v('objective')) ?>">
          <small>A meta principal que guiará os temas e chamadas para ação.</small>
        </label>

        <label class="field span-12">Campanha ou contexto adicional
          <textarea name="campaign_context" rows="3" maxlength="1500" placeholder="Ex.: lançamento de um novo sabor em março, feriado, ação com influenciador..."><?= e($v('campaign_context')) ?></textarea>
        </label>
      </div>

      <div class="form-actions">
        <span class="form-hint">Os dados de negócio e marca entram no prompt automaticamente.</span>
        <button type="submit" class="button primary">
          ✦ <?= $ia_configurada ? 'Gerar planejamento com IA' : 'Criar rascunho local' ?> <span>→</span>
        </button>
      </div>
    </form>
  </section>
<?php endif; ?>

<section class="panel">
  <div class="panel-heading">
    <div>
      <h3>Calendários</h3>
      <p><?= count($planos) ?> planejamento(s) neste workspace · <?= (int) $consumo['geracoes'] ?> geração(ões) de IA neste mês</p>
    </div>
  </div>

  <?php if ($planos === []): ?>
    <div class="empty-state" style="padding:28px 0">
      <p class="page-subtitle">Nenhum planejamento ainda. O primeiro calendário aparece aqui.</p>
    </div>
  <?php else: ?>
    <div class="plan-list">
      <?php foreach ($planos as $plano): ?>
        <a class="plan-row" href="<?= e($config->url('/planner/' . $plano['id'])) ?>">
          <span class="plan-badge"><?= (int) $plano['period_days'] ?>d</span>
          <span class="plan-main">
            <b><?= e((string) $plano['title']) ?></b>
            <small>
              <?= e((string) ($plano['trade_name'] ?: $plano['company_name'])) ?> ·
              início <?= e(date('d/m/Y', strtotime((string) $plano['start_date']))) ?> ·
              <?= (int) $plano['post_count'] ?> publicação(ões)
            </small>
          </span>
          <span class="plan-tags">
            <?php foreach (\Smia\Planner\PlannerRepository::redes($plano['networks_json']) as $rede): ?>
              <span class="tag"><?= e($rede) ?></span>
            <?php endforeach; ?>
          </span>
          <span class="badge <?= $plano['status'] === 'in_production' ? 'badge-live' : '' ?>">
            <?= $plano['status'] === 'in_production' ? 'Em produção' : 'Rascunho' ?>
          </span>
          <span class="row-arrow">↗</span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = 'Planejamento';
require __DIR__ . '/../layouts/app.php';
