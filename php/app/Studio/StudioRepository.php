<?php

declare(strict_types=1);

namespace Smia\Studio;

use Smia\Database\Database;

/**
 * Dados do estúdio: artes, versões salvas e itens de feed.
 *
 * Toda consulta filtra por company_id. Criar arte, gerar imagem, exportar e
 * restaurar versão acontecem em transação: a arte e o histórico nunca ficam
 * fora de sincronia.
 */
final class StudioRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Clientes ativos com a identidade visual que a IA precisa. */
    public function clientesAtivos(int $companyId): array
    {
        return $this->db->all(
            "SELECT c.*, b.primary_color, b.secondary_color, b.accent_color, b.logo_path,
                    b.typography, b.visual_style, b.communication_tone, b.slogan,
                    b.graphic_elements, b.brand_manual
             FROM clients c
             LEFT JOIN client_branding b ON b.client_id = c.id
             WHERE c.company_id = ? AND c.status = 'active'
             ORDER BY c.company_name COLLATE NOCASE",
            [$companyId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function artes(int $companyId, int $limite = 40): array
    {
        return $this->db->all(
            "SELECT cp.*, c.company_name, c.trade_name,
                    (SELECT COUNT(*) FROM post_versions pv WHERE pv.creative_id = cp.id) AS version_count
             FROM creative_posts cp
             JOIN clients c ON c.id = cp.client_id AND c.company_id = cp.company_id
             WHERE cp.company_id = ?
             ORDER BY cp.created_at DESC, cp.id DESC
             LIMIT ?",
            [$companyId, $limite]
        );
    }

    public function arte(int $id, int $companyId): ?array
    {
        return $this->db->one(
            "SELECT cp.*, c.company_name, c.trade_name, c.segment, c.description, c.products_services,
                    c.audience, c.business_goals,
                    b.primary_color, b.secondary_color, b.accent_color, b.typography, b.visual_style,
                    b.communication_tone, b.slogan, b.graphic_elements, b.brand_manual, b.logo_path
             FROM creative_posts cp
             JOIN clients c ON c.id = cp.client_id AND c.company_id = cp.company_id
             LEFT JOIN client_branding b ON b.client_id = c.id
             WHERE cp.id = ? AND cp.company_id = ?",
            [$id, $companyId]
        );
    }

    /**
     * Cria a arte e a primeira versão do histórico.
     *
     * @param array<string,string> $conteudo
     * @param array{elements:list<array<string,mixed>>} $layout
     */
    public function criar(
        int $companyId,
        int $clientId,
        int $userId,
        string $assunto,
        string $formato,
        int $largura,
        int $altura,
        array $conteudo,
        array $layout,
        string $corFundo,
        string $promptImagem,
        string $modelo,
        int $tokensEntrada,
        int $tokensSaida,
        string $rotuloVersao = 'Texto e layout gerados',
    ): int {
        return (int) $this->db->transaction(function () use (
            $companyId, $clientId, $userId, $assunto, $formato, $largura, $altura,
            $conteudo, $layout, $corFundo, $promptImagem, $modelo, $tokensEntrada, $tokensSaida, $rotuloVersao
        ): int {
            $this->db->run(
                'INSERT INTO creative_posts
                    (company_id, client_id, created_by, subject, format_key, canvas_width, canvas_height,
                     content_json, layout_json, background_color, image_prompt)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $companyId, $clientId, $userId, $assunto, $formato, $largura, $altura,
                    json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $corFundo, $promptImagem,
                ]
            );
            $id = (int) $this->db->pdo()->lastInsertId();
            $this->registrarConsumo($companyId, $clientId, $userId, 'studio_copy', $modelo, $tokensEntrada, $tokensSaida);
            $this->gravarVersao($id, $companyId, $userId, $rotuloVersao);

            return $id;
        });
    }

    /**
     * Atualiza conteúdo/layout/imagem e guarda uma versão do que ficou.
     *
     * @param array<string,mixed> $campos colunas já validadas
     */
    public function atualizar(int $id, int $companyId, int $userId, array $campos, string $rotuloVersao): void
    {
        $permitidos = ['content_json', 'layout_json', 'background_color', 'image_prompt', 'image_path', 'export_path', 'status'];
        $sets = [];
        $valores = [];
        foreach ($permitidos as $coluna) {
            if (array_key_exists($coluna, $campos)) {
                $sets[] = $coluna . ' = ?';
                $valores[] = $campos[$coluna];
            }
        }
        if ($sets === []) {
            return;
        }
        $sets[] = "updated_at = datetime('now')";
        $valores[] = $id;
        $valores[] = $companyId;

        $this->db->transaction(function () use ($sets, $valores, $id, $companyId, $userId, $rotuloVersao): void {
            $this->db->run(
                'UPDATE creative_posts SET ' . implode(', ', $sets) . ' WHERE id = ? AND company_id = ?',
                $valores
            );
            $this->gravarVersao($id, $companyId, $userId, $rotuloVersao);
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function versoes(int $creativeId, int $companyId, int $limite = 12): array
    {
        return $this->db->all(
            'SELECT id, version_label, created_at FROM post_versions
             WHERE creative_id = ? AND company_id = ?
             ORDER BY id DESC LIMIT ?',
            [$creativeId, $companyId, $limite]
        );
    }

    public function versao(int $versaoId, int $creativeId, int $companyId): ?array
    {
        return $this->db->one(
            'SELECT * FROM post_versions WHERE id = ? AND creative_id = ? AND company_id = ?',
            [$versaoId, $creativeId, $companyId]
        );
    }

    /** Restaura a versão e registra o próprio restauro no histórico. */
    public function restaurar(int $creativeId, int $companyId, int $userId, array $versao): void
    {
        $this->db->transaction(function () use ($creativeId, $companyId, $userId, $versao): void {
            $this->db->run(
                "UPDATE creative_posts
                 SET content_json = ?, layout_json = ?, background_color = ?, image_path = ?, export_path = ?,
                     status = ?, updated_at = datetime('now')
                 WHERE id = ? AND company_id = ?",
                [
                    $versao['content_json'], $versao['layout_json'], $versao['background_color'],
                    $versao['image_path'], $versao['export_path'],
                    $versao['export_path'] ? 'exported' : 'draft',
                    $creativeId, $companyId,
                ]
            );
            $this->gravarVersao($creativeId, $companyId, $userId, 'Restaurado: ' . (string) $versao['version_label']);
        });
    }

    /**
     * Arquivos da arte (atual e das versões) — usados para apagar do disco
     * depois que a linha some do banco.
     *
     * @return list<string>
     */
    public function arquivos(int $creativeId, int $companyId): array
    {
        $linhas = $this->db->all(
            'SELECT image_path AS caminho FROM creative_posts WHERE id = ? AND company_id = ?
             UNION
             SELECT export_path AS caminho FROM creative_posts WHERE id = ? AND company_id = ?
             UNION
             SELECT image_path AS caminho FROM post_versions WHERE creative_id = ? AND company_id = ?
             UNION
             SELECT export_path AS caminho FROM post_versions WHERE creative_id = ? AND company_id = ?',
            [$creativeId, $companyId, $creativeId, $companyId, $creativeId, $companyId, $creativeId, $companyId]
        );
        $arquivos = [];
        foreach ($linhas as $linha) {
            $caminho = (string) ($linha['caminho'] ?? '');
            if ($caminho !== '' && !in_array($caminho, $arquivos, true)) {
                $arquivos[] = $caminho;
            }
        }

        return $arquivos;
    }

    public function remover(int $creativeId, int $companyId): void
    {
        $this->db->run('DELETE FROM creative_posts WHERE id = ? AND company_id = ?', [$creativeId, $companyId]);
    }

    /** O arquivo pertence a alguma arte desta empresa? (serve por URL) */
    public function arquivoDaEmpresa(string $nome, int $companyId): bool
    {
        $achado = $this->db->scalar(
            'SELECT 1 FROM creative_posts WHERE company_id = ? AND (image_path = ? OR export_path = ?)
             UNION
             SELECT 1 FROM post_versions WHERE company_id = ? AND (image_path = ? OR export_path = ?)
             LIMIT 1',
            [$companyId, $nome, $nome, $companyId, $nome, $nome]
        );

        return $achado !== null && $achado !== false;
    }

    public function energiaIa(int $companyId): int
    {
        return (int) ($this->db->scalar(
            "SELECT COUNT(*) FROM ai_generations WHERE company_id = ? AND feature LIKE 'studio_%'",
            [$companyId]
        ) ?? 0);
    }

    public function contarArtes(int $companyId): int
    {
        return (int) ($this->db->scalar('SELECT COUNT(*) FROM creative_posts WHERE company_id = ?', [$companyId]) ?? 0);
    }

    public function contarExportadas(int $companyId): int
    {
        return (int) ($this->db->scalar("SELECT COUNT(*) FROM creative_posts WHERE company_id = ? AND status = 'exported'", [$companyId]) ?? 0);
    }

    public function auditoria(int $companyId, int $userId, string $action, string $detail): void
    {
        // Mesmo formato do planejamento: audit_log guarda detail (sem entity).
        $this->db->run(
            'INSERT INTO audit_log (company_id, user_id, action, detail) VALUES (?, ?, ?, ?)',
            [$companyId, $userId, $action, mb_substr($detail, 0, 500)]
        );
    }

    public function registrarConsumo(int $companyId, int $clientId, int $userId, string $feature, string $modelo, int $entrada, int $saida): void
    {
        $this->db->run(
            'INSERT INTO ai_generations (company_id, client_id, user_id, feature, model, input_tokens, output_tokens)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$companyId, $clientId, $userId, $feature, mb_substr($modelo, 0, 60), max(0, $entrada), max(0, $saida)]
        );
    }

    /** Cópia do estado atual no histórico de versões. */
    private function gravarVersao(int $creativeId, int $companyId, int $userId, string $rotulo): void
    {
        $arte = $this->db->one(
            'SELECT content_json, layout_json, background_color, image_path, export_path
             FROM creative_posts WHERE id = ? AND company_id = ?',
            [$creativeId, $companyId]
        );
        if ($arte === null) {
            return;
        }
        $this->db->run(
            'INSERT INTO post_versions
                (creative_id, company_id, user_id, content_json, layout_json, background_color, image_path, export_path, version_label)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $creativeId, $companyId, $userId, $arte['content_json'], $arte['layout_json'],
                $arte['background_color'], $arte['image_path'], $arte['export_path'],
                mb_substr($rotulo, 0, 100),
            ]
        );
    }
}
