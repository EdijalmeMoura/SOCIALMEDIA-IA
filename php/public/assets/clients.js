/**
 * Análise do perfil social no cadastro de cliente.
 *
 * O botão "Analisar perfil e preencher" manda a rede e o endereço para
 * /clients/analisar e escreve a proposta da IA nos campos do formulário.
 *
 * Três cuidados que valem o arquivo:
 *  1. Nada é enviado sozinho: só no clique. Análise consome IA paga.
 *  2. Campo que a pessoa já preencheu NÃO é sobrescrito sem aviso — a proposta
 *     entra por cima apenas nos vazios, e o que foi trocado é dito na tela.
 *  3. A análise não salva nada. Quem salva é o botão do formulário, depois da
 *     revisão. Por isso o texto "revise antes de salvar" fica visível.
 */
document.addEventListener('DOMContentLoaded', () => {
  const botao = document.getElementById('analyzeProfile');
  if (!botao) return;

  const status = document.getElementById('analyzeStatus');
  const rede = document.getElementById('socialNetwork');
  const perfil = document.getElementById('profileUrl');
  const rotulo = botao.querySelector('.analyzer-label');

  const dizer = (texto, tipo = '') => {
    if (!status) return;
    status.textContent = texto;
    status.className = 'analyzer-status' + (tipo ? ' is-' + tipo : '');
  };

  const campo = (nome) => document.querySelector(`[name="${nome}"]`);

  /** Escreve a proposta: vazios são preenchidos, preenchidos são preservados. */
  const aplicar = (campos) => {
    const trocados = [];
    const preservados = [];
    Object.entries(campos || {}).forEach(([nome, valor]) => {
      const alvo = campo(nome);
      if (!alvo || typeof valor !== 'string' || valor.trim() === '') return;
      if (alvo.value.trim() === '') {
        alvo.value = valor;
        alvo.classList.add('analyzer-filled');
        setTimeout(() => alvo.classList.remove('analyzer-filled'), 2200);
        trocados.push(nome);
      } else if (alvo.value.trim() !== valor.trim()) {
        preservados.push(nome);
      }
    });

    if (trocados.length === 0 && preservados.length === 0) {
      dizer('A análise não trouxe nada novo. Complete a rede e o endereço do perfil.', 'erro');
      return;
    }
    const parte = trocados.length > 0
      ? `${trocados.length} campo(s) preenchido(s)`
      : 'nenhum campo estava vazio';
    dizer(
      `${parte}. Revise e salve.${preservados.length > 0 ? ` ${preservados.length} campo(s) seu(s) foram mantidos como estavam.` : ''}`,
      'ok',
    );
  };

  botao.addEventListener('click', async () => {
    if (!rede?.value && !perfil?.value.trim()) {
      dizer('Informe a rede social ou o endereço do perfil antes de analisar.', 'erro');
      rede?.focus();
      return;
    }

    botao.disabled = true;
    botao.classList.add('is-busy');
    if (rotulo) rotulo.textContent = 'Analisando o perfil…';
    dizer('Falando com a IA. Isso pode levar alguns segundos.');

    const corpo = new URLSearchParams({
      csrf_token: botao.dataset.csrf || '',
      social_network: rede?.value || '',
      profile_url: perfil?.value || '',
      company_name: campo('company_name')?.value || '',
      notes: campo('description')?.value || '',
    });

    try {
      const resposta = await fetch(botao.dataset.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: corpo.toString(),
        credentials: 'same-origin',
      });
      let dados = {};
      try { dados = await resposta.json(); } catch (e) { dados = {}; }

      if (!resposta.ok) {
        dizer(dados.erro || 'Não foi possível analisar agora. Tente novamente.', 'erro');
        return;
      }
      aplicar(dados.campos);
      if (dados.aviso) dizer(`${dados.aviso}${dados.modo === 'local' ? '' : ''}`, 'ok');
    } catch (erro) {
      dizer('A conexão falhou no meio da análise. Verifique a internet e tente de novo.', 'erro');
    } finally {
      botao.disabled = false;
      botao.classList.remove('is-busy');
      if (rotulo) rotulo.textContent = 'Analisar perfil e preencher';
    }
  });

  // Se o endereço parece o perfil de outra rede, avisa antes de gastar análise.
  perfil?.addEventListener('blur', () => {
    const valor = perfil.value.toLowerCase();
    if (!rede?.value && /instagram\.com/.test(valor)) { rede.value = 'instagram'; }
    else if (!rede?.value && /facebook\.com/.test(valor)) { rede.value = 'facebook'; }
    else if (!rede?.value && /tiktok\.com/.test(valor)) { rede.value = 'tiktok'; }
  });
});
