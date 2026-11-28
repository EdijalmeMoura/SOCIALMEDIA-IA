/*
 * Edição da publicação do calendário.
 *
 * Duas coisas que só existem nesta tela:
 *  - o contador de caracteres com o limite da rede escolhida (antes o limite
 *    aparecia só no fim, quando a legenda já estava escrita);
 *  - a prévia do texto como o cliente vai ler, atualizada enquanto digita.
 *
 * Sem framework e sem dependência: o que não existir na tela é ignorado.
 */
document.addEventListener('DOMContentLoaded', () => {
  const legenda = document.getElementById('postCaption');
  if (!legenda) return;

  const hashtags = document.getElementById('postHashtags');
  const contador = document.querySelector('[data-counter-caption]');
  const contadorTags = document.querySelector('[data-counter-tags]');
  const avisoRede = document.querySelector('[data-network-note]');
  const previaLegenda = document.querySelector('[data-preview-caption]');
  const previaTags = document.querySelector('[data-preview-tags]');
  const previaCta = document.querySelector('[data-preview-cta]');
  const cta = document.querySelector('input[name="cta"]');

  /** Quantos caracteres a legenda tem a mais que o limite da rede. */
  function excedente(texto, limite) {
    return limite > 0 ? texto.length - limite : 0;
  }

  function atualizar() {
    const texto = legenda.value || '';
    const limite = Number(contador?.dataset.max || legenda.dataset.captionLimit || 0);

    if (contador) {
      contador.textContent = String(texto.length);
      const passou = limite > 0 && texto.length > limite;
      // Acima do limite não bloqueia (o texto pode ser colado de outro lugar),
      // mas o número fica vermelho e a dica diz quanto cortar.
      contador.classList.toggle('is-over', passou);
      contador.parentElement?.classList.toggle('is-over', passou);
      if (avisoRede) {
        const sobra = excedente(texto, limite);
        avisoRede.hidden = !passou;
        avisoRede.textContent = passou ? `· ${sobra} caracteres acima do limite` : '';
      }
    }

    if (hashtags) {
      const tags = (hashtags.value || '').match(/#[\p{L}\p{N}_]+/gu) || [];
      const maximo = Number(hashtags.dataset.tagsMax || 0);
      if (contadorTags) {
        contadorTags.textContent = String(tags.length);
        contadorTags.classList.toggle('is-over', maximo > 0 && tags.length > maximo);
      }
      if (previaTags) {
        const texto = (hashtags.value || '').trim();
        previaTags.textContent = texto;
        // Sem hashtags, a linha some em vez de deixar um espaço vazio.
        previaTags.hidden = texto === '';
      }
    }

    if (previaCta && cta) {
      const texto = (cta.value || '').trim();
      previaCta.textContent = texto;
      previaCta.hidden = texto === '';
    }

    if (previaLegenda) {
      // `textContent` primeiro: a prévia nunca interpreta HTML do usuário.
      previaLegenda.textContent = texto;
      previaLegenda.style.whiteSpace = 'pre-wrap';
    }
  }

  legenda.addEventListener('input', atualizar);
  hashtags?.addEventListener('input', atualizar);
  cta?.addEventListener('input', atualizar);
  atualizar();
});
