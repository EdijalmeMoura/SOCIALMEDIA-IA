/*
 * Estúdio: pré-visualização do formulário de criação.
 *
 * Mostra, antes de gerar, o que a arte vai ter de previsível: o título com o
 * assunto digitado, a chamada, a marca e a cor do cliente. Não é a arte final
 * (a IA ainda escreve), mas evita criar no formato errado e descobrir depois.
 *
 * Nada aqui esconde campo obrigatório: é só espelho do formulário.
 */
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('studioCreateForm');
  if (!form) return;

  const assunto = document.getElementById('createSubject');
  const cliente = document.getElementById('createClient');
  const quadro = document.getElementById('createPreviewFrame');
  const titulo = document.getElementById('createPreviewTitle');
  const marca = document.getElementById('createPreviewBrand');
  const cta = document.getElementById('createPreviewCta');
  const legenda = document.getElementById('createPreviewCaption');
  const dica = document.getElementById('createHint');
  const fatos = {
    rede: document.querySelector('[data-preview="rede"]'),
    formato: document.querySelector('[data-preview="formato"]'),
    imagem: document.querySelector('[data-preview="imagem"]'),
  };

  const texto = (valor, padrao) => (valor || '').trim() || padrao;

  // Cor de fundo legível: o cliente pode ter cor clara, então o texto
  // acompanha o contraste em vez de sumir no fundo.
  function textoContraste(hex) {
    if (!/^#[\da-f]{6}$/i.test(hex || '')) return '#ffffff';
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    const luminancia = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return luminancia > 0.68 ? '#1b2344' : '#ffffff';
  }

  function atualizar() {
    const opcaoEscolhida = cliente?.selectedOptions?.[0];
    const cor = opcaoEscolhida?.dataset?.cor || '#3158d8';
    const nomeMarca = opcaoEscolhida?.dataset?.marca || 'Marca';

    if (quadro) {
      quadro.style.background = `linear-gradient(150deg, ${cor}, #1b2344)`;
      quadro.style.color = textoContraste(cor);
    }
    if (titulo) titulo.textContent = texto(assunto?.value, 'Seu título aparece aqui');
    if (marca) marca.textContent = nomeMarca;
    if (cta) cta.textContent = 'Saiba mais';

    const redeMarcada = form.querySelector('input[name="network"]:checked');
    const formatoMarcado = form.querySelector('input[name="format_key"]:checked');
    const imagemMarcada = form.querySelector('input[name="with_image"]:checked');

    if (fatos.rede) fatos.rede.textContent = redeMarcada?.closest('label')?.querySelector('span')?.textContent || '—';
    if (fatos.formato && formatoMarcado) {
      fatos.formato.textContent = formatoMarcado.closest('label')?.querySelector('b')?.textContent || '—';
    }
    if (fatos.imagem) {
      fatos.imagem.textContent = imagemMarcada?.value === 'agora' ? 'gerada na criação' : 'no editor';
    }

    // O quadro acompanha a proporção do formato: story é alto, banner é largo.
    if (quadro && formatoMarcado) {
      const [largura, altura] = (formatoMarcado.closest('label')?.querySelector('.format-preview')?.style.aspectRatio || '1 / 1')
        .split('/')
        .map((parte) => Number(parte.trim()) || 1);
      quadro.style.aspectRatio = `${largura} / ${altura}`;
      quadro.classList.toggle('is-alto', altura / largura > 1.45);
    }

    if (legenda) {
      legenda.textContent = nomeMarca !== 'Marca'
        ? `Prévia aproximada. Depois de criar, o editor mostra legenda, hashtags e a arte em tamanho real para ${nomeMarca}.`
        : 'Escolha um cliente para ver a cor e a marca na prévia.';
    }
  }

  assunto?.addEventListener('input', atualizar);
  cliente?.addEventListener('change', atualizar);
  form.querySelectorAll('input[name="network"], input[name="format_key"], input[name="with_image"]')
    .forEach((input) => input.addEventListener('change', atualizar));

  // Duplo clique no botão não gera duas artes iguais: a geração chama IA e
  // leva alguns segundos, então o botão avisa que está trabalhando.
  form.addEventListener('submit', (evento) => {
    // Antes de desabilitar o botão, confere os dois campos que a pessoa
    // esquece: sem cliente ou sem assunto a criação volta como erro do
    // servidor, o que parece falha do sistema.
    const faltando = [];
    if (!cliente?.value) faltando.push('cliente');
    if ((assunto?.value || '').trim().length < 3) faltando.push('assunto (3 letras no mínimo)');
    if (!form.querySelector('input[name="format_key"]:checked')) faltando.push('formato');
    if (faltando.length) {
      evento.preventDefault();
      if (dica) dica.textContent = `Falta escolher: ${faltando.join(', ')}.`;
      (cliente?.value ? assunto : cliente)?.focus();
      return;
    }

    const botao = form.querySelector('[data-create-submit]');
    if (botao) {
      botao.disabled = true;
      botao.textContent = 'Criando…';
    }
    if (dica) dica.textContent = 'Gerando o texto e montando a prancheta. Isso pode levar alguns segundos.';
  });

  // Devolve o foco ao primeiro campo vazio quando a página abre com erro do
  // servidor: a mensagem já está no topo, falta levar a pessoa ao campo.
  if (document.querySelector('.error-alert')) {
    const alvo = !cliente?.value ? cliente : (assunto?.value || '').trim().length < 3 ? assunto : null;
    alvo?.focus({ preventScroll: true });
  }

  atualizar();
});

/*
 * Galeria: busca sem recarregar a cada tecla.
 *
 * A busca é do servidor (LIKE no assunto, cliente e título), então o JS só
 * espera a pessoa parar de digitar antes de enviar o formulário de filtros.
 */
document.addEventListener('DOMContentLoaded', () => {
  const campo = document.querySelector('[data-gallery-search]');
  const form = campo?.closest('form');
  if (!campo || !form) return;

  let timer = null;
  campo.addEventListener('input', () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => form.requestSubmit(), 420);
  });
  // Esc limpa a busca e volta à lista completa.
  campo.addEventListener('keydown', (evento) => {
    if (evento.key === 'Escape' && campo.value !== '') {
      campo.value = '';
      window.clearTimeout(timer);
      form.requestSubmit();
    }
  });
});

/*
 * Galeria: ações em lote.
 *
 * A barra só aparece quando há seleção — antes disso ela é ruído numa tela
 * que já tem formulário de criação. A exclusão continua pedindo confirmação.
 */
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-bulk-form]');
  const barra = document.querySelector('[data-bulk-bar]');
  const contador = document.querySelector('[data-bulk-count]');
  if (!form || !barra) return;

  const marcados = () => Array.from(document.querySelectorAll('[data-bulk-item]:checked'));

  function atualizar() {
    const total = marcados().length;
    barra.hidden = total === 0;
    if (contador) contador.textContent = String(total);
  }

  document.querySelectorAll('[data-bulk-item]').forEach((caixa) => caixa.addEventListener('change', atualizar));
  document.querySelector('[data-bulk-clear]')?.addEventListener('click', () => {
    document.querySelectorAll('[data-bulk-item]').forEach((caixa) => { caixa.checked = false; });
    atualizar();
  });
  atualizar();
});
