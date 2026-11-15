<?php
/**
 * Front controller — o único arquivo público da aplicação.
 *
 * Funciona na raiz do domínio e em subpasta (/edijalmemoura/socialai/): o
 * prefixo é detectado do próprio servidor ou de SOCIALMEDIA_BASE_PATH, e todas
 * as URLs geradas já saem com ele.
 */

declare(strict_types=1);

$config = require dirname(__DIR__) . '/app/bootstrap.php';

$requisicao = \Smia\Http\Request::fromGlobals();
$GLOBALS['smia_base_path'] = $config->basePath;

// Arquivo estático pedido direto ao PHP (servidor sem rewrite): entrega.
if (php_sapi_name() !== 'cli' && is_file(__DIR__ . $requisicao->path) && !str_ends_with($requisicao->path, '.php')) {
    return false;
}

$app = new \Smia\Application($config, null, $requisicao->scriptBase);
$app->handle($requisicao)->send();
