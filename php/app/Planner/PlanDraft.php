<?php

declare(strict_types=1);

namespace Smia\Planner;

/**
 * Rascunho de calendário editorial SEM IA.
 *
 * Existe para dois casos concretos: (a) a agência ainda não configurou
 * OPENAI_API_KEY e não pode ficar sem pauta nenhuma; (b) o provedor caiu no
 * meio do trabalho e perder o planejamento inteiro seria pior.
 *
 * O que ele faz: distribui as publicações pelas datas e redes escolhidas e
 * monta tema, formato, CTA e briefing visual a partir do segmento, do objetivo
 * e da identidade do cliente — variando o "ângulo" de cada post (bastidor,
 * prova social, educacional, oferta, marca...). O texto é genérico de propósito:
 * o produto deixa claro na tela e no banco (`ai_model = local-draft`) que
 * aquilo é ponto de partida para a equipe editar, não conteúdo de IA.
 */
final class PlanDraft
{
    /** Ângulos editoriais rotativos, com formato e intenção de CTA. */
    private const ANGULOS = [
        ['tema' => 'Bastidores da operação', 'objetivo' => 'Mostrar o processo por trás do produto e criar proximidade', 'formato' => 'Carrossel', 'cta' => 'Conte nos comentários o que você quer ver por aqui.', 'visual' => 'Sequência de fotos reais do dia a dia, luz natural'],
        ['tema' => 'Dúvida frequente do público', 'objetivo' => 'Resolver uma objeção comum e ganhar confiança', 'formato' => 'Post único com texto', 'cta' => 'Salve este post para consultar depois.', 'visual' => 'Card limpo, tipografia grande, fundo na cor principal'],
        ['tema' => 'Detalhe do produto ou serviço', 'objetivo' => 'Apresentar um diferencial concreto do que é oferecido', 'formato' => 'Foto em close', 'cta' => 'Toque no link da bio para saber mais.', 'visual' => 'Close com fundo neutro, cor de destaque no elemento principal'],
        ['tema' => 'História de quem atende', 'objetivo' => 'Humanizar a marca e fortalecer a lembrança', 'formato' => 'Reels curto', 'cta' => 'Siga para acompanhar os próximos capítulos.', 'visual' => 'Vídeo na vertical, fala direta para a câmera, legenda embutida'],
        ['tema' => 'Antes e depois / transformação', 'objetivo' => 'Demonstrar resultado de forma visual', 'formato' => 'Carrossel comparativo', 'cta' => 'Compartilhe com quem precisa ver isso.', 'visual' => 'Duas imagens lado a lado, manter as cores da marca'],
        ['tema' => 'Dica prática do segmento', 'objetivo' => 'Ser útil para o público e ampliar alcance orgânico', 'formato' => 'Carrossel de 5 telas', 'cta' => 'Qual dessas dicas você já aplica?', 'visual' => 'Telas com numeração, ícones simples, cor secundária de fundo'],
        ['tema' => 'Prova social de clientes', 'objetivo' => 'Reduzir a hesitação de quem está decidindo', 'formato' => 'Post com depoimento', 'cta' => 'Quer o mesmo resultado? Fale com a gente.', 'visual' => 'Print ou foto do cliente com o texto legível no celular'],
        ['tema' => 'Convite para conversar', 'objetivo' => 'Gerar contato direto e oportunidades comerciais', 'formato' => 'Story com enquete', 'cta' => 'Chame no direct para tirar suas dúvidas.', 'visual' => 'Fundo na cor principal, pergunta curta, sticker de enquete'],
    ];

    /** Formato padrão por rede, para variar a linguagem. */
    private const POR_REDE = [
        'Instagram' => ['Carrossel', 'Reels curto', 'Post único com texto', 'Story com enquete'],
        'Facebook' => ['Post único com texto', 'Carrossel', 'Foto em close', 'Vídeo curto'],
        'TikTok' => ['Vídeo curto vertical', 'Vídeo resposta', 'Bastidor em vídeo'],
        'LinkedIn' => ['Post de texto', 'Carrossel em PDF', 'Artigo curto'],
    ];

    /**
     * @param array<string,mixed> $cliente perfil + identidade visual
     * @param array{period_days:int,target_posts:int,start_date:string,networks:list<string>,objective:string,campaign_context:string} $spec
     * @return list<array<string,string>>
     */
    public function gerar(array $cliente, array $spec): array
    {
        $inicio = new \DateTimeImmutable($spec['start_date']);
        $quantidade = max(1, min(PlanGenerator::MAX_POR_PERIODO, (int) $spec['target_posts']));
        $dias = max(1, (int) $spec['period_days']);
        $redes = array_values(array_filter($spec['networks'], static fn (string $r): bool => in_array($r, PlanGenerator::REDES, true)));
        if ($redes === []) {
            $redes = ['Instagram'];
        }

        $marca = trim((string) ($cliente['trade_name'] ?? '')) ?: (string) ($cliente['company_name'] ?? 'a marca');
        $segmento = trim((string) ($cliente['segment'] ?? ''));
        $publico = trim((string) ($cliente['audience'] ?? ''));
        $objetivo = trim((string) $spec['objective']);
        $contexto = trim((string) $spec['campaign_context']);
        $tom = trim((string) ($cliente['communication_tone'] ?? ''));
        $estilo = trim((string) ($cliente['visual_style'] ?? ''));
        $corPrincipal = (string) ($cliente['primary_color'] ?? '#3158d8');
        $corDestaque = (string) ($cliente['accent_color'] ?? '#16a68a');

        $posts = [];
        for ($i = 0; $i < $quantidade; $i++) {
            $angulo = self::ANGULOS[$i % count(self::ANGULOS)];
            $rede = $redes[$i % count($redes)];
            $dia = $dias === 1 ? 0 : (int) floor($i * $dias / $quantidade);
            $data = $inicio->modify('+' . min($dia, $dias - 1) . ' days');
            $formato = self::formatoAlternativo($rede, $angulo['formato'], intdiv($i, count($redes)));

            $tema = $angulo['tema'];
            if ($segmento !== '') {
                $tema .= ' · ' . $segmento;
            }

            $briefingVisual = $angulo['visual'] . '; usar a cor principal ' . $corPrincipal;
            if ($estilo !== '') {
                $briefingVisual .= ' no estilo ' . $estilo;
            }
            $briefingVisual .= ', destaque em ' . $corDestaque . '.';

            $posts[] = [
                'publication_date' => $data->format('Y-m-d'),
                'network' => $rede,
                'theme' => $tema,
                'strategic_objective' => $angulo['objetivo'] . ' — alinhado ao objetivo "' . $objetivo . '".',
                'content_type' => $formato,
                'main_text' => $this->textoPrincipal($marca, $segmento, $publico, $angulo['tema']),
                'caption' => $this->legenda($marca, $tom, $contexto, $angulo['tema']),
                'hashtags' => $this->hashtags($segmento, $rede, $marca),
                'cta' => $angulo['cta'],
                'visual_brief' => $briefingVisual,
                'image_suggestion' => 'Imagem própria de ' . $marca . ': ' . mb_strtolower($angulo['tema']) . ' (evitar banco de imagens genérico).',
            ];
        }

        return $posts;
    }

    private static function formatoAlternativo(string $rede, string $padrao, int $rodada): string
    {
        $opcoes = self::POR_REDE[$rede] ?? [];
        if ($opcoes === []) {
            return $padrao;
        }

        return $opcoes[$rodada % count($opcoes)];
    }

    private function textoPrincipal(string $marca, string $segmento, string $publico, string $tema): string
    {
        $quem = $publico !== '' ? $publico : 'quem acompanha a marca';
        $onde = $segmento !== '' ? ' em ' . mb_strtolower($segmento) : '';

        return mb_strtoupper(mb_substr($tema, 0, 1)) . mb_substr($tema, 1) . ': um recorte do que a '
            . $marca . ' faz' . $onde . ' e por que isso importa para ' . $quem
            . '. [RASCUNHO LOCAL — revise e complete com informações reais antes de publicar.]';
    }

    private function legenda(string $marca, string $tom, string $contexto, string $tema): string
    {
        $frase = $tom !== '' ? 'Falamos com ' . mb_strtolower($tom) . '. ' : '';
        $campanha = $contexto !== '' ? ' Dentro de: ' . mb_substr($contexto, 0, 160) . '.' : '';

        return $frase . 'Aqui na ' . $marca . ', ' . mb_strtolower($tema) . ' é parte da nossa rotina.' . $campanha
            . ' Conta pra gente o que você quer ver mais por aqui.';
    }

    private function hashtags(string $segmento, string $rede, string $marca): string
    {
        $tags = ['#socialmedia'];
        $slug = static function (string $texto): string {
            $limpo = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($texto)) ?? '';

            return $limpo === '' ? '' : '#' . $limpo;
        };
        $daMarca = $slug($marca);
        if ($daMarca !== '') {
            $tags[] = $daMarca;
        }
        $doSegmento = $slug($segmento);
        if ($doSegmento !== '') {
            $tags[] = $doSegmento;
        }
        $tags[] = '#' . mb_strtolower($rede);

        return implode(' ', array_slice($tags, 0, 8));
    }
}
