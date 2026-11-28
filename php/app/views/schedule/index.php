<?php
/**
 * Agenda interna: fila de horários + estado real da Meta.
 *
 * A tela separa, de propósito, o que é registro interno (TikTok/LinkedIn) do
 * que é envio real (Facebook/Instagram), e mostra por que um botão está
 * desabilitado em vez de escondê-lo sem explicação.
 *
 * @var array<int,array<string,mixed>> $clientes
 * @var array<string,mixed>|null $selecionado
 * @var array<int,array<string,mixed>> $aprovados
 * @var array<int,array<string,mixed>> $agenda
 * @var int $ativas
 * @var array<int,string> $redes
 * @var array<int,string> $fusos
 * @var string $fuso_padrao
 * @var string $sugestao
 * @var bool $meta_configurada
 * @var string|null $meta_erro
 * @var bool $midia_pronta
 * @var string|null $midia_erro
 * @var array<int,array<string,mixed>> $conexoes
 * @var bool $pode_editar
 * @var string|null $aviso
 * @var string|null $erro
 * @var array{ativos:int,atrasados:int,publicados:int,cancelados:int,total:int} $resumo
 * @var array{client_id:int,status:string} $filtros
 * @var array<int,array{dia:string,rotulo:string,itens:array<int,array<string,mixed>>}> $por_dia
 */
$nomeCliente = static fn (array $linha): string => trim((string) ($linha['trade_name'] ?? '')) ?: (string) $linha['company_name'];
$rotuloEvento = [
    'scheduled' => 'Agendado internamente',
    'rescheduled' => 'Horário alterado',
    'cancelled' => 'Cancelado',
];
$tituloBotao = static function (array $linha): string {
    return match (true) {
        (string) $linha['status'] === 'cancelled' => 'Cancelado',
        (bool) $linha['meta_published'] => 'Publicado via Meta',
        ($linha['meta_resolution'] ?? null) === 'confirmed_published' => 'Confirmado manualmente na Meta',
        ($linha['meta_status'] ?? null) === 'unknown' => 'Resultado incerto',
        ($linha['meta_status'] ?? null) === 'publishing' => 'Envio em andamento',
        ($linha['meta_status'] ?? null) === 'failed' => 'Falha Meta',
        default => 'Na agenda',
    };
};
ob_start();
?>
<div class="page-head schedule-head">
  <div>
    <div class="eyebrow">ORGANIZAÇÃO DE PUBLICAÇÃO</div>
    <h1>Agenda interna</h1>
    <p class="page-sub">Organize quando cada conteúdo aprovado deve sair — e, no horário, decida o envio à Meta.</p>
  </div>
  <?php if ($clientes !== []): ?>
    <div class="page-actions">
      <form method="get" action="<?= e($config->url('/schedule')) ?>" class="schedule-client-select">
        <?php if (($filtros['status'] ?? '') !== ''): ?>
          <input type="hidden" name="status" value="<?= e($filtros['status']) ?>">
        <?php endif; ?>
        <label for="scheduleClient">Cliente</label>
        <select name="client_id" id="scheduleClient" onchange="this.form.submit()">
          <option value="">Todos os clientes</option>
          <?php foreach ($clientes as $cliente): ?>
            <option value="<?= (int) $cliente['id'] ?>" <?= $selecionado !== null && (int) $selecionado['id'] === (int) $cliente['id'] ? 'selected' : '' ?>>
              <?= e($nomeCliente($cliente)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  <?php endif; ?>
</div>

<div class="schedule-truth-note">
  <span>ⓘ</span>
  <p><b>A agenda não publica sozinha.</b> Depois do horário, uma pessoa autorizada pode iniciar
    Facebook ou Instagram via Meta — e só com o ciclo aprovado. TikTok e LinkedIn ficam como
    registro interno. Publicar é a única ação do produto que não se desfaz: por isso não existe
    envio automático nem repetição silenciosa.</p>
</div>

<?php if (!empty($aviso)): ?>
  <div class="alert success-alert"><?= e($aviso) ?></div>
<?php endif; ?>
<?php if (!empty($erro)): ?>
  <div class="alert error-alert"><?= e($erro) ?></div>
<?php endif; ?>

<?php
$urlDaAgenda = static function (array $troca = []) use ($config, $filtros): string {
    $final = array_filter(array_merge($filtros, $troca), static fn ($v) => $v !== 0 && $v !== '' && $v !== null);
    return $config->url('/schedule') . ($final === [] ? '' : '?' . http_build_query($final));
};
$abaAtual = (string) ($filtros['status'] ?? '');
?>
<section class="panel schedule-summary" aria-label="Resumo da agenda">
  <nav class="schedule-tabs" role="tablist" aria-label="Filtrar a fila">
    <a class="schedule-tab <?= $abaAtual === '' ? 'is-active' : '' ?>" href="<?= e($urlDaAgenda(['status' => null])) ?>"
       <?= $abaAtual === '' ? 'aria-current="page"' : '' ?>>Tudo <b><?= (int) $resumo['total'] ?></b></a>
    <a class="schedule-tab <?= $abaAtual === 'ativos' ? 'is-active' : '' ?>" href="<?= e($urlDaAgenda(['status' => 'ativos'])) ?>"
       <?= $abaAtual === 'ativos' ? 'aria-current="page"' : '' ?>>Na fila <b><?= (int) $resumo['ativos'] ?></b></a>
    <a class="schedule-tab <?= $abaAtual === 'historico' ? 'is-active' : '' ?>" href="<?= e($urlDaAgenda(['status' => 'historico'])) ?>"
       <?= $abaAtual === 'historico' ? 'aria-current="page"' : '' ?>>Histórico <b><?= (int) $resumo['cancelados'] ?></b></a>
  </nav>
  <div class="schedule-facts">
    <span class="schedule-fact <?= $resumo['publicados'] > 0 ? 'is-ok' : '' ?>">
      <b><?= (int) $resumo['publicados'] ?></b> publicadas
    </span>
    <span class="schedule-fact <?= $resumo['atrasados'] > 0 ? 'is-late' : '' ?>">
      <b><?= (int) $resumo['atrasados'] ?></b> com horário vencido
    </span>
  </div>
</section>

<?php if ($resumo['atrasados'] > 0 && $pode_editar): ?>
  <div class="alert schedule-late-alert" role="status">
    <b><?= (int) $resumo['atrasados'] ?> <?= $resumo['atrasados'] === 1 ? 'horário vencido espera' : 'horários vencidos esperam' ?> uma decisão.</b>
    A agenda não publica sozinha: no horário, alguém da equipe escolhe enviar à Meta — ou cancelar e reagendar.
    <a class="text-link" href="<?= e($urlDaAgenda(['status' => 'ativos'])) ?>">Ver só a fila ativa <span>→</span></a>
  </div>
<?php endif; ?>

<div class="schedule-layout">
  <section class="panel schedule-create">
    <div class="panel-heading">
      <div>
        <h3>Adicionar à agenda</h3>
        <p>Somente ciclos aprovados pelo cliente podem ser organizados.</p>
      </div>
      <span class="schedule-chip"><?= (int) $ativas ?> ativo(s)</span>
    </div>

    <?php if (!$pode_editar): ?>
      <p class="schedule-note">Seu papel atual é somente leitura. Peça a um administrador ou editor para organizar horários.</p>
    <?php elseif ($aprovados === []): ?>
      <p class="schedule-note">
        Ainda não há conteúdo aprovado disponível<?= $selecionado !== null ? ' para este cliente' : '' ?>.
        <a class="text-link" href="<?= e($config->url('/approvals' . ($selecionado !== null ? '?client_id=' . (int) $selecionado['id'] : ''))) ?>">Acesse as aprovações</a>
        para concluir uma revisão antes do agendamento.
      </p>
    <?php else: ?>
      <form method="post" action="<?= e($config->url('/schedule')) ?>" class="schedule-form" data-schedule-form>
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <label class="field">Conteúdo aprovado <em>*</em>
          <select name="approval_id" id="scheduleApproval" required>
            <option value="">Escolha um conteúdo aprovado</option>
            <?php foreach ($aprovados as $aprovacao): ?>
              <option value="<?= (int) $aprovacao['id'] ?>"
                      data-source-type="<?= e((string) $aprovacao['source_type']) ?>"
                      data-network="<?= e((string) $aprovacao['network']) ?>">
                <?= e((string) $aprovacao['title']) ?> · <?= e((string) $aprovacao['cliente']) ?><?= ($aprovacao['network'] ?? '') !== '' ? ' · ' . e((string) $aprovacao['network']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <div class="schedule-row">
          <label class="field">Rede <em>*</em>
            <select name="network" id="scheduleNetwork" required>
              <?php foreach ($redes as $rede): ?>
                <option value="<?= e($rede) ?>"><?= e($rede) ?></option>
              <?php endforeach; ?>
            </select>
            <small id="scheduleNetworkHint">Escolha onde a equipe pretende publicar.</small>
          </label>
          <label class="field">Data e horário local <em>*</em>
            <input type="datetime-local" name="scheduled_local" required value="<?= e($sugestao) ?>">
            <small>O horário precisa estar pelo menos 5 minutos no futuro.</small>
          </label>
        </div>

        <label class="field field-narrow">Fuso horário
          <select name="timezone">
            <?php foreach ($fusos as $fuso): ?>
              <option value="<?= e($fuso) ?>" <?= $fuso === $fuso_padrao ? 'selected' : '' ?>><?= e($fuso) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <button class="button primary" type="submit">Adicionar à agenda</button>
      </form>
    <?php endif; ?>
  </section>

  <aside class="panel schedule-state">
    <span class="eyebrow muted-eyebrow">ESTADO DESTA INSTÂNCIA</span>
    <ul class="schedule-state-list">
      <li class="<?= $meta_configurada ? 'ok' : 'pending' ?>">
        <b>App Meta</b>
        <small><?= $meta_configurada ? 'Configuração detectada no .env' : 'Configuração pendente' ?></small>
      </li>
      <li class="<?= $midia_pronta ? 'ok' : 'pending' ?>">
        <b>URL de mídia HTTPS</b>
        <small><?= $midia_pronta ? 'Origem pública pronta para a Meta buscar imagens' : 'Pendente — a Meta precisa de uma origem HTTPS' ?></small>
      </li>
      <li class="<?= $conexoes === [] ? 'pending' : 'ok' ?>">
        <b>Páginas conectadas</b>
        <small><?= count($conexoes) ?> página(s)</small>
      </li>
    </ul>
    <?php if (!$meta_configurada && !empty($meta_erro)): ?>
      <p class="schedule-note"><?= e($meta_erro) ?></p>
    <?php endif; ?>
    <?php if (!$midia_pronta && !empty($midia_erro)): ?>
      <p class="schedule-note"><?= e($midia_erro) ?></p>
    <?php endif; ?>
    <a class="text-link" href="<?= e($config->url('/meta')) ?>">Abrir Conexões Meta <span>→</span></a>
  </aside>
</div>

<section class="panel schedule-queue">
  <div class="panel-heading">
    <div>
      <h3>Fila de horários</h3>
      <p>Ativos primeiro; cancelados ficam no histórico.</p>
    </div>
    <span class="schedule-chip">
      <?php if (($filtros['status'] ?? '') !== '' || (int) ($filtros['client_id'] ?? 0) > 0): ?>
        <?= count($agenda) ?> no filtro · <?= (int) $resumo['ativos'] ?> na fila no total
      <?php else: ?>
        <?= (int) $resumo['ativos'] ?> na fila · <?= count($agenda) ?> registro(s)
      <?php endif; ?>
    </span>
  </div>

  <?php if ($agenda === []): ?>
    <div class="empty-state small-empty">
      <div class="empty-icon">◷</div>
      <b>Nenhum horário organizado ainda</b>
      <p>Depois da aprovação, adicione conteúdos à fila interna.</p>
    </div>
  <?php else: ?>
    <div class="schedule-list">
      <?php foreach ($por_dia as $grupo): ?>
        <h4 class="schedule-day">
          <span><?= e((string) $grupo['rotulo']) ?></span>
          <small><?= count($grupo['itens']) ?> <?= count($grupo['itens']) === 1 ? 'horário' : 'horários' ?></small>
        </h4>
      <?php foreach ($grupo['itens'] as $linha): ?>
        <?php
        $cancelado = (string) $linha['status'] === 'cancelled';
        $incerto = ($linha['meta_status'] ?? null) === 'unknown';
        $falhou = ($linha['meta_status'] ?? null) === 'failed';
        $publicado = (bool) $linha['meta_published'];
        $classe = $cancelado ? 'is-cancelled' : ($publicado ? 'is-published' : ($incerto ? 'is-unknown' : ($falhou ? 'is-failed' : '')));
        $compativel = array_values(array_filter($conexoes, static function (array $conexao) use ($linha): bool {
            if ((string) $linha['network'] === 'Instagram') {
                return !empty($conexao['instagram_user_id'])
                    && ($conexao['instagram_basic_permission'] ?? null) !== 'declined'
                    && ($conexao['instagram_publish_permission'] ?? null) !== 'declined';
            }

            return ($conexao['facebook_publish_permission'] ?? null) !== 'declined';
        }));
        ?>
        <article class="schedule-item <?= e($classe) ?>">
          <div class="schedule-item-main">
            <div class="schedule-item-meta">
              <span class="schedule-network-pill"><?= e((string) $linha['network']) ?></span>
              <span><?= e((string) $linha['cliente']) ?></span>
              <span><?= e((string) $linha['timezone']) ?></span>
            </div>
            <b><?= e((string) $linha['approval_title']) ?></b>
            <small>
              <?= e((string) $linha['local_label']) ?>
              <?php if (($linha['dia_relativo'] ?? '') !== '' && !in_array((string) $linha['dia_relativo'], ['Hoje', 'Amanhã', 'Ontem'], true)): ?>
                · <?= e((string) $linha['dia_relativo']) ?>
              <?php endif; ?>
              <?php if (!empty($linha['is_due']) && (string) $linha['status'] === 'scheduled' && empty($linha['meta_busy'])): ?>
                · <b class="schedule-due">horário vencido</b>
              <?php endif; ?>
              · ciclo aprovado registrado
            </small>
            <div class="schedule-audit">
              <?php foreach ($linha['events'] as $evento): ?>
                <span><?= e($rotuloEvento[(string) $evento['event_type']] ?? (string) $evento['event_type']) ?> ·
                  <?= e((string) ($evento['actor_name'] ?? 'Equipe')) ?><?= $evento['event_type'] === 'rescheduled' && $evento['note'] !== '' ? ' · ' . e((string) $evento['note']) : '' ?></span>
              <?php endforeach; ?>
            </div>
          </div>

          <span class="schedule-status <?= e($classe) ?>"><?= e($tituloBotao($linha)) ?></span>

          <?php if ($publicado): ?>
            <small class="meta-result-note">ID Meta: <?= e((string) ($linha['meta_remote_id'] ?? '')) ?> · confirmado pela API</small>
          <?php elseif (($linha['meta_resolution'] ?? null) === 'confirmed_published'): ?>
            <small class="meta-result-note">ID Meta: <?= e((string) ($linha['meta_resolution_remote_id'] ?? '')) ?> ·
              conferido por <?= e((string) ($linha['meta_resolution_by'] ?? 'Equipe')) ?> em <?= e((string) ($linha['meta_resolution_at'] ?? '')) ?> UTC.
              <?= e((string) ($linha['meta_resolution_note'] ?? '')) ?></small>
          <?php elseif (($linha['meta_resolution'] ?? null) === 'confirmed_not_published'): ?>
            <small class="meta-result-note">Ausência conferida por <?= e((string) ($linha['meta_resolution_by'] ?? 'Equipe')) ?>
              em <?= e((string) ($linha['meta_resolution_at'] ?? '')) ?> UTC. <?= e((string) ($linha['meta_resolution_note'] ?? '')) ?></small>
          <?php elseif ($incerto || $falhou): ?>
            <small class="meta-result-note"><?= e((string) ($linha['meta_error'] ?? '')) ?></small>
          <?php endif; ?>

          <?php if ($incerto && empty($linha['meta_resolution']) && in_array((string) $user['role'], ['owner', 'admin'], true)): ?>
            <details class="meta-reconcile">
              <summary>Conferir resultado na Meta</summary>
              <p class="schedule-note">Use somente depois de olhar a Página/conta na Meta. Uma confirmação manual não entra
                na métrica de publicação confirmada pela API.</p>
              <form method="post" action="<?= e($config->url('/schedule/meta-attempt/' . (int) $linha['meta_attempt_id'] . '/reconcile')) ?>"
                    class="meta-reconcile-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <label class="field">Resultado
                  <select name="outcome" required>
                    <option value="">Selecione</option>
                    <option value="confirmed_published">Publicação encontrada</option>
                    <option value="confirmed_not_published">Nenhuma publicação encontrada</option>
                  </select>
                </label>
                <label class="field">ID da publicação (obrigatório se encontrada)
                  <input name="remote_publication_id" maxlength="255" placeholder="ID real exibido pela Meta">
                </label>
                <label class="field">Observação da conferência
                  <textarea name="note" minlength="12" maxlength="500" required placeholder="Descreva como conferiu o resultado"></textarea>
                </label>
                <button class="button outline small-button" type="submit">Registrar conferência</button>
              </form>
            </details>
          <?php endif; ?>

          <?php if ($pode_editar && !$cancelado && empty($linha['meta_busy'])): ?>
            <div class="schedule-actions">
              <form method="post" action="<?= e($config->url('/schedule/' . (int) $linha['id'] . '/reschedule')) ?>" class="schedule-inline-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <label class="field">Nova data e hora
                  <input type="datetime-local" name="scheduled_local" required value="<?= e((string) $linha['local_input']) ?>">
                </label>
                <label class="field">Fuso
                  <select name="timezone">
                    <?php foreach ($fusos as $fuso): ?>
                      <option value="<?= e($fuso) ?>" <?= $fuso === (string) $linha['timezone'] ? 'selected' : '' ?>><?= e($fuso) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <button class="button outline small-button" type="submit">Salvar novo horário</button>
              </form>
              <form method="post" action="<?= e($config->url('/schedule/' . (int) $linha['id'] . '/cancel')) ?>" data-confirm="Cancelar este horário? O registro continua no histórico.">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="button danger-outline small-button" type="submit">Cancelar horário</button>
              </form>
            </div>
          <?php endif; ?>

          <?php if ($pode_editar && !$cancelado && empty($linha['meta_busy']) && in_array((string) $linha['network'], ['Facebook', 'Instagram'], true)): ?>
            <?php if (!$linha['is_due']): ?>
              <span class="meta-publish-hint">Publicação manual disponível no horário agendado.</span>
            <?php elseif (!$meta_configurada): ?>
              <span class="meta-publish-hint">Configure a conexão Meta no .env para liberar a ação.</span>
            <?php elseif (!$midia_pronta): ?>
              <span class="meta-publish-hint">Configure uma URL HTTPS pública (META_PUBLIC_BASE_URL) para a Meta buscar a imagem.</span>
            <?php elseif ((string) $linha['network'] === 'Instagram' && !$linha['has_export']): ?>
              <span class="meta-publish-hint">O Instagram exige uma arte exportada aprovada.</span>
            <?php elseif ($compativel === []): ?>
              <span class="meta-publish-hint">Nenhuma Página compatível. <a class="text-link" href="<?= e($config->url('/meta')) ?>">Ver Conexões Meta</a>.</span>
            <?php else: ?>
              <form method="post" action="<?= e($config->url('/schedule/' . (int) $linha['id'] . '/publish')) ?>"
                    class="meta-publish-form" data-confirm="Iniciar agora a publicação real na Meta? O conteúdo será enviado à conta selecionada.">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <select name="connection_id" required aria-label="Página Meta">
                  <?php foreach ($compativel as $conexao): ?>
                    <option value="<?= (int) $conexao['id'] ?>">
                      <?= e((string) $conexao['page_name']) ?><?php if ((string) $linha['network'] === 'Instagram'): ?> · @<?= e((string) ($conexao['instagram_username'] ?? $conexao['instagram_user_id'])) ?><?php endif; ?><?php if (($conexao['last_check_status'] ?? null) === 'ok'): ?> · leitura Graph OK<?php elseif (($conexao['last_check_status'] ?? null) === 'failed'): ?> · falha na leitura Graph<?php else: ?> · leitura Graph pendente<?php endif; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button class="meta-publish-button" type="submit">Publicar via Meta ↗</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = 'Agenda interna';
require __DIR__ . '/../layouts/app.php';
