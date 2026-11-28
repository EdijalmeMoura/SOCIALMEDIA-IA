<?php

declare(strict_types=1);

namespace Smia\Database;

/**
 * Números do dia a dia da agência para a visão geral.
 *
 * O dashboard mostrava a ficha técnica da instância (PHP, data de instalação,
 * módulos prontos) e nenhum trabalho: com seis posts planejados, duas
 * aprovações e um horário na agenda, os cartões diziam "0". Aqui ficam as
 * consultas que respondem "o que precisa da minha atenção hoje?".
 *
 * Tudo é recortado por empresa e por janelas de data explícitas — nada de
 * `now()` no SQL, para o teste conseguir fixar o dia.
 */
final class DashboardRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{posts_futuros:int, artes_em_rascunho:int, aprovacoes_pendentes:int,
     *   aprovacoes_paradas:int, aprovadas_sem_agenda:int, agenda_ativa:int,
     *   agenda_atrasada:int, publicadas:int}
     */
    public function numeros(int $companyId, string $hoje): array
    {
        $clientesAtivos = 'SELECT id FROM clients WHERE company_id = ? AND status = \'active\'';

        return [
            'posts_futuros' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM posts WHERE company_id = ? AND publication_date >= ?",
                [$companyId, $hoje]
            ) ?? 0),
            'artes_em_rascunho' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM creative_posts WHERE company_id = ? AND status = 'draft'",
                [$companyId]
            ) ?? 0),
            'aprovacoes_pendentes' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM approvals WHERE company_id = ? AND status IN ('pending','changes_requested')",
                [$companyId]
            ) ?? 0),
            // Pendente há mais de 3 dias: o cliente provavelmente precisa de um
            // empurrão. É o número que faz diferença na segunda-feira.
            'aprovacoes_paradas' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM approvals
                 WHERE company_id = ? AND status = 'pending'
                   AND datetime(submitted_at) < datetime('now', '-3 days')",
                [$companyId]
            ) ?? 0),
            // Aprovado pelo cliente e ainda sem nenhum horário na agenda: é
            // trabalho parado no meio do caminho, e a agenda vazia não dizia
            // isso. Conta aprovações (não tentativas) sem horário nenhum.
            'aprovadas_sem_agenda' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM approvals a
                 WHERE a.company_id = ? AND a.status = 'approved'
                   AND NOT EXISTS (
                     SELECT 1 FROM scheduled_publications sp
                     WHERE sp.approval_id = a.id AND sp.company_id = a.company_id
                   )",
                [$companyId]
            ) ?? 0),
            'agenda_ativa' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM scheduled_publications WHERE company_id = ? AND status = 'scheduled'",
                [$companyId]
            ) ?? 0),
            'agenda_atrasada' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM scheduled_publications
                 WHERE company_id = ? AND status = 'scheduled'
                   AND scheduled_at_utc <= strftime('%Y-%m-%dT%H:%M:%S', 'now')",
                [$companyId]
            ) ?? 0),
            'publicadas' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM scheduled_publications
                 WHERE company_id = ? AND EXISTS (
                    SELECT 1 FROM meta_publication_attempts ma
                    WHERE ma.schedule_id = scheduled_publications.id
                      AND ma.company_id = scheduled_publications.company_id AND ma.status = 'published'
                 )",
                [$companyId]
            ) ?? 0),
        ];
    }

    /**
     * Próximas publicações do calendário, com o nome da marca.
     *
     * @return array<int,array<string,mixed>>
     */
    public function proximasPublicacoes(int $companyId, string $hoje, int $limite = 5): array
    {
        return $this->db->all(
            "SELECT p.id, p.publication_date, p.network, p.theme, p.status, p.creative_id,
                    COALESCE(NULLIF(c.trade_name, ''), c.company_name) AS cliente
             FROM posts p
             JOIN clients c ON c.id = p.client_id AND c.company_id = p.company_id
             WHERE p.company_id = ? AND p.publication_date >= ?
             ORDER BY p.publication_date ASC, p.id ASC
             LIMIT ?",
            [$companyId, $hoje, $limite]
        );
    }

    /**
     * Revisões esperando o cliente (as mais antigas primeiro: são as que
     * travam a fila).
     *
     * @return array<int,array<string,mixed>>
     */
    public function aprovacoesPendentes(int $companyId, int $limite = 4): array
    {
        return $this->db->all(
            "SELECT a.id, a.title, a.status, a.source_type, a.submitted_at,
                    c.id AS client_id,
                    COALESCE(NULLIF(c.trade_name, ''), c.company_name) AS cliente,
                    CAST(julianday('now') - julianday(a.submitted_at) AS INTEGER) AS dias
             FROM approvals a
             JOIN clients c ON c.id = a.client_id AND c.company_id = a.company_id
             WHERE a.company_id = ? AND a.status IN ('pending','changes_requested')
             ORDER BY a.submitted_at ASC, a.id ASC
             LIMIT ?",
            [$companyId, $limite]
        );
    }

    /**
     * A fila da agenda, na ordem em que vai acontecer.
     *
     * @return array<int,array<string,mixed>>
     */
    public function agenda(int $companyId, int $limite = 4): array
    {
        return $this->db->all(
            "SELECT sp.id, sp.network, sp.scheduled_at_utc, sp.timezone,
                    a.title, a.id AS approval_id,
                    COALESCE(NULLIF(c.trade_name, ''), c.company_name) AS cliente
             FROM scheduled_publications sp
             JOIN approvals a ON a.id = sp.approval_id AND a.company_id = sp.company_id
             JOIN clients c ON c.id = sp.client_id AND c.company_id = sp.company_id
             WHERE sp.company_id = ? AND sp.status = 'scheduled'
             ORDER BY sp.scheduled_at_utc ASC
             LIMIT ?",
            [$companyId, $limite]
        );
    }

    /**
     * Atalhos com o número ao lado: só aparece o que a conta pode abrir.
     *
     * @return array<int,array{slug:string,name:string,path:string,icon:string,count:int,alerta:bool}>
     */
    public function atalhos(int $companyId, array $numeros): array
    {
        return [
            ['slug' => 'planner', 'name' => 'Planejamento', 'path' => '/planner', 'icon' => '▤',
                'count' => (int) ($this->db->scalar(
                    'SELECT COUNT(*) FROM posts WHERE company_id = ? AND publication_date >= ?',
                    [$companyId, gmdate('Y-m-d')]
                ) ?? 0), 'alerta' => false],
            ['slug' => 'approvals', 'name' => 'Aprovações', 'path' => '/approvals', 'icon' => '◷',
                'count' => $numeros['aprovacoes_pendentes'], 'alerta' => $numeros['aprovacoes_paradas'] > 0],
            ['slug' => 'schedule', 'name' => 'Agenda', 'path' => '/schedule', 'icon' => '◔',
                'count' => $numeros['agenda_ativa'], 'alerta' => $numeros['agenda_atrasada'] > 0],
            ['slug' => 'studio', 'name' => 'Estúdio', 'path' => '/studio', 'icon' => '✦',
                'count' => $numeros['artes_em_rascunho'], 'alerta' => false],
        ];
    }
}
