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
        private readonly ?\Smia\Admin\AdminRepository $admin = null,
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

    /**
     * POST /trocar-agencia — muda a agência em uso sem sair da conta.
     *
     * Serve para o dono da instalação alternar entre agências pelo menu. Quem
     * não é dono tem uma única agência na lista e o servidor recusa qualquer
     * outro id — a lista de agências disponíveis é a autorização.
     *
     * A escolha fica na sessão; nada é gravado no usuário, então fechar o
     * navegador volta para a agência da própria conta.
     */
    public function switchCompany(Request $req): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            $this->session->flash('error', 'A sessão do navegador venceu. Recarregue a página e escolha a agência de novo.');

            return Response::redirect($this->voltarPara($req));
        }

        $alvo = (int) $req->input('company_id', '0');
        $resultado = $this->auth->trocarAgencia($usuario, $alvo);

        if ($resultado['ok'] !== true) {
            $this->session->flash('error', $resultado['erro']);

            return Response::redirect($this->voltarPara($req));
        }

        $agencia = $resultado['agencia'];
        if ($agencia !== null && $this->admin !== null) {
            // Fica na trilha do dono: trocar de agência muda o que a pessoa vê.
            $this->admin->registrar(
                (int) $usuario['id'],
                (int) ($usuario['company_id'] ?? 0),
                'company.switched',
                'para ' . $agencia['name']
            );
        }
        if ($agencia !== null) {
            $this->session->flash('success', 'Agora você está em ' . $agencia['name'] . '.');
        }

        return Response::redirect($this->voltarPara($req));
    }

    /**
     * Destino depois da troca: a página em que a pessoa estava, se for da
     * própria instalação. Evita jogar todo mundo no dashboard e perder o
     * contexto (por exemplo, estava no Estúdio de um cliente).
     */
    private function voltarPara(Request $req): string
    {
        $pedido = (string) $req->input('voltar', '');
        $base = $this->config->basePath;

        if ($pedido === '' || !str_starts_with($pedido, '/') || str_starts_with($pedido, '//')) {
            return $this->config->url('/dashboard');
        }
        // Só caminho interno: nada de protocolo, host ou caminho externo.
        if (preg_match('#^/[a-z0-9/_\-]*$#i', $pedido) !== 1) {
            return $this->config->url('/dashboard');
        }
        // Já vem com o prefixo público? Então é só devolver.
        if ($base !== '' && str_starts_with($pedido, $base)) {
            return $pedido;
        }

        return rtrim($this->config->url($pedido), '/') ?: $this->config->url('/dashboard');
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
