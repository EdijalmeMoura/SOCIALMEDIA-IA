<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Installer\Installer;
use Smia\Support\Config;
use Smia\Support\Session;
use Smia\Support\View;

/**
 * Telas do instalador: ambiente -> revisão -> concluído.
 *
 * Três passos curtos, nada gravado antes da confirmação e o instalador se
 * fecha (410) assim que existir conta.
 */
final class InstallerController
{
    public function __construct(
        private readonly Config $config,
        private readonly Installer $installer,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function show(Request $req): Response
    {
        if (!$this->guardAccess($req)) {
            return $this->denied($req);
        }
        if (!$this->installer->needed()) {
            return $this->closed();
        }

        // Reabrir o passo 1 com um rascunho salvo não é erro, mas confunde:
        // a pessoa acha que perdeu a revisão. O aviso vem de `flash`, que o
        // layout já sabe mostrar.
        $pendentes = count($this->installer->pendingDrafts());
        if ($pendentes > 0) {
            $this->session->flash(
                'info',
                $pendentes === 1
                    ? 'Existe uma revisão salva aguardando confirmação. Se você recomeçar daqui, ela é descartada quando este formulário for enviado.'
                    : 'Existem ' . $pendentes . ' revisões salvas aguardando confirmação.'
            );
        }

        return $this->render('install/form', [
            'checks' => $this->installer->environmentChecks(),
            'errors' => [],
            'old' => [],
            'origin' => $this->detectedOrigin($req),
            'token_required' => $this->installer->tokenRequired(),
            'token' => $req->input('access_token', ''),
        ]);
    }

    public function validate(Request $req): Response
    {
        if (!$this->guardAccess($req)) {
            return $this->denied($req);
        }
        if (!$this->installer->needed()) {
            return $this->closed();
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            // A causa quase sempre é o cookie de sessão não voltar (caminho
            // errado, navegador bloqueando, troca de http/https, cache). Dizer
            // só "sessão expirada" faz a pessoa repetir o mesmo erro.
            return $this->render('install/form', [
                'checks' => $this->installer->environmentChecks(),
                'errors' => [
                    'A sessão do navegador não acompanhou o formulário (CSRF inválido). '
                    . 'Costuma acontecer quando o cookie é bloqueado ou quando o endereço '
                    . 'muda entre http e https. Recarregue esta página e envie de novo; '
                    . 'se repetir, abra /install/diagnostico e veja "Sessão iniciada".',
                ],
                'old' => $req->post,
                'origin' => $this->detectedOrigin($req),
                'token_required' => $this->installer->tokenRequired(),
                'token' => $req->input('access_token', ''),
            ], 400);
        }

        [$dados, $erros] = $this->installer->validate($req->post, $this->detectedOrigin($req));
        if ($erros !== []) {
            return $this->render('install/form', [
                'checks' => $this->installer->environmentChecks(),
                'errors' => $erros,
                'old' => $req->post,
                'origin' => $this->detectedOrigin($req),
                'token_required' => $this->installer->tokenRequired(),
                'token' => $req->input('access_token', ''),
            ], 422);
        }

        $id = $this->installer->createDraft($dados);
        return Response::redirect($this->url($req, '/install/review', ['draft' => $id]));
    }

    public function review(Request $req): Response
    {
        if (!$this->guardAccess($req)) {
            return $this->denied($req);
        }
        if (!$this->installer->needed()) {
            return $this->closed();
        }
        $rascunho = $this->installer->loadDraft((string) $req->input('draft', ''));
        if ($rascunho === null) {
            $this->session->flash('error', 'Revisão expirada. Preencha os dados novamente.');

            return Response::redirect($this->url($req, '/install'));
        }

        return $this->render('install/review', [
            'draft' => $rascunho,
            'token' => $req->input('access_token', ''),
        ]);
    }

    /**
     * Conclui a instalação. Aceita GET e POST de propósito.
     *
     * GET só acontece quando alguém abre/atualiza este endereço direto (o botão
     * de recarregar depois de confirmar, um favorito, a barra do navegador).
     * Nesse caso **nada é criado**: a pessoa é levada para a tela de conclusão
     * ou de volta ao início. Antes isso devolvia um 405 cru, e parecia que a
     * instalação tinha travado nessa URL.
     */
    public function run(Request $req): Response
    {
        // Sem os campos do formulário não há o que executar. Isto cobre o GET,
        // o reenvio vazio e qualquer POST sem corpo.
        if ($req->input('draft') === '' || $req->input('csrf_token') === '') {
            $this->installer->logRun('recusa:formulario_incompleto(' . $req->method
                . ' draft=' . ($req->input('draft') === '' ? 'nao' : 'sim')
                . ' csrf=' . ($req->input('csrf_token') === '' ? 'nao' : 'sim') . ')');
            if (!$this->guardAccess($req)) {
                return $this->denied($req);
            }
            if (!$this->installer->needed()) {
                return $this->done();
            }
            $this->session->flash('error', 'A confirmação precisa ser feita pelo botão da revisão. Recomece por aqui.');

            return Response::redirect($this->url($req, '/install'));
        }

        if (!$this->guardAccess($req)) {
            $this->installer->logRun('recusa:token_invalido');

            return $this->denied($req);
        }
        if (!$this->installer->needed()) {
            $this->installer->logRun('recusa:ja_instalado');

            return $this->done();
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            $this->installer->logRun('recusa:csrf_invalido');
            $this->session->flash('error', 'A sessão do navegador não acompanhou a confirmação (CSRF inválido). Abra /install/diagnostico para ver o estado da sessão.');

            return Response::redirect($this->url($req, '/install'));
        }
        $rascunho = $this->installer->loadDraft((string) $req->input('draft', ''));
        if ($rascunho === null) {
            $this->installer->logRun('recusa:rascunho_expirado_ou_inexistente');
            $this->session->flash('error', 'Revisão expirada. Preencha os dados novamente.');

            return Response::redirect($this->url($req, '/install'));
        }
        $this->installer->logRun('confirmacao_aceita');

        // A instalação tem duas fases: GRAVAR (banco + conta) e FECHAR (sessão,
        // redirecionamento). Falha na segunda não pode parecer falha na
        // primeira — antes, o erro caía no handler genérico e a pessoa ficava
        // sem saber que a conta JÁ existia. Aqui cada fase é dita na tela.
        try {
            $this->installer->install($rascunho['data']);
        } catch (\Throwable $erro) {
            error_log('[SMIA][install][gravar] ' . $erro::class . ': ' . $erro->getMessage() . ' em ' . $erro->getFile() . ':' . $erro->getLine());

            return $this->render('install/failure', [
                'etapa' => 'gravar',
                'mensagem' => $erro->getMessage(),
                'arquivo' => $erro->getFile(),
                'linha' => $erro->getLine(),
                'criada' => !$this->installer->needed(),
                'token' => (string) $req->input('access_token', ''),
                'debug' => $this->config->debug,
            ], 500);
        }

        // Daqui para baixo a conta EXISTE. Qualquer falha é de acabamento.
        try {
            $this->installer->dropDraft($rascunho['id']);
            $this->session->regenerate();
            $this->session->flash('success', 'Instância criada. Entre com o e-mail e a senha que você definiu.');
        } catch (\Throwable $erro) {
            error_log('[SMIA][install][fechar] ' . $erro::class . ': ' . $erro->getMessage() . ' em ' . $erro->getFile() . ':' . $erro->getLine());

            // A conta existe: 200, não 500. A tela diz exatamente isso e leva
            // ao login em vez de sugerir que a instalação falhou.
            return $this->render('install/failure', [
                'etapa' => 'fechar',
                'mensagem' => $erro->getMessage(),
                'arquivo' => $erro->getFile(),
                'linha' => $erro->getLine(),
                'criada' => true,
                'token' => (string) $req->input('access_token', ''),
                'debug' => $this->config->debug,
                'registro' => $this->installer->installedRecord(),
            ]);
        }

        // Passo 3 do instalador: a tela "Instância pronta". É ela que mostra o
        // registro da instalação e o botão de login; ir direto ao /login pulava
        // essa confirmação.
        //
        // Redireciona (302) em vez de renderizar aqui: assim o F5 não reenvia o
        // formulário e a URL fica limpa. O GET em /install/run, agora que a
        // instância existe, entrega a tela de conclusão.
        $this->installer->logRun('redirecionando_para_passo3');

        return Response::redirect($this->url($req, '/install/run'));
    }

    /**
     * Diagnóstico só-leitura: GET /install/diagnostico.
     *
     * Aberto de propósito: se o problema for justamente o token, exigir token
     * aqui seria inútil. Por isso não mostra segredo nenhum — só se ele existe
     * e se a sessão/CSRF estão funcionando.
     */
    public function diagnostics(Request $req): Response
    {
        $csrAtual = $this->session->csrfToken();
        $precisaInstalar = $this->installer->needed();
        $registro = $this->installer->installedRecord();

        $dataDir = $this->config->dataDir;
        $gravavel = is_dir($dataDir) ? is_writable($dataDir) : false;
        $dbExiste = is_file($this->config->dbPath);
        $pendentes = $this->installer->pendingDrafts();
        $rascunhos = count($pendentes);

        $linhas = [
            [
                'rotulo' => 'Versão do pacote',
                'valor' => 'v' . Config::VERSAO,
                'ok' => version_compare(Config::VERSAO, '1.12', '>='),
                'dica' => version_compare(Config::VERSAO, '1.12', '<')
                    ? 'Este servidor está com um pacote antigo. Envie app/ e public/ da versão nova.'
                    : 'Pacote atual.',
            ],
            [
                'rotulo' => 'PHP',
                'valor' => PHP_VERSION,
                'ok' => version_compare(PHP_VERSION, '8.0', '>='),
                'dica' => $this->config->debug ? 'SOCIALMEDIA_DEBUG ligado: erros aparecem com arquivo e linha.' : '',
            ],
            [
                'rotulo' => 'Token exigido',
                'valor' => $this->installer->tokenRequired() ? 'sim (SOCIALMEDIA_INSTALL_TOKEN definido)' : 'não (qualquer um com a URL instala)',
                'ok' => true,
                'dica' => '',
            ],
            [
                'rotulo' => 'Sessão iniciada',
                'valor' => session_status() === PHP_SESSION_ACTIVE ? 'sim' : 'não',
                'ok' => session_status() === PHP_SESSION_ACTIVE,
                'dica' => session_status() === PHP_SESSION_ACTIVE
                    ? 'Cookie de sessão com caminho ' . ($this->config->basePath === '' ? '/' : $this->config->basePath . '/')
                    : 'Sem sessão o CSRF nunca valida e a confirmação volta para o passo 1.',
            ],
            [
                'rotulo' => 'CSRF gerado',
                'valor' => $csrAtual !== '' ? 'sim' : 'não',
                'ok' => $csrAtual !== '',
                'dica' => '',
            ],
            [
                'rotulo' => 'Pasta de dados',
                'valor' => $dataDir . ($gravavel ? ' (gravável)' : ' (NÃO gravável)'),
                'ok' => $gravavel,
                'dica' => $gravavel ? '' : 'Sem escrita nesta pasta a instalação não conclui. Ajuste para 755 ou 775 no cPanel.',
            ],
            [
                'rotulo' => 'Banco',
                'valor' => $dbExiste ? $this->config->dbPath . ' (' . number_format((int) filesize($this->config->dbPath) / 1024, 0, ',', '.') . ' KB)' : 'ainda não existe',
                'ok' => $precisaInstalar ? true : (bool) $registro,
                'dica' => '',
            ],
            [
                'rotulo' => 'Instalação',
                'valor' => $precisaInstalar ? 'pendente (nenhum usuário)' : 'concluída',
                'ok' => true,
                'dica' => $registro ? 'Agência: ' . (string) ($registro['agency_name'] ?? '—') . ' · proprietário: ' . (string) ($registro['owner_email'] ?? '—') : '',
            ],
            [
                'rotulo' => 'Rascunhos pendentes',
                'valor' => (string) $rascunhos,
                'ok' => true,
                'dica' => $pendentes === []
                    ? ''
                    : 'Mais recente: ' . (string) ($pendentes[0]['email'] ?? '—')
                      . ', salvo em ' . gmdate('Y-m-d H:i:s', (int) ($pendentes[0]['created'] ?? 0)) . ' UTC.',
            ],
            [
                'rotulo' => 'Endereço reconhecido',
                'valor' => $this->config->basePath === '' ? '(raiz do domínio)' : $this->config->basePath,
                'ok' => $this->config->basePath !== '',
                'dica' => 'Caminho interno pedido nesta visita: ' . $req->path,
            ],
        ];

        $chave = static function (string $rel): array {
            $caminho = \SMIA_APP . '/' . $rel;
            $mtime = is_file($caminho) ? (int) filemtime($caminho) : 0;

            return [
                'caminho' => $rel,
                'quando' => $mtime > 0 ? gmdate('Y-m-d H:i:s', $mtime) . ' UTC' : 'AUSENTE',
            ];
        };
        $arquivos = [
            'Roteador' => $chave('Http/Router.php'),
            'Controlador do instalador' => $chave('Controllers/InstallerController.php'),
            'Tela de conclusão' => $chave('views/install/closed.php'),
            'Configuração' => $chave('Support/Config.php'),
        ];

        if ($precisaInstalar) {
            $resumo = 'Este servidor ainda não tem conta.';
        } else {
            $resumo = 'Esta instância já está instalada.';
        }

        // Trilha da última tentativa de confirmação. É o que distingue "nunca
        // chegou a gravar" de "gravou pela metade" e de "o PHP morreu no meio".
        $trilha = $this->installer->lastRunTrail();
        $etapasFeitas = array_map(static fn ($e) => (string) ($e['etapa'] ?? ''), $trilha);
        $ultimaEm = $trilha === [] ? '' : (string) ($trilha[count($trilha) - 1]['em'] ?? '');
        if ($trilha !== [] && !in_array('fim', $etapasFeitas, true)) {
            $this->session->flash(
                'error',
                'A última tentativa de confirmação parou na etapa "' . (string) end($etapasFeitas)
                . '" (registrada em ' . $ultimaEm . ' UTC). Veja a trilha abaixo e o log do servidor.'
            );
        }

        return $this->render('install/diagnostico', [
            'linhas' => $linhas,
            'arquivos' => $arquivos,
            'resumo' => $resumo,
            'precisaInstalar' => $precisaInstalar,
            'tokenDigitado' => '',
            'trilha' => $trilha,
        ]);
    }

    public function cancel(Request $req): Response
    {
        if ($this->guardAccess($req) && $this->session->csrfValid($req->input('csrf_token'))) {
            $this->installer->dropDraft((string) $req->input('draft', ''));
        }

        return Response::redirect($this->url($req, '/install'));
    }

    /**
     * URL interna do instalador **com o token**, quando houver.
     *
     * Todo redirecionamento daqui passa por este método. Antes, um voltar para
     * `/install` perdia o `?access_token=`, e o próprio guarda de acesso
     * devolvia 403 — o usuário era jogado na tela de "acesso restrito" no meio
     * do assistente, sem entender que só faltava o parâmetro na URL.
     */
    private function url(Request $req, string $caminho, array $extras = []): string
    {
        $url = $this->config->url($caminho);
        $parametros = $extras;
        $token = (string) $req->input('access_token', '');
        if ($token !== '') {
            $parametros += ['access_token' => $token];
        }

        return $parametros === [] ? $url : $url . '?' . http_build_query($parametros);
    }

    /**
     * Porta de entrada de quem abriu o instalador sem token.
     *
     * Valida o valor digitado e redireciona para o destino pedido **com** o
     * token na URL. Sem isso, o único caminho era copiar o token do `.env` e
     * montar a URL à mão.
     */
    public function access(Request $req): Response
    {
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->render('install/access', [
                'next' => $this->nextPath($req),
                'errors' => ['Sessão expirada. Abra o instalador novamente e tente de novo.'],
            ], 400);
        }

        $token = (string) $req->input('access_token', '');
        if (!$this->installer->tokenRequired() || !$this->installer->tokenValid($token)) {
            // Não repetimos o valor digitado, nem no HTML nem no log.
            return $this->render('install/access', [
                'next' => $this->nextPath($req),
                'errors' => ['Token incorreto. Confira o valor de SOCIALMEDIA_INSTALL_TOKEN no .env (sem espaços, sem aspas).'],
            ], 403);
        }

        $destino = $this->config->url($this->nextPath($req)) . '?access_token=' . rawurlencode($token);

        return Response::redirect($destino);
    }

    /** Caminho interno permitido como destino ("next"), sempre dentro de /install. */
    private function nextPath(Request $req): string
    {
        // Sem campo "next" na requisição o destino é /install. Importante:
        // input() devolve null (e não o padrão) quando a chave não existe — um
        // parse_url(null) estourava e o app caía no handler de erro 500.
        $pedido = (string) ($req->input('next') ?? '/install');
        if ($pedido === '') {
            $pedido = '/install';
        }
        // Só aceita rotas do instalador: nada de redirecionamento aberto.
        return $this->safePath((string) parse_url($pedido, PHP_URL_PATH));
    }

    private function guardAccess(Request $req): bool
    {
        return !$this->installer->tokenRequired() || $this->installer->tokenValid($req->input('access_token'));
    }

    /**
     * 403 com a tela de acesso. Guarda de onde a pessoa veio: quem estava em
     * /install/run volta para /install/run depois de colar o token — e não
     * para /install, que numa instância já criada responde 410.
     */
    private function denied(Request $req): Response
    {
        // O destino é onde a pessoa TENTOU entrar (o path da requisição), não
        // o campo "next" do formulário — que ainda não foi preenchido.
        return $this->render('install/access', ['next' => $this->safePath($req->path)], 403);
    }

    /** Aceita só caminhos do instalador; qualquer outra coisa vira /install. */
    private function safePath(string $caminho): string
    {
        $caminho = '/' . trim($caminho, '/');
        if ($caminho === '/' || !preg_match('#^/install(/[a-z]+)?$#', $caminho)) {
            return '/install';
        }

        return $caminho;
    }

    private function closed(): Response
    {
        return $this->render('install/closed', [
            'record' => $this->installer->installedRecord(),
            'concluida' => false,
        ], 410);
    }

    /**
     * Passo 3 ("Instância pronta"). 200 porque é o fim feliz do assistente —
     * o 410 fica para quem tenta reabrir /install numa instância já instalada.
     */
    private function done(): Response
    {
        return $this->render('install/closed', [
            'record' => $this->installer->installedRecord(),
            'concluida' => true,
        ]);
    }

    private function detectedOrigin(Request $req): string
    {
        $esquema = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443')
            ? 'https'
            : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $esquema . '://' . $host . $this->config->basePath;
    }

    /** @param array<string,mixed> $dados */
    private function render(string $template, array $dados, int $status = 200): Response
    {
        $resposta = $this->view->render($template, $dados + [
            'session' => $this->session,
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
        ], $status);

        return $resposta
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withSecurityHeaders();
    }
}
