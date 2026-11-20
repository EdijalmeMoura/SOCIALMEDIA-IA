<?php
/**
 * Falha durante a confirmação — separando GRAVAR de FECHAR.
 *
 * A instalação tem duas fases. Se falhar ao gravar, nada foi criado (ou o banco
 * ficou intacto e dá para tentar de novo). Se falhar ao fechar, **a conta já
 * existe** e a única coisa certa a fazer é entrar por /login. Misturar as duas
 * coisas fazia a pessoa refazer o assistente achando que não tinha instalado.
 *
 * A causa técnica (arquivo:linha) aparece sempre: numa tela dessas, esconder a
 * causa é o que transforma um erro de 2 minutos num mistério de horas.
 *
 * @var string $etapa 'gravar'|'fechar'
 * @var string $mensagem
 * @var string $arquivo
 * @var int $linha
 * @var bool $criada
 * @var string $token
 * @var bool $debug
 * @var array<string,mixed>|null $registro
 * @var \Smia\Support\Config $config
 */
$registro = $registro ?? null;
$gravou = $etapa === 'fechar' || $criada;
ob_start();
?>
<div class="install-head">
  <div class="install-kicker install-kicker-fail">
    <?= $gravou ? 'INSTALAÇÃO GRAVADA · FALHA NO ACABAMENTO' : 'FALHA AO GRAVAR' ?>
  </div>
  <?php if ($gravou): ?>
    <h1>A conta foi criada. Falhou só o último passo.</h1>
    <p>O banco e a conta do proprietário <b>já estão gravados</b>. Nada precisa ser
      refeito: entre por <code>/login</code> com o e-mail e a senha que você definiu.</p>
  <?php else: ?>
    <h1>A instalação não foi concluída.</h1>
    <p>Nenhuma conta foi criada. Corrigi a causa indicada abaixo e envie a
      confirmação de novo — o rascunho da revisão continua válido por 30 minutos.</p>
  <?php endif; ?>
</div>

<div class="install-errors" role="alert">
  <ul>
    <li><b>Etapa:</b> <?= e($gravou ? 'acabamento (sessão / redirecionamento)' : 'gravação no banco') ?></li>
    <li><b>Causa:</b> <?= e($mensagem) ?></li>
    <li><b>Onde:</b> <code><?= e($arquivo . ':' . $linha) ?></code></li>
    <li><b>Conta criada?</b> <?= $gravou ? 'sim' : 'não' ?></li>
  </ul>
</div>

<?php if ($registro): ?>
  <section class="install-card">
    <div class="install-card-head">
      <div><h2>Registro da instalação</h2><p>Já está no banco desta instância.</p></div>
      <span class="install-badge badge-ok">OK</span>
    </div>
    <dl class="review-list">
      <dt>Agência</dt><dd><?= e((string) ($registro['agency_name'] ?? '—')) ?></dd>
      <dt>Proprietário</dt><dd><?= e((string) ($registro['owner_email'] ?? '—')) ?></dd>
      <dt>Instalada em</dt><dd><?= e((string) ($registro['installed_at'] ?? '—')) ?> UTC</dd>
    </dl>
  </section>
<?php endif; ?>

<section class="install-card">
  <div class="install-card-head">
    <div><h2>O que fazer agora</h2></div>
  </div>
  <ul class="check-list">
    <?php if ($gravou): ?>
      <li class="check"><span class="check-icon">1</span><div><b>Vá para o login</b><small>Use o e-mail e a senha definidos na revisão.</small></div></li>
      <li class="check"><span class="check-icon">2</span><div><b>Se a senha não entrar</b><small>Apague <code>data/socialmedia.sqlite</code> e <code>data/installer-drafts/</code>, e refaça o assistente.</small></div></li>
    <?php else: ?>
      <li class="check"><span class="check-icon">1</span><div><b>Corrija a causa acima</b><small>Permissão da pasta <code>data/</code> costuma ser a razão (precisa ser 755 ou 775, gravável pelo PHP).</small></div></li>
      <li class="check"><span class="check-icon">2</span><div><b>Tente de novo</b><small>Volte à revisão e confirme outra vez; o rascunho é reaproveitado.</small></div></li>
    <?php endif; ?>
    <li class="check"><span class="check-icon">3</span><div><b>O erro também está no log</b><small>Prefixo <code>[SMIA][install]</code> no log de erros do PHP.</small></div></li>
  </ul>
</section>

<div class="install-actions">
  <?php if ($gravou): ?>
    <a class="button primary" href="<?= e($config->url('/login')) ?>">Ir para o login <span>→</span></a>
  <?php else: ?>
    <a class="button primary" href="<?= e($config->url('/install') . '?access_token=' . rawurlencode($token)) ?>">Voltar ao instalador <span>→</span></a>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$title = $gravou ? 'Instalado — falha no acabamento' : 'Falha ao instalar';
$step = 3;
$topNote = $gravou ? 'Conta criada' : 'Nada foi gravado';
require __DIR__ . '/../layouts/install.php';
