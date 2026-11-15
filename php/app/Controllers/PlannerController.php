<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Auth\Authenticator;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Planner\PlanGenerator;
use Smia\Planner\PlannerError;
use Smia\Planner\PlannerRepository;
use Smia\Support\Config;
use Smia\Support\Modules;
use Smia\Support\Session;
use Smia\Support\View;

/**
 * Planejamento editorial: listagem, geração do calendário, edição de posts.
 *
 * O gerador recebe o perfil e a identidade do cliente; a gravação é transacional
 * (plano + posts + registro de consumo). Quando não há chave de IA, o plano é
 * montado localmente e a tela informa isso — o produto nunca finge que houve IA.
 */
final class PlannerController
{
    private const PAPEIS_EDICAO = ['owner', 'admin', 'editor'];

    public function __construct(
        private readonly Config $config,
        private readonly PlannerRepository $repo,
        private readonly PlanGenerator $gerador,
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

        return $this->render('planner/index', $req, [
            'clientes' => $this->repo->clientesAtivos($empresa),
            'planos' => $this->repo->planos($empresa),
            'ia_configurada' => $this->gerador->iaConfigurada(),
            'modelo' => $this->gerador->modelo(),
            'consumo' => $this->repo->consumoIa($empresa),
            'pode_editar' => $this->podeEditar($usuario),
            'hoje' => (new \DateTimeImmutable('now'))->format('Y-m-d'),
            'form' => [],
            'erro' => $req->input('erro'),
        ]);
    }

    public function generate(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $empresa = (int) $usuario['company_id'];
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->telaErro($req, 'Sessão expirada. Revise os dados e envie novamente.', $req->post, 400);
        }

        [$spec, $erros] = $this->normalizarSpec($req, $empresa);
        if ($erros !== []) {
            return $this->telaErro($req, implode(' ', $erros), $req->post, 400);
        }

        $cliente = null;
        foreach ($this->repo->clientesAtivos($empresa) as $candidato) {
            if ((int) $candidato['id'] === $spec['client_id']) {
                $cliente = $candidato;
                break;
            }
        }
        if ($cliente === null) {
            return $this->telaErro($req, 'Cliente não encontrado ou inativo neste workspace.', $req->post, 400);
        }

        try {
            $resultado = $this->gerador->gerar($cliente, $spec);
        } catch (PlannerError $erro) {
            return $this->telaErro($req, $erro->getMessage(), $req->post, 502);
        }

        $marca = trim((string) ($cliente['trade_name'] ?? '')) ?: (string) $cliente['company_name'];
        $spec['title'] = mb_substr($marca . ' · ' . $spec['objective'], 0, 180);

        try {
            $calendarId = $this->repo->criar(
                $empresa,
                $spec['client_id'],
                (int) $usuario['id'],
                $spec,
                $resultado['posts'],
                $resultado['model'],
                $resultado['input_tokens'],
                $resultado['output_tokens'],
            );
        } catch (\PDOException $erro) {
            error_log('[SMIA] planejamento: ' . $erro->getMessage());

            return $this->telaErro($req, 'Não foi possível salvar o planejamento. Tente novamente.', $req->post, 500);
        }

        $this->repo->auditoria(
            $empresa,
            (int) $usuario['id'],
            'planner.created',
            $marca . ' · ' . count($resultado['posts']) . ' posts (' . $resultado['modo'] . ')'
        );
        $this->session->flash(
            'success',
            $resultado['modo'] === 'ia'
                ? 'Planejamento gerado com IA e salvo. Revise os posts antes de encaminhar para produção.'
                : 'Rascunho de planejamento criado (sem IA). Ajuste os textos antes de encaminhar para produção.'
        );

        return Response::redirect($this->config->url('/planner/' . $calendarId . '?created=1'));
    }

    public function show(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $empresa = (int) $usuario['company_id'];
        $plano = $this->repo->plano((int) ($params['id'] ?? 0), $empresa);
        if ($plano === null) {
            return $this->naoEncontrado($req);
        }
        $posts = $this->repo->posts((int) $plano['id'], $empresa);

        return $this->render('planner/detail', $req, [
            'plano' => $plano,
            'posts' => $posts,
            'semanas' => PlannerRepository::semanas($plano, $posts),
            'pode_editar' => $this->podeEditar($usuario),
            'pode_excluir' => $this->podeExcluir($usuario),
            'ia_configurada' => $this->gerador->iaConfigurada(),
            'aviso' => $req->input('ok') === 'updated' ? 'Calendário atualizado.' : null,
            'erro' => $req->input('erro'),
        ]);
    }

    public function editPost(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $empresa = (int) $usuario['company_id'];
        $post = $this->repo->post((int) ($params['id'] ?? 0), $empresa);
        if ($post === null) {
            return $this->naoEncontrado($req);
        }
        $plano = $this->repo->plano((int) $post['calendar_id'], $empresa);
        if ($plano === null) {
            return $this->naoEncontrado($req);
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }

        if ($req->isPost()) {
            return $this->salvarPost($req, $post, $plano);
        }

        return $this->render('planner/post_edit', $req, [
            'post' => $post,
            'plano' => $plano,
            'erro' => null,
        ]);
    }

    public function deletePost(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $empresa = (int) $usuario['company_id'];
        $post = $this->repo->post((int) ($params['id'] ?? 0), $empresa);
        if ($post === null) {
            return $this->naoEncontrado($req);
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->semPermissao($req, 'Sessão expirada. Tente novamente.');
        }
        $planoId = (int) $post['calendar_id'];
        $this->repo->removerPost((int) $post['id'], $planoId, $empresa);

        return Response::redirect($this->config->url('/planner/' . $planoId . '?ok=updated'));
    }

    public function duplicatePost(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $empresa = (int) $usuario['company_id'];
        $post = $this->repo->post((int) ($params['id'] ?? 0), $empresa);
        if ($post === null) {
            return $this->naoEncontrado($req);
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->semPermissao($req, 'Sessão expirada. Tente novamente.');
        }
        $this->repo->duplicarPost((int) $post['id'], $empresa);

        return Response::redirect($this->config->url('/planner/' . $post['calendar_id'] . '?ok=updated'));
    }

    public function production(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $empresa = (int) $usuario['company_id'];
        $plano = $this->repo->plano((int) ($params['id'] ?? 0), $empresa);
        if ($plano === null) {
            return $this->naoEncontrado($req);
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->semPermissao($req, 'Sessão expirada. Tente novamente.');
        }
        $this->repo->enviarParaProducao((int) $plano['id'], $empresa);
        $this->repo->auditoria($empresa, (int) $usuario['id'], 'planner.production', (string) $plano['title']);

        return Response::redirect($this->config->url('/planner/' . $plano['id'] . '?ok=updated'));
    }

    public function delete(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $empresa = (int) $usuario['company_id'];
        $plano = $this->repo->plano((int) ($params['id'] ?? 0), $empresa);
        if ($plano === null) {
            return $this->naoEncontrado($req);
        }
        if (!$this->podeExcluir($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->semPermissao($req, 'Sessão expirada. Tente novamente.');
        }
        $this->repo->removerPlano((int) $plano['id'], $empresa);
        $this->repo->auditoria($empresa, (int) $usuario['id'], 'planner.deleted', (string) $plano['title']);
        $this->session->flash('success', 'Planejamento e publicações removidos.');

        return Response::redirect($this->config->url('/planner'));
    }

    // ------------------------------------------------------------------

    /**
     * Valida o formulário de geração.
     *
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    private function normalizarSpec(Request $req, int $empresa): array
    {
        $erros = [];
        $clientId = (int) ($req->input('client_id') ?? 0);
        $periodo = (int) ($req->input('period_days') ?? 0);
        $alvo = (int) ($req->input('target_posts') ?? 0);
        $inicio = (string) ($req->input('start_date') ?? '');
        $objetivo = mb_substr(trim((string) ($req->input('objective') ?? '')), 0, 500);
        $contexto = mb_substr(trim((string) ($req->input('campaign_context') ?? '')), 0, 1500);

        $redes = [];
        $enviadas = $req->post['networks'] ?? $req->query['networks'] ?? [];
        foreach (is_array($enviadas) ? $enviadas : [$enviadas] as $rede) {
            $rede = trim((string) $rede);
            if (in_array($rede, PlanGenerator::REDES, true) && !in_array($rede, $redes, true)) {
                $redes[] = $rede;
            }
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        if ($clientId <= 0) {
            $erros[] = 'Selecione um cliente.';
        }
        if (!in_array($periodo, PlanGenerator::PERIODOS, true)) {
            $erros[] = 'Escolha um período de 7, 15 ou 30 dias.';
        }
        if ($alvo < 1 || $alvo > PlanGenerator::MAX_POR_PERIODO) {
            $erros[] = 'A quantidade de publicações precisa ficar entre 1 e 45.';
        }
        if ($data === false) {
            $erros[] = 'Informe uma data de início válida.';
        }
        if ($redes === []) {
            $erros[] = 'Selecione ao menos uma rede social.';
        }
        if ($objetivo === '') {
            $erros[] = 'Informe o objetivo deste planejamento.';
        }

        return [[
            'client_id' => $clientId,
            'period_days' => $periodo,
            'target_posts' => $alvo,
            'start_date' => $data !== false ? $data->format('Y-m-d') : $inicio,
            'networks' => $redes,
            'objective' => $objetivo,
            'campaign_context' => $contexto,
            'title' => '',
        ], $erros];
    }

    private function salvarPost(Request $req, array $post, array $plano): Response
    {
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->render('planner/post_edit', $req, [
                'post' => $post,
                'plano' => $plano,
                'erro' => 'Sessão expirada. Revise os dados e envie novamente.',
            ], 400);
        }

        $dados = ['publication_date' => (string) ($req->input('publication_date') ?? '')];
        foreach (PlanGenerator::CAMPOS_POST as $campo) {
            $valor = $req->input($campo) ?? '';
            $dados[$campo] = mb_substr(trim($valor), 0, 5000);
        }
        $dados['network'] = trim((string) ($req->input('network') ?? ''));

        $erros = [];
        $inicio = new \DateTimeImmutable((string) $plano['start_date']);
        $fim = $inicio->modify('+' . ((int) $plano['period_days'] - 1) . ' days');
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $dados['publication_date']);

        if ($data === false) {
            $erros[] = 'Informe uma data de publicação válida.';
        } elseif ($data < $inicio || $data > $fim) {
            $erros[] = 'A data deve permanecer dentro do período do planejamento ('
                . $inicio->format('d/m/Y') . ' a ' . $fim->format('d/m/Y') . ').';
        }
        if (!in_array($dados['network'], $plano['networks'], true)) {
            $erros[] = 'Escolha uma rede selecionada neste planejamento.';
        }
        foreach (['theme', 'strategic_objective', 'content_type', 'main_text', 'caption', 'visual_brief', 'image_suggestion'] as $obrigatorio) {
            if ($dados[$obrigatorio] === '') {
                $erros[] = 'Preencha os campos obrigatórios da publicação.';
                break;
            }
        }

        if ($erros !== []) {
            return $this->render('planner/post_edit', $req, [
                'post' => array_merge($post, $dados),
                'plano' => $plano,
                'erro' => implode(' ', array_unique($erros)),
            ], 422);
        }

        $this->repo->atualizarPost((int) $post['id'], (int) $post['company_id'], $dados);

        return Response::redirect($this->config->url('/planner/' . $post['calendar_id'] . '?ok=updated'));
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

    private function podeEditar(array $usuario): bool
    {
        return in_array((string) $usuario['role'], self::PAPEIS_EDICAO, true);
    }

    private function podeExcluir(array $usuario): bool
    {
        return in_array((string) $usuario['role'], ['owner', 'admin'], true);
    }

    private function semPermissao(Request $req, string $mensagem = 'Seu perfil não tem permissão para esta ação.'): Response
    {
        return $this->render('planner/forbidden', $req, ['mensagem' => $mensagem], 403);
    }

    private function naoEncontrado(Request $req): Response
    {
        return $this->render('planner/not_found', $req, [], 404);
    }

    /** Volta ao formulário mantendo o que foi digitado, com a mensagem de erro. */
    private function telaErro(Request $req, string $mensagem, array $form, int $status): Response
    {
        $usuario = $this->auth->user();
        $empresa = (int) ($usuario['company_id'] ?? 0);

        return $this->render('planner/index', $req, [
            'clientes' => $this->repo->clientesAtivos($empresa),
            'planos' => $this->repo->planos($empresa),
            'ia_configurada' => $this->gerador->iaConfigurada(),
            'modelo' => $this->gerador->modelo(),
            'consumo' => $this->repo->consumoIa($empresa),
            'pode_editar' => $usuario !== null && $this->podeEditar($usuario),
            'hoje' => (new \DateTimeImmutable('now'))->format('Y-m-d'),
            'form' => $form,
            'erro' => $mensagem,
        ], $status);
    }

    private function render(string $view, Request $req, array $dados, int $status = 200): Response
    {
        return $this->view->render($view, $dados + [
            'user' => $this->auth->user(),
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
            'modules' => Modules::all(),
            'active' => 'planner',
            'current_path' => $req->path,
        ], $status)->withSecurityHeaders();
    }
}
