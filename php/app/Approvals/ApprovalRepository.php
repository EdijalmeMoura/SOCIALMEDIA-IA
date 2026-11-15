<?php

declare(strict_types=1);

namespace Smia\Approvals;

use Smia\Database\Database;
use Smia\Studio\Layout;

/**
 * Aprovações: envio para revisão, retorno do cliente e histórico.
 *
 * Duas regras sustentam o módulo:
 *  - o que o cliente vê é uma FOTOGRAFIA do conteúdo no momento do envio
 *    (snapshot_json). Editar a arte depois não muda o que foi aprovado;
 *  - só existe uma pendência por conteúdo. O índice único garante isso mesmo
 *    com dois cliques ao mesmo tempo, então o código não depende de sorte.
 */
final class ApprovalRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public const STATUS = [
        'pending' => 'Aguardando',
        'approved' => 'Aprovado',
        'changes_requested' => 'Alterações solicitadas',
        'rejected' => 'Rejeitado',
    ];

    /** Clientes com conteúdo disponível para revisão. */
    public function clientes(int $companyId, bool $apenasAtivos = false): array
    {
        $filtro = $apenasAtivos ? " AND status = 'active'" : '';

        return $this->db->all(
            "SELECT id, company_name, trade_name, status FROM clients WHERE company_id = ?{$filtro} ORDER BY company_name COLLATE NOCASE",
            [$companyId]
        );
    }

    public function cliente(int $clientId, int $companyId): ?array
    {
        return $this->db->one('SELECT * FROM clients WHERE id = ? AND company_id = ?', [$clientId, $companyId]);
    }

    /** Artes exportadas e ainda sem pendência (prontas para enviar). */
    public function artesDisponiveis(int $clientId, int $companyId): array
    {
        return $this->db->all(
            "SELECT cp.id, cp.subject, cp.format_key, cp.updated_at
             FROM creative_posts cp
             WHERE cp.company_id = ? AND cp.client_id = ?
               AND cp.status = 'exported' AND cp.export_path IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM approvals a
                   WHERE a.company_id = cp.company_id AND a.client_id = cp.client_id
                     AND a.creative_id = cp.id AND a.status = 'pending'
               )
             ORDER BY cp.updated_at DESC, cp.id DESC",
            [$companyId, $clientId]
        );
    }

    /** Pautas planejadas e ainda sem pendência. */
    public function postsDisponiveis(int $clientId, int $companyId): array
    {
        return $this->db->all(
            "SELECT p.id, p.theme, p.network, p.publication_date
             FROM posts p
             WHERE p.company_id = ? AND p.client_id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM approvals a
                   WHERE a.company_id = p.company_id AND a.client_id = p.client_id
                     AND a.planned_post_id = p.id AND a.status = 'pending'
               )
             ORDER BY p.publication_date, p.position, p.id",
            [$companyId, $clientId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function lista(int $companyId, int $limite = 100, ?string $status = null): array
    {
        $filtro = '';
        $parametros = [$companyId];
        if ($status !== null && isset(self::STATUS[$status])) {
            $filtro = ' AND a.status = ?';
            $parametros[] = $status;
        }
        $parametros[] = $limite;

        return $this->db->all(
            "SELECT a.*, c.company_name, c.trade_name,
                    (SELECT COUNT(*) FROM comments cm WHERE cm.approval_id = a.id) AS comment_count
             FROM approvals a
             JOIN clients c ON c.id = a.client_id AND c.company_id = a.company_id
             WHERE a.company_id = ?{$filtro}
             ORDER BY CASE a.status WHEN 'pending' THEN 0 ELSE 1 END, a.submitted_at DESC, a.id DESC
             LIMIT ?",
            $parametros
        );
    }

    /** Lista vista pelo cliente (só o cliente dele). */
    public function listaDoPortal(int $companyId, int $clientId, int $limite = 60): array
    {
        return $this->db->all(
            "SELECT a.*, (SELECT COUNT(*) FROM comments cm WHERE cm.approval_id = a.id) AS comment_count
             FROM approvals a
             WHERE a.company_id = ? AND a.client_id = ?
             ORDER BY CASE a.status WHEN 'pending' THEN 0 ELSE 1 END, a.submitted_at DESC, a.id DESC
             LIMIT ?",
            [$companyId, $clientId, $limite]
        );
    }

    public function contagemDoPortal(int $companyId, int $clientId): array
    {
        $linhas = $this->db->all(
            'SELECT status, COUNT(*) AS total FROM approvals WHERE company_id = ? AND client_id = ? GROUP BY status',
            [$companyId, $clientId]
        );
        $contagem = ['pending' => 0, 'approved' => 0, 'changes_requested' => 0, 'rejected' => 0];
        foreach ($linhas as $linha) {
            $contagem[(string) $linha['status']] = (int) $linha['total'];
        }
        $contagem['total'] = array_sum($contagem);

        return $contagem;
    }

    public function aprovacao(int $id, int $companyId): ?array
    {
        return $this->db->one(
            'SELECT a.*, c.company_name, c.trade_name, c.segment
             FROM approvals a
             JOIN clients c ON c.id = a.client_id AND c.company_id = a.company_id
             WHERE a.id = ? AND a.company_id = ?',
            [$id, $companyId]
        );
    }

    /** Versão do portal: precisa ser do cliente daquele acesso. */
    public function aprovacaoDoPortal(int $id, int $companyId, int $clientId): ?array
    {
        return $this->db->one(
            'SELECT a.*, c.company_name, c.trade_name, c.segment
             FROM approvals a
             JOIN clients c ON c.id = a.client_id AND c.company_id = a.company_id
             WHERE a.id = ? AND a.company_id = ? AND a.client_id = ?',
            [$id, $companyId, $clientId]
        );
    }

    public function fonte(string $tipo, int $sourceId, int $clientId, int $companyId): ?array
    {
        if ($tipo === 'creative') {
            return $this->db->one(
                "SELECT * FROM creative_posts
                 WHERE id = ? AND company_id = ? AND client_id = ?
                   AND status = 'exported' AND export_path IS NOT NULL",
                [$sourceId, $companyId, $clientId]
            );
        }
        if ($tipo === 'planned') {
            return $this->db->one(
                'SELECT p.*, cc.title AS plan_title
                 FROM posts p
                 JOIN content_calendar cc ON cc.id = p.calendar_id AND cc.company_id = p.company_id
                 WHERE p.id = ? AND p.company_id = ? AND p.client_id = ?',
                [$sourceId, $companyId, $clientId]
            );
        }

        return null;
    }

    /**
     * Fotografia do conteúdo enviado para revisão.
     *
     * @param array<string,mixed> $fonte
     * @return array<string,mixed>
     */
    public static function snapshot(string $tipo, array $fonte): array
    {
        if ($tipo === 'creative') {
            $conteudo = json_decode((string) ($fonte['content_json'] ?? ''), true);

            return [
                'type' => 'creative',
                'title' => (string) $fonte['subject'],
                'format' => Layout::rotulo((string) $fonte['format_key']),
                'width' => (int) $fonte['canvas_width'],
                'height' => (int) $fonte['canvas_height'],
                'content' => is_array($conteudo) ? $conteudo : [],
                'background_color' => (string) $fonte['background_color'],
                'image_path' => $fonte['image_path'] ?? null,
                'export_path' => $fonte['export_path'] ?? null,
            ];
        }

        return [
            'type' => 'planned',
            'title' => (string) $fonte['theme'],
            'network' => (string) $fonte['network'],
            'publication_date' => (string) $fonte['publication_date'],
            'content_type' => (string) $fonte['content_type'],
            'strategic_objective' => (string) $fonte['strategic_objective'],
            'main_text' => (string) $fonte['main_text'],
            'caption' => (string) $fonte['caption'],
            'hashtags' => (string) ($fonte['hashtags'] ?? ''),
            'cta' => (string) ($fonte['cta'] ?? ''),
            'visual_brief' => (string) $fonte['visual_brief'],
            'image_suggestion' => (string) $fonte['image_suggestion'],
            'plan_title' => (string) ($fonte['plan_title'] ?? ''),
        ];
    }

    /** Já existe pendência para este conteúdo? */
    public function pendentePara(string $tipo, int $sourceId, int $clientId, int $companyId): bool
    {
        $coluna = $tipo === 'creative' ? 'creative_id' : 'planned_post_id';
        $achado = $this->db->scalar(
            "SELECT 1 FROM approvals WHERE company_id = ? AND client_id = ? AND {$coluna} = ? AND status = 'pending' LIMIT 1",
            [$companyId, $clientId, $sourceId]
        );

        return $achado !== null && $achado !== false;
    }

    /**
     * Cria o pedido de aprovação (ciclo novo) e registra o evento de envio.
     *
     * @param array<string,mixed> $snapshot
     * @throws ApprovalError quando o conteúdo já está em revisão
     */
    public function enviar(
        int $companyId,
        int $clientId,
        int $userId,
        string $tipo,
        int $sourceId,
        array $snapshot,
        string $nota = '',
    ): int {
        $coluna = $tipo === 'creative' ? 'creative_id' : 'planned_post_id';

        return (int) $this->db->transaction(function () use ($companyId, $clientId, $userId, $tipo, $sourceId, $snapshot, $coluna, $nota): int {
            $ciclo = (int) ($this->db->scalar(
                "SELECT COALESCE(MAX(cycle_number), 0) + 1 FROM approvals WHERE company_id = ? AND client_id = ? AND {$coluna} = ?",
                [$companyId, $clientId, $sourceId]
            ) ?? 1);

            try {
                $this->db->run(
                    "INSERT INTO approvals (company_id, client_id, created_by, source_type, {$coluna}, cycle_number, title, snapshot_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $companyId, $clientId, $userId, $tipo, $sourceId, $ciclo,
                        mb_substr((string) $snapshot['title'], 0, 200),
                        json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]
                );
            } catch (\PDOException $erro) {
                // Índice único de pendência: outra aba enviou primeiro.
                if (str_contains($erro->getMessage(), 'UNIQUE')) {
                    throw new ApprovalError('Este conteúdo já está aguardando aprovação.');
                }
                throw $erro;
            }

            $id = (int) $this->db->pdo()->lastInsertId();
            $this->db->run(
                "INSERT INTO approval_events (approval_id, company_id, actor_type, actor_user_id, previous_status, new_status, note)
                 VALUES (?, ?, 'agency', ?, NULL, 'pending', 'Enviado para aprovação')",
                [$id, $companyId, $userId]
            );
            if (trim($nota) !== '') {
                $this->comentar($id, $companyId, 'agency', $userId, null, trim($nota));
            }

            return $id;
        });
    }

    /**
     * Retorno do cliente. Só vale se ainda estiver pendente — a checagem está
     * no próprio UPDATE, então duas respostas simultâneas não passam as duas.
     */
    public function responder(
        int $id,
        int $companyId,
        int $clientId,
        int $portalUserId,
        string $status,
        string $nota,
        ?string $anexo = null,
        ?string $anexoNome = null,
    ): void {
        if (!in_array($status, ['approved', 'changes_requested', 'rejected'], true)) {
            throw new ApprovalError('Escolha uma decisão válida.');
        }
        // Pedir alteração sem dizer o quê só gera retrabalho: exige texto ou
        // uma referência anexada.
        if ($status === 'changes_requested' && trim($nota) === '' && ($anexo === null || $anexo === '')) {
            throw new ApprovalError('Descreva as alterações necessárias ou anexe uma referência.');
        }

        $this->db->transaction(function () use ($id, $companyId, $clientId, $portalUserId, $status, $nota, $anexo, $anexoNome): void {
            $atualizado = $this->db->run(
                "UPDATE approvals
                 SET status = ?, resolved_at = datetime('now'), updated_at = datetime('now')
                 WHERE id = ? AND company_id = ? AND client_id = ? AND status = 'pending'",
                [$status, $id, $companyId, $clientId]
            );
            if ($atualizado->rowCount() !== 1) {
                throw new ApprovalConflict('Esta revisão já foi respondida. Atualize a página.');
            }
            $this->db->run(
                "INSERT INTO approval_events (approval_id, company_id, actor_type, actor_portal_user_id, previous_status, new_status, note)
                 VALUES (?, ?, 'client', ?, 'pending', ?, ?)",
                [$id, $companyId, $portalUserId, $status, mb_substr(trim($nota), 0, 1000)]
            );
            if (trim($nota) !== '' || $anexo !== null) {
                $this->comentar($id, $companyId, 'client', null, $portalUserId, trim($nota), $anexo, $anexoNome);
            }
        });
    }

    /** Cancelar o pedido (agência desiste da revisão), liberando o conteúdo. */
    public function cancelar(int $id, int $companyId, int $userId): void
    {
        $this->db->transaction(function () use ($id, $companyId, $userId): void {
            $atualizado = $this->db->run(
                "UPDATE approvals SET status = 'rejected', resolved_at = datetime('now'), updated_at = datetime('now')
                 WHERE id = ? AND company_id = ? AND status = 'pending'",
                [$id, $companyId]
            );
            if ($atualizado->rowCount() !== 1) {
                throw new ApprovalConflict('Esta revisão não está mais pendente.');
            }
            $this->db->run(
                "INSERT INTO approval_events (approval_id, company_id, actor_type, actor_user_id, previous_status, new_status, note)
                 VALUES (?, ?, 'agency', ?, 'pending', 'rejected', 'Revisão cancelada pela agência')",
                [$id, $companyId, $userId]
            );
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function comentarios(int $approvalId, int $companyId): array
    {
        return $this->db->all(
            'SELECT cm.*, u.name AS agency_name, pu.name AS client_name
             FROM comments cm
             LEFT JOIN users u ON u.id = cm.author_user_id
             LEFT JOIN client_portal_users pu ON pu.id = cm.author_portal_user_id
             WHERE cm.approval_id = ? AND cm.company_id = ?
             ORDER BY cm.id',
            [$approvalId, $companyId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function eventos(int $approvalId, int $companyId): array
    {
        return $this->db->all(
            'SELECT e.*, u.name AS agency_name, pu.name AS client_name
             FROM approval_events e
             LEFT JOIN users u ON u.id = e.actor_user_id
             LEFT JOIN client_portal_users pu ON pu.id = e.actor_portal_user_id
             WHERE e.approval_id = ? AND e.company_id = ?
             ORDER BY e.id',
            [$approvalId, $companyId]
        );
    }

    public function comentar(int $approvalId, int $companyId, string $autorTipo, ?int $userId, ?int $portalUserId, string $corpo, ?string $anexo = null, ?string $anexoNome = null): void
    {
        $this->db->run(
            'INSERT INTO comments (approval_id, company_id, author_type, author_user_id, author_portal_user_id, body, attachment_path, attachment_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$approvalId, $companyId, $autorTipo, $userId, $portalUserId, mb_substr($corpo, 0, 3000), $anexo, $anexoNome]
        );
    }

    /** O arquivo é mídia desta revisão ou anexo de um comentário dela? */
    public function arquivoDaRevisao(int $approvalId, int $companyId, string $nome): bool
    {
        $aprovacao = $this->aprovacao($approvalId, $companyId);
        if ($aprovacao === null) {
            return false;
        }
        $snapshot = json_decode((string) $aprovacao['snapshot_json'], true) ?: [];
        $permitidos = array_filter([
            (string) ($snapshot['export_path'] ?? ''),
            (string) ($snapshot['image_path'] ?? ''),
        ]);
        if (in_array($nome, $permitidos, true)) {
            return true;
        }
        $anexo = $this->db->scalar(
            'SELECT 1 FROM comments WHERE approval_id = ? AND company_id = ? AND attachment_path = ? LIMIT 1',
            [$approvalId, $companyId, $nome]
        );

        return $anexo !== null && $anexo !== false;
    }

    public function contagem(int $companyId): array
    {
        $linhas = $this->db->all(
            'SELECT status, COUNT(*) AS total FROM approvals WHERE company_id = ? GROUP BY status',
            [$companyId]
        );
        $contagem = ['pending' => 0, 'approved' => 0, 'changes_requested' => 0, 'rejected' => 0];
        foreach ($linhas as $linha) {
            $contagem[(string) $linha['status']] = (int) $linha['total'];
        }
        $contagem['total'] = array_sum($contagem);

        return $contagem;
    }

    public function auditoria(int $companyId, int $userId, string $acao, string $detalhe): void
    {
        $this->db->run(
            'INSERT INTO audit_log (company_id, user_id, action, detail) VALUES (?, ?, ?, ?)',
            [$companyId, $userId, $acao, mb_substr($detalhe, 0, 500)]
        );
    }
}
