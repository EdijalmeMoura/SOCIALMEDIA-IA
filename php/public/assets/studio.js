/*
 * Prancheta do estúdio.
 *
 * Desenha a arte em canvas com os mesmos elementos que o servidor validou
 * (título, subtítulo, CTA e assinatura). Sem frameworks: só o que o navegador
 * do cliente tem. A exportação manda o PNG/JPG renderizado de volta ao PHP,
 * que guarda o arquivo e libera o download.
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

  let selecionado = layout.elements.find((el) => el.id === 'title') || layout.elements[0];
  let fundo = canvas.dataset.bgColor || '#3158d8';

  const imagem = new Image();
  let imagemPronta = false;
  const logo = new Image();
  let logoPronto = false;

  const rotulo = document.getElementById('selectedLabel');
  const corInput = document.getElementById('elementColor');
  const tamanhoInput = document.getElementById('elementSize');
  const semImagem = document.getElementById('canvasNoImage');
  const campos = [...document.querySelectorAll('[data-layout-text]')];
  const clamp = (valor, min, max) => Math.max(min, Math.min(max, valor));

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

  function desenhar(mostrarSelecao = true) {
    const largura = canvas.width;
    const altura = canvas.height;

    ctx.clearRect(0, 0, largura, altura);
    ctx.fillStyle = fundo;
    ctx.fillRect(0, 0, largura, altura);

    if (imagemPronta) {
      ctx.drawImage(imagem, 0, 0, largura, altura);
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

    // Escurece topo e base para o texto branco ter contraste em qualquer foto.
    const sombra = ctx.createLinearGradient(0, 0, 0, altura);
    sombra.addColorStop(0, 'rgba(0,0,0,.15)');
    sombra.addColorStop(0.45, 'rgba(0,0,0,.02)');
    sombra.addColorStop(1, 'rgba(0,0,0,.28)');
    ctx.fillStyle = sombra;
    ctx.fillRect(0, 0, largura, altura);

    for (const el of layout.elements) {
      ctx.save();
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
        ctx.font = `${el.bold ? '700' : '500'} ${el.fontSize}px Manrope, Arial, sans-serif`;
        ctx.fillStyle = el.color || '#ffffff';
        ctx.textBaseline = 'top';
        ctx.textAlign = el.align || 'left';
        ctx.shadowColor = 'rgba(0,0,0,.22)';
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

      if (mostrarSelecao && el === selecionado && editavel) {
        ctx.save();
        ctx.setLineDash([Math.max(4, largura * 0.007), Math.max(3, largura * 0.004)]);
        ctx.lineWidth = Math.max(2, largura * 0.002);
        ctx.strokeStyle = '#ffffff';
        ctx.strokeRect(el.x, el.y, el.w, el.h);
        ctx.setLineDash([]);
        ctx.fillStyle = '#ffffff';
        ctx.strokeStyle = '#5267df';
        ctx.beginPath();
        ctx.arc(el.x + el.w, el.y + el.h, Math.max(8, largura * 0.012), 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();
        ctx.restore();
      }
    }

    if (semImagem) semImagem.hidden = imagemPronta;
  }

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

  function selecionar(el) {
    selecionado = el;
    if (rotulo) rotulo.textContent = el.label || el.id;
    if (corInput) corInput.value = /^#[\da-f]{6}$/i.test(el.color || '') ? el.color : '#ffffff';
    if (tamanhoInput) tamanhoInput.value = el.fontSize;
    campos.forEach((input) => {
      if (input.dataset.layoutText === el.id && input.value !== el.text) input.value = el.text;
    });
    desenhar();
  }
  selecionar(selecionado);

  campos.forEach((input) => input.addEventListener('input', () => {
    const el = layout.elements.find((item) => item.id === input.dataset.layoutText);
    if (el) {
      el.text = input.value;
      if (el === selecionado) desenhar();
    }
  }));

  corInput?.addEventListener('input', () => {
    if (selecionado) { selecionado.color = corInput.value; desenhar(); }
  });
  tamanhoInput?.addEventListener('input', () => {
    if (selecionado) { selecionado.fontSize = Number(tamanhoInput.value); desenhar(); }
  });
  document.getElementById('backgroundColor')?.addEventListener('input', (evento) => {
    fundo = evento.target.value;
    desenhar();
  });

  // Arrastar para mover, puxador do canto para redimensionar.
  let gesto = null;
  function ponto(evento) {
    const rect = canvas.getBoundingClientRect();
    return {
      x: (evento.clientX - rect.left) * canvas.width / rect.width,
      y: (evento.clientY - rect.top) * canvas.height / rect.height,
    };
  }
  canvas.addEventListener('pointerdown', (evento) => {
    if (!editavel) return;
    const p = ponto(evento);
    const el = [...layout.elements].reverse().find((item) =>
      p.x >= item.x && p.x <= item.x + item.w && p.y >= item.y && p.y <= item.y + item.h);
    if (!el) return;
    selecionar(el);
    const alvo = Math.max(24, canvas.width * 0.03);
    const redimensionar = p.x > el.x + el.w - alvo && p.y > el.y + el.h - alvo;
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
    if (gesto.modo === 'move') {
      el.x = clamp(Math.round(gesto.origem.x + dx), 0, canvas.width - el.w);
      el.y = clamp(Math.round(gesto.origem.y + dy), 0, canvas.height - el.h);
    } else {
      el.w = clamp(Math.round(gesto.origem.w + dx), 80, canvas.width - el.x);
      el.h = clamp(Math.round(gesto.origem.h + dy), 50, canvas.height - el.y);
    }
    desenhar();
  });
  const soltar = () => { gesto = null; };
  canvas.addEventListener('pointerup', soltar);
  canvas.addEventListener('pointercancel', soltar);

  // O layout enviado no salvamento é exatamente o que está na tela.
  document.getElementById('studioSave')?.addEventListener('submit', () => {
    campos.forEach((input) => {
      const el = layout.elements.find((item) => item.id === input.dataset.layoutText);
      if (el) el.text = input.value;
    });
    const campo = document.getElementById('layoutJson');
    if (campo) campo.value = JSON.stringify({ elements: layout.elements });
  });

  document.getElementById('imageGenerationForm')?.addEventListener('submit', () => {
    const origem = document.getElementById('imagePromptSave');
    const destino = document.getElementById('imageGenerationPrompt');
    if (origem && destino) destino.value = origem.value;
  });

  document.querySelector('[data-image-upload]')?.addEventListener('change', (evento) => {
    if (evento.target.files?.length) evento.target.form.requestSubmit();
  });

  document.querySelectorAll('[data-export]').forEach((botao) => botao.addEventListener('click', () => {
    const formato = botao.dataset.export;
    botao.disabled = true;
    desenhar(false); // a arte final não leva a moldura de seleção
    canvas.toBlob(async (blob) => {
      desenhar(true);
      if (!blob) {
        window.alert('Não foi possível renderizar a arte.');
        botao.disabled = false;
        return;
      }
      const dados = new FormData();
      dados.append('csrf_token', canvas.dataset.csrf);
      dados.append('file', blob, `socialmedia-ia.${formato === 'jpg' ? 'jpg' : 'png'}`);
      try {
        const resposta = await fetch(canvas.dataset.exportUrl, { method: 'POST', body: dados });
        const resultado = await resposta.json();
        if (!resposta.ok) throw new Error(resultado.error || 'Erro ao exportar');
        window.location.reload();
      } catch (erro) {
        window.alert(erro.message);
        botao.disabled = false;
      }
    }, formato === 'jpg' ? 'image/jpeg' : 'image/png', 0.95);
  }));
});
