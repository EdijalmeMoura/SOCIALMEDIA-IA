<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Approvals\ApprovalConflict;
use Smia\Approvals\ApprovalError;
use Smia\Approvals\ApprovalRepository;
use Smia\Approvals\PortalRepository;
use Smia\Auth\Authenticator;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Support\Config;
use Smia\Support\Modules;
use Smia\Support\Session;
use Smia\Support\Upload;
use Smia\Support\View;

/**
 * Aprovações, lado da agência: enviar para revisão, acompanhar e responder.
 *
 * O cliente vê o conteúdo em uma fotografia: depois de enviado, editar a arte
 * no estúdio não muda o que ele está aprovando. Um novo envio vira o ciclo 2,
 * preservando o histórico — é isso que torna a revisão auditável.
 */
final class ApprovalController
{
    private const PAPEIS_EDICAO = ['owner', 'admin', 'editor'];
    private const PAPEIS_GESTAO = ['owner', 'admin'];

    public function __construct(
        private readonly Config $config,
        private readonly ApprovalRepository $repo,
        private readonly PortalRepository $portal,
        private readonly Upload $upload,
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

        $selecionadoId = (int) ($req->input('client_id') ?? 0);
        if ($selecionadoId <= 0 && $clientes !== []) {
            $selecionadoId = (int) $clientes[0]['id'];
        }
        $selecionado = $selecionadoId > 0 ? $this->repo->cliente($selecionadoId, $empresa) : null;

        $status = (string) ($req->input('status') ?? '');
        $status = isset(ApprovalRepository::STATUS[$status]) ? $status : null;

        return $this->render('approvals/index', $req, [
            'clientes' => $clientes,
            'selecionado' => $selecionado,
            'artes' => $selecionado !== null ? $this->repo->artesDisponiveis((int) $selecionado['id'], $empresa) : [],
            'posts' => $selecionado !== null ? $this->repo->postsDisponiveis((int) $selecionado['id'], $empresa) : [],
            'aprovacoes' => $this->repo->lista($empresa, 100, $status),
            'status_filtro' => $status,
            'contagem' => $this->repo->contagem($empresa),
            'contas' => $this->portal->contas($empresa),
            'pode_editar' => $this->podeEditar($usuario),
            'pode_gerir' => $this->podeGerir($usuario),
            'aviso' => $this->avisoDeQuery($req),
            'erro' => $req->input('erro'),
        ]);
    }

    /** Envia um conteúdo para o portal do cliente. */
    public function send(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro($req, 'Sessão expirada. Recarregue a página e envie novamente.');
        }

        $empresa = (int) $usuario['company_id'];
        $clientId = (int) ($req->input('client_id') ?? 0);
        $origem = (string) ($req->input('source') ?? '');
        $separador = strpos($origem, ':');
        $tipo = $separador === false ? '' : substr($origem, 0, $separador);
        $sourceId = $separador === false ? 0 : (int) substr($origem, $separador + 1);

        if ($clientId <= 0 || !in_array($tipo, ['creative', 'planned'], true) || $sourceId <= 0) {
            return $this->voltarErro($req, 'Selecione um cliente e um conteúdo válidos.');
        }
        if ($this->repo->cliente($clientId, $empresa) === null) {
            return $this->voltarErro($req, 'Selecione um cliente e um conteúdo válidos.');
        }
        $fonte = $this->repo->fonte($tipo, $sourceId, $clientId, $empresa);
        if ($fonte === null) {
            return $this->voltarErro(
                $req,
                $tipo === 'creative'
                    ? 'Só artes exportadas podem ser enviadas para aprovação.'
                    : 'Esse conteúdo do planejamento não foi encontrado.'
            );
        }

        try {
            $id = $this->repo->enviar(
                $empresa,
                $clientId,
                (int) $usuario['id'],
                $tipo,
                $sourceId,
                ApprovalRepository::snapshot($tipo, $fonte),
                (string) ($req->input('message') ?? ''),
            );
        } catch (ApprovalError $erro) {
            return $this->voltarErro($req, $erro->getMessage(), $clientId);
        }

        $aprovacao = $this->repo->aprovacao($id, $empresa);
        $this->portal->avisarCliente(
            $empresa,
            $clientId,
            'Novo conteúdo para revisão',
            (string) ($aprovacao['title'] ?? ''),
            '/portal/approvals/' . $id,
        );
        $this->repo->auditoria($empresa, (int) $usuario['id'], 'approval.sent', $tipo . ':' . $sourceId . ' -> ' . $id);

        return Response::redirect($this->config->url('/approvals/' . $id . '?sent=1'));
    }

    /** Detalhe da revisão: fotografia + histórico. */
    public function show(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $empresa = (int) $usuario['company_id'];
        $aprovacao = $this->repo->aprovacao((int) ($params['id'] ?? 0), $empresa);
        if ($aprovacao === null) {
            return $this->naoEncontrado($req);
        }
        $snapshot = json_decode((string) $aprovacao['snapshot_json'], true) ?: [];
        $midia = (string) ($snapshot['export_path'] ?? $snapshot['image_path'] ?? '');

        return $this->render('approvals/detail', $req, [
            'aprovacao' => $aprovacao,
            'snapshot' => $snapshot,
            'midia' => $midia !== '' ? $this->config->url('/approvals/' . (int) $aprovacao['id'] . '/media/' . $midia) : null,
            'comentarios' => $this->repo->comentarios((int) $aprovacao['id'], $empresa),
            'eventos' => $this->repo->eventos((int) $aprovacao['id'], $empresa),
            'pode_editar' => $this->podeEditar($usuario),
            'aviso' => (string) ($req->input('sent') ?? '') === '1' ? 'Conteúdo enviado para o portal do cliente.' : null,
            'erro' => $req->input('erro'),
        ]);
    }

    public function comment(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $empresa = (int) $usuario['company_id'];
        $aprovacao = $this->repo->aprovacao((int) ($params['id'] ?? 0), $empresa);
        if ($aprovacao === null) {
            return $this->naoEncontrado($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro($req, 'Sessão expirada. Recarregue a página e comente novamente.', null, (int) $aprovacao['id']);
        }

        $corpo = mb_substr((string) ($req->input('body') ?? ''), 0, 3000);
        $anexo = null;
        try {
            $anexo = $this->upload->store($_FILES['file'] ?? [], (int) $aprovacao['client_id'], 'approval');
        } catch (\InvalidArgumentException $erro) {
            return $this->voltarErro($req, $erro->getMessage(), null, (int) $aprovacao['id']);
        }
        if (trim($corpo) === '' && $anexo === null) {
            return $this->voltarErro($req, 'Escreva um comentário ou anexe uma referência.', null, (int) $aprovacao['id']);
        }

        $this->repo->comentar(
            (int) $aprovacao['id'],
            $empresa,
            'agency',
            (int) $usuario['id'],
            null,
            $corpo,
            $anexo['filename'] ?? null,
            $anexo['original_name'] ?? null,
        );
        $this->portal->avisarCliente(
            $empresa,
            (int) $aprovacao['client_id'],
            'Novo comentário da agência',
            trim($corpo) !== '' ? $corpo : 'A equipe anexou uma referência.',
            '/portal/approvals/' . (int) $aprovacao['id'],
        );

        return Response::redirect($this->config->url('/approvals/' . (int) $aprovacao['id'] . '#historico'));
    }

    /** Cancela a revisão (a agência desiste) — libera o conteúdo para novo envio. */
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
        $empresa = (int) $usuario['company_id'];
        $aprovacao = $this->repo->aprovacao((int) ($params['id'] ?? 0), $empresa);
        if ($aprovacao === null) {
            return $this->naoEncontrado($req);
        }

        try {
            $this->repo->cancelar((int) $aprovacao['id'], $empresa, (int) $usuario['id']);
        } catch (ApprovalConflict $erro) {
            return $this->voltarErro($req, $erro->getMessage(), null, (int) $aprovacao['id']);
        }
        $this->repo->auditoria($empresa, (int) $usuario['id'], 'approval.cancelled', (string) $aprovacao['id']);

        return Response::redirect($this->config->url('/approvals/' . (int) $aprovacao['id']));
    }

    /** Arquivo da revisão (arte exportada ou anexo de comentário). */
    public function media(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $nome = (string) ($params['filename'] ?? '');
        if (!Upload::isSafeName($nome)) {
            return Response::text('Arquivo não encontrado.', 404);
        }
        $empresa = (int) $usuario['company_id'];
        if (!$this->repo->arquivoDaRevisao((int) ($params['id'] ?? 0), $empresa, $nome)) {
            return Response::text('Arquivo não encontrado.', 404);
        }
        $caminho = $this->upload->diretorio() . '/' . $nome;
        if (!is_file($caminho)) {
            return Response::text('Arquivo não encontrado.', 404);
        }

        return Response::file($caminho, Upload::mime($nome))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withSecurityHeaders();
    }

    /** Cria acesso do cliente ao portal. */
    public function createPortalUser(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeGerir($usuario)) {
            return $this->semPermissao($req, 'Só owner e admin criam acessos do portal.');
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro($req, 'Sessão expirada. Recarregue a página e tente novamente.');
        }
        $empresa = (int) $usuario['company_id'];
        $clientId = (int) ($req->input('client_id') ?? 0);
        if ($clientId <= 0 || $this->repo->cliente($clientId, $empresa) === null) {
            return $this->voltarErro($req, 'Selecione um cliente válido para a conta.');
        }

        try {
            $id = $this->portal->criar(
                $empresa,
                $clientId,
                (string) ($req->input('name') ?? ''),
                (string) ($req->input('email') ?? ''),
                (string) ($req->input('password') ?? ''),
            );
        } catch (ApprovalError $erro) {
            return $this->voltarErro($req, $erro->getMessage());
        }
        $this->repo->auditoria($empresa, (int) $usuario['id'], 'portal.user_created', 'portal_user:' . $id);

        return Response::redirect($this->config->url('/approvals?portal=created'));
    }

    public function togglePortalUser(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeGerir($usuario)) {
            return $this->semPermissao($req, 'Só owner e admin alteram acessos do portal.');
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro($req, 'Sessão expirada. Recarregue a página e tente novamente.');
        }
        $empresa = (int) $usuario['company_id'];
        $ativo = $this->portal->alternar((int) ($params['id'] ?? 0), $empresa);
        if ($ativo === null) {
            return $this->naoEncontrado($req);
        }
        $this->repo->auditoria($empresa, (int) $usuario['id'], 'portal.user_toggled', 'portal_user:' . (int) ($params['id'] ?? 0));

        return Response::redirect($this->config->url('/approvals?portal=' . ($ativo ? 'activated' : 'paused')));
    }

    /** Marca os avisos da agência como lidos. */
    public function readNotifications(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro($req, 'Sessão expirada. Recarregue a página e tente novamente.');
        }
        $this->portal->marcarLidos((int) $usuario['company_id'], 'agency', (int) $usuario['id']);

        return Response::redirect($this->config->url('/approvals'));
    }

    // --- infraestrutura de tela ---------------------------------------

    private function avisoDeQuery(Request $req): ?string
    {
        $portal = (string) ($req->input('portal') ?? '');

        return match ($portal) {
            'created' => 'Acesso ao portal criado. Envie o e-mail e a senha para o cliente.',
            'activated' => 'Acesso reativado.',
            'paused' => 'Acesso pausado: a pessoa não entra mais no portal.',
            default => null,
        };
    }

    /** @return array<string,mixed>|Response */
    private function precisaLogin(): array|Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }

        return $usuario;
    }

    private function podeEditar(array $usuario): bool
    {
        return in_array((string) $usuario['role'], self::PAPEIS_EDICAO, true);
    }

    private function podeGerir(array $usuario): bool
    {
        return in_array((string) $usuario['role'], self::PAPEIS_GESTAO, true);
    }

    private function semPermissao(Request $req, string $mensagem = 'Seu perfil não tem permissão para revisar conteúdos.'): Response
    {
        return $this->render('approvals/forbidden', $req, ['mensagem' => $mensagem], 403);
    }

    private function naoEncontrado(Request $req): Response
    {
        return $this->render('approvals/not_found', $req, [], 404);
    }

    private function voltarErro(Request $req, string $mensagem, ?int $clientId = null, ?int $approvalId = null): Response
    {
        if ($approvalId !== null) {
            return Response::redirect($this->config->url('/approvals/' . $approvalId . '?erro=' . rawurlencode($mensagem)));
        }
        $consulta = '?erro=' . rawurlencode($mensagem) . ($clientId !== null ? '&client_id=' . $clientId : '');

        return Response::redirect($this->config->url('/approvals' . $consulta));
    }

    private function render(string $view, Request $req, array $dados, int $status = 200): Response
    {
        $usuario = $this->auth->user();

        return $this->view->render($view, $dados + [
            'user' => $usuario,
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
            'modules' => Modules::all(),
            'active' => 'approvals',
            'current_path' => $req->path,
            'avisos' => $usuario !== null ? $this->portal->avisosAgencia((int) $usuario['company_id'], (int) $usuario['id'], 6) : [],
            'avisos_novos' => $usuario !== null ? $this->portal->naoLidosAgencia((int) $usuario['company_id'], (int) $usuario['id']) : 0,
        ], $status)->withSecurityHeaders();
    }
}
