<?php
/**
 * Configurações: chave de IA, modelos e equipe da agência.
 *
 * Esta tela é a resposta para "onde eu coloco a chave?". Ela nunca devolve o
 * valor da chave: mostra origem, formato e tamanho. Quem quiser saber se ela
 * funciona usa o botão de teste, que chama o provedor no servidor.
 *
 * @var bool $pode_gerenciar
 * @var array{configurada:bool,origem:string,tamanho:int,prefixo:string,cifrada:bool,formato:string} $situacao
 * @var string $modelo_texto
 * @var string $modelo_imagem
 * @var bool $cofre_disponivel
 * @var array<int,array<string,mixed>> $equipe
 * @var array<string,mixed>|null $resultado_teste
 * @var string|null $aviso
 * @var string|null $erro
 */
$erro = $erro ?? null;
$resultado_teste = $resultado_teste ?? null;
$aviso = $aviso ?? null;
$equipe = $equipe ?? [];
$pode_gerenciar = $pode_gerenciar ?? false;
$cofre_disponivel = $cofre_disponivel ?? false;
$rotuloPapel = static fn (string $papel, int $super): string => $super === 1
    ? 'Dono da instalação'
    : ['owner' => 'Proprietário', 'admin' => 'Administrador', 'editor' => 'Editor', 'viewer' => 'Leitor'][$papel] ?? $papel;
ob_start();
?>
<div class="page-head">
  <div>
    <div class="eyebrow">CONFIGURAÇÕES</div>
    <h1>Chave de IA e modelos</h1>
    <p class="page-sub">A chave fica no servidor. Esta tela nunca mostra o valor de volta — só se ele está lá e se funciona.</p>
  </div>
</div>

<?php if (!empty($erro)): ?>
  <div class="alert error-alert" role="alert"><?= e($erro) ?></div>
<?php endif; ?>

<section class="panel settings-ia">
  <div class="panel-head">
    <div>
      <h2>Chave da OpenAI</h2>
      <p class="muted">Usada pelo planejamento, pelo estúdio de textos e pela geração de imagem.</p>
    </div>
    <span class="badge <?= $situacao['configurada'] ? 'badge-ok' : 'badge-off' ?>">
      <?= $situacao['configurada'] ? 'configurada' : 'sem chave' ?>
    </span>
  </div>

  <dl class="settings-facts">
    <div>
      <dt>Situação</dt>
      <dd>
        <?php if ($situacao['configurada']): ?>
          <?= e($situacao['formato']) ?>
        <?php else: ?>
          <?= e($situacao['formato']) ?>
        <?php endif; ?>
      </dd>
    </div>
    <div>
      <dt>Origem do valor em uso</dt>
      <dd><?= e($situacao['origem']) ?><?= $situacao['configurada'] ? ' · ' . (int) $situacao['tamanho'] . ' caracteres' : '' ?></dd>
    </div>
    <div>
      <dt>Armazenamento</dt>
      <dd>
        <?php if (!$situacao['configurada']): ?>
          —
        <?php elseif ($situacao['cifrada']): ?>
          cifrada no banco (AES-256-GCM com o SECRET_KEY da instalação)
        <?php else: ?>
          <?= $situacao['origem'] === 'Configurações (este painel)'
            ? 'em claro no banco — falta SECRET_KEY nesta instalação'
            : 'fora do banco (.env ou ambiente do servidor)' ?>
        <?php endif; ?>
      </dd>
    </div>
  </dl>

  <?php if (!$cofre_disponivel && $pode_gerenciar): ?>
    <div class="alert">
      Esta instalação está sem <code>SECRET_KEY</code>, então a chave gravada aqui ficaria em claro no banco (arquivo já protegido com permissão 600).
      Se quiser cifrar, defina <code>SECRET_KEY</code> no <code>.env</code> e recarregue. A chave funciona igual nos dois casos.
    </div>
  <?php endif; ?>

  <?php if ($resultado_teste !== null): ?>
    <div class="alert <?= !empty($resultado_teste['ok']) ? '' : 'error-alert' ?>" role="status">
      <b><?= e((string) $resultado_teste['titulo']) ?></b> · testado às <?= e((string) $resultado_teste['quando']) ?>
      <br><?= e((string) $resultado_teste['detalhe']) ?>
    </div>
  <?php endif; ?>

  <?php if ($pode_gerenciar): ?>
    <form method="post" action="<?= e($config->url('/settings/ia/chave')) ?>" class="settings-form" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <label class="field">
        <?= $situacao['configurada'] ? 'Trocar a chave' : 'Colar a chave da OpenAI' ?>
        <input type="password" name="openai_api_key" placeholder="sk-svcacct-…" autocomplete="new-password" spellcheck="false">
        <small class="hint">Cole só o valor. Nada de aspas, espaço ou comentário junto. A chave não é exibida depois de salva.</small>
      </label>
      <div class="settings-actions">
        <button class="button primary" type="submit">Salvar chave</button>
      </div>
    </form>

    <div class="settings-actions settings-actions-row">
      <form method="post" action="<?= e($config->url('/settings/ia/testar')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="button outline" type="submit">Testar chave agora</button>
      </form>
      <?php if ($situacao['origem'] === 'Configurações (este painel)'): ?>
        <form method="post" action="<?= e($config->url('/settings/ia/chave/remover')) ?>"
              onsubmit="return confirm('Remover a chave gravada neste painel? Se houver uma no .env, ela volta a valer.');">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <button class="button ghost" type="submit">Remover chave deste painel</button>
        </form>
      <?php endif; ?>
    </div>
    <p class="hint">O teste gasta 1 token e responde com o que o provedor disse: aceita, recusada, modelo inexistente, sem cota, ou servidor sem certificado/rede.</p>
  <?php else: ?>
    <p class="muted">Somente o proprietário ou um administrador da agência troca a chave.</p>
  <?php endif; ?>
</section>

<section class="panel settings-modelos">
  <div class="panel-head">
    <div>
      <h2>Modelos</h2>
      <p class="muted">Se a OpenAI desligar um modelo, o erro cita o nome — troque aqui, sem mexer em arquivo.</p>
    </div>
  </div>
  <?php if ($pode_gerenciar): ?>
    <form method="post" action="<?= e($config->url('/settings/ia/modelos')) ?>" class="settings-form settings-form-inline">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <label class="field">Texto
        <input type="text" name="openai_model" value="<?= e($modelo_texto) ?>" spellcheck="false">
      </label>
      <label class="field">Imagem
        <input type="text" name="openai_image_model" value="<?= e($modelo_imagem) ?>" spellcheck="false">
      </label>
      <button class="button primary" type="submit">Salvar modelos</button>
    </form>
  <?php else: ?>
    <p class="muted"><?= e($modelo_texto) ?> · <?= e($modelo_imagem) ?></p>
  <?php endif; ?>
</section>

<section class="panel" id="equipe">
  <div class="panel-head">
    <div>
      <h2>Equipe desta agência</h2>
      <p class="muted">Quem entra neste workspace. Para criar agências ou mexer em todas as contas, use a Área do dono.</p>
    </div>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>Nome</th><th>E-mail</th><th>Papel</th><th>Situação</th><th>Último acesso</th></tr>
      </thead>
      <tbody>
        <?php foreach ($equipe as $pessoa): ?>
          <tr>
            <td><?= e((string) $pessoa['name']) ?></td>
            <td><?= e((string) $pessoa['email']) ?></td>
            <td><?= e($rotuloPapel((string) $pessoa['role'], (int) ($pessoa['is_super'] ?? 0))) ?></td>
            <td>
              <?php if ((int) ($pessoa['is_active'] ?? 1) === 1): ?>
                <span class="badge badge-ok">ativo</span>
              <?php else: ?>
                <span class="badge badge-off">desativado</span>
              <?php endif; ?>
            </td>
            <td><?= $pessoa['last_login_at'] ? e(date('d/m/Y H:i', (int) strtotime((string) $pessoa['last_login_at'] . ' UTC'))) . ' UTC' : 'nunca' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint">Para criar, desativar ou trocar a senha de alguém, abra a <b>Área do dono</b>.</p>
</section>
<?php
$content = ob_get_clean();
$title = 'Configurações';
require __DIR__ . '/../layouts/app.php';
