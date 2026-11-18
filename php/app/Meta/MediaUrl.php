<?php

declare(strict_types=1);

namespace Smia\Meta;

use Smia\Support\Config;

/**
 * URL pública e ASSINADA da arte exportada.
 *
 * A Meta não recebe upload de arquivo: ela busca a imagem por URL HTTPS. Como
 * a pasta de dados fica fora de public/, a imagem só é servida por uma rota
 * que exige assinatura HMAC com prazo — um endereço vazado morre em minutos e
 * ninguém consegue listar o que existe.
 */
final class MediaUrl
{
    private const VALIDADE = 600;
    private const JANELA = 900;

    public function __construct(private readonly string $basePublica, private readonly string $segredo)
    {
    }

    /**
     * Origem usada pela Meta para buscar as imagens.
     *
     * `META_PUBLIC_BASE_URL` manda; sem ela, a origem pública da instalação
     * serve (é o caso do cPanel em subpasta). HTTP é recusado: a Meta não
     * baixa imagem de origem insegura.
     */
    public static function resolver(Config $config): self
    {
        $base = trim($config->metaPublicBaseUrl) !== '' ? trim($config->metaPublicBaseUrl) : rtrim($config->publicOrigin, '/');
        $partes = parse_url($base);
        if (!is_array($partes) || ($partes['scheme'] ?? '') !== 'https' || empty($partes['host'])
            || isset($partes['user']) || isset($partes['pass']) || isset($partes['query']) || isset($partes['fragment'])
            || str_contains((string) ($partes['path'] ?? ''), '..')) {
            throw new MetaError('Configure META_PUBLIC_BASE_URL com a origem HTTPS pública desta aplicação (sem consulta nem fragmento) para que a Meta consiga buscar as imagens aprovadas.');
        }
        if (trim($config->secretKey) === '') {
            throw new MetaError('Sem SECRET_KEY configurada não é possível assinar a URL da imagem.');
        }

        return new self(rtrim($base, '/'), $config->secretKey);
    }

    /** Tenta resolver sem lançar: [objeto, erro]. */
    public static function tentar(Config $config): array
    {
        try {
            return [self::resolver($config), null];
        } catch (MetaError $erro) {
            return [null, $erro->getMessage()];
        }
    }

    public function base(): string
    {
        return $this->basePublica;
    }

    /**
     * URL assinada para um arquivo da pasta de uploads.
     *
     * Só PNG e JPG entram: é o que a Meta aceita e o que o estúdio exporta.
     */
    public function para(string $arquivo, ?int $agora = null): string
    {
        if (!$this->nomeSeguro($arquivo)) {
            throw new MetaError('A arte exportada não é um arquivo PNG ou JPG seguro.');
        }
        $expira = ($agora ?? time()) + self::VALIDADE;

        return $this->basePublica
            . '/meta/media/' . rawurlencode($arquivo)
            . '?expires=' . $expira
            . '&signature=' . $this->assinar($arquivo, $expira);
    }

    /** Confere prazo e assinatura de uma requisição de mídia. */
    public function valida(string $arquivo, ?string $expira, ?string $assinatura, ?int $agora = null): bool
    {
        if (!$this->nomeSeguro($arquivo) || $expira === null || $assinatura === null) {
            return false;
        }
        if (preg_match('/^\d{1,12}$/', $expira) !== 1) {
            return false;
        }
        $agora = $agora ?? time();
        $prazo = (int) $expira;
        if ($prazo < $agora || $prazo > $agora + self::JANELA) {
            return false;
        }

        return hash_equals($this->assinar($arquivo, $prazo), $assinatura);
    }

    private function assinar(string $arquivo, int $expira): string
    {
        return hash_hmac('sha256', $arquivo . ':' . $expira, $this->segredo);
    }

    public function nomeSeguro(string $arquivo): bool
    {
        if ($arquivo === '' || $arquivo !== basename($arquivo) || str_contains($arquivo, '..')) {
            return false;
        }
        if (preg_match('/^[A-Za-z0-9._-]{1,200}$/', $arquivo) !== 1) {
            return false;
        }

        return in_array(strtolower((string) pathinfo($arquivo, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true);
    }
}
