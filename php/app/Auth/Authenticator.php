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

            return null;
        }

        // Troca de agência escolhida no menu. Sem isto, o seletor mudaria só o
        // cartão do menu e todas as telas continuariam lendo a agência da
        // conta — pior que não ter o botão, porque pareceria funcionar.
        //
        // `company_id` passa a ser a agência ATIVA da requisição; a agência de
        // origem continua em `home_company_id`. Quem não é dono nunca tem uma
        // escolha gravada na sessão (a troca é recusada antes), então para
        // essas contas este bloco não muda nada.
        $usuario['home_company_id'] = (int) $usuario['company_id'];
        $escolhida = (int) ($this->session->get('company_id') ?? 0);
        if ($escolhida > 0 && $escolhida !== (int) $usuario['company_id']) {
            $permitidas = array_column($this->agenciasDisponiveis($usuario), 'id');
            if (in_array($escolhida, $permitidas, true)) {
                $agencia = $this->db->one('SELECT id, name, slug FROM companies WHERE id = ?', [$escolhida]);
                if ($agencia !== null) {
                    $usuario['company_id'] = (int) $agencia['id'];
                    $usuario['company_name'] = (string) $agencia['name'];
                    $usuario['company_slug'] = (string) $agencia['slug'];
                }
            } else {
                // Agência desativada ou permissão que mudou: volta ao padrão.
                $this->session->forget('company_id');
            }
        }

        return $usuario;
    }

    /**
     * Agências que esta conta pode abrir no seletor do menu.
     *
     * A regra é simples e conservadora:
     *  - dono da instalação (`is_super`) vê todas as agências ativas;
     *  - qualquer outra conta vê a própria agência.
     *
     * Isso não afrouxa o isolamento: o `company_id` da sessão continua sendo o
     * que todos os controllers usam, e a troca é recusada no servidor quando a
     * agência não está nesta lista.
     *
     * @return array<int,array{id:int,name:string,slug:string}>
     */
    public function agenciasDisponiveis(array $usuario): array
    {
        $super = (int) ($usuario['is_super'] ?? 0) === 1;

        if (!$super) {
            return [[
                'id' => (int) ($usuario['home_company_id'] ?? $usuario['company_id']),
                'name' => (string) ($usuario['company_name'] ?? ''),
                'slug' => (string) ($usuario['company_slug'] ?? ''),
            ]];
        }

        $linhas = $this->db->all(
            "SELECT id, name, slug FROM companies WHERE COALESCE(is_active, 1) = 1 ORDER BY name COLLATE NOCASE"
        );

        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'name' => (string) $l['name'],
            'slug' => (string) $l['slug'],
        ], $linhas);
    }

    /**
     * Troca a agência em uso na sessão.
     *
     * Só aceita uma agência que esteja em `agenciasDisponiveis()` — a lista é
     * a autorização, então não existe caminho para "mandar o id 3 no POST" e
     * entrar numa agência de outra pessoa.
     *
     * @return array{ok:bool,erro:string,agencia:?array{id:int,name:string,slug:string}}
     */
    public function trocarAgencia(array $usuario, int $companyId): array
    {
        foreach ($this->agenciasDisponiveis($usuario) as $agencia) {
            if ($agencia['id'] === $companyId) {
                $this->session->put('company_id', $companyId);

                return ['ok' => true, 'erro' => '', 'agencia' => $agencia];
            }
        }

        return [
            'ok' => false,
            'erro' => 'Sua conta não tem acesso a essa agência.',
            'agencia' => null,
        ];
    }

    /** Agência escolhida no menu, se ainda fizer sentido para esta conta. */
    public function agenciaAtiva(array $usuario): array
    {
        $disponiveis = $this->agenciasDisponiveis($usuario);
        $escolhida = (int) ($this->session->get('company_id') ?? 0);

        foreach ($disponiveis as $agencia) {
            if ($agencia['id'] === $escolhida) {
                return $agencia;
            }
        }

        // Padrão: a agência da própria conta (existe sempre, é a do usuário).
        return [
            'id' => (int) ($usuario['home_company_id'] ?? $usuario['company_id']),
            'name' => (string) ($usuario['company_name'] ?? ''),
            'slug' => (string) ($usuario['company_slug'] ?? ''),
        ];
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
        // Conta desativada não entra — e a mensagem NÃO diz que ela existe,
        // para não transformar a tela de login num verificador de e-mails.
        if ($usuario !== null && (int) ($usuario['is_active'] ?? 1) === 0) {
            $recentes[] = $agora;
            $tentativas[$chave] = $recentes;
            $this->session->put(self::CHAVE_TENTATIVAS, $tentativas);

            return ['ok' => false, 'error' => 'E-mail ou senha inválidos.', 'user' => null];
        }
        if ($usuario === null || !password_verify($senha, (string) $usuario['password_hash'])) {
            $recentes[] = $agora;
            $tentativas[$chave] = $recentes;
            $this->session->put(self::CHAVE_TENTATIVAS, $tentativas);

            return ['ok' => false, 'error' => 'E-mail ou senha inválidos.', 'user' => null];
        }

        unset($tentativas[$chave]);
        $this->session->put(self::CHAVE_TENTATIVAS, $tentativas);
        $this->session->regenerate();
        // Entrar na agência encerra uma sessão de portal aberta no mesmo
        // navegador: os dois escopos nunca ficam ativos ao mesmo tempo.
        $this->session->forget('portal_user_id');
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
