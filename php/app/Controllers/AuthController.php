<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Auth\Authenticator;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Support\Config;
use Smia\Support\Session;
use Smia\Support\View;

/** Login, logout e a raiz que decide para onde mandar o visitante. */
final class AuthController
{
    public function __construct(
        private readonly Config $config,
        private readonly Authenticator $auth,
        private readonly Session $session,
        private readonly View $view,
        private readonly \Closure $installerNeeded,
    ) {
    }

    public function root(Request $req): Response
    {
        if ($this->auth->user() !== null) {
            return Response::redirect($this->config->url('/dashboard'));
        }
        if (($this->installerNeeded)()) {
            return Response::redirect($this->config->url('/install'));
        }

        return Response::redirect($this->config->url('/login'));
    }

    public function loginForm(Request $req): Response
    {
        if ($this->auth->user() !== null) {
            return Response::redirect($this->config->url('/dashboard'));
        }
        if (($this->installerNeeded)()) {
            return Response::redirect($this->config->url('/install'));
        }

        return $this->render('auth/login', [
            'error' => null,
            'email' => '',
            'installed' => $req->input('installed') === '1',
        ]);
    }

    public function login(Request $req): Response
    {
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->render('auth/login', [
                'error' => 'Sessão expirada. Tente novamente.',
                'email' => (string) $req->input('email', ''),
                'installed' => false,
            ], 400);
        }

        $resultado = $this->auth->attempt(
            (string) $req->input('email', ''),
            (string) $req->input('password', ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')
        );
        if (!$resultado['ok']) {
            return $this->render('auth/login', [
                'error' => $resultado['error'],
                'email' => (string) $req->input('email', ''),
                'installed' => false,
            ], 401);
        }

        $this->session->flash('success', 'Bem-vindo(a) de volta.');

        return Response::redirect($this->config->url('/dashboard'));
    }

    public function logout(Request $req): Response
    {
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return Response::redirect($this->config->url('/dashboard'));
        }
        $this->auth->logout();

        return Response::redirect($this->config->url('/login'));
    }

    /** @param array<string,mixed> $dados */
    private function render(string $template, array $dados, int $status = 200): Response
    {
        return $this->view->render($template, $dados + [
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
        ], $status)->withSecurityHeaders();
    }
}
