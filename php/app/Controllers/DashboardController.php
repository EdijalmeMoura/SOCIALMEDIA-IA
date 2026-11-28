<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Auth\Authenticator;
use Smia\Clients\ClientRepository;
use Smia\Database\DashboardRepository;
use Smia\Database\Database;
use Smia\Meta\ScheduleRepository;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Support\Config;
use Smia\Support\Modules;
use Smia\Support\Session;
use Smia\Support\View;

/**
 * Primeira tela autenticada.
 *
 * Antes era a ficha técnica da instância: PHP, data de instalação e módulos
 * prontos. Com seis posts planejados e duas aprovações, os cartões diziam
 * "0" — não havia nada sobre o trabalho. Agora abre com o que pede atenção
 * hoje, e a ficha técnica continua no rodapé para quem precisa dela.
 */
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
        $empresa = (int) $usuario['company_id'];
        $equipe = (int) ($this->db->scalar('SELECT COUNT(*) FROM users WHERE company_id = ?', [$empresa]) ?? 0);
        $clientes = new ClientRepository($this->db);
        $auditoria = $this->db->all(
            'SELECT action, detail, created_at FROM audit_log WHERE company_id = ? ORDER BY id DESC LIMIT 6',
            [$empresa]
        );

        $hoje = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $painel = new DashboardRepository($this->db);
        $numeros = $painel->numeros($empresa, $hoje);
        $agenda = $painel->agenda($empresa);
        foreach ($agenda as $indice => $linha) {
            $agenda[$indice]['quando'] = ScheduleRepository::rotuloLocal((string) $linha['scheduled_at_utc'], (string) $linha['timezone']);
            $agenda[$indice]['dia'] = ScheduleRepository::diaRelativo((string) $linha['scheduled_at_utc'], (string) $linha['timezone']);
            $agenda[$indice]['vencido'] = strtotime((string) $linha['scheduled_at_utc'] . ' UTC') <= time();
        }

        return $this->view->render('dashboard/index', [
            'numeros' => $numeros,
            'proximas' => $painel->proximasPublicacoes($empresa, $hoje),
            'pendentes' => $painel->aprovacoesPendentes($empresa),
            'agenda' => $agenda,
            'atalhos' => $painel->atalhos($empresa, $numeros),
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
