/**
 * Servidor mínimo que imita o Meta Graph API para a suíte.
 *
 * Existe para exercitar o caminho REAL de publicação (HTTP + JSON + erros do
 * Graph) sem depender da internet do sandbox. Cada rota também serve de
 * "modo de falha" — é assim que os casos de incerteza são testados de verdade.
 *
 * Uso: node tests/fake-graph.mjs [porta]
 */
import { createServer } from 'node:http';

const PORT = Number(process.argv[2] || 8197);
const escutas = [];
let chamadas = [];

/** Corpo de formulário ou query string -> objeto. */
function ler(params) {
  return Object.fromEntries(params.entries());
}

const json = (res, corpo, status = 200) => {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(corpo));
};

createServer(async (req, res) => {
  const url = new URL(req.url, `http://127.0.0.1:${PORT}`);
  const corpo = [];
  for await (const parte of req) corpo.push(parte);
  const form = ler(new URLSearchParams(Buffer.concat(corpo).toString()));
  const caminho = url.pathname.replace(/^\/v\d+\.\d+/, '');
  const params = { ...ler(url.searchParams), ...form };
  chamadas.push({ metodo: req.method, caminho, params });

  // --- controle do teste ---------------------------------------------
  if (caminho === '/__chamadas') return json(res, { chamadas });
  if (caminho === '/__limpar') { chamadas = []; return json(res, { ok: true }); }

  // --- OAuth ----------------------------------------------------------
  if (caminho === '/oauth/access_token') {
    if (params.grant_type === 'fb_exchange_token') {
      return json(res, { access_token: 'TOKEN-LONGO-DE-USUARIO', token_type: 'bearer', expires_in: 5184000 });
    }
    if (params.code === 'codigo-recusado') {
      return json(res, { error: { message: 'Código de autorização inválido.', code: 100 } }, 400);
    }
    return json(res, { access_token: 'TOKEN-CURTO-DE-USUARIO', token_type: 'bearer' });
  }

  // --- Páginas e permissões -------------------------------------------
  if (caminho === '/me/accounts') {
    // Lista vazia: usuário sem Página (o produto precisa recusar com motivo).
    if (params.access_token === 'TOKEN-SEM-PAGINA') {
      return json(res, { data: [] });
    }
    if (params.after === 'pagina-2') {
      return json(res, {
        data: [{
          id: 'PAGE-2', name: 'Segunda Página', access_token: 'TOKEN-PAGINA-2',
          instagram_business_account: { id: 'IG-2', username: 'segunda.pagina' },
        }],
      });
    }
    if (params.after) {
      // Cursor repetido: o cliente precisa desistir em vez de girar.
      return json(res, { data: [], paging: { next: 'x', cursors: { after: params.after } } });
    }
    return json(res, {
      data: [{
        id: 'PAGE-1', name: 'Página Bravo', access_token: 'TOKEN-PAGINA-1',
        instagram_business_account: { id: 'IG-1', username: 'bravo.fitness' },
      }],
      paging: { next: 'https://graph.facebook.com/me/accounts?after=pagina-2', cursors: { after: 'pagina-2' } },
    });
  }

  if (caminho === '/me/permissions') {
    return json(res, {
      data: [
        { permission: 'pages_show_list', status: 'granted' },
        { permission: 'pages_manage_posts', status: 'granted' },
        { permission: 'instagram_basic', status: 'granted' },
        { permission: 'instagram_content_publish', status: 'granted' },
        { permission: 'pages_read_engagement', status: 'declined' },
        { permission: 'esporte_radical', status: 'granted' },
      ],
    });
  }

  // --- Página individual ----------------------------------------------
  if (req.method === 'GET' && caminho === '/PAGE-1') {
    if (params.access_token === 'TOKEN-PAGINA-1') return json(res, { id: 'PAGE-1', name: 'Página Bravo' });
    return json(res, { error: { message: 'Token inválido.', code: 190 } }, 400);
  }
  if (req.method === 'GET' && caminho === '/PAGE-2') {
    return json(res, { id: 'OUTRA-PAGINA', name: 'Outra' });
  }

  // --- Instagram ------------------------------------------------------
  if (req.method === 'POST' && caminho === '/IG-1/media') {
    if (params.caption && params.caption.includes('FALHA-CONTAINER')) {
      return json(res, { error: { message: 'A imagem não pôde ser baixada.', code: 9004 } }, 400);
    }
    return json(res, { id: 'CONTAINER-1' });
  }
  if (req.method === 'GET' && caminho === '/CONTAINER-1') {
    return json(res, { status_code: 'FINISHED' });
  }
  if (req.method === 'POST' && caminho === '/IG-2/media') {
    return json(res, { id: 'CONTAINER-2-IG' });
  }
  if (req.method === 'GET' && caminho === '/CONTAINER-2-IG') {
    return json(res, { status_code: 'FINISHED' });
  }
  if (req.method === 'POST' && caminho === '/IG-2/media_publish') {
    return json(res, { id: 'IG-MEDIA-2' });
  }
  if (req.method === 'GET' && caminho === '/CONTAINER-2') {
    return json(res, { status_code: 'ERROR', status: 'Falha no processamento' });
  }
  if (req.method === 'POST' && caminho === '/IG-1/media_publish') {
    return json(res, { id: 'IG-MEDIA-1' });
  }

  // --- Facebook -------------------------------------------------------
  if (req.method === 'POST' && caminho === '/PAGE-1/photos') {
    if (params.caption && params.caption.includes('DUPLICADO')) {
      // 500: a Meta não confirmou. Pode ter publicado.
      return json(res, { error: { message: 'Erro interno.', code: 1 } }, 500);
    }
    return json(res, { id: 'FB-PHOTO-1', post_id: 'PAGE-1_FB-PHOTO-1' });
  }
  if (req.method === 'POST' && caminho === '/PAGE-1/feed') {
    return json(res, { id: 'PAGE-1_FB-TEXTO-1' });
  }

  json(res, { error: { message: 'Rota não simulada: ' + caminho, code: 803 } }, 404);
}).listen(PORT, '127.0.0.1', () => {
  console.log(`GRAFO_FALSO=http://127.0.0.1:${PORT}`);
});
