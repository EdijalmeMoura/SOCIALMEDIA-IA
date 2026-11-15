<?php

declare(strict_types=1);

namespace Smia\Http;

/**
 * Requisição HTTP normalizada.
 *
 * Existe porque o produto é publicado em subpasta: o servidor pode entregar
 * SCRIPT_NAME=/pasta/index.php e PATH_INFO vazio, ou o caminho já sem o
 * prefixo. Aqui isso é resolvido uma vez, para o roteador e as views verem
 * sempre "caminho interno" + "prefixo público".
 */
final class Request
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $cookies,
        public readonly array $headers,
        public readonly string $scriptBase,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        [$scriptBase, $path] = self::resolvePaths();

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            post: $_POST,
            cookies: $_COOKIE,
            headers: self::collectHeaders(),
            scriptBase: $scriptBase,
        );
    }

    /**
     * Devolve [prefixo público, caminho interno].
     *
     * Casos tratados:
     *  - /edijalmemoura/socialai/            -> ['/edijalmemoura/socialai', '']
     *  - /edijalmemoura/socialai/install     -> [prefixo, '/install']
     *  - SCRIPT_NAME=/.../index.php + PATH_INFO=/install (mod_php/rewrite)
     *  - SOCIALMEDIA_BASE_PATH declarado e servidor entregando só "/install".
     *
     * @return array{0:string,1:string}
     */
    public static function resolvePaths(): array
    {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $caminhoUri = parse_url($uri, PHP_URL_PATH);
        $caminhoUri = is_string($caminhoUri) && $caminhoUri !== '' ? $caminhoUri : '/';
        $caminhoUri = rawurldecode($caminhoUri);
        $configurado = \Smia\Support\Config::normalizeBasePath((string) ($_SERVER['SOCIALMEDIA_BASE_PATH'] ?? null));

        // Diretório onde o front controller está, segundo o servidor.
        $prefixoScript = dirname($scriptName);
        $prefixoScript = ($prefixoScript === '/' || $prefixoScript === '.') ? '' : rtrim($prefixoScript, '/');
        $base = $configurado !== '' ? $configurado : $prefixoScript;

        // PATH_INFO preenchido pelo servidor é a fonte mais direta.
        $pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');
        if ($pathInfo !== '') {
            return [$base, '/' . trim($pathInfo, '/')];
        }

        // Sem PATH_INFO, o caminho interno é o que sobra da URI. A ordem importa:
        // primeiro os prefixos candidatos, do mais específico para o mais curto.
        $candidatos = array_values(array_unique(array_filter([$base, $configurado, $prefixoScript])));
        usort($candidatos, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $caminho = $caminhoUri;
        foreach ($candidatos as $prefixo) {
            if ($prefixo !== '' && ($caminho === $prefixo || str_starts_with($caminho, $prefixo . '/'))) {
                $caminho = substr($caminho, strlen($prefixo));
                break;
            }
        }
        $caminho = preg_replace('#/index\.php$#', '', $caminho) ?? $caminho;

        return [$base, '/' . trim($caminho, '/')];
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function input(string $chave, ?string $padrao = null): ?string
    {
        $valor = $this->post[$chave] ?? $this->query[$chave] ?? null;
        if ($valor === null || !is_scalar($valor)) {
            return $padrao;
        }

        return trim((string) $valor);
    }

    public function header(string $nome): ?string
    {
        return $this->headers[strtolower($nome)] ?? null;
    }

    public function cookie(string $nome): ?string
    {
        return $this->cookies[$nome] ?? null;
    }

    /** @return array<string,string> */
    private static function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $chave => $valor) {
            if (str_starts_with($chave, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($chave, 5)))] = (string) $valor;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $chave => $nome) {
            if (isset($_SERVER[$chave])) {
                $headers[$nome] = (string) $_SERVER[$chave];
            }
        }

        return $headers;
    }
}
