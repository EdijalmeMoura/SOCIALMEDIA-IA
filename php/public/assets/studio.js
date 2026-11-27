/*
 * Prancheta do estúdio.
 *
 * Desenha a arte em canvas com os mesmos elementos que o servidor validou
 * (imagem de fundo, título, subtítulo, CTA e assinatura). Sem frameworks: só o
 * que o navegador do cliente tem. A exportação manda o PNG/JPG renderizado de
 * volta ao PHP, que guarda o arquivo e libera o download.
 *
 * Regras que a tela promete e este arquivo cumpre:
 *  - o que está na prancheta é exatamente o que é salvo e exportado;
 *  - mover, redimensionar, girar e mudar cor podem ser desfeitos (Ctrl+Z);
 *  - a imagem de fundo tem três enquadramentos e pode virar elemento solto;
 *  - o texto pode ser editado clicando duas vezes sobre ele.
 */
document.addEventListener('DOMContentLoaded', () => {
  const canvas = document.getElementById('studioCanvas');
  if (!canvas) return;

  const ctx = canvas.getContext('2d');
  const editavel = canvas.dataset.editable === '1';
  let layout;
  try {
    layout = JSON.parse(canvas.dataset.layout || '{"elements":[]}');
  } catch {
    layout = { elements: [] };
  }
  if (!Array.isArray(layout.elements) || layout.elements.length === 0) return;

  // A moldura da imagem vem primeiro: ela é o fundo de tudo.
  layout.elements.sort((a, b) => (a.id === 'image' ? -1 : b.id === 'image' ? 1 : 0));

  const margemSegura = Math.round(canvas.width * 0.057);
  const elementos = layout.elements.filter((el) => el.id !== 'image');
  const moldura = layout.elements.find((el) => el.id === 'image');
  if (moldura) {
    if (!['cobrir', 'encaixar', 'moldura'].includes(moldura.fit)) moldura.fit = 'cobrir';
    moldura.shade = moldura.shade !== false;
    moldura.loose = moldura.loose === true;
    moldura.rotate = Number(moldura.rotate) || 0;
  }

  let selecionado = elementos.find((el) => el.id === 'title') || elementos[0];
  let fundo = canvas.dataset.bgColor || '#3158d8';
  let guias = true;
  let grade = false;
  let zoom = 0;
  // `sujo` nasce aqui: antes era declarado no fim do arquivo e só o submit do
  // formulário o marcava, então um gesto na prancheta saía sem aviso nenhum.
  let sujo = false;

  const imagem = new Image();
  let imagemPronta = false;
  const logo = new Image();
  let logoPronto = false;

  const rotulo = document.getElementById('selectedLabel');
  const corInput = document.getElementById('elementColor');
  const tamanhoInput = document.getElementById('elementSize');
  const semImagem = document.getElementById('canvasNoImage');
  const campos = [...document.querySelectorAll('[data-layout-text]')];
  const botaoDesfazer = document.querySelector('[data-undo]');
  const rotuloZoom = document.querySelector('[data-zoom-value]');
  const ajudaEncaixe = document.querySelector('[data-fit-help]');
  // Miniatura da aba Legenda: declarada aqui porque `desenhar()` a agenda, e
  // `desenhar()` roda no fim da inicialização — antes destas linhas, TDZ.
  const previewCaixa = document.querySelector('[data-caption-preview]');
  const previewImagem = document.querySelector('[data-preview-image]');
  const previewTitulo = document.querySelector('[data-preview-title]');
  let previewAgendado = 0;
  const statusSalvar = document.querySelector('[data-save-status]');
  const estadoSalvar = document.querySelector('[data-save-state]');
  const textoEstado = document.querySelector('[data-save-state-text]');
  const formulario = document.getElementById('studioSave');
  const rotuloSalvo = statusSalvar?.dataset.savedLabel || 'Tudo salvo';
  const clamp = (valor, min, max) => Math.max(min, Math.min(max, valor));

  /* ---------- histórico ---------- */

  const historico = [];
  const refeitos = [];
  const LIMITE_HISTORICO = 40;
  const botaoRefazer = document.querySelector('[data-redo]');
  const contadorHistorico = document.querySelector('[data-history]');

  const copiar = () => JSON.parse(JSON.stringify(layout.elements));

  function atualizarHistorico() {
    if (botaoDesfazer) botaoDesfazer.disabled = historico.length === 0;
    if (botaoRefazer) botaoRefazer.disabled = refeitos.length === 0;
    if (contadorHistorico) {
      contadorHistorico.textContent = String(historico.length);
      contadorHistorico.title = historico.length === 0
        ? 'Nenhum passo para desfazer'
        : `${historico.length} ${historico.length === 1 ? 'passo' : 'passos'} para desfazer`;
    }
  }

  function registrar() {
    // Todo ajuste que entra no histórico é uma alteração não salva. Fica aqui
    // (e não em cada listener) para nenhum caminho novo esquecer de avisar.
    marcarSujo();
    historico.push(copiar());
    if (historico.length > LIMITE_HISTORICO) historico.shift();
    // Um ajuste novo invalida o futuro que estava refeito: senão Ctrl+Shift+Z
    // traria de volta um estado que não é mais filho do atual.
    refeitos.length = 0;
    atualizarHistorico();
  }

  /** Reaplica um estado em cima dos mesmos objetos (referências vivas). */
  function aplicarEstado(estado) {
    const porId = new Map(estado.map((el) => [el.id, el]));
    for (const el of layout.elements) {
      const salvo = porId.get(el.id);
      if (salvo) Object.assign(el, salvo);
    }
    sincronizarControles();
    desenhar();
    atualizarHistorico();
  }

  function desfazer() {
    const anterior = historico.pop();
    if (!anterior) {
      atualizarHistorico();
      return;
    }
    refeitos.push(copiar());
    aplicarEstado(anterior);
    // Desfazer também é uma alteração: sem isso, sair da tela depois de
    // Ctrl+Z não avisava nada.
    marcarSujo();
  }

  function refazer() {
    const proximo = refeitos.pop();
    if (!proximo) {
      atualizarHistorico();
      return;
    }
    historico.push(copiar());
    aplicarEstado(proximo);
    marcarSujo();
  }

  botaoDesfazer?.addEventListener('click', desfazer);
  botaoRefazer?.addEventListener('click', refazer);
  atualizarHistorico();

  document.addEventListener('keydown', (evento) => {
    if (!editavel) return;
    const digitando = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)
      || document.activeElement?.isContentEditable === true;
    const comando = evento.ctrlKey || evento.metaKey;
    const tecla = evento.key.toLowerCase();

    // Ctrl+S salva de qualquer aba, sem depender do botão estar à vista.
    if (comando && tecla === 's') {
      evento.preventDefault();
      salvarAgora();
      return;
    }
    if (comando && tecla === 'z' && !digitando) {
      evento.preventDefault();
      if (evento.shiftKey) refazer();
      else desfazer();
      return;
    }
    // Ctrl+Y é o refazer de quem vem do Windows.
    if (comando && tecla === 'y' && !digitando) {
      evento.preventDefault();
      refazer();
      return;
    }
    // Setas movem o elemento selecionado: ajuste fino sem brigar com o mouse.
    if (!selecionado || moldura === selecionado || digitando) return;
    const passo = evento.shiftKey ? 10 : 1;
    const mapa = { ArrowLeft: [-passo, 0], ArrowRight: [passo, 0], ArrowUp: [0, -passo], ArrowDown: [0, passo] };
    if (!mapa[evento.key]) return;
    evento.preventDefault();
    registrar();
    selecionado.x = clamp(selecionado.x + mapa[evento.key][0], 0, canvas.width - selecionado.w);
    selecionado.y = clamp(selecionado.y + mapa[evento.key][1], 0, canvas.height - selecionado.h);
    if (guias) encaixar(selecionado);
    desenhar();
  });

  /* ---------- desenho ---------- */

  /** Quebra o texto em linhas que cabem na largura do elemento. */
  function quebrar(texto, larguraMax) {
    const palavras = String(texto || '').split(/\s+/).filter(Boolean);
    const linhas = [];
    let linha = '';
    for (const palavra of palavras) {
      const proxima = linha ? `${linha} ${palavra}` : palavra;
      if (ctx.measureText(proxima).width > larguraMax && linha) {
        linhas.push(linha);
        linha = palavra;
      } else {
        linha = proxima;
      }
    }
    if (linha) linhas.push(linha);
    return linhas;
  }

  function fonteDe(el) {
    return `${el.bold ? '800' : '500'} ${el.fontSize}px Manrope, Arial, sans-serif`;
  }

  /** Desenha a foto respeitando o enquadramento escolhido. */
  function desenharImagem() {
    if (!imagemPronta) return;
    const quadro = moldura && moldura.loose
      ? { x: moldura.x, y: moldura.y, w: moldura.w, h: moldura.h }
      : { x: 0, y: 0, w: canvas.width, h: canvas.height };
    const fit = moldura?.fit || 'cobrir';

    ctx.save();
    if (moldura && (moldura.loose || fit === 'moldura')) {
      ctx.beginPath();
      const raio = Math.min(quadro.w, quadro.h) * 0.03;
      if (typeof ctx.roundRect === 'function') ctx.roundRect(quadro.x, quadro.y, quadro.w, quadro.h, raio);
      else ctx.rect(quadro.x, quadro.y, quadro.w, quadro.h);
      ctx.clip();
    }

    if (fit === 'encaixar') {
      // Conter: a foto inteira cabe, o resto mostra a cor de fundo.
      const escala = Math.min(quadro.w / imagem.width, quadro.h / imagem.height);
      const w = imagem.width * escala;
      const h = imagem.height * escala;
      ctx.drawImage(imagem, quadro.x + (quadro.w - w) / 2, quadro.y + (quadro.h - h) / 2, w, h);
    } else {
      // Preencher e moldura: cobre o quadro e corta o excesso no centro.
      const escala = Math.max(quadro.w / imagem.width, quadro.h / imagem.height);
      const w = imagem.width * escala;
      const h = imagem.height * escala;
      ctx.drawImage(imagem, quadro.x + (quadro.w - w) / 2, quadro.y + (quadro.h - h) / 2, w, h);
    }
    ctx.restore();

    // A moldura ganha um contorno: sem ele, foto escura em fundo escuro
    // parece não ter borda nenhuma.
    if (moldura && (moldura.loose || fit === 'moldura')) {
      ctx.save();
      ctx.strokeStyle = 'rgba(255,255,255,.55)';
      ctx.lineWidth = Math.max(2, canvas.width * 0.002);
      ctx.strokeRect(quadro.x, quadro.y, quadro.w, quadro.h);
      ctx.restore();
    }
  }

  function desenhar(mostrarSelecao = true) {
    const largura = canvas.width;
    const altura = canvas.height;

    ctx.clearRect(0, 0, largura, altura);
    ctx.fillStyle = fundo;
    ctx.fillRect(0, 0, largura, altura);

    if (imagemPronta) {
      desenharImagem();
    } else {
      // Fundo de espera: gradiente da cor da marca com um círculo de respiro.
      const gradiente = ctx.createLinearGradient(0, 0, largura, altura);
      gradiente.addColorStop(0, fundo);
      gradiente.addColorStop(1, '#1b2344');
      ctx.fillStyle = gradiente;
      ctx.fillRect(0, 0, largura, altura);
      ctx.beginPath();
      ctx.arc(largura * 0.78, altura * 0.24, Math.min(largura, altura) * 0.29, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(255,255,255,.08)';
      ctx.fill();
      ctx.strokeStyle = 'rgba(255,255,255,.23)';
      ctx.lineWidth = Math.max(2, largura * 0.002);
      ctx.beginPath();
      ctx.arc(largura * 0.78, altura * 0.24, Math.min(largura, altura) * 0.36, 0, Math.PI * 2);
      ctx.stroke();
    }

    // Subtom: escurece topo e base para o texto branco ter contraste em
    // qualquer foto. Pode ser desligado no painel Imagem.
    if (!moldura || moldura.shade !== false) {
      const sombra = ctx.createLinearGradient(0, 0, 0, altura);
      sombra.addColorStop(0, 'rgba(0,0,0,.18)');
      sombra.addColorStop(0.45, 'rgba(0,0,0,.03)');
      sombra.addColorStop(1, 'rgba(0,0,0,.32)');
      ctx.fillStyle = sombra;
      ctx.fillRect(0, 0, largura, altura);
    }

    for (const el of elementos) {
      ctx.save();
      if (el.rotate) {
        ctx.translate(el.x + el.w / 2, el.y + el.h / 2);
        ctx.rotate((el.rotate * Math.PI) / 180);
        ctx.translate(-(el.x + el.w / 2), -(el.y + el.h / 2));
      }
      if (el.fill) {
        ctx.fillStyle = el.fill;
        const raio = Math.min(el.h * 0.2, 24);
        ctx.beginPath();
        if (typeof ctx.roundRect === 'function') {
          ctx.roundRect(el.x, el.y, el.w, el.h, raio);
        } else {
          ctx.rect(el.x, el.y, el.w, el.h);
        }
        ctx.fill();
      }
      ctx.beginPath();
      ctx.rect(el.x, el.y, el.w, el.h);
      ctx.clip();

      if (el.id === 'logo' && logoPronto) {
        const escala = Math.min(el.w / logo.width, el.h / logo.height);
        const w = logo.width * escala;
        const h = logo.height * escala;
        ctx.drawImage(logo, el.x + (el.w - w) / 2, el.y + (el.h - h) / 2, w, h);
      } else {
        ctx.font = fonteDe(el);
        ctx.fillStyle = el.color || '#ffffff';
        ctx.textBaseline = 'top';
        ctx.textAlign = el.align || 'left';
        ctx.shadowColor = 'rgba(0,0,0,.25)';
        ctx.shadowBlur = el.fill ? 0 : Math.max(1, el.fontSize * 0.12);

        const linhas = quebrar(el.text, Math.max(10, el.w - 24));
        const alturaLinha = el.fontSize * 1.18;
        const visiveis = Math.max(1, Math.floor(el.h / alturaLinha));
        let tx = el.x + 12;
        if (el.align === 'center') tx = el.x + el.w / 2;
        else if (el.align === 'right') tx = el.x + el.w - 12;

        const total = Math.min(visiveis, linhas.length);
        const inicio = el.y + Math.max(0, (el.h - total * alturaLinha) / 2);
        for (let i = 0; i < total; i += 1) {
          ctx.fillText(linhas[i], tx, inicio + i * alturaLinha, Math.max(10, el.w - 24));
        }
      }
      ctx.restore();
    }

    // Grade de alinhamento (item 13): só na tela, nunca na exportação —
    // `desenhar(false)` é o caminho da arte final.
    if (grade && mostrarSelecao && editavel) {
      ctx.save();
      const passo = Math.max(24, Math.round(largura / 12));
      ctx.strokeStyle = 'rgba(255,255,255,.18)';
      ctx.lineWidth = Math.max(1, largura * 0.0012);
      ctx.beginPath();
      for (let x = passo; x < largura; x += passo) {
        ctx.moveTo(x, 0);
        ctx.lineTo(x, altura);
      }
      for (let y = passo; y < altura; y += passo) {
        ctx.moveTo(0, y);
        ctx.lineTo(largura, y);
      }
      ctx.stroke();
      // Terços: onde o olho procura o assunto.
      ctx.strokeStyle = 'rgba(255,255,255,.34)';
      ctx.beginPath();
      for (const fracao of [1 / 3, 2 / 3]) {
        ctx.moveTo(largura * fracao, 0);
        ctx.lineTo(largura * fracao, altura);
        ctx.moveTo(0, altura * fracao);
        ctx.lineTo(largura, altura * fracao);
      }
      ctx.stroke();
      ctx.restore();
    }

    // Guias de encaixe: mostram a margem segura e o centro da arte.
    if (guias && editavel && selecionado) {
      ctx.save();
      ctx.setLineDash([Math.max(6, largura * 0.01), Math.max(6, largura * 0.01)]);
      ctx.lineWidth = Math.max(1, largura * 0.0015);
      ctx.strokeStyle = 'rgba(255,255,255,.30)';
      ctx.strokeRect(margemSegura, margemSegura, largura - margemSegura * 2, altura - margemSegura * 2);
      ctx.beginPath();
      ctx.moveTo(largura / 2, 0);
      ctx.lineTo(largura / 2, altura);
      ctx.moveTo(0, altura / 2);
      ctx.lineTo(largura, altura / 2);
      ctx.stroke();
      ctx.restore();
    }

    if (mostrarSelecao && selecionado && editavel) {
      const el = selecionado;
      ctx.save();
      if (el.rotate) {
        ctx.translate(el.x + el.w / 2, el.y + el.h / 2);
        ctx.rotate((el.rotate * Math.PI) / 180);
        ctx.translate(-(el.x + el.w / 2), -(el.y + el.h / 2));
      }
      ctx.setLineDash([Math.max(4, largura * 0.007), Math.max(3, largura * 0.004)]);
      ctx.lineWidth = Math.max(2, largura * 0.002);
      ctx.strokeStyle = '#ffffff';
      ctx.strokeRect(el.x, el.y, el.w, el.h);
      ctx.setLineDash([]);
      const raio = Math.max(8, largura * 0.012);

      if (el.id !== 'image') {
        // Puxador de redimensionar (canto) e de girar (topo).
        ctx.fillStyle = '#ffffff';
        ctx.strokeStyle = '#5267df';
        ctx.beginPath();
        ctx.arc(el.x + el.w, el.y + el.h, raio, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();
        ctx.beginPath();
        ctx.arc(el.x + el.w / 2, el.y - raio * 1.6, raio * 0.8, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();
      }
      ctx.restore();
    }

    if (semImagem) semImagem.hidden = imagemPronta;
    agendarPreview();
  }

  /* ---------- encaixe ---------- */

  /** Aproxima o elemento da margem segura ou do centro quando está perto. */
  function encaixar(el) {
    const tolerancia = canvas.width * 0.012;
    const alvosX = [margemSegura, canvas.width / 2 - el.w / 2, canvas.width - margemSegura - el.w];
    const alvosY = [margemSegura, canvas.height / 2 - el.h / 2];
    for (const alvo of alvosX) {
      if (Math.abs(el.x - alvo) < tolerancia) { el.x = Math.round(alvo); break; }
    }
    for (const alvo of alvosY) {
      if (Math.abs(el.y - alvo) < tolerancia) { el.y = Math.round(alvo); break; }
    }
  }

  /* ---------- imagens ---------- */

  if (canvas.dataset.imageUrl) {
    imagem.onload = () => { imagemPronta = true; desenhar(); };
    imagem.onerror = () => { imagemPronta = false; desenhar(); };
    imagem.src = canvas.dataset.imageUrl;
  }
  if (canvas.dataset.logoUrl) {
    logo.onload = () => { logoPronto = true; desenhar(); };
    logo.onerror = () => { logoPronto = false; desenhar(); };
    logo.src = canvas.dataset.logoUrl;
  }

  /* ---------- seleção e controles ---------- */

  function sincronizarControles() {
    const el = selecionado;
    if (!el) return;
    if (rotulo) rotulo.textContent = el.id === 'image' ? 'Imagem' : (el.label || el.id);
    if (corInput) corInput.value = /^#[\da-f]{6}$/i.test(el.color || '') ? el.color : '#ffffff';
    if (tamanhoInput) tamanhoInput.value = el.fontSize;
    document.querySelectorAll('[data-align]').forEach((botao) => {
      botao.classList.toggle('is-on', botao.dataset.align === (el.align || 'left'));
    });
    document.querySelector('[data-bold]')?.classList.toggle('is-on', el.bold === true);
    document.querySelector('[data-fill]')?.classList.toggle('is-on', Boolean(el.fill));
    document.querySelector('[data-image-editable]')?.classList.toggle('is-on', moldura?.loose === true);
  }

  function selecionar(el, registrarEstado = true) {
    if (el && el !== selecionado && registrarEstado) registrar();
    selecionado = el;
    sincronizarControles();
    campos.forEach((input) => {
      if (input.dataset.layoutText === el.id && input.value !== el.text) input.value = el.text;
    });
    desenhar();
  }
  selecionar(selecionado, false);

  campos.forEach((input) => input.addEventListener('input', () => {
    const el = elementos.find((item) => item.id === input.dataset.layoutText);
    if (el) {
      el.text = input.value;
      atualizarContadores();
      if (el === selecionado) desenhar();
    }
  }));

  corInput?.addEventListener('input', () => {
    if (!selecionado) return;
    selecionado.color = corInput.value;
    desenhar();
  });
  corInput?.addEventListener('change', () => registrar());
  tamanhoInput?.addEventListener('input', () => {
    if (!selecionado) return;
    selecionado.fontSize = Number(tamanhoInput.value);
    desenhar();
  });
  tamanhoInput?.addEventListener('change', () => registrar());

  document.getElementById('backgroundColor')?.addEventListener('input', (evento) => {
    fundo = evento.target.value;
    desenhar();
  });
  document.getElementById('backgroundColor')?.addEventListener('change', () => registrar());

  document.querySelectorAll('[data-align]').forEach((botao) => botao.addEventListener('click', () => {
    if (!selecionado || selecionado.id === 'image') return;
    registrar();
    marcarSujo();
    selecionado.align = botao.dataset.align;
    sincronizarControles();
    desenhar();
  }));

  document.querySelector('[data-bold]')?.addEventListener('click', () => {
    if (!selecionado || selecionado.id === 'image') return;
    registrar();
    marcarSujo();
    selecionado.bold = !selecionado.bold;
    sincronizarControles();
    desenhar();
  });

  // Faixa de fundo: melhora a leitura sem precisar de imagem escura.
  document.querySelector('[data-fill]')?.addEventListener('click', () => {
    if (!selecionado || selecionado.id === 'image') return;
    registrar();
    marcarSujo();
    selecionado.fill = selecionado.fill ? '' : '#1b2344';
    sincronizarControles();
    desenhar();
  });

  /* ---------- enquadramento, subtom e imagem solta ---------- */

  const ajudaPorEncaixe = {
    cobrir: 'Corta o excesso para a foto ocupar a arte inteira.',
    encaixar: 'Mostra a foto inteira; o que sobra fica na cor de fundo.',
    moldura: 'Foto inteira com cantos arredondados e contorno, para recortes de produto.',
  };

  function aplicarEncaixe(fit) {
    if (!moldura) return;
    registrar();
    moldura.fit = fit;
    document.querySelectorAll('[data-fit]').forEach((botao) => {
      botao.classList.toggle('is-on', botao.dataset.fit === fit);
    });
    if (ajudaEncaixe) ajudaEncaixe.textContent = ajudaPorEncaixe[fit] || '';
    marcarSujo();
    desenhar();
  }
  document.querySelectorAll('[data-fit]').forEach((botao) => {
    botao.classList.toggle('is-on', botao.dataset.fit === (moldura?.fit || 'cobrir'));
    botao.addEventListener('click', () => aplicarEncaixe(botao.dataset.fit));
  });
  if (ajudaEncaixe) ajudaEncaixe.textContent = ajudaPorEncaixe[moldura?.fit || 'cobrir'];

  document.querySelector('[data-image-editable]')?.addEventListener('click', () => {
    if (!moldura) return;
    registrar();
    marcarSujo();
    moldura.loose = !moldura.loose;
    if (!moldura.loose) {
      Object.assign(moldura, { x: 0, y: 0, w: canvas.width, h: canvas.height });
      selecionar(elementos.find((el) => el.id === 'title') || elementos[0], false);
    }
    sincronizarControles();
    desenhar();
  });

  /* ---------- zoom ---------- */

  const niveisZoom = [0, 1, 1.6, 2.4];
  function aplicarZoom() {
    const escala = niveisZoom[zoom];
    if (rotuloZoom) rotuloZoom.textContent = escala === 0 ? 'ajustar' : `${Math.round(escala * 100)}%`;
    canvas.style.maxHeight = escala === 0 ? '' : 'none';
    canvas.style.maxWidth = escala === 0 ? '' : 'none';
    canvas.style.width = escala === 0 ? '' : `${Math.round(canvas.width * escala)}px`;
  }
  document.querySelectorAll('[data-zoom]').forEach((botao) => botao.addEventListener('click', () => {
    zoom = clamp(zoom + (botao.dataset.zoom === 'in' ? 1 : -1), 0, niveisZoom.length - 1);
    aplicarZoom();
  }));

  /* ---------- gestos ---------- */

  let gesto = null;
  function ponto(evento) {
    const rect = canvas.getBoundingClientRect();
    return {
      x: (evento.clientX - rect.left) * canvas.width / rect.width,
      y: (evento.clientY - rect.top) * canvas.height / rect.height,
    };
  }

  function elementoEm(p) {
    return [...elementos].reverse().find((item) =>
      p.x >= item.x && p.x <= item.x + item.w && p.y >= item.y && p.y <= item.y + item.h);
  }

  canvas.addEventListener('pointerdown', (evento) => {
    if (!editavel) return;
    const p = ponto(evento);
    const alvoRedor = Math.max(24, canvas.width * 0.03);

    // Alças do elemento selecionado: girar (acima) e redimensionar (canto).
    const raioAlca = Math.max(8, canvas.width * 0.012);
    if (selecionado && selecionado.id !== 'image') {
      const centroX = selecionado.x + selecionado.w / 2;
      if (Math.abs(p.x - centroX) < alvoRedor && Math.abs(p.y - (selecionado.y - raioAlca * 1.6)) < alvoRedor) {
        registrar();
        gesto = { el: selecionado, modo: 'rotate', x: p.x, y: p.y, origem: selecionado.rotate || 0 };
        canvas.setPointerCapture(evento.pointerId);
        evento.preventDefault();
        return;
      }
    }

    // Imagem solta: ela é alcançável em qualquer ponto dentro do quadro.
    if (moldura && moldura.loose) {
      const dentro = p.x >= moldura.x && p.x <= moldura.x + moldura.w && p.y >= moldura.y && p.y <= moldura.y + moldura.h;
      const noCanto = p.x > moldura.x + moldura.w - alvoRedor && p.y > moldura.y + moldura.h - alvoRedor;
      if (noCanto && dentro) {
        selecionar(moldura);
        gesto = { el: moldura, modo: 'resize', x: p.x, y: p.y, origem: { x: moldura.x, y: moldura.y, w: moldura.w, h: moldura.h } };
        canvas.setPointerCapture(evento.pointerId);
        evento.preventDefault();
        return;
      }
      if (dentro && !elementoEm(p)) {
        selecionar(moldura);
        gesto = { el: moldura, modo: 'move', x: p.x, y: p.y, origem: { x: moldura.x, y: moldura.y, w: moldura.w, h: moldura.h } };
        canvas.setPointerCapture(evento.pointerId);
        evento.preventDefault();
        return;
      }
    }

    const el = elementoEm(p);
    if (!el) return;
    selecionar(el, false);
    const redimensionar = p.x > el.x + el.w - alvoRedor && p.y > el.y + el.h - alvoRedor;
    registrar();
    gesto = { el, modo: redimensionar ? 'resize' : 'move', x: p.x, y: p.y, origem: { x: el.x, y: el.y, w: el.w, h: el.h } };
    canvas.setPointerCapture(evento.pointerId);
    evento.preventDefault();
  });

  canvas.addEventListener('pointermove', (evento) => {
    if (!gesto) return;
    const p = ponto(evento);
    const dx = p.x - gesto.x;
    const dy = p.y - gesto.y;
    const el = gesto.el;

    if (gesto.modo === 'rotate') {
      const centroX = el.x + el.w / 2;
      const centroY = el.y + el.h / 2;
      const angulo = (Math.atan2(p.y - centroY, p.x - centroX) * 180) / Math.PI + 90;
      el.rotate = clamp(Math.round(angulo), -45, 45);
    } else if (gesto.modo === 'move') {
      el.x = clamp(Math.round(gesto.origem.x + dx), 0, canvas.width - el.w);
      el.y = clamp(Math.round(gesto.origem.y + dy), 0, canvas.height - el.h);
      if (guias) encaixar(el);
    } else {
      el.w = clamp(Math.round(gesto.origem.w + dx), 80, canvas.width - el.x);
      el.h = clamp(Math.round(gesto.origem.h + dy), 50, canvas.height - el.y);
    }
    desenhar();
  });

  const soltar = () => {
    // Fecha o gesto e marca a arte como alterada: mover/redimensionar/girar
    // mudam o layout tanto quanto digitar no formulário.
    if (gesto) marcarSujo();
    gesto = null;
  };
  canvas.addEventListener('pointerup', soltar);
  canvas.addEventListener('pointercancel', soltar);

  /* ---------- edição no lugar (duplo clique sobre a arte) ---------- */

  // Um contenteditable sobreposto ao elemento: o texto é editado onde ele
  // aparece. Ao sair (blur, Enter, Esc ou clique fora), o valor volta para o
  // campo do painel — que continua sendo a fonte da verdade no envio.
  let editorInline = null;
  let textoAntesDoInline = '';

  function fecharInline(confirmar = true) {
    if (!editorInline) return;
    const { el, caixa } = editorInline;
    const campo = campos.find((input) => input.dataset.layoutText === el.id);
    const valor = caixa.innerText.replace(/\n{3,}/g, '\n\n').trim();
    // Sair do estado antes de remover: `remove()` pode disparar blur, e o
    // handler de blur chamaria esta função de novo em recursão.
    editorInline = null;
    canvas.style.pointerEvents = '';
    caixa.remove();
    if (confirmar && campo && valor !== textoAntesDoInline) {
      campo.value = valor;
      el.text = valor;
      registrar();
      marcarSujo();
      atualizarContadores();
    }
    desenhar();
    canvas.focus?.();
  }

  function editarNoLugar(el) {
    if (!el || el.id === 'image' || el.id === 'logo') return;
    fecharInline();
    const retangulo = canvas.getBoundingClientRect();
    const escalaX = retangulo.width / canvas.width;
    const escalaY = retangulo.height / canvas.height;
    const caixa = document.createElement('div');
    caixa.className = 'canvas-inline-editor';
    caixa.contentEditable = 'true';
    caixa.spellcheck = false;
    caixa.setAttribute('role', 'textbox');
    caixa.setAttribute('aria-label', `Editar ${el.label || el.id} direto na arte`);
    caixa.textContent = el.text;
    Object.assign(caixa.style, {
      left: `${retangulo.left + el.x * escalaX}px`,
      top: `${retangulo.top + el.y * escalaY}px`,
      width: `${Math.max(60, el.w * escalaX)}px`,
      minHeight: `${Math.max(28, el.h * escalaY)}px`,
      fontSize: `${Math.max(12, el.fontSize * escalaY)}px`,
      color: el.color || '#fff',
      fontWeight: el.bold ? '800' : '500',
      textAlign: el.align || 'left',
      lineHeight: '1.18',
    });
    document.body.append(caixa);
    canvas.style.pointerEvents = 'none';
    textoAntesDoInline = el.text;
    editorInline = { el, caixa };
    // `preventScroll`: focar um elemento fora da vista rola a página, e o
    // handler de scroll fecharia a edição no mesmo instante.
    caixa.focus({ preventScroll: true });
    const selecao = window.getSelection();
    const intervalo = document.createRange();
    intervalo.selectNodeContents(caixa);
    selecao?.removeAllRanges();
    selecao?.addRange(intervalo);
    caixa.addEventListener('keydown', (evento) => {
      if (evento.key === 'Escape') {
        evento.preventDefault();
        fecharInline(false);
      }
      // Enter quebra linha de propósito só com Shift; Enter sozinho confirma.
      if (evento.key === 'Enter' && !evento.shiftKey) {
        evento.preventDefault();
        fecharInline(true);
      }
      evento.stopPropagation();
    });
    caixa.addEventListener('blur', () => fecharInline(true));
  }

  // A caixa é `position: fixed`: ao rolar, ela deixaria de cobrir o texto.
  window.addEventListener('scroll', () => fecharInline(true), true);

  canvas.addEventListener('dblclick', (evento) => {
    if (!editavel) return;
    const el = elementoEm(ponto(evento));
    if (!el) return;
    document.querySelector('[data-tab="arte"]')?.click();
    editarNoLugar(el);
  });

  /* ---------- contadores e cópia ---------- */

  function atualizarContadores() {
    document.querySelectorAll('[data-counter]').forEach((alvo) => {
      const nome = alvo.dataset.counter;
      let valor = '';
      if (nome === 'caption') valor = document.getElementById('captionField')?.value || '';
      else if (nome === 'hashtags') valor = document.getElementById('hashtagsField')?.value || '';
      else {
        const campo = campos.find((input) => input.dataset.layoutText === nome)
          || document.querySelector(`[name="${nome}"]`);
        valor = campo?.value || '';
      }
      alvo.textContent = String(valor.length);
      const limite = Number(alvo.dataset.max || 0);
      // Acima do limite o contador avisa; não bloqueia, porque o texto às
      // vezes é colado e o usuário precisa ver o excedente.
      alvo.classList.toggle('is-over', limite > 0 && valor.length > limite);
      if (limite > 0) alvo.parentElement?.classList.toggle('is-over', valor.length > limite);
    });
    const contagemLegenda = document.querySelector('[data-count-caption]');
    if (contagemLegenda) contagemLegenda.textContent = String((document.getElementById('captionField')?.value || '').length);
  }

  ['captionField', 'hashtagsField'].forEach((id) => {
    document.getElementById(id)?.addEventListener('input', atualizarContadores);
  });
  campos.forEach((input) => input.addEventListener('input', atualizarContadores));

  document.querySelector('[data-copy-caption]')?.addEventListener('click', async (evento) => {
    const legenda = document.getElementById('captionField')?.value?.trim() || '';
    const tags = document.getElementById('hashtagsField')?.value?.trim() || '';
    const texto = [legenda, tags].filter(Boolean).join('\n\n');
    const botao = evento.currentTarget;
    const original = botao.textContent;
    try {
      await navigator.clipboard.writeText(texto);
      botao.textContent = '✓ Copiado';
    } catch {
      // Navegador sem permissão de área de transferência: seleciona o texto
      // para o usuário copiar com Ctrl+C em vez de não fazer nada.
      const campo = document.getElementById('captionField');
      campo?.focus();
      campo?.select();
      botao.textContent = 'Selecione e Ctrl+C';
    }
    window.setTimeout(() => { botao.textContent = original; }, 2200);
  });

  /* ---------- abas ---------- */

  document.querySelectorAll('[data-tab]').forEach((botao) => botao.addEventListener('click', () => {
    document.querySelectorAll('[data-tab]').forEach((outro) => {
      const ativo = outro === botao;
      outro.classList.toggle('is-active', ativo);
      outro.setAttribute('aria-selected', ativo ? 'true' : 'false');
    });
    const alvo = document.getElementById(`tab${botao.dataset.tab.charAt(0).toUpperCase()}${botao.dataset.tab.slice(1)}`);
    document.querySelectorAll('.editor-tab-panel').forEach((painel) => {
      const mostrar = painel === alvo;
      painel.classList.toggle('is-active', mostrar);
      painel.hidden = !mostrar;
    });
    // A miniatura só é gerada com a aba aberta: ao abrir, atualiza já.
    if (botao.dataset.tab === 'legenda') agendarPreview(true);
  }));

  /* ---------- estado de salvamento ---------- */

  /**
   * Marca a arte como alterada e atualiza a barra de status.
   *
   * `marcarSujo()` é o único caminho: qualquer gesto, tecla, cor ou campo que
   * muda a arte chama daqui, então a barra e o aviso de saída nunca discordam.
   */
  function marcarSujo() {
    sujo = true;
    if (estadoSalvar) estadoSalvar.classList.add('is-dirty');
    if (textoEstado) textoEstado.textContent = 'Alterações não salvas';
    if (statusSalvar) statusSalvar.classList.add('is-dirty');
  }

  function marcarLimpo() {
    sujo = false;
    if (estadoSalvar) estadoSalvar.classList.remove('is-dirty');
    if (textoEstado) textoEstado.textContent = rotuloSalvo;
    if (statusSalvar) statusSalvar.classList.remove('is-dirty');
  }

  /** Envia o formulário de edição (usado pelo Ctrl+S e pela barra fixa). */
  function salvarAgora() {
    if (!formulario) return;
    if (typeof formulario.reportValidity === 'function' && !formulario.reportValidity()) return;
    fecharInline();
    formulario.requestSubmit();
  }

  // O layout enviado no salvamento é exatamente o que está na tela.
  function prepararLayout() {
    campos.forEach((input) => {
      const el = elementos.find((item) => item.id === input.dataset.layoutText);
      if (el) el.text = input.value;
    });
    const campo = document.getElementById('layoutJson');
    if (campo) campo.value = JSON.stringify({ elements: layout.elements });
  }

  formulario?.addEventListener('submit', (evento) => {
    prepararLayout();
    // Os botões de reescrita enviam o mesmo formulário para outra rota: só o
    // salvamento de verdade limpa o estado "alterações não salvas".
    const acao = evento.submitter?.formAction || formulario.getAttribute('action') || '';
    if (!acao.includes('/reescrever')) marcarLimpo();
  });
  formulario?.addEventListener('input', marcarSujo);

  /* ---------- grade e guias ---------- */

  document.querySelector('[data-grid]')?.addEventListener('click', (evento) => {
    grade = !grade;
    const botao = evento.currentTarget;
    botao.classList.toggle('is-on', grade);
    botao.setAttribute('aria-pressed', grade ? 'true' : 'false');
    desenhar();
  });

  document.querySelector('[data-guides]')?.addEventListener('click', (evento) => {
    // O botão já tinha o rótulo "Guias" e nascia ligado, mas não fazia nada:
    // agora liga e desliga a margem segura e o centro.
    guias = !guias;
    const botao = evento.currentTarget;
    botao.classList.toggle('is-on', guias);
    botao.setAttribute('aria-pressed', guias ? 'true' : 'false');
    desenhar();
  });

  /* ---------- miniatura na aba Legenda ---------- */

  /**
   * Copia a prancheta para a miniatura da aba Legenda.
   *
   * Feito com `toDataURL` (não com o canvas inteiro) porque o desenho pode ser
   * grande; a miniatura é reduzida pelo CSS. Agendado com `requestAnimationFrame`
   * para não redesenhar uma vez por pixel durante o arrasto.
   */
  function agendarPreview(forcar = false) {
    if (!previewCaixa || !previewImagem) return;
    // Enquanto a aba Legenda está fechada não há quem veja a miniatura: só é
    // gerada quando ela abre (ou por pedido explícito ao trocar de aba).
    const painel = document.getElementById('tabLegenda');
    if (!forcar && painel && painel.hidden) return;
    if (previewAgendado) return;
    previewAgendado = window.requestAnimationFrame(() => {
      previewAgendado = 0;
      try {
        previewImagem.src = canvas.toDataURL('image/jpeg', 0.72);
        if (previewTitulo) {
          previewTitulo.textContent = document.querySelector('[data-layout-text="title"]')?.value || '';
        }
        previewCaixa.hidden = false;
      } catch {
        // Canvas contaminado por imagem externa: a miniatura simplesmente
        // não aparece, e o resto do editor continua funcionando.
        previewCaixa.hidden = true;
      }
    });
  }

  /* ---------- imagem de fundo e upload ---------- */

  const feedbackUpload = document.querySelector('[data-upload-feedback]');
  const rotuloUpload = document.querySelector('[data-upload-label]');

  document.querySelector('[data-image-upload]')?.addEventListener('change', (evento) => {
    const arquivo = evento.target.files?.[0];
    if (!arquivo) return;
    // Feedback imediato: o envio de 8 MB demora e a tela parecia travada.
    if (feedbackUpload) feedbackUpload.hidden = false;
    if (rotuloUpload) rotuloUpload.classList.add('is-busy');
    evento.target.form?.requestSubmit();
  });

  /* ---------- exportação ---------- */

  document.querySelectorAll('[data-export]').forEach((botao) => botao.addEventListener('click', () => {
    const formato = botao.dataset.export;
    const rotulo = botao.textContent;
    botao.disabled = true;
    botao.textContent = 'Exportando…';
    fecharInline(false);
    prepararLayout();
    desenhar(false); // a arte final não leva a moldura de seleção nem a grade
    canvas.toBlob(async (blob) => {
      desenhar(true);
      if (!blob) {
        window.alert('Não foi possível renderizar a arte.');
        botao.disabled = false;
        botao.textContent = rotulo;
        return;
      }
      const dados = new FormData();
      dados.append('csrf_token', canvas.dataset.csrf);
      dados.append('file', blob, `socialmedia-ia.${formato === 'jpg' ? 'jpg' : 'png'}`);
      try {
        const resposta = await fetch(canvas.dataset.exportUrl, { method: 'POST', body: dados });
        const resultado = await resposta.json();
        if (!resposta.ok) throw new Error(resultado.error || 'Erro ao exportar');
        marcarLimpo(); // exportar já salvou o layout; não faz sentido avisar
        window.location.reload();
      } catch (erro) {
        window.alert(erro.message);
        botao.disabled = false;
        botao.textContent = rotulo;
      }
    }, formato === 'jpg' ? 'image/jpeg' : 'image/png', 0.95);
  }));

  /* ---------- sair com alteração pendente ---------- */

  window.addEventListener('beforeunload', (evento) => {
    if (!sujo) return;
    evento.preventDefault();
    evento.returnValue = '';
  });

  atualizarContadores();
  aplicarZoom();
  desenhar();
});
