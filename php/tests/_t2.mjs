import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
const code = `<?php
$f = '/tmp/x.zip'; @unlink($f);
$z = new ZipArchive();
$r = $z->open($f, ZipArchive::CREATE | ZipArchive::OVERWRITE);
echo "open=" . var_export($r, true) . "\\n";
$z->addFromString('app/Support/Config.php', "<?php");
$z->addFromString('../fora.php', "x");
echo "close=" . var_export($z->close(), true) . "\\n";
clearstatcache();
echo "existe=" . var_export(is_file($f), true) . " size=" . @filesize($f) . "\\n";
$z2 = new ZipArchive(); $o=$z2->open($f);
echo "reabrir=" . var_export($o, true) . " num=" . $z2->numFiles . "\\n";
for ($i=0;$i<$z2->numFiles;$i++){ $s=$z2->statIndex($i); echo "  [" . $s['name'] . "]\\n"; }
`;
const r = await php.run({ code });
process.stdout.write(r.text + (r.errors || ''));
