<?php

declare(strict_types=1);

namespace Smia\Support;

/**
 * Configuração da instância, resolvida uma única vez no boot.
 *
 * Todos os caminhos são absolutos e nenhum deles fica dentro de public/,
 * que é a única pasta exposta pelo servidor web.
 */
final class Config
{
    public function __construct(
        public readonly string $root,
        public readonly string $dataDir,
        public readonly string $dbPath,
        public readonly string $envFile,
        public readonly string $basePath,
        public readonly string $publicOrigin,
        public readonly string $secretKey,
        public readonly bool $cookieSecure,
        public readonly ?string $installToken,
        public readonly string $timezone,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $root = defined('SMIA_ROOT') ? SMIA_ROOT : dirname(__DIR__, 2);
        $dataDir = self::env('SOCIALMEDIA_DATA') ?: $root . '/data';
        $dbPath = self::env('SOCIALMEDIA_DB') ?: $dataDir . '/socialmedia.sqlite';
        $envFile = self::env('SOCIALMEDIA_INSTALLER_ENV')
            ?: (self::env('SOCIALMEDIA_ENV_FILE') ?: $dataDir . '/installer.env');

        return new self(
            root: $root,
            dataDir: rtrim($dataDir, '/'),
            dbPath: $dbPath,
            envFile: $envFile,
            basePath: self::normalizeBasePath(self::env('SOCIALMEDIA_BASE_PATH')),
            publicOrigin: rtrim(self::env('SOCIALMEDIA_PUBLIC_ORIGIN') ?? '', '/'),
            secretKey: self::env('SECRET_KEY') ?? '',
            cookieSecure: self::env('COOKIE_SECURE') === '1',
            installToken: self::env('SOCIALMEDIA_INSTALL_TOKEN') ?: null,
            timezone: self::env('SOCIALMEDIA_TIMEZONE') ?: 'America/Sao_Paulo',
        );
    }

    /** Prefixo da subpasta, sempre começando com "/" e sem barra no fim. */
    /**
     * Cópia com o prefixo detectado na requisição.
     *
     * O servidor pode não ter SOCIALMEDIA_BASE_PATH configurado e ainda assim
     * entregar a aplicação em /pasta: nesse caso o prefixo vem da própria
     * requisição e precisa valer para TODAS as URLs (e para o cookie de sessão).
     */
    public function withBasePath(string $basePath): self
    {
        return new self(
            root: $this->root,
            dataDir: $this->dataDir,
            dbPath: $this->dbPath,
            envFile: $this->envFile,
            basePath: $basePath,
            publicOrigin: $this->publicOrigin,
            secretKey: $this->secretKey,
            cookieSecure: $this->cookieSecure,
            installToken: $this->installToken,
            timezone: $this->timezone,
        );
    }

    public static function normalizeBasePath(?string $valor): string
    {
        $valor = trim((string) $valor);
        if ($valor === '' || $valor === '/') {
            return '';
        }
        $valor = '/' . trim($valor, '/');

        return $valor === '/' ? '' : $valor;
    }

    /** URL absoluta para um caminho interno, respeitando a subpasta. */
    public function url(string $caminho = '/'): string
    {
        $caminho = '/' . ltrim($caminho, '/');

        return $this->basePath . ($caminho === '/' ? '/' : $caminho);
    }

    /** Origem pública para callbacks (Meta) e URLs assinadas.
     *
     * `publicOrigin` é a URL COMPLETA da instância e já pode conter a subpasta
     * (https://host/pasta). O caminho interno é anexado sem repetir o prefixo;
     * se a origem vier sem a pasta, ela é acrescentada uma única vez.
     */
    public function absoluteUrl(string $caminho = '/'): string
    {
        $origem = rtrim($this->publicOrigin, '/');
        if ($origem === '') {
            return $this->url($caminho);
        }
        $base = $this->basePath;
        if ($base !== '' && !str_ends_with($origem, $base)) {
            $origem .= $base;
        }

        return $origem . '/' . ltrim($caminho, '/');
    }

    public static function env(string $chave): ?string
    {
        $valor = getenv($chave);
        if ($valor === false) {
            $valor = $_ENV[$chave] ?? $_SERVER[$chave] ?? null;
        }
        if ($valor === null) {
            return null;
        }
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
