/**
 * Servidor HTTP local usando PHP 8.3 real (php-wasm), para testar o caminho
 * completo do navegador — inclusive subpasta, cookies e redirecionamentos —
 * sem depender de Apache/cPanel.
 *
 * Uso: node tests/serve.mjs [porta] [basePath]
 */
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { extname, join } from 'node:path';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const ROOT = new URL('..', import.meta.url).pathname.replace(/\/$/, '');
const PORT = Number(process.argv[2] || 8099);
const BASE = process.argv[3] || '';

const MIME = {
  '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml',
};

const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));

import { readdirSync, statSync } from 'node:fs';

async function copiar(rel) {
  for (const entry of readdirSync(join(ROOT, rel))) {
    const origem = join(ROOT, rel, entry);
    const destino = `/www/php/${rel}/${entry}`;
    if (statSync(origem).isDirectory()) {
      try { await php.mkdir(destino, { recursive: true }); } catch { /* já existe */ }
      await copiar(`${rel}/${entry}`);
    } else {
      await php.writeFile(destino, readFileSync(origem));
    }
  }
}

for (const pasta of ['app', 'public']) {
  try { await php.mkdir(`/www/php/${pasta}`, { recursive: true }); } catch { /* já existe */ }
  await copiar(pasta);
}

// .env do projeto, quando existir: é o que permite testar o módulo Meta no
// navegador apontando META_GRAPH_ENDPOINT para um servidor de teste.
for (const rel of ['.env', 'installer.env']) {
  try {
    await php.writeFile(`/www/php/${rel}`, readFileSync(join(ROOT, rel)));
  } catch { /* arquivo opcional */ }
}

/**
 * Lê multipart/form-data (o navegador envia upload assim).
 *
 * O PHP do php-wasm não popula $_FILES sozinho neste servidor de teste, então
 * os arquivos são gravados em /www/php/.uploads e $_FILES é montado à mão —
 * o código da aplicação continua usando a API normal (is_uploaded_file já é
 * dispensado para SAPI sem HTTP).
 */
function lerMultipart(buffer, boundary) {
  const partes = [];
  const separador = Buffer.from('--' + boundary);
  let indice = buffer.indexOf(separador);
  while (indice !== -1) {
    const inicio = indice + separador.length;
    const fim = buffer.indexOf(separador, inicio);
    if (fim === -1) break;
    const bloco = buffer.subarray(inicio, fim);
    const corte = bloco.indexOf('\r\n\r\n');
    if (corte > 0) {
      const cabecalhos = bloco.subarray(0, corte).toString('utf8');
      const conteudo = bloco.subarray(corte + 4, bloco.length - 2);
      const nome = /name="([^"]*)"/.exec(cabecalhos);
      const arquivo = /filename="([^"]*)"/.exec(cabecalhos);
      const tipo = /Content-Type:\s*([^\r\n]+)/i.exec(cabecalhos);
      if (nome) {
        partes.push({
          campo: nome[1],
          filename: arquivo ? arquivo[1] : null,
          mime: tipo ? tipo[1].trim() : 'application/octet-stream',
          conteudo,
        });
      }
    }
    indice = fim;
  }
  return partes;
}

/**
 * Reproduz o comportamento do PHP para campos repetidos e `campo[]`:
 * "networks[]=a&networks[]=b" vira post.networks = ['a','b'].
 */
function adicionarCampo(post, campo, valor) {
  const lista = campo.endsWith('[]');
  const chave = lista ? campo.slice(0, -2) : campo;
  if (lista) {
    if (!Array.isArray(post[chave])) post[chave] = post[chave] === undefined ? [] : [post[chave]];
    post[chave].push(valor);
    return;
  }
  if (post[chave] === undefined) post[chave] = valor;
  else if (Array.isArray(post[chave])) post[chave].push(valor);
  else post[chave] = [post[chave], valor];
}

const parseCookies = (header) => {
  const out = {};
  for (const parte of header.split(';')) {
    const i = parte.indexOf('=');
    if (i > 0) out[parte.slice(0, i).trim()] = parte.slice(i + 1).trim();
  }
  return out;
};

createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  let caminho = decodeURIComponent(url.pathname);
  if (BASE && caminho.startsWith(BASE)) caminho = caminho.slice(BASE.length) || '/';

  // Estáticos
  if (caminho.startsWith('/assets/')) {
    try {
      const arquivo = join(ROOT, 'public', caminho);
      const dados = readFileSync(arquivo);
      res.writeHead(200, { 'Content-Type': MIME[extname(arquivo)] || 'application/octet-stream' });
      return res.end(dados);
    } catch {
      res.writeHead(404); return res.end('não encontrado');
    }
  }

  const corpo = [];
  for await (const chunk of req) corpo.push(chunk);
  const bruto = Buffer.concat(corpo);
  const tipoConteudo = String(req.headers['content-type'] || '');
  const post = {};
  const arquivos = {};

  if (tipoConteudo.startsWith('multipart/form-data')) {
    const boundary = /boundary=(?:"([^"]+)"|([^;]+))/i.exec(tipoConteudo);
    for (const parte of lerMultipart(bruto, (boundary?.[1] || boundary?.[2] || '').trim())) {
      if (parte.filename === null) {
        adicionarCampo(post, parte.campo, parte.conteudo.toString('utf8'));
        continue;
      }
      const destino = `/www/php/.uploads/${Date.now()}-${parte.filename.replace(/[^\w.-]/g, '_')}`;
      try { await php.mkdir('/www/php/.uploads', { recursive: true }); } catch { /* já existe */ }
      await php.writeFile(destino, parte.conteudo);
      arquivos[parte.campo] = {
        name: parte.filename,
        tmp_name: destino,
        error: 0,
        size: parte.conteudo.length,
        type: parte.mime,
      };
    }
  } else if (tipoConteudo.startsWith('application/json')) {
    // fetch manda JSON puro. No navegador o PHP lê php://input; aqui o corpo
    // é entregue no campo que o controller também aceita, para o caminho ser
    // o mesmo do formulário.
    try {
      const dados = JSON.parse(bruto.toString() || '{}');
      if (Object.prototype.hasOwnProperty.call(dados, 'order')) post.order_json = JSON.stringify(dados.order);
    } catch { /* corpo inválido segue vazio */ }
  } else {
    for (const [campo, valor] of new URLSearchParams(bruto.toString()).entries()) {
      adicionarCampo(post, campo, valor);
    }
  }

  // O ambiente e o corpo do POST vão por arquivo: nada de interpolar no código.
  const ambiente = {
    method: req.method,
    uri: req.url,
    script: `${BASE}/index.php`,
    pathInfo: caminho,
    host: req.headers.host || 'localhost',
    get: Object.fromEntries(url.searchParams.entries()),
    post,
    files: arquivos,
    cookies: parseCookies(req.headers.cookie || ''),
    headers: { 'x-csrf-token': String(req.headers['x-csrf-token'] || '') },
  };
  await php.writeFile('/www/php/.request.json', JSON.stringify(ambiente));

  let out;
  try {
    out = await php.run({
      code: `<?php
$r = json_decode(file_get_contents('/www/php/.request.json'), true);
$_SERVER['REQUEST_METHOD'] = $r['method'];
$_SERVER['REQUEST_URI'] = $r['uri'];
$_SERVER['SCRIPT_NAME'] = $r['script'];
$_SERVER['PATH_INFO'] = $r['pathInfo'];
$_SERVER['HTTP_HOST'] = $r['host'];
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['HTTPS'] = '';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (!empty($r['headers']['x-csrf-token'])) { $_SERVER['HTTP_X_CSRF_TOKEN'] = $r['headers']['x-csrf-token']; }
$_GET = $r['get'];
$_POST = $r['post'];
$_COOKIE = $r['cookies'];
$_FILES = $r['files'];
require '/www/php/public/index.php';
`,
    });
  } catch (erro) {
    res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
    return res.end('Erro no PHP: ' + erro.message + '\n' + (erro?.response?.errors ?? ''));
  }

  const headers = {};
  for (const [k, v] of Object.entries(out.headers || {})) headers[k] = Array.isArray(v) ? v.join(', ') : v;
  res.writeHead(out.httpStatusCode || 200, headers);
  res.end(Buffer.from(out.bytes || []));
}).listen(PORT, '0.0.0.0', () => {
  console.log(`PHP 8.3 (php-wasm) em http://0.0.0.0:${PORT}${BASE || '/'}`);
});
