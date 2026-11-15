<?php

declare(strict_types=1);

namespace Smia\Support;

/**
 * Sessão endurecida + CSRF.
 *
 * Cookies com HttpOnly, SameSite=Lax e Secure quando há HTTPS. O caminho do
 * cookie inclui a subpasta, senão o navegador não devolve a sessão em
 * /edijalmemoura/socialai/*.
 */
final class Session
{
    public const CSRF_FIELD = 'csrf_token';
    private const CHAVE_CSRF = '_csrf';
    private const CHAVE_FLASH = '_flash';

    public function __construct(private readonly string $basePath, private readonly bool $secure)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (headers_sent()) {
            // CLI/testes: sem cabeçalhos, a sessão usa o storage padrão.
            if (PHP_SAPI === 'cli' && session_status() === PHP_SESSION_NONE) {
                @session_start();
            }

            return;
        }
        $caminho = $this->basePath === '' ? '/' : $this->basePath . '/';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $caminho,
            'httponly' => true,
            'secure' => $this->secure,
            'samesite' => 'Lax',
        ]);
        session_name('smia_session');
        session_start();
    }

    public function get(string $chave, mixed $padrao = null): mixed
    {
        return $_SESSION[$chave] ?? $padrao;
    }

    public function put(string $chave, mixed $valor): void
    {
        $_SESSION[$chave] = $valor;
    }

    public function forget(string $chave): void
    {
        unset($_SESSION[$chave]);
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $parametros = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $parametros['path'],
                'httponly' => true,
                'secure' => (bool) $parametros['secure'],
                'samesite' => 'Lax',
            ]);
            session_destroy();
        }
    }

    /** Token CSRF estável por sessão (comparado em tempo constante). */
    public function csrfToken(): string
    {
        if (empty($_SESSION[self::CHAVE_CSRF])) {
            $_SESSION[self::CHAVE_CSRF] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CHAVE_CSRF];
    }

    public function csrfValid(?string $enviado): bool
    {
        $esperado = $_SESSION[self::CHAVE_CSRF] ?? '';

        return is_string($enviado) && $esperado !== '' && hash_equals($esperado, $enviado);
    }

    public function flash(string $tipo, string $mensagem): void
    {
        $_SESSION[self::CHAVE_FLASH][] = ['type' => $tipo, 'message' => $mensagem];
    }

    /** @return array<int,array{type:string,message:string}> */
    public function takeFlashes(): array
    {
        $mensagens = $_SESSION[self::CHAVE_FLASH] ?? [];
        unset($_SESSION[self::CHAVE_FLASH]);

        return $mensagens;
    }
}
