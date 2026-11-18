<?php

declare(strict_types=1);

namespace Smia\Reports;

use Smia\Database\Database;
use Smia\Planner\PlanGenerator;

/**
 * Relatório operacional: o que a agência **fez** no período, a partir dos
 * registros internos do produto.
 *
 * Três compromissos que valem mais que qualquer gráfico:
 *  - não existe métrica de rede aqui. Alcance, curtidas, impressões e cliques
 *    só viriam de API de rede social — se não veio, não aparece;
 *  - o recorte é por data de **registro** (quando entrou no produto), não por
 *    data editorial da pauta;
 *  - nada é estimado: cada número é um COUNT de linha real.
 */
final class ReportRepository
{
    public const PERIODO_MAXIMO = 366;

    public function __construct(private readonly Database $db)
    {
    }

    public function clientes(int $companyId): array
    {
        return $this->db->all(
            'SELECT id, company_name, trade_name FROM clients WHERE company_id = ? ORDER BY company_name COLLATE NOCASE',
            [$companyId]
        );
    }

    public function cliente(int $clientId, int $companyId): ?array
    {
        return $this->db->one('SELECT * FROM clients WHERE id = ? AND company_id = ?', [$clientId, $companyId]);
    }

    /**
     * Intervalo padrão: do primeiro dia do mês corrente até hoje (UTC).
     *
     * @return array{0:string,1:string}
     */
    public static function periodoPadrao(?string $hoje = null): array
    {
        $hoje = $hoje ?? gmdate('Y-m-d');

        return [gmdate('Y-m-01', (int) strtotime($hoje . ' UTC')), $hoje];
    }

    /**
     * Valida o recorte vindo da tela.
     *
     * @return array{inicio:string,fim:string}
     * @throws ReportError
     */
    public static function validarPeriodo(?string $inicio, ?string $fim): array
    {
        [$padraoInicio, $padraoFim] = self::periodoPadrao();
        $inicio = trim((string) $inicio) !== '' ? trim((string) $inicio) : $padraoInicio;
        $fim = trim((string) $fim) !== '' ? trim((string) $fim) : $padraoFim;

        foreach ([$inicio, $fim] as $valor) {
            $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor, new \DateTimeZone('UTC'));
            if ($data === false || $data->format('Y-m-d') !== $valor) {
                throw new ReportError('Escolha datas válidas para o período.');
            }
        }
        if ($fim < $inicio) {
            throw new ReportError('A data final precisa ser igual ou posterior à data inicial.');
        }
        $dias = (int) (new \DateTimeImmutable($inicio, new \DateTimeZone('UTC')))
            ->diff(new \DateTimeImmutable($fim, new \DateTimeZone('UTC')))
            ->days;
        if ($dias > self::PERIODO_MAXIMO) {
            throw new ReportError('O período máximo do relatório é de 366 dias.');
        }

        return ['inicio' => $inicio, 'fim' => $fim];
    }

    /**
     * Números do período + quebra por rede e por status de aprovação.
     *
     * @return array<string,mixed>
     */
    public function resumo(int $companyId, string $inicio, string $fim, ?int $clientId): array
    {
        // O mesmo recorte vale para todas as consultas: empresa, período e,
        // quando escolhido, o cliente. O alias do filtro muda por tabela.
        $filtroCliente = $clientId !== null ? ' AND x.client_id = ?' : '';
        $parametros = static function () use ($companyId, $inicio, $fim, $clientId): array {
            $valores = [$companyId, $inicio, $fim];
            if ($clientId !== null) {
                $valores[] = $clientId;
            }

            return $valores;
        };

        $posts = $this->db->all(
            'SELECT p.id, p.client_id, p.theme, p.network, p.status, p.created_at,
                    COALESCE(NULLIF(c.trade_name, \'\'), c.company_name) AS cliente
             FROM posts p
             JOIN clients c ON c.id = p.client_id AND c.company_id = p.company_id
             WHERE p.company_id = ? AND date(p.created_at) BETWEEN ? AND ?' . str_replace('x.', 'p.', $filtroCliente) . '
             ORDER BY p.created_at DESC, p.id DESC',
            $parametros()
        );

        $artes = $this->db->all(
            'SELECT cp.id, cp.client_id, cp.subject, cp.status, cp.export_path, cp.created_at,
                    COALESCE(NULLIF(c.trade_name, \'\'), c.company_name) AS cliente
             FROM creative_posts cp
             JOIN clients c ON c.id = cp.client_id AND c.company_id = cp.company_id
             WHERE cp.company_id = ? AND date(cp.created_at) BETWEEN ? AND ?' . str_replace('x.', 'cp.', $filtroCliente) . '
             ORDER BY cp.created_at DESC, cp.id DESC',
            $parametros()
        );

        $aprovacoes = $this->db->all(
            'SELECT a.id, a.client_id, a.title, a.source_type, a.snapshot_json, a.status, a.submitted_at,
                    COALESCE(NULLIF(c.trade_name, \'\'), c.company_name) AS cliente
             FROM approvals a
             JOIN clients c ON c.id = a.client_id AND c.company_id = a.company_id
             WHERE a.company_id = ? AND date(a.submitted_at) BETWEEN ? AND ?' . str_replace('x.', 'a.', $filtroCliente) . '
             ORDER BY a.submitted_at DESC, a.id DESC',
            $parametros()
        );

        // Decisões tomadas no período (podem ser de revisões abertas antes dele).
        // O evento não guarda client_id: quem sabe de quem é a revisão é approvals.
        $eventos = $this->db->all(
            "SELECT e.new_status, COUNT(*) AS total
             FROM approval_events e
             JOIN approvals a ON a.id = e.approval_id AND a.company_id = e.company_id
             WHERE e.company_id = ? AND date(e.created_at) BETWEEN ? AND ?
               AND e.new_status IN ('approved', 'changes_requested', 'rejected')" . str_replace('x.', 'a.', $filtroCliente) . '
             GROUP BY e.new_status',
            $parametros()
        );

        $decisoes = ['approved' => 0, 'changes_requested' => 0, 'rejected' => 0];
        foreach ($eventos as $evento) {
            $decisoes[(string) $evento['new_status']] = (int) $evento['total'];
        }

        $status = ['pending' => 0, 'approved' => 0, 'changes_requested' => 0, 'rejected' => 0];
        foreach ($aprovacoes as $aprovacao) {
            $status[(string) $aprovacao['status']] = ($status[(string) $aprovacao['status']] ?? 0) + 1;
        }

        $exportadas = count(array_filter($artes, static fn (array $arte): bool => !empty($arte['export_path'])));

        $porRede = [];
        foreach ($posts as $post) {
            $rede = (string) $post['network'];
            $porRede[$rede] = ($porRede[$rede] ?? 0) + 1;
        }
        $linhasRede = [];
        foreach (PlanGenerator::REDES as $rede) {
            if (($porRede[$rede] ?? 0) > 0) {
                $linhasRede[] = ['network' => $rede, 'posts' => $porRede[$rede]];
            }
        }
        foreach ($porRede as $rede => $total) {
            if (!in_array($rede, PlanGenerator::REDES, true)) {
                $linhasRede[] = ['network' => $rede, 'posts' => $total];
            }
        }

        $atividade = [];
        foreach ($posts as $post) {
            $atividade[] = [
                'date' => (string) $post['created_at'],
                'client' => (string) $post['cliente'],
                'category' => 'Planejamento',
                'title' => (string) $post['theme'],
                'network' => (string) $post['network'],
                'status' => $post['status'] === 'in_production' ? 'Em produção' : 'Planejada',
            ];
        }
        foreach ($artes as $arte) {
            $atividade[] = [
                'date' => (string) $arte['created_at'],
                'client' => (string) $arte['cliente'],
                'category' => 'Arte',
                'title' => (string) $arte['subject'],
                'network' => '—',
                'status' => !empty($arte['export_path']) ? 'Exportada' : 'Rascunho',
            ];
        }
        $rotulos = [
            'pending' => 'Aguardando cliente',
            'approved' => 'Aprovado',
            'changes_requested' => 'Alterações solicitadas',
            'rejected' => 'Rejeitado',
        ];
        foreach ($aprovacoes as $aprovacao) {
            $fotografia = json_decode((string) $aprovacao['snapshot_json'], true);
            $atividade[] = [
                'date' => (string) $aprovacao['submitted_at'],
                'client' => (string) $aprovacao['cliente'],
                'category' => 'Aprovação',
                'title' => (string) $aprovacao['title'],
                'network' => is_array($fotografia) && isset($fotografia['network']) ? (string) $fotografia['network'] : '—',
                'status' => $rotulos[(string) $aprovacao['status']] ?? (string) $aprovacao['status'],
            ];
        }

        // Mais recente primeiro; o id desempata registros do mesmo segundo.
        usort($atividade, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
        $totalAtividade = count($atividade);

        return [
            'metrics' => [
                'planned_posts' => count($posts),
                'creatives' => count($artes),
                'exported_creatives' => $exportadas,
                'approvals' => count($aprovacoes),
                'approval_pending' => $status['pending'],
                'approval_approved' => $status['approved'],
                'approval_changes' => $status['changes_requested'],
                'approval_rejected' => $status['rejected'],
                'approved_events' => $decisoes['approved'],
                'changes_events' => $decisoes['changes_requested'],
                'rejected_events' => $decisoes['rejected'],
            ],
            'network_rows' => $linhasRede,
            'activity' => array_slice($atividade, 0, 300),
            'activity_count' => $totalAtividade,
            'truncated' => $totalAtividade > 300,
        ];
    }

    /** Consumo de IA no período (tokens), por recurso — dado real, não estimado. */
    public function consumoIa(int $companyId, string $inicio, string $fim, ?int $clientId): array
    {
        $sql = "SELECT feature, model, COUNT(*) AS chamadas,
                       COALESCE(SUM(input_tokens), 0) AS entrada,
                       COALESCE(SUM(output_tokens), 0) AS saida
                FROM ai_generations
                WHERE company_id = ? AND date(created_at) BETWEEN ? AND ?";
        $parametros = [$companyId, $inicio, $fim];
        if ($clientId !== null) {
            $sql .= ' AND client_id = ?';
            $parametros[] = $clientId;
        }
        $sql .= ' GROUP BY feature, model ORDER BY entrada + saida DESC';

        return $this->db->all($sql, $parametros);
    }

    public function totalFeed(int $companyId, ?int $clientId): int
    {
        $sql = 'SELECT COUNT(*) FROM feed_items WHERE company_id = ?';
        $parametros = [$companyId];
        if ($clientId !== null) {
            $sql .= ' AND client_id = ?';
            $parametros[] = $clientId;
        }

        return (int) ($this->db->scalar($sql, $parametros) ?? 0);
    }
}
