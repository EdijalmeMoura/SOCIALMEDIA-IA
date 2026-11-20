<?php
/**
 * Configurações em cinco abas: IA, agência, preferências, minha conta, sistema.
 *
 * Duas regras que a tela inteira segue:
 *  - **todos veem** tudo (transparência: a pessoa sabe com o que trabalha);
 *  - **quem não é dono/admin vê os campos bloqueados** e um aviso no topo da
 *    aba, em vez de um formulário que falha ao enviar.
 *
 * A chave de IA nunca volta para a tela: só origem, formato e tamanho.
 *
 * @var string $aba
 * @var bool $pode_gerenciar
 * @var array{configurada:bool,origem:string,tamanho:int,prefixo:string,cifrada:bool,formato:string} $situacao
 * @var string $modelo_texto
 * @var string $modelo_imagem
 * @var bool $cofre_disponivel
 * @var array<int,array<string,mixed>> $equipe
 * @var array<string,mixed>|null $resultado_teste
 * @var array<string,string> $agencia
 * @var array{rede:string,tom:string,tags:string,assinatura:string,padrao:bool} $preferencias
 * @var array<string,array{label:string,limite:int,tags:int}> $redes
 * @var array<string,string> $tons
 * @var array{nome:string,email:string,papel:string,super:bool} $conta
 * @var array<int,array{rotulo:string,valor:string,ok:bool}>|null $sistema_versoes
 * @var array<int,array{label:string,status:string,detail:string}>|null $sistema_ambiente
 * @var array<int,array{rotulo:string,caminho:string,ok:bool,detalhe:string}>|null $sistema_arquivos
 * @var string|null $aviso
 * @var string|null $erro
 */
$erro = $erro ?? null;
$resultado_teste = $resultado_teste ?? null;
$aviso = $aviso ?? null;
$equipe = $equipe ?? [];
$pode_gerenciar = $pode_gerenciar ?? false;
$cofre_disponivel = $cofre_disponivel ?? false;
$aba = $aba ?? 'ia';
$rotuloPapel = static fn (string $papel, int $super): string => $super === 1
    ? 'Dono da instalação'
    : (['owner' => 'Proprietário', 'admin' => 'Administrador', 'editor' => 'Editor', 'viewer' => 'Leitor'][$papel] ?? $papel);
$abas = [
    'ia' => 'Chave de IA',
    'agencia' => 'Agência',
    'preferencias' => 'Preferências',
    'conta' => 'Minha conta',
    'sistema' => 'Sistema',
];
// Campos bloqueados para quem só lê. `readonly` + `disabled` no botão é o que
// o navegador respeita; o servidor recusa de novo em cada rota.
$soLeitura = $pode_gerenciar ? '' : 'readonly disabled';
ob_start();
?>
<div class="page-head">
  <div>
    <div class="eyebrow">CONFIGURAÇÕES</div>
    <h1>Como o sistema está ajustado</h1>
    <p class="page-sub">Chave de IA, dados da agência, preferências do estúdio e a sua conta. A chave nunca aparece de volta: só se ela está lá e se funciona.</p>
  </div>
  <div class="page-actions">
    <span class="badge <?= $situacao['configurada'] ? 'badge-live' : '' ?>">
      <?= $situacao['configurada'] ? 'IA configurada' : 'Sem chave de IA' ?>
    </span>
  </div>
</div>

<?php if (!empty($erro)): ?>
  <div class="alert error-alert" role="alert"><?= e($erro) ?></div>
<?php endif; ?>
<?php if (!empty($aviso)): ?>
  <div class="alert success-alert" role="status"><?= e($aviso) ?></div>
<?php endif; ?>

<nav class="settings-tabs" role="tablist" aria-label="Seções das configurações">
  <?php foreach ($abas as $chave => $rotulo): ?>
    <a class="settings-tab <?= $aba === $chave ? 'is-active' : '' ?>"
       role="tab" aria-selected="<?= $aba === $chave ? 'true' : 'false' ?>"
       href="<?= e($config->url('/settings' . ($chave === 'ia' ? '' : '?aba=' . $chave))) ?>">
      <?= e($rotulo) ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if (!$pode_gerenciar && in_array($aba, ['ia', 'agencia', 'preferencias'], true)): ?>
  <div class="alert info-alert">
    Você pode ver esta aba, mas <b>só o proprietário ou um administrador altera</b>.
    O que é da sua conta (nome, e-mail e senha) fica em <a href="<?= e($config->url('/settings?aba=conta')) ?>">Minha conta</a>.
  </div>
<?php endif; ?>

<?php if ($aba === 'ia'): ?>
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
      <dd><?= e($situacao['formato']) ?></dd>
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

<?php elseif ($aba === 'agencia'): ?>
<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Dados da agência</h2>
      <p class="muted">Aparecem no portal do cliente e no rodapé dos relatórios. Nada aqui é obrigatório.</p>
    </div>
    <span class="badge <?= $agencia['ativa'] ? 'badge-ok' : 'badge-off' ?>"><?= $agencia['ativa'] ? 'ativa' : 'inativa' ?></span>
  </div>

  <form method="post" action="<?= e($config->url('/settings/agencia')) ?>" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <div class="form-grid">
      <label class="field span-6">Nome da agência <em>*</em>
        <input name="name" id="agenciaNome" required minlength="2" maxlength="120"
               value="<?= e($agencia['nome']) ?>" <?= $soLeitura ?>>
        <small class="hint">É o nome que aparece no alto da tela e no portal.</small>
      </label>
      <label class="field span-6">Razão social
        <input name="legal_name" id="agenciaRazao" maxlength="160" value="<?= e($agencia['razao']) ?>" <?= $soLeitura ?>>
        <small class="hint">O nome do contrato, se for diferente do comercial.</small>
      </label>
      <label class="field span-6">CPF ou CNPJ
        <input name="document" id="agenciaDoc" inputmode="numeric" maxlength="24"
               value="<?= e($agencia['documento']) ?>" placeholder="só números" <?= $soLeitura ?>>
      </label>
      <label class="field span-6">Telefone / WhatsApp
        <input name="contact_phone" id="agenciaFone" maxlength="40" value="<?= e($agencia['telefone']) ?>" <?= $soLeitura ?>>
      </label>
      <label class="field span-6">E-mail de contato
        <input name="contact_email" type="email" id="agenciaEmail" maxlength="160" value="<?= e($agencia['email']) ?>" <?= $soLeitura ?>>
      </label>
      <label class="field span-6">Site
        <input name="website" id="agenciaSite" maxlength="200" placeholder="https://" value="<?= e($agencia['site']) ?>" <?= $soLeitura ?>>
      </label>
    </div>
    <div class="settings-actions">
      <button class="button primary" type="submit" <?= $pode_gerenciar ? '' : 'disabled' ?>>Salvar dados da agência</button>
      <span class="hint">Criada em <?= e(substr($agencia['desde'], 0, 10)) ?> · identificador <code><?= e($agencia['slug']) ?></code></span>
    </div>
  </form>
</section>

<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Equipe desta agência</h2>
      <p class="muted">Quem entra neste workspace. Para criar agências ou mexer em todas as contas, use a Área do dono.</p>
    </div>
    <span class="version-count"><?= count($equipe) ?></span>
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

<?php elseif ($aba === 'preferencias'): ?>
<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Preferências do estúdio</h2>
      <p class="muted">O que já vem escolhido quando alguém cria uma arte. Cada arte pode mudar depois — isto é só o ponto de partida.</p>
    </div>
    <?php if ($preferencias['padrao']): ?><span class="badge">padrão de fábrica</span><?php endif; ?>
  </div>

  <form method="post" action="<?= e($config->url('/settings/preferencias')) ?>" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <div class="form-grid">
      <label class="field span-6">Rede social padrão
        <select name="default_network" <?= $pode_gerenciar ? '' : 'disabled' ?>>
          <?php foreach ($redes as $chave => $rede): ?>
            <option value="<?= e($chave) ?>" <?= $preferencias['rede'] === $chave ? 'selected' : '' ?>>
              <?= e($rede['label']) ?> · até <?= (int) $rede['limite'] ?> caracteres
            </option>
          <?php endforeach; ?>
        </select>
        <small class="hint">Define o contador de legenda e o limite de hashtags.</small>
      </label>
      <label class="field span-6">Tom de voz padrão
        <select name="default_tone" <?= $pode_gerenciar ? '' : 'disabled' ?>>
          <?php foreach ($tons as $chave => $rotuloTom): ?>
            <option value="<?= e($chave) ?>" <?= $preferencias['tom'] === $chave ? 'selected' : '' ?>><?= e($rotuloTom) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="hint">O tom da marca continua valendo por cima quando o cliente tem um cadastrado.</small>
      </label>
      <label class="field span-12">Hashtags padrão
        <textarea name="default_hashtags" id="prefTags" rows="2" maxlength="600" <?= $soLeitura ?>
                  placeholder="#minhaagencia #atendimento"><?= e($preferencias['tags']) ?></textarea>
        <small class="hint">Entram na legenda das artes novas. Separe por espaço ou vírgula; o “#” é opcional.</small>
      </label>
      <label class="field span-12">Assinatura no fim da legenda
        <input name="default_signature" id="prefAssinatura" maxlength="100" <?= $soLeitura ?>
               value="<?= e($preferencias['assinatura']) ?>" placeholder="Ex.: Fale com a gente no direct.">
      </label>
    </div>
    <div class="settings-actions">
      <button class="button primary" type="submit" <?= $pode_gerenciar ? '' : 'disabled' ?>>Salvar preferências</button>
    </div>
  </form>

  <?php if (($preferencias['tags'] !== '' || $preferencias['assinatura'] !== '') && $pode_gerenciar): ?>
    <form method="post" action="<?= e($config->url('/settings/preferencias/restaurar')) ?>"
          onsubmit="return confirm('Voltar as preferências ao padrão? Suas artes e pautas não são alteradas.');">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <button class="button ghost" type="submit">Voltar ao padrão</button>
    </form>
  <?php endif; ?>
</section>

<section class="panel panel-hint-only">
  <div class="panel-head">
    <div>
      <h2>Ainda não existe (e por quê)</h2>
      <p class="muted">Preferência só entra quando o produto de fato usa o valor.</p>
    </div>
  </div>
  <ul class="settings-plain-list">
    <li><b>Fuso horário</b> — vem do <code>.env</code> da instalação; mudar por aqui sem reiniciar o agendador criaria horários diferentes para a mesma publicação.</li>
    <li><b>Idioma</b> — o produto é escrito em português brasileiro do começo ao fim; um seletor sem tradução seria enfeite.</li>
    <li><b>Logo da agência no portal</b> — hoje o portal usa o logo do <b>cliente</b>, que é o que ele espera ver.</li>
  </ul>
</section>

<?php elseif ($aba === 'conta'): ?>
<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Minha conta</h2>
      <p class="muted">Seus dados de acesso. Esta aba é só sua: ninguém da agência altera por aqui.</p>
    </div>
    <span class="badge"><?= e($rotuloPapel($conta['papel'], $conta['super'] ? 1 : 0)) ?></span>
  </div>

  <form method="post" action="<?= e($config->url('/settings/conta')) ?>" class="settings-form" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <div class="form-grid">
      <label class="field span-6">Seu nome
        <input name="name" required minlength="2" maxlength="120" value="<?= e($conta['nome']) ?>">
      </label>
      <label class="field span-6">Seu e-mail
        <input name="email" type="email" required maxlength="160" value="<?= e($conta['email']) ?>">
        <small class="hint">É com ele que você entra no sistema.</small>
      </label>
    </div>

    <div class="control-divider"></div>
    <h3 class="settings-subtitle">Trocar a senha</h3>
    <p class="hint">Precisa da senha atual. Deixe os três campos vazios para manter a senha como está.</p>
    <div class="form-grid">
      <label class="field span-4">Senha atual
        <input name="current_password" type="password" autocomplete="current-password">
      </label>
      <label class="field span-4">Nova senha
        <input name="new_password" type="password" minlength="8" autocomplete="new-password">
      </label>
      <label class="field span-4">Repita a nova
        <input name="new_password_confirm" type="password" minlength="8" autocomplete="new-password">
      </label>
    </div>
    <div class="settings-actions">
      <button class="button primary" type="submit">Salvar minha conta</button>
      <span class="hint">Se a senha atual não conferir, o perfil é salvo e a senha fica como estava — a mensagem diz qual dos dois.</span>
    </div>
  </form>
</section>

<?php elseif ($aba === 'sistema'): ?>
<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Versões</h2>
      <p class="muted">Para responder “o arquivo novo subiu mesmo?”.</p>
    </div>
  </div>
  <div class="settings-status-grid">
    <?php foreach (($sistema_versoes ?? []) as $item): ?>
      <div class="<?= $item['ok'] ? 'is-ok' : 'is-warn' ?>">
        <strong><?= e($item['rotulo']) ?></strong>
        <span><?= e($item['valor']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Ambiente do servidor</h2>
      <p class="muted">As mesmas checagens do instalador, agora sem precisar reinstalar nada.</p>
    </div>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Item</th><th>Situação</th><th>Detalhe</th></tr></thead>
      <tbody>
        <?php foreach (($sistema_ambiente ?? []) as $check): ?>
          <tr>
            <td><?= e($check['label']) ?></td>
            <td>
              <?php if ($check['status'] === 'ok'): ?>
                <span class="badge badge-ok">ok</span>
              <?php elseif ($check['status'] === 'warn'): ?>
                <span class="badge">atenção</span>
              <?php else: ?>
                <span class="badge badge-off">falha</span>
              <?php endif; ?>
            </td>
            <td class="settings-detail"><?= e($check['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Arquivos e pastas</h2>
      <p class="muted">Se algo não grava, é aqui que aparece.</p>
    </div>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Item</th><th>Situação</th><th>Caminho</th></tr></thead>
      <tbody>
        <?php foreach (($sistema_arquivos ?? []) as $item): ?>
          <tr>
            <td><?= e($item['rotulo']) ?></td>
            <td>
              <span class="badge <?= $item['ok'] ? 'badge-ok' : 'badge-off' ?>"><?= $item['ok'] ? 'ok' : 'atenção' ?></span>
              <small class="settings-detail"><?= e($item['detalhe']) ?></small>
            </td>
            <td><code class="settings-path"><?= e($item['caminho']) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Backup do banco</h2>
      <p class="muted">Uma cópia do banco inteiro num arquivo só, pronta para guardar.</p>
    </div>
  </div>
  <p>
    O arquivo tem <b>tudo</b>: clientes, artes, legendas, aprovações e usuários. Guarde em lugar seguro,
    porque não há senha dentro dele. O banco em uso tem
    <b><?= number_format(((int) ($banco_tamanho ?? 0)) / 1024, 1, ',', '.') ?> KB</b>.
  </p>
  <p class="hint">
    A cópia é feita com o banco aberto (VACUUM INTO), então sai íntegra mesmo com gente usando o sistema.
    Para restaurar: renomeie o arquivo para <code>socialmedia.sqlite</code> e coloque na pasta <code>data/</code>
    com a aplicação parada.
  </p>
  <?php if ($pode_gerenciar): ?>
    <form method="post" action="<?= e($config->url('/settings/sistema/backup')) ?>">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <button class="button primary" type="submit">↓ Baixar backup agora</button>
    </form>
  <?php else: ?>
    <p class="muted">Só o proprietário ou um administrador baixa o backup.</p>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Configurações';
require __DIR__ . '/../layouts/app.php';
