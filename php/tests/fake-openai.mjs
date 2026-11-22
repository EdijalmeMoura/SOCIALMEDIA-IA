/**
 * Provedor de IA falso para a suíte.
 *
 * O php-wasm roda em ambiente sem acesso à internet e sem sockets de servidor,
 * então o caminho de IA (prompt -> HTTP -> JSON -> validação) nunca seria
 * exercitado. Este servidor Node imita a API Chat Completions da OpenAI:
 * responde no mesmo formato, conta tokens e permite simular falhas via query
 * string (`?cenario=401|429|invalido`).
 *
 * Só é usado pelos testes — nunca é empacotado para o cPanel.
 */
import { createServer } from 'node:http';
import { crc32, deflateSync } from 'node:zlib';

const cenarios = {
  401: { status: 401, body: { error: { message: 'Incorrect API key provided', type: 'invalid_request_error' } } },
  429: { status: 429, body: { error: { message: 'Rate limit reached for requests', type: 'requests' } } },
  500: { status: 500, body: { error: { message: 'The server had an error', type: 'server_error' } } },
};

/**
 * PNG mínimo e válido (largura x altura, cor sólida), gerado sem dependências.
 * É o "resultado" da API de imagens nos testes.
 */
function pngSolido(largura, altura, [r, g, b]) {
  const assinatura = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  const bloco = (tipo, dados) => {
    const comprimento = Buffer.alloc(4);
    comprimento.writeUInt32BE(dados.length);
    const corpo = Buffer.concat([Buffer.from(tipo, 'ascii'), dados]);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(corpo) >>> 0);
    return Buffer.concat([comprimento, corpo, crc]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(largura, 0);
  ihdr.writeUInt32BE(altura, 4);
  ihdr[8] = 8;
  ihdr[9] = 2;
  const linha = Buffer.alloc(1 + largura * 3);
  for (let x = 0; x < largura; x += 1) {
    linha[1 + x * 3] = r;
    linha[2 + x * 3] = g;
    linha[3 + x * 3] = b;
  }
  const dados = Buffer.concat(Array.from({ length: altura }, () => linha));
  return Buffer.concat([
    assinatura,
    bloco('IHDR', ihdr),
    bloco('IDAT', deflateSync(dados)),
    bloco('IEND', Buffer.alloc(0)),
  ]);
}

/** Posts coerentes com o pedido recebido no prompt. */
function gerarPosts(spec) {
  const inicio = new Date(`${spec.data_inicio}T00:00:00Z`);
  const redes = Array.isArray(spec.redes_sociais) && spec.redes_sociais.length ? spec.redes_sociais : ['Instagram'];
  const quantidade = Number(spec.quantidade_publicacoes ?? 1);
  const posts = [];
  for (let i = 0; i < quantidade; i += 1) {
    const data = new Date(inicio.getTime() + i * 86400000).toISOString().slice(0, 10);
    posts.push({
      publication_date: data,
      network: redes[i % redes.length],
      theme: `Tema IA ${i + 1}`,
      strategic_objective: 'Objetivo IA',
      content_type: 'Carrossel',
      main_text: 'Texto gerado pela IA',
      caption: 'Legenda gerada pela IA',
      hashtags: ['#ia', '#teste'],
      cta: 'CTA da IA',
      visual_brief: 'Briefing da IA',
      image_suggestion: 'Imagem da IA',
    });
  }
  return posts;
}

const servidor = createServer((req, res) => {
  const url = new URL(req.url ?? '/', 'http://127.0.0.1');
  const cenario = url.searchParams.get('cenario') ?? 'ok';
  let bruto = '';
  req.on('data', (parte) => { bruto += parte; });
  req.on('end', () => {
    // Registro do que a aplicação mandou: os testes conferem nomes de campo
    // que a OpenAI aceita hoje (max_completion_tokens, reasoning_effort).
    if (url.pathname.endsWith('/__ultimo') || url.searchParams.get('cenario') === 'registrar') {
      // Registro puro: nenhuma validação de payload aqui. É este cenário que
      // permite ao teste inspecionar o corpo que a aplicação monta.
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ corpo: JSON.parse(bruto || '{}') }));
      return;
    }
    const responder = (status, corpo) => {
      const texto = JSON.stringify(corpo);
      res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(texto) });
      res.end(texto);
    };

    if (cenarios[cenario]) {
      responder(cenarios[cenario].status, cenarios[cenario].body);
      return;
    }

    const dados = JSON.parse(bruto || '{}');
    // Modelos atuais recusam `max_tokens` (e temperatura com raciocínio ligado).
    // Sem essa recusa o teste passaria com um payload que a OpenAI rejeita.
    if (Object.prototype.hasOwnProperty.call(dados, 'max_tokens')) {
      responder(400, { error: { message: "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead.", type: 'invalid_request_error', param: 'max_tokens' } });
      return;
    }
    if (Object.prototype.hasOwnProperty.call(dados, 'reasoning_effort') && String(dados.reasoning_effort) !== 'none' && dados.temperature !== undefined) {
      responder(400, { error: { message: 'Unsupported value: temperature is not supported with reasoning enabled.', type: 'invalid_request_error', param: 'temperature' } });
      return;
    }
    // Modelo fora da lista: 404 com a mensagem que a OpenAI manda.
    const MODELOS = new Set(['gpt-6-luna', 'gpt-6.1-terra', 'gpt-image-2', 'gpt-image-1.5', 'gpt-image-1-mini']);
    if (!MODELOS.has(String(dados.model))) {
      responder(404, { error: { message: 'The model `' + (dados.model ?? '') + '` does not exist or you do not have access to it.', type: 'invalid_request_error', code: 'model_not_found' } });
      return;
    }
    if (cenario !== 'invalido' && cenario !== 'imagem' && !req.headers.authorization?.startsWith('Bearer chave-')) {
      responder(401, { error: { message: 'Incorrect API key provided' } });
      return;
    }

    let spec = { quantidade_publicacoes: 1, data_inicio: '2026-03-02', redes_sociais: ['Instagram'] };
    try {
      spec = JSON.parse(dados.messages?.[1]?.content ?? '{}').planejamento ?? spec;
    } catch { /* mantém o padrão */ }

    if (url.pathname.endsWith('/images/generations')) {
      if (cenario === 'imagem-401') {
        responder(401, { error: { message: 'Incorrect API key provided' } });
        return;
      }
      if (cenario === 'imagem-vazia') {
        responder(200, { data: [{ b64_json: Buffer.from('nao sou png').toString('base64') }], usage: {} });
        return;
      }
      const [largura, altura] = String(dados.size ?? '1024x1024').split('x').map(Number);
      responder(200, {
        created: 1,
        data: [{ b64_json: pngSolido(largura || 1024, altura || 1024, [17, 34, 68]).toString('base64') }],
        usage: { input_tokens: 12, output_tokens: 34 },
      });
      return;
    }

    if (cenario === 'invalido') {
      // Conteúdo que não é JSON: o gerador deve recusar com mensagem tratada.
      responder(200, { choices: [{ message: { content: 'desculpe, não consegui gerar' } }], usage: {} });
      return;
    }

    // Três contratos no mesmo provedor: planejamento manda "planejamento",
    // estúdio manda "assunto", a análise de perfil manda "tarefa". A resposta
    // segue o pedido recebido.
    if (JSON.parse(dados.messages?.[1]?.content ?? '{}').tarefa) {
      const perfil = JSON.parse(dados.messages?.[1]?.content ?? '{}');
      const nome = String(perfil.nome_informado || 'Marca do Teste');
      responder(200, {
        id: 'chatcmpl-perfil',
        object: 'chat.completion',
        model: dados.model,
        choices: [{
          index: 0,
          message: {
            role: 'assistant',
            content: JSON.stringify({
              trade_name: nome,
              segment: 'Cafeteria de bairro',
              description: 'Cafeteria que torra o próprio café e atende o bairro. '
                + 'Dedução a partir do perfil informado.',
              products_services: 'Café coado, espresso, pães de fermentação natural',
              audience: 'Moradores do bairro, 25 a 45 anos, que trabalham perto',
              business_goals: 'Aumentar o movimento no meio da tarde; divulgar o clube de assinatura',
              instagram: '@marcadoteste',
              facebook: 'https://facebook.com/marcadoteste',
            }),
          },
          finish_reason: 'stop',
        }],
        usage: { prompt_tokens: 300, completion_tokens: 200, total_tokens: 500 },
      });
      return;
    }

    const pedido = JSON.parse(dados.messages?.[1]?.content ?? '{}');
    const conteudo = pedido.planejamento
      ? { posts: gerarPosts(pedido.planejamento) }
      : {
        title: 'Tema IA 1',
        subtitle: 'Subtítulo da arte',
        caption: 'Legenda gerada pela IA para a arte.',
        hashtags: ['#ia', '#arte'],
        cta: 'Fale com a gente',
        visual_brief: 'Composição com área de respiro e cor da marca.',
        image_suggestion: 'Foto real do ambiente.',
        composition: 'Texto à esquerda, imagem ao fundo.',
      };

    responder(200, {
      id: 'chatcmpl-teste',
      object: 'chat.completion',
      model: dados.model,
      choices: [{ index: 0, message: { role: 'assistant', content: JSON.stringify(conteudo) }, finish_reason: 'stop' }],
      usage: { prompt_tokens: 1234, completion_tokens: 567, total_tokens: 1801 },
    });
  });
});

servidor.listen(0, '127.0.0.1', () => {
  const { port } = servidor.address();
  process.stdout.write(`PROVEDOR_IA=http://127.0.0.1:${port}/v1/chat/completions\n`);
});
