/*
 * Grade do feed: arrastar para reordenar, salvar a ordem e abrir o detalhe.
 *
 * Duas regras que evitam estrago:
 *  - a ordem só vai para o servidor quando a pessoa confirma ("Salvar ordem");
 *  - se o servidor responder 409, a página recarrega em vez de insistir —
 *    significa que alguém mexeu na grade enquanto esta aba estava aberta.
 */
document.addEventListener('DOMContentLoaded', () => {
  const layout = document.querySelector('.feed-layout');
  const grid = document.querySelector('.feed-grid');
  if (!layout || !grid) return;

  const podeEditar = layout.dataset.canEdit === 'true';
  const cliente = layout.dataset.feedClient;
  const barra = document.getElementById('feedOrderBar');
  const mensagem = document.getElementById('feedSaveMessage');
  const csrf = document.querySelector('.feed-csrf')?.value || '';
  const tiles = () => [...grid.querySelectorAll('[data-feed-id]')];
  let original = tiles().map((tile) => tile.dataset.feedId);
  let arrastado = null;

  const numerar = () => tiles().forEach((tile, indice) => {
    const numero = tile.querySelector('.tile-order');
    if (numero) numero.textContent = String(indice + 1);
  });

  const mudouOrdem = () => {
    const atual = tiles().map((tile) => tile.dataset.feedId);
    const mudou = atual.some((id, indice) => id !== original[indice]);
    if (barra) barra.hidden = !mudou;
    if (mensagem) mensagem.textContent = '';
    numerar();
    return mudou;
  };

  if (podeEditar) {
    tiles().forEach((tile) => {
      tile.addEventListener('dragstart', (evento) => {
        arrastado = tile;
        tile.classList.add('is-dragging');
        evento.dataTransfer.effectAllowed = 'move';
        evento.dataTransfer.setData('text/plain', tile.dataset.feedId);
      });

      tile.addEventListener('dragend', () => {
        tile.classList.remove('is-dragging');
        grid.querySelectorAll('.feed-tile').forEach((item) => item.classList.remove('is-target'));
        arrastado = null;
        mudouOrdem();
      });

      tile.addEventListener('dragover', (evento) => {
        if (!arrastado || arrastado === tile) return;
        evento.preventDefault();
        const caixa = tile.getBoundingClientRect();
        // Passou da metade do card? Vai depois; senão, antes.
        const depois = (evento.clientY - caixa.top) / caixa.height > 0.52;
        grid.insertBefore(arrastado, depois ? tile.nextSibling : tile);
        tile.classList.add('is-target');
      });

      tile.addEventListener('dragleave', () => tile.classList.remove('is-target'));
      tile.addEventListener('drop', (evento) => {
        evento.preventDefault();
        tile.classList.remove('is-target');
      });
    });
  }

  document.getElementById('feedSaveOrder')?.addEventListener('click', async (evento) => {
    const botao = evento.currentTarget;
    const ordem = tiles().map((tile) => Number(tile.dataset.feedId));
    botao.disabled = true;
    if (mensagem) mensagem.textContent = 'Salvando…';

    try {
      const resposta = await fetch(`${layout.dataset.baseUrl || ''}/feed/${cliente}/reorder`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ order: ordem }),
      });
      const resultado = await resposta.json().catch(() => ({}));

      if (resposta.status === 409) {
        if (mensagem) mensagem.textContent = resultado.error || 'A lista mudou. Recarregando…';
        setTimeout(() => window.location.reload(), 1200);
        return;
      }
      if (!resposta.ok) {
        throw new Error(resultado.error || 'Não foi possível salvar a ordem.');
      }

      original = ordem.map(String);
      if (barra) barra.hidden = true;
      if (mensagem) {
        mensagem.textContent = resultado.message || 'Ordem salva.';
        setTimeout(() => { mensagem.textContent = ''; }, 2400);
      }
    } catch (erro) {
      if (mensagem) mensagem.textContent = erro.message;
    } finally {
      botao.disabled = false;
    }
  });

  document.getElementById('feedCancelOrder')?.addEventListener('click', () => {
    // Volta para a ordem salva: os nós são reanexados na sequência original.
    const mapa = new Map(tiles().map((tile) => [tile.dataset.feedId, tile]));
    original.forEach((id) => {
      const no = mapa.get(id);
      if (no) grid.appendChild(no);
    });
    if (barra) barra.hidden = true;
    numerar();
  });

  // Detalhe da publicação: título, legenda, status e briefing.
  const modal = document.getElementById('feedPreview');
  const preencher = (id, texto) => {
    const alvo = document.getElementById(id);
    if (alvo) alvo.textContent = texto;
  };

  grid.querySelectorAll('.feed-tile-preview').forEach((botao) => {
    botao.addEventListener('click', () => {
      if (!modal) return;
      const tile = botao.closest('.feed-tile');
      preencher('feedModalTitle', tile.dataset.title || 'Publicação');
      preencher('feedModalNetwork', tile.dataset.network || '');
      preencher('feedModalDate', tile.dataset.date ? `Data recomendada: ${tile.dataset.date}` : 'Sem data definida');
      preencher('feedModalCaption', tile.dataset.caption || 'Legenda ainda não cadastrada.');
      preencher('feedModalStatus', tile.dataset.status || '');
      preencher('feedModalBrief', tile.dataset.brief || '');
      modal.showModal();
    });
  });

  document.getElementById('feedPreviewClose')?.addEventListener('click', () => modal?.close());
  modal?.addEventListener('click', (evento) => {
    if (evento.target === modal) modal.close();
  });
});
