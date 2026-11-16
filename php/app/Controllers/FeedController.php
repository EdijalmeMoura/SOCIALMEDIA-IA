<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Auth\Authenticator;
use Smia\Feed\FeedConflict;
use Smia\Feed\FeedError;
use Smia\Feed\FeedNotFound;
use Smia\Feed\FeedRepository;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Support\Config;
use Smia\Support\Modules;
use Smia\Support\Session;
use Smia\Support\View;

/**
 * Feed: a grade 3×3 do Instagram montada com o que a agência já produziu.
 *
 * A prévia é interna e não agenda nada. O objetivo é ver a sequência como o
 * seguidor veria antes de mandar para aprovação — e reordenar arrastando.
 */
final class FeedController
{
    private const PAPEIS_EDICAO = ['owner', 'admin', 'editor'];

    public function __construct(
        private readonly Config $config,
        private readonly FeedRepository $repo,
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

        if ($clientes === []) {
            return $this->render('feed/index', $req, [
                'clientes' => [],
                'selecionado' => null,
                'marca' => [],
                'itens' => [],
                'artes' => [],
                'pautas' => [],
                'pode_editar' => $this->podeEditar($usuario),
                'aviso' => null,
                'erro' => null,
            ]);
        }

        $selecionado = null;
        $pedido = (int) ($req->input('client_id') ?? 0);
        foreach ($clientes as $cliente) {
            if ((int) $cliente['id'] === $pedido) {
                $selecionado = $cliente;
                break;
            }
        }
        // Cliente inválido ou de outra marca: cai na primeira da lista, sem erro.
        $selecionado ??= $clientes[0];
        $clientId = (int) $selecionado['id'];

        return $this->render('feed/index', $req, [
            'clientes' => $clientes,
            'selecionado' => $selecionado,
            'marca' => $this->repo->marca($clientId),
            'itens' => $this->repo->itens($clientId, $empresa),
            'artes' => $this->repo->artesDisponiveis($clientId, $empresa),
            'pautas' => $this->repo->postsDisponiveis($clientId, $empresa),
            'pode_editar' => $this->podeEditar($usuario),
            'aviso' => $this->avisoDeQuery($req),
            'erro' => $req->input('erro'),
        ]);
    }

    public function addCreative(Request $req, array $params): Response
    {
        return $this->adicionar($req, $params, 'creative');
    }

    public function addPost(Request $req, array $params): Response
    {
        return $this->adicionar($req, $params, 'planned');
    }

    public function remove(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro((int) ($params['client_id'] ?? 0), 'Sessão expirada. Recarregue a página e tente novamente.');
        }
        $empresa = (int) $usuario['company_id'];
        $clientId = (int) ($params['client_id'] ?? 0);
        if ($this->repo->cliente($clientId, $empresa) === null) {
            return $this->naoEncontrado($req);
        }

        if (!$this->repo->remover($clientId, (int) ($params['id'] ?? 0), $empresa)) {
            return $this->voltarErro($clientId, 'Esse item já não estava na prévia.');
        }
        $this->repo->auditoria($empresa, (int) $usuario['id'], 'feed.removed', 'client:' . $clientId);

        return Response::redirect($this->config->url('/feed?client_id=' . $clientId . '#grade'));
    }

    /**
     * Reordenação vinda do arrastar-e-soltar.
     *
     * Recebe JSON `{"order":[3,1,2]}`. A conferência compara com a ordem
     * salva: se bater, grava; se não, 409 para a tela recarregar.
     */
    public function reorder(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->jsonErro('Seu perfil não pode reorganizar a prévia.', 403);
        }
        // O JavaScript manda o token no cabeçalho; o formulário manda no corpo.
        $token = $req->header('x-csrf-token') ?? $req->input('csrf_token');
        if (!$this->session->csrfValid($token)) {
            return $this->jsonErro('Sessão expirada. Atualize a página e tente novamente.', 400);
        }

        $empresa = (int) $usuario['company_id'];
        $clientId = (int) ($params['client_id'] ?? 0);
        if ($this->repo->cliente($clientId, $empresa) === null) {
            return $this->jsonErro('Marca não encontrada neste workspace.', 404);
        }

        // A ordem chega como JSON puro (fetch) ou no campo `order_json`
        // (formulário/testes). O corpo cru é lido com teto de tamanho e só é
        // usado quando o campo não veio.
        $texto = (string) ($req->input('order_json') ?? '');
        if ($texto === '') {
            $texto = (string) file_get_contents('php://input', false, null, 0, 65536);
        }
        $dados = json_decode($texto, true);
        $ordem = [];
        if (is_array($dados)) {
            foreach ($dados as $valor) {
                if (is_int($valor) || (is_string($valor) && preg_match('/^\d+$/', $valor) === 1)) {
                    $ordem[] = (int) $valor;
                } else {
                    $ordem = null;
                    break;
                }
            }
        } else {
            $ordem = null;
        }
        if ($ordem === null || $ordem === []) {
            return $this->jsonErro('A ordem enviada é inválida.', 400);
        }
        if (count($ordem) !== count(array_unique($ordem))) {
            return $this->jsonErro('A ordem enviada é inválida.', 400);
        }

        try {
            $this->repo->reordenar($clientId, $empresa, $ordem);
        } catch (FeedConflict $erro) {
            return $this->jsonErro($erro->getMessage(), 409);
        }
        $this->repo->auditoria($empresa, (int) $usuario['id'], 'feed.reordered', 'client:' . $clientId . ' itens:' . count($ordem));

        return Response::json(['status' => 'ok', 'message' => 'Ordem do feed salva.']);
    }

    // ------------------------------------------------------------------

    private function adicionar(Request $req, array $params, string $tipo): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $clientId = (int) ($params['client_id'] ?? 0);
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro($clientId, 'Sessão expirada. Recarregue a página e tente novamente.');
        }
        $empresa = (int) $usuario['company_id'];
        if ($this->repo->cliente($clientId, $empresa) === null) {
            return $this->naoEncontrado($req);
        }

        try {
            if ($tipo === 'creative') {
                $this->repo->adicionarArte($clientId, (int) ($params['id'] ?? 0), $empresa);
            } else {
                $this->repo->adicionarPost($clientId, (int) ($params['id'] ?? 0), $empresa);
            }
        } catch (FeedNotFound $erro) {
            return $this->naoEncontrado($req);
        } catch (FeedError $erro) {
            return $this->voltarErro($clientId, $erro->getMessage());
        }
        $this->repo->auditoria($empresa, (int) $usuario['id'], 'feed.added', $tipo . ' -> client:' . $clientId);

        return Response::redirect($this->config->url('/feed?client_id=' . $clientId . '#grade'));
    }

    private function avisoDeQuery(Request $req): ?string
    {
        return match ((string) ($req->input('ok') ?? '')) {
            'order' => 'Ordem salva.',
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

    private function semPermissao(Request $req, string $mensagem = 'Seu perfil pode ver a prévia, mas não montar a grade.'): Response
    {
        return $this->render('feed/forbidden', $req, ['mensagem' => $mensagem], 403);
    }

    private function naoEncontrado(Request $req): Response
    {
        return $this->render('feed/not_found', $req, [], 404);
    }

    private function voltarErro(int $clientId, string $mensagem): Response
    {
        return Response::redirect($this->config->url(
            '/feed?client_id=' . max(0, $clientId) . '&erro=' . rawurlencode($mensagem) . '#grade'
        ));
    }

    private function jsonErro(string $mensagem, int $status): Response
    {
        return Response::json(['error' => $mensagem], $status);
    }

    private function render(string $view, Request $req, array $dados, int $status = 200): Response
    {
        return $this->view->render($view, $dados + [
            'user' => $this->auth->user(),
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
            'modules' => Modules::all(),
            'active' => 'feed',
            'current_path' => $req->path,
            'base_url' => $this->config->basePath,
        ], $status)->withSecurityHeaders();
    }
}
