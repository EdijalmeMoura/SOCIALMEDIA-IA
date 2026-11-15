<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Approvals\ApprovalConflict;
use Smia\Approvals\ApprovalError;
use Smia\Approvals\ApprovalRepository;
use Smia\Approvals\PortalRepository;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Support\Config;
use Smia\Support\Session;
use Smia\Support\Upload;
use Smia\Support\View;

/**
 * Portal do cliente: só o que é daquele cliente, sem painel da agência.
 *
 * A sessão do portal é um escopo separado (`portal_user_id`). Entrar como
 * cliente não abre a agência e a conta da agência não abre o portal — o setor
 * que decide isso está nas rotas e no logout. Cada requisição reconfere se a
 * conta continua ativa (desativar derruba o acesso na hora, sem esperar a
 * sessão expirar).
 */
final class PortalController
{
    private const MAX_TENTATIVAS = 10;
    private const JANELA = 300; // 5 minutos
    private const CHAVE_TENTATIVAS = '_portal_attempts';

    public function __construct(
        private readonly Config $config,
        private readonly ApprovalRepository $repo,
        private readonly PortalRepository $portal,
        private readonly Upload $upload,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function loginForm(Request $req): Response
    {
        if ($this->contaLogada() !== null) {
            return Response::redirect($this->config->url('/portal/approvals'));
        }

        return $this->render('portal/login', [
            'erro' => $req->input('erro'),
            'email' => '',
        ], $req);
    }

    public function login(Request $req): Response
    {
        if ($this->contaLogada() !== null) {
            return Response::redirect($this->config->url('/portal/approvals'));
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->render('portal/login', ['erro' => 'Sessão expirada. Tente novamente.', 'email' => (string) ($req->input('email') ?? '')], $req, 400);
        }

        $email = (string) ($req->input('email') ?? '');
        $senha = (string) ($req->input('password') ?? '');
        $chave = hash('sha256', (string) ($req->header('x-forwarded-for') ?? $req->header('remote-addr') ?? 'local'));
        $tentativas = (array) $this->session->get(self::CHAVE_TENTATIVAS, []);
        $agora = time();
        $registro = $tentativas[$chave] ?? ['n' => 0, 'desde' => $agora];
        if ($agora - (int) $registro['desde'] > self::JANELA) {
            $registro = ['n' => 0, 'desde' => $agora];
        }
        if ((int) $registro['n'] >= self::MAX_TENTATIVAS) {
            return $this->render('portal/login', [
                'erro' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.',
                'email' => $email,
            ], $req, 429);
        }

        $conta = $this->portal->autenticar($email, $senha);
        if ($conta === null) {
            $registro['n'] = (int) $registro['n'] + 1;
            $tentativas[$chave] = $registro;
            $this->session->put(self::CHAVE_TENTATIVAS, $tentativas);

            return $this->render('portal/login', ['erro' => 'E-mail ou senha incorretos.', 'email' => $email], $req, 401);
        }

        unset($tentativas[$chave]);
        $this->session->put(self::CHAVE_TENTATIVAS, $tentativas);
        $this->session->regenerate();
        // Mesma sessão PHP, escopos separados: o painel olha `user_id`, o portal
        // olha `portal_user_id`. Entrar no portal encerra o acesso da agência
        // neste navegador — quem revisa é o cliente, não a equipe.
        $this->session->forget('user_id');
        $this->session->put('portal_user_id', (int) $conta['id']);

        return Response::redirect($this->config->url('/portal/approvals'));
    }

    public function logout(Request $req): Response
    {
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return Response::redirect($this->config->url('/portal/approvals'));
        }
        $this->session->forget('portal_user_id');
        $this->session->regenerate();

        return Response::redirect($this->config->url('/portal/login'));
    }

    /** Lista de revisões do cliente. */
    public function index(Request $req): Response
    {
        $conta = $this->contaLogada();
        if ($conta === null) {
            return $this->entrar();
        }
        $empresa = (int) $conta['company_id'];
        $aprovacoes = $this->repo->listaDoPortal($empresa, (int) $conta['client_id']);

        return $this->render('portal/index', [
            'conta' => $conta,
            'aprovacoes' => $aprovacoes,
            'contagem' => $this->repo->contagemDoPortal($empresa, (int) $conta['client_id']),
            'avisos' => $this->portal->avisosCliente($empresa, (int) $conta['id'], 8),
            'aviso' => (string) ($req->input('responded') ?? '') === '1' ? 'Resposta enviada para a agência.' : null,
        ], $req);
    }

    public function show(Request $req, array $params): Response
    {
        $conta = $this->contaLogada();
        if ($conta === null) {
            return $this->entrar();
        }
        $empresa = (int) $conta['company_id'];
        $aprovacao = $this->repo->aprovacaoDoPortal((int) ($params['id'] ?? 0), $empresa, (int) $conta['client_id']);
        if ($aprovacao === null) {
            return $this->naoEncontrado($conta, $req);
        }
        $snapshot = json_decode((string) $aprovacao['snapshot_json'], true) ?: [];
        $midia = (string) ($snapshot['export_path'] ?? $snapshot['image_path'] ?? '');

        return $this->render('portal/detail', [
            'conta' => $conta,
            'aprovacao' => $aprovacao,
            'snapshot' => $snapshot,
            'midia' => $midia !== '' ? $this->config->url('/portal/approvals/' . (int) $aprovacao['id'] . '/media/' . $midia) : null,
            'comentarios' => $this->repo->comentarios((int) $aprovacao['id'], $empresa),
            'eventos' => $this->repo->eventos((int) $aprovacao['id'], $empresa),
            'aviso' => (string) ($req->input('responded') ?? '') === '1' ? 'Resposta enviada. A agência já foi avisada.' : null,
            'erro' => $req->input('erro'),
        ], $req);
    }

    /** Aprovar, pedir alterações ou rejeitar. */
    public function respond(Request $req, array $params): Response
    {
        $conta = $this->contaLogada();
        if ($conta === null) {
            return $this->entrar();
        }
        $empresa = (int) $conta['company_id'];
        $id = (int) ($params['id'] ?? 0);
        $aprovacao = $this->repo->aprovacaoDoPortal($id, $empresa, (int) $conta['client_id']);
        if ($aprovacao === null) {
            return $this->naoEncontrado($conta, $req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro($id, 'Sessão expirada. Recarregue a página e responda novamente.');
        }
        if ((string) $aprovacao['status'] !== 'pending') {
            return Response::text('Esta revisão já foi respondida.', 409);
        }

        $decisao = (string) ($req->input('status') ?? '');
        if (!in_array($decisao, ['approved', 'changes_requested', 'rejected'], true)) {
            return $this->voltarErro($id, 'Escolha uma decisão válida.');
        }
        $corpo = mb_substr((string) ($req->input('body') ?? ''), 0, 3000);
        $anexo = null;
        try {
            $anexo = $this->upload->store($_FILES['file'] ?? [], (int) $aprovacao['client_id'], 'approval');
        } catch (\InvalidArgumentException $erro) {
            return $this->voltarErro($id, $erro->getMessage());
        }
        if ($decisao === 'changes_requested' && trim($corpo) === '' && $anexo === null) {
            return $this->voltarErro($id, 'Descreva as alterações necessárias ou anexe uma referência.');
        }

        try {
            $this->repo->responder(
                $id,
                $empresa,
                (int) $conta['client_id'],
                (int) $conta['id'],
                $decisao,
                $corpo,
                $anexo['filename'] ?? null,
                $anexo['original_name'] ?? null,
            );
        } catch (ApprovalConflict $erro) {
            return Response::text($erro->getMessage(), 409);
        } catch (ApprovalError $erro) {
            return $this->voltarErro($id, $erro->getMessage());
        }

        $rotulos = [
            'approved' => 'aprovou',
            'changes_requested' => 'solicitou alterações em',
            'rejected' => 'rejeitou',
        ];
        $this->portal->avisarAgencia(
            $empresa,
            'Retorno do cliente',
            (string) $conta['name'] . ' ' . $rotulos[$decisao] . ': ' . (string) $aprovacao['title'],
            '/approvals/' . $id,
        );

        return Response::redirect($this->config->url('/portal/approvals/' . $id . '?responded=1'));
    }

    public function comment(Request $req, array $params): Response
    {
        $conta = $this->contaLogada();
        if ($conta === null) {
            return $this->entrar();
        }
        $empresa = (int) $conta['company_id'];
        $id = (int) ($params['id'] ?? 0);
        $aprovacao = $this->repo->aprovacaoDoPortal($id, $empresa, (int) $conta['client_id']);
        if ($aprovacao === null) {
            return $this->naoEncontrado($conta, $req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro($id, 'Sessão expirada. Recarregue a página e comente novamente.');
        }

        $corpo = mb_substr((string) ($req->input('body') ?? ''), 0, 3000);
        $anexo = null;
        try {
            $anexo = $this->upload->store($_FILES['file'] ?? [], (int) $aprovacao['client_id'], 'approval');
        } catch (\InvalidArgumentException $erro) {
            return $this->voltarErro($id, $erro->getMessage());
        }
        if (trim($corpo) === '' && $anexo === null) {
            return $this->voltarErro($id, 'Escreva um comentário ou anexe uma referência.');
        }

        $this->repo->comentar(
            $id,
            $empresa,
            'client',
            null,
            (int) $conta['id'],
            $corpo,
            $anexo['filename'] ?? null,
            $anexo['original_name'] ?? null,
        );
        $this->portal->avisarAgencia(
            $empresa,
            'Novo comentário do cliente',
            trim($corpo) !== '' ? $corpo : 'O cliente anexou uma referência.',
            '/approvals/' . $id,
        );

        return Response::redirect($this->config->url('/portal/approvals/' . $id . '#historico'));
    }

    public function media(Request $req, array $params): Response
    {
        $conta = $this->contaLogada();
        if ($conta === null) {
            return $this->entrar();
        }
        $nome = (string) ($params['filename'] ?? '');
        if (!Upload::isSafeName($nome)) {
            return Response::text('Arquivo não encontrado.', 404);
        }
        $empresa = (int) $conta['company_id'];
        $id = (int) ($params['id'] ?? 0);
        $aprovacao = $this->repo->aprovacaoDoPortal($id, $empresa, (int) $conta['client_id']);
        if ($aprovacao === null || !$this->repo->arquivoDaRevisao($id, $empresa, $nome)) {
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

    public function readNotifications(Request $req): Response
    {
        $conta = $this->contaLogada();
        if ($conta === null) {
            return $this->entrar();
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return Response::redirect($this->config->url('/portal/approvals'));
        }
        $this->portal->marcarLidos((int) $conta['company_id'], 'client', (int) $conta['id']);

        return Response::redirect($this->config->url('/portal/approvals'));
    }

    // --- sessão do portal ---------------------------------------------

    /**
     * Conta por trás da sessão do portal, reconferida no banco.
     * Desativar a conta encerra o acesso imediatamente.
     *
     * @return array<string,mixed>|null
     */
    private function contaLogada(): ?array
    {
        $id = (int) ($this->session->get('portal_user_id') ?? 0);
        if ($id <= 0) {
            return null;
        }
        $conta = $this->portal->sessaoValida($id);
        if ($conta === null) {
            $this->session->forget('portal_user_id');

            return null;
        }

        return $conta;
    }

    private function entrar(): Response
    {
        return Response::redirect($this->config->url('/portal/login'));
    }

    private function voltarErro(int $id, string $mensagem): Response
    {
        return Response::redirect($this->config->url('/portal/approvals/' . $id . '?erro=' . rawurlencode($mensagem)));
    }

    private function naoEncontrado(array $conta, Request $req): Response
    {
        return $this->render('portal/not_found', ['conta' => $conta], $req, 404);
    }

    /** O portal tem layout próprio: nada de menu da agência. */
    private function render(string $view, array $dados, Request $req, int $status = 200): Response
    {
        return $this->view->render($view, $dados + [
            'csrf' => $this->session->csrfToken(),
            'current_path' => $req->path,
        ], $status)->withSecurityHeaders();
    }
}
