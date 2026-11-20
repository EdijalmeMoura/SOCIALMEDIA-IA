<?php
/**
 * Diagnóstico da instalação — GET /install/diagnostico
 *
 * Existe porque "a instalação não termina" pode ser qualquer coisa: token, CSRF,
 * sessão, permissão da pasta data/, versão antiga do arquivo. Em vez de
 * adivinhar, esta página mede o estado real do servidor e mostra em texto
 * simples.
 *
 * **Não** mostra segredo: o token aparece só como "definido/não definido" e a
 * senha nunca existe aqui. Não grava nada. Não cria conta.
 *
 * @var \Smia\Support\Config $config
 * @var array<int,array{rotulo:string,valor:string,ok:bool,dica:string}> $linhas
 * @var array<int,string> $arquivos lista de arquivos-chave com mtime
 * @var string $resumo
 * @var bool $precisaInstalar
 * @var string $tokenDigitado
 */
ob_start();
?>
<div class="install-head">
  <div class="install-kicker">DIAGNÓSTICO</div>
  <h1><?= e($resumo) ?></h1>
  <p>Retrato do que este servidor está executando agora. Nada é gravado e
    nenhum segredo é exibido. Se precisar de ajuda, copie este quadro inteiro.</p>
</div>

<section class="install-card">
  <div class="install-card-head">
    <div><h2>Estado</h2><p>Medido no momento em que esta página abriu.</p></div>
    <span class="install-badge <?= $precisaInstalar ? 'badge-off' : 'badge-ok' ?>">
      <?= $precisaInstalar ? 'não instalado' : 'instalado' ?>
    </span>
  </div>
  <dl class="review-list">
    <?php foreach ($linhas as $linha): ?>
      <dt><?= e($linha['rotulo']) ?></dt>
      <dd>
        <b style="color:<?= $linha['ok'] ? '#0a7d36' : '#b3261e' ?>"><?= e($linha['valor']) ?></b>
        <?php if ($linha['dica'] !== ''): ?><br><small><?= e($linha['dica']) ?></small><?php endif; ?>
      </dd>
    <?php endforeach; ?>
  </dl>
</section>

<section class="install-card">
  <div class="install-card-head">
    <div><h2>Última tentativa de instalação</h2><p>Etapas concluídas, na ordem. A que falta é onde parou.</p></div>
    <?php
    $feitas = array_map(static fn ($e) => (string) ($e['etapa'] ?? ''), $trilha);
    $terminou = in_array('fim', $feitas, true);
    ?>
    <span class="install-badge <?= $terminou ? 'badge-ok' : ($trilha === [] ? 'badge-off' : 'badge-off') ?>">
      <?= $trilha === [] ? 'sem registro' : ($terminou ? 'concluída' : 'parou no meio') ?>
    </span>
  </div>
  <?php if ($trilha === []): ?>
    <p class="review-empty">Nenhuma tentativa de confirmação registrada nesta pasta de dados ainda.</p>
  <?php else: ?>
    <dl class="review-list">
      <?php foreach ($trilha as $evento): ?>
        <dt><?= e((string) ($evento['em'] ?? '')) ?> UTC</dt>
        <dd><code><?= e((string) ($evento['etapa'] ?? '')) ?></code></dd>
      <?php endforeach; ?>
    </dl>
    <?php if (!$terminou): ?>
      <p class="review-empty">
        A trilha <b>não chegou a <code>fim</code></b>. Se a última etapa é
        <code>inicio</code> ou <code>migracao_ok</code>, a gravação nem começou.
        Se é <code>transacao_inicio</code>, o banco recusou a transação — a causa
        está no log com o prefixo <code>[SMIA][install]</code>.
      </p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="install-card">
  <div class="install-card-head">
    <div><h2>Arquivos em uso</h2><p>Confirma se o pacote novo foi realmente enviado.</p></div>
  </div>
  <dl class="review-list">
    <?php foreach ($arquivos as $rotulo => $dados): ?>
      <dt><?= e((string) $rotulo) ?></dt>
      <dd><code><?= e($dados['caminho']) ?></code><br><small>alterado em <?= e($dados['quando']) ?></small></dd>
    <?php endforeach; ?>
  </dl>
</section>

<section class="install-card">
  <div class="install-card-head">
    <div><h2>Ir para o instalador</h2><p>Sem token na URL desta página, por segurança.</p></div>
  </div>
  <form method="post" action="<?= e($config->url('/install/access')) ?>" class="install-form">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="next" value="/install">
    <label class="install-field span-12">Token de instalação
      <input type="password" name="access_token" autocomplete="off" data-no-autofill
             value="<?= e($tokenDigitado) ?>" placeholder="cole o valor de SOCIALMEDIA_INSTALL_TOKEN">
    </label>
    <button class="button primary install-submit" type="submit">Abrir o instalador <span>→</span></button>
  </form>
</section>
<?php
$content = ob_get_clean();
$title = 'Diagnóstico da instalação';
$step = $precisaInstalar ? 1 : 3;
$topNote = 'Somente leitura';
require __DIR__ . '/../layouts/install.php';
