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
  form.addEventListener('submit', () => {
    const botao = form.querySelector('[data-create-submit]');
    if (botao) {
      botao.disabled = true;
      botao.textContent = 'Criando…';
    }
    if (dica) dica.textContent = 'Gerando o texto e montando a prancheta. Isso pode levar alguns segundos.';
  });

  atualizar();
});
