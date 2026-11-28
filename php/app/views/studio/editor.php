<?php
/**
 * Editor de arte: prancheta à esquerda, controles à direita.
 *
 * O desenho acontece no navegador (canvas). O servidor manda o layout como
 * JSON e recebe de volta o PNG/JPG renderizado na exportação — nada de
 * biblioteca gráfica no PHP, então funciona em qualquer cPanel.
 *
 * @var array<string,mixed> $arte
 * @var array<string,mixed> $conteudo
 * @var array{elements:list<array<string,mixed>>} $layout
 * @var array<string,array<string,mixed>> $formatos
 * @var array<string,string> $rotulos
 * @var array<int,array<string,mixed>> $versoes
 * @var bool $ia_configurada
 * @var bool $pode_editar
 * @var string|null $imagem_url
 * @var string|null $logo_url
 * @var string|null $aviso
 * @var array<string,array{label:string,limite:int,tags:int}> $redes
 * @var array<string,string> $tons
 * @var array<string,int> $limites
 * @var bool $pode_enviar
 * @var bool $exportada
 * @var int $portal_ativo
 * @var array{id:int,enviado_em:string}|null $aprovacao_pendente
 * @var bool $pode_desfazer
 * @var array{em:string,campos:array<string,string>}|null $rascunho
 */
$formato = $formatos[(string) $arte['format_key']] ?? ['label' => $arte['format_key'], 'width' => $arte['canvas_width'], 'height' => $arte['canvas_height']];
$erroQuery = $config->url('/studio/posts/' . (int) $arte['id']);
$redeArte = (string) ($arte['network'] ?? 'generico');
$redeInfo = $redes[$redeArte] ?? $redes['generico'];
$tomArte = (string) ($arte['tone'] ?? 'marca');
$tomAtual = isset($tons[$tomArte]) ? $tomArte : 'marca';
$legendaAtual = (string) ($conteudo['caption'] ?? '');
$tagsAtuais = (string) ($conteudo['hashtags'] ?? '');
ob_start();
?>
<?php if (!empty($rascunho) && $pode_editar): ?>
  <?php
  $rascunhoCampos = (array) ($rascunho['campos'] ?? []);
  $diferencas = [];
  foreach (['title' => 'título', 'subtitle' => 'chamada', 'cta' => 'botão', 'caption' => 'legenda', 'hashtags' => 'hashtags'] as $campo => $rotulo) {
      $atual = (string) ($conteudo[$campo] ?? '');
      $guardado = (string) ($rascunhoCampos[$campo] ?? '');
      if ($guardado !== '' && trim($guardado) !== trim($atual)) {
          $diferencas[] = $rotulo;
      }
  }
  ?>
  <?php if ($diferencas !== []): ?>
    <div class="alert info-alert draft-alert" data-draft-banner>
      <b>Você tem alterações não salvas</b> em <?= e(implode(', ', $diferencas)) ?>,
      de <?= e(substr((string) ($rascunho['em'] ?? ''), 0, 16)) ?>.
      <div class="draft-alert-actions">
        <button class="button outline small-button" type="button" data-draft-restore>Recuperar texto</button>
        <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/rascunho/descartar')) ?>">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <button class="button ghost small-button" type="submit">Descartar</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="studio-head">
  <div>
    <a class="back-link" href="<?= e($config->url('/studio')) ?>">← <span>Voltar ao estúdio</span></a>
    <div class="eyebrow muted-eyebrow">
      <?= e(trim((string) ($arte['trade_name'] ?? '')) ?: (string) $arte['company_name']) ?>
      · <?= e((string) $formato['label']) ?> · <?= (int) $arte['canvas_width'] ?> × <?= (int) $arte['canvas_height'] ?>
    </div>
    <h1><?= e((string) $arte['subject']) ?></h1>
  </div>
  <div class="studio-head-actions">
    <?php if ($pode_editar): ?>
      <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/variation')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="variation_note" value="Mantenha a mesma estratégia, com uma abordagem visual e textual alternativa.">
        <button class="button outline" type="submit">✦ Gerar outra versão</button>
      </form>
      <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/duplicate')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="button outline" type="submit" title="Criar uma cópia independente desta arte">⧉ Duplicar</button>
      </form>
      <?php if (empty($aprovacao_pendente)): ?>
        <?php if ($exportada): ?>
          <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/aprovar')) ?>"
                data-confirm="<?= $portal_ativo > 0
                    ? 'Enviar esta arte para a aprovação do cliente? Ele recebe um aviso no portal.'
                    : 'Este cliente ainda não tem acesso ao portal: ninguém será avisado. Enviar mesmo assim?' ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <button class="button primary" type="submit">✓ Enviar para aprovação</button>
          </form>
        <?php else: ?>
          <?php // Sem exportação não há arquivo para o cliente aprovar: o botão explica em vez de falhar. ?>
          <button class="button outline" type="button" disabled
                  title="Exporte a arte em PNG ou JPG para enviar à aprovação">✓ Enviar para aprovação</button>
        <?php endif; ?>
      <?php endif; ?>
      <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/delete')) ?>"
            data-confirm="Excluir esta criação e todas as versões salvas?">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="button danger-outline" type="submit">Excluir criação</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($aviso)): ?>
  <div class="alert success-alert save-alert">
    <span><?= e($aviso) ?></span>
    <?php if (!empty($pode_desfazer)): ?>
      <?php // Volta ao estado de antes do salvamento: a versão atual já está no histórico. ?>
      <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/desfazer-salvamento')) ?>"
            data-confirm="Voltar ao estado de antes deste salvamento?">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="button outline small-button" type="submit">↶ Desfazer este salvamento</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if (!empty($aprovacao_pendente)): ?>
  <?php // Enquanto o cliente não responde, avisar aqui evita mandar duas vezes. ?>
  <div class="alert info-alert">
    <span>Esta arte está aguardando a aprovação do cliente.</span>
    <a class="button outline small-button" href="<?= e($config->url('/approvals/' . (int) $aprovacao_pendente['id'])) ?>">Ver revisão →</a>
  </div>
<?php endif; ?>
<?php if (!empty($erro)): ?>
  <div class="alert error-alert"><?= e($erro) ?></div>
<?php endif; ?>

<?php // Barra fixa no rodapé em telas pequenas: salvar sem caçar o botão. ?>
<?php if ($pode_editar): ?>
  <div class="mobile-save-bar" data-mobile-save>
    <span class="mobile-save-state" data-save-state-text>Alterações não salvas</span>
    <button class="button primary" type="submit" form="studioSave">Salvar</button>
  </div>
<?php endif; ?>

<div class="studio-workspace">
  <section class="panel canvas-panel">
    <div class="canvas-toolbar">
      <div class="canvas-tool-title">
        <span>✦</span><b>Prancheta</b>
        <small><?= (int) $arte['canvas_width'] ?> × <?= (int) $arte['canvas_height'] ?></small>
      </div>
      <div class="canvas-actions">
        <?php if ($pode_editar): ?>
          <button type="button" class="canvas-tool-button" data-undo title="Desfazer o último ajuste (Ctrl+Z)" disabled>↶ Desfazer</button>
          <button type="button" class="canvas-tool-button" data-redo title="Refazer o último desfeito (Ctrl+Shift+Z)" disabled>↷ Refazer</button>
          <span class="canvas-history" data-history title="Passos no histórico">0</span>
          <button type="button" class="canvas-tool-button is-on" data-guides title="Mostrar margem segura e centro">⊞ Guias</button>
          <?php // Grade visual: alinhar sem depender do olho. Fica só na tela. ?>
          <button type="button" class="canvas-tool-button" data-grid aria-pressed="false" title="Grade e margem segura sobre a arte">▦ Grade</button>
          <button type="button" class="canvas-tool-button" data-zoom="out" aria-label="Diminuir zoom">−</button>
          <span class="canvas-zoom-value" data-zoom-value>ajustar</span>
          <button type="button" class="canvas-tool-button" data-zoom="in" aria-label="Aumentar zoom">+</button>
          <button type="button" class="canvas-export-button" data-export="png">↓ PNG</button>
          <button type="button" class="canvas-export-button" data-export="jpg">↓ JPG</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="canvas-stage">
      <div class="canvas-wrapper">
        <canvas id="studioCanvas"
                width="<?= (int) $arte['canvas_width'] ?>"
                height="<?= (int) $arte['canvas_height'] ?>"
                data-layout="<?= e((string) json_encode($layout, JSON_UNESCAPED_UNICODE)) ?>"
                data-bg-color="<?= e((string) $arte['background_color']) ?>"
                data-image-url="<?= e((string) ($imagem_url ?? '')) ?>"
                data-logo-url="<?= e((string) ($logo_url ?? '')) ?>"
                data-export-url="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/export')) ?>"
                data-csrf="<?= e($csrf) ?>"
                data-draft-url="<?= $pode_editar ? e($config->url('/studio/posts/' . (int) $arte['id'] . '/rascunho')) : '' ?>"
                data-draft-preview="<?= $pode_editar ? e($config->url('/studio/posts/' . (int) $arte['id'] . '/rascunho/preview')) : '' ?>"
                data-marca="<?= e(trim((string) ($arte['trade_name'] ?? '')) ?: (string) $arte['company_name']) ?>"
                data-editable="<?= $pode_editar ? '1' : '0' ?>"
                role="img"
                aria-label="<?= e('Arte: ' . (string) $arte['subject'] . ' — ' . (string) $conteudo['title'] . ($conteudo['subtitle'] ? ' / ' . (string) $conteudo['subtitle'] : '')) ?>"
                aria-describedby="canvasAltText"
                tabindex="0"></canvas>
        <div class="canvas-no-image" id="canvasNoImage" <?= $imagem_url ? 'hidden' : '' ?>>
          <span>✦</span><b>Imagem de fundo</b>
          <small>Gere uma imagem com IA ou envie uma referência para começar.</small>
        </div>
      </div>
    </div>
    <?php // Alternativa textual: leitores de tela não enxergam o canvas. ?>
    <p class="canvas-alt-text" id="canvasAltText">
      <b>O que está na arte:</b>
      <?= e((string) ($conteudo['title'] ?? '')) ?><?php if (($conteudo['subtitle'] ?? '') !== ''): ?> — <?= e((string) $conteudo['subtitle']) ?><?php endif; ?><?php if (($conteudo['cta'] ?? '') !== ''): ?> · <?= e((string) $conteudo['cta']) ?><?php endif; ?>.
      <?php if ($imagem_url): ?>Imagem de fundo aplicada<?= ($conteudo['alt_text'] ?? '') !== '' ? ': ' . e((string) $conteudo['alt_text']) : '' ?>.<?php else: ?>Sem imagem de fundo ainda.<?php endif; ?>
    </p>
    <div class="canvas-hint">
      <span>↗</span>
      <span>Clique em um texto para editar no lugar. Arraste para mover, use o puxador do canto para
      redimensionar e a alça de cima para girar. Setas movem 1 px (Shift move 10), Ctrl+Z desfaz e
      Ctrl+Shift+Z refaz.</span>
    </div>
    <?php if ($pode_editar): ?>
      <?php // Barra de status: diz se há alteração pendente e qual o atalho de salvar. ?>
      <div class="canvas-status" data-save-status data-saved-label="Tudo salvo">
        <span class="save-state" data-save-state><i></i><span data-save-state-text>Tudo salvo</span></span>
        <span class="save-hint">Ctrl+S salva de qualquer aba.</span>
      </div>
    <?php endif; ?>
    <?php if ($arte['export_path']): ?>
      <a class="export-ready" href="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/download')) ?>">
        ✓ Arte exportada — baixar arquivo <span>↓</span>
      </a>
    <?php endif; ?>
  </section>

  <aside class="studio-controls">
    <?php if ($pode_editar): ?>
    <section class="panel studio-control-panel">
      <div class="control-heading">
        <div><h2>Editar publicação</h2><p>Arte, legenda e imagem no mesmo lugar.</p></div>
        <span class="edit-icon">✎</span>
      </div>

      <div class="editor-tabs" role="tablist" aria-label="O que editar">
        <button type="button" class="editor-tab is-active" role="tab" aria-selected="true" aria-controls="tabArte" data-tab="arte">Arte</button>
        <button type="button" class="editor-tab" role="tab" aria-selected="false" aria-controls="tabLegenda" data-tab="legenda">
          Legenda <span class="tab-count" data-count-caption><?= mb_strlen($legendaAtual) ?></span>
        </button>
        <button type="button" class="editor-tab" role="tab" aria-selected="false" aria-controls="tabImagem" data-tab="imagem">Imagem</button>
      </div>

      <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/save')) ?>" id="studioSave">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="layout_json" id="layoutJson">
        <?php // rede/tom/objetivo viajam junto com o salvamento para não voltarem ao padrão. ?>
        <input type="hidden" name="network" value="<?= e($redeArte) ?>">
        <input type="hidden" name="tone" value="<?= e($tomArte) ?>">

        <div class="editor-tab-panel is-active" id="tabArte" role="tabpanel">
          <div class="control-group-label">Textos da arte</div>
          <label class="field studio-field">Título
            <input name="title" data-layout-text="title" maxlength="1000" required
                   value="<?= e((string) ($conteudo['title'] ?? '')) ?>">
            <span class="field-helper">Curto, para ler no celular. <span data-counter="title"><?= mb_strlen((string) ($conteudo['title'] ?? '')) ?></span>/<?= (int) ($limites['title'] ?? 70) ?></span>
          </label>
          <label class="field studio-field">Subtítulo
            <textarea name="subtitle" data-layout-text="subtitle" rows="2" maxlength="1000"><?= e((string) ($conteudo['subtitle'] ?? '')) ?></textarea>
            <span class="field-helper"><span data-counter="subtitle"><?= mb_strlen((string) ($conteudo['subtitle'] ?? '')) ?></span>/<?= (int) ($limites['subtitle'] ?? 90) ?></span>
          </label>
          <label class="field studio-field">Chamada para ação
            <input name="cta" data-layout-text="cta" maxlength="1000" value="<?= e((string) ($conteudo['cta'] ?? '')) ?>">
          </label>

          <div class="selected-element-row">
            <div><span class="field-kicker">ELEMENTO SELECIONADO</span><b id="selectedLabel">Título</b></div>
            <label class="mini-size-label">Tamanho
              <input type="range" id="elementSize" min="12" max="180" step="1" value="48" aria-label="Tamanho do texto selecionado">
            </label>
            <label class="mini-color-label">Cor
              <input type="color" id="elementColor" value="#ffffff" aria-label="Cor do texto selecionado">
            </label>
            <div class="element-align-group" role="group" aria-label="Alinhamento do texto selecionado">
              <button type="button" class="element-tool" data-align="left" title="Alinhar à esquerda" aria-label="Alinhar à esquerda">⇤</button>
              <button type="button" class="element-tool" data-align="center" title="Centralizar" aria-label="Centralizar">↔</button>
              <button type="button" class="element-tool" data-align="right" title="Alinhar à direita" aria-label="Alinhar à direita">⇥</button>
              <button type="button" class="element-tool" data-bold title="Negrito" aria-label="Negrito"><b>B</b></button>
              <button type="button" class="element-tool" data-fill title="Faixa de fundo atrás do texto" aria-label="Faixa de fundo">▭</button>
            </div>
          </div>

          <div class="control-divider"></div>

          <label class="field studio-field">Cor de fundo
            <input type="color" name="background_color" id="backgroundColor" value="<?= e((string) $arte['background_color']) ?>">
            <span class="field-helper">Aparece atrás da imagem e nos espaços vazios.</span>
          </label>

          <div class="control-group-label">Pedido original</div>
          <div class="form-grid studio-small-grid">
            <label class="field span-6">Objetivo
              <input name="objective" maxlength="300" value="<?= e((string) ($arte['objective'] ?? '')) ?>" placeholder="Sem objetivo definido">
            </label>
            <label class="field span-6">Tom
              <select name="tone">
                <?php foreach ($tons as $chave => $rotuloTom): ?>
                  <option value="<?= e($chave) ?>" <?= $tomAtual === $chave ? 'selected' : '' ?>><?= e($rotuloTom) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field span-12">Não pode faltar
              <input name="reference" maxlength="600" value="<?= e((string) ($arte['reference'] ?? '')) ?>" placeholder="Preço, data, endereço…">
            </label>
          </div>
        </div>

        <div class="editor-tab-panel" id="tabLegenda" role="tabpanel" hidden>
          <?php // Miniatura fixa: no celular a prancheta fica acima e some ao rolar;
                // sem ela, escrever a legenda vira adivinhação. É o mesmo canvas,
                // copiado para um <img> cada vez que o desenho muda. ?>
          <div class="caption-preview" data-caption-preview hidden>
            <img alt="Prévia da arte enquanto você escreve a legenda" data-preview-image>
            <div class="caption-preview-text">
              <b data-preview-title><?= e((string) ($conteudo['title'] ?? '')) ?></b>
              <small>
                <?= e((string) $formato['label']) ?> · <?= (int) $arte['canvas_width'] ?> × <?= (int) $arte['canvas_height'] ?>
                <?= $exportada ? ' · exportada' : ' · não exportada' ?>
              </small>
            </div>
          </div>
          <div class="caption-toolbar">
            <span class="field-kicker">PARA <?= e(mb_strtoupper($redeInfo['label'])) ?></span>
            <button type="button" class="button outline copy-caption" data-copy-caption>⧉ Copiar legenda + hashtags</button>
          </div>
          <label class="field studio-field">Legenda <em>*</em>
            <textarea name="caption" id="captionField" rows="9" maxlength="5000" required
                      data-caption-limit="<?= (int) $redeInfo['limite'] ?>"><?= e($legendaAtual) ?></textarea>
            <span class="field-helper">
              <span data-counter="caption" data-max="<?= (int) $redeInfo['limite'] ?>"><?= mb_strlen($legendaAtual) ?></span>/<?= (int) $redeInfo['limite'] ?>
              · limite do <?= e($redeInfo['label']) ?>
            </span>
          </label>
          <label class="field studio-field">Hashtags
            <textarea name="hashtags" id="hashtagsField" rows="3" maxlength="5000"><?= e($tagsAtuais) ?></textarea>
            <span class="field-helper">
              <span data-counter="hashtags"><?= mb_strlen($tagsAtuais) ?></span> caracteres · até <?= (int) $redeInfo['tags'] ?> hashtags
              <?= $tagsAtuais === '' ? '· opcional, mas ajuda a ser encontrado' : '' ?>
            </span>
          </label>
          <label class="field studio-field">Descrição da imagem (acessibilidade)
            <input name="alt_text" maxlength="500" value="<?= e((string) ($conteudo['alt_text'] ?? '')) ?>" placeholder="O que aparece na imagem">
            <span class="field-helper">Lida por leitores de tela e usada pelo Instagram. <span data-counter="alt_text"><?= mb_strlen((string) ($conteudo['alt_text'] ?? '')) ?></span>/<?= (int) ($limites['alt_text'] ?? 300) ?></span>
          </label>

          <?php // Reescrever sem refazer a arte inteira: o texto que já está bom fica. ?>
          <div class="rewrite-box" data-rewrite-box>
            <div class="control-group-label">Reescrever com IA</div>
            <p class="field-helper rewrite-note">
              Mexe só no texto escolhido — título, data, CTA e arte continuam como estão.
              <?php if (!$ia_configurada): ?><b>Precisa da chave de IA em Configurações.</b><?php endif; ?>
            </p>
            <?php // Dois envios do mesmo formulário, cada um para a rota de reescrita:
                  // formnovalidate porque o texto atual pode estar vazio e mesmo assim
                  // dar para reescrever a partir do que já foi salvo. ?>
            <label class="field studio-field">Orientação (opcional)
              <input name="rewrite_instruction" maxlength="500" placeholder="Ex.: mais curto, com urgência, sem emoji">
            </label>
            <div class="rewrite-actions">
              <button class="button outline" type="submit" name="target" value="caption" formnovalidate
                      formaction="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/reescrever')) ?>"
                      <?= $ia_configurada ? '' : 'disabled' ?>>✎ Reescrever legenda</button>
              <button class="button outline" type="submit" name="target" value="hashtags" formnovalidate
                      formaction="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/reescrever')) ?>"
                      <?= $ia_configurada ? '' : 'disabled' ?>># Reescrever só hashtags</button>
            </div>
          </div>
        </div>

        <div class="editor-tab-panel" id="tabImagem" role="tabpanel" hidden>
          <div class="control-group-label">Enquadramento</div>
          <div class="fit-row" role="group" aria-label="Enquadramento da imagem">
            <button type="button" class="fit-button is-on" data-fit="cobrir">Preencher</button>
            <button type="button" class="fit-button" data-fit="encaixar">Conter</button>
            <button type="button" class="fit-button" data-fit="moldura">Moldura</button>
          </div>
          <p class="field-helper" data-fit-help>Corta o excesso para a foto ocupar a arte inteira.</p>
          <button type="button" class="canvas-tool-button full-button" data-image-editable>◻ Mover a imagem como elemento</button>
          <p class="field-helper">Com a imagem solta, arraste e redimensione a foto na prancheta para deixar uma faixa da cor da marca.</p>

          <div class="control-divider"></div>

          <label class="field studio-field">Briefing visual
            <textarea name="visual_brief" rows="3" maxlength="5000" required><?= e((string) ($conteudo['visual_brief'] ?? '')) ?></textarea>
          </label>
          <label class="field studio-field">Sugestão de imagem
            <textarea name="image_suggestion" rows="2" maxlength="5000" required><?= e((string) ($conteudo['image_suggestion'] ?? '')) ?></textarea>
          </label>
          <label class="field studio-field">Composição
            <textarea name="composition" rows="2" maxlength="5000"><?= e((string) ($conteudo['composition'] ?? '')) ?></textarea>
          </label>
        </div>

        <?php // Fica no rodapé do painel e é fixado no rodapé da tela em telas
              // pequenas: salvava-se só rolando até o fim da aba Arte. ?>
        <div class="save-bar">
          <button class="button primary save-creative-button" type="submit">Salvar alterações <span>✓</span></button>
          <span class="field-helper save-note">Cada salvamento vira uma versão em “Versões salvas”. <kbd>Ctrl</kbd>+<kbd>S</kbd></span>
        </div>
      </form>
    </section>

    <section class="panel studio-control-panel image-control-panel">
      <div class="control-heading">
        <div><h2>Imagem de fundo</h2><p>Use a IA ou envie um arquivo.</p></div>
        <span class="image-icon">▧</span>
      </div>
      <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/image')) ?>" id="imageGenerationForm">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <?php // Um único campo de prompt: antes havia dois com o mesmo `name` e o
              // navegador enviava os dois valores (o vazio ganhava no PHP). ?>
        <label class="field studio-field">Prompt da imagem
          <textarea name="image_prompt" id="imagePromptSave" rows="3" maxlength="4000"><?= e((string) $arte['image_prompt']) ?></textarea>
          <span class="field-helper">A imagem é gerada sem tipografia para evitar letras distorcidas.</span>
        </label>
        <button class="button primary full-button" type="submit" <?= $ia_configurada ? '' : 'disabled' ?>
          <?= $ia_configurada ? '' : 'title="Configure a chave de IA em Configurações"' ?>>
          <?= $ia_configurada ? '✦ Gerar imagem com IA' : '✦ Falta a chave de IA' ?>
        </button>
        <?php if (!$ia_configurada): ?>
          <a class="field-helper" href="<?= e($config->url('/settings')) ?>">Configurar a chave de IA →</a>
        <?php endif; ?>
      </form>
      <div class="upload-divider"><span>ou use sua imagem</span></div>
      <form method="post" action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/image/upload')) ?>"
            enctype="multipart/form-data" class="studio-upload-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <?php // Sem `required`: o campo é escondido (`hidden`) e o navegador pode
              // recusar enviar um obrigatório invisível (Safari/Android). A escolha
              // do arquivo já dispara o envio; o vazio é barrado no servidor. ?>
        <label class="button outline full-button" data-upload-label>↑ Substituir imagem
          <input type="file" name="file" accept="image/png,image/jpeg,image/webp" data-image-upload>
        </label>
        <p class="upload-feedback" data-upload-feedback hidden>⏳ Enviando a imagem… não feche a página.</p>
      </form>
      <small class="studio-image-note">PNG, JPG ou WEBP até 8 MB · a imagem é ajustada ao formato selecionado.</small>
    </section>
    <?php endif; ?>

    <section class="panel studio-control-panel version-panel">
      <div class="control-heading">
        <div><h2>Versões salvas</h2><p>Restaure uma edição anterior.</p></div>
        <span class="version-count"><?= count($versoes) ?></span>
      </div>
      <?php if ($versoes === []): ?>
        <p class="versions-empty">Nenhuma versão salva.</p>
      <?php else: ?>
        <?php foreach ($versoes as $indice => $versao): ?>
          <div class="version-row">
            <div class="version-dot"></div>
            <div class="version-info">
              <b><?= e((string) $versao['version_label']) ?></b>
              <small><?= e((string) $versao['created_at']) ?></small>
            </div>
            <?php if ($indice > 0 && $pode_editar): ?>
              <form method="post"
                    action="<?= e($config->url('/studio/posts/' . (int) $arte['id'] . '/versions/' . (int) $versao['id'] . '/restore')) ?>"
                    data-confirm="Restaurar esta versão? As alterações atuais ficam no histórico.">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button title="Restaurar versão" aria-label="Restaurar versão" type="submit">↶</button>
              </form>
            <?php elseif ($indice === 0): ?>
              <span class="current-version">Atual</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </aside>
</div>
<script src="<?= e(smia_asset('/assets/studio.js')) ?>" defer></script>
<?php
$content = ob_get_clean();
$title = 'Editor de arte';
$active = 'studio';
require __DIR__ . '/../layouts/app.php';
