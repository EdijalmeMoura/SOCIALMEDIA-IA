<?php

declare(strict_types=1);

namespace Smia\Http;

/**
 * Resposta HTTP imutável o suficiente para ser testável sem servidor.
 * Os testes criam a resposta, inspecionam status/cabeçalhos e imprimem.
 */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $corpo, int $status = 200): self
    {
        return new self($corpo, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $destino, int $status = 302): self
    {
        return new self('', $status, ['Location' => $destino]);
    }

    public static function text(string $corpo, int $status = 200): self
    {
        return new self($corpo, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * Resposta binária (imagens enviadas). Lê o arquivo aqui, então o corpo da
     * resposta continua sendo uma string — nada de stream no handler.
     */
    /** Resposta JSON (o editor usa para confirmar a exportação). */
    public static function json(array $dados, int $status = 200): self
    {
        return new self(
            (string) json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function file(string $caminho, string $mime, int $status = 200): self
    {
        $conteudo = is_file($caminho) ? (string) file_get_contents($caminho) : '';

        return new self($conteudo, $status, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($conteudo),
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Bytes em memória (backup do banco). Diferente de `file()`, não define
     * cache nem lê do disco: quem chama já tem o conteúdo e ele é único.
     */
    public static function bytes(string $conteudo, string $mime, int $status = 200): self
    {
        return new self($conteudo, $status, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($conteudo),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function withHeader(string $nome, string $valor): self
    {
        $this->headers[$nome] = $valor;

        return $this;
    }

    /** Cabeçalhos de segurança iguais aos do produto Python. */
    public function withSecurityHeaders(): self
    {
        return $this
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
                . "font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; connect-src 'self'; "
                . "frame-ancestors 'self'"
            );
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $nome => $valor) {
                header($nome . ': ' . $valor, true);
            }
        }
        echo $this->body;
    }

    public function header(string $nome): ?string
    {
        foreach ($this->headers as $chave => $valor) {
            if (strcasecmp($chave, $nome) === 0) {
                return $valor;
            }
        }

        return null;
    }
}
