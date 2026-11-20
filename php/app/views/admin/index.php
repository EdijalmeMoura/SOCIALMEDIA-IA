<?php
/**
 * Área do dono: todas as agências, todos os usuários.
 *
 * Só quem tem `is_super = 1` chega aqui. A tela é dividida em três blocos que
 * respondem três perguntas: quantas agências existem, quem entra em cada uma e
 * o que mudou ultimamente. Toda ação destrutiva diz o efeito antes de aplicar.
 *
 * @var array<string,int> $resumo
 * @var array<int,array<string,mixed>> $agencias
 * @var array<int,array<string,mixed>> $usuarios
 * @var array<int,array<string,mixed>> $acoes
 * @var array<string,string> $papeis
 */
$rotuloPapel = static fn (string $papel, int $super): string => $super === 1
    ? 'Dono da instalação'
    : (['owner' => 'Proprietário', 'admin' => 'Administrador', 'editor' => 'Editor', 'viewer' => 'Leitor'][$papel] ?? $papel);
ob_start();
?>
<div class="page-head">
  <div>
    <div class="eyebrow">INSTALAÇÃO</div>
    <h1>Área do dono</h1>
    <p class="page-sub">Todas as agências e todos os usuários deste servidor. Nenhuma outra conta vê esta tela.</p>
  </div>
</div>

<div class="stat-grid admin-stats">
  <div class="stat-card">
    <span class="stat-label">AGÊNCIAS</span>
    <b class="stat-value"><?= (int) $resumo['active_companies'] ?></b>
    <span class="stat-note"><?= (int) $resumo['companies'] ?> no total</span>
  </div>
  <div class="stat-card">
    <span class="stat-label">USUÁRIOS</span>
    <b class="stat-value"><?= (int) $resumo['active_users'] ?></b>
    <span class="stat-note"><?= (int) $resumo['users'] ?> no total</span>
  </div>
  <div class="stat-card">
    <span class="stat-label">CLIENTES</span>
    <b class="stat-value"><?= (int) $resumo['clients'] ?></b>
    <span class="stat-note">em todas as agências</span>
  </div>
</div>

<section class="panel" id="agencias">
  <div class="panel-head">
    <div>
      <h2>Agências</h2>
      <p class="muted">Cada agência é um workspace isolado: os clientes de uma não aparecem na outra.</p>
    </div>
  </div>

  <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr>
          <th>Agência</th><th>Usuários</th><th>Clientes</th><th>Último acesso</th><th>Situação</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($agencias as $agencia): ?>
          <tr>
            <td>
              <form method="post" action="<?= e($config->url('/admin/agencias/' . (int) $agencia['id'])) ?>" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="company_id" value="<?= (int) $agencia['id'] ?>">
                <input type="text" name="name" value="<?= e((string) $agencia['name']) ?>" aria-label="Nome da agência" required>
                <input type="email" name="owner_email" value="<?= e((string) ($agencia['owner_email'] ?? '')) ?>" placeholder="responsável@agencia.com" aria-label="E-mail do responsável">
                <button class="button ghost small" type="submit">Salvar</button>
              </form>
            </td>
            <td><?= (int) $agencia['active_users'] ?> ativo(s) de <?= (int) $agencia['user_count'] ?></td>
            <td><?= (int) $agencia['client_count'] ?></td>
            <td><?= $agencia['last_login'] ? e(date('d/m/Y H:i', (int) strtotime((string) $agencia['last_login'] . ' UTC'))) : 'nunca' ?></td>
            <td>
              <?php if ((int) ($agencia['is_active'] ?? 1) === 1): ?>
                <span class="badge badge-ok">ativa</span>
              <?php else: ?>
                <span class="badge badge-off">desativada</span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" action="<?= e($config->url('/admin/agencias/' . (int) $agencia['id'] . '/ativar')) ?>"
                    onsubmit="return confirm('<?= (int) ($agencia['is_active'] ?? 1) === 1 ? 'Desativar esta agência?' : 'Reativar esta agência?' ?>');">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="company_id" value="<?= (int) $agencia['id'] ?>">
                <button class="button ghost small" type="submit">
                  <?= (int) ($agencia['is_active'] ?? 1) === 1 ? 'Desativar' : 'Reativar' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <form method="post" action="<?= e($config->url('/admin/agencias')) ?>" class="settings-form settings-form-inline admin-new-company">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <label class="field">Nova agência
      <input type="text" name="name" placeholder="Nome da agência" required>
    </label>
    <label class="field">Responsável (opcional)
      <input type="email" name="owner_email" placeholder="responsavel@agencia.com">
    </label>
    <button class="button primary" type="submit">Criar agência</button>
  </form>
</section>

<section class="panel" id="usuarios">
  <div class="panel-head">
    <div>
      <h2>Usuários</h2>
      <p class="muted">Criar, editar, trocar senha e desativar. Desativado = login recusado na hora.</p>
    </div>
  </div>

  <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>Nome</th><th>E-mail</th><th>Agência</th><th>Papel</th><th>Situação</th><th>Último acesso</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($usuarios as $pessoa): ?>
          <tr class="<?= (int) ($pessoa['is_active'] ?? 1) === 0 ? 'row-off' : '' ?>">
            <td>
              <form method="post" action="<?= e($config->url('/admin/usuarios/' . (int) $pessoa['id'])) ?>" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="user_id" value="<?= (int) $pessoa['id'] ?>">
                <input type="text" name="name" value="<?= e((string) $pessoa['name']) ?>" aria-label="Nome" required>
                <input type="email" name="email" value="<?= e((string) $pessoa['email']) ?>" aria-label="E-mail" required>
                <select name="role" aria-label="Papel">
                  <?php foreach ($papeis as $chave => $descricao): ?>
                    <option value="<?= e($chave) ?>" <?= (string) $pessoa['role'] === $chave ? 'selected' : '' ?>><?= e($descricao) ?></option>
                  <?php endforeach; ?>
                </select>
                <label class="check-inline">
                  <input type="checkbox" name="is_super" value="1" <?= (int) ($pessoa['is_super'] ?? 0) === 1 ? 'checked' : '' ?>>
                  dono da instalação
                </label>
                <button class="button ghost small" type="submit">Salvar</button>
              </form>
            </td>
            <td><?= e((string) $pessoa['email']) ?></td>
            <td><?= e((string) $pessoa['company_name']) ?></td>
            <td><?= e($rotuloPapel((string) $pessoa['role'], (int) ($pessoa['is_super'] ?? 0))) ?></td>
            <td>
              <?php if ((int) ($pessoa['is_active'] ?? 1) === 1): ?>
                <span class="badge badge-ok">ativo</span>
              <?php else: ?>
                <span class="badge badge-off">desativado</span>
              <?php endif; ?>
            </td>
            <td><?= $pessoa['last_login_at'] ? e(date('d/m/Y H:i', (int) strtotime((string) $pessoa['last_login_at'] . ' UTC'))) : 'nunca' ?></td>
            <td class="admin-row-actions">
              <form method="post" action="<?= e($config->url('/admin/usuarios/' . (int) $pessoa['id'] . '/senha')) ?>" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="user_id" value="<?= (int) $pessoa['id'] ?>">
                <input type="password" name="password" placeholder="nova senha (8+)" aria-label="Nova senha" autocomplete="new-password">
                <button class="button ghost small" type="submit">Trocar senha</button>
              </form>
              <form method="post" action="<?= e($config->url('/admin/usuarios/' . (int) $pessoa['id'] . '/ativar')) ?>"
                    onsubmit="return confirm('<?= (int) ($pessoa['is_active'] ?? 1) === 1 ? 'Desativar este usuário? O login dele passa a ser recusado.' : 'Reativar este usuário?' ?>');">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="user_id" value="<?= (int) $pessoa['id'] ?>">
                <button class="button ghost small" type="submit">
                  <?= (int) ($pessoa['is_active'] ?? 1) === 1 ? 'Desativar' : 'Reativar' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <form method="post" action="<?= e($config->url('/admin/usuarios')) ?>" class="settings-form admin-new-user">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <h3>Novo usuário</h3>
    <div class="settings-form-inline">
      <label class="field">Nome
        <input type="text" name="name" required>
      </label>
      <label class="field">E-mail
        <input type="email" name="email" required>
      </label>
      <label class="field">Agência
        <select name="company_id" required>
          <?php foreach ($agencias as $agencia): ?>
            <?php if ((int) ($agencia['is_active'] ?? 1) === 1): ?>
              <option value="<?= (int) $agencia['id'] ?>"><?= e((string) $agencia['name']) ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">Papel
        <select name="role">
          <?php foreach ($papeis as $chave => $descricao): ?>
            <option value="<?= e($chave) ?>" <?= $chave === 'editor' ? 'selected' : '' ?>><?= e($descricao) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">Senha (8+)
        <input type="password" name="password" required autocomplete="new-password">
      </label>
      <label class="check-inline">
        <input type="checkbox" name="is_super" value="1">
        dono da instalação
      </label>
    </div>
    <div class="settings-actions">
      <button class="button primary" type="submit">Criar usuário</button>
    </div>
  </form>
</section>

<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Últimas ações administrativas</h2>
      <p class="muted">Quem mudou o quê, e quando. Fica gravado no banco da instância.</p>
    </div>
  </div>
  <?php if ($acoes === []): ?>
    <p class="muted">Nada registrado ainda.</p>
  <?php else: ?>
    <ul class="admin-log">
      <?php foreach ($acoes as $acao): ?>
        <li>
          <span class="admin-log-when"><?= e(date('d/m H:i', (int) strtotime((string) $acao['created_at'] . ' UTC'))) ?> UTC</span>
          <span class="admin-log-what">
            <b><?= e((string) ($acao['actor_name'] ?? 'sistema')) ?></b>
            <?= e(str_replace(['admin.', '_'], ['', ' '], (string) $acao['action'])) ?>
            <?php if (!empty($acao['company_name'])): ?>· <?= e((string) $acao['company_name']) ?><?php endif; ?>
            <?php if ((string) ($acao['detail'] ?? '') !== ''): ?><em><?= e((string) $acao['detail']) ?></em><?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = 'Área do dono';
require __DIR__ . '/../layouts/app.php';
