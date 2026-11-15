<?php

declare(strict_types=1);

namespace Smia\Studio;

/**
 * Formatos de arte e o layout da prancheta.
 *
 * O layout é a fonte da verdade do canvas: o navegador desenha exatamente estes
 * elementos (título, subtítulo, CTA e assinatura da marca). Nada de HTML/CSS
 * livre vindo do usuário — só números e textos curtos, validados aqui.
 *
 * Mesmas medidas do produto Python, para o arquivo exportado ficar idêntico.
 */
final class Layout
{
    public const FORMATOS = [
        'square' => ['label' => 'Post quadrado', 'width' => 1080, 'height' => 1080, 'image_size' => '1024x1024'],
        'portrait' => ['label' => 'Post vertical', 'width' => 1080, 'height' => 1350, 'image_size' => '1024x1536'],
        'story' => ['label' => 'Stories', 'width' => 1080, 'height' => 1920, 'image_size' => '1024x1536'],
        'carousel' => ['label' => 'Carrossel · capa', 'width' => 1080, 'height' => 1350, 'image_size' => '1024x1536'],
        'banner' => ['label' => 'Banner', 'width' => 1200, 'height' => 628, 'image_size' => '1536x1024'],
        'cover' => ['label' => 'Capa', 'width' => 1640, 'height' => 624, 'image_size' => '1536x1024'],
        'ad' => ['label' => 'Anúncio', 'width' => 1080, 'height' => 1350, 'image_size' => '1024x1536'],
    ];

    /** Campos de texto que a IA devolve e o editor mantém. */
    public const CAMPOS = ['title', 'subtitle', 'caption', 'hashtags', 'cta', 'visual_brief', 'image_suggestion', 'composition'];

    /** Campos que existem na arte (os demais são legenda/briefing). */
    public const ELEMENTOS = ['title', 'subtitle', 'cta', 'logo'];

    public static function formato(string $chave): ?array
    {
        return self::FORMATOS[$chave] ?? null;
    }

    /** Rótulo do formato, com queda segura para chaves antigas. */
    public static function rotulo(string $chave): string
    {
        return self::FORMATOS[$chave]['label'] ?? $chave;
    }

    /**
     * Layout inicial: título no topo, subtítulo no meio, CTA e assinatura
     * embaixo. Margem de 7,5% e área de respiro — o mesmo desenho do Python.
     *
     * @param array<string,mixed> $conteudo
     * @return array{elements:list<array<string,mixed>>}
     */
    public static function padrao(array $conteudo, int $largura, int $altura, string $corPrimaria, string $marca = 'Marca'): array
    {
        $primaria = self::corValida($corPrimaria) ? strtolower($corPrimaria) : '#3158d8';
        $margem = (int) round($largura * 0.075);
        $marca = self::textoCurto($marca, 100) ?: 'Marca';

        return ['elements' => [
            [
                'id' => 'title', 'label' => 'Título', 'text' => self::textoCurto($conteudo['title'] ?? '', 1000),
                'x' => $margem, 'y' => (int) round($altura * 0.20), 'w' => $largura - $margem * 2, 'h' => (int) round($altura * 0.20),
                'fontSize' => (int) round($largura * 0.075), 'color' => '#ffffff', 'fill' => '', 'align' => 'left', 'bold' => true,
            ],
            [
                'id' => 'subtitle', 'label' => 'Subtítulo', 'text' => self::textoCurto($conteudo['subtitle'] ?? '', 1000),
                'x' => $margem, 'y' => (int) round($altura * 0.44), 'w' => (int) round($largura * 0.74), 'h' => (int) round($altura * 0.16),
                'fontSize' => (int) round($largura * 0.035), 'color' => '#ffffff', 'fill' => '', 'align' => 'left', 'bold' => false,
            ],
            [
                'id' => 'cta', 'label' => 'CTA', 'text' => self::textoCurto($conteudo['cta'] ?? '', 1000),
                'x' => $margem, 'y' => (int) round($altura * 0.72), 'w' => (int) round($largura * 0.55), 'h' => (int) round($altura * 0.10),
                'fontSize' => (int) round($largura * 0.030), 'color' => '#ffffff', 'fill' => '', 'align' => 'center', 'bold' => true,
            ],
            [
                'id' => 'logo', 'label' => 'Marca', 'text' => $marca,
                'x' => (int) round($largura * 0.70), 'y' => (int) round($altura * 0.89), 'w' => (int) round($largura * 0.24), 'h' => (int) round($altura * 0.055),
                'fontSize' => (int) round($largura * 0.022), 'color' => '#ffffff', 'fill' => '', 'align' => 'center', 'bold' => true,
            ],
        ]];
    }

    /**
     * Valida o layout vindo do editor (JSON do navegador).
     *
     * Rejeita qualquer coisa fora do contrato em vez de tentar consertar: se o
     * layout não é exatamente os quatro elementos conhecidos, é erro do cliente.
     *
     * @return array{elements:list<array<string,mixed>>}
     * @throws StudioError
     */
    public static function validar(mixed $bruto, int $largura, int $altura): array
    {
        $dados = is_string($bruto) ? json_decode($bruto, true) : $bruto;
        if (!is_array($dados) || !isset($dados['elements']) || !is_array($dados['elements'])) {
            throw new StudioError('O layout do editor está inválido. Atualize a página e tente novamente.');
        }
        $elementos = $dados['elements'];
        if (count($elementos) !== count(self::ELEMENTOS)) {
            throw new StudioError('O layout do editor está inválido. Atualize a página e tente novamente.');
        }

        $vistos = [];
        $limpos = [];
        foreach ($elementos as $elemento) {
            if (!is_array($elemento)) {
                throw new StudioError('O layout do editor está inválido. Atualize a página e tente novamente.');
            }
            $id = (string) ($elemento['id'] ?? '');
            if (!in_array($id, self::ELEMENTOS, true) || isset($vistos[$id])) {
                throw new StudioError('O layout do editor está inválido. Atualize a página e tente novamente.');
            }
            $vistos[$id] = true;

            $x = self::inteiro($elemento['x'] ?? 0);
            $y = self::inteiro($elemento['y'] ?? 0);
            $w = self::inteiro($elemento['w'] ?? 0);
            $h = self::inteiro($elemento['h'] ?? 0);
            $fonte = self::inteiro($elemento['fontSize'] ?? 0);
            $alinhamento = (string) ($elemento['align'] ?? 'left');

            $limpos[] = [
                'id' => $id,
                'label' => ucfirst($id),
                'text' => self::textoCurto($elemento['text'] ?? '', 1000),
                'x' => max(0, min($largura, $x)),
                'y' => max(0, min($altura, $y)),
                'w' => max(20, min($largura, $w)),
                'h' => max(20, min($altura, $h)),
                'fontSize' => max(12, min(240, $fonte)),
                'color' => self::corValida((string) ($elemento['color'] ?? '')) ? strtolower((string) $elemento['color']) : '#ffffff',
                'fill' => self::corValida((string) ($elemento['fill'] ?? '')) ? strtolower((string) $elemento['fill']) : '',
                'align' => in_array($alinhamento, ['left', 'center', 'right'], true) ? $alinhamento : 'left',
                'bold' => (bool) ($elemento['bold'] ?? false),
            ];
        }

        return ['elements' => $limpos];
    }

    /** #rgb ou #rrggbb — só isso entra em cor de arte. */
    public static function corValida(?string $cor): bool
    {
        return is_string($cor) && (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $cor);
    }

    /** Texto de uma linha, sem caracteres de controle, já aparado. */
    public static function textoCurto(mixed $texto, int $limite): string
    {
        $texto = is_scalar($texto) ? (string) $texto : '';
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto);
        $texto = trim($texto);

        return mb_substr($texto, 0, $limite);
    }

    private static function inteiro(mixed $valor): int
    {
        if (is_int($valor)) {
            return $valor;
        }
        if (is_float($valor)) {
            return (int) round($valor);
        }
        if (is_string($valor) && preg_match('/^-?\d{1,9}$/', trim($valor))) {
            return (int) trim($valor);
        }

        return 0;
    }
}
