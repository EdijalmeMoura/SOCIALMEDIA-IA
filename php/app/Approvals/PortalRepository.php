<?php

declare(strict_types=1);

namespace Smia\Approvals;

use Smia\Database\Database;

/**
 * Contas do portal do cliente e avisos internos.
 *
 * O portal tem sessão separada da agência: quem entra como cliente NÃO ganha
 * acesso ao painel, e vice-versa. Aqui ficam só os dados dessa conta.
 *
 * O cPanel compartilhado costuma não ter envio de e-mail confiável, então o
 * aviso é dentro do produto (tabela `notifications`), não por e-mail.
 */
final class PortalRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function contas(int $companyId): array
    {
        return $this->db->all(
            'SELECT pu.*, c.company_name, c.trade_name
             FROM client_portal_users pu
             JOIN clients c ON c.id = pu.client_id AND c.company_id = pu.company_id
             WHERE pu.company_id = ?
             ORDER BY c.company_name COLLATE NOCASE, pu.name COLLATE NOCASE',
            [$companyId]
        );
    }

    public function conta(int $id, int $companyId): ?array
    {
        return $this->db->one(
            'SELECT * FROM client_portal_users WHERE id = ? AND company_id = ?',
            [$id, $companyId]
        );
    }

    /**
     * Cria acesso do cliente.
     *
     * @throws ApprovalError e-mail já usado ou dados inválidos
     */
    public function criar(int $companyId, int $clientId, string $nome, string $email, string $senha): int
    {
        $nome = trim($nome);
        $email = mb_strtolower(trim($email));
        if ($nome === '' || mb_strlen($nome) > 100) {
            throw new ApprovalError('Informe o nome de quem vai revisar.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
            throw new ApprovalError('Informe um e-mail válido.');
        }
        if (mb_strlen($senha) < 8) {
            throw new ApprovalError('A senha temporária precisa de pelo menos 8 caracteres.');
        }

        try {
            $this->db->run(
                'INSERT INTO client_portal_users (company_id, client_id, name, email, password_hash) VALUES (?, ?, ?, ?, ?)',
                [$companyId, $clientId, $nome, $email, password_hash($senha, PASSWORD_DEFAULT)]
            );
        } catch (\PDOException $erro) {
            if (str_contains($erro->getMessage(), 'UNIQUE')) {
                throw new ApprovalError('Este e-mail já possui acesso ao portal.');
            }
            throw $erro;
        }

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Ativa/desativa o acesso. Desativar derruba a sessão na próxima requisição. */
    public function alternar(int $id, int $companyId): ?bool
    {
        $conta = $this->conta($id, $companyId);
        if ($conta === null) {
            return null;
        }
        $novo = ((int) $conta['is_active']) === 1 ? 0 : 1;
        $this->db->run('UPDATE client_portal_users SET is_active = ? WHERE id = ? AND company_id = ?', [$novo, $id, $companyId]);

        return $novo === 1;
    }

    public function remover(int $id, int $companyId): void
    {
        $this->db->run('DELETE FROM client_portal_users WHERE id = ? AND company_id = ?', [$id, $companyId]);
    }

    /** Troca a senha (agência redefine, ou o próprio cliente). */
    public function trocarSenha(int $id, int $companyId, string $senha): void
    {
        if (mb_strlen($senha) < 8) {
            throw new ApprovalError('A nova senha precisa de pelo menos 8 caracteres.');
        }
        $this->db->run(
            'UPDATE client_portal_users SET password_hash = ? WHERE id = ? AND company_id = ?',
            [password_hash($senha, PASSWORD_DEFAULT), $id, $companyId]
        );
    }

    /**
     * Autentica o cliente do portal.
     *
     * Só contas ativas entram. A senha é conferida com password_verify; se o
     * hash estiver em um algoritmo antigo, é atualizado na hora.
     */
    public function autenticar(string $email, string $senha): ?array
    {
        $email = mb_strtolower(trim($email));
        $conta = $this->db->one(
            'SELECT pu.*, c.company_name, c.trade_name
             FROM client_portal_users pu
             JOIN clients c ON c.id = pu.client_id AND c.company_id = pu.company_id
             WHERE pu.email = ? AND pu.is_active = 1',
            [$email]
        );
        if ($conta === null || !password_verify($senha, (string) $conta['password_hash'])) {
            return null;
        }
        if (password_needs_rehash((string) $conta['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->run(
                'UPDATE client_portal_users SET password_hash = ? WHERE id = ?',
                [password_hash($senha, PASSWORD_DEFAULT), (int) $conta['id']]
            );
        }
        $this->db->run("UPDATE client_portal_users SET last_login_at = datetime('now') WHERE id = ?", [(int) $conta['id']]);

        return $conta;
    }

    /** Dados atuais da conta (para a sessão conferir se ainda está ativa). */
    public function sessaoValida(int $portalUserId): ?array
    {
        return $this->db->one(
            'SELECT pu.*, c.company_name, c.trade_name
             FROM client_portal_users pu
             JOIN clients c ON c.id = pu.client_id AND c.company_id = pu.company_id
             WHERE pu.id = ? AND pu.is_active = 1',
            [$portalUserId]
        );
    }

    // --- avisos -------------------------------------------------------

    /** Avisa todos os responsáveis ativos do cliente. */
    public function avisarCliente(int $companyId, int $clientId, string $titulo, string $corpo, string $url): void
    {
        $contas = $this->db->all(
            'SELECT id FROM client_portal_users WHERE company_id = ? AND client_id = ? AND is_active = 1',
            [$companyId, $clientId]
        );
        foreach ($contas as $conta) {
            $this->db->run(
                "INSERT INTO notifications (company_id, recipient_type, recipient_portal_user_id, title, body, target_url)
                 VALUES (?, 'client', ?, ?, ?, ?)",
                [$companyId, (int) $conta['id'], mb_substr($titulo, 0, 160), mb_substr($corpo, 0, 500), $url]
            );
        }
    }

    /** Avisa toda a equipe da agência. */
    public function avisarAgencia(int $companyId, string $titulo, string $corpo, string $url): void
    {
        $usuarios = $this->db->all('SELECT id FROM users WHERE company_id = ?', [$companyId]);
        foreach ($usuarios as $usuario) {
            $this->db->run(
                "INSERT INTO notifications (company_id, recipient_type, recipient_user_id, title, body, target_url)
                 VALUES (?, 'agency', ?, ?, ?, ?)",
                [$companyId, (int) $usuario['id'], mb_substr($titulo, 0, 160), mb_substr($corpo, 0, 500), $url]
            );
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function avisosAgencia(int $companyId, int $userId, int $limite = 8): array
    {
        return $this->db->all(
            'SELECT * FROM notifications
             WHERE company_id = ? AND recipient_type = ? AND recipient_user_id = ?
             ORDER BY id DESC LIMIT ?',
            [$companyId, 'agency', $userId, $limite]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function avisosCliente(int $companyId, int $portalUserId, int $limite = 20): array
    {
        return $this->db->all(
            'SELECT * FROM notifications
             WHERE company_id = ? AND recipient_type = ? AND recipient_portal_user_id = ?
             ORDER BY id DESC LIMIT ?',
            [$companyId, 'client', $portalUserId, $limite]
        );
    }

    public function naoLidosAgencia(int $companyId, int $userId): int
    {
        return (int) ($this->db->scalar(
            'SELECT COUNT(*) FROM notifications
             WHERE company_id = ? AND recipient_type = ? AND recipient_user_id = ? AND read_at IS NULL',
            [$companyId, 'agency', $userId]
        ) ?? 0);
    }

    public function naoLidosCliente(int $companyId, int $portalUserId): int
    {
        return (int) ($this->db->scalar(
            'SELECT COUNT(*) FROM notifications
             WHERE company_id = ? AND recipient_type = ? AND recipient_portal_user_id = ? AND read_at IS NULL',
            [$companyId, 'client', $portalUserId]
        ) ?? 0);
    }

    public function marcarLidos(int $companyId, string $tipo, int $destinatarioId): void
    {
        if ($tipo === 'agency') {
            $this->db->run(
                "UPDATE notifications SET read_at = datetime('now')
                 WHERE company_id = ? AND recipient_type = 'agency' AND recipient_user_id = ? AND read_at IS NULL",
                [$companyId, $destinatarioId]
            );

            return;
        }
        $this->db->run(
            "UPDATE notifications SET read_at = datetime('now')
             WHERE company_id = ? AND recipient_type = 'client' AND recipient_portal_user_id = ? AND read_at IS NULL",
            [$companyId, $destinatarioId]
        );
    }

    /** O aviso é do destinatário? (evita abrir link de outra pessoa) */
    public function avisoDoDestinatario(int $id, int $companyId, string $tipo, int $destinatarioId): bool
    {
        $coluna = $tipo === 'agency' ? 'recipient_user_id' : 'recipient_portal_user_id';
        $achado = $this->db->scalar(
            "SELECT 1 FROM notifications WHERE id = ? AND company_id = ? AND recipient_type = ? AND {$coluna} = ?",
            [$id, $companyId, $tipo, $destinatarioId]
        );

        return $achado !== null && $achado !== false;
    }
}
