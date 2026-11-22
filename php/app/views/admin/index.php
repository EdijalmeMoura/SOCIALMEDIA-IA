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
 * @var array<string,mixed> $atualizacao
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

<nav class="admin-jump" aria-label="Seções da área do dono">
  <a href="#agencias">Agências</a>
  <a href="#usuarios">Usuários</a>
  <a href="#atualizacao">Atualização do sistema</a>
  <a href="#acoes">Últimas ações</a>
</nav>

<?php $consultaTopo = $atualizacao['consulta'] ?? null; ?>
<?php if (is_array($consultaTopo) && ($consultaTopo['ok'] ?? false) === true && version_compare((string) $consultaTopo['versao'], (string) $atualizacao['atual']) > 0): ?>
  <div class="alert info-alert update-banner">
    <b>Versão v<?= e((string) $consultaTopo['versao']) ?> disponível</b> — você está na v<?= e((string) $atualizacao['atual']) ?>.
    <a href="#atualizacao">Ver e aplicar</a>
  </div>
<?php endif; ?>

<div class="stats-grid admin-stats">
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

  <div class="table-scroll admin-table-block">
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

  <div class="table-scroll admin-table-block">
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


<section class="panel" id="atualizacao">
  <div class="panel-head">
    <div>
      <h2>Atualização do sistema</h2>
      <p class="muted">Atualiza o código (app/ e public/) sem FTP nem cPanel. O banco, as imagens e o .env nunca são substituídos.</p>
    </div>
    <span class="badge">v<?= e((string) $atualizacao['atual']) ?> no ar</span>
  </div>

  <?php if (($atualizacao['disponivel'] ?? false) !== true): ?>
    <div class="alert error-alert"><?= e((string) ($atualizacao['motivo'] ?? 'Atualização indisponível.')) ?></div>
  <?php else: ?>

    <?php if (($atualizacao['pode'] ?? true) !== true): ?>
      <div class="alert error-alert">
        <b>Não dá para atualizar por aqui ainda:</b> <?= e((string) $atualizacao['motivo']) ?>.
        Peça à hospedagem para ajustar as permissões (pastas 755, arquivos 644) e volte.
      </div>
    <?php endif; ?>

    <ol class="update-steps">
      <li>
        <b>1. Verificar versão nova</b>
        <p class="muted">Consulta a última release do pacote PHP e mostra as notas antes de qualquer mudança.</p>
        <a class="button outline" href="<?= e($config->url('/admin/atualizacao/consultar')) ?>">Verificar versão nova</a>
      </li>

      <li>
        <b>2. Aplicar (com cópia de segurança)</b>
        <?php $consulta = $atualizacao['consulta'] ?? null; ?>
        <?php if (is_array($consulta) && ($consulta['ok'] ?? false) === true): ?>
          <div class="update-found">
            <p>
              Versão publicada: <b>v<?= e((string) $consulta['versao']) ?></b> ·
              você está na <b>v<?= e((string) $atualizacao['atual']) ?></b>.
            </p>
            <?php if (trim((string) ($consulta['notas'] ?? '')) !== ''): ?>
              <details class="update-notes">
                <summary>O que muda nesta versão</summary>
                <div class="update-notes-body"><?= nl2br(e(mb_substr((string) $consulta['notas'], 0, 4000))) ?></div>
              </details>
            <?php endif; ?>
          </div>
          <form method="post" action="<?= e($config->url('/admin/atualizacao/aplicar')) ?>" class="update-form"
                onsubmit="return confirm('Atualizar para a v<?= e((string) $consulta['versao']) ?>? Uma cópia da versão atual e do banco é guardada em data/backups antes de trocar os arquivos.');">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="versao" value="<?= e((string) $consulta['versao']) ?>">
            <input type="hidden" name="origem" value="github">
            <button class="button primary" type="submit" <?= ($atualizacao['pode'] ?? true) ? '' : 'disabled' ?>>Atualizar para v<?= e((string) $consulta['versao']) ?></button>
            <span class="form-hint">A página vai recarregar na versão nova. Se algo quebrar, use o passo 3.</span>
          </form>
        <?php else: ?>
          <p class="muted">Clique em <b>Verificar versão nova</b> primeiro: só depois de ver a versão e as notas o botão de atualizar aparece.</p>
        <?php endif; ?>

        <details class="update-upload">
          <summary>Sem saída para a internet? Envie o zip baixado</summary>
          <p class="muted">
            Baixe o <b>SOCIALMEDIA-IA-php-vX.Y.zip</b> na página de releases deste projeto, no seu computador,
            e envie aqui. O arquivo é conferido antes de aplicar: a versão lida de dentro do zip precisa ser a que você digitar.
          </p>
          <form method="post" action="<?= e($config->url('/admin/atualizacao/aplicar')) ?>" enctype="multipart/form-data" class="update-form"
                onsubmit="return confirm('Aplicar o zip enviado? A cópia da versão atual e do banco é guardada antes.');">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="origem" value="upload">
            <label class="field">Arquivo .zip
              <input type="file" name="pacote" accept=".zip,application/zip" required>
            </label>
            <label class="field">Versão que está dentro do zip
              <input type="text" name="versao" inputmode="decimal" placeholder="1.18" required pattern="[0-9]+\.[0-9]+(\.[0-9]+)?">
            </label>
            <button class="button outline" type="submit" <?= ($atualizacao['pode'] ?? true) ? '' : 'disabled' ?>>Conferir e aplicar o zip</button>
          </form>
        </details>
      </li>

      <li>
        <b>3. Voltar atrás</b>
        <p class="muted">Cada atualização guarda <code>app/</code> e o banco em <code>data/backups/</code>. Restaurar troca o código de volta; o banco fica na pasta, para você decidir se também volta.</p>
        <?php if (($atualizacao['copias'] ?? []) === []): ?>
          <p class="muted">Nenhuma cópia ainda — a primeira é criada na primeira atualização.</p>
        <?php else: ?>
          <form method="post" action="<?= e($config->url('/admin/atualizacao/restaurar')) ?>" class="update-form"
                onsubmit="return confirm('Restaurar esta cópia? O código atual é substituído pela versão guardada.');">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <label class="field">Cópia guardada
              <select name="copia">
                <?php foreach ($atualizacao['copias'] as $copia): ?>
                  <option value="<?= e((string) $copia['nome']) ?>">
                    <?= e((string) $copia['nome']) ?> — v<?= e((string) $copia['versao']) ?><?= $copia['banco'] ? ' · com banco' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <button class="button danger-outline" type="submit">Restaurar esta cópia</button>
          </form>
        <?php endif; ?>
      </li>
    </ol>

    <p class="hint">
      O que a atualização nunca faz: tocar em <code>data/</code> (banco, imagens, backups), no <code>.env</code>
      ou em arquivos fora de <code>app/</code> e <code>public/</code>. A migração do banco roda sozinha na primeira abertura.
      <?php if (empty($atualizacao['tem_curl'])): ?><br><b>Atenção:</b> cURL está desativado neste servidor — a consulta ao GitHub não vai funcionar; use o envio do zip.<?php endif; ?>
    </p>
  <?php endif; ?>
</section>

<section class="panel" id="acoes">
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
