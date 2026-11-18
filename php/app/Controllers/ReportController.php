<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Auth\Authenticator;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Reports\ReportError;
use Smia\Reports\ReportRepository;
use Smia\Support\Config;
use Smia\Support\Modules;
use Smia\Support\Session;
use Smia\Support\View;

/**
 * Relatórios: o que a agência fez no período, com exportação em CSV.
 *
 * O recorte padrão é o mês corrente. Recorte inválido não derruba a tela: a
 * página volta com o padrão e uma mensagem — relatório é consulta, não
 * transação.
 */
final class ReportController
{
    public function __construct(
        private readonly Config $config,
        private readonly ReportRepository $repo,
        private readonly Authenticator $auth,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function index(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $empresa = (int) $usuario['company_id'];
        $clientes = $this->repo->clientes($empresa);

        $erro = null;
        $clientId = $this->clientePedido($req, $empresa, $erro);
        if ($erro !== null) {
            // Marca que não é desta empresa é 404; id malformado volta com aviso.
            $pedido = (string) ($req->input('client_id') ?? '');
            if (preg_match('/^\d+$/', $pedido) === 1) {
                return $this->naoEncontrado($req);
            }
            $clientId = null;
        }
        try {
            $periodo = ReportRepository::validarPeriodo($req->input('start_date'), $req->input('end_date'));
        } catch (ReportError $falha) {
            $erro = $falha->getMessage();
            [$inicio, $fim] = ReportRepository::periodoPadrao();
            $periodo = ['inicio' => $inicio, 'fim' => $fim];
        }

        return $this->render('reports/index', $req, [
            'clientes' => $clientes,
            'selecionado' => $clientId !== null ? $this->repo->cliente($clientId, $empresa) : null,
            'inicio' => $periodo['inicio'],
            'fim' => $periodo['fim'],
            'relatorio' => $this->repo->resumo($empresa, $periodo['inicio'], $periodo['fim'], $clientId),
            'consumo' => $this->repo->consumoIa($empresa, $periodo['inicio'], $periodo['fim'], $clientId),
            'feed_total' => $this->repo->totalFeed($empresa, $clientId),
            'erro' => $erro,
        ]);
    }

    /** CSV pronto para planilha (BOM + proteção contra fórmula). */
    public function export(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $empresa = (int) $usuario['company_id'];
        $erro = null;
        $clientId = $this->clientePedido($req, $empresa, $erro);
        if ($erro !== null) {
            return Response::text($erro, 400);
        }
        try {
            $periodo = ReportRepository::validarPeriodo($req->input('start_date'), $req->input('end_date'));
        } catch (ReportError $falha) {
            return Response::text($falha->getMessage(), 400);
        }

        $relatorio = $this->repo->resumo($empresa, $periodo['inicio'], $periodo['fim'], $clientId);
        $nomeCliente = 'Todos os clientes';
        if ($clientId !== null) {
            $cliente = $this->repo->cliente($clientId, $empresa);
            $nomeCliente = trim((string) ($cliente['trade_name'] ?? '')) ?: (string) ($cliente['company_name'] ?? $nomeCliente);
        }

        $linhas = [
            ['Relatório operacional SocialMedia IA'],
            ['Período (UTC)', $periodo['inicio'], $periodo['fim']],
            ['Cliente', $nomeCliente],
            ['Gerado em (UTC)', gmdate('Y-m-d H:i')],
            [],
            ['Indicador', 'Quantidade'],
        ];
        $rotulos = [
            'Posts planejados' => 'planned_posts',
            'Artes criadas' => 'creatives',
            'Artes exportadas' => 'exported_creatives',
            'Aprovações enviadas' => 'approvals',
            'Aprovações pendentes' => 'approval_pending',
            'Aprovadas' => 'approval_approved',
            'Alterações solicitadas' => 'approval_changes',
            'Rejeitadas' => 'approval_rejected',
            'Decisões de aprovação no período' => 'approved_events',
            'Pedidos de alteração no período' => 'changes_events',
            'Rejeições no período' => 'rejected_events',
        ];
        foreach ($rotulos as $rotulo => $chave) {
            $linhas[] = [$rotulo, (string) $relatorio['metrics'][$chave]];
        }
        $linhas[] = [];
        $linhas[] = ['Categoria', 'Cliente', 'Conteúdo', 'Rede', 'Data registrada (UTC)', 'Status interno'];
        foreach ($relatorio['activity'] as $linha) {
            $linhas[] = [
                $linha['category'],
                $linha['client'],
                $linha['title'],
                $linha['network'],
                $linha['date'],
                $linha['status'],
            ];
        }
        $linhas[] = [];
        $linhas[] = ['Observação', 'Este relatório usa registros internos do produto. Não inclui alcance, curtidas, impressões ou cliques de redes sociais.'];

        $csv = "\u{FEFF}";
        foreach ($linhas as $linha) {
            $csv .= implode(';', array_map([self::class, 'celula'], $linha)) . "\r\n";
        }

        return (new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="relatorio-socialmedia-' . $periodo['inicio'] . '-a-' . $periodo['fim'] . '.csv"',
            'Cache-Control' => 'private, no-store',
        ]))->withSecurityHeaders();
    }

    /**
     * Uma célula do CSV.
     *
     * Aspas quando há separador/quebra de linha e um apóstrofo na frente de
     * `=`, `+`, `-`, `@` — planilha não deve executar texto que veio do banco.
     */
    public static function celula(mixed $valor): string
    {
        $texto = (string) ($valor ?? '');
        if ($texto !== '' && in_array($texto[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $texto = "'" . $texto;
        }
        if (strpbrk($texto, ";\"\r\n") !== false) {
            $texto = '"' . str_replace('"', '""', $texto) . '"';
        }

        return $texto;
    }

    // ------------------------------------------------------------------

    /**
     * Cliente do recorte, se houver. Cliente de outra empresa responde 404 —
     * não faz sentido "relatório vazio" para marca que não é sua.
     */
    private function clientePedido(Request $req, int $empresa, ?string &$erro): ?int
    {
        $valor = (string) ($req->input('client_id') ?? '');
        if (trim($valor) === '') {
            return null;
        }
        if (!preg_match('/^\d+$/', $valor)) {
            $erro = 'Escolha um cliente válido.';

            return null;
        }
        $clientId = (int) $valor;
        if ($this->repo->cliente($clientId, $empresa) === null) {
            $erro = 'Escolha um cliente válido.';

            return null;
        }

        return $clientId;
    }

    /** @return array<string,mixed>|Response */
    private function precisaLogin(): array|Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }

        return $usuario;
    }

    private function naoEncontrado(Request $req): Response
    {
        return $this->render('reports/not_found', $req, [], 404);
    }

    private function render(string $view, Request $req, array $dados, int $status = 200): Response
    {
        return $this->view->render($view, $dados + [
            'user' => $this->auth->user(),
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
            'modules' => Modules::all(),
            'active' => 'reports',
            'current_path' => $req->path,
        ], $status)->withSecurityHeaders();
    }
}
