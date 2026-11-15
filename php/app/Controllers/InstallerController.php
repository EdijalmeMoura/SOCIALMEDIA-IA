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

    public function run(Request $req): Response
    {
        if (!$this->guardAccess($req)) {
            return $this->denied();
        }
        if (!$this->installer->needed()) {
            return $this->closed();
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
            $resumo = $this->installer->install($rascunho['data']);
        } catch (\Throwable $erro) {
            error_log('[SMIA][install] ' . $erro->getMessage());

            return $this->render('install/review', [
                'draft' => $rascunho,
                'token' => $req->input('access_token', ''),
                'errors' => ['Não foi possível concluir: ' . $erro->getMessage()],
            ], 500);
        }

        $this->installer->dropDraft($rascunho['id']);
        $this->session->regenerate();
        $this->session->flash('success', 'Instância criada. Entre com o e-mail e a senha que você definiu.');

        return Response::redirect($this->config->url('/login?installed=1'));
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
        return $this->render('install/closed', ['record' => $this->installer->installedRecord()], 410);
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
