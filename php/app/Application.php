<?php

declare(strict_types=1);

namespace Smia;

use Smia\Auth\Authenticator;
use Smia\Clients\ClientRepository;
use Smia\Controllers\AuthController;
use Smia\Controllers\ClientController;
use Smia\Controllers\DashboardController;
use Smia\Controllers\InstallerController;
use Smia\Database\Database;
use Smia\Database\Schema;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Http\Router;
use Smia\Installer\Installer;
use Smia\Support\Config;
use Smia\Support\Session;
use Smia\Support\Upload;
use Smia\Support\View;

/**
 * Monta a aplicação: configuração, banco, sessão, rotas.
 *
 * Tudo recebe dependências explícitas, então os testes podem construir uma
 * instância isolada (banco em memória/arquivo temporário) sem servidor web.
 */
final class Application
{
    public readonly Router $router;
    public readonly Session $session;
    public readonly Database $db;
    public readonly Installer $installer;
    public readonly Upload $upload;

    public readonly Config $config;

    public function __construct(Config $config, ?Database $db = null, ?string $detectedBase = null)
    {
        $detectado = Config::normalizeBasePath($detectedBase);
        if ($config->basePath === '' && $detectado !== '') {
            $config = $config->withBasePath($detectado);
        }
        $this->config = $config;
        $this->db = $db ?? new Database($config->dbPath);
        Schema::migrate($this->db);
        $this->session = new Session($this->config->basePath, $this->config->cookieSecure);
        $this->installer = Installer::make($this->config, $this->db);
        $this->upload = new Upload($this->config->dataDir . '/uploads');
        $this->router = new Router();
        $this->routes();
    }

    private function view(): View
    {
        return new View(SMIA_APP . '/views', $this->config);
    }

    private function routes(): void
    {
        $auth = new Authenticator($this->db, $this->session);
        $installer = new InstallerController($this->config, $this->installer, $this->session, $this->view());
        $authController = new AuthController(
            $this->config,
            $auth,
            $this->session,
            $this->view(),
            fn (): bool => $this->installer->needed(),
        );
        $dashboard = new DashboardController($this->config, $this->db, $auth, $this->session, $this->view());
        $clientes = new ClientController(
            $this->config,
            new ClientRepository($this->db),
            $auth,
            $this->session,
            $this->view(),
            $this->upload,
        );

        $this->router->get('/', $authController->root(...));

        $this->router->get('/install', $installer->show(...));
        $this->router->post('/install/validate', $installer->validate(...));
        $this->router->get('/install/review', $installer->review(...));
        $this->router->post('/install/run', $installer->run(...));
        $this->router->post('/install/cancel', $installer->cancel(...));

        $this->router->get('/login', $authController->loginForm(...));
        $this->router->post('/login', $authController->login(...));
        $this->router->post('/logout', $authController->logout(...));

        $this->router->get('/dashboard', $dashboard->index(...));

        // --- Clientes -------------------------------------------------
        $this->router->get('/clients', $clientes->index(...));
        $this->router->get('/clients/new', $clientes->createForm(...));
        $this->router->post('/clients/new', $clientes->create(...));
        // A imagem tem que ser servida pelo roteador: public/ não conhece
        // o id do cliente, e a checagem de dono fica no controller.
        $this->router->get('/clients/file', $clientes->file(...));
        $this->router->get('/clients/{id}', $clientes->show(...));
        $this->router->post('/clients/{id}', $clientes->update(...));
        $this->router->post('/clients/{id}/delete', $clientes->delete(...));
        $this->router->post('/clients/{id}/logo', $clientes->uploadLogo(...));
        $this->router->post('/clients/{id}/assets', $clientes->uploadAsset(...));
        $this->router->post('/clients/{id}/assets/{asset}/delete', $clientes->deleteAsset(...));
    }

    /**
     * Trata uma requisição já resolvida (usado pelo front controller e pelos
     * testes). Cabeçalhos de segurança entram aqui, uma vez só.
     */
    public function handle(Request $req): Response
    {
        $this->session->start();
        $GLOBALS['smia_base_path'] = $this->config->basePath;

        try {
            $resposta = $this->router->dispatch($req);
        } catch (\Throwable $erro) {
            error_log('[SMIA] ' . $erro::class . ': ' . $erro->getMessage() . ' em ' . $erro->getFile() . ':' . $erro->getLine());
            $resposta = Response::html(
                '<!doctype html><meta charset="utf-8"><title>Erro</title>'
                . '<div style="font-family:system-ui;padding:40px">'
                . '<h1>Algo falhou nesta página</h1>'
                . '<p>O erro foi registrado no log do servidor. Se acabou de instalar, confira as permissões da pasta de dados.</p>'
                . '<p><a href="' . e($this->config->url('/dashboard')) . '">Voltar ao workspace</a></p></div>',
                500
            );
        }

        return $resposta->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
