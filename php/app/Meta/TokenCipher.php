<?php

declare(strict_types=1);

namespace Smia\Meta;

/**
 * Criptografia do token da Página em repouso.
 *
 * AES-256-GCM (extensão openssl, que todo cPanel tem) com chave dedicada do
 * .env: o banco sozinho não abre o token. O formato é `v1:base64(iv|tag|dados)`
 * para permitir trocar de algoritmo no futuro sem adivinhar o que é o quê.
 */
final class TokenCipher
{
    private const PREFIXO = 'v1:';
    private const CIFRA = 'aes-256-gcm';
    private const TAMANHO_IV = 12;
    private const TAMANHO_TAG = 16;

    private readonly string $chave;

    public function __construct(string $chaveBase64)
    {
        $bytes = base64_decode($chaveBase64, true);
        if ($bytes === false || strlen($bytes) !== 32) {
            throw new MetaError('META_TOKEN_ENCRYPTION_KEY inválida. Gere 32 bytes aleatórios em Base64 e mantenha em segredo.');
        }
        $this->chave = $bytes;
    }

    public function cifrar(string $token): string
    {
        if (trim($token) === '') {
            throw new MetaError('O Meta retornou um token vazio.');
        }
        if (!function_exists('openssl_encrypt')) {
            throw new MetaError('A extensão openssl do PHP não está ativa; sem ela o token não pode ser guardado com segurança.');
        }
        $iv = random_bytes(self::TAMANHO_IV);
        $tag = '';
        $dados = openssl_encrypt($token, self::CIFRA, $this->chave, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAMANHO_TAG);
        if ($dados === false) {
            throw new MetaError('Não foi possível criptografar o token da Página.');
        }

        return self::PREFIXO . base64_encode($iv . $tag . $dados);
    }

    public function decifrar(string $guardado): string
    {
        if (!str_starts_with($guardado, self::PREFIXO)) {
            throw new MetaError('O token guardado está em um formato desconhecido.');
        }
        $bruto = base64_decode(substr($guardado, strlen(self::PREFIXO)), true);
        if ($bruto === false || strlen($bruto) <= self::TAMANHO_IV + self::TAMANHO_TAG) {
            throw new MetaError('O token guardado está corrompido.');
        }
        $iv = substr($bruto, 0, self::TAMANHO_IV);
        $tag = substr($bruto, self::TAMANHO_IV, self::TAMANHO_TAG);
        $dados = substr($bruto, self::TAMANHO_IV + self::TAMANHO_TAG);
        $token = openssl_decrypt($dados, self::CIFRA, $this->chave, OPENSSL_RAW_DATA, $iv, $tag);
        if ($token === false) {
            // A causa mais comum é a chave ter mudado depois da conexão.
            throw new MetaError('Não foi possível abrir o token guardado. Confirme se META_TOKEN_ENCRYPTION_KEY é a mesma desde a conexão.');
        }

        return $token;
    }
}
