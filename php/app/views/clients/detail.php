<?php
/**
 * Perfil do cliente: dados do negócio, identidade visual e referências.
 *
 * @var array<string,mixed> $cliente
 * @var array<int,array<string,mixed>> $referencias
 * @var bool $pode_editar
 * @var bool $pode_excluir
 * @var string|null $aviso
 * @var string|null $erroUpload
 * @var string $csrf
 * @var array<int,array{type:string,message:string}> $flashes
 * @var \Smia\Support\Config $config
 */
use Smia\Clients\ClientRepository;

// O layout carrega o JS da análise (o controller manda em `scripts_extras`).

$v = static fn (string $campo): string => (string) ($cliente[$campo] ?? '');
$cor = ClientRepository::corValida($v('primary_color')) ? $v('primary_color') : '#3158d8';
$corSecundaria = ClientRepository::corValida($v('secondary_color')) ? $v('secondary_color') : '#f57a45';
$corDestaque = ClientRepository::corValida($v('accent_color')) ? $v('accent_color') : '#16a68a';
$nome = trim($v('trade_name')) !== '' ? $v('trade_name') : $v('company_name');
$logo = $v('logo_path');
$desabilitar = $pode_editar ? '' : ' disabled';
ob_start();
?>
<div class="detail-top">
  <div>
    <a class="back-link" href="<?= e($config->url('/clients')) ?>">← <span>Todos os clientes</span></a>
    <div class="detail-title-row">
      <div class="detail-logo" style="--client-color:<?= e($cor) ?>">
        <?php if ($logo !== ''): ?>
          <img src="<?= e($config->url('/clients/file?file=' . rawurlencode($logo))) ?>" alt="Logo <?= e($v('company_name')) ?>">
        <?php else: ?>
          <?= e(mb_strtoupper(mb_substr($v('company_name'), 0, 1))) ?>
        <?php endif; ?>
      </div>
      <div>
        <div class="eyebrow muted-eyebrow">PERFIL DO CLIENTE <span class="active-tag"><?= $cliente['status'] === 'active' ? 'Ativo' : 'Inativo' ?></span></div>
        <h1><?= e($nome) ?></h1>
        <p class="page-subtitle">
          <?= e($v('segment') !== '' ? $v('segment') : 'Segmento não informado') ?>
          <span class="dot-separator">·</span> Cliente desde <?= e(mb_substr($v('created_at'), 0, 10)) ?>
        </p>
      </div>
    </div>
  </div>
  <?php if ($pode_editar): ?>
    <div class="detail-actions">
      <?php if ($pode_excluir): ?>
        <form method="post" action="<?= e($config->url('/clients/' . $cliente['id'] . '/delete')) ?>"
              data-confirm="Tem certeza que deseja excluir este cliente e todos os seus arquivos?">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <button class="button danger-outline" type="submit">Excluir cliente</button>
        </form>
      <?php endif; ?>
      <button type="button" class="button primary" data-submit-form="brandForm">Salvar alterações <span>✓</span></button>
    </div>
  <?php endif; ?>
</div>

<?php if ($aviso !== null): ?>
  <div class="toast-message"><?= e($aviso) ?></div>
<?php endif; ?>
<?php if ($erroUpload !== null): ?>
  <div class="alert error-alert"><?= e($erroUpload) ?></div>
<?php endif; ?>

<div class="detail-tabs">
  <a class="selected" href="#business">Informações do negócio</a>
  <a href="#brand">Identidade visual</a>
  <a href="#references">Referências <span><?= count($referencias) ?></span></a>
</div>

<form method="post" id="brandForm" action="<?= e($config->url('/clients/' . $cliente['id'])) ?>" class="client-form detail-form">
  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

  <section class="form-section" id="business">
    <div class="form-section-title">
      <span class="section-number">01</span>
      <div><h2>Sobre o negócio</h2><p>Essas informações ficam disponíveis para os próximos módulos.</p></div>
    </div>
    <div class="form-grid">
      <label class="field span-6">Nome da empresa <em>*</em>
        <input name="company_name" required maxlength="200" value="<?= e($v('company_name')) ?>"<?= $desabilitar ?>>
      </label>
      <label class="field span-6">Nome comercial
        <input name="trade_name" maxlength="500" value="<?= e($v('trade_name')) ?>"<?= $desabilitar ?>>
      </label>
      <label class="field span-6">Segmento
        <input name="segment" maxlength="500" value="<?= e($v('segment')) ?>"<?= $desabilitar ?>>
      </label>
      <label class="field span-6">Site
        <input name="website" maxlength="500" value="<?= e($v('website')) ?>"<?= $desabilitar ?>>
      </label>
      <label class="field span-12">Descrição
        <textarea name="description" rows="3" maxlength="4000"<?= $desabilitar ?>><?= e($v('description')) ?></textarea>
      </label>
      <label class="field span-6">Produtos e serviços
        <textarea name="products_services" rows="3" maxlength="4000"<?= $desabilitar ?>><?= e($v('products_services')) ?></textarea>
      </label>
      <label class="field span-6">Público-alvo
        <textarea name="audience" rows="3" maxlength="4000"<?= $desabilitar ?>><?= e($v('audience')) ?></textarea>
      </label>
      <label class="field span-12">Objetivos comerciais
        <textarea name="business_goals" rows="3" maxlength="4000"<?= $desabilitar ?>><?= e($v('business_goals')) ?></textarea>
      </label>
      <label class="field span-4">Telefone
        <input name="phone" maxlength="500" value="<?= e($v('phone')) ?>"<?= $desabilitar ?>>
      </label>
      <label class="field span-4">WhatsApp
        <input name="whatsapp" maxlength="500" value="<?= e($v('whatsapp')) ?>"<?= $desabilitar ?>>
      </label>
      <label class="field span-4">Endereço
        <input name="address" maxlength="500" value="<?= e($v('address')) ?>"<?= $desabilitar ?>>
      </label>
      <label class="field span-4">Rede social principal
        <select name="social_network" id="socialNetwork"<?= $desabilitar ?>>
          <option value="">Escolha a rede</option>
          <?php foreach (($redes ?? []) as $chaveRede => $rotuloRede): ?>
            <option value="<?= e($chaveRede) ?>" <?= $v('social_network') === $chaveRede ? 'selected' : '' ?>><?= e($rotuloRede) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field span-8">Endereço do perfil
        <input name="profile_url" id="profileUrl" maxlength="500" value="<?= e($v('profile_url')) ?>"<?= $desabilitar ?>
               placeholder="@nomedamarca ou https://instagram.com/nomedamarca">
      </label>
      <?php if ($pode_editar): ?>
        <div class="field span-12 analyzer-actions">
          <button type="button" class="button outline" id="analyzeProfile"
                  data-endpoint="<?= e($config->url('/clients/analisar')) ?>"
                  data-csrf="<?= e($csrf) ?>">
            <span class="analyzer-spark" aria-hidden="true">✦</span>
            <span class="analyzer-label">Analisar perfil e completar os campos</span>
          </button>
          <span class="analyzer-status" id="analyzeStatus" role="status" aria-live="polite"></span>
        </div>
      <?php endif; ?>
      <label class="field span-6">Instagram
        <input name="instagram" maxlength="500" value="<?= e($v('instagram')) ?>"<?= $desabilitar ?>>
      </label>
      <label class="field span-6">Facebook
        <input name="facebook" maxlength="500" value="<?= e($v('facebook')) ?>"<?= $desabilitar ?>>
      </label>
    </div>
  </section>

  <section class="form-section" id="brand">
    <div class="form-section-title">
      <span class="section-number">02</span>
      <div><h2>Identidade visual</h2><p>Uma referência consistente para tudo o que sua equipe criar.</p></div>
    </div>
    <div class="brand-identity-layout">
      <div class="logo-upload-card">
        <div class="upload-logo-preview" style="--client-color:<?= e($cor) ?>">
          <?php if ($logo !== ''): ?>
            <img src="<?= e($config->url('/clients/file?file=' . rawurlencode($logo))) ?>" alt="Logotipo <?= e($v('company_name')) ?>">
          <?php else: ?>
            <span><?= e(mb_strtoupper(mb_substr($v('company_name'), 0, 1))) ?></span>
          <?php endif; ?>
        </div>
        <b>Logotipo da marca</b>
        <small>PNG, JPG ou WEBP · até 8 MB</small>
        <?php if ($pode_editar): ?>
          <label class="button outline small-button">
            <?= $logo !== '' ? 'Substituir logo' : 'Enviar logotipo' ?>
            <input type="file" name="file" accept="image/png,image/jpeg,image/webp" hidden
                   data-upload-url="<?= e($config->url('/clients/' . $cliente['id'] . '/logo')) ?>"
                   data-csrf="<?= e($csrf) ?>">
          </label>
        <?php endif; ?>
      </div>
      <div class="form-grid brand-fields">
        <label class="field span-4">Cor principal
          <div class="color-field">
            <input type="color" name="primary_color" value="<?= e($cor) ?>" data-color-picker>
            <input type="text" value="<?= e($cor) ?>" maxlength="7" data-color-value>
          </div>
        </label>
        <label class="field span-4">Cor secundária
          <div class="color-field">
            <input type="color" name="secondary_color" value="<?= e($corSecundaria) ?>" data-color-picker>
            <input type="text" value="<?= e($corSecundaria) ?>" maxlength="7" data-color-value>
          </div>
        </label>
        <label class="field span-4">Cor de destaque
          <div class="color-field">
            <input type="color" name="accent_color" value="<?= e($corDestaque) ?>" data-color-picker>
            <input type="text" value="<?= e($corDestaque) ?>" maxlength="7" data-color-value>
          </div>
        </label>
        <label class="field span-6">Tipografia
          <input name="typography" maxlength="250" placeholder="Ex.: Montserrat + Lora" value="<?= e($v('typography')) ?>"<?= $desabilitar ?>>
        </label>
        <label class="field span-6">Slogan
          <input name="slogan" maxlength="500" placeholder="Frase que representa a marca" value="<?= e($v('slogan')) ?>"<?= $desabilitar ?>>
        </label>
        <label class="field span-6">Estilo visual
          <textarea name="visual_style" rows="3" maxlength="2000" placeholder="Ex.: minimalista, orgânico, vibrante..."<?= $desabilitar ?>><?= e($v('visual_style')) ?></textarea>
        </label>
        <label class="field span-6">Tom de comunicação
          <textarea name="communication_tone" rows="3" maxlength="2000" placeholder="Ex.: próximo, bem-humorado, especialista..."<?= $desabilitar ?>><?= e($v('communication_tone')) ?></textarea>
        </label>
        <label class="field span-6">Elementos gráficos
          <textarea name="graphic_elements" rows="3" maxlength="2000" placeholder="Formas, grafismos ou elementos recorrentes"<?= $desabilitar ?>><?= e($v('graphic_elements')) ?></textarea>
        </label>
        <label class="field span-6">Manual da marca
          <textarea name="brand_manual" rows="3" maxlength="4000" placeholder="Orientações importantes para o uso da marca"<?= $desabilitar ?>><?= e($v('brand_manual')) ?></textarea>
        </label>
      </div>
    </div>
  </section>

  <section class="form-section" id="references">
    <div class="form-section-title">
      <span class="section-number">03</span>
      <div><h2>Imagens de referência</h2><p>Inspirações visuais para manter a identidade da marca consistente.</p></div>
    </div>
    <div class="reference-toolbar">
      <span>Biblioteca de referências <b><?= count($referencias) ?></b></span>
      <?php if ($pode_editar): ?>
        <label class="button outline small-button">＋ Adicionar imagem
          <input type="file" name="file" accept="image/png,image/jpeg,image/webp" hidden
                 data-upload-url="<?= e($config->url('/clients/' . $cliente['id'] . '/assets')) ?>"
                 data-csrf="<?= e($csrf) ?>">
        </label>
      <?php endif; ?>
    </div>
    <?php if ($referencias !== []): ?>
      <div class="reference-grid">
        <?php foreach ($referencias as $referencia): ?>
          <div class="reference-card">
            <img src="<?= e($config->url('/clients/file?file=' . rawurlencode((string) $referencia['filename']))) ?>"
                 alt="<?= e((string) $referencia['original_name']) ?>">
            <div class="reference-caption">
              <span title="<?= e((string) $referencia['original_name']) ?>"><?= e((string) $referencia['original_name']) ?></span>
              <?php if ($pode_editar): ?>
                <form method="post" action="<?= e($config->url('/clients/' . $cliente['id'] . '/assets/' . $referencia['id'] . '/delete')) ?>"
                      data-confirm="Remover esta referência?">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <button type="submit" aria-label="Remover imagem">×</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="reference-empty">
        <div>▧</div>
        <span>Ainda sem referências visuais.</span>
        <small>Adicione imagens que ajudem a traduzir o universo da marca.</small>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($pode_editar): ?>
    <div class="form-actions sticky-actions">
      <span>Atualizado em <?= e(mb_substr($v('updated_at'), 0, 10)) ?></span>
      <button class="button primary" type="submit">Salvar alterações <span>✓</span></button>
    </div>
  <?php endif; ?>
</form>
<?php
$content = ob_get_clean();
$title = $nome;
require __DIR__ . '/../layouts/app.php';
