/**
 * Comportamentos compartilhados das telas autenticadas (PHP).
 *
 * Tudo aqui é progressivo: sem JS, os formulários continuam funcionando —
 * exceto o envio de imagem, que tem um `<noscript>`-style de fallback no
 * próprio formulário (input type=file dentro do form principal).
 */
document.addEventListener('DOMContentLoaded', () => {
  // Menu lateral no celular (gaveta): abre, fecha, escurece o fundo e
  // mantém aria-expanded em dia, para leitor de tela saber o estado.
  const menu = document.getElementById('mobileMenu');
  const sidebar = document.getElementById('sidebar');
  const scrim = document.getElementById('sidebarScrim');
  const fecharMenu = document.getElementById('sidebarClose');

  const definirMenu = (aberto) => {
    if (!sidebar) return;
    sidebar.classList.toggle('open', aberto);
    scrim?.classList.toggle('is-on', aberto);
    menu?.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    document.body.classList.toggle('menu-aberto', aberto);
  };

  menu?.addEventListener('click', () => definirMenu(!sidebar?.classList.contains('open')));
  fecharMenu?.addEventListener('click', () => definirMenu(false));
  scrim?.addEventListener('click', () => definirMenu(false));
  document.addEventListener('click', (evento) => {
    if (sidebar?.classList.contains('open') && !sidebar.contains(evento.target) && !menu?.contains(evento.target)) {
      definirMenu(false);
    }
  });

  // Menus nativos (<details>): abrir um fecha o outro e clicar fora fecha.
  // Sem JS eles continuam funcionando — isto é só conforto.
  const menus = document.querySelectorAll('details[data-menu]');
  menus.forEach((menu) => {
    menu.addEventListener('toggle', () => {
      if (!menu.open) return;
      menus.forEach((outro) => { if (outro !== menu) outro.open = false; });
    });
  });
  document.addEventListener('click', (evento) => {
    menus.forEach((menu) => {
      if (menu.open && !menu.contains(evento.target)) menu.open = false;
    });
  });
  document.addEventListener('keydown', (evento) => {
    if (evento.key !== 'Escape') return;
    menus.forEach((menu) => { menu.open = false; });
    definirMenu(false);
  });

  // Fecha a gaveta ao escolher uma agência ou um link: o navegador vai
  // recarregar, mas até lá o menu aberto parece travado no celular.
  sidebar?.querySelectorAll('.workspace-list form').forEach((form) => {
    form.addEventListener('submit', () => definirMenu(false));
  });
  sidebar?.querySelectorAll('a.nav-link, a.brand').forEach((link) => {
    link.addEventListener('click', () => definirMenu(false));
  });

  // Confirmação antes de ações destrutivas
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (evento) => {
      if (!window.confirm(form.dataset.confirm)) evento.preventDefault();
    });
  });

  // Botão externo que envia o formulário principal do perfil
  document.querySelectorAll('[data-submit-form]').forEach((botao) => {
    botao.addEventListener('click', () => document.getElementById(botao.dataset.submitForm)?.requestSubmit());
  });

  // Abas da tela de detalhe (ancoradas na mesma página)
  document.querySelectorAll('.detail-tabs a[href^="#"]').forEach((aba) => {
    aba.addEventListener('click', () => {
      document.querySelectorAll('.detail-tabs a').forEach((item) => item.classList.remove('selected'));
      aba.classList.add('selected');
    });
  });

  // Busca instantânea na listagem de clientes
  const busca = document.getElementById('clientSearch');
  busca?.addEventListener('input', () => {
    const termo = busca.value.trim().toLowerCase();
    let visiveis = 0;
    document.querySelectorAll('.client-card').forEach((cartao) => {
      const combina = (cartao.dataset.search || '').includes(termo);
      cartao.hidden = !combina;
      if (combina) visiveis++;
    });
    const semResultado = document.getElementById('noSearchResults');
    if (semResultado) semResultado.hidden = visiveis > 0;
  });

  // Campo de cor: color picker <-> texto hexadecimal
  document.querySelectorAll('[data-color-picker]').forEach((cor) => {
    const texto = cor.parentElement.querySelector('[data-color-value]');
    if (!texto) return;
    cor.addEventListener('input', () => { texto.value = cor.value; });
    texto.addEventListener('input', () => {
      if (/^#[\da-f]{6}$/i.test(texto.value)) cor.value = texto.value;
    });
  });

  // Upload de imagem com barra de progresso simples no próprio botão
  document.querySelectorAll('[data-upload-url]').forEach((input) => {
    input.addEventListener('change', async () => {
      if (!input.files || !input.files.length) return;
      const rotulo = input.closest('label');
      rotulo?.classList.add('is-loading');
      rotulo?.setAttribute('aria-busy', 'true');
      const dados = new FormData();
      dados.append('csrf_token', input.dataset.csrf || '');
      dados.append('file', input.files[0]);
      try {
        const resposta = await fetch(input.dataset.uploadUrl, { method: 'POST', body: dados });
        if (!resposta.ok && resposta.status !== 302 && resposta.status !== 200) {
          throw new Error('status ' + resposta.status);
        }
        window.location.reload();
      } catch (_erro) {
        rotulo?.classList.remove('is-loading');
        rotulo?.removeAttribute('aria-busy');
        window.alert('Não foi possível enviar a imagem. Use PNG, JPG ou WEBP de até 8 MB.');
      }
    });
  });
});
