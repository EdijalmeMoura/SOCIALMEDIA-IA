<?php

declare(strict_types=1);

namespace Smia\Clients;

use Smia\Database\Database;

/**
 * Regras de dados dos clientes (multi-tenant).
 *
 * Toda consulta filtra por company_id: um id de outro workspace nunca é
 * encontrado, então nada vaza entre agências mesmo que alguém adivinhe a URL.
 */
final class ClientRepository
{
    /** Campos do perfil do negócio, na ordem do formulário. */
    public const CAMPOS = [
        'company_name', 'trade_name', 'segment', 'description', 'products_services',
        'audience', 'address', 'phone', 'whatsapp', 'website', 'instagram', 'facebook',
        'business_goals',
        // v1.20: rede informada no cadastro e endereço do perfil analisado.
        'social_network', 'profile_url',
    ];

    /** Campos com limite maior (textos longos). */
    private const CAMPOS_LONGOS = ['description', 'products_services', 'audience', 'business_goals'];
    public const CORES_PADRAO = ['#3158d8', '#f57a45', '#16a68a'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function forCompany(int $companyId): array
    {
        return $this->db->all(
            'SELECT c.*, b.primary_color, b.secondary_color, b.accent_color, b.logo_path,
                    (SELECT COUNT(*) FROM client_assets a WHERE a.client_id = c.id) AS asset_count
             FROM clients c
             LEFT JOIN client_branding b ON b.client_id = c.id
             WHERE c.company_id = ?
             ORDER BY c.company_name COLLATE NOCASE',
            [$companyId]
        );
    }

    /** Cliente + identidade visual, sempre limitado à empresa. */
    public function find(int $id, int $companyId): ?array
    {
        return $this->db->one(
            'SELECT c.*, b.logo_path, b.primary_color, b.secondary_color, b.accent_color,
                    b.typography, b.visual_style, b.communication_tone, b.slogan,
                    b.graphic_elements, b.brand_manual
             FROM clients c
             LEFT JOIN client_branding b ON b.client_id = c.id
             WHERE c.id = ? AND c.company_id = ?',
            [$id, $companyId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function assets(int $clientId): array
    {
        return $this->db->all(
            'SELECT id, filename, original_name, created_at FROM client_assets WHERE client_id = ? ORDER BY id DESC',
            [$clientId]
        );
    }

    /** Contagem por status, para os indicadores da listagem. */
    public function summary(int $companyId): array
    {
        $linha = $this->db->one(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS ativos
             FROM clients WHERE company_id = ?",
            [$companyId]
        );

        return ['total' => (int) ($linha['total'] ?? 0), 'ativos' => (int) ($linha['ativos'] ?? 0)];
    }

    /**
     * Normaliza os campos do formulário (trim + limite), igual ao Python.
     *
     * @param array<string,mixed> $entrada normalmente $_POST
     * @return array<string,string>
     */
    public static function normalize(array $entrada): array
    {
        $dados = [];
        foreach (self::CAMPOS as $campo) {
            $valor = $entrada[$campo] ?? '';
            $valor = is_scalar($valor) ? trim((string) $valor) : '';
            $limite = in_array($campo, self::CAMPOS_LONGOS, true) ? 4000 : 500;
            if ($campo === 'company_name') {
                $limite = 200;
            }
            $dados[$campo] = mb_substr($valor, 0, $limite);
        }

        return $dados;
    }

    /** @param array<string,string> $dados já normalizados */
    public function validate(array $dados): array
    {
        $erros = [];
        if ($dados['company_name'] === '') {
            $erros[] = 'O nome da empresa é obrigatório.';
        }
        if ($dados['website'] !== '' && !preg_match('#^https?://#i', $dados['website'])) {
            $erros[] = 'O site deve começar com http:// ou https://.';
        }

        return $erros;
    }

    /** Cria o cliente + registro de identidade visual numa única transação. */
    public function create(int $companyId, array $dados): int
    {
        return $this->db->transaction(function (Database $db) use ($companyId, $dados): int {
            $colunas = implode(', ', array_keys($dados));
            $marcadores = implode(', ', array_fill(0, count($dados), '?'));
            $db->run(
                "INSERT INTO clients (company_id, {$colunas}) VALUES (?, {$marcadores})",
                [$companyId, ...array_values($dados)]
            );
            $clientId = (int) $db->pdo()->lastInsertId();
            $db->run('INSERT INTO client_branding (client_id) VALUES (?)', [$clientId]);

            return $clientId;
        });
    }

    /** @param array<string,string> $dados */
    public function update(int $id, int $companyId, array $dados): void
    {
        $atribuicoes = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($dados)));
        $this->db->run(
            "UPDATE clients SET {$atribuicoes}, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND company_id = ?",
            [...array_values($dados), $id, $companyId]
        );
    }

    /**
     * Atualiza a identidade visual. Cores inválidas caem no padrão.
     *
     * @param array<string,mixed> $entrada $_POST
     */
    public function updateBranding(int $clientId, array $entrada): void
    {
        $cores = [];
        foreach (['primary_color', 'secondary_color', 'accent_color'] as $indice => $campo) {
            $valor = trim((string) ($entrada[$campo] ?? ''));
            $cores[] = self::corValida($valor) ? $valor : self::CORES_PADRAO[$indice];
        }

        $textos = [];
        foreach (['typography' => 250, 'visual_style' => 2000, 'communication_tone' => 2000, 'slogan' => 500, 'graphic_elements' => 2000, 'brand_manual' => 4000] as $campo => $limite) {
            $valor = $entrada[$campo] ?? '';
            $textos[] = mb_substr(is_scalar($valor) ? trim((string) $valor) : '', 0, $limite);
        }

        if ($this->db->scalar('SELECT COUNT(*) FROM client_branding WHERE client_id = ?', [$clientId]) === 0) {
            $this->db->run('INSERT INTO client_branding (client_id) VALUES (?)', [$clientId]);
        }
        $this->db->run(
            'UPDATE client_branding SET primary_color = ?, secondary_color = ?, accent_color = ?,
                    typography = ?, visual_style = ?, communication_tone = ?, slogan = ?,
                    graphic_elements = ?, brand_manual = ?, updated_at = CURRENT_TIMESTAMP
             WHERE client_id = ?',
            [...$cores, ...$textos, $clientId]
        );
    }

    public function setLogo(int $clientId, string $filename): ?string
    {
        $anterior = $this->db->scalar('SELECT logo_path FROM client_branding WHERE client_id = ?', [$clientId]);
        if ($this->db->scalar('SELECT COUNT(*) FROM client_branding WHERE client_id = ?', [$clientId]) === 0) {
            $this->db->run('INSERT INTO client_branding (client_id) VALUES (?)', [$clientId]);
        }
        $this->db->run(
            'UPDATE client_branding SET logo_path = ?, updated_at = CURRENT_TIMESTAMP WHERE client_id = ?',
            [$filename, $clientId]
        );

        return $anterior === false || $anterior === null ? null : (string) $anterior;
    }

    public function addAsset(int $clientId, string $filename, string $originalName): int
    {
        $this->db->run(
            'INSERT INTO client_assets (client_id, filename, original_name) VALUES (?, ?, ?)',
            [$clientId, $filename, $originalName]
        );

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function findAsset(int $assetId, int $clientId): ?array
    {
        return $this->db->one('SELECT * FROM client_assets WHERE id = ? AND client_id = ?', [$assetId, $clientId]);
    }

    public function deleteAsset(int $assetId, int $clientId): void
    {
        $this->db->run('DELETE FROM client_assets WHERE id = ? AND client_id = ?', [$assetId, $clientId]);
    }

    /** Exclui o cliente (cascade leva branding e assets). Devolve arquivos a apagar. */
    public function delete(int $id, int $companyId): array
    {
        $arquivos = $this->db->all(
            'SELECT a.filename FROM client_assets a JOIN clients c ON c.id = a.client_id
             WHERE a.client_id = ? AND c.company_id = ?
             UNION
             SELECT b.logo_path AS filename FROM client_branding b JOIN clients c ON c.id = b.client_id
             WHERE b.client_id = ? AND c.company_id = ? AND b.logo_path IS NOT NULL',
            [$id, $companyId, $id, $companyId]
        );
        $this->db->run('DELETE FROM clients WHERE id = ? AND company_id = ?', [$id, $companyId]);

        return array_values(array_filter(array_map(static fn (array $a): string => (string) $a['filename'], $arquivos)));
    }

    /**
     * O arquivo é logotipo ou referência de um cliente DESTA empresa?
     * É a checagem que impede servir imagem de outro workspace pela URL.
     */
    public function fileBelongsToCompany(string $filename, int $companyId): bool
    {
        $achado = $this->db->scalar(
            'SELECT 1 FROM client_branding b JOIN clients c ON c.id = b.client_id
             WHERE b.logo_path = ? AND c.company_id = ?
             UNION ALL
             SELECT 1 FROM client_assets a JOIN clients c ON c.id = a.client_id
             WHERE a.filename = ? AND c.company_id = ?
             LIMIT 1',
            [$filename, $companyId, $filename, $companyId]
        );

        return $achado !== null;
    }

    /** Ao menos um cliente cadastrado? (usado nos atalhos do dashboard) */
    public function count(int $companyId): int
    {
        return (int) ($this->db->scalar('SELECT COUNT(*) FROM clients WHERE company_id = ?', [$companyId]) ?? 0);
    }

    /** Registra o evento na auditoria local. */
    public function audit(int $companyId, int $userId, string $action, string $detail): void
    {
        $this->db->run(
            'INSERT INTO audit_log (company_id, user_id, action, detail) VALUES (?, ?, ?, ?)',
            [$companyId, $userId, $action, mb_substr($detail, 0, 500)]
        );
    }

    public static function corValida(string $valor): bool
    {
        return (bool) preg_match('/^#[0-9a-f]{6}$/i', $valor);
    }
}
