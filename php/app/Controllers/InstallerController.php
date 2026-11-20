<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Installer\Installer;
use Smia\Support\Config;
use Smia\Support\Session;
use Smia\Support\View;

/**
 * Telas do instalador: ambiente -> revisão -> concluído.
 *
 * Três passos curtos, nada gravado antes da confirmação e o instalador se
 * fecha (410) assim que existir conta.
 */
final class InstallerController
{
    public function __construct(
        private readonly Config $config,
        private readonly Installer $installer,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function show(Request $req): Response
    {
        if (!$this->guardAccess($req)) {
            return $this->denied($req);
        }
        if (!$this->installer->needed()) {
            return $this->closed();
        }

        return $this->render('install/form', [
            'checks' => $this->installer->environmentChecks(),
            'errors' => [],
            'old' => [],
            'origin' => $this->detectedOrigin($req),
            'token_required' => $this->installer->tokenRequired(),
            'token' => $req->input('access_token', ''),
        ]);
    }

    public function validate(Request $req): Response
    {
        if (!$this->guardAccess($req)) {
            return $this->denied($req);
        }
        if (!$this->installer->needed()) {
            return $this->closed();
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->render('install/form', [
                'checks' => $this->installer->environmentChecks(),
                'errors' => ['Sessão expirada. Revise os dados e envie novamente.'],
                'old' => $req->post,
                'origin' => $this->detectedOrigin($req),
                'token_required' => $this->installer->tokenRequired(),
                'token' => $req->input('access_token', ''),
            ], 400);
        }

        [$dados, $erros] = $this->installer->validate($req->post, $this->detectedOrigin($req));
        if ($erros !== []) {
            return $this->render('install/form', [
                'checks' => $this->installer->environmentChecks(),
                'errors' => $erros,
                'old' => $req->post,
                'origin' => $this->detectedOrigin($req),
                'token_required' => $this->installer->tokenRequired(),
                'token' => $req->input('access_token', ''),
            ], 422);
        }

        $id = $this->installer->createDraft($dados);
        return Response::redirect($this->url($req, '/install/review', ['draft' => $id]));
    }

    public function review(Request $req): Response
    {
        if (!$this->guardAccess($req)) {
            return $this->denied($req);
        }
        if (!$this->installer->needed()) {
            return $this->closed();
        }
        $rascunho = $this->installer->loadDraft((string) $req->input('draft', ''));
        if ($rascunho === null) {
            $this->session->flash('error', 'Revisão expirada. Preencha os dados novamente.');

            return Response::redirect($this->url($req, '/install'));
        }

        return $this->render('install/review', [
            'draft' => $rascunho,
            'token' => $req->input('access_token', ''),
        ]);
    }

    /**
     * Conclui a instalação. Aceita GET e POST de propósito.
     *
     * GET só acontece quando alguém abre/atualiza este endereço direto (o botão
     * de recarregar depois de confirmar, um favorito, a barra do navegador).
     * Nesse caso **nada é criado**: a pessoa é levada para a tela de conclusão
     * ou de volta ao início. Antes isso devolvia um 405 cru, e parecia que a
     * instalação tinha travado nessa URL.
     */
    public function run(Request $req): Response
    {
        // Sem os campos do formulário não há o que executar. Isto cobre o GET,
        // o reenvio vazio e qualquer POST sem corpo.
        if ($req->input('draft') === '' || $req->input('csrf_token') === '') {
            if (!$this->guardAccess($req)) {
                return $this->denied($req);
            }
            if (!$this->installer->needed()) {
                return $this->done();
            }
            $this->session->flash('error', 'A confirmação precisa ser feita pelo botão da revisão. Recomece por aqui.');

            return Response::redirect($this->url($req, '/install'));
        }

        if (!$this->guardAccess($req)) {
            return $this->denied($req);
        }
        if (!$this->installer->needed()) {
            return $this->done();
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            $this->session->flash('error', 'Sessão expirada. Refaça a revisão.');

            return Response::redirect($this->url($req, '/install'));
        }
        $rascunho = $this->installer->loadDraft((string) $req->input('draft', ''));
        if ($rascunho === null) {
            $this->session->flash('error', 'Revisão expirada. Preencha os dados novamente.');

            return Response::redirect($this->url($req, '/install'));
        }

        try {
            $this->installer->install($rascunho['data']);
        } catch (\Throwable $erro) {
            error_log('[SMIA][install] ' . $erro->getMessage());

            return $this->render('install/review', [
                'draft' => $rascunho,
                'token' => $req->input('access_token', ''),
                'errors' => ['Não foi possível concluir: ' . $erro->getMessage()],
            ], 500);
        }

        // Só descarta o rascunho DEPOIS de instalar com sucesso. Se a instalação
        // falhar, a revisão continua disponível e a pessoa pode tentar de novo
        // sem redigitar tudo.
        $this->installer->dropDraft($rascunho['id']);
        $this->session->regenerate();
        $this->session->flash('success', 'Instância criada. Entre com o e-mail e a senha que você definiu.');

        // Passo 3 do instalador: a tela "Instância pronta". É ela que mostra o
        // registro da instalação e o botão de login; ir direto ao /login pulava
        // essa confirmação.
        //
        // Redireciona (302) em vez de renderizar aqui: assim o F5 não reenvia o
        // formulário e a URL fica limpa. O GET em /install/run, agora que a
        // instância existe, entrega a tela de conclusão.
        return Response::redirect($this->url($req, '/install/run'));
    }

    public function cancel(Request $req): Response
    {
        if ($this->guardAccess($req) && $this->session->csrfValid($req->input('csrf_token'))) {
            $this->installer->dropDraft((string) $req->input('draft', ''));
        }

        return Response::redirect($this->url($req, '/install'));
    }

    /**
     * URL interna do instalador **com o token**, quando houver.
     *
     * Todo redirecionamento daqui passa por este método. Antes, um voltar para
     * `/install` perdia o `?access_token=`, e o próprio guarda de acesso
     * devolvia 403 — o usuário era jogado na tela de "acesso restrito" no meio
     * do assistente, sem entender que só faltava o parâmetro na URL.
     */
    private function url(Request $req, string $caminho, array $extras = []): string
    {
        $url = $this->config->url($caminho);
        $parametros = $extras;
        $token = (string) $req->input('access_token', '');
        if ($token !== '') {
            $parametros += ['access_token' => $token];
        }

        return $parametros === [] ? $url : $url . '?' . http_build_query($parametros);
    }

    /**
     * Porta de entrada de quem abriu o instalador sem token.
     *
     * Valida o valor digitado e redireciona para o destino pedido **com** o
     * token na URL. Sem isso, o único caminho era copiar o token do `.env` e
     * montar a URL à mão.
     */
    public function access(Request $req): Response
    {
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->render('install/access', [
                'next' => $this->nextPath($req),
                'errors' => ['Sessão expirada. Abra o instalador novamente e tente de novo.'],
            ], 400);
        }

        $token = (string) $req->input('access_token', '');
        if (!$this->installer->tokenRequired() || !$this->installer->tokenValid($token)) {
            // Não repetimos o valor digitado, nem no HTML nem no log.
            return $this->render('install/access', [
                'next' => $this->nextPath($req),
                'errors' => ['Token incorreto. Confira o valor de SOCIALMEDIA_INSTALL_TOKEN no .env (sem espaços, sem aspas).'],
            ], 403);
        }

        $destino = $this->config->url($this->nextPath($req)) . '?access_token=' . rawurlencode($token);

        return Response::redirect($destino);
    }

    /** Caminho interno permitido como destino ("next"), sempre dentro de /install. */
    private function nextPath(Request $req): string
    {
        // Sem campo "next" na requisição o destino é /install. Importante:
        // input() devolve null (e não o padrão) quando a chave não existe — um
        // parse_url(null) estourava e o app caía no handler de erro 500.
        $pedido = (string) ($req->input('next') ?? '/install');
        if ($pedido === '') {
            $pedido = '/install';
        }
        // Só aceita rotas do instalador: nada de redirecionamento aberto.
        return $this->safePath((string) parse_url($pedido, PHP_URL_PATH));
    }

    private function guardAccess(Request $req): bool
    {
        return !$this->installer->tokenRequired() || $this->installer->tokenValid($req->input('access_token'));
    }

    /**
     * 403 com a tela de acesso. Guarda de onde a pessoa veio: quem estava em
     * /install/run volta para /install/run depois de colar o token — e não
     * para /install, que numa instância já criada responde 410.
     */
    private function denied(Request $req): Response
    {
        // O destino é onde a pessoa TENTOU entrar (o path da requisição), não
        // o campo "next" do formulário — que ainda não foi preenchido.
        return $this->render('install/access', ['next' => $this->safePath($req->path)], 403);
    }

    /** Aceita só caminhos do instalador; qualquer outra coisa vira /install. */
    private function safePath(string $caminho): string
    {
        $caminho = '/' . trim($caminho, '/');
        if ($caminho === '/' || !preg_match('#^/install(/[a-z]+)?$#', $caminho)) {
            return '/install';
        }

        return $caminho;
    }

    private function closed(): Response
    {
        return $this->render('install/closed', [
            'record' => $this->installer->installedRecord(),
            'concluida' => false,
        ], 410);
    }

    /**
     * Passo 3 ("Instância pronta"). 200 porque é o fim feliz do assistente —
     * o 410 fica para quem tenta reabrir /install numa instância já instalada.
     */
    private function done(): Response
    {
        return $this->render('install/closed', [
            'record' => $this->installer->installedRecord(),
            'concluida' => true,
        ]);
    }

    private function detectedOrigin(Request $req): string
    {
        $esquema = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443')
            ? 'https'
            : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $esquema . '://' . $host . $this->config->basePath;
    }

    /** @param array<string,mixed> $dados */
    private function render(string $template, array $dados, int $status = 200): Response
    {
        $resposta = $this->view->render($template, $dados + [
            'session' => $this->session,
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
        ], $status);

        return $resposta
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withSecurityHeaders();
    }
}
