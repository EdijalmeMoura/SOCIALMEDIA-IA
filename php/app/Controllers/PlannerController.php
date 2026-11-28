<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Auth\Authenticator;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Planner\PlanGenerator;
use Smia\Planner\PlannerError;
use Smia\Planner\PlannerRepository;
use Smia\Studio\Layout;
use Smia\Studio\StudioError;
use Smia\Studio\StudioGenerator;
use Smia\Studio\StudioRepository;
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
        // Estúdio: criar a arte a partir da publicação e abrir a que já existe.
        // Opcional para um teste do calendário não precisar montá-lo.
        private readonly ?StudioRepository $estudio = null,
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

        return $this->render('planner/post_edit', $req, $this->dadosDaPublicacao($post, $plano, $req) + [
            'erro' => null,
        ]);
    }

    /**
     * Dados extras da edição da publicação.
     *
     * A arte vinculada vem junto porque o formulário mostra a prévia dela e o
     * botão para abrir o editor — sem isso, o usuário produz a arte em outro
     * lugar e não tem como voltar.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $plano
     * @return array<string,mixed>
     */
    private function dadosDaPublicacao(array $post, array $plano, Request $req): array
    {
        $empresa = (int) $plano['company_id'];
        $comArte = $this->repo->postComArte((int) $post['id'], $empresa) ?? $post;
        $arte = null;
        if (!empty($comArte['creative_id']) && $this->estudio !== null) {
            $arte = $this->estudio->arte((int) $comArte['creative_id'], $empresa);
        }
        $aviso = match ((string) ($req->input('ok') ?? '')) {
            'arte-criada' => 'Arte criada a partir desta publicação. O texto da legenda foi o que você escreveu aqui.',
            'arte-vinculada' => 'Arte vinculada a esta publicação.',
            default => null,
        };

        return [
            'post' => $comArte,
            'plano' => $plano,
            'arte' => $arte !== null && (int) $arte['client_id'] === (int) $post['client_id'] ? $arte : null,
            'artes_do_cliente' => $this->estudio !== null
                ? $this->estudio->artes($empresa, 40, ['client_id' => (int) $post['client_id']])
                : [],
            'aviso' => $aviso,
            'pode_criar_arte' => $this->estudio !== null,
            // Limite por rede e formatos: a tela do calendário agora avisa o
            // mesmo número que o estúdio, em vez de descobrir no fim.
            'redes' => StudioGenerator::REDES,
            'formatos' => Layout::FORMATOS,
            'tons' => StudioGenerator::TONS,
        ];
    }

    /**
     * Cria a arte a partir da publicação e deixa as duas ligadas.
     *
     * O texto da publicação é a fonte: tema no título, legenda na legenda,
     * briefing e sugestão de imagem no painel Imagem. Assim a arte nasce com o
     * que já foi aprovado no calendário, e o vínculo impede que os dois textos
     * divirjam sem ninguém perceber.
     */
    public function criarArte(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $empresa = (int) $usuario['company_id'];
        $post = $this->repo->post((int) ($params['id'] ?? 0), $empresa);
        if ($post === null) {
            return $this->naoEncontrado($req);
        }
        $plano = $this->repo->plano((int) $post['calendar_id'], $empresa);
        if ($plano === null || $this->estudio === null) {
            return $this->naoEncontrado($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return Response::redirect($this->config->url('/planner/posts/' . $post['id'] . '/edit?erro=' . rawurlencode('Sessão expirada. Recarregue a página e tente novamente.')));
        }
        // Uma arte por publicação: criar de novo deixaria duas versões sem
        // dono e o botão "Abrir a arte" apontaria para a antiga.
        if (!empty($post['creative_id'])) {
            return Response::redirect($this->config->url('/studio/posts/' . (int) $post['creative_id']));
        }

        $formato = (string) ($req->input('format_key') ?? 'square');
        if (!isset(Layout::FORMATOS[$formato])) {
            $formato = 'square';
        }
        $medida = Layout::FORMATOS[$formato];
        $conteudo = [
            'title' => Layout::textoCurto((string) $post['theme'], 70),
            'subtitle' => Layout::textoCurto((string) $post['strategic_objective'], 90),
            'caption' => Layout::textoCurto((string) $post['caption'], 5000),
            'hashtags' => Layout::textoCurto((string) $post['hashtags'], 1000),
            'cta' => Layout::textoCurto((string) $post['cta'] !== '' ? (string) $post['cta'] : 'Saiba mais', 80),
            'visual_brief' => Layout::textoCurto((string) $post['visual_brief'], 5000),
            'image_suggestion' => Layout::textoCurto((string) $post['image_suggestion'], 5000),
            'composition' => '',
            'alt_text' => '',
        ];
        // Marca e cor vêm da identidade do cliente: a arte nasce parecida com a
        // do estúdio, não com um retângulo azul genérico.
        $cliente = null;
        foreach ($this->estudio->clientesAtivos($empresa) as $candidato) {
            if ((int) $candidato['id'] === (int) $post['client_id']) {
                $cliente = $candidato;
                break;
            }
        }
        $cor = Layout::corValida((string) ($cliente['primary_color'] ?? '')) ? strtolower((string) $cliente['primary_color']) : '#3158d8';
        $marca = trim((string) ($cliente['trade_name'] ?? '')) ?: (string) ($cliente['company_name'] ?? 'Marca');
        $layout = Layout::padrao($conteudo, (int) $medida['width'], (int) $medida['height'], $cor, $marca);
        $rede = self::redeDoEstudio((string) $post['network']);

        try {
            $id = $this->estudio->criar(
                $empresa,
                (int) $post['client_id'],
                (int) $usuario['id'],
                (string) $post['theme'],
                $formato,
                (int) $medida['width'],
                (int) $medida['height'],
                $conteudo,
                $layout,
                $cor,
                $this->promptDoPost($post, $conteudo),
                'local-draft',
                0,
                0,
                'Arte criada a partir do planejamento',
                [
                    'network' => $rede,
                    'objective' => (string) $post['strategic_objective'],
                    'tone' => 'marca',
                    'reference' => '',
                ],
            );
        } catch (StudioError $erro) {
            return Response::redirect($this->config->url('/planner/posts/' . $post['id'] . '/edit?erro=' . rawurlencode($erro->getMessage())));
        }

        $this->repo->vincularArte((int) $post['id'], $id, $empresa);
        $this->repo->auditoria($empresa, (int) $usuario['id'], 'planner.creative_created', 'post:' . $post['id'] . ' -> creative:' . $id);

        return Response::redirect($this->config->url('/studio/posts/' . $id . '?ok=created'));
    }

    /** Vincula uma arte que já existe a esta publicação. */
    public function vincularArte(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $empresa = (int) $usuario['company_id'];
        $post = $this->repo->post((int) ($params['id'] ?? 0), $empresa);
        if ($post === null) {
            return $this->naoEncontrado($req);
        }
        $voltar = '/planner/posts/' . $post['id'] . '/edit';
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return Response::redirect($this->config->url($voltar . '?erro=' . rawurlencode('Sessão expirada. Recarregue a página e tente novamente.')));
        }
        $id = (int) ($req->input('creative_id') ?? 0);
        if ($id <= 0 || !$this->repo->vincularArte((int) $post['id'], $id, $empresa)) {
            // Arte de outro cliente/agência cai aqui: a mensagem não diz se
            // existe, só que não pode ser vinculada.
            return Response::redirect($this->config->url($voltar . '?erro=' . rawurlencode('Escolha uma arte deste cliente.')));
        }
        $this->repo->auditoria($empresa, (int) $usuario['id'], 'planner.creative_linked', 'post:' . $post['id'] . ' -> creative:' . $id);

        return Response::redirect($this->config->url($voltar . '?ok=arte-vinculada'));
    }

    /** Rede do planejamento para a chave que o estúdio entende. */
    private static function redeDoEstudio(string $rede): string
    {
        return match (mb_strtolower(trim($rede))) {
            'instagram' => 'instagram',
            'facebook' => 'facebook',
            'tiktok' => 'tiktok',
            'linkedin' => 'linkedin',
            default => 'generico',
        };
    }

    /** Prompt de imagem inicial: sugestão do post + o que não pode faltar. */
    private function promptDoPost(array $post, array $conteudo): string
    {
        $partes = array_filter([
            trim((string) ($conteudo['image_suggestion'] ?? '')),
            trim((string) ($conteudo['visual_brief'] ?? '')),
            'sem texto na imagem, área de respiro para o título',
        ], static fn (string $p): bool => $p !== '');

        return Layout::textoCurto(implode('. ', $partes) . '.', 4000);
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
            return $this->render('planner/post_edit', $req, $this->dadosDaPublicacao($post, $plano, $req) + [
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
            return $this->render('planner/post_edit', $req, $this->dadosDaPublicacao($post, $plano, $req) + [
                'post' => array_merge($this->repo->postComArte((int) $post['id'], (int) $post['company_id']) ?? $post, $dados),
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
