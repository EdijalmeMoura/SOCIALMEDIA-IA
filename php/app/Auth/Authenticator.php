<?php

declare(strict_types=1);

namespace Smia\Auth;

use Smia\Database\Database;
use Smia\Support\Session;

/**
 * Login por e-mail/senha com trava de tentativas.
 *
 * Senhas usam password_verify (Argon2id/bcrypt). Mensagem de erro é sempre a
 * mesma para e-mail inexistente e senha errada, para não revelar cadastros.
 */
final class Authenticator
{
    private const MAX_TENTATIVAS = 8;
    private const JANELA = 900; // 15 minutos
    private const CHAVE_TENTATIVAS = '_login_attempts';

    public function __construct(private readonly Database $db, private readonly Session $session)
    {
    }

    public function user(): ?array
    {
        $id = $this->session->get('user_id');
        if (!$id) {
            return null;
        }
        $usuario = $this->db->one(
            'SELECT u.*, c.name AS company_name, c.slug AS company_slug
             FROM users u JOIN companies c ON c.id = u.company_id WHERE u.id = ?',
            [$id]
        );
        if ($usuario === null) {
            $this->session->forget('user_id');
        }

        return $usuario;
    }

    /** @return array{ok:bool,error:?string,user:?array} */
    public function attempt(string $email, string $senha, string $ip): array
    {
        $chave = hash('sha256', mb_strtolower(trim($email)) . '|' . $ip);
        $tentativas = $this->session->get(self::CHAVE_TENTATIVAS, []);
        $agora = time();
        $recentes = array_values(array_filter(
            (array) ($tentativas[$chave] ?? []),
            static fn ($t) => $agora - (int) $t < self::JANELA
        ));

        if (count($recentes) >= self::MAX_TENTATIVAS) {
            return ['ok' => false, 'error' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.', 'user' => null];
        }

        $usuario = $this->db->one('SELECT * FROM users WHERE email = ? COLLATE NOCASE', [mb_strtolower(trim($email))]);
        if ($usuario === null || !password_verify($senha, (string) $usuario['password_hash'])) {
            $recentes[] = $agora;
            $tentativas[$chave] = $recentes;
            $this->session->put(self::CHAVE_TENTATIVAS, $tentativas);

            return ['ok' => false, 'error' => 'E-mail ou senha inválidos.', 'user' => null];
        }

        unset($tentativas[$chave]);
        $this->session->put(self::CHAVE_TENTATIVAS, $tentativas);
        $this->session->regenerate();
        $this->session->put('user_id', (int) $usuario['id']);
        $this->db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $usuario['id']]);

        // Rehash transparente quando o custo/algoritmo padrão mudar.
        if (password_needs_rehash((string) $usuario['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [
                password_hash($senha, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT),
                $usuario['id'],
            ]);
        }

        return ['ok' => true, 'error' => null, 'user' => $usuario];
    }

    public function logout(): void
    {
        $this->session->destroy();
    }
}
