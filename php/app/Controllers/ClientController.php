<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Auth\Authenticator;
use Smia\Clients\ClientRepository;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Support\Config;
use Smia\Support\Modules;
use Smia\Support\Session;
use Smia\Support\Upload;
use Smia\Support\View;

/**
 * Módulo de clientes: listagem, cadastro, perfil, identidade visual e imagens.
 *
 * Permissões seguem o produto Python: owner/admin/editor editam,
 * viewer só lê, owner/admin excluem. Toda escrita é POST com CSRF.
 */
final class ClientController
{
    private const PAPEIS_EDICAO = ['owner', 'admin', 'editor'];
    private const PAPEIS_EXCLUSAO = ['owner', 'admin'];

    public function __construct(
        private readonly Config $config,
        private readonly ClientRepository $clients,
        private readonly Authenticator $auth,
        private readonly Session $session,
        private readonly View $view,
        private readonly Upload $upload,
    ) {
    }

    public function index(Request $req): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        $empresa = (int) $usuario['company_id'];

        return $this->render('clients/index', $req, [
            'clientes' => $this->clients->forCompany($empresa),
            'resumo' => $this->clients->summary($empresa),
            'pode_editar' => $this->podeEditar($usuario),
            'pode_excluir' => $this->podeExcluir($usuario),
        ]);
    }

    public function createForm(Request $req): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }

        return $this->render('clients/form', $req, [
            'modo' => 'novo',
            'cliente' => [],
            'erros' => [],
        ]);
    }

    public function create(Request $req): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->render('clients/form', $req, [
                'modo' => 'novo',
                'cliente' => $req->post,
                'erros' => ['Sessão expirada. Revise os dados e envie novamente.'],
            ], 400);
        }

        $dados = ClientRepository::normalize($req->post);
        $erros = $this->clients->validate($dados);
        if ($erros !== []) {
            return $this->render('clients/form', $req, [
                'modo' => 'novo',
                'cliente' => $dados,
                'erros' => $erros,
            ], 422);
        }

        $clientId = $this->clients->create((int) $usuario['company_id'], $dados);
        $this->clients->audit((int) $usuario['company_id'], (int) $usuario['id'], 'client.created', $dados['company_name']);
        $this->session->flash('success', 'Perfil criado. Agora complete a identidade visual da marca.');

        return Response::redirect($this->config->url('/clients/' . $clientId . '?created=1'));
    }

    public function show(Request $req, array $params): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        $cliente = $this->clients->find((int) ($params['id'] ?? 0), (int) $usuario['company_id']);
        if ($cliente === null) {
            return $this->naoEncontrado($req);
        }

        $mensagens = [
            'created' => 'Perfil criado. Agora complete a identidade visual da marca.',
            'saved' => 'Alterações salvas com sucesso.',
            'logo' => 'Logotipo atualizado.',
            'asset' => 'Biblioteca de referências atualizada.',
            'asset_deleted' => 'Referência removida.',
        ];
        $aviso = $mensagens[(string) $req->input('ok', '')] ?? null;
        $erro = $req->input('erro');

        return $this->render('clients/detail', $req, [
            'cliente' => $cliente,
            'referencias' => $this->clients->assets((int) $cliente['id']),
            'pode_editar' => $this->podeEditar($usuario),
            'pode_excluir' => $this->podeExcluir($usuario),
            'aviso' => $aviso,
            'erroUpload' => $erro !== null && $erro !== '' ? $erro : null,
        ]);
    }

    public function update(Request $req, array $params): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $cliente = $this->clients->find((int) ($params['id'] ?? 0), (int) $usuario['company_id']);
        if ($cliente === null) {
            return $this->naoEncontrado($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->render('clients/detail', $req, [
                'cliente' => $cliente,
                'referencias' => $this->clients->assets((int) $cliente['id']),
                'pode_editar' => true,
                'pode_excluir' => $this->podeExcluir($usuario),
                'aviso' => null,
                'erroUpload' => 'Sessão expirada. Revise os dados e envie novamente.',
            ], 400);
        }

        $dados = ClientRepository::normalize($req->post);
        $erros = $this->clients->validate($dados);
        if ($erros !== []) {
            return $this->render('clients/detail', $req, [
                'cliente' => array_merge($cliente, $dados),
                'referencias' => $this->clients->assets((int) $cliente['id']),
                'pode_editar' => true,
                'pode_excluir' => $this->podeExcluir($usuario),
                'aviso' => null,
                'erroUpload' => implode(' ', $erros),
            ], 422);
        }

        $this->clients->update((int) $cliente['id'], (int) $usuario['company_id'], $dados);
        $this->clients->updateBranding((int) $cliente['id'], $req->post);
        $this->clients->audit((int) $usuario['company_id'], (int) $usuario['id'], 'client.updated', $dados['company_name']);
        $this->session->flash('success', 'Alterações salvas com sucesso.');

        return Response::redirect($this->config->url('/clients/' . $cliente['id'] . '?ok=saved'));
    }

    public function delete(Request $req, array $params): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        if (!$this->podeExcluir($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->semPermissao($req, 'Sessão expirada. Tente novamente.');
        }
        $cliente = $this->clients->find((int) ($params['id'] ?? 0), (int) $usuario['company_id']);
        if ($cliente === null) {
            return $this->naoEncontrado($req);
        }

        $arquivos = $this->clients->delete((int) $cliente['id'], (int) $usuario['company_id']);
        foreach ($arquivos as $arquivo) {
            $this->upload->delete($arquivo);
        }
        $this->clients->audit((int) $usuario['company_id'], (int) $usuario['id'], 'client.deleted', (string) $cliente['company_name']);
        $this->session->flash('success', 'Cliente removido com sucesso.');

        return Response::redirect($this->config->url('/clients?ok=deleted'));
    }

    public function uploadLogo(Request $req, array $params): Response
    {
        return $this->receberImagem($req, $params, 'logo');
    }

    public function uploadAsset(Request $req, array $params): Response
    {
        return $this->receberImagem($req, $params, 'referencia');
    }

    public function deleteAsset(Request $req, array $params): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $cliente = $this->clients->find((int) ($params['id'] ?? 0), (int) $usuario['company_id']);
        if ($cliente === null) {
            return $this->naoEncontrado($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->semPermissao($req, 'Sessão expirada. Tente novamente.');
        }
        $asset = $this->clients->findAsset((int) ($params['asset'] ?? 0), (int) $cliente['id']);
        if ($asset === null) {
            return $this->naoEncontrado($req);
        }

        $this->clients->deleteAsset((int) $asset['id'], (int) $cliente['id']);
        $this->upload->delete((string) $asset['filename']);

        return Response::redirect($this->config->url('/clients/' . $cliente['id'] . '?ok=asset_deleted'));
    }

    /**
     * Serve uma imagem enviada, mas só se ela pertencer a um cliente DESTA
     * empresa — igual ao Python. Sem isso, trocar o nome na URL mostraria
     * arquivos de outro workspace.
     */
    public function file(Request $req): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        $nome = (string) $req->input('file', '');
        if (!Upload::isSafeName($nome) || basename($nome) !== $nome) {
            return Response::html('', 404);
        }
        $empresa = (int) $usuario['company_id'];
        $permitido = $this->clients->fileBelongsToCompany($nome, $empresa);
        $caminho = $this->upload->diretorio() . '/' . $nome;
        if (!$permitido || !is_file($caminho)) {
            return Response::html('', 404);
        }

        return Response::file($caminho, Upload::mime($nome));
    }

    // ------------------------------------------------------------------

    /** @param array<string,mixed> $dados */
    private function render(string $view, Request $req, array $dados, int $status = 200): Response
    {
        $usuario = $this->auth->user();

        return $this->view->render($view, $dados + [
            'user' => $usuario,
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
            'modules' => Modules::all(),
            'active' => 'clients',
            'current_path' => $req->path,
        ], $status)->withSecurityHeaders();
    }

    /** @param array<string,mixed> $params */
    private function receberImagem(Request $req, array $params, string $tipo): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $cliente = $this->clients->find((int) ($params['id'] ?? 0), (int) $usuario['company_id']);
        if ($cliente === null) {
            return $this->naoEncontrado($req);
        }
        $destino = $this->config->url('/clients/' . $cliente['id']);
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return Response::redirect($destino . '?erro=' . rawurlencode('Sessão expirada. Envie a imagem novamente.'));
        }

        try {
            $resultado = $this->upload->store($_FILES['file'] ?? [], (int) $cliente['id'], $tipo);
        } catch (\InvalidArgumentException $erro) {
            return Response::redirect($destino . '?erro=' . rawurlencode($erro->getMessage()));
        } catch (\RuntimeException $erro) {
            error_log('[SMIA] upload: ' . $erro->getMessage());

            return Response::redirect($destino . '?erro=' . rawurlencode('Não foi possível salvar a imagem. Confira a permissão da pasta de dados.'));
        }

        if ($resultado === null) {
            return Response::redirect($destino);
        }

        if ($tipo === 'logo') {
            $anterior = $this->clients->setLogo((int) $cliente['id'], $resultado['filename']);
            $this->upload->delete($anterior);
            $this->clients->audit((int) $usuario['company_id'], (int) $usuario['id'], 'client.logo', (string) $cliente['company_name']);

            return Response::redirect($destino . '?ok=logo');
        }

        $this->clients->addAsset((int) $cliente['id'], $resultado['filename'], $resultado['original_name']);
        $this->clients->audit((int) $usuario['company_id'], (int) $usuario['id'], 'client.asset', (string) $cliente['company_name']);

        return Response::redirect($destino . '?ok=asset');
    }

    private function podeEditar(array $usuario): bool
    {
        return in_array((string) $usuario['role'], self::PAPEIS_EDICAO, true);
    }

    private function podeExcluir(array $usuario): bool
    {
        return in_array((string) $usuario['role'], self::PAPEIS_EXCLUSAO, true);
    }

    private function semPermissao(Request $req, string $mensagem = 'Seu perfil não tem permissão para esta ação.'): Response
    {
        return $this->render('clients/forbidden', $req, ['mensagem' => $mensagem], 403);
    }

    private function naoEncontrado(Request $req): Response
    {
        return $this->render('clients/not_found', $req, [], 404);
    }
}
