<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Auth\Authenticator;
use Smia\Database\Database;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Support\Config;
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
        $auditoria = $this->db->all(
            'SELECT action, detail, created_at FROM audit_log WHERE company_id = ? ORDER BY id DESC LIMIT 6',
            [$usuario['company_id']]
        );

        return $this->view->render('dashboard/index', [
            'user' => $usuario,
            'installation' => $instalacao,
            'team_count' => $equipe,
            'audit' => $auditoria,
            'modules' => self::MODULES,
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
        ])->withSecurityHeaders();
    }

    /** Módulos do produto, na ordem em que serão portados. */
    public const MODULES = [
        ['slug' => 'clients', 'name' => 'Clientes', 'hint' => 'Contas, identidade visual e briefing', 'ready' => false],
        ['slug' => 'planner', 'name' => 'Planejamento', 'hint' => 'Calendário editorial e pautas', 'ready' => false],
        ['slug' => 'studio', 'name' => 'Estúdio', 'hint' => 'Criação de artes e legendas', 'ready' => false],
        ['slug' => 'approvals', 'name' => 'Aprovações', 'hint' => 'Portal do cliente e revisões', 'ready' => false],
        ['slug' => 'feed', 'name' => 'Feed', 'hint' => 'Prévia do perfil e grade', 'ready' => false],
        ['slug' => 'reports', 'name' => 'Relatórios', 'hint' => 'Métricas e entregas', 'ready' => false],
        ['slug' => 'meta', 'name' => 'Conexões Meta', 'hint' => 'Páginas, Instagram e publicação', 'ready' => false],
        ['slug' => 'settings', 'name' => 'Configurações', 'hint' => 'Equipe, segurança e integrações', 'ready' => false],
    ];
}
