<?php
/**
 * Conexões Meta: OAuth, Páginas conectadas e evidências reais.
 *
 * O painel de evidências existe para não vender fumaça: configuração, OAuth e
 * leitura do Graph aparecem separados de "publicou de verdade pela API".
 *
 * @var bool $configurada
 * @var string|null $settings_erro
 * @var string|null $graph_version
 * @var bool $callback_confere
 * @var bool $midia_pronta
 * @var string $midia_base
 * @var string|null $midia_erro
 * @var array<int,array<string,mixed>> $conexoes
 * @var array<string,mixed> $evidencias
 * @var bool $sessao_segura
 * @var array<int,string> $escopos
 * @var string|null $aviso
 * @var string|null $erro
 */
$rotuloEscopo = [
    'pages_show_list' => 'Listar páginas',
    'pages_read_engagement' => 'Ler engajamento',
    'pages_manage_posts' => 'Publicar no Facebook',
    'instagram_basic' => 'Ler Instagram',
    'instagram_content_publish' => 'Publicar no Instagram',
];
$rotuloStatus = ['granted' => 'autorizada', 'declined' => 'recusada', 'unknown' => 'não confirmada'];
$ultimoFacebook = $evidencias['ultimo_facebook'] ?? null;
$ultimoInstagram = $evidencias['ultimo_instagram'] ?? null;
$ultimaLeitura = $evidencias['ultima_leitura'] ?? null;
$ultimoTls = $evidencias['ultimo_tls'] ?? null;
$ultimaMidia = $evidencias['ultima_midia'] ?? null;
ob_start();
?>
<div class="page-head meta-head">
  <div>
    <div class="eyebrow">CONEXÃO SOCIAL</div>
    <h1>Meta · Facebook e Instagram</h1>
    <p class="page-sub">Conecte Páginas e descubra as contas profissionais do Instagram vinculadas.</p>
  </div>
  <div class="page-actions">
    <span class="meta-connection-state <?= $configurada ? 'is-ready' : 'is-pending' ?>">
      <?= $configurada ? 'Configuração detectada' : 'Configuração pendente' ?>
    </span>
  </div>
</div>

<?php if (!empty($aviso)): ?>
  <div class="alert success-alert"><?= e($aviso) ?></div>
<?php endif; ?>
<?php if (!empty($erro)): ?>
  <div class="alert error-alert"><?= e($erro) ?></div>
<?php endif; ?>

<div class="meta-truth-note">
  <span>ⓘ</span>
  <p><b>Publicação é manual e exige horário vencido; não há envio automático.</b>
    O painel abaixo mostra apenas respostas reais registradas nesta agência: leitura do Graph confirmada e
    publicações com resposta da API. Configuração presente, consentimento OAuth ou teste simulado **não** contam
    como publicação ao vivo.</p>
</div>

<section class="panel meta-evidence">
  <div class="panel-heading">
    <div>
      <h3>O que falta para validar a integração</h3>
      <p>Evidências deste workspace, uma por etapa.</p>
    </div>
  </div>
  <div class="meta-evidence-grid">
    <article>
      <b>OAuth e Páginas</b>
      <strong><?= count($conexoes) ?> salva(s)</strong>
      <small>É necessário concluir o OAuth neste workspace e escolher os ativos corretos.</small>
    </article>
    <article>
      <b>Callback OAuth</b>
      <strong><?= $callback_confere ? 'Host e rota conferem' : 'Revisar configuração' ?></strong>
      <small>Compara META_REDIRECT_URI com este host e a rota /integrations/meta/callback.</small>
    </article>
    <article>
      <b>Leitura do Graph</b>
      <strong><?= $ultimaLeitura !== null ? 'Confirmada' : 'Pendente' ?></strong>
      <small><?= $ultimaLeitura !== null
          ? e((string) $ultimaLeitura['page_name']) . ' · ' . e((string) $ultimaLeitura['checked_at']) . ' UTC'
          : 'Use “Testar conexão”; isso não comprova permissão de publicação.' ?></small>
    </article>
    <article>
      <b>Saída HTTPS para o Graph</b>
      <strong><?= $ultimoTls === null ? 'Ainda não testada' : ($ultimoTls['status'] === 'ok' ? 'TLS confirmado' : 'Teste falhou') ?></strong>
      <small><?= $ultimoTls !== null
          ? e((string) $ultimoTls['details']) . ' · ' . e((string) $ultimoTls['checked_at']) . ' UTC'
          : 'Testa apenas o handshake TLS do servidor com graph.facebook.com; não consulta OAuth.' ?></small>
      <?php if ($configurada): ?>
        <form method="post" action="<?= e($config->url('/meta/graph-check')) ?>">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <button class="button outline small-button" type="submit">Testar saída</button>
        </form>
      <?php endif; ?>
    </article>
    <article>
      <b>Facebook · publicação real</b>
      <strong><?= $ultimoFacebook !== null ? 'Confirmada pela API' : 'Ainda não comprovada' ?></strong>
      <small><?= $ultimoFacebook !== null
          ? 'ID ' . e((string) $ultimoFacebook['remote_publication_id']) . ' · ' . e((string) $ultimoFacebook['created_at']) . ' UTC'
          : 'Exige um post manual de teste com resposta de sucesso da API.' ?></small>
    </article>
    <article>
      <b>Instagram · publicação real</b>
      <strong><?= $ultimoInstagram !== null ? 'Confirmada pela API' : 'Ainda não comprovada' ?></strong>
      <small><?= $ultimoInstagram !== null
          ? 'ID ' . e((string) $ultimoInstagram['remote_publication_id']) . ' · ' . e((string) $ultimoInstagram['created_at']) . ' UTC'
          : 'Exige imagem aprovada, URL pública acessível pela Meta e post manual de teste.' ?></small>
    </article>
    <article>
      <b>URL de mídia HTTPS</b>
      <strong><?= $midia_pronta ? 'Configurada' : 'Pendente' ?></strong>
      <small><?php if (!$midia_pronta): ?><?= e((string) $midia_erro) ?>
        <?php elseif ($ultimaMidia !== null && (string) $ultimaMidia['base_url'] === $midia_base): ?>
          <?= $ultimaMidia['status'] === 'ok' ? 'Servidor buscou o JPEG assinado' : 'Último teste falhou' ?> ·
          <?= e((string) $ultimaMidia['checked_at']) ?> UTC
        <?php else: ?>Origem pronta: <?= e($midia_base) ?>. Uma imagem só é servida com assinatura e prazo.<?php endif; ?></small>
    </article>
    <article>
      <b>Segurança da sessão</b>
      <strong><?= $sessao_segura ? 'Configuração básica OK' : 'Pendente no deploy' ?></strong>
      <small>Exige SECRET_KEY com 32+ caracteres e COOKIE_SECURE=1; o valor do segredo nunca é exibido.</small>
    </article>
    <article>
      <b>Revisão e acesso avançado do App</b>
      <strong>Verificação externa necessária</strong>
      <small>O produto não consegue consultar o estado de revisão no painel do App Meta.</small>
    </article>
  </div>
</section>

<div class="meta-layout">
  <section class="panel meta-connect">
    <div class="panel-heading">
      <div>
        <h3>Conexão oficial por OAuth</h3>
        <p>Quem autoriza é você, na própria Meta — o produto nunca pede senha do Facebook.</p>
      </div>
    </div>

    <?php if (!$configurada): ?>
      <div class="meta-config-error">
        <b>Falta configuração segura do servidor</b>
        <p><?= e((string) $settings_erro) ?></p>
      </div>
    <?php endif; ?>

    <div class="meta-checklist">
      <div><span><?= $configurada ? '✓' : '01' ?></span><div><b>Configurar App Meta</b><small>META_APP_ID, META_APP_SECRET e META_GRAPH_VERSION</small></div></div>
      <div><span><?= $configurada ? '✓' : '02' ?></span><div><b>Definir callback e criptografia</b><small>META_REDIRECT_URI em HTTPS e META_TOKEN_ENCRYPTION_KEY</small></div></div>
      <div><span>03</span><div><b>Revisar permissões no App</b><small><?= e(implode(', ', $escopos)) ?></small></div></div>
      <div><span><?= $midia_pronta ? '✓' : '04' ?></span><div><b>Disponibilizar imagens publicamente</b><small><?= $midia_pronta ? 'Origem HTTPS configurada; acessibilidade externa não foi testada.' : 'Origem HTTPS pendente (META_PUBLIC_BASE_URL).' ?> Necessário para Instagram e Facebook com foto.</small></div></div>
    </div>

    <?php if ($configurada): ?>
      <form method="post" action="<?= e($config->url('/meta/connect')) ?>" class="meta-connect-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="button primary" type="submit"><?= $conexoes === [] ? 'Conectar com Facebook' : 'Atualizar conexão e permissões' ?> <span>↗</span></button>
        <p class="schedule-note">Versão Graph configurada: <?= e((string) $graph_version) ?> · os tokens ficam criptografados no banco.</p>
      </form>
    <?php else: ?>
      <button class="button primary" type="button" disabled>Configure o servidor para conectar</button>
    <?php endif; ?>
  </section>

  <aside class="panel meta-safety">
    <span class="eyebrow muted-eyebrow">SEGURANÇA E ESCOPO</span>
    <ul>
      <li>App Secret e chave de criptografia ficam só no <code>.env</code>.</li>
      <li>Tokens de Página são cifrados em repouso (AES-256-GCM) e nunca aparecem na interface.</li>
      <li>O fluxo confere <code>state</code>, prazo de 10 minutos e isolamento por agência.</li>
      <li>A agência pode remover uma Página e apagar o token salvo na hora.</li>
    </ul>
    <p class="schedule-note">A Meta pode exigir App Review e acesso avançado às permissões antes de liberar contas fora do papel de desenvolvedor/testador.</p>
  </aside>
</div>

<section class="panel meta-pages">
  <div class="panel-heading">
    <div>
      <h3>Páginas conectadas</h3>
      <p>Os estados refletem a última autorização OAuth; reconecte para atualizá-los. Eles não comprovam revisão do App nem acesso avançado.</p>
    </div>
    <span class="schedule-chip"><?= count($conexoes) ?></span>
  </div>

  <?php if ($conexoes === []): ?>
    <div class="empty-state small-empty">
      <div class="empty-icon">◎</div>
      <b>Nenhuma Página conectada</b>
      <p>Configure o App Meta e autorize as páginas que pertencem à sua operação.</p>
    </div>
  <?php else: ?>
    <div class="meta-page-list">
      <?php foreach ($conexoes as $conexao): ?>
        <article class="meta-page-row">
          <span class="meta-page-avatar">f</span>
          <div class="meta-page-copy">
            <b><?= e((string) $conexao['page_name']) ?></b>
            <small>Página ID <?= e((string) $conexao['page_id']) ?> · conectada <?= e((string) $conexao['connected_at']) ?></small>
            <small class="meta-token token-<?= e((string) $conexao['token_expiry_status']) ?>">
              <?php if ($conexao['token_expiry_status'] === 'future'): ?>
                Prazo registrado do token OAuth: <?= e((string) $conexao['token_expiry_utc']) ?> UTC
              <?php elseif ($conexao['token_expiry_status'] === 'expiring'): ?>
                Prazo registrado do token OAuth se aproxima: <?= e((string) $conexao['token_expiry_utc']) ?> UTC
              <?php elseif ($conexao['token_expiry_status'] === 'expired'): ?>
                Prazo registrado do token OAuth passou: <?= e((string) $conexao['token_expiry_utc']) ?> UTC · reconecte e teste a leitura
              <?php else: ?>
                A Meta não informou prazo de validade para o token OAuth.
              <?php endif; ?>
            </small>
          </div>

          <div class="meta-scopes">
            <b>Status das permissões solicitadas</b>
            <?php if ($conexao['permissions'] === []): ?>
              <span class="permission-unknown">Não foram consultadas na autorização salva.</span>
            <?php else: ?>
              <?php foreach ($conexao['permissions'] as $permissao): ?>
                <span class="permission-<?= e((string) $permissao['status']) ?>" title="Consentimento OAuth capturado em <?= e((string) $permissao['checked_at']) ?>">
                  <?= e($rotuloEscopo[(string) $permissao['permission']] ?? (string) $permissao['permission']) ?> ·
                  <?= e($rotuloStatus[(string) $permissao['status']] ?? (string) $permissao['status']) ?>
                </span>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <?php if (!empty($conexao['instagram_user_id'])): ?>
            <span class="meta-instagram-pill">Instagram · @<?= e((string) ($conexao['instagram_username'] ?? $conexao['instagram_user_id'])) ?></span>
          <?php else: ?>
            <span class="meta-no-instagram">Sem conta profissional vinculada</span>
          <?php endif; ?>

          <div class="meta-check-result <?= ($conexao['last_check_status'] ?? null) === 'ok' ? 'is-ok' : (($conexao['last_check_status'] ?? null) === 'failed' ? 'is-failed' : '') ?>">
            <?php if (!empty($conexao['last_check_status'])): ?>
              <b><?= $conexao['last_check_status'] === 'ok' ? 'Leitura Graph confirmada' : 'Falha na verificação' ?></b>
              <small><?= e((string) $conexao['last_check_details']) ?> · <?= e((string) $conexao['last_checked_at']) ?></small>
            <?php else: ?>
              <small>Ainda não verificada no Graph API.</small>
            <?php endif; ?>
          </div>

          <div class="meta-page-actions">
            <?php if ($configurada): ?>
              <form method="post" action="<?= e($config->url('/meta/' . (int) $conexao['id'] . '/test')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="button outline small-button" type="submit">Testar conexão</button>
              </form>
            <?php endif; ?>
            <form method="post" action="<?= e($config->url('/meta/' . (int) $conexao['id'] . '/disconnect')) ?>" data-confirm="Desconectar esta Página e remover o token guardado?">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <button class="button danger-outline small-button" type="submit">Desconectar</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = 'Conexões Meta';
require __DIR__ . '/../layouts/app.php';
