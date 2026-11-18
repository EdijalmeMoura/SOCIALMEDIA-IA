<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Auth\Authenticator;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Meta\Graph;
use Smia\Meta\MediaUrl;
use Smia\Meta\MetaError;
use Smia\Meta\MetaRepository;
use Smia\Meta\MetaSettings;
use Smia\Meta\ScheduleRepository;
use Smia\Meta\TokenCipher;
use Smia\Support\Config;
use Smia\Support\Modules;
use Smia\Support\Session;
use Smia\Support\View;

/**
 * Conexões Meta: OAuth, teste de leitura, desconexão e a mídia assinada.
 *
 * Duas coisas que esta tela faz questão de não fingir:
 *  - "leitura Graph confirmada" não é permissão de publicar;
 *  - configuração presente não é publicação comprovada.
 * Cada evidência aparece separada, com a data em que foi coletada.
 */
final class MetaController
{
    private const PAPEIS_GESTAO = ['owner', 'admin'];

    public function __construct(
        private readonly Config $config,
        private readonly MetaRepository $repo,
        private readonly ScheduleRepository $agenda,
        private readonly Authenticator $auth,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function index(Request $req): Response
    {
        $usuario = $this->precisaGestao();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $empresa = (int) $usuario['company_id'];

        [$settings, $erroConfig] = MetaSettings::tentar($this->config);
        [$midia, $erroMidia] = MediaUrl::tentar($this->config);
        $this->repo->avisarPrazos($empresa, '/meta');

        return $this->render('meta/index', $req, [
            'configurada' => $settings !== null,
            'settings_erro' => $erroConfig,
            'graph_version' => $settings?->graphVersion,
            'callback_confere' => $settings !== null && $settings->callbackConfere((string) $this->hostAtual($req)),
            'midia_pronta' => $midia !== null,
            'midia_base' => $midia?->base() ?? '',
            'midia_erro' => $erroMidia,
            'conexoes' => $this->repo->conexoes($empresa),
            'evidencias' => $this->repo->evidencias($empresa),
            'sessao_segura' => $this->sessaoSegura(),
            'escopos' => MetaSettings::ESCOPOS,
            'aviso' => $this->avisoDeQuery($req),
            'erro' => $req->input('erro'),
        ]);
    }

    /** Inicia o OAuth: guarda o state na sessão e manda o navegador à Meta. */
    public function connect(Request $req): Response
    {
        $usuario = $this->precisaGestao();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro('Sessão expirada. Recarregue a página e tente novamente.');
        }
        $settings = MetaSettings::fromConfig($this->config) ?? null;
        if ($settings === null) {
            return $this->voltarErro('Configure o App Meta no .env antes de conectar.');
        }

        $state = bin2hex(random_bytes(24));
        $this->session->put('meta_oauth_state', $state);
        $this->session->put('meta_oauth_started', time());

        return Response::redirect((new Graph($settings, $this->config->metaGraphEndpoint))->authorizationUrl($state));
    }

    /**
     * Retorno do OAuth. O state vem da sessão e do endereço: se não casar, ou
     * se passou de 10 minutos, nada é gravado.
     */
    public function callback(Request $req): Response
    {
        $usuario = $this->precisaGestao();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $esperado = (string) ($this->session->get('meta_oauth_state') ?? '');
        $iniciado = (int) ($this->session->get('meta_oauth_started') ?? 0);
        $this->session->forget('meta_oauth_state');
        $this->session->forget('meta_oauth_started');

        $recebido = (string) ($req->input('state') ?? '');
        if ($esperado === '' || $recebido === '' || !hash_equals($esperado, $recebido) || time() - $iniciado > 600) {
            return $this->voltarErro('A sessão de autorização expirou ou não corresponde. Inicie a conexão novamente.');
        }
        if ($req->input('error') !== null) {
            $descricao = mb_substr((string) ($req->input('error_description') ?? $req->input('error')), 0, 240);

            return $this->voltarErro('A autorização Meta não foi concluída: ' . $descricao);
        }

        try {
            $settings = MetaSettings::fromConfig($this->config);
            $graph = new Graph($settings, $this->config->metaGraphEndpoint);
            $curto = $graph->exchangeCode((string) ($req->input('code') ?? ''));
            $longo = $graph->exchangeLongLivedToken((string) ($curto['access_token'] ?? ''));
            $tokenUsuario = (string) ($longo['access_token'] ?? '');
            if ($tokenUsuario === '') {
                throw new MetaError('A troca do token do usuário não retornou access_token.');
            }
            $paginas = $graph->fetchPages($tokenUsuario);
            if ($paginas === []) {
                throw new MetaError('Nenhuma Página do Facebook foi encontrada. Confira as permissões, o acesso à Página e a conta profissional do Instagram vinculada.');
            }
            try {
                $permissoes = $graph->fetchPermissions($tokenUsuario);
            } catch (MetaError) {
                $permissoes = [];
            }
            $expiraEm = null;
            $expiraSegundos = $longo['expires_in'] ?? null;
            if (is_numeric($expiraSegundos)) {
                $expiraEm = gmdate('Y-m-d H:i:s', time() + (int) $expiraSegundos);
            }
            $total = $this->repo->salvarConexoes(
                (int) $usuario['company_id'],
                (int) $usuario['id'],
                $paginas,
                $permissoes,
                new TokenCipher($settings->chaveTokens),
                $expiraEm,
            );
        } catch (MetaError $erro) {
            $this->repo->auditoria((int) $usuario['company_id'], (int) $usuario['id'], 'meta.connect.failed', $erro->getMessage());

            return $this->voltarErro($erro->getMessage());
        }

        $this->repo->auditoria((int) $usuario['company_id'], (int) $usuario['id'], 'meta.connected', $total . ' página(s)');

        return Response::redirect($this->config->url('/meta?connected=' . $total));
    }

    /** Teste de leitura: prova que o token abre a Página, nada além. */
    public function test(Request $req, array $params): Response
    {
        $usuario = $this->precisaGestao();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro('Sessão expirada. Recarregue a página e tente novamente.');
        }
        $empresa = (int) $usuario['company_id'];
        $conexao = $this->repo->conexao((int) ($params['id'] ?? 0), $empresa);
        if ($conexao === null) {
            return $this->render('meta/not_found', $req, [], 404);
        }

        try {
            $settings = MetaSettings::fromConfig($this->config);
            $token = (new TokenCipher($settings->chaveTokens))->decifrar((string) $conexao['page_access_token_encrypted']);
            $lido = (new Graph($settings, $this->config->metaGraphEndpoint))
                ->checkPage((string) $conexao['page_id'], $token);
            $this->repo->registrarTeste(
                (int) $conexao['id'],
                $empresa,
                (int) $usuario['id'],
                true,
                'Leitura Graph confirmada para ' . ($lido['page_name'] !== '' ? $lido['page_name'] : $conexao['page_name']) . '. Isso não testa permissões de publicação.'
            );
            $status = 'ok';
        } catch (MetaError $erro) {
            $this->repo->registrarTeste((int) $conexao['id'], $empresa, (int) $usuario['id'], false, $erro->getMessage());
            $status = 'failed';
        }

        return Response::redirect($this->config->url('/meta?connection_check=' . $status));
    }

    /** Handshake TLS com o Graph — só diagnóstico de saída do servidor. */
    public function graphCheck(Request $req): Response
    {
        $usuario = $this->precisaGestao();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro('Sessão expirada. Recarregue a página e tente novamente.');
        }
        $empresa = (int) $usuario['company_id'];
        try {
            $settings = MetaSettings::fromConfig($this->config);
            $versao = (new Graph($settings, $this->config->metaGraphEndpoint))->tlsVersion();
            $this->repo->registrarTls($empresa, (int) $usuario['id'], true, 'Handshake TLS confirmado com graph.facebook.com (' . $versao . '). Isso não valida OAuth, permissões nem publicação.');
            $status = 'ok';
        } catch (MetaError $erro) {
            $this->repo->registrarTls($empresa, (int) $usuario['id'], false, $erro->getMessage());
            $status = 'failed';
        }

        return Response::redirect($this->config->url('/meta?graph_check=' . $status));
    }

    public function disconnect(Request $req, array $params): Response
    {
        $usuario = $this->precisaGestao();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro('Sessão expirada. Recarregue a página e tente novamente.');
        }
        $empresa = (int) $usuario['company_id'];
        $conexao = $this->repo->conexao((int) ($params['id'] ?? 0), $empresa);
        if ($conexao === null) {
            return $this->render('meta/not_found', $req, [], 404);
        }
        $this->repo->desconectar((int) $conexao['id'], $empresa);
        $this->repo->auditoria($empresa, (int) $usuario['id'], 'meta.disconnected', (string) $conexao['page_name']);

        return Response::redirect($this->config->url('/meta?disconnected=1'));
    }

    /**
     * Mídia pública assinada (a Meta busca a imagem aqui).
     *
     * Rota SEM sessão de propósito: quem pede é o servidor da Meta, não uma
     * pessoa logada. A proteção é a assinatura HMAC + prazo de 10 minutos.
     */
    public function media(Request $req, array $params): Response
    {
        $arquivo = (string) ($params['arquivo'] ?? '');
        $midia = MediaUrl::tentar($this->config)[0];
        if ($midia === null) {
            return Response::text('Mídia indisponível.', 404);
        }
        if (!$midia->valida($arquivo, $req->input('expires'), $req->input('signature'))) {
            return Response::text('Link expirado ou inválido.', 404);
        }
        $caminho = $this->config->dataDir . '/uploads/' . $arquivo;
        if (!is_file($caminho)) {
            return Response::text('Arquivo não encontrado.', 404);
        }
        $mime = str_ends_with(strtolower($arquivo), '.png') ? 'image/png' : 'image/jpeg';

        return Response::file($caminho, $mime)
            ->withHeader('Cache-Control', 'public, max-age=300')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** Conferência manual de uma tentativa incerta. */
    public function reconcile(Request $req, array $params): Response
    {
        $usuario = $this->precisaGestao();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarErro('Sessão expirada. Recarregue a página e tente novamente.');
        }
        $attemptId = (int) ($params['id'] ?? 0);
        $empresa = (int) $usuario['company_id'];
        if ($this->repo->tentativa($attemptId, $empresa) === null) {
            return $this->render('meta/not_found', $req, [], 404);
        }
        $resultado = (string) ($req->input('outcome') ?? '');

        try {
            $this->repo->reconciliar(
                $attemptId,
                $empresa,
                (int) $usuario['id'],
                $resultado,
                (string) ($req->input('remote_publication_id') ?? ''),
                (string) ($req->input('note') ?? ''),
                '/schedule',
            );
        } catch (MetaError $erro) {
            return Response::redirect($this->config->url('/schedule?erro=' . rawurlencode($erro->getMessage())));
        }
        $this->repo->auditoria($empresa, (int) $usuario['id'], 'meta.reconciled.' . $resultado, 'tentativa #' . $attemptId);

        return Response::redirect($this->config->url('/schedule?reconciled=' . rawurlencode($resultado)));
    }

    // ------------------------------------------------------------------

    /** @return array<string,mixed>|Response */
    private function precisaGestao(): array|Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        if (!in_array((string) $usuario['role'], self::PAPEIS_GESTAO, true)) {
            return $this->render('meta/forbidden', new Request('GET', '/meta', [], [], [], [], $this->config->basePath), [
                'motivo' => 'Só proprietários e administradores podem ver as conexões Meta desta agência.',
            ], 403);
        }

        return $usuario;
    }

    private function sessaoSegura(): bool
    {
        $segredo = $this->config->secretKey;

        return $this->config->cookieSecure && strlen($segredo) >= 32 && !str_contains($segredo, 'dev-only');
    }

    private function hostAtual(Request $req): string
    {
        $origem = $this->config->publicOrigin;
        $host = is_string($origem) && $origem !== '' ? (string) parse_url($origem, PHP_URL_HOST) : '';

        return $host !== '' ? $host : (string) ($req->header('host') ?? '');
    }

    private function voltarErro(string $mensagem): Response
    {
        return Response::redirect($this->config->url('/meta?erro=' . rawurlencode($mensagem)));
    }

    private function avisoDeQuery(Request $req): ?string
    {
        return match (true) {
            $req->input('connected') !== null => 'Autorização concluída: ' . (int) $req->input('connected') . ' Página(s) sincronizada(s).',
            $req->input('disconnected') !== null => 'Página desconectada. O token criptografado foi removido.',
            $req->input('connection_check') === 'ok' => 'O Graph confirmou leitura da Página; isso não testa permissões de publicação.',
            $req->input('connection_check') === 'failed' => 'A conexão não foi confirmada. Confira os detalhes e as permissões no App Meta.',
            $req->input('graph_check') === 'ok' => 'Handshake TLS com o Graph da Meta confirmado; isso não valida OAuth nem permissões.',
            $req->input('graph_check') === 'failed' => 'O servidor não alcançou graph.facebook.com por TLS. Confira o acesso HTTPS de saída.',
            default => null,
        };
    }

    private function render(string $view, Request $req, array $dados, int $status = 200): Response
    {
        return $this->view->render($view, $dados + [
            'user' => $this->auth->user(),
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
            'modules' => Modules::all(),
            'active' => 'meta',
            'current_path' => $req->path,
        ], $status)->withSecurityHeaders();
    }
}
