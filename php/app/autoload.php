<?php
/**
 * Autoload simples: Smia\Support\EnvFile -> app/Support/EnvFile.php
 * Sem Composer de propósito — o pacote precisa rodar em cPanel sem instalação.
 */

declare(strict_types=1);

if (!defined('SMIA_ROOT')) {
    define('SMIA_ROOT', dirname(__DIR__));
}
if (!defined('SMIA_APP')) {
    define('SMIA_APP', __DIR__);
}

require_once __DIR__ . '/Support/helpers.php';

spl_autoload_register(static function (string $classe): void {
    if (!str_starts_with($classe, 'Smia\\')) {
        return;
    }
    $relativo = str_replace('\\', DIRECTORY_SEPARATOR, substr($classe, 5));
    $arquivo = __DIR__ . DIRECTORY_SEPARATOR . $relativo . '.php';
    if (is_file($arquivo)) {
        require $arquivo;
    }
});
