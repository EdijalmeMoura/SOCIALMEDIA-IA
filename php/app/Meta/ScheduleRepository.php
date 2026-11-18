<?php

declare(strict_types=1);

namespace Smia\Meta;

use Smia\Database\Database;

/**
 * Agenda interna de publicação.
 *
 * A agenda é um **registro de intenção**, não um agendador: nada dispara
 * sozinho. Quando o horário chega, uma pessoa autorizada pode iniciar o envio
 * à Meta (só Facebook e Instagram); TikTok e LinkedIn ficam como registro.
 *
 * O horário é guardado em UTC e mostrado no fuso escolhido — o mesmo instante,
 * duas leituras. Guardar hora local seria pedir para errar no horário de verão.
 */
final class ScheduleRepository
{
    public const REDES = ['Instagram', 'Facebook', 'TikTok', 'LinkedIn'];
    public const FUSOS = [
        'America/Recife',
        'America/Sao_Paulo',
        'America/Manaus',
        'America/Belem',
        'UTC',
        'Europe/Lisbon',
        'America/New_York',
    ];
    public const FUSO_PADRAO = 'America/Recife';
    public const ANTECEDENCIA_MINUTOS = 5;

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

    /** Ciclos aprovados pelo cliente, prontos para entrarem na fila. */
    public function aprovados(int $companyId, ?int $clientId): array
    {
        $sql = "SELECT a.id, a.client_id, a.title, a.source_type, a.snapshot_json, a.resolved_at,
                       COALESCE(NULLIF(c.trade_name, ''), c.company_name) AS cliente
                FROM approvals a
                JOIN clients c ON c.id = a.client_id AND c.company_id = a.company_id
                WHERE a.company_id = ? AND a.status = 'approved'";
        $parametros = [$companyId];
        if ($clientId !== null) {
            $sql .= ' AND a.client_id = ?';
            $parametros[] = $clientId;
        }
        $sql .= ' ORDER BY a.resolved_at DESC, a.id DESC LIMIT 100';

        $linhas = $this->db->all($sql, $parametros);
        foreach ($linhas as $indice => $linha) {
            $fotografia = json_decode((string) $linha['snapshot_json'], true);
            $linhas[$indice]['fotografia'] = is_array($fotografia) ? $fotografia : [];
            $linhas[$indice]['network'] = (string) ($linhas[$indice]['fotografia']['network'] ?? '');
        }

        return $linhas;
    }

    public function aprovacao(int $approvalId, int $companyId): ?array
    {
        return $this->db->one('SELECT * FROM approvals WHERE id = ? AND company_id = ?', [$approvalId, $companyId]);
    }

    /**
     * Fila da agência: ativos primeiro, depois o histórico.
     *
     * @return array<int,array<string,mixed>>
     */
    public function lista(int $companyId, int $limite = 100): array
    {
        $sql = "SELECT sp.*, a.title AS approval_title, a.source_type, a.snapshot_json, a.status AS approval_status,
                       COALESCE(NULLIF(c.trade_name, ''), c.company_name) AS cliente,
                       ma.id AS meta_attempt_id, ma.status AS meta_status, ma.remote_publication_id AS meta_remote_id,
                       ma.error_message AS meta_error,
                       mr.outcome AS meta_resolution, mr.remote_publication_id AS meta_resolution_remote_id,
                       mr.note AS meta_resolution_note, mr.created_at AS meta_resolution_at,
                       ru.name AS meta_resolution_by, mc.page_name AS meta_page_name
                FROM scheduled_publications sp
                JOIN approvals a ON a.id = sp.approval_id AND a.company_id = sp.company_id
                JOIN clients c ON c.id = sp.client_id AND c.company_id = sp.company_id
                LEFT JOIN meta_publication_attempts ma ON ma.id = (
                    SELECT MAX(id) FROM meta_publication_attempts
                    WHERE schedule_id = sp.id AND company_id = sp.company_id
                )
                LEFT JOIN meta_publication_reconciliations mr ON mr.attempt_id = ma.id AND mr.company_id = sp.company_id
                LEFT JOIN users ru ON ru.id = mr.reconciled_by
                LEFT JOIN meta_page_connections mc ON mc.id = ma.connection_id AND mc.company_id = ma.company_id
                WHERE sp.company_id = ?
                ORDER BY CASE sp.status WHEN 'scheduled' THEN 0 ELSE 1 END, sp.scheduled_at_utc, sp.id DESC
                LIMIT ?";
        $linhas = $this->db->all($sql, [$companyId, $limite]);

        $agora = time();
        foreach ($linhas as $indice => $linha) {
            $fotografia = json_decode((string) $linha['snapshot_json'], true);
            $fotografia = is_array($fotografia) ? $fotografia : [];
            $linhas[$indice]['local_label'] = self::rotuloLocal((string) $linha['scheduled_at_utc'], (string) $linha['timezone']);
            $linhas[$indice]['local_input'] = self::entradaLocal((string) $linha['scheduled_at_utc'], (string) $linha['timezone']);
            $linhas[$indice]['is_due'] = (int) strtotime((string) $linha['scheduled_at_utc'] . ' UTC') <= $agora;
            $linhas[$indice]['has_export'] = trim((string) ($fotografia['export_path'] ?? '')) !== '';
            $linhas[$indice]['meta_published'] = ($linha['meta_status'] ?? null) === 'published';
            $linhas[$indice]['meta_busy'] = in_array($linha['meta_status'] ?? null, ['publishing', 'published', 'unknown'], true);
            $linhas[$indice]['events'] = $this->db->all(
                'SELECT se.*, u.name AS actor_name
                 FROM schedule_events se
                 LEFT JOIN users u ON u.id = se.actor_user_id
                 WHERE se.scheduled_publication_id = ? AND se.company_id = ?
                 ORDER BY se.id DESC LIMIT 5',
                [(int) $linha['id'], $companyId]
            );
        }

        return $linhas;
    }

    public function ativo(int $scheduleId, int $companyId): ?array
    {
        return $this->db->one(
            'SELECT sp.*, a.title AS approval_title, a.status AS approval_status, a.snapshot_json,
                    COALESCE(NULLIF(c.trade_name, \'\'), c.company_name) AS cliente,
                    (SELECT ma.id FROM meta_publication_attempts ma
                      WHERE ma.schedule_id = sp.id AND ma.company_id = sp.company_id
                      ORDER BY ma.id DESC LIMIT 1) AS meta_attempt_id,
                    (SELECT ma.status FROM meta_publication_attempts ma
                      WHERE ma.schedule_id = sp.id AND ma.company_id = sp.company_id
                      ORDER BY ma.id DESC LIMIT 1) AS meta_status,
                    (SELECT pc.page_name FROM meta_publication_attempts ma
                      JOIN meta_page_connections pc ON pc.id = ma.connection_id AND pc.company_id = ma.company_id
                      WHERE ma.schedule_id = sp.id AND ma.company_id = sp.company_id
                      ORDER BY ma.id DESC LIMIT 1) AS meta_page_name
             FROM scheduled_publications sp
             JOIN approvals a ON a.id = sp.approval_id AND a.company_id = sp.company_id
             JOIN clients c ON c.id = sp.client_id AND c.company_id = sp.company_id
             WHERE sp.id = ? AND sp.company_id = ?',
            [$scheduleId, $companyId]
        );
    }

    /**
     * Coloca um ciclo aprovado na fila.
     *
     * @throws MetaError mensagem pronta para a tela
     */
    public function agendar(int $companyId, int $userId, int $approvalId, string $rede, string $local, string $fuso): int
    {
        $aprovacao = $this->aprovacao($approvalId, $companyId);
        if ($aprovacao === null || (string) $aprovacao['status'] !== 'approved') {
            throw new MetaError('Somente conteúdos aprovados pelo cliente podem entrar na fila.');
        }
        $fotografia = json_decode((string) $aprovacao['snapshot_json'], true);
        $fotografia = is_array($fotografia) ? $fotografia : [];
        if (!in_array($rede, self::REDES, true)) {
            throw new MetaError('Escolha uma rede social válida.');
        }
        if (($fotografia['type'] ?? '') === 'planned' && $rede !== (string) ($fotografia['network'] ?? '')) {
            throw new MetaError('A publicação planejada deve permanecer na rede aprovada.');
        }
        [$utc, $zona] = self::validarHorario($local, $fuso);

        try {
            $this->db->run(
                "INSERT INTO scheduled_publications (company_id, client_id, approval_id, scheduled_by, network, scheduled_at_utc, timezone)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$companyId, (int) $aprovacao['client_id'], $approvalId, $userId, $rede, $utc, $zona]
            );
        } catch (\PDOException $erro) {
            if (str_contains($erro->getMessage(), 'UNIQUE')) {
                throw new MetaError('Este conteúdo já está agendado para essa rede. Cancele o horário anterior para substituí-lo.');
            }
            throw $erro;
        }

        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->run(
            "INSERT INTO schedule_events (scheduled_publication_id, company_id, actor_user_id, event_type, scheduled_at_utc, timezone, note)
             VALUES (?, ?, ?, 'scheduled', ?, ?, 'Agendamento interno criado')",
            [$id, $companyId, $userId, $utc, $zona]
        );

        return $id;
    }

    /** @throws MetaError */
    public function reagendar(int $scheduleId, int $companyId, int $userId, string $local, string $fuso): void
    {
        $agenda = $this->ativo($scheduleId, $companyId);
        if ($agenda === null || (string) $agenda['status'] !== 'scheduled') {
            throw new MetaError('Este horário não está mais ativo.');
        }
        self::garantirEditavel($agenda, 'alterar o horário');
        [$utc, $zona] = self::validarHorario($local, $fuso);

        $anterior = self::rotuloLocal((string) $agenda['scheduled_at_utc'], (string) $agenda['timezone']);
        $this->db->run(
            "UPDATE scheduled_publications SET scheduled_at_utc = ?, timezone = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND company_id = ? AND status = 'scheduled'",
            [$utc, $zona, $scheduleId, $companyId]
        );
        $this->db->run(
            "INSERT INTO schedule_events (scheduled_publication_id, company_id, actor_user_id, event_type, scheduled_at_utc, timezone, note)
             VALUES (?, ?, ?, 'rescheduled', ?, ?, ?)",
            [$scheduleId, $companyId, $userId, $utc, $zona, mb_substr('Horário anterior: ' . $anterior, 0, 200)]
        );
    }

    /** @throws MetaError */
    public function cancelar(int $scheduleId, int $companyId, int $userId): void
    {
        $agenda = $this->ativo($scheduleId, $companyId);
        if ($agenda === null || (string) $agenda['status'] !== 'scheduled') {
            throw new MetaError('Este horário não está mais ativo.');
        }
        self::garantirEditavel($agenda, 'cancelar');

        $this->db->run(
            "UPDATE scheduled_publications SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND company_id = ? AND status = 'scheduled'",
            [$scheduleId, $companyId]
        );
        $this->db->run(
            "INSERT INTO schedule_events (scheduled_publication_id, company_id, actor_user_id, event_type, scheduled_at_utc, timezone, note)
             VALUES (?, ?, ?, 'cancelled', ?, ?, 'Agendamento interno cancelado')",
            [$scheduleId, $companyId, $userId, (string) $agenda['scheduled_at_utc'], (string) $agenda['timezone']]
        );
    }

    /** Resolve o fuso pedido (aceita só a lista conhecida) e cai no padrão. */
    public static function fuso(?string $fuso): string
    {
        return in_array($fuso, self::FUSOS, true) ? (string) $fuso : self::FUSO_PADRAO;
    }

    /**
     * Hora local + fuso -> instante UTC.
     *
     * Três recusas, todas com motivo: formato inválido, horário que não existe
     * (buraco do horário de verão) e horário no passado/perto demais.
     *
     * @return array{0:string,1:string} [UTC em ISO-8601, fuso]
     * @throws MetaError
     */
    public static function validarHorario(?string $local, string $fuso): array
    {
        $zonaNome = self::fuso($fuso);
        $zona = new \DateTimeZone($zonaNome);
        $local = trim((string) $local);
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, $zona);
        if ($data === false || $data->format('Y-m-d\TH:i') !== $local) {
            throw new MetaError('Informe data, hora e fuso horário válidos.');
        }
        // Horário local que não existe (adiantamento do horário de verão).
        if ($data->setTimezone(new \DateTimeZone('UTC'))->setTimezone($zona)->format('Y-m-d\TH:i') !== $local) {
            throw new MetaError('Este horário local não existe devido à mudança de fuso. Escolha outro horário.');
        }
        $utc = $data->setTimezone(new \DateTimeZone('UTC'));
        if ($utc->getTimestamp() < time() + self::ANTECEDENCIA_MINUTOS * 60) {
            throw new MetaError('Agende pelo menos 5 minutos à frente do horário atual.');
        }

        return [$utc->format('Y-m-d\TH:i:s'), $zonaNome];
    }

    /** "03/04/2026 às 14:30" no fuso escolhido. */
    public static function rotuloLocal(string $utc, string $fuso): string
    {
        try {
            $data = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));

            return $data->setTimezone(new \DateTimeZone($fuso))->format('d/m/Y \à\s H:i');
        } catch (\Exception) {
            return $utc;
        }
    }

    /** Valor para <input type="datetime-local"> no fuso escolhido. */
    public static function entradaLocal(string $utc, string $fuso): string
    {
        try {
            $data = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));

            return $data->setTimezone(new \DateTimeZone($fuso))->format('Y-m-d\TH:i');
        } catch (\Exception) {
            return '';
        }
    }

    /** Sugestão de horário: amanhã, mesma hora cheia, no fuso padrão. */
    public static function proximoHorarioSugerido(): string
    {
        $zona = new \DateTimeZone(self::FUSO_PADRAO);
        $quando = (new \DateTimeImmutable('now', $zona))->modify('+1 day');

        return $quando->format('Y-m-d\TH:i');
    }

    /** @param array<string,mixed> $agenda */
    private static function garantirEditavel(array $agenda, string $acao): void
    {
        if (in_array($agenda['meta_status'] ?? null, ['publishing', 'published', 'unknown'], true)) {
            throw new MetaError('Esta tentativa está em andamento ou tem resultado incerto; verifique a conta Meta antes de ' . $acao . '.');
        }
    }
}
