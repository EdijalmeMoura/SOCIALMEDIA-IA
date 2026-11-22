import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
const code = `<?php
echo 'zip: ', (class_exists('ZipArchive') ? 'sim' : 'NAO'), "\\n";
echo 'curl: ', (function_exists('curl_init') ? 'sim' : 'NAO'), "\\n";
echo 'phar: ', (class_exists('Phar') ? 'sim' : 'NAO'), "\\n";
echo 'allow_url_fopen: ', (ini_get('allow_url_fopen') ? 'sim' : 'NAO'), "\\n";
echo 'exec: ', (function_exists('exec') ? 'sim' : 'NAO'), "\\n";
echo 'posix: ', (function_exists('posix_kill') ? 'sim' : 'NAO'), "\\n";
`;
const r = await php.run({ code });
process.stdout.write(r.text + (r.errors || ''));
