<?php

declare(strict_types=1);

namespace Smia;

use Smia\Admin\AdminRepository;
use Smia\Admin\SystemReport;
use Smia\Admin\Updater;
use Smia\Admin\SettingsRepository;
use Smia\Approvals\ApprovalRepository;
use Smia\Approvals\PortalRepository;
use Smia\Auth\Authenticator;
use Smia\Clients\ClientRepository;
use Smia\Controllers\AdminController;
use Smia\Controllers\ApprovalController;
use Smia\Controllers\AuthController;
use Smia\Controllers\ClientController;
use Smia\Controllers\DashboardController;
use Smia\Controllers\FeedController;
use Smia\Controllers\InstallerController;
use Smia\Controllers\PlannerController;
use Smia\Controllers\PortalController;
use Smia\Controllers\MetaController;
use Smia\Controllers\ReportController;
use Smia\Controllers\ScheduleController;
use Smia\Controllers\SettingsController;
use Smia\Controllers\StudioController;
use Smia\Database\Database;
use Smia\Feed\FeedRepository;
use Smia\Database\Schema;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Http\Router;
use Smia\Installer\Installer;
use Smia\Meta\MetaRepository;
use Smia\Meta\ScheduleRepository;
use Smia\Planner\PlanGenerator;
use Smia\Planner\PlannerRepository;
use Smia\Reports\ReportRepository;
use Smia\Studio\StudioGenerator;
use Smia\Studio\StudioRepository;
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

        try {
            $this->router = new Router();
            $this->routes();
        } catch (\Throwable $erro) {
            // Falha ao montar as rotas não pode virar tela em branco: aparece
            // a causa, que é o que permite consertar sem adivinhar.
            error_log('[SMIA][boot] ' . $erro::class . ': ' . $erro->getMessage() . ' em ' . $erro->getFile() . ':' . $erro->getLine());

            throw $erro;
        }
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

        // A chave pode ter sido gravada em Configurações (banco). A precedência
        // é resolvida num lugar só — banco > .env > ambiente — e o resto do
        // app continua lendo `$config->openaiKey` sem saber de onde veio.
        $ajustes = new SettingsRepository($this->db, $this->config);
        $configIa = $ajustes->configEfetiva($this->config);

        $planner = new PlannerController(
            $configIa,
            new PlannerRepository($this->db),
            new PlanGenerator(
                $configIa->openaiKey !== '' ? $configIa->openaiKey : null,
                $configIa->openaiModel,
                90,
                $configIa->openaiEndpoint,
            ),
            $auth,
            $this->session,
            $this->view(),
        );
        $aprovacoes = new ApprovalController(
            $this->config,
            new ApprovalRepository($this->db),
            new PortalRepository($this->db),
            $this->upload,
            $auth,
            $this->session,
            $this->view(),
        );
        // O portal é uma sessão separada (portal_user_id) e não recebe o
        // Authenticator: nenhuma rota do portal consegue enxergar a agência.
        $portal = new PortalController(
            $this->config,
            new ApprovalRepository($this->db),
            new PortalRepository($this->db),
            $this->upload,
            $this->session,
            $this->view(),
        );
        $feed = new FeedController(
            $this->config,
            new FeedRepository($this->db),
            $auth,
            $this->session,
            $this->view(),
        );
        // Agenda e conexões Meta compartilham o mesmo repositório de conexões:
        // a agenda decide se o botão de publicar aparece com base nele.
        $metaRepo = new MetaRepository($this->db, new \Smia\Approvals\PortalRepository($this->db));
        $agendaPub = new ScheduleRepository($this->db);
        $agenda = new ScheduleController(
            $this->config,
            $agendaPub,
            $metaRepo,
            $auth,
            $this->session,
            $this->view(),
        );
        $meta = new MetaController(
            $this->config,
            $metaRepo,
            $agendaPub,
            $auth,
            $this->session,
            $this->view(),
        );
        $relatorios = new ReportController(
            $this->config,
            new ReportRepository($this->db),
            $auth,
            $this->session,
            $this->view(),
        );
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
        // Porta de entrada do token (a tela de 403 tem o formulário).
        $this->router->any('/install/access', $installer->access(...));
        // Somente leitura e sem token na URL: serve para descobrir a causa.
        $this->router->get('/install/diagnostico', $installer->diagnostics(...));
        $this->router->post('/install/validate', $installer->validate(...));
        $this->router->get('/install/review', $installer->review(...));
        // GET + POST: abrir /install/run no navegador não pode virar 405.
        $this->router->any('/install/run', $installer->run(...));
        $this->router->any('/install/cancel', $installer->cancel(...));

        $this->router->get('/login', $authController->loginForm(...));
        $this->router->post('/login', $authController->login(...));
        $this->router->post('/logout', $authController->logout(...));

        $this->router->get('/dashboard', $dashboard->index(...));

        // --- Configurações e área do dono ------------------------------
        // O instalador é reusado: as checagens de ambiente dele são as mesmas
        // que a aba Sistema mostra, e duplicar a lógica faria as duas
        // discordarem com o tempo.
        $instaladorAjustes = new Installer($this->config, $this->db, $this->config->dataDir . '/installer-drafts');
        $ajustesCtl = new SettingsController(
            $this->config,
            $ajustes,
            new AdminRepository($this->db),
            $auth,
            $this->session,
            $this->view(),
            new SystemReport($this->config, $this->db, $instaladorAjustes),
            $instaladorAjustes,
        );
        $this->router->get('/settings', $ajustesCtl->index(...));
        $this->router->post('/settings/ia/chave', $ajustesCtl->saveKey(...));
        $this->router->post('/settings/ia/chave/remover', $ajustesCtl->forgetKey(...));
        $this->router->post('/settings/ia/testar', $ajustesCtl->testKey(...));
        $this->router->post('/settings/ia/modelos', $ajustesCtl->saveModels(...));
        $this->router->post('/settings/agencia', $ajustesCtl->saveCompany(...));
        $this->router->post('/settings/preferencias', $ajustesCtl->savePreferences(...));
        $this->router->post('/settings/preferencias/restaurar', $ajustesCtl->resetPreferences(...));
        $this->router->post('/settings/conta', $ajustesCtl->saveAccount(...));
        $this->router->post('/settings/sistema/backup', $ajustesCtl->downloadBackup(...));

        $dono = new AdminController(
            $this->config,
            new AdminRepository($this->db),
            $auth,
            $this->session,
            $this->view(),
            new Updater($this->config, $this->db, $instaladorAjustes),
        );
        $this->router->get('/admin', $dono->index(...));
        $this->router->post('/admin/agencias', $dono->createCompany(...));
        $this->router->post('/admin/agencias/{id}', $dono->updateCompany(...));
        $this->router->post('/admin/agencias/{id}/ativar', $dono->toggleCompany(...));
        $this->router->post('/admin/usuarios', $dono->createUser(...));
        $this->router->post('/admin/usuarios/{id}', $dono->updateUser(...));
        $this->router->post('/admin/usuarios/{id}/senha', $dono->resetPassword(...));
        $this->router->post('/admin/usuarios/{id}/ativar', $dono->toggleUser(...));
        // Atualização pelo painel: só o dono da instalação.
        $this->router->get('/admin/atualizacao/consultar', $dono->checkUpdate(...));
        $this->router->post('/admin/atualizacao/aplicar', $dono->applyUpdate(...));
        $this->router->post('/admin/atualizacao/restaurar', $dono->restoreUpdate(...));

        // --- Clientes -------------------------------------------------
        $studio = new StudioController(
            $configIa,
            new StudioRepository($this->db),
            new StudioGenerator(
                $configIa->openaiKey !== '' ? $configIa->openaiKey : null,
                $configIa->openaiModel,
                $configIa->openaiImageModel,
                90,
                180,
                $configIa->openaiEndpoint,
                $configIa->openaiImageEndpoint,
            ),
            $this->upload,
            $auth,
            $this->session,
            $this->view(),
            // O estúdio começa com as preferências da agência (Configurações).
            $ajustes,
        );

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

        // --- Planejamento ---------------------------------------------
        $this->router->get('/planner', $planner->index(...));
        $this->router->post('/planner/generate', $planner->generate(...));
        $this->router->get('/planner/posts/{id}/edit', $planner->editPost(...));
        $this->router->post('/planner/posts/{id}/edit', $planner->editPost(...));
        $this->router->post('/planner/posts/{id}/delete', $planner->deletePost(...));
        $this->router->post('/planner/posts/{id}/duplicate', $planner->duplicatePost(...));
        $this->router->get('/planner/{id}', $planner->show(...));
        $this->router->post('/planner/{id}/production', $planner->production(...));
        $this->router->post('/planner/{id}/delete', $planner->delete(...));

        // --- Estúdio ---------------------------------------------------
        $this->router->get('/studio', $studio->index(...));
        $this->router->post('/studio/generate', $studio->generate(...));
        $this->router->get('/studio/files', $studio->file(...));
        $this->router->get('/studio/posts/{id}', $studio->editor(...));
        $this->router->post('/studio/posts/{id}/save', $studio->save(...));
        $this->router->post('/studio/posts/{id}/image', $studio->generateImage(...));
        $this->router->post('/studio/posts/{id}/image/upload', $studio->uploadImage(...));
        $this->router->post('/studio/posts/{id}/export', $studio->export(...));
        $this->router->get('/studio/posts/{id}/download', $studio->download(...));
        $this->router->post('/studio/posts/{id}/variation', $studio->variation(...));
        $this->router->post('/studio/posts/{id}/versions/{version}/restore', $studio->restore(...));
        $this->router->post('/studio/posts/{id}/duplicate', $studio->duplicate(...));
        $this->router->post('/studio/posts/{id}/delete', $studio->delete(...));

        // --- Feed ------------------------------------------------------
        $this->router->get('/feed', $feed->index(...));
        $this->router->post('/feed/{client_id}/add/creative/{id}', $feed->addCreative(...));
        $this->router->post('/feed/{client_id}/add/post/{id}', $feed->addPost(...));
        $this->router->post('/feed/{client_id}/remove/{id}', $feed->remove(...));
        $this->router->post('/feed/{client_id}/reorder', $feed->reorder(...));

        // --- Agenda interna e Meta --------------------------------------
        $this->router->get('/schedule', $agenda->index(...));
        $this->router->post('/schedule', $agenda->store(...));
        $this->router->post('/schedule/{id}/reschedule', $agenda->reschedule(...));
        $this->router->post('/schedule/{id}/cancel', $agenda->cancel(...));
        $this->router->post('/schedule/{id}/publish', $agenda->publish(...));
        $this->router->post('/schedule/meta-attempt/{id}/reconcile', $meta->reconcile(...));
        $this->router->get('/meta', $meta->index(...));
        $this->router->post('/meta/connect', $meta->connect(...));
        $this->router->get('/integrations/meta/callback', $meta->callback(...));
        $this->router->post('/meta/{id}/test', $meta->test(...));
        $this->router->post('/meta/{id}/disconnect', $meta->disconnect(...));
        $this->router->post('/meta/graph-check', $meta->graphCheck(...));
        $this->router->get('/meta/media/{arquivo}', $meta->media(...));

        // --- Relatórios ------------------------------------------------
        $this->router->get('/reports', $relatorios->index(...));
        $this->router->get('/reports/export.csv', $relatorios->export(...));

        // --- Aprovações (agência) --------------------------------------
        $this->router->get('/approvals', $aprovacoes->index(...));
        $this->router->post('/approvals', $aprovacoes->send(...));
        $this->router->post('/approvals/portal-users', $aprovacoes->createPortalUser(...));
        $this->router->post('/approvals/portal-users/{id}/toggle', $aprovacoes->togglePortalUser(...));
        $this->router->post('/approvals/notifications/read', $aprovacoes->readNotifications(...));
        $this->router->get('/approvals/{id}', $aprovacoes->show(...));
        $this->router->post('/approvals/{id}/comment', $aprovacoes->comment(...));
        $this->router->post('/approvals/{id}/cancel', $aprovacoes->cancel(...));
        $this->router->get('/approvals/{id}/media/{filename}', $aprovacoes->media(...));

        // --- Portal do cliente -----------------------------------------
        $this->router->get('/portal/login', $portal->loginForm(...));
        $this->router->post('/portal/login', $portal->login(...));
        $this->router->post('/portal/logout', $portal->logout(...));
        $this->router->get('/portal/approvals', $portal->index(...));
        $this->router->get('/portal/approvals/{id}', $portal->show(...));
        $this->router->post('/portal/approvals/{id}/respond', $portal->respond(...));
        $this->router->post('/portal/approvals/{id}/comment', $portal->comment(...));
        $this->router->get('/portal/approvals/{id}/media/{filename}', $portal->media(...));
        $this->router->post('/portal/notifications/read', $portal->readNotifications(...));
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
            $detalhe = '';
            if ($this->config->debug) {
                $detalhe = '<pre style="background:#fee;padding:12px;overflow:auto;white-space:pre-wrap">'
                    . e($erro::class . ': ' . $erro->getMessage()) . "\n"
                    . e($erro->getFile() . ':' . $erro->getLine()) . '</pre>';
            }
            $resposta = Response::html(
                '<!doctype html><meta charset="utf-8"><title>Erro</title>'
                . '<div style="font-family:system-ui;padding:40px">'
                . '<h1>Algo falhou nesta página</h1>'
                . '<p>O erro foi registrado no log do servidor. Se acabou de instalar, confira as permissões da pasta de dados.</p>'
                . $detalhe
                . '<p><a href="' . e($this->config->url('/dashboard')) . '">Voltar ao workspace</a></p></div>',
                500
            );
        }

        return $resposta->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
