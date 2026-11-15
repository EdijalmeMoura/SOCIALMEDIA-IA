<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Auth\Authenticator;
use Smia\Clients\ClientRepository;
use Smia\Database\Database;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Support\Config;
use Smia\Support\Modules;
use Smia\Support\Session;
use Smia\Support\View;

/** Primeira tela autenticada: visão do workspace e atalhos dos módulos. */
final class DashboardController
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly Authenticator $auth,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function index(Request $req): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }

        $instalacao = $this->db->one('SELECT * FROM installation WHERE id = 1');
        $equipe = (int) ($this->db->scalar('SELECT COUNT(*) FROM users WHERE company_id = ?', [$usuario['company_id']]) ?? 0);
        $clientes = new ClientRepository($this->db);
        $auditoria = $this->db->all(
            'SELECT action, detail, created_at FROM audit_log WHERE company_id = ? ORDER BY id DESC LIMIT 6',
            [$usuario['company_id']]
        );

        return $this->view->render('dashboard/index', [
            'user' => $usuario,
            'installation' => $instalacao,
            'team_count' => $equipe,
            'audit' => $auditoria,
            'modules' => Modules::all(),
            'client_total' => $clientes->count((int) $usuario['company_id']),
            'client_summary' => $clientes->summary((int) $usuario['company_id']),
            'recent_clients' => $this->db->all(
                'SELECT id, company_name, trade_name, segment, created_at FROM clients WHERE company_id = ? ORDER BY id DESC LIMIT 4',
                [$usuario['company_id']]
            ),
            'active' => null,
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
        ])->withSecurityHeaders();
    }
}
