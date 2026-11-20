<?php

declare(strict_types=1);

namespace Smia\Admin;

/**
 * Cofre pequeno para o único segredo que a interface precisa guardar: a chave
 * da OpenAI.
 *
 * Regra que não muda: a chave **nunca** volta para a tela, nem mascarada com
 * pedaço. A tela mostra só "configurada" e o tamanho. Quem precisa testar usa
 * o botão de teste, que chama o provedor no servidor.
 *
 * O texto cifrado fica no banco (arquivo 0600). A chave de cifra vem do
 * SECRET_KEY da instalação; sem SECRET_KEY, o valor é guardado em claro e a
 * tela avisa — melhor avisar do que fingir segurança que não existe.
 */
final class SecretBox
{
    private const CIFRA = 'aes-256-gcm';

    public function __construct(private readonly string $segredoMestre)
    {
    }

    public function disponivel(): bool
    {
        return trim($this->segredoMestre) !== '' && function_exists('openssl_encrypt');
    }

    public function cifrar(string $claro): string
    {
        if (!$this->disponivel()) {
            // Sem chave mestre não há como cifrar de verdade. Marca o valor
            // para que a tela possa dizer a verdade sobre o armazenamento.
            return 'plain:' . base64_encode($claro);
        }
        $iv = random_bytes(12);
        $tag = '';
        $cifrado = openssl_encrypt($claro, self::CIFRA, $this->chave(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cifrado === false) {
            throw new \RuntimeException('Não foi possível cifrar a chave. Verifique a extensão openssl do PHP.');
        }

        return 'enc:' . base64_encode($iv . $tag . $cifrado);
    }

    public function decifrar(string $guardado): string
    {
        if (str_starts_with($guardado, 'plain:')) {
            return (string) base64_decode(substr($guardado, 6), true);
        }
        if (!str_starts_with($guardado, 'enc:')) {
            return '';
        }
        $bruto = base64_decode(substr($guardado, 4), true);
        if ($bruto === false || strlen($bruto) < 29) {
            return '';
        }
        $iv = substr($bruto, 0, 12);
        $tag = substr($bruto, 12, 16);
        $cifrado = substr($bruto, 28);
        $claro = openssl_decrypt($cifrado, self::CIFRA, $this->chave(), OPENSSL_RAW_DATA, $iv, $tag);

        return $claro === false ? '' : $claro;
    }

    private function chave(): string
    {
        return hash('sha256', 'smia-secretbox|' . $this->segredoMestre, true);
    }
}
