<?php

declare(strict_types=1);

namespace Smia\Feed;

use Smia\Database\Database;
use Smia\Studio\Layout;

/**
 * Prévia do feed: a grade 3×3 do Instagram montada a partir das artes do
 * estúdio e das pautas do planejamento.
 *
 * Três decisões que sustentam o módulo:
 *  - a grade é **interna**: nada é agendado nem publicado, é uma prévia para
 *    conversar com o cliente;
 *  - a ordem é do usuário, não da data: cada item tem `position`, refeita a
 *    cada reorganização;
 *  - reordenar é tudo ou nada. Se a lista mudou entre a tela e o clique, a
 *    gravação é recusada (409) em vez de embaralhar o que outra pessoa mexeu.
 */
final class FeedRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Clientes ativos (a prévia é por marca). */
    public function clientes(int $companyId): array
    {
        return $this->db->all(
            "SELECT id, company_name, trade_name, segment, instagram
             FROM clients
             WHERE company_id = ? AND status = 'active'
             ORDER BY company_name COLLATE NOCASE",
            [$companyId]
        );
    }

    public function cliente(int $clientId, int $companyId): ?array
    {
        return $this->db->one(
            "SELECT * FROM clients WHERE id = ? AND company_id = ? AND status = 'active'",
            [$clientId, $companyId]
        );
    }

    /** Identidade visual da marca (cor, logo, tom) para vestir a prévia. */
    public function marca(int $clientId): array
    {
        return $this->db->one('SELECT * FROM client_branding WHERE client_id = ?', [$clientId]) ?? [];
    }

    /**
     * Itens da grade na ordem salva.
     *
     * O LEFT JOIN é proposital: se a arte for excluída no estúdio, a linha do
     * feed continua (com os dados vazios) e a tela avisa que a origem sumiu —
     * melhor do que a publicação desaparecer sem explicação.
     *
     * @return array<int,array<string,mixed>>
     */
    public function itens(int $clientId, int $companyId): array
    {
        $linhas = $this->db->all(
            'SELECT fi.id AS feed_id, fi.position, fi.creative_id, fi.planned_post_id,
                    cp.subject, cp.format_key, cp.status AS creative_status, cp.image_path,
                    cp.export_path, cp.content_json,
                    p.theme, p.publication_date, p.network, p.status AS post_status, p.caption,
                    p.hashtags, p.content_type, p.visual_brief, p.calendar_id,
                    cc.title AS plan_title
             FROM feed_items fi
             LEFT JOIN creative_posts cp ON cp.id = fi.creative_id AND cp.company_id = fi.company_id
             LEFT JOIN posts p ON p.id = fi.planned_post_id AND p.company_id = fi.company_id
             LEFT JOIN content_calendar cc ON cc.id = p.calendar_id AND cc.company_id = fi.company_id
             WHERE fi.company_id = ? AND fi.client_id = ?
             ORDER BY fi.position, fi.id',
            [$companyId, $clientId]
        );

        return array_map(fn (array $linha): array => $this->descrever($linha), $linhas);
    }

    /**
     * Traduz a linha crua para o que a tela precisa: título, legenda, imagem,
     * status legível. É aqui que arte e pauta ficam com o mesmo formato.
     *
     * @param array<string,mixed> $linha
     * @return array<string,mixed>
     */
    private function descrever(array $linha): array
    {
        if ($linha['creative_id'] !== null && $linha['subject'] === null) {
            return $linha + [
                'item_type' => 'creative',
                'title' => 'Arte removida do estúdio',
                'caption' => '',
                'hashtags' => '',
                'network' => 'Arte',
                'publish_date' => null,
                'status_label' => 'Origem removida',
                'media_path' => null,
                'is_exported' => false,
                'missing' => true,
            ];
        }
        if ($linha['planned_post_id'] !== null && $linha['theme'] === null) {
            return $linha + [
                'item_type' => 'planned',
                'title' => 'Pauta removida do planejamento',
                'caption' => '',
                'hashtags' => '',
                'network' => 'Planejamento',
                'publish_date' => null,
                'status_label' => 'Origem removida',
                'media_path' => null,
                'is_exported' => false,
                'missing' => true,
            ];
        }

        if ($linha['creative_id'] !== null) {
            $conteudo = json_decode((string) $linha['content_json'], true);
            $conteudo = is_array($conteudo) ? $conteudo : [];
            $exportado = !empty($linha['export_path']);
            $comImagem = !empty($linha['image_path']);

            return $linha + [
                'item_type' => 'creative',
                'title' => (string) $linha['subject'],
                'caption' => (string) ($conteudo['caption'] ?? ''),
                'hashtags' => (string) ($conteudo['hashtags'] ?? ''),
                'network' => Layout::rotulo((string) $linha['format_key']),
                'publish_date' => null,
                'status_label' => $exportado
                    ? 'Arte exportada'
                    : ($comImagem ? 'Rascunho · imagem pronta' : 'Rascunho · sem imagem'),
                'media_path' => $exportado ? (string) $linha['export_path'] : ($comImagem ? (string) $linha['image_path'] : null),
                'is_exported' => $exportado,
                'missing' => false,
            ];
        }

        return $linha + [
            'item_type' => 'planned',
            'title' => (string) $linha['theme'],
            'caption' => (string) ($linha['caption'] ?? ''),
            'hashtags' => (string) ($linha['hashtags'] ?? ''),
            'publish_date' => (string) ($linha['publication_date'] ?? ''),
            'status_label' => 'Planejada · sem arte',
            'media_path' => null,
            'is_exported' => false,
            'missing' => false,
        ];
    }

    /** Artes desta marca que ainda não estão na grade. */
    public function artesDisponiveis(int $clientId, int $companyId): array
    {
        return $this->db->all(
            'SELECT cp.id, cp.subject, cp.format_key, cp.status, cp.created_at,
                    (cp.export_path IS NOT NULL) AS exported
             FROM creative_posts cp
             WHERE cp.company_id = ? AND cp.client_id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM feed_items fi
                   WHERE fi.company_id = cp.company_id AND fi.client_id = cp.client_id
                     AND fi.creative_id = cp.id
               )
             ORDER BY cp.created_at DESC, cp.id DESC',
            [$companyId, $clientId]
        );
    }

    /** Pautas desta marca que ainda não estão na grade. */
    public function postsDisponiveis(int $clientId, int $companyId): array
    {
        return $this->db->all(
            'SELECT p.id, p.theme, p.publication_date, p.network, p.content_type, cc.title AS plan_title
             FROM posts p
             JOIN content_calendar cc ON cc.id = p.calendar_id AND cc.company_id = p.company_id
             WHERE p.company_id = ? AND p.client_id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM feed_items fi
                   WHERE fi.company_id = p.company_id AND fi.client_id = p.client_id
                     AND fi.planned_post_id = p.id
               )
             ORDER BY p.publication_date, p.position, p.id',
            [$companyId, $clientId]
        );
    }

    /**
     * Adiciona uma arte ao fim da grade.
     *
     * @throws FeedError quando a arte é de outra marca, não existe ou já está na grade
     */
    public function adicionarArte(int $clientId, int $creativeId, int $companyId): void
    {
        $arte = $this->db->one(
            'SELECT id, client_id FROM creative_posts WHERE id = ? AND company_id = ?',
            [$creativeId, $companyId]
        );
        if ($arte === null || (int) $arte['client_id'] !== $clientId) {
            throw new FeedNotFound('Essa arte não pertence a esta marca.');
        }
        $this->inserir($clientId, $companyId, 'creative_id', $creativeId);
    }

    /** @throws FeedError */
    public function adicionarPost(int $clientId, int $postId, int $companyId): void
    {
        $post = $this->db->one(
            'SELECT id, client_id FROM posts WHERE id = ? AND company_id = ?',
            [$postId, $companyId]
        );
        if ($post === null || (int) $post['client_id'] !== $clientId) {
            throw new FeedNotFound('Essa pauta não pertence a esta marca.');
        }
        $this->inserir($clientId, $companyId, 'planned_post_id', $postId);
    }

    private function inserir(int $clientId, int $companyId, string $coluna, int $sourceId): void
    {
        $posicao = (int) ($this->db->scalar(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM feed_items WHERE company_id = ? AND client_id = ?',
            [$companyId, $clientId]
        ) ?? 1);

        try {
            $this->db->run(
                "INSERT INTO feed_items (company_id, client_id, {$coluna}, position) VALUES (?, ?, ?, ?)",
                [$companyId, $clientId, $sourceId, $posicao]
            );
        } catch (\PDOException $erro) {
            // Índice único da origem: dois cliques no "+" não duplicam a linha.
            if (str_contains($erro->getMessage(), 'UNIQUE')) {
                throw new FeedError('Esse conteúdo já está na prévia.');
            }
            throw $erro;
        }
    }

    /** Remove o item da grade (o conteúdo original continua existindo). */
    public function remover(int $clientId, int $feedId, int $companyId): bool
    {
        $removidos = $this->db->run(
            'DELETE FROM feed_items WHERE id = ? AND client_id = ? AND company_id = ?',
            [$feedId, $clientId, $companyId]
        )->rowCount();

        return $removidos === 1;
    }

    /** @return list<int> ids na ordem salva */
    public function ordemAtual(int $clientId, int $companyId): array
    {
        $linhas = $this->db->all(
            'SELECT id FROM feed_items WHERE company_id = ? AND client_id = ? ORDER BY position, id',
            [$companyId, $clientId]
        );

        return array_map(static fn (array $linha): int => (int) $linha['id'], $linhas);
    }

    /**
     * Grava a nova ordem.
     *
     * A lista enviada tem que ser exatamente a lista atual — mesma quantidade,
     * mesmos ids, sem repetição. Qualquer diferença significa que a tela está
     * velha, e a resposta é "atualize" em vez de adivinhar.
     *
     * @param array<int,int> $ordem
     * @throws FeedConflict
     */
    public function reordenar(int $clientId, int $companyId, array $ordem): void
    {
        if ($ordem === [] || count($ordem) > 500) {
            throw new FeedConflict('A ordem enviada é inválida.');
        }
        foreach ($ordem as $id) {
            if ($id <= 0) {
                throw new FeedConflict('A ordem enviada é inválida.');
            }
        }
        $atuais = $this->ordemAtual($clientId, $companyId);
        sort($atuais);
        $enviados = $ordem;
        sort($enviados);
        if ($atuais !== $enviados) {
            throw new FeedConflict('A lista do feed mudou. Atualize a página antes de reorganizar.');
        }

        $this->db->transaction(function () use ($ordem, $clientId, $companyId): void {
            $posicao = 0;
            foreach ($ordem as $id) {
                $posicao++;
                $this->db->run(
                    'UPDATE feed_items SET position = ? WHERE id = ? AND company_id = ? AND client_id = ?',
                    [$posicao, $id, $companyId, $clientId]
                );
            }
        });
    }

    public function contar(int $companyId): int
    {
        return (int) ($this->db->scalar('SELECT COUNT(*) FROM feed_items WHERE company_id = ?', [$companyId]) ?? 0);
    }

    public function auditoria(int $companyId, int $userId, string $acao, string $detalhe): void
    {
        $this->db->run(
            'INSERT INTO audit_log (company_id, user_id, action, detail) VALUES (?, ?, ?, ?)',
            [$companyId, $userId, $acao, mb_substr($detalhe, 0, 500)]
        );
    }
}
