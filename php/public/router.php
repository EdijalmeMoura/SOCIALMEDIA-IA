<?php
/**
 * Router do servidor embutido (`php -S`), apenas para desenvolvimento:
 *   php -S 127.0.0.1:8080 -t public public/router.php
 */
declare(strict_types=1);

$caminho = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if ($caminho !== '/' && is_file(__DIR__ . $caminho)) {
    return false;
}

require __DIR__ . '/index.php';
