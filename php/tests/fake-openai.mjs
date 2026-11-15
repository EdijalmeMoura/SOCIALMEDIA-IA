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

const cenarios = {
  401: { status: 401, body: { error: { message: 'Incorrect API key provided', type: 'invalid_request_error' } } },
  429: { status: 429, body: { error: { message: 'Rate limit reached for requests', type: 'requests' } } },
  500: { status: 500, body: { error: { message: 'The server had an error', type: 'server_error' } } },
};

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
    if (cenario !== 'invalido' && !req.headers.authorization?.startsWith('Bearer chave-')) {
      responder(401, { error: { message: 'Incorrect API key provided' } });
      return;
    }

    let spec = { quantidade_publicacoes: 1, data_inicio: '2026-03-02', redes_sociais: ['Instagram'] };
    try {
      spec = JSON.parse(dados.messages?.[1]?.content ?? '{}').planejamento ?? spec;
    } catch { /* mantém o padrão */ }

    if (cenario === 'invalido') {
      // Conteúdo que não é JSON: o gerador deve recusar com mensagem tratada.
      responder(200, { choices: [{ message: { content: 'desculpe, não consegui gerar' } }], usage: {} });
      return;
    }

    responder(200, {
      id: 'chatcmpl-teste',
      object: 'chat.completion',
      model: dados.model,
      choices: [{ index: 0, message: { role: 'assistant', content: JSON.stringify({ posts: gerarPosts(spec) }) }, finish_reason: 'stop' }],
      usage: { prompt_tokens: 1234, completion_tokens: 567, total_tokens: 1801 },
    });
  });
});

servidor.listen(0, '127.0.0.1', () => {
  const { port } = servidor.address();
  process.stdout.write(`PROVEDOR_IA=http://127.0.0.1:${port}/v1/chat/completions\n`);
});
