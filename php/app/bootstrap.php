<?php
/**
 * Carregador da aplicação PHP do SocialMedia IA.
 *
 * Sem Composer e sem extensões exóticas: PHP 8.0+, pdo_sqlite, mbstring e
 * openssl. É o que qualquer cPanel oferece por padrão.
 *
 * A ordem importa: configuração -> classes -> banco -> sessão. Nada aqui
 * assume que o processo é Apache: o caminho público vem do servidor (ou de
 * SOCIALMEDIA_BASE_PATH) para funcionar em /edijalmemoura/socialai/.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('SocialMedia IA (PHP) exige PHP 8.0 ou superior. Versão atual: ' . PHP_VERSION);
}
foreach (['pdo_sqlite', 'mbstring', 'json'] as $extensao) {
    if (!extension_loaded($extensao)) {
        http_response_code(500);
        exit('Extensão PHP ausente: ' . $extensao . '. Ative no cPanel (Select PHP Version > Extensions).');
    }
}

require __DIR__ . '/autoload.php';

use Smia\Support\Config;
use Smia\Support\EnvFile;

// .env na raiz do projeto PHP (ou acima dele, para conviver com o pacote).
$candidatos = [SMIA_ROOT . '/.env', dirname(SMIA_ROOT) . '/.env'];
foreach ($candidatos as $arquivo) {
    if (is_file($arquivo)) {
        EnvFile::load($arquivo);
        break;
    }
}

return Config::fromEnvironment();
