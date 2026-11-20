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
            return $this->denied();
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
            return $this->denied();
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
        $destino = $this->config->url('/install/review') . '?draft=' . $id;
        if ($req->input('access_token')) {
            $destino .= '&access_token=' . rawurlencode((string) $req->input('access_token'));
        }

        return Response::redirect($destino);
    }

    public function review(Request $req): Response
    {
        if (!$this->guardAccess($req)) {
            return $this->denied();
        }
        if (!$this->installer->needed()) {
            return $this->closed();
        }
        $rascunho = $this->installer->loadDraft((string) $req->input('draft', ''));
        if ($rascunho === null) {
            $this->session->flash('error', 'Revisão expirada. Preencha os dados novamente.');

            return Response::redirect($this->config->url('/install'));
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
                return $this->denied();
            }
            if (!$this->installer->needed()) {
                return $this->done();
            }
            $this->session->flash('error', 'A confirmação precisa ser feita pelo botão da revisão. Recomece por aqui.');

            return Response::redirect($this->config->url('/install'));
        }

        if (!$this->guardAccess($req)) {
            return $this->denied();
        }
        if (!$this->installer->needed()) {
            return $this->done();
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            $this->session->flash('error', 'Sessão expirada. Refaça a revisão.');

            return Response::redirect($this->config->url('/install'));
        }
        $rascunho = $this->installer->loadDraft((string) $req->input('draft', ''));
        if ($rascunho === null) {
            $this->session->flash('error', 'Revisão expirada. Preencha os dados novamente.');

            return Response::redirect($this->config->url('/install'));
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
        $destino = $this->config->url('/install/run');
        if ($this->installer->tokenRequired()) {
            $destino .= '?access_token=' . rawurlencode((string) $this->config->installToken);
        }

        return Response::redirect($destino);
    }

    public function cancel(Request $req): Response
    {
        if ($this->guardAccess($req) && $this->session->csrfValid($req->input('csrf_token'))) {
            $this->installer->dropDraft((string) $req->input('draft', ''));
        }

        return Response::redirect($this->config->url('/install'));
    }

    private function guardAccess(Request $req): bool
    {
        return !$this->installer->tokenRequired() || $this->installer->tokenValid($req->input('access_token'));
    }

    private function denied(): Response
    {
        return $this->render('install/access', [], 403);
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
