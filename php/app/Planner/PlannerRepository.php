<?php

declare(strict_types=1);

namespace Smia\Planner;

use Smia\Database\Database;

/**
 * Dados do planejamento editorial (calendários e publicações).
 *
 * Mesmas regras do produto Python: toda consulta filtra por company_id e a
 * gravação de um plano com seus posts acontece numa transação única — ou entra
 * tudo, ou não entra nada.
 */
final class PlannerRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Clientes ativos, já com a identidade visual (a IA usa isso). */
    public function clientesAtivos(int $companyId): array
    {
        return $this->db->all(
            "SELECT c.*, b.primary_color, b.secondary_color, b.accent_color, b.logo_path,
                    b.typography, b.visual_style, b.communication_tone, b.slogan,
                    b.graphic_elements, b.brand_manual
             FROM clients c
             LEFT JOIN client_branding b ON b.client_id = c.id
             WHERE c.company_id = ? AND c.status = 'active'
             ORDER BY c.company_name COLLATE NOCASE",
            [$companyId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function planos(int $companyId, int $limite = 60): array
    {
        return $this->db->all(
            'SELECT cc.*, c.company_name, c.trade_name,
                    (SELECT COUNT(*) FROM posts p WHERE p.calendar_id = cc.id) AS post_count
             FROM content_calendar cc
             JOIN clients c ON c.id = cc.client_id AND c.company_id = cc.company_id
             WHERE cc.company_id = ?
             ORDER BY cc.created_at DESC, cc.id DESC
             LIMIT ?',
            [$companyId, $limite]
        );
    }

    public function plano(int $id, int $companyId): ?array
    {
        $plano = $this->db->one(
            'SELECT cc.*, c.company_name, c.trade_name, c.segment,
                    (SELECT COUNT(*) FROM posts p WHERE p.calendar_id = cc.id) AS post_count
             FROM content_calendar cc
             JOIN clients c ON c.id = cc.client_id AND c.company_id = cc.company_id
             WHERE cc.id = ? AND cc.company_id = ?',
            [$id, $companyId]
        );
        if ($plano === null) {
            return null;
        }
        $plano['networks'] = self::redes($plano['networks_json'] ?? '');

        return $plano;
    }

    /** @return array<int,array<string,mixed>> */
    public function posts(int $calendarId, int $companyId): array
    {
        return $this->db->all(
            'SELECT * FROM posts WHERE calendar_id = ? AND company_id = ? ORDER BY publication_date, position, id',
            [$calendarId, $companyId]
        );
    }

    public function post(int $id, int $companyId): ?array
    {
        return $this->db->one('SELECT * FROM posts WHERE id = ? AND company_id = ?', [$id, $companyId]);
    }

    /**
     * Publicação com a arte que foi criada a partir dela (se houver).
     *
     * @return array<string,mixed>|null
     */
    public function postComArte(int $id, int $companyId): ?array
    {
        return $this->db->one(
            'SELECT p.*, cp.subject AS arte_assunto, cp.status AS arte_status,
                    cp.export_path AS arte_export_path, cp.format_key AS arte_formato
             FROM posts p
             LEFT JOIN creative_posts cp ON cp.id = p.creative_id AND cp.company_id = p.company_id
             WHERE p.id = ? AND p.company_id = ?',
            [$id, $companyId]
        );
    }

    /**
     * Liga a publicação à arte.
     *
     * A checagem de dono é da própria arte (mesma empresa e mesmo cliente):
     * um id de outra agência não pode virar vínculo nem por acidente.
     */
    public function vincularArte(int $postId, int $creativeId, int $companyId): bool
    {
        $arte = $this->db->one(
            'SELECT cp.id FROM creative_posts cp
             JOIN posts p ON p.id = ? AND p.company_id = cp.company_id AND p.client_id = cp.client_id
             WHERE cp.id = ? AND cp.company_id = ?',
            [$postId, $creativeId, $companyId]
        );
        if ($arte === null) {
            return false;
        }
        $this->db->run(
            "UPDATE posts SET creative_id = ?, updated_at = datetime('now') WHERE id = ? AND company_id = ?",
            [$creativeId, $postId, $companyId]
        );

        return true;
    }

    /**
     * Grava plano + publicações + registro de consumo numa transação.
     *
     * @param list<array<string,string>> $posts
     * @return int id do calendário
     */
    public function criar(
        int $companyId,
        int $clientId,
        int $userId,
        array $spec,
        array $posts,
        string $model,
        int $inputTokens = 0,
        int $outputTokens = 0,
        string $feature = 'weekly_planning',
    ): int {
        return $this->db->transaction(function (Database $db) use ($companyId, $clientId, $userId, $spec, $posts, $model, $inputTokens, $outputTokens, $feature): int {
            $db->run(
                'INSERT INTO content_calendar
                    (company_id, client_id, created_by, title, period_days, start_date, objective,
                     campaign_context, networks_json, target_posts, ai_model)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $companyId, $clientId, $userId, $spec['title'], $spec['period_days'], $spec['start_date'],
                    $spec['objective'], $spec['campaign_context'], json_encode(array_values($spec['networks']), JSON_UNESCAPED_UNICODE),
                    count($posts), $model,
                ]
            );
            $calendarId = (int) $db->pdo()->lastInsertId();

            $posicao = 0;
            foreach ($posts as $post) {
                $posicao++;
                $db->run(
                    'INSERT INTO posts
                        (company_id, client_id, calendar_id, position, publication_date, network, theme,
                         strategic_objective, content_type, main_text, caption, hashtags, cta,
                         visual_brief, image_suggestion)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $companyId, $clientId, $calendarId, $posicao, $post['publication_date'], $post['network'],
                        $post['theme'], $post['strategic_objective'], $post['content_type'], $post['main_text'],
                        $post['caption'], $post['hashtags'], $post['cta'], $post['visual_brief'], $post['image_suggestion'],
                    ]
                );
            }

            $this->registrarUso($db, $companyId, $clientId, $userId, $feature, $model, $inputTokens, $outputTokens);

            return $calendarId;
        });
    }

    /** Atualiza uma publicação (só se pertencer à empresa). */
    public function atualizarPost(int $postId, int $companyId, array $dados): void
    {
        $this->db->run(
            'UPDATE posts SET publication_date = ?, network = ?, theme = ?, strategic_objective = ?,
                    content_type = ?, main_text = ?, caption = ?, hashtags = ?, cta = ?,
                    visual_brief = ?, image_suggestion = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND company_id = ?',
            [
                $dados['publication_date'], $dados['network'], $dados['theme'], $dados['strategic_objective'],
                $dados['content_type'], $dados['main_text'], $dados['caption'], $dados['hashtags'],
                $dados['cta'], $dados['visual_brief'], $dados['image_suggestion'], $postId, $companyId,
            ]
        );
    }

    /** Remove a publicação e ajusta o alvo do plano (como no Python). */
    public function removerPost(int $postId, int $calendarId, int $companyId): void
    {
        $this->db->transaction(function (Database $db) use ($postId, $calendarId, $companyId): void {
            $db->run('DELETE FROM posts WHERE id = ? AND company_id = ?', [$postId, $companyId]);
            $db->run(
                'UPDATE content_calendar SET target_posts = MAX(0, target_posts - 1), updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND company_id = ?',
                [$calendarId, $companyId]
            );
        });
    }

    /** Duplica uma publicação no fim do calendário. */
    public function duplicarPost(int $postId, int $companyId): ?int
    {
        $post = $this->post($postId, $companyId);
        if ($post === null) {
            return null;
        }
        $calendarId = (int) $post['calendar_id'];

        return $this->db->transaction(function (Database $db) use ($post, $calendarId, $companyId): int {
            $proxima = (int) ($db->scalar('SELECT COALESCE(MAX(position), 0) + 1 FROM posts WHERE calendar_id = ?', [$calendarId]) ?? 1);
            $db->run(
                'INSERT INTO posts
                    (company_id, client_id, calendar_id, position, publication_date, network, theme,
                     strategic_objective, content_type, main_text, caption, hashtags, cta,
                     visual_brief, image_suggestion, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $companyId, $post['client_id'], $calendarId, $proxima, $post['publication_date'], $post['network'],
                    $post['theme'], $post['strategic_objective'], $post['content_type'], $post['main_text'],
                    $post['caption'], $post['hashtags'], $post['cta'], $post['visual_brief'],
                    $post['image_suggestion'], $post['status'],
                ]
            );
            $novoId = (int) $db->pdo()->lastInsertId();
            $db->run(
                'UPDATE content_calendar SET target_posts = target_posts + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$calendarId]
            );

            return $novoId;
        });
    }

    /** Marca plano e publicações como "em produção". */
    public function enviarParaProducao(int $calendarId, int $companyId): void
    {
        $this->db->transaction(function (Database $db) use ($calendarId, $companyId): void {
            $db->run(
                "UPDATE content_calendar SET status = 'in_production', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND company_id = ?",
                [$calendarId, $companyId]
            );
            $db->run(
                "UPDATE posts SET status = 'in_production', updated_at = CURRENT_TIMESTAMP WHERE calendar_id = ? AND company_id = ?",
                [$calendarId, $companyId]
            );
        });
    }

    public function removerPlano(int $calendarId, int $companyId): void
    {
        $this->db->run('DELETE FROM content_calendar WHERE id = ? AND company_id = ?', [$calendarId, $companyId]);
    }

    public function contarPlanos(int $companyId): int
    {
        return (int) ($this->db->scalar('SELECT COUNT(*) FROM content_calendar WHERE company_id = ?', [$companyId]) ?? 0);
    }

    public function contarEmProducao(int $companyId): int
    {
        return (int) ($this->db->scalar(
            "SELECT COUNT(*) FROM content_calendar WHERE company_id = ? AND status = 'in_production'",
            [$companyId]
        ) ?? 0);
    }

    /** Consumo de IA do mês corrente (transparência de custo). */
    public function consumoIa(int $companyId): array
    {
        $linha = $this->db->one(
            "SELECT COUNT(*) AS geracoes, COALESCE(SUM(input_tokens), 0) AS entrada, COALESCE(SUM(output_tokens), 0) AS saida
             FROM ai_generations
             WHERE company_id = ? AND strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')",
            [$companyId]
        );

        return [
            'geracoes' => (int) ($linha['geracoes'] ?? 0),
            'entrada' => (int) ($linha['entrada'] ?? 0),
            'saida' => (int) ($linha['saida'] ?? 0),
        ];
    }

    public function auditoria(int $companyId, int $userId, string $action, string $detail): void
    {
        $this->db->run(
            'INSERT INTO audit_log (company_id, user_id, action, detail) VALUES (?, ?, ?, ?)',
            [$companyId, $userId, $action, mb_substr($detail, 0, 500)]
        );
    }

    /** @return list<string> */
    public static function redes(?string $json): array
    {
        $valor = json_decode((string) $json, true);
        if (!is_array($valor)) {
            return [];
        }

        return array_values(array_filter($valor, static fn ($r): bool => is_string($r) && in_array($r, PlanGenerator::REDES, true)));
    }

    /** Semanas do calendário para a visão de grade. */
    public static function semanas(array $plano, array $posts): array
    {
        $porData = [];
        foreach ($posts as $post) {
            $porData[(string) $post['publication_date']][] = $post;
        }

        $inicio = new \DateTimeImmutable((string) $plano['start_date']);
        $dias = [];
        for ($i = 0; $i < (int) $plano['period_days']; $i++) {
            $dia = $inicio->modify('+' . $i . ' days');
            $chave = $dia->format('Y-m-d');
            $dias[] = [
                'date' => $chave,
                'label' => $dia->format('d'),
                'mes' => $dia->format('m'),
                'weekday' => ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'][(int) $dia->format('N') - 1],
                'posts' => $porData[$chave] ?? [],
            ];
        }

        return array_chunk($dias, 7);
    }

    private function registrarUso(Database $db, int $companyId, int $clientId, int $userId, string $feature, string $model, int $entrada, int $saida): void
    {
        $db->run(
            'INSERT INTO ai_generations (company_id, client_id, user_id, feature, model, input_tokens, output_tokens)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$companyId, $clientId, $userId, $feature, $model, $entrada, $saida]
        );
    }
}
