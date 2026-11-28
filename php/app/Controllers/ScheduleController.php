<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Auth\Authenticator;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Meta\MetaError;
use Smia\Meta\MetaRepository;
use Smia\Meta\MetaSettings;
use Smia\Meta\ScheduleRepository;
use Smia\Support\Config;
use Smia\Support\Modules;
use Smia\Support\Session;
use Smia\Support\View;

/**
 * Agenda interna: organizar quando cada conteúdo aprovado deve sair.
 *
 * A tela diz, em texto fixo, o que a agenda é e o que ela não é: não publica
 * sozinha, e publicar é um clique humano em horário vencido. Prometer
 * automação aqui seria vender o que o produto não faz.
 */
final class ScheduleController
{
    private const PAPEIS_EDICAO = ['owner', 'admin', 'editor'];

    public function __construct(
        private readonly Config $config,
        private readonly ScheduleRepository $repo,
        private readonly MetaRepository $meta,
        private readonly Authenticator $auth,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function index(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $empresa = (int) $usuario['company_id'];

        $clientes = $this->repo->clientes($empresa);
        // Sem filtro a agenda mostra a agência inteira: antes ela abria presa
        // no primeiro cliente da lista alfabética, e quem tinha duas marcas
        // via a fila da "A" sem ter pedido.
        $selecionadoId = max(0, (int) ($req->input('client_id') ?? 0));
        $selecionado = $selecionadoId > 0 ? $this->repo->cliente($selecionadoId, $empresa) : null;
        if ($selecionadoId > 0 && $selecionado === null) {
            return $this->naoEncontrado($req);
        }
        $status = (string) ($req->input('status') ?? '');
        if (!in_array($status, ['', 'ativos', 'historico'], true)) {
            $status = '';
        }
        $filtros = ['client_id' => $selecionadoId, 'status' => $status];

        // Tentativa que ficou "publicando" e nunca voltou é marcada como
        // incerta toda vez que a agenda é aberta — antes de qualquer clique.
        $this->meta->resolverTentativasParadas($empresa, '/schedule');

        $agenda = $this->repo->lista($empresa, 100, $filtros);
        $conexoes = $this->meta->conexoes($empresa);
        [$settings, $erroConfig] = MetaSettings::tentar($this->config);
        [, $erroMidia] = \Smia\Meta\MediaUrl::tentar($this->config);

        $ativas = 0;
        foreach ($agenda as $linha) {
            if ((string) $linha['status'] === 'scheduled'
                && !$linha['meta_published']
                && ($linha['meta_resolution'] ?? null) !== 'confirmed_published') {
                $ativas++;
            }
        }

        return $this->render('schedule/index', $req, [
            'clientes' => $clientes,
            'selecionado' => $selecionado,
            'resumo' => $this->repo->resumo($empresa, $selecionadoId),
            'filtros' => $filtros,
            // Por dia, na ordem em que a fila já vem (ativos primeiro).
            'por_dia' => $this->agruparPorDia($agenda),
            'aprovados' => $this->repo->aprovados($empresa, $selecionado !== null ? (int) $selecionado['id'] : null),
            'agenda' => $agenda,
            'ativas' => $ativas,
            'redes' => ScheduleRepository::REDES,
            'fusos' => ScheduleRepository::FUSOS,
            'fuso_padrao' => ScheduleRepository::FUSO_PADRAO,
            'sugestao' => ScheduleRepository::proximoHorarioSugerido(),
            'meta_configurada' => $settings !== null,
            'meta_erro' => $erroConfig,
            'midia_pronta' => $erroMidia === null,
            'midia_erro' => $erroMidia,
            'conexoes' => $conexoes,
            'pode_editar' => $this->podeEditar($usuario),
            'aviso' => $this->avisoDeQuery($req),
            'erro' => $req->input('erro'),
        ]);
    }

    /** Adiciona um ciclo aprovado à fila. */
    public function store(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro($req, 'Sessão expirada. Recarregue a página e tente novamente.');
        }

        try {
            $id = $this->repo->agendar(
                (int) $usuario['company_id'],
                (int) $usuario['id'],
                (int) ($req->input('approval_id') ?? 0),
                (string) ($req->input('network') ?? ''),
                $req->input('scheduled_local'),
                $req->input('timezone'),
            );
        } catch (MetaError $erro) {
            return $this->voltarErro($req, $erro->getMessage());
        }

        $this->meta->auditoria((int) $usuario['company_id'], (int) $usuario['id'], 'schedule.created', 'horário #' . $id);

        return Response::redirect($this->config->url('/schedule?created=' . $id));
    }

    public function reschedule(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro($req, 'Sessão expirada. Recarregue a página e tente novamente.');
        }
        $id = (int) ($params['id'] ?? 0);
        if ($this->repo->ativo($id, (int) $usuario['company_id']) === null) {
            return $this->naoEncontrado($req);
        }

        try {
            $this->repo->reagendar($id, (int) $usuario['company_id'], (int) $usuario['id'], $req->input('scheduled_local'), $req->input('timezone'));
        } catch (MetaError $erro) {
            return $this->voltarErro($req, $erro->getMessage());
        }
        $this->meta->auditoria((int) $usuario['company_id'], (int) $usuario['id'], 'schedule.rescheduled', 'horário #' . $id);

        return Response::redirect($this->config->url('/schedule?updated=' . $id));
    }

    public function cancel(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro($req, 'Sessão expirada. Recarregue a página e tente novamente.');
        }
        $id = (int) ($params['id'] ?? 0);
        if ($this->repo->ativo($id, (int) $usuario['company_id']) === null) {
            return $this->naoEncontrado($req);
        }

        try {
            $this->repo->cancelar($id, (int) $usuario['company_id'], (int) $usuario['id']);
        } catch (MetaError $erro) {
            return $this->voltarErro($req, $erro->getMessage());
        }
        $this->meta->auditoria((int) $usuario['company_id'], (int) $usuario['id'], 'schedule.cancelled', 'horário #' . $id);

        return Response::redirect($this->config->url('/schedule?cancelled=' . $id));
    }

    /**
     * Publica agora, via Meta. Ação destrutiva por natureza: sem formulário de
     * confirmação no servidor, mas exigindo POST + CSRF + papel + horário
     * vencido + aprovação válida.
     */
    public function publish(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro($req, 'Sessão expirada. Recarregue a página e tente novamente.');
        }
        $id = (int) ($params['id'] ?? 0);
        $empresa = (int) $usuario['company_id'];
        $agenda = $this->repo->ativo($id, $empresa);
        if ($agenda === null) {
            return $this->naoEncontrado($req);
        }

        [$settings, $erroConfig] = MetaSettings::tentar($this->config);
        if ($settings === null) {
            return $this->voltarErro($req, (string) $erroConfig);
        }
        [$midia, $erroMidia] = \Smia\Meta\MediaUrl::tentar($this->config);
        if ($midia === null) {
            return $this->voltarErro($req, (string) $erroMidia);
        }

        $publicador = new \Smia\Meta\Publisher(
            $this->meta->db(),
            $this->repo,
            $this->meta,
            new \Smia\Approvals\PortalRepository($this->meta->db()),
            $this->config->dataDir . '/uploads',
        );

        try {
            $resultado = $publicador->publicar(
                $empresa,
                (int) $usuario['id'],
                $id,
                (int) ($req->input('connection_id') ?? 0),
                $settings,
                new \Smia\Meta\Graph($settings, $this->config->metaGraphEndpoint, 25),
                $midia,
            );
        } catch (MetaError $erro) {
            return $this->voltarErro($req, $erro->getMessage());
        }

        $this->meta->auditoria(
            $empresa,
            (int) $usuario['id'],
            'meta.published.' . $resultado['status'],
            'horário #' . $id . ($resultado['remote_id'] !== null ? ' · ID ' . $resultado['remote_id'] : '')
        );

        if ($resultado['status'] === 'published') {
            return Response::redirect($this->config->url('/schedule?published=' . $id . '&id=' . rawurlencode((string) $resultado['remote_id'])));
        }

        return $this->voltarErro($req, $resultado['mensagem']);
    }

    // ------------------------------------------------------------------

    /** @return array<string,mixed>|Response */
    private function precisaLogin(): array|Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }

        return $usuario;
    }

    /** @param array<string,mixed> $usuario */
    private function podeEditar(array $usuario): bool
    {
        return in_array((string) $usuario['role'], self::PAPEIS_EDICAO, true);
    }

    private function semPermissao(Request $req): Response
    {
        return $this->render('schedule/index', $req, $this->dadosVazios($req), 403);
    }

    private function naoEncontrado(Request $req): Response
    {
        return $this->render('schedule/not_found', $req, [], 404);
    }

    /** Tela de 403 não precisa de dados: só o caminho de volta. */
    private function dadosVazios(Request $req): array
    {
        return [
            'clientes' => [],
            'selecionado' => null,
            'aprovados' => [],
            'agenda' => [],
            'ativas' => 0,
            'redes' => ScheduleRepository::REDES,
            'fusos' => ScheduleRepository::FUSOS,
            'fuso_padrao' => ScheduleRepository::FUSO_PADRAO,
            'sugestao' => ScheduleRepository::proximoHorarioSugerido(),
            'meta_configurada' => false,
            'meta_erro' => 'Seu papel atual é somente leitura.',
            'midia_pronta' => false,
            'midia_erro' => null,
            'conexoes' => [],
            'pode_editar' => false,
            'aviso' => null,
            'erro' => 'Seu papel atual é somente leitura. Peça a um administrador ou editor para organizar horários.',
            // A view desenha o resumo mesmo na tela de 403; sem estas chaves
            // ela emitia "Undefined variable" e o 403 virava 500.
            'resumo' => ['ativos' => 0, 'atrasados' => 0, 'publicados' => 0, 'cancelados' => 0, 'total' => 0],
            'filtros' => ['client_id' => 0, 'status' => ''],
            'por_dia' => [],
        ];
    }

    private function voltarErro(Request $req, string $mensagem): Response
    {
        return Response::redirect($this->config->url('/schedule?erro=' . rawurlencode($mensagem)));
    }

    /**
     * Agrupa a fila por dia (no fuso de cada horário) mantendo a ordem.
     *
     * A tela mostrava uma lista corrida: com dez horários, "quando é o
     * próximo?" exigia comparar datas linha a linha.
     *
     * @param array<int,array<string,mixed>> $agenda
     * @return array<int,array{dia:string,rotulo:string,itens:array<int,array<string,mixed>>}>
     */
    private function agruparPorDia(array $agenda): array
    {
        $dias = [];
        foreach ($agenda as $linha) {
            $chave = (string) ($linha['dia'] ?? substr((string) $linha['scheduled_at_utc'], 0, 10));
            if (!isset($dias[$chave])) {
                $dias[$chave] = [
                    'dia' => $chave,
                    'rotulo' => (string) ($linha['dia_relativo'] ?? $chave),
                    'itens' => [],
                ];
            }
            $dias[$chave]['itens'][] = $linha;
        }

        return array_values($dias);
    }

    private function avisoDeQuery(Request $req): ?string
    {
        return match (true) {
            $req->input('created') !== null => 'Horário adicionado à agenda interna.',
            $req->input('updated') !== null => 'Horário atualizado.',
            $req->input('cancelled') !== null => 'Agendamento interno cancelado; o histórico foi preservado.',
            $req->input('published') !== null => 'A Meta confirmou a publicação.',
            $req->input('reconciled') === 'confirmed_published' => 'A equipe registrou a confirmação manual na conta Meta; o relatório não conta como resposta confirmada pela API.',
            $req->input('reconciled') === 'confirmed_not_published' => 'Conferência registrada. A tentativa pode ser iniciada novamente.',
            default => null,
        };
    }

    private function render(string $view, Request $req, array $dados, int $status = 200): Response
    {
        return $this->view->render($view, $dados + [
            'user' => $this->auth->user(),
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
            'modules' => Modules::all(),
            // A agenda ganhou item próprio no menu (v1.24); antes ela existia
            // sem link nenhum e o CSS/realce do menu iam para "Conexões Meta".
            'active' => 'schedule',
            'current_path' => $req->path,
        ], $status)->withSecurityHeaders();
    }
}
