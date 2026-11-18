<?php

declare(strict_types=1);

namespace Smia\Meta;

use Smia\Approvals\PortalRepository;
use Smia\Database\Database;

/**
 * Conexões Meta, tentativas de publicação e a reconciliação humana.
 *
 * O desenho parte de uma constatação: publicar é a única ação do produto que
 * **não** pode ser desfeita. Por isso:
 *  - cada clique vira uma tentativa registrada antes de falar com a Meta;
 *  - o índice único do banco impede duas tentativas ativas no mesmo horário;
 *  - erro sem resposta confiável fica "unknown" e trava novas tentativas até
 *    alguém conferir a conta e registrar o que viu;
 *  - o token da Página é criptografado e nunca é devolvido para a tela.
 */
final class MetaRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly PortalRepository $avisos,
    ) {
    }

    // --- conexões -----------------------------------------------------

    /** Páginas conectadas, com último teste e permissões de publicação. */
    public function conexoes(int $companyId): array
    {
        $linhas = $this->db->all(
            "SELECT pc.*,
                    (SELECT mc.status FROM meta_connection_checks mc
                      WHERE mc.connection_id = pc.id AND mc.company_id = pc.company_id
                      ORDER BY mc.id DESC LIMIT 1) AS last_check_status,
                    (SELECT mc.details FROM meta_connection_checks mc
                      WHERE mc.connection_id = pc.id AND mc.company_id = pc.company_id
                      ORDER BY mc.id DESC LIMIT 1) AS last_check_details,
                    (SELECT mc.checked_at FROM meta_connection_checks mc
                      WHERE mc.connection_id = pc.id AND mc.company_id = pc.company_id
                      ORDER BY mc.id DESC LIMIT 1) AS last_checked_at,
                    (SELECT p.status FROM meta_connection_permissions p
                      WHERE p.connection_id = pc.id AND p.company_id = pc.company_id
                        AND p.permission = 'pages_manage_posts') AS facebook_publish_permission,
                    (SELECT p.status FROM meta_connection_permissions p
                      WHERE p.connection_id = pc.id AND p.company_id = pc.company_id
                        AND p.permission = 'instagram_basic') AS instagram_basic_permission,
                    (SELECT p.status FROM meta_connection_permissions p
                      WHERE p.connection_id = pc.id AND p.company_id = pc.company_id
                        AND p.permission = 'instagram_content_publish') AS instagram_publish_permission
             FROM meta_page_connections pc
             WHERE pc.company_id = ?
             ORDER BY pc.page_name COLLATE NOCASE",
            [$companyId]
        );

        foreach ($linhas as $indice => $linha) {
            $linhas[$indice]['permissions'] = $this->db->all(
                'SELECT permission, status, checked_at FROM meta_connection_permissions
                 WHERE company_id = ? AND connection_id = ? ORDER BY permission',
                [$companyId, (int) $linha['id']]
            );
            [$status, $quando] = self::prazoToken($linha['token_expires_at']);
            $linhas[$indice]['token_expiry_status'] = $status;
            $linhas[$indice]['token_expiry_utc'] = $quando;
        }

        return $linhas;
    }

    public function conexao(int $connectionId, int $companyId): ?array
    {
        return $this->db->one('SELECT * FROM meta_page_connections WHERE id = ? AND company_id = ?', [$connectionId, $companyId]);
    }

    /** Páginas que podem publicar nesta rede (sem permissão recusada). */
    public function conexoesCompativeis(int $companyId, string $rede): array
    {
        $todas = $this->conexoes($companyId);

        return array_values(array_filter($todas, static function (array $conexao) use ($rede): bool {
            if ($rede === 'Instagram') {
                return !empty($conexao['instagram_user_id'])
                    && ($conexao['instagram_basic_permission'] ?? null) !== 'declined'
                    && ($conexao['instagram_publish_permission'] ?? null) !== 'declined';
            }

            return ($conexao['facebook_publish_permission'] ?? null) !== 'declined';
        }));
    }

    /**
     * Grava (ou atualiza) as Páginas descobertas pelo OAuth.
     *
     * @param array<int,array{page_id:string,page_name:string,page_access_token:string,instagram_user_id:?string,instagram_username:?string}> $paginas
     * @param array<string,string> $permissoes
     */
    public function salvarConexoes(
        int $companyId,
        int $userId,
        array $paginas,
        array $permissoes,
        TokenCipher $cipher,
        ?string $expiraEm,
    ): int {
        $this->db->pdo()->beginTransaction();
        try {
            foreach ($paginas as $pagina) {
                $this->db->run(
                    'INSERT INTO meta_page_connections
                        (company_id, connected_by, page_id, page_name, instagram_user_id, instagram_username, page_access_token_encrypted, token_expires_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (company_id, page_id) DO UPDATE SET
                        connected_by = excluded.connected_by,
                        page_name = excluded.page_name,
                        instagram_user_id = excluded.instagram_user_id,
                        instagram_username = excluded.instagram_username,
                        page_access_token_encrypted = excluded.page_access_token_encrypted,
                        token_expires_at = excluded.token_expires_at,
                        updated_at = CURRENT_TIMESTAMP',
                    [
                        $companyId,
                        $userId,
                        $pagina['page_id'],
                        $pagina['page_name'],
                        $pagina['instagram_user_id'],
                        $pagina['instagram_username'],
                        $cipher->cifrar($pagina['page_access_token']),
                        $expiraEm,
                    ]
                );
                $conexaoId = (int) $this->db->scalar(
                    'SELECT id FROM meta_page_connections WHERE company_id = ? AND page_id = ?',
                    [$companyId, $pagina['page_id']]
                );
                foreach (MetaSettings::ESCOPOS as $escopo) {
                    $this->db->run(
                        'INSERT INTO meta_connection_permissions (company_id, connection_id, permission, status)
                         VALUES (?, ?, ?, ?)
                         ON CONFLICT (connection_id, permission) DO UPDATE SET
                            status = excluded.status, checked_at = CURRENT_TIMESTAMP',
                        [$companyId, $conexaoId, $escopo, $permissoes[$escopo] ?? 'unknown']
                    );
                }
            }
            $this->db->pdo()->commit();
        } catch (\Throwable $erro) {
            $this->db->pdo()->rollBack();
            throw $erro;
        }

        return count($paginas);
    }

    public function desconectar(int $connectionId, int $companyId): void
    {
        // O token criptografado some junto com a linha; tentativas antigas
        // ficam no histórico (connection_id vira NULL).
        $this->db->run('DELETE FROM meta_page_connections WHERE id = ? AND company_id = ?', [$connectionId, $companyId]);
    }

    public function registrarTeste(int $connectionId, int $companyId, int $userId, bool $ok, string $detalhes): void
    {
        $this->db->run(
            'INSERT INTO meta_connection_checks (company_id, connection_id, checked_by, status, details) VALUES (?, ?, ?, ?, ?)',
            [$companyId, $connectionId, $userId, $ok ? 'ok' : 'failed', mb_substr($detalhes, 0, 500)]
        );
    }

    public function registrarTls(int $companyId, int $userId, bool $ok, string $detalhes): void
    {
        $this->db->run(
            'INSERT INTO meta_graph_connectivity_checks (company_id, checked_by, status, details) VALUES (?, ?, ?, ?)',
            [$companyId, $userId, $ok ? 'ok' : 'failed', mb_substr($detalhes, 0, 500)]
        );
    }

    public function registrarMidia(int $companyId, int $userId, string $base, bool $ok, string $detalhes): void
    {
        $this->db->run(
            'INSERT INTO meta_public_media_checks (company_id, checked_by, base_url, status, details) VALUES (?, ?, ?, ?, ?)',
            [$companyId, $userId, mb_substr($base, 0, 200), $ok ? 'ok' : 'failed', mb_substr($detalhes, 0, 500)]
        );
    }

    /**
     * Evidências para o painel: o que já foi comprovado nesta agência.
     *
     * @return array<string,mixed>
     */
    public function evidencias(int $companyId): array
    {
        return [
            'ultima_leitura' => $this->db->one(
                "SELECT mc.checked_at, pc.page_name FROM meta_connection_checks mc
                 JOIN meta_page_connections pc ON pc.id = mc.connection_id AND pc.company_id = mc.company_id
                 WHERE mc.company_id = ? AND mc.status = 'ok' ORDER BY mc.id DESC LIMIT 1",
                [$companyId]
            ),
            'ultimo_facebook' => $this->db->one(
                "SELECT remote_publication_id, created_at FROM meta_publication_attempts
                 WHERE company_id = ? AND network = 'Facebook' AND status = 'published' ORDER BY id DESC LIMIT 1",
                [$companyId]
            ),
            'ultimo_instagram' => $this->db->one(
                "SELECT remote_publication_id, created_at FROM meta_publication_attempts
                 WHERE company_id = ? AND network = 'Instagram' AND status = 'published' ORDER BY id DESC LIMIT 1",
                [$companyId]
            ),
            'ultimo_tls' => $this->db->one(
                'SELECT status, details, checked_at FROM meta_graph_connectivity_checks WHERE company_id = ? ORDER BY id DESC LIMIT 1',
                [$companyId]
            ),
            'ultima_midia' => $this->db->one(
                'SELECT base_url, status, details, checked_at FROM meta_public_media_checks WHERE company_id = ? ORDER BY id DESC LIMIT 1',
                [$companyId]
            ),
        ];
    }

    /** Prazos de token a vencer viram aviso dentro do produto, uma vez cada. */
    public function avisarPrazos(int $companyId, string $url): void
    {
        foreach ($this->conexoes($companyId) as $conexao) {
            $status = $conexao['token_expiry_status'];
            if (!in_array($status, ['expiring', 'expired'], true) || empty($conexao['token_expires_at'])) {
                continue;
            }
            $inserido = $this->db->run(
                'INSERT OR IGNORE INTO meta_token_expiry_notices (company_id, connection_id, token_expires_at, notice_type)
                 VALUES (?, ?, ?, ?)',
                [$companyId, (int) $conexao['id'], (string) $conexao['token_expires_at'], $status]
            );
            if ($inserido->rowCount() !== 1) {
                continue;
            }
            $titulo = $status === 'expired'
                ? 'Prazo informado do token Meta passou'
                : 'Prazo informado do token Meta se aproxima';
            $this->avisos->avisarAgencia(
                $companyId,
                $titulo,
                $conexao['page_name'] . ' · OAuth expira em ' . $conexao['token_expiry_utc'] . ' UTC. Reconecte e teste a leitura.',
                $url
            );
        }
    }

    // --- publicação ---------------------------------------------------

    /**
     * Abre a tentativa ANTES de falar com a Meta.
     *
     * @throws MetaError quando já existe tentativa ativa (índice único)
     */
    public function abrirTentativa(int $companyId, int $scheduleId, int $connectionId, int $userId, string $rede): int
    {
        try {
            $this->db->run(
                "INSERT INTO meta_publication_attempts (company_id, schedule_id, connection_id, attempted_by, network, status)
                 VALUES (?, ?, ?, ?, ?, 'publishing')",
                [$companyId, $scheduleId, $connectionId, $userId, $rede]
            );
        } catch (\PDOException $erro) {
            if (str_contains($erro->getMessage(), 'UNIQUE')) {
                throw new MetaError('Já existe uma tentativa em andamento ou uma publicação concluída para este horário.');
            }
            throw $erro;
        }

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function concluirTentativa(int $attemptId, int $companyId, string $remoteId): void
    {
        $this->db->run(
            "UPDATE meta_publication_attempts
             SET status = 'published', remote_publication_id = ?, error_message = '', updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND company_id = ?",
            [mb_substr($remoteId, 0, 255), $attemptId, $companyId]
        );
    }

    public function falharTentativa(int $attemptId, int $companyId, bool $incerta, string $mensagem): void
    {
        $this->db->run(
            'UPDATE meta_publication_attempts SET status = ?, error_message = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND company_id = ?',
            [$incerta ? 'unknown' : 'failed', mb_substr($mensagem, 0, 700), $attemptId, $companyId]
        );
    }

    /**
     * Tentativa que ficou "publicando" por mais de 10 minutos virou incerta.
     *
     * Sem isso, uma queda do servidor no meio do envio deixaria o horário
     * travado para sempre — e alguém poderia publicar de novo sem saber.
     */
    public function resolverTentativasParadas(int $companyId, string $url): int
    {
        $paradas = $this->db->all(
            "SELECT ma.id, ma.network, a.title
             FROM meta_publication_attempts ma
             JOIN scheduled_publications sp ON sp.id = ma.schedule_id AND sp.company_id = ma.company_id
             JOIN approvals a ON a.id = sp.approval_id AND a.company_id = sp.company_id
             WHERE ma.company_id = ? AND ma.status = 'publishing'
               AND datetime(ma.created_at) < datetime('now', '-10 minutes')
             ORDER BY ma.id",
            [$companyId]
        );
        $mensagem = 'A tentativa ficou sem resposta do servidor. Confira a conta Meta antes de qualquer nova tentativa.';
        $marcadas = 0;
        foreach ($paradas as $tentativa) {
            $atualizadas = $this->db->run(
                "UPDATE meta_publication_attempts SET status = 'unknown', error_message = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND company_id = ? AND status = 'publishing'",
                [$mensagem, (int) $tentativa['id'], $companyId]
            );
            if ($atualizadas->rowCount() !== 1) {
                continue;
            }
            $marcadas++;
            $this->avisos->avisarAgencia(
                $companyId,
                'Publicação Meta ficou sem conclusão',
                $tentativa['title'] . ' · ' . $tentativa['network'] . ' · tentativa #' . $tentativa['id'] . ' está incerta após 10 minutos.',
                $url
            );
        }

        return $marcadas;
    }

    public function tentativa(int $attemptId, int $companyId): ?array
    {
        return $this->db->one(
            'SELECT ma.*, sp.status AS schedule_status FROM meta_publication_attempts ma
             JOIN scheduled_publications sp ON sp.id = ma.schedule_id AND sp.company_id = ma.company_id
             WHERE ma.id = ? AND ma.company_id = ?',
            [$attemptId, $companyId]
        );
    }

    /**
     * Registra a conferência humana de uma tentativa incerta.
     *
     * @throws MetaError
     */
    public function reconciliar(
        int $attemptId,
        int $companyId,
        int $userId,
        string $resultado,
        string $remoteId,
        string $observacao,
        string $url,
    ): void {
        if (!in_array($resultado, ['confirmed_published', 'confirmed_not_published'], true)) {
            throw new MetaError('Escolha se a publicação foi encontrada ou não foi encontrada na conta Meta.');
        }
        if (mb_strlen(trim($observacao)) < 12) {
            throw new MetaError('Registre uma observação de pelo menos 12 caracteres sobre a conferência manual.');
        }
        if ($resultado === 'confirmed_published' && trim($remoteId) === '') {
            throw new MetaError('Informe o ID real da publicação mostrado na Meta.');
        }
        if ($resultado === 'confirmed_not_published' && trim($remoteId) !== '') {
            throw new MetaError('Não informe um ID de publicação quando a conferência apontou que nada foi publicado.');
        }

        $this->db->pdo()->beginTransaction();
        try {
            $tentativa = $this->tentativa($attemptId, $companyId);
            if ($tentativa === null) {
                throw new MetaError('Tentativa não encontrada.', false);
            }
            if ((string) $tentativa['status'] !== 'unknown') {
                $this->db->pdo()->rollBack();

                throw new MetaError('Só é possível conferir uma tentativa de resultado incerto.');
            }
            if ($this->db->scalar('SELECT 1 FROM meta_publication_reconciliations WHERE attempt_id = ? AND company_id = ?', [$attemptId, $companyId]) !== null) {
                $this->db->pdo()->rollBack();

                throw new MetaError('Esta tentativa já foi conferida.');
            }
            if ($resultado === 'confirmed_not_published') {
                $this->db->run(
                    "UPDATE meta_publication_attempts SET status = 'failed', error_message = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND company_id = ? AND status = 'unknown'",
                    [mb_substr('Equipe confirmou manualmente que não houve publicação: ' . $observacao, 0, 700), $attemptId, $companyId]
                );
            }
            $this->db->run(
                'INSERT INTO meta_publication_reconciliations (company_id, attempt_id, reconciled_by, outcome, remote_publication_id, note)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$companyId, $attemptId, $userId, $resultado, trim($remoteId) !== '' ? mb_substr(trim($remoteId), 0, 255) : null, mb_substr(trim($observacao), 0, 500)]
            );
            $this->db->pdo()->commit();
        } catch (MetaError $erro) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            throw $erro;
        } catch (\PDOException $erro) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            if (str_contains($erro->getMessage(), 'UNIQUE')) {
                throw new MetaError('Esta tentativa já foi conferida.');
            }
            throw $erro;
        }

        $this->avisos->avisarAgencia(
            $companyId,
            $resultado === 'confirmed_published' ? 'Publicação conferida manualmente' : 'Tentativa Meta conferida: não publicada',
            $observacao,
            $url
        );
    }

    /** Auditoria simples, na mesma tabela dos outros módulos. */
    public function auditoria(int $companyId, ?int $userId, string $acao, string $detalhe = ''): void
    {
        $this->db->run(
            'INSERT INTO audit_log (company_id, user_id, action, detail) VALUES (?, ?, ?, ?)',
            [$companyId, $userId, $acao, mb_substr($detalhe, 0, 500)]
        );
    }

    /** O publicador precisa do banco para conferir permissões. */
    public function db(): Database
    {
        return $this->db;
    }

    // --- utilidades ---------------------------------------------------

    /** @return array{0:string,1:?string} [future|expiring|expired|unknown, data legível] */
    public static function prazoToken(?string $expiraEm, ?int $agora = null): array
    {
        if ($expiraEm === null || trim($expiraEm) === '') {
            return ['unknown', null];
        }
        $quando = strtotime($expiraEm);
        if ($quando === false) {
            return ['unknown', null];
        }
        $agora = $agora ?? time();
        $legivel = gmdate('d/m/Y H:i', $quando);
        if ($quando <= $agora) {
            return ['expired', $legivel];
        }
        if ($quando - $agora <= 7 * 86400) {
            return ['expiring', $legivel];
        }

        return ['future', $legivel];
    }
}
