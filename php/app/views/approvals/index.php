<?php
/**
 * Aprovações: enviar para revisão, acompanhar e gerir acessos do portal.
 *
 * @var array<int,array<string,mixed>> $clientes
 * @var array<string,mixed>|null $selecionado
 * @var array<int,array<string,mixed>> $artes
 * @var array<int,array<string,mixed>> $posts
 * @var array<int,array<string,mixed>> $aprovacoes
 * @var string|null $status_filtro
 * @var array<string,int> $contagem
 * @var array<int,array<string,mixed>> $contas
 * @var bool $pode_editar
 * @var bool $pode_gerir
 * @var string|null $aviso
 * @var string|null $erro
 * @var array<int,array<string,mixed>> $avisos
 * @var int $avisos_novos
 */
$statusRotulos = \Smia\Approvals\ApprovalRepository::STATUS;
$nomeCliente = static fn (array $linha): string => trim((string) ($linha['trade_name'] ?? '')) ?: (string) $linha['company_name'];
ob_start();
?>
<div class="page-head">
  <div>
    <div class="eyebrow">REVISÃO</div>
    <h1>Aprovações</h1>
    <p class="page-sub">O cliente vê o conteúdo em uma fotografia, decide e o retorno cai aqui com histórico completo.</p>
  </div>
  <div class="page-actions">
    <?php if ($avisos_novos > 0): ?>
      <form method="post" action="<?= e($config->url('/approvals/notifications/read')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="button outline" type="submit"><?= (int) $avisos_novos ?> aviso(s) novo(s) · marcar lidos</button>
      </form>
    <?php endif; ?>
    <span class="badge <?= $contagem['pending'] > 0 ? 'badge-warn' : 'badge-live' ?>"><?= (int) $contagem['pending'] ?> aguardando</span>
  </div>
</div>

<?php if (!empty($aviso)): ?>
  <div class="alert success-alert"><?= e($aviso) ?></div>
<?php endif; ?>
<?php if (!empty($erro)): ?>
  <div class="alert error-alert"><?= e($erro) ?></div>
<?php endif; ?>

<div class="approval-stats">
  <div class="approval-stat"><b><?= (int) $contagem['pending'] ?></b><span>Aguardando cliente</span></div>
  <div class="approval-stat"><b><?= (int) $contagem['changes_requested'] ?></b><span>Alterações pedidas</span></div>
  <div class="approval-stat"><b><?= (int) $contagem['approved'] ?></b><span>Aprovados</span></div>
  <div class="approval-stat"><b><?= (int) $contagem['rejected'] ?></b><span>Rejeitados</span></div>
</div>

<?php if ($clientes === []): ?>
  <div class="panel empty-state">
    <div class="empty-illustration"><div class="empty-circle">◫</div></div>
    <h2>Cadastre um cliente primeiro</h2>
    <p>As aprovações são por cliente — é ele quem entra no portal para revisar.</p>
    <a class="button primary" href="<?= e($config->url('/clients/new')) ?>">Cadastrar cliente</a>
  </div>
<?php else: ?>
  <div class="approval-layout">
    <section class="panel approval-send">
      <div class="panel-heading">
        <div>
          <h3>Enviar para revisão</h3>
          <p>Só entra arte exportada do estúdio ou pauta do planejamento. Um conteúdo tem uma pendência por vez.</p>
        </div>
      </div>

      <form method="get" action="<?= e($config->url('/approvals')) ?>" class="approval-client-picker">
        <label class="field">Cliente
          <select name="client_id" onchange="this.form.submit()">
            <?php foreach ($clientes as $cliente): ?>
              <option value="<?= (int) $cliente['id'] ?>" <?= $selecionado !== null && (int) $selecionado['id'] === (int) $cliente['id'] ? 'selected' : '' ?>>
                <?= e($nomeCliente($cliente)) ?><?= $cliente['status'] !== 'active' ? ' (inativo)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <noscript><button class="button outline" type="submit">Trocar cliente</button></noscript>
      </form>

      <?php if ($pode_editar): ?>
        <?php if ($artes === [] && $posts === []): ?>
          <div class="approval-none">
            <b>Nenhum conteúdo disponível deste cliente</b>
            <p>Exporte uma arte no <a href="<?= e($config->url('/studio')) ?>">estúdio</a> ou gere um plano no
              <a href="<?= e($config->url('/planner')) ?>">planejamento</a> — o que já está em revisão não aparece aqui.</p>
          </div>
        <?php else: ?>
          <form method="post" action="<?= e($config->url('/approvals')) ?>" class="approval-send-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="client_id" value="<?= (int) $selecionado['id'] ?>">

            <?php if ($artes !== []): ?>
              <div class="field-group">
                <span class="field-label">Artes exportadas</span>
                <div class="source-list">
                  <?php foreach ($artes as $arte): ?>
                    <label class="source-item">
                      <input type="radio" name="source" value="creative:<?= (int) $arte['id'] ?>" required>
                      <span class="source-badge">ARTE</span>
                      <span class="source-info">
                        <b><?= e((string) $arte['subject']) ?></b>
                        <small><?= e(\Smia\Studio\Layout::rotulo((string) $arte['format_key'])) ?> · atualizada em <?= e(date('d/m/Y', (int) strtotime((string) $arte['updated_at']))) ?></small>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($posts !== []): ?>
              <div class="field-group">
                <span class="field-label">Pautas do planejamento</span>
                <div class="source-list">
                  <?php foreach ($posts as $post): ?>
                    <label class="source-item">
                      <input type="radio" name="source" value="planned:<?= (int) $post['id'] ?>">
                      <span class="source-badge planned">PAUTA</span>
                      <span class="source-info">
                        <b><?= e((string) $post['theme']) ?></b>
                        <small><?= e((string) $post['network']) ?> · <?= e(date('d/m/Y', (int) strtotime((string) $post['publication_date']))) ?></small>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <label class="field span-12">Mensagem para o cliente (opcional)
              <textarea name="message" rows="3" maxlength="2000" placeholder="Explique o contexto: o que você quer que ele avalie?"></textarea>
            </label>

            <div class="form-actions">
              <button class="button primary" type="submit">Enviar para o portal</button>
            </div>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p class="form-hint">Seu perfil pode acompanhar as revisões, mas não enviar conteúdo.</p>
      <?php endif; ?>
    </section>

    <aside class="approval-side">
      <section class="panel approval-accounts">
        <div class="panel-heading">
          <div>
            <h3>Acessos do portal</h3>
            <p>Uma conta por pessoa do cliente. Sem conta ativa, ninguém revisa.</p>
          </div>
        </div>

        <?php if ($contas === []): ?>
          <p class="form-hint">Nenhuma conta criada ainda.</p>
        <?php else: ?>
          <ul class="account-list">
            <?php foreach ($contas as $conta): ?>
              <li class="<?= (int) $conta['is_active'] === 1 ? '' : 'is-paused' ?>">
                <div>
                  <b><?= e((string) $conta['name']) ?></b>
                  <small><?= e((string) $conta['email']) ?> · <?= e($nomeCliente($conta)) ?></small>
                  <small>
                    <?= (int) $conta['is_active'] === 1 ? 'Ativo' : 'Pausado' ?>
                    <?= !empty($conta['last_login_at']) ? '· último acesso ' . e(date('d/m/Y H:i', (int) strtotime((string) $conta['last_login_at']))) : '· nunca acessou' ?>
                  </small>
                </div>
                <?php if ($pode_gerir): ?>
                  <form method="post" action="<?= e($config->url('/approvals/portal-users/' . (int) $conta['id'] . '/toggle')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <button class="text-link" type="submit"><?= (int) $conta['is_active'] === 1 ? 'Pausar' : 'Reativar' ?></button>
                  </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($pode_gerir && $selecionado !== null): ?>
          <form method="post" action="<?= e($config->url('/approvals/portal-users')) ?>" class="approval-account-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="client_id" value="<?= (int) $selecionado['id'] ?>">
            <label class="field">Nome de quem revisa
              <input name="name" maxlength="100" required placeholder="Ex.: Marina Alves">
            </label>
            <label class="field">E-mail de acesso
              <input type="email" name="email" maxlength="160" required placeholder="marina@cliente.com.br">
            </label>
            <label class="field">Senha temporária (mín. 8)
              <input type="text" name="password" minlength="8" maxlength="100" required placeholder="Troque depois do primeiro acesso">
            </label>
            <button class="button outline" type="submit">Criar acesso de <?= e($nomeCliente($selecionado)) ?></button>
          </form>
        <?php elseif (!$pode_gerir): ?>
          <p class="form-hint">Só owner e admin criam ou pausam acessos do portal.</p>
        <?php endif; ?>
      </section>

      <?php if ($avisos !== []): ?>
        <section class="panel portal-notices" data-aviso="1">
          <div class="panel-heading"><div><h3>Avisos</h3><p>Retorno do cliente e comentários.</p></div></div>
          <ul class="notice-list">
            <?php foreach ($avisos as $avisoItem): ?>
              <li class="<?= $avisoItem['read_at'] === null ? 'is-new' : '' ?>">
                <a href="<?= e($config->url((string) $avisoItem['target_url'])) ?>">
                  <b><?= e((string) $avisoItem['title']) ?></b>
                  <span><?= e((string) $avisoItem['body']) ?></span>
                  <small><?= e(date('d/m/Y H:i', (int) strtotime((string) $avisoItem['created_at']))) ?></small>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>
    </aside>
  </div>

  <section class="approval-history-block">
    <div class="section-heading">
      <div>
        <h2>Revisões</h2>
        <p>Pendentes primeiro. Cada linha leva ao histórico completo.</p>
      </div>
      <form method="get" action="<?= e($config->url('/approvals')) ?>" class="period-selector">
        <?php if ($selecionado !== null): ?>
          <input type="hidden" name="client_id" value="<?= (int) $selecionado['id'] ?>">
        <?php endif; ?>
        <select name="status" onchange="this.form.submit()">
          <option value="">Todos os status</option>
          <?php foreach ($statusRotulos as $chave => $rotulo): ?>
            <option value="<?= e($chave) ?>" <?= $status_filtro === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <?php if ($aprovacoes === []): ?>
      <div class="panel empty-state small-empty">
        <h2>Nenhuma revisão <?= $status_filtro !== null ? 'com esse status' : 'ainda' ?></h2>
        <p>Envie um conteúdo acima: o cliente recebe o aviso no portal.</p>
      </div>
    <?php else: ?>
      <div class="panel approval-table">
        <table>
          <thead>
            <tr>
              <th>Conteúdo</th>
              <th>Cliente</th>
              <th>Ciclo</th>
              <th>Status</th>
              <th>Enviado</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($aprovacoes as $item): ?>
              <tr>
                <td>
                  <b><?= e((string) $item['title']) ?></b>
                  <small><?= $item['source_type'] === 'creative' ? 'Arte' : 'Pauta' ?> · <?= (int) $item['comment_count'] ?> comentário(s)</small>
                </td>
                <td><?= e($nomeCliente($item)) ?></td>
                <td><?= (int) $item['cycle_number'] ?></td>
                <td><span class="approval-status status-<?= e((string) $item['status']) ?>"><?= e($statusRotulos[(string) $item['status']] ?? (string) $item['status']) ?></span></td>
                <td><?= e(date('d/m H:i', (int) strtotime((string) $item['submitted_at']))) ?></td>
                <td><a class="text-link" href="<?= e($config->url('/approvals/' . (int) $item['id'])) ?>">abrir <span>→</span></a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Aprovações';
require __DIR__ . '/../layouts/app.php';
