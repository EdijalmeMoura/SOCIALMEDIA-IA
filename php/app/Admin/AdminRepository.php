<?php

declare(strict_types=1);

namespace Smia\Admin;

use Smia\Database\Database;

/**
 * Leitura e escrita da área do dono: todas as agências, todos os usuários.
 *
 * Toda consulta daqui é global de propósito — é o único lugar do produto que
 * enxerga além da própria agência. Por isso o controller exige o papel `super`
 * antes de qualquer chamada; nenhuma view usa este repositório.
 */
final class AdminRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function agencias(): array
    {
        return $this->db->all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) AS user_count,
                    (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.is_active = 1) AS active_users,
                    (SELECT COUNT(*) FROM clients cl WHERE cl.company_id = c.id) AS client_count,
                    (SELECT MAX(u.last_login_at) FROM users u WHERE u.company_id = c.id) AS last_login
             FROM companies c
             ORDER BY c.is_active DESC, c.name COLLATE NOCASE"
        );
    }

    public function agencia(int $id): ?array
    {
        return $this->db->one('SELECT * FROM companies WHERE id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function usuarios(int $companyId): array
    {
        return $this->db->all(
            'SELECT id, name, email, role, is_super, is_active, last_login_at, created_at
             FROM users WHERE company_id = ? ORDER BY is_active DESC, is_super DESC, name COLLATE NOCASE',
            [$companyId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function todosUsuarios(): array
    {
        return $this->db->all(
            'SELECT u.id, u.name, u.email, u.role, u.is_super, u.is_active, u.last_login_at, u.created_at,
                    c.name AS company_name, c.id AS company_id
             FROM users u JOIN companies c ON c.id = u.company_id
             ORDER BY u.is_active DESC, c.name COLLATE NOCASE, u.name COLLATE NOCASE'
        );
    }

    public function usuario(int $id): ?array
    {
        return $this->db->one(
            'SELECT u.*, c.name AS company_name FROM users u JOIN companies c ON c.id = u.company_id WHERE u.id = ?',
            [$id]
        );
    }

    public function emailEmUso(string $email, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE email = ? COLLATE NOCASE';
        $parametros = [mb_strtolower(trim($email))];
        if ($ignorarId !== null) {
            $sql .= ' AND id <> ?';
            $parametros[] = $ignorarId;
        }

        return (int) ($this->db->scalar($sql, $parametros) ?? 0) > 0;
    }

    public function criarAgencia(string $nome, ?string $emailDono, string $observacao = ''): int
    {
        $slug = $this->slugLivre($nome);
        $this->db->run(
            'INSERT INTO companies (name, slug, owner_email, notes) VALUES (?, ?, ?, ?)',
            [$nome, $slug, $emailDono, $observacao]
        );

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function atualizarAgencia(int $id, string $nome, ?string $emailDono, string $observacao): void
    {
        $this->db->run(
            'UPDATE companies SET name = ?, owner_email = ?, notes = ? WHERE id = ?',
            [$nome, $emailDono, $observacao, $id]
        );
    }

    public function alternarAgencia(int $id, bool $ativa): void
    {
        $this->db->run('UPDATE companies SET is_active = ? WHERE id = ?', [$ativa ? 1 : 0, $id]);
    }

    public function criarUsuario(int $companyId, string $nome, string $email, string $hash, string $papel, bool $super = false): int
    {
        $this->db->run(
            'INSERT INTO users (company_id, name, email, password_hash, role, is_super, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$companyId, $nome, mb_strtolower(trim($email)), $hash, $papel, $super ? 1 : 0]
        );

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function atualizarUsuario(int $id, string $nome, string $email, string $papel, bool $super = false): void
    {
        $this->db->run(
            'UPDATE users SET name = ?, email = ?, role = ?, is_super = ? WHERE id = ?',
            [$nome, mb_strtolower(trim($email)), $papel, $super ? 1 : 0, $id]
        );
    }

    public function trocarSenha(int $id, string $hash): void
    {
        $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $id]);
    }

    public function alternarUsuario(int $id, bool $ativo): void
    {
        $this->db->run('UPDATE users SET is_active = ? WHERE id = ?', [$ativo ? 1 : 0, $id]);
    }

    public function excluirUsuario(int $id): void
    {
        $this->db->run('DELETE FROM users WHERE id = ?', [$id]);
    }

    /**
     * Quantas pessoas ativas conseguem administrar a agência.
     *
     * `admin` conta: a diferença entre owner e admin é de escopo (dono também
     * responde pela agência), não de poder — os dois gerenciam. Se só `owner`
     * contasse, uma agência com owner + admin recusaria desativar o owner,
     * que é justamente o caso seguro.
     */
    public function administradoresAtivos(int $companyId): int
    {
        return (int) ($this->db->scalar(
            "SELECT COUNT(*) FROM users
             WHERE company_id = ? AND is_active = 1 AND (role IN ('owner','admin') OR is_super = 1)",
            [$companyId]
        ) ?? 0);
    }

    public function totalDonos(): int
    {
        return (int) ($this->db->scalar('SELECT COUNT(*) FROM users WHERE is_super = 1 AND is_active = 1') ?? 0);
    }

    /** @param array<string,mixed> $extra */
    public function registrar(?int $autor, ?int $companyId, string $acao, string $detalhe = ''): void
    {
        $this->db->run(
            'INSERT INTO admin_actions (actor_id, target_company_id, action, detail) VALUES (?, ?, ?, ?)',
            [$autor, $companyId, $acao, $detalhe]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function ultimasAcoes(int $limite = 20): array
    {
        return $this->db->all(
            'SELECT a.*, u.name AS actor_name, u.email AS actor_email, c.name AS company_name
             FROM admin_actions a
             LEFT JOIN users u ON u.id = a.actor_id
             LEFT JOIN companies c ON c.id = a.target_company_id
             ORDER BY a.id DESC LIMIT ?',
            [$limite]
        );
    }

    /** @return array{companies:int,users:int,clients:int,active_users:int} */
    public function resumo(): array
    {
        return [
            'companies' => (int) ($this->db->scalar('SELECT COUNT(*) FROM companies') ?? 0),
            'active_companies' => (int) ($this->db->scalar('SELECT COUNT(*) FROM companies WHERE is_active = 1') ?? 0),
            'users' => (int) ($this->db->scalar('SELECT COUNT(*) FROM users') ?? 0),
            'active_users' => (int) ($this->db->scalar('SELECT COUNT(*) FROM users WHERE is_active = 1') ?? 0),
            'clients' => (int) ($this->db->scalar('SELECT COUNT(*) FROM clients') ?? 0),
        ];
    }

    /** Slug único legível: "Agência X" -> "agencia-x", "agencia-x-2"... */
    private function slugLivre(string $nome): string
    {
        $limpo = $this->semAcento($nome);
        $limpo = (string) preg_replace('/[^A-Za-z0-9]+/', '-', $limpo);
        $base = strtolower(trim($limpo, '-'));
        $base = $base === '' ? 'agencia' : substr($base, 0, 48);
        $slug = $base;
        $n = 2;
        while ((int) ($this->db->scalar('SELECT COUNT(*) FROM companies WHERE slug = ?', [$slug]) ?? 0) > 0) {
            $slug = $base . '-' . $n;
            $n++;
        }

        return $slug;
    }

    private function semAcento(string $texto): string
    {
        $mapa = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
            'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A',
            'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Õ' => 'O',
            'Ô' => 'O', 'Ú' => 'U', 'Ç' => 'C',
        ];

        return strtr($texto, $mapa);
    }
}
