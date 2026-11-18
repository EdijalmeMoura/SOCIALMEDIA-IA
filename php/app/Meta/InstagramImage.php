<?php

declare(strict_types=1);

namespace Smia\Meta;

/**
 * Versão JPEG da arte para o Instagram.
 *
 * O Instagram não aceita PNG no envio por URL e exige proporção de feed
 * (4:5 a 1,91:1). Em vez de recusar a publicação sem explicação, o produto
 * confere a arte e, quando dá, gera uma cópia JPEG temporária — apagada logo
 * depois do envio. O arquivo aprovado nunca é alterado.
 */
final class InstagramImage
{
    public const TAMANHO_MAX = 8 * 1024 * 1024;
    private const LARGURA_MAX = 1440;
    private const LARGURA_MIN = 320;

    /**
     * O que impede esta arte de ir ao Instagram — ou null quando está tudo bem.
     *
     * A checagem é feita aqui, com mensagem pronta: publicar e só então
     * descobrir que a proporção não serve seria pior para quem opera.
     */
    public static function problema(string $caminho): ?string
    {
        if (!is_file($caminho)) {
            return 'O arquivo exportado desta aprovação não está disponível.';
        }
        $info = @getimagesize($caminho);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            return 'Não foi possível ler a arte exportada como imagem válida.';
        }
        $formato = (string) ($info['mime'] ?? '');
        if (!in_array($formato, ['image/png', 'image/jpeg'], true)) {
            return 'O envio de imagem ao Instagram exige uma arte PNG ou JPEG válida.';
        }
        $proporcao = $info[0] / $info[1];
        if ($proporcao < 0.8 || $proporcao > 1.91) {
            return 'Este envio usa uma publicação de feed do Instagram, que exige proporção entre 4:5 e 1,91:1. Stories 9:16 e banners precisam ser ajustados e aprovados novamente.';
        }

        return null;
    }

    /**
     * Gera a cópia JPEG e devolve o nome do arquivo criado.
     *
     * @throws MetaError quando a arte não pode ser convertida ou passa de 8 MB
     */
    public static function preparar(string $origem, string $diretorio): string
    {
        $problema = self::problema($origem);
        if ($problema !== null) {
            throw new MetaError($problema);
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new MetaError('A extensão GD do PHP não está ativa, e sem ela não é possível preparar a versão JPEG para o Instagram.');
        }

        $bytes = (string) file_get_contents($origem);
        $imagem = @imagecreatefromstring($bytes);
        if ($imagem === false) {
            throw new MetaError('Não foi possível preparar uma versão JPEG segura da arte para o Instagram.');
        }

        $nome = 'meta_instagram_' . bin2hex(random_bytes(12)) . '.jpg';
        $destino = rtrim($diretorio, '/') . '/' . $nome;
        $largura = imagesx($imagem);
        $altura = imagesy($imagem);
        $alvoLargura = max(self::LARGURA_MIN, min(self::LARGURA_MAX, $largura));
        $alvoAltura = max(1, (int) round($altura * $alvoLargura / max(1, $largura)));

        $lona = imagecreatetruecolor($alvoLargura, $alvoAltura);
        // PNG com transparência precisa de fundo branco: o Instagram só mostra JPEG.
        imagefill($lona, 0, 0, imagecolorallocate($lona, 255, 255, 255));
        imagecopyresampled($lona, $imagem, 0, 0, 0, 0, $alvoLargura, $alvoAltura, $largura, $altura);
        imagedestroy($imagem);

        $completo = false;
        try {
            foreach ([92, 84, 76] as $qualidade) {
                if (!imagejpeg($lona, $destino, $qualidade)) {
                    throw new MetaError('Não foi possível preparar uma versão JPEG segura da arte para o Instagram.');
                }
                clearstatcache(true, $destino);
                if ((int) filesize($destino) <= self::TAMANHO_MAX) {
                    $completo = true;

                    return $nome;
                }
            }

            throw new MetaError('A versão JPEG da arte excede 8 MB, limite desta publicação no Instagram.');
        } finally {
            imagedestroy($lona);
            if (!$completo && is_file($destino)) {
                @unlink($destino);
            }
        }
    }

    /** Apaga a cópia temporária (nunca o arquivo aprovado). */
    public static function remover(?string $arquivo, string $diretorio): void
    {
        if ($arquivo === null || preg_match('/^meta_instagram_[a-f0-9]{24}\.jpg$/', $arquivo) !== 1) {
            return;
        }
        $caminho = rtrim($diretorio, '/') . '/' . $arquivo;
        if (is_file($caminho)) {
            @unlink($caminho);
        }
    }
}
