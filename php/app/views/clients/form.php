<?php
/**
 * Cadastro de cliente (etapa 01): dados do negócio e canais de contato.
 *
 * @var string $modo
 * @var array<string,mixed> $cliente
 * @var array<int,string> $erros
 * @var string $csrf
 * @var \Smia\Support\Config $config
 */
$valor = static fn (string $campo): string => (string) ($cliente[$campo] ?? '');
$redes = $redes ?? [];
$redeAtual = (string) ($cliente['social_network'] ?? '');
ob_start();
?>
<div class="form-page-head">
  <a class="back-link" href="<?= e($config->url('/clients')) ?>">← <span>Voltar para clientes</span></a>
  <div class="eyebrow muted-eyebrow">ETAPA 01 · PORTFÓLIO</div>
  <h1>Vamos conhecer essa marca.</h1>
  <p class="page-subtitle">Comece pelas informações essenciais. Você poderá completar a identidade visual depois.</p>
</div>

<?php foreach ($erros as $erro): ?>
  <div class="alert error-alert"><?= e($erro) ?></div>
<?php endforeach; ?>

<form method="post" action="<?= e($config->url('/clients/new')) ?>" class="client-form">
  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

  <?php /* Rede social primeiro: é dela que sai a análise que preenche o resto.
           O bloco fica dentro do formulário para os campos preenchidos irem
           junto no POST — a análise não grava nada, só propõe. */ ?>
  <section class="form-section profile-analyzer" id="analise-social">
    <div class="form-section-title">
      <span class="section-number">00</span>
      <div>
        <h2>Rede social do cliente</h2>
        <p>Informe o perfil e deixe a IA rascunhar o cadastro. Você revisa antes de salvar.</p>
      </div>
    </div>
    <div class="form-grid">
      <label class="field span-4">Rede social
        <select name="social_network" id="socialNetwork">
          <option value="">Escolha a rede</option>
          <?php foreach ($redes as $chave => $rotuloRede): ?>
            <option value="<?= e($chave) ?>" <?= $redeAtual === $chave ? 'selected' : '' ?>><?= e($rotuloRede) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field span-8">Endereço do perfil
        <input name="profile_url" id="profileUrl" maxlength="500"
               placeholder="@nomedamarca ou https://instagram.com/nomedamarca"
               value="<?= e($valor('profile_url')) ?>">
        <small class="hint">Sem raspagem: a análise usa o que está aqui e no nome da marca. O que a IA não souber fica em branco.</small>
      </label>
      <div class="field span-12 analyzer-actions">
        <button type="button" class="button outline" id="analyzeProfile"
                data-endpoint="<?= e($config->url('/clients/analisar')) ?>"
                data-csrf="<?= e($csrf) ?>">
          <span class="analyzer-spark" aria-hidden="true">✦</span>
          <span class="analyzer-label">Analisar perfil e preencher</span>
        </button>
        <span class="analyzer-status" id="analyzeStatus" role="status" aria-live="polite"></span>
      </div>
    </div>
  </section>

  <section class="form-section">
    <div class="form-section-title">
      <span class="section-number">01</span>
      <div><h2>Sobre o negócio</h2><p>Informações para sua equipe entender a marca.</p></div>
    </div>
    <div class="form-grid">
      <label class="field span-6">Nome da empresa <em>*</em>
        <input name="company_name" required maxlength="200" placeholder="Ex.: Aurora Cafés Especiais" value="<?= e($valor('company_name')) ?>">
      </label>
      <label class="field span-6">Nome comercial
        <input name="trade_name" maxlength="500" placeholder="Como o público conhece a marca" value="<?= e($valor('trade_name')) ?>">
      </label>
      <label class="field span-6">Segmento de atuação
        <input name="segment" maxlength="500" placeholder="Ex.: Gastronomia, Moda, Saúde..." value="<?= e($valor('segment')) ?>">
      </label>
      <label class="field span-6">Site
        <input name="website" maxlength="500" placeholder="https://suaempresa.com.br" value="<?= e($valor('website')) ?>">
      </label>
      <label class="field span-12">Descrição do negócio
        <textarea name="description" rows="3" maxlength="4000" placeholder="O que a empresa faz e o que a torna especial?"><?= e($valor('description')) ?></textarea>
      </label>
      <label class="field span-6">Produtos e serviços
        <textarea name="products_services" rows="3" maxlength="4000" placeholder="Produtos, serviços ou diferenciais principais"><?= e($valor('products_services')) ?></textarea>
      </label>
      <label class="field span-6">Público-alvo
        <textarea name="audience" rows="3" maxlength="4000" placeholder="Quem a marca quer alcançar?"><?= e($valor('audience')) ?></textarea>
      </label>
      <label class="field span-12">Objetivos comerciais
        <textarea name="business_goals" rows="3" maxlength="4000" placeholder="Ex.: aumentar pedidos, fortalecer a marca, lançar um produto..."><?= e($valor('business_goals')) ?></textarea>
      </label>
    </div>
  </section>

  <section class="form-section">
    <div class="form-section-title">
      <span class="section-number">02</span>
      <div><h2>Canais e contato</h2><p>Facilite o contato e encontre os perfis oficiais.</p></div>
    </div>
    <div class="form-grid">
      <label class="field span-4">Telefone
        <input name="phone" maxlength="500" placeholder="(00) 0000-0000" value="<?= e($valor('phone')) ?>">
      </label>
      <label class="field span-4">WhatsApp
        <input name="whatsapp" maxlength="500" placeholder="(00) 00000-0000" value="<?= e($valor('whatsapp')) ?>">
      </label>
      <label class="field span-4">Endereço
        <input name="address" maxlength="500" placeholder="Cidade, estado" value="<?= e($valor('address')) ?>">
      </label>
      <label class="field span-6">Instagram
        <input name="instagram" maxlength="500" placeholder="@nomedamarca" value="<?= e($valor('instagram')) ?>">
      </label>
      <label class="field span-6">Facebook
        <input name="facebook" maxlength="500" placeholder="facebook.com/nomedamarca" value="<?= e($valor('facebook')) ?>">
      </label>
    </div>
  </section>

  <div class="form-actions">
    <a href="<?= e($config->url('/clients')) ?>" class="button outline">Cancelar</a>
    <button type="submit" class="button primary">Criar perfil do cliente <span>→</span></button>
  </div>
</form>
<?php
$content = ob_get_clean();
$title = 'Novo cliente';
// O JS da análise vem do controller (scripts_extras), não daqui: assim a tela
// de erro do formulário também o carrega sem duplicar include.
require __DIR__ . '/../layouts/app.php';
