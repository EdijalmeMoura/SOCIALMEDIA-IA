<?php

declare(strict_types=1);

namespace Smia\Installer;

use Smia\Database\Database;
use Smia\Database\Schema;
use Smia\Support\Config;

/**
 * Instalação pelo navegador, portada do produto Python.
 *
 * Regras mantidas de propósito:
 *  - a rota só existe enquanto não há usuário;
 *  - nada é gravado antes da confirmação final;
 *  - a senha só é persistida como hash (password_hash/ARGON2ID quando existe);
 *  - segredos são gravados em arquivo privado 0600;
 *  - a URL pública aceita subpasta, porque é isso que gera o callback Meta.
 */
final class Installer
{
    public const MIN_PASSWORD_LENGTH = 8;
    private const RASCUNHO_TTL = 1800;

    private const SENHAS_OBVIAS = [
        '12345678', '123456789', '1234567890', 'senha123', 'senha1234',
        'password', 'password1', 'qwerty12', 'admin123', 'socialmedia',
        'instagram', 'abcd1234', '12341234', '11111111',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly string $rascunhoDir,
    ) {
    }

    public static function make(Config $config, Database $db): self
    {
        return new self($config, $db, $config->dataDir . '/installer-drafts');
    }

    /** A instância precisa de instalação? (nenhum usuário cadastrado) */
    public function needed(): bool
    {
        try {
            $total = (int) ($this->db->scalar('SELECT COUNT(*) FROM users') ?? 0);
        } catch (\PDOException) {
            return true; // tabela ainda não existe: banco novo
        }

        return $total === 0;
    }

    public function installedRecord(): ?array
    {
        try {
            return $this->db->one('SELECT * FROM installation WHERE id = 1');
        } catch (\PDOException) {
            return null;
        }
    }

    public function tokenRequired(): bool
    {
        return $this->config->installToken !== null;
    }

    public function tokenValid(?string $enviado): bool
    {
        $esperado = (string) $this->config->installToken;

        return $esperado !== '' && is_string($enviado) && hash_equals($esperado, $enviado);
    }

    /**
     * Valida o formulário completo e devolve [dados, erros].
     *
     * @param array<string,mixed> $form
     * @return array{0:array<string,mixed>,1:array<int,string>}
     */
    public function validate(array $form, string $origemDetectada): array
    {
        $erros = [];
        $limpar = static fn (?string $valor, int $limite = 200): string => mb_substr(trim((string) $valor), 0, $limite);

        $agencia = $limpar($form['agency_name'] ?? '');
        $nome = $limpar($form['admin_name'] ?? '');
        $email = mb_strtolower($limpar($form['admin_email'] ?? ''));
        $senha = (string) ($form['admin_password'] ?? '');
        $confirmacao = (string) ($form['admin_password_confirm'] ?? '');
        $publica = $limpar($form['public_origin'] ?? '', 200) ?: $origemDetectada;

        if ($agencia === '') {
            $erros[] = 'Informe o nome da agência.';
        }
        if ($nome === '') {
            $erros[] = 'Informe o nome do administrador.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'Informe um e-mail válido para o administrador.';
        }
        if (mb_strlen($senha) < self::MIN_PASSWORD_LENGTH) {
            $erros[] = 'A senha precisa ter pelo menos ' . self::MIN_PASSWORD_LENGTH . ' caracteres.';
        } elseif (in_array(mb_strtolower($senha), self::SENHAS_OBVIAS, true) || mb_strtolower($senha) === $email) {
            $erros[] = 'Escolha uma senha menos previsível.';
        } elseif ($confirmacao === '') {
            $erros[] = 'Confirme a senha do administrador.';
        } elseif (!hash_equals($senha, $confirmacao)) {
            $erros[] = 'A confirmação da senha não confere.';
        }

        try {
            $origem = self::normalizePublicOrigin($publica);
        } catch (\InvalidArgumentException $erro) {
            $origem = '';
            $erros[] = $erro->getMessage();
        }

        return [[
            'agency_name' => $agencia,
            'admin_name' => $nome,
            'admin_email' => $email,
            'password_hash' => $senha === '' ? '' : self::hashPassword($senha),
            'public_origin' => $origem,
        ], $erros];
    }

    /**
     * Origem pública com caminho opcional (subpasta do cPanel).
     * Espelha installer.validate_public_origin do lado Python.
     */
    public static function normalizePublicOrigin(string $bruto): string
    {
        $bruto = trim($bruto);
        $partes = parse_url($bruto);
        if ($partes === false || empty($partes['scheme']) || empty($partes['host'])) {
            throw new \InvalidArgumentException('Informe a URL pública com esquema e domínio, por exemplo https://app.suaagencia.com.');
        }
        $esquema = strtolower($partes['scheme']);
        if (!in_array($esquema, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('A URL pública deve usar http ou https.');
        }
        if (isset($partes['user']) || isset($partes['pass']) || isset($partes['query']) || isset($partes['fragment'])) {
            throw new \InvalidArgumentException('A URL pública deve ter apenas esquema, domínio e caminho, sem parâmetros ou credenciais.');
        }
        $caminho = rtrim((string) ($partes['path'] ?? ''), '/');
        if ($caminho !== '' && (str_contains($caminho, '..') || str_contains($caminho, ' '))) {
            throw new \InvalidArgumentException('O caminho da URL pública é inválido.');
        }
        $host = $partes['host'];
        if ($esquema !== 'https' && !in_array($host, ['localhost', '127.0.0.1'], true)) {
            throw new \InvalidArgumentException('A URL pública precisa usar HTTPS (HTTP só é aceito em localhost para desenvolvimento).');
        }
        $porta = isset($partes['port']) ? ':' . $partes['port'] : '';

        return $esquema . '://' . $host . $porta . $caminho;
    }

    public static function hashPassword(string $senha): string
    {
        $algoritmo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

        return password_hash($senha, $algoritmo);
    }

    /** Grava rascunho criptografado em disco (0600) e devolve o id. */
    public function createDraft(array $dados): string
    {
        $this->prune();
        if (!is_dir($this->rascunhoDir) && !mkdir($this->rascunhoDir, 0o700, true) && !is_dir($this->rascunhoDir)) {
            throw new \RuntimeException('Não foi possível criar o diretório de rascunhos.');
        }
        $id = bin2hex(random_bytes(12));
        $conteudo = json_encode(['created' => time(), 'data' => $dados], JSON_THROW_ON_ERROR);
        $arquivo = $this->rascunhoDir . '/' . $id . '.json';
        if (file_put_contents($arquivo, $conteudo, LOCK_EX) === false) {
            throw new \RuntimeException('Não foi possível gravar o rascunho da instalação.');
        }
        @chmod($arquivo, 0o600);

        return $id;
    }

    public function loadDraft(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
            return null;
        }
        $arquivo = $this->rascunhoDir . '/' . $id . '.json';
        if (!is_file($arquivo)) {
            return null;
        }
        $conteudo = json_decode((string) file_get_contents($arquivo), true);
        if (!is_array($conteudo) || (time() - (int) ($conteudo['created'] ?? 0)) > self::RASCUNHO_TTL) {
            @unlink($arquivo);

            return null;
        }

        return ['id' => $id, 'data' => $conteudo['data'] ?? []];
    }

    public function dropDraft(string $id): void
    {
        if (preg_match('/^[a-f0-9]{24}$/', $id)) {
            @unlink($this->rascunhoDir . '/' . $id . '.json');
        }
    }

    private function prune(): void
    {
        if (!is_dir($this->rascunhoDir)) {
            return;
        }
        foreach (glob($this->rascunhoDir . '/*.json') ?: [] as $arquivo) {
            if ((time() - (int) filemtime($arquivo)) > self::RASCUNHO_TTL) {
                @unlink($arquivo);
            }
        }
    }

    /**
     * Executa a instalação: segredos, empresa, proprietário e registro.
     *
     * @return array<string,mixed> resumo (sem senha)
     */
    public function install(array $dados): array
    {
        if (!$this->needed()) {
            throw new \RuntimeException('Já existe uma conta nesta instância. O instalador foi encerrado.');
        }

        Schema::migrate($this->db);

        $segredos = $this->mergeSecrets([
            'COOKIE_SECURE' => $this->config->cookieSecure ? '1' : '0',
            'SOCIALMEDIA_PUBLIC_ORIGIN' => $dados['public_origin'],
        ]);

        $this->writeEnvFile($segredos);

        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($dados['agency_name'])) ?: 'agencia';
        $slug = trim(mb_substr($slug, 0, 60), '-') . '-' . bin2hex(random_bytes(3));

        $resultado = $this->db->transaction(function (Database $db) use ($dados, $slug): array {
            if ((int) ($db->scalar('SELECT COUNT(*) FROM users') ?? 0) > 0) {
                throw new \RuntimeException('Já existe uma conta nesta instância. O instalador foi encerrado.');
            }
            $db->run('INSERT INTO companies (name, slug) VALUES (?, ?)', [$dados['agency_name'], $slug]);
            $companyId = (int) $db->pdo()->lastInsertId();
            $db->run(
                'INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)',
                [$companyId, $dados['admin_name'], $dados['admin_email'], $dados['password_hash'], 'owner']
            );
            $userId = (int) $db->pdo()->lastInsertId();
            $db->run(
                'INSERT INTO installation (id, installed_at, agency_name, owner_email, public_origin, php_version)
                 VALUES (1, ?, ?, ?, ?, ?)',
                [gmdate('Y-m-d H:i:s'), $dados['agency_name'], $dados['admin_email'], $dados['public_origin'], PHP_VERSION]
            );
            $db->run(
                'INSERT INTO audit_log (company_id, user_id, action, detail) VALUES (?, ?, ?, ?)',
                [$companyId, $userId, 'installation.completed', 'Instalação pelo navegador (' . PHP_VERSION . ')']
            );

            return ['company_id' => $companyId, 'user_id' => $userId];
        });

        return [
            'agency_name' => $dados['agency_name'],
            'owner_email' => $dados['admin_email'],
            'public_origin' => $dados['public_origin'],
            'env_file' => $this->config->envFile,
            'installed_at' => gmdate('Y-m-d H:i:s'),
            'company_id' => $resultado['company_id'],
        ];
    }

    /** @param array<string,string> $extras */
    private function mergeSecrets(array $extras): array
    {
        $existentes = is_file($this->config->envFile)
            ? \Smia\Support\EnvFile::parse((string) file_get_contents($this->config->envFile))
            : [];
        $valores = array_filter($existentes, static fn ($v) => $v !== '');
        $valores['SECRET_KEY'] = $existentes['SECRET_KEY'] ?? bin2hex(random_bytes(32));

        return array_filter($extras + $valores, static fn ($v) => $v !== '');
    }

    /** @param array<string,string> $valores */
    public function writeEnvFile(array $valores): void
    {
        $diretorio = dirname($this->config->envFile);
        if (!is_dir($diretorio) && !mkdir($diretorio, 0o700, true) && !is_dir($diretorio)) {
            throw new \RuntimeException('Não foi possível criar o diretório de configuração.');
        }
        $linhas = [
            '# Configuração gerada pelo instalador do SocialMedia IA (PHP).',
            '# Contém segredos: mantenha permissão 0600 e inclua em backup seguro.',
            '# Gerado em ' . gmdate('Y-m-d H:i:s') . ' UTC.',
            '',
        ];
        foreach ($valores as $chave => $valor) {
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $chave)) {
                continue;
            }
            $linhas[] = $chave . '=' . str_replace(["\n", "\r"], '', (string) $valor);
        }
        $temporario = $this->config->envFile . '.tmp';
        if (file_put_contents($temporario, implode("\n", $linhas) . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Não foi possível gravar o arquivo de configuração.');
        }
        @chmod($temporario, 0o600);
        if (!rename($temporario, $this->config->envFile)) {
            throw new \RuntimeException('Não foi possível finalizar o arquivo de configuração.');
        }
        @chmod($this->config->envFile, 0o600);
    }

    /** Diagnóstico mostrado na primeira tela do instalador. */
    /** @return array<int,array{label:string,status:string,detail:string}> */
    public function environmentChecks(): array
    {
        $checks = [];
        $checks[] = [
            'label' => 'PHP',
            'status' => PHP_VERSION_ID >= 80000 ? 'ok' : 'fail',
            'detail' => PHP_VERSION . ' (mínimo 8.0)',
        ];
        $extensoes = array_filter(['pdo_sqlite', 'mbstring', 'json', 'openssl'], 'extension_loaded');
        $faltando = array_diff(['pdo_sqlite', 'mbstring', 'json', 'openssl'], $extensoes);
        $checks[] = [
            'label' => 'Extensões',
            'status' => $faltando === [] ? 'ok' : 'fail',
            'detail' => $faltando === [] ? 'pdo_sqlite, mbstring, json, openssl' : 'Faltando: ' . implode(', ', $faltando),
        ];

        $gravavel = is_writable($this->config->dataDir) || (!is_dir($this->config->dataDir) && is_writable(dirname($this->config->dataDir)));
        $checks[] = [
            'label' => 'Pasta de dados',
            'status' => $gravavel ? 'ok' : 'fail',
            'detail' => $this->config->dataDir . ($gravavel ? ' (gravável)' : ' (sem permissão de escrita)'),
        ];

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
        $checks[] = [
            'label' => 'HTTPS',
            'status' => $https ? 'ok' : 'warn',
            'detail' => $https ? 'Conexão segura: cookies sairão com o atributo Secure.' : 'Sem HTTPS confirmado: configure TLS antes de divulgar a instância.',
        ];

        if ($this->config->basePath !== '') {
            $checks[] = [
                'label' => 'Subpasta da aplicação',
                'status' => 'ok',
                'detail' => 'Rotas servidas sob ' . $this->config->basePath . ' (SOCIALMEDIA_BASE_PATH).',
            ];
        }

        $checks[] = [
            'label' => 'Proteção do instalador',
            'status' => $this->tokenRequired() ? 'ok' : 'warn',
            'detail' => $this->tokenRequired()
                ? 'SOCIALMEDIA_INSTALL_TOKEN ativo.'
                : 'Sem SOCIALMEDIA_INSTALL_TOKEN: qualquer pessoa com a URL pode instalar até a conta ser criada.',
        ];

        return $checks;
    }
}
