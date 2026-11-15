<?php

declare(strict_types=1);

namespace Smia\Support;

/**
 * Upload de imagens dos clientes (logotipo e referências).
 *
 * Reaproveita as mesmas regras do produto Python: só PNG, JPG/JPEG ou WEBP,
 * até 8 MB, e a validação é pela ASSINATURA dos bytes — a extensão do nome
 * enviado não é confiável.
 *
 * O nome final é gerado aqui ({client}-{kind}-{aleatório}.{ext}); nada do nome
 * original toca no sistema de arquivos, então não há path traversal.
 */
final class Upload
{
    public const TAMANHO_MAX = 8 * 1024 * 1024;
    private const EXTENSOES = ['png', 'jpg', 'jpeg', 'webp'];

    public function __construct(private readonly string $diretorio)
    {
    }

    public function diretorio(): string
    {
        if (!is_dir($this->diretorio) && !mkdir($this->diretorio, 0o755, true) && !is_dir($this->diretorio)) {
            throw new \RuntimeException('Não foi possível criar a pasta de uploads.');
        }

        return $this->diretorio;
    }

    /**
     * Copia um arquivo de $_FILES para a pasta de uploads.
     *
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $arquivo
     * @return array{filename:string,original_name:string}|null null quando nada foi enviado
     * @throws \InvalidArgumentException arquivo inválido (mensagem pronta ao usuário)
     */
    public function store(array $arquivo, int $clientId, string $kind): ?array
    {
        $erro = (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($erro === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) {
            throw new \InvalidArgumentException('A imagem passa do limite de 8 MB.');
        }
        if ($erro !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('O envio da imagem falhou. Tente novamente.');
        }

        $temporario = (string) ($arquivo['tmp_name'] ?? '');
        if ($temporario === '' || !is_file($temporario)) {
            throw new \InvalidArgumentException('O arquivo enviado não foi recebido corretamente.');
        }
        // Em produção (Apache/FPM) o PHP marca o arquivo como "enviado de
        // verdade" — proteção contra caminho arbitrário. Só nas execuções sem
        // HTTP (CLI dos testes e php-wasm, SAPI "wasm") a checagem é dispensada:
        // ali não existe requisição de visitante para forjar tmp_name.
        if (!$this->semHttp() && !is_uploaded_file($temporario)) {
            throw new \InvalidArgumentException('O arquivo enviado não foi recebido corretamente.');
        }

        $original = (string) ($arquivo['name'] ?? 'imagem');
        $extensao = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extensao, self::EXTENSOES, true)) {
            throw new \InvalidArgumentException('Use um arquivo PNG, JPG ou WEBP.');
        }

        $tamanho = filesize($temporario);
        if ($tamanho === false || $tamanho > self::TAMANHO_MAX) {
            throw new \InvalidArgumentException('A imagem passa do limite de 8 MB.');
        }

        $cabecalho = (string) file_get_contents($temporario, false, null, 0, 16);
        if (!$this->assinaturaValida($extensao, $cabecalho)) {
            throw new \InvalidArgumentException('O conteúdo não corresponde a uma imagem PNG, JPG ou WEBP válida.');
        }

        $nome = $clientId . '-' . preg_replace('/[^a-z0-9_-]/', '', strtolower($kind)) . '-' . bin2hex(random_bytes(12)) . '.' . $extensao;
        $destino = $this->diretorio() . '/' . $nome;
        $movido = $this->semHttp()
            ? rename($temporario, $destino)
            : move_uploaded_file($temporario, $destino);
        if (!$movido) {
            throw new \RuntimeException('Não foi possível salvar a imagem na pasta de dados.');
        }
        @chmod($destino, 0o644);

        return ['filename' => $nome, 'original_name' => mb_substr($original, 0, 255)];
    }

    /** Execução sem servidor HTTP (CLI, testes, php-wasm)? */
    private function semHttp(): bool
    {
        return in_array(PHP_SAPI, ['cli', 'cli-server', 'embed', 'phpdbg', 'wasm'], true);
    }

    /** Apaga um arquivo já validado (nome vindo do banco, nunca do usuário). */
    public function delete(?string $filename): void
    {
        if ($filename === null || $filename === '' || basename($filename) !== $filename) {
            return;
        }
        $caminho = $this->diretorio() . '/' . $filename;
        if (is_file($caminho)) {
            @unlink($caminho);
        }
    }

    /** Nome de arquivo seguro para servir por URL (?file=...). */
    public static function isSafeName(string $filename): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,200}$/', $filename);
    }

    public static function mime(string $filename): string
    {
        return match (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function assinaturaValida(string $extensao, string $cabecalho): bool
    {
        return match ($extensao) {
            'png' => str_starts_with($cabecalho, "\x89PNG\r\n\x1a\n"),
            'jpg', 'jpeg' => str_starts_with($cabecalho, "\xff\xd8\xff"),
            'webp' => strlen($cabecalho) >= 12 && substr($cabecalho, 0, 4) === 'RIFF' && substr($cabecalho, 8, 4) === 'WEBP',
            default => false,
        };
    }
}
