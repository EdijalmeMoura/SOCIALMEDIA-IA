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
  const post = Object.fromEntries(new URLSearchParams(Buffer.concat(corpo).toString()).entries());

  // O ambiente e o corpo do POST vão por arquivo: nada de interpolar no código.
  const ambiente = {
    method: req.method,
    uri: req.url,
    script: `${BASE}/index.php`,
    pathInfo: caminho,
    host: req.headers.host || 'localhost',
    get: Object.fromEntries(url.searchParams.entries()),
    post,
    cookies: parseCookies(req.headers.cookie || ''),
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
$_GET = $r['get'];
$_POST = $r['post'];
$_COOKIE = $r['cookies'];
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
