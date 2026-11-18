/**
 * Runner da suíte PHP usando PHP 8.3 real (php-wasm).
 *
 * Existe porque a aplicação precisa ser testada de verdade — sintaxe, roteamento,
 * sessão, CSRF, SQLite e views — e não apenas "parecer correta". O php-wasm
 * executa os mesmos arquivos que vão para o cPanel.
 *
 * Uso:  npm install && node tests/run-tests.mjs
 * Requisitos: Node 18+, pacotes @php-wasm/universal e @php-wasm/node.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { join, relative } from 'node:path';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const ROOT = new URL('..', import.meta.url).pathname.replace(/\/$/, '');
const DOC_ROOT = '/www/php';

function collect(dir, base = dir) {
  const out = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (entry === 'node_modules' || entry === 'data' || entry.startsWith('.')) continue;
    if (statSync(full).isDirectory()) out.push(...collect(full, base));
    else if (entry.endsWith('.php')) out.push(relative(base, full));
  }
  return out;
}

const arquivos = collect(ROOT);
console.log(`Arquivos PHP a verificar: ${arquivos.length}`);

// Provedor de IA falso: o wasm não tem internet nem servidor de sockets, então o
// caminho de IA precisa de um par Node para ser realmente exercitado.
async function iniciarProvedor() {
  const filho = spawn(process.execPath, [join(ROOT, 'tests', 'fake-openai.mjs')], { stdio: ['ignore', 'pipe', 'inherit'] });
  const endpoint = await new Promise((resolve, rejeitar) => {
    const limite = setTimeout(() => rejeitar(new Error('provedor de IA falso não subiu')), 10000);
    filho.stdout.on('data', (parte) => {
      const achado = /PROVEDOR_IA=(\S+)/.exec(parte.toString());
      if (achado) { clearTimeout(limite); resolve(achado[1]); }
    });
    filho.on('exit', (codigo) => rejeitar(new Error(`provedor de IA falso saiu com código ${codigo}`)));
  });
  return { filho, endpoint };
}

// Graph falso: mesmo motivo, para o caminho de publicação Meta.
async function iniciarGrafo() {
  const filho = spawn(process.execPath, [join(ROOT, 'tests', 'fake-graph.mjs')], { stdio: ['ignore', 'pipe', 'inherit'] });
  const endpoint = await new Promise((resolve, rejeitar) => {
    const limite = setTimeout(() => rejeitar(new Error('Graph falso não subiu')), 10000);
    filho.stdout.on('data', (parte) => {
      const achado = /GRAFO_FALSO=(\S+)/.exec(parte.toString());
      if (achado) { clearTimeout(limite); resolve(achado[1]); }
    });
    filho.on('exit', (codigo) => rejeitar(new Error(`Graph falso saiu com código ${codigo}`)));
  });
  return { filho, endpoint };
}

const provedor = await iniciarProvedor();
const grafo = await iniciarGrafo();

// processId é exigido pelo runtime do php-wasm antes do init.
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));

// Monta o projeto dentro do filesystem virtual do PHP (criando as pastas).
for (const rel of arquivos) {
  const destino = `${DOC_ROOT}/${rel}`;
  const pastas = destino.split('/').slice(0, -1).join('/');
  try { await php.mkdir(pastas, { recursive: true }); } catch { /* já existe */ }
  await php.writeFile(destino, readFileSync(join(ROOT, rel)));
}

// env não atravessa o wasm: o endpoint do provedor falso vai por arquivo.
await php.writeFile(`${DOC_ROOT}/tests/.provedor-ia`, provedor.endpoint + '\n');
await php.writeFile(`${DOC_ROOT}/tests/.grafo-falso`, grafo.endpoint + '\n');

// Uma única execução: parse de todos os arquivos + suíte funcional.
let resultado;
try {
  resultado = await php.run({
  code: `<?php
$errosLint = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('${DOC_ROOT}', FilesystemIterator::SKIP_DOTS));
foreach ($it as $arquivo) {
    if ($arquivo->getExtension() !== 'php') continue;
    try {
        token_get_all(file_get_contents($arquivo->getPathname()), TOKEN_PARSE);
    } catch (ParseError $erro) {
        $errosLint++;
        echo "PARSE FALHOU: " . $arquivo->getPathname() . " -> " . $erro->getMessage() . " (linha " . $erro->getLine() . ")\\n";
    }
}
echo "lint_erros=" . $errosLint . "\\n";
require '${DOC_ROOT}/tests/suite.php';
`,
  });
} catch (erro) {
  provedor.filho.kill();
  grafo.filho.kill();
  const parcial = erro?.response?.bytes ? Buffer.from(erro.response.bytes).toString('utf8') : '';
  process.stdout.write(parcial);
  process.stderr.write(`\nErro fatal no PHP: ${erro.message}\n${erro?.response?.errors ?? ''}\n`);
  process.exit(1);
}

provedor.filho.kill();
grafo.filho.kill();

process.stdout.write(resultado.text);
if (resultado.errors) process.stderr.write(String(resultado.errors));
const falhou = !resultado.text.includes('RESULTADO: OK') || !resultado.text.includes('lint_erros=0');
process.exit(falhou ? 1 : 0);
