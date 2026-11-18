<?php

declare(strict_types=1);

namespace Smia\Meta;

use Smia\Approvals\PortalRepository;
use Smia\Database\Database;

/**
 * O caminho de uma publicação real: validações, tentativa, chamada, resultado.
 *
 * A ordem importa. Tudo o que pode ser recusado sem falar com a Meta é
 * recusado antes: rede, horário vencido, aprovação, permissão, imagem. Só
 * depois de passar por tudo isso a tentativa é aberta — porque tentativa
 * aberta trava o horário.
 *
 * Depois do envio, três desfechos possíveis:
 *  - publicado (ID remoto guardado);
 *  - falhou (a Meta respondeu que não);
 *  - incerto (sem resposta confiável) — e aí ninguém tenta de novo até uma
 *    pessoa conferir a conta na Meta.
 */
final class Publisher
{
    public function __construct(
        private readonly Database $db,
        private readonly ScheduleRepository $agenda,
        private readonly MetaRepository $repo,
        private readonly PortalRepository $avisos,
        private readonly string $uploadsDir,
    ) {
    }

    /**
     * Publica o horário escolhido numa Página conectada.
     *
     * @return array{status:string,remote_id:?string,mensagem:string}
     * @throws MetaError com mensagem pronta para a tela
     */
    public function publicar(
        int $companyId,
        int $userId,
        int $scheduleId,
        int $connectionId,
        MetaSettings $settings,
        Graph $graph,
        MediaUrl $midia,
    ): array {
        $agenda = $this->agenda->ativo($scheduleId, $companyId);
        if ($agenda === null || (string) $agenda['status'] !== 'scheduled') {
            throw new MetaError('Este horário não está mais ativo.');
        }
        if ((string) $agenda['approval_status'] !== 'approved') {
            throw new MetaError('Só é possível publicar o ciclo aprovado pelo cliente.');
        }
        if (in_array($agenda['meta_status'] ?? null, ['publishing', 'published', 'unknown'], true)) {
            throw new MetaError('Já existe uma tentativa ativa, concluída ou incerta para este horário.');
        }
        $rede = (string) $agenda['network'];
        if (!in_array($rede, ['Facebook', 'Instagram'], true)) {
            throw new MetaError('Esta etapa publica apenas em Páginas do Facebook e contas profissionais do Instagram.');
        }
        $quando = strtotime((string) $agenda['scheduled_at_utc'] . ' UTC');
        if ($quando === false) {
            throw new MetaError('O horário salvo não pôde ser interpretado.');
        }
        if ($quando > time()) {
            throw new MetaError('Este horário ainda não chegou. A publicação só pode ser iniciada quando estiver no horário ou após ele.');
        }

        $conexao = $this->repo->conexao($connectionId, $companyId);
        if ($conexao === null) {
            throw new MetaError('Escolha uma Página Meta conectada a esta agência.');
        }
        if ($rede === 'Instagram' && trim((string) $conexao['instagram_user_id']) === '') {
            throw new MetaError('A Página selecionada não possui uma conta profissional do Instagram vinculada.');
        }
        $this->conferirPermissoes($companyId, $connectionId, $rede);

        $fotografia = json_decode((string) $agenda['snapshot_json'], true);
        $fotografia = is_array($fotografia) ? $fotografia : [];
        [$legenda, $imagemAprovada] = self::conteudo($fotografia);

        if ($rede === 'Instagram' && $imagemAprovada === null) {
            throw new MetaError('O Instagram exige uma arte exportada; publique uma criação visual aprovada.');
        }

        $temporario = null;
        $url = null;
        try {
            if ($imagemAprovada !== null) {
                $caminho = rtrim($this->uploadsDir, '/') . '/' . $imagemAprovada;
                if (!$midia->nomeSeguro($imagemAprovada) || !is_file($caminho)) {
                    throw new MetaError('O arquivo exportado desta aprovação não está disponível.');
                }
                if ($rede === 'Instagram') {
                    $temporario = InstagramImage::preparar($caminho, $this->uploadsDir);
                    $url = $midia->para($temporario);
                } else {
                    $url = $midia->para($imagemAprovada);
                }
            }

            try {
                $token = (new TokenCipher($settings->chaveTokens))->decifrar((string) $conexao['page_access_token_encrypted']);
            } catch (MetaError $erro) {
                throw $erro;
            }

            // A tentativa é registrada ANTES da chamada: se o servidor cair no
            // meio, o horário fica travado em "publicando" — e não livre para
            // alguém publicar de novo sem saber o que aconteceu.
            $attemptId = $this->repo->abrirTentativa($companyId, $scheduleId, $connectionId, $userId, $rede);

            try {
                if ($rede === 'Instagram') {
                    $remoteId = $graph->publishInstagram((string) $conexao['instagram_user_id'], $token, (string) $url, $legenda);
                } elseif ($url !== null) {
                    $remoteId = $graph->publishFacebookPhoto((string) $conexao['page_id'], $token, $url, $legenda);
                } else {
                    $remoteId = $graph->publishFacebookText((string) $conexao['page_id'], $token, $legenda);
                }
            } catch (MetaError $erro) {
                // Quando a resposta não veio, a tela precisa dizer "incerto" —
                // e não "falhou" — porque repetir pode duplicar a publicação.
                $mensagem = $erro->incerta
                    ? 'Resultado incerto: a Meta não confirmou a publicação. Confira a conta antes de tentar novamente. ' . $erro->getMessage()
                    : $erro->getMessage();
                $this->repo->falharTentativa($attemptId, $companyId, $erro->incerta, $mensagem);
                $this->avisos->avisarAgencia(
                    $companyId,
                    $erro->incerta ? 'Resultado da publicação incerto' : 'Publicação Meta não confirmada',
                    $agenda['approval_title'] . ' · ' . $rede . ' · ' . $mensagem,
                    '/meta/publication'
                );

                return ['status' => $erro->incerta ? 'unknown' : 'failed', 'remote_id' => null, 'mensagem' => $mensagem];
            } catch (\Throwable $erro) {
                // Qualquer surpresa depois do envio é incerta por definição.
                error_log('[SMIA] Meta publicação: ' . $erro::class . ': ' . $erro->getMessage());
                $mensagem = 'A resposta da Meta não pôde ser confirmada. Confira a conta antes de repetir para evitar uma publicação duplicada.';
                $this->repo->falharTentativa($attemptId, $companyId, true, $mensagem);
                $this->avisos->avisarAgencia(
                    $companyId,
                    'Resultado da publicação incerto',
                    $agenda['approval_title'] . ' · ' . $rede . ' · ' . $mensagem,
                    '/meta/publication'
                );

                return ['status' => 'unknown', 'remote_id' => null, 'mensagem' => $mensagem];
            }

            $this->repo->concluirTentativa($attemptId, $companyId, $remoteId);
            $this->avisos->avisarAgencia(
                $companyId,
                'Publicação confirmada pela Meta',
                $agenda['approval_title'] . ' · ' . $rede . ' · ID ' . mb_substr($remoteId, 0, 180),
                '/meta/publication'
            );

            return ['status' => 'published', 'remote_id' => $remoteId, 'mensagem' => 'A Meta confirmou a publicação.'];
        } finally {
            InstagramImage::remover($temporario, $this->uploadsDir);
        }
    }

    /**
     * Legenda e arquivo exportado a partir da fotografia aprovada.
     *
     * A legenda junta legenda + hashtags como o produto Python: é o que o
     * cliente viu e aprovou, sem reescrever nada na hora do envio.
     *
     * @param array<string,mixed> $fotografia
     * @return array{0:string,1:?string}
     */
    public static function conteudo(array $fotografia): array
    {
        if (($fotografia['type'] ?? '') === 'creative') {
            $texto = is_array($fotografia['content'] ?? null) ? $fotografia['content'] : [];
            $partes = array_filter([
                trim((string) ($texto['caption'] ?? '')),
                trim((string) ($texto['hashtags'] ?? '')),
            ], static fn (string $parte): bool => $parte !== '');
            $exportado = trim((string) ($fotografia['export_path'] ?? ''));

            return [implode("\n\n", $partes), $exportado !== '' ? $exportado : null];
        }

        $partes = array_filter([
            trim((string) ($fotografia['caption'] ?? '')),
            trim((string) ($fotografia['hashtags'] ?? '')),
        ], static fn (string $parte): bool => $parte !== '');

        return [implode("\n\n", $partes), null];
    }

    /** @throws MetaError */
    private function conferirPermissoes(int $companyId, int $connectionId, string $rede): void
    {
        $exigidas = $rede === 'Facebook'
            ? ['pages_manage_posts']
            : ['instagram_basic', 'instagram_content_publish'];
        $salvas = [];
        foreach ($this->db->all(
            'SELECT permission, status FROM meta_connection_permissions WHERE company_id = ? AND connection_id = ?',
            [$companyId, $connectionId]
        ) as $linha) {
            $salvas[(string) $linha['permission']] = (string) $linha['status'];
        }
        $recusadas = array_values(array_filter($exigidas, static fn (string $p): bool => ($salvas[$p] ?? '') === 'declined'));
        if ($recusadas !== []) {
            throw new MetaError('A autorização Meta recusou ' . implode(', ', $recusadas) . '. Reconecte a Página e conceda as permissões exigidas para publicação.');
        }
    }
}
