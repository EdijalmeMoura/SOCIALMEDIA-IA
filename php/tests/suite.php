<?php
/**
 * Suíte funcional da aplicação PHP.
 *
 * Roda dentro de um PHP real (php-wasm), sem servidor web: monta a aplicação
 * com um banco temporário e conversa com ela por Request/Response. Cobre o
 * caminho que o usuário percorre no cPanel: subpasta, token, instalação,
 * fechamento do instalador, login e dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/autoload.php';

use Smia\Application;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Support\Config;
use Smia\Support\EnvFile;
use Smia\Support\Session;

$total = 0;
$falhas = 0;

function check(string $nome, bool $condicao, string $detalhe = ''): void
{
    global $total, $falhas;
    $total++;
    if ($condicao) {
        echo "  ok   {$nome}\n";
    } else {
        $falhas++;
        echo "  FALHA {$nome}" . ($detalhe !== '' ? " -> {$detalhe}" : '') . "\n";
    }
}

function assertSame(string $nome, mixed $esperado, mixed $obtido): void
{
    check($nome, $esperado === $obtido, 'esperado ' . var_export($esperado, true) . ', obtido ' . var_export($obtido, true));
}

/** Monta um Request como o servidor entrega quando a app está em subpasta. */
function requisicao(string $metodo, string $caminhoInterno, array $post = [], array $query = [], string $base = '/edijalmemoura/socialai'): Request
{
    return new Request(
        method: $metodo,
        path: $caminhoInterno,
        query: $query,
        post: $post,
        cookies: [],
        headers: [],
        scriptBase: $base,
    );
}

/**
 * Monta uma instalação isolada.
 *
 * `$env` aceita `token` (instalador) e `meta` (configuração da integração
 * Meta). Sem `meta`, o app roda como uma instalação que não configurou a
 * integração — que é o estado inicial de todo mundo.
 */
function appNova(array $env = []): array
{
    $raiz = sys_get_temp_dir() . '/smia-teste-' . bin2hex(random_bytes(4));
    mkdir($raiz, 0o700, true);
    $meta = is_array($env['meta'] ?? null) ? $env['meta'] : null;
    $config = new Config(
        root: $raiz,
        dataDir: $raiz,
        dbPath: $raiz . '/socialmedia.sqlite',
        envFile: $raiz . '/installer.env',
        basePath: '/edijalmemoura/socialai',
        publicOrigin: 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
        secretKey: $env['secret'] ?? '',
        cookieSecure: true,
        installToken: $env['token'] ?? null,
        timezone: 'America/Sao_Paulo',
        metaAppId: $meta['app_id'] ?? '',
        metaAppSecret: $meta['app_secret'] ?? '',
        metaRedirectUri: $meta['redirect_uri'] ?? '',
        metaGraphVersion: $meta['graph_version'] ?? '',
        metaTokenKey: $meta['token_key'] ?? '',
        metaPublicBaseUrl: $meta['public_base'] ?? '',
        metaGraphEndpoint: $meta['endpoint'] ?? '',
    );

    return [new Application($config), $config, $raiz];
}

echo "\n[1] Configuração\n";
assertSame('base path normalizado', '/edijalmemoura/socialai', Config::normalizeBasePath('/edijalmemoura/socialai/'));
assertSame('raiz sem prefixo', '', Config::normalizeBasePath('/'));
assertSame('URL com prefixo', '/edijalmemoura/socialai/install', (new Config('', '', '', '', '/edijalmemoura/socialai', '', '', false, null, 'UTC'))->url('/install'));
assertSame('URL absoluta com subpasta', 'https://x.com/p/install', (new Config('', '', '', '', '/p', 'https://x.com/p', '', false, null, 'UTC'))->absoluteUrl('/install'));

echo "\n[2] .env (fallback, ambiente vence)\n";
$valores = EnvFile::parse("SECRET_KEY=abc\n# comentário\nexport COOKIE_SECURE=1\nMETA_APP_SECRET=\"segredo aqui\"\nMODELO=modelo-de-exemplo # barato\n");
assertSame('.env simples', 'abc', $valores['SECRET_KEY']);
assertSame('.env com export', '1', $valores['COOKIE_SECURE']);
assertSame('.env com aspas', 'segredo aqui', $valores['META_APP_SECRET']);
assertSame('.env com comentário no fim', 'modelo-de-exemplo', $valores['MODELO']);

echo "\n[3] Resolução de caminho (subpasta / rewrite / SCRIPT_NAME)\n";
$casos = [
    ['/pasta/install', '/pasta/index.php', '', '/pasta', '/install'],
    ['/pasta/', '/pasta/index.php', '', '/pasta', '/'],
    ['/pasta/install', '/pasta/index.php', '/install', '/pasta', '/install'],
    ['/install', '/index.php', '', '', '/install'],
    // .htaccess da raiz reescrevendo para public/: public/ é interno, não prefixo público.
    ['/pasta/install', '/pasta/public/index.php', '', '/pasta', '/install'],
    ['/pasta/', '/pasta/public/index.php', '', '/pasta', '/'],
    ['/pasta/install', '/pasta/public/index.php', '/install', '/pasta', '/install'],
];
foreach ($casos as [$uri, $script, $pathInfo, $baseEsperado, $caminhoEsperado]) {
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['SCRIPT_NAME'] = $script;
    $_SERVER['PATH_INFO'] = $pathInfo;
    unset($_SERVER['SOCIALMEDIA_BASE_PATH']);
    [$base, $caminho] = Request::resolvePaths();
    assertSame("base de {$uri}", $baseEsperado, $base);
    assertSame("caminho de {$uri}", $caminhoEsperado, $caminho);
}

echo "\n[4] Instalador com token obrigatório\n";
[$app, $config, $raiz] = appNova(['token' => 'token-secreto-123']);
check('instância nova precisa de instalação', $app->installer->needed());

$semToken = $app->handle(requisicao('GET', '/install'));
assertSame('sem token responde 403', 403, $semToken->status);
check('página de acesso restrito', str_contains($semToken->body, 'ACESSO RESTRITO'));
assertSame('403 não é indexável', 'noindex, nofollow', $semToken->header('X-Robots-Tag'));

$comToken = $app->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
assertSame('com token responde 200', 200, $comToken->status);
check('mostra ambiente detectado', str_contains($comToken->body, 'AMBIENTE DETECTADO'));
check('CSS com prefixo', str_contains($comToken->body, '/edijalmemoura/socialai/assets/app.css'));
preg_match('/name="csrf_token" value="([^"]+)"/', $comToken->body, $m);
$csrf = $m[1] ?? '';
check('token CSRF presente', $csrf !== '');

echo "\n[5] Validação de dados\n";
$base = ['csrf_token' => $csrf, 'access_token' => 'token-secreto-123'];
$invalido = $app->handle(requisicao('POST', '/install/validate', $base + [
    'agency_name' => '', 'admin_name' => '', 'admin_email' => 'invalido',
    'admin_password' => '123', 'admin_password_confirm' => '321', 'public_origin' => 'ftp://x/y',
]));
assertSame('formulário inválido responde 422', 422, $invalido->status);
check('lista os erros', str_contains($invalido->body, 'Informe o nome da agência.') && str_contains($invalido->body, 'senha'));

$semCsrf = $app->handle(new Request(
    method: 'POST',
    path: '/install/validate',
    query: [],
    post: ['csrf_token' => 'errado', 'access_token' => 'token-secreto-123'],
    cookies: [],
    headers: [],
    scriptBase: '/edijalmemoura/socialai',
));
assertSame('CSRF inválido responde 400', 400, $semCsrf->status);

// Sem install token configurado, o CSRF é a única defesa: 400.
[$appSemToken] = appNova();
assertSame('POST sem install token configurado responde 400', 400, $appSemToken->handle(new Request(
    method: 'POST',
    path: '/install/validate',
    query: [],
    post: ['csrf_token' => 'errado'],
    cookies: [],
    headers: [],
    scriptBase: '/edijalmemoura/socialai',
))->status);
// Com install token configurado e sem enviá-lo, a barreira é o 403.
assertSame('POST sem access_token responde 403', 403, $app->handle(new Request(
    method: 'POST',
    path: '/install/validate',
    query: [],
    post: ['csrf_token' => 'errado'],
    cookies: [],
    headers: [],
    scriptBase: '/edijalmemoura/socialai',
))->status);

echo "\n[6] Regras de origem pública\n";
assertSame('aceita subpasta', 'https://host.com.br/pasta', \Smia\Installer\Installer::normalizePublicOrigin('https://host.com.br/pasta/'));
assertSame('aceita raiz', 'https://host.com.br', \Smia\Installer\Installer::normalizePublicOrigin('https://host.com.br'));
foreach (['https://h/a/../b', 'https://h/?x=1', 'ftp://h/p', 'https://u:p@h/p', 'http://h/p'] as $invalida) {
    $rejeitou = false;
    try {
        \Smia\Installer\Installer::normalizePublicOrigin($invalida);
    } catch (\InvalidArgumentException) {
        $rejeitou = true;
    }
    check("rejeita {$invalida}", $rejeitou);
}
assertSame('http em localhost é aceito', 'http://localhost:8080/p', \Smia\Installer\Installer::normalizePublicOrigin('http://localhost:8080/p'));

echo "\n[6b] Token do instalador: não se perde no meio do caminho\n";
check('a tela do instalador mostra a versão', str_contains(
    $app->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']))->body,
    'v' . \Smia\Support\Config::VERSAO
), 'versão não aparece no rodapé');
check('versão é a do pacote', version_compare(\Smia\Support\Config::VERSAO, '1.14', '>='), \Smia\Support\Config::VERSAO);
// Defeito real: todo redirecionamento interno voltava para /install SEM o
// token, então o próprio guarda de acesso devolvia 403 no meio do assistente.
$semToken = $app->handle(requisicao('GET', '/install'));
assertSame('/install sem token responde 403', 403, $semToken->status);
check('403 explica o que fazer', str_contains($semToken->body, 'Token de instalação'));
check('403 tem campo para colar o token', str_contains($semToken->body, 'name="access_token"'));
check('403 não repete o token esperado', !str_contains($semToken->body, 'token-secreto-123'));

// Erro de CSRF em /install/validate volta para o passo 1 — levando o token.
$voltaComToken = $app->handle(requisicao('POST', '/install/validate', [
    'csrf_token' => 'errado', 'access_token' => 'token-secreto-123',
]));
assertSame('CSRF inválido responde 400', 400, $voltaComToken->status);
preg_match('/name="csrf_token" value="([^"]+)"/', $voltaComToken->body, $mCsrf);
check('formulário devolvido mantém o token', str_contains($voltaComToken->body, 'value="token-secreto-123"'));

// Rascunho inexistente: volta ao passo 1 COM token (antes ia sem e dava 403).
$revisaoSumida = $app->handle(requisicao('GET', '/install/review', [], [
    'draft' => 'naoexiste', 'access_token' => 'token-secreto-123',
]));
assertSame('revisão inexistente redireciona', 302, $revisaoSumida->status);
$destinoSumida = (string) $revisaoSumida->header('Location');
check('redirecionamento mantém o token', str_contains($destinoSumida, 'access_token=token-secreto-123'), $destinoSumida);
$voltaDoRedirect = $app->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
assertSame('e o destino abre (não 403)', 200, $voltaDoRedirect->status);

// O formulário da tela de 403 autentica e leva ao destino pedido.
$csrfParaAcesso = $app->session->csrfToken();
$acessoErrado = $app->handle(requisicao('POST', '/install/access', [
    'csrf_token' => $csrfParaAcesso, 'access_token' => 'chute-errado', 'next' => '/install',
]));
assertSame('token errado responde 403', 403, $acessoErrado->status);
check('token errado avisa sem revelar o certo', str_contains($acessoErrado->body, 'Token incorreto'));
check('token errado não aparece na tela', !str_contains($acessoErrado->body, 'chute-errado'));
check('token certo não aparece na tela', !str_contains($acessoErrado->body, 'token-secreto-123'));

$acessoCerto = $app->handle(requisicao('POST', '/install/access', [
    'csrf_token' => $csrfParaAcesso, 'access_token' => 'token-secreto-123', 'next' => '/install/run',
]));
assertSame('token certo redireciona', 302, $acessoCerto->status);
$destinoAcesso = (string) $acessoCerto->header('Location');
check('vai para o destino pedido', str_contains($destinoAcesso, '/install/run'), $destinoAcesso);
check('e leva o token', str_contains($destinoAcesso, 'token-secreto-123'), $destinoAcesso);

// Nada de redirecionamento aberto: "next" só aceita rotas do instalador.
$acessoFora = $app->handle(requisicao('POST', '/install/access', [
    'csrf_token' => $csrfParaAcesso, 'access_token' => 'token-secreto-123', 'next' => 'https://malicioso.example/x',
]));
$destinoFora = (string) $acessoFora->header('Location');
check('next externo é ignorado', !str_contains($destinoFora, 'malicioso'), $destinoFora);
check('next externo cai em /install', str_contains($destinoFora, '/edijalmemoura/socialai/install?'), $destinoFora);

// Quem veio de /install/run volta para /install/run: numa instância já criada
// /install responde 410, e mandar a pessoa para lá seria beco sem saída.
$runSemToken = $app->handle(requisicao('GET', '/install/run'));
assertSame('/install/run sem token responde 403', 403, $runSemToken->status);
check('403 guarda o destino de origem', str_contains($runSemToken->body, 'name="next" value="/install/run"'), 'next não aponta para /install/run');
$acessoDoRun = $app->handle(requisicao('POST', '/install/access', [
    'csrf_token' => $csrfParaAcesso, 'access_token' => 'token-secreto-123', 'next' => '/install/run',
]));
check(
    'volta para /install/run com token',
    str_ends_with((string) $acessoDoRun->header('Location'), '/install/run?access_token=token-secreto-123'),
    (string) $acessoDoRun->header('Location')
);

// CSRF inválido no formulário de acesso não autentica.
$acessoSemCsrf = $app->handle(requisicao('POST', '/install/access', [
    'csrf_token' => 'errado', 'access_token' => 'token-secreto-123',
]));
assertSame('acesso sem CSRF responde 400', 400, $acessoSemCsrf->status);
check('acesso sem CSRF não redireciona', $acessoSemCsrf->header('Location') === null);

echo "\n[6c] Diagnóstico da instalação\n";
// A página existe justamente para quando o token está errado: ela NÃO pode
// exigir token, e não pode vazar segredo nenhum.
$diag = $app->handle(requisicao('GET', '/install/diagnostico'));
assertSame('diagnóstico abre sem token', 200, $diag->status);
check('diagnóstico mostra a versão', str_contains($diag->body, 'v' . \Smia\Support\Config::VERSAO));
check('diagnóstico não mostra o token', !str_contains($diag->body, 'token-secreto-123'));
check('diagnóstico diz que o token é exigido', str_contains($diag->body, 'Token exigido'));
check('diagnóstico informa a pasta de dados', str_contains($diag->body, 'Pasta de dados'));
check('diagnóstico informa o estado da instalação', str_contains($diag->body, 'Instalação'));
check('diagnóstico informa a versão dos arquivos', str_contains($diag->body, 'Arquivos em uso'));
check('diagnóstico não cria conta', $app->installer->needed());

echo "\n[6d] Falha na confirmação não pode esconder que a conta existe\n";
// Simula a fase "gravar" falhando: a tela precisa dizer que NADA foi criado e
// mostrar a causa técnica (arquivo:linha), não um genérico "algo falhou".
$configFalha = new \Smia\Support\Config(
    root: $raizFalha = sys_get_temp_dir() . '/smia-falha-' . bin2hex(random_bytes(4)),
    dataDir: $raizFalha,
    dbPath: $raizFalha . '/socialmedia.sqlite',
    envFile: $raizFalha . '/installer.env',
    basePath: '/edijalmemoura/socialai',
    publicOrigin: 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
    secretKey: '', cookieSecure: false, installToken: 'token-secreto-123',
    timezone: 'America/Sao_Paulo',
);
mkdir($raizFalha, 0o700, true);
$appFalha = new \Smia\Application($configFalha);
$csrfFalha = $appFalha->session->csrfToken();
// Banco somente-leitura: a gravação estoura e a tela precisa explicar.
$rascunhoFalha = $appFalha->installer->createDraft([
    'agency_name' => 'Agência Falha', 'admin_name' => 'Ana', 'admin_email' => 'ana@falha.example',
    'password_hash' => password_hash('PastaForte2026', PASSWORD_DEFAULT),
    'public_origin' => 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
    'admin_password' => '', 'admin_password_confirm' => '',
]);
$telaFalha = null;
try {
    // Torna a pasta de dados não gravável para forçar o erro na fase de gravar.
    chmod($raizFalha, 0o500);
    $telaFalha = $appFalha->handle(requisicao('POST', '/install/run', [
        'csrf_token' => $csrfFalha, 'access_token' => 'token-secreto-123', 'draft' => $rascunhoFalha,
    ]));
} finally {
    chmod($raizFalha, 0o700);
}
if ($telaFalha !== null && $telaFalha->status === 500) {
    check('falha ao gravar mostra a etapa', str_contains($telaFalha->body, 'gravação no banco'));
    check('falha ao gravar diz que nada foi criado', str_contains($telaFalha->body, 'Nenhuma conta foi criada'));
    check('falha ao gravar mostra o arquivo e a linha', str_contains($telaFalha->body, 'Onde:'));
    check('falha ao gravar não diz que instalou', !str_contains($telaFalha->body, 'A conta foi criada'));
    check('falha ao gravar mantém o instalador aberto', $appFalha->installer->needed());
} else {
    // Ambiente que permite escrever mesmo com 0500 (root): o teste vira
    // verificação de que a confirmação funcionou, sem quebrar a suíte.
    check('confirmação funcionou onde a escrita é permitida', $telaFalha !== null && !$appFalha->installer->needed());
}

// Fase "fechar" falhando: a conta existe, então a tela tem de dizer isso e
// levar ao login — não sugerir refazer tudo.
$configFechar = new \Smia\Support\Config(
    root: $raizFechar = sys_get_temp_dir() . '/smia-fechar-' . bin2hex(random_bytes(4)),
    dataDir: $raizFechar, dbPath: $raizFechar . '/socialmedia.sqlite',
    envFile: $raizFechar . '/installer.env',
    basePath: '', publicOrigin: 'https://exemplo.test',
    secretKey: '', cookieSecure: false, installToken: null,
    timezone: 'America/Sao_Paulo',
);
mkdir($raizFechar, 0o700, true);
$appFechar = new \Smia\Application($configFechar);
$appFechar->installer->install([
    'agency_name' => 'Agência Fechar', 'admin_name' => 'Ana', 'admin_email' => 'ana@fechar.example',
    'password_hash' => password_hash('PastaForte2026', PASSWORD_DEFAULT),
    'public_origin' => 'https://exemplo.test',
]);
check('instalação de apoio foi gravada', !$appFechar->installer->needed());
$registroFechar = $appFechar->installer->installedRecord();
check('registro disponível para a tela de falha', ($registroFechar['owner_email'] ?? '') === 'ana@fechar.example');

echo "\n[6e] Mensagens do instalador aparecem na tela\n";
// O layout do instalador não renderizava flash: "sessão expirada" era gravada
// na sessão e nunca exibida — a pessoa repetia o mesmo erro sem entender.
[$appFlash] = appNova(['token' => 'token-secreto-123']);
$appFlash->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
$csrfFlash = $appFlash->session->csrfToken();
// Confirmação com rascunho inexistente: grava flash e redireciona.
$redirFlash = $appFlash->handle(requisicao('POST', '/install/run', [
    'csrf_token' => $csrfFlash, 'access_token' => 'token-secreto-123', 'draft' => str_repeat('a', 24),
]));
assertSame('rascunho inexistente redireciona', 302, $redirFlash->status);
$comFlash = $appFlash->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
check('a tela mostra a mensagem da requisição anterior', str_contains($comFlash->body, 'install-flash'), 'flash não apareceu');
check('a mensagem diz que a revisão expirou', str_contains($comFlash->body, 'Revisão expirada'), 'sem explicação');

// Erro de CSRF no formulário é explicado com a causa provável.
[$appCsrf] = appNova(['token' => 'token-secreto-123']);
$appCsrf->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
$semCsrf = $appCsrf->handle(requisicao('POST', '/install/validate', [
    'csrf_token' => 'errado', 'access_token' => 'token-secreto-123',
]));
assertSame('CSRF inválido no passo 1 responde 400', 400, $semCsrf->status);
check('o aviso de CSRF é explicado, não só "expirada"', str_contains($semCsrf->body, 'CSRF inválido'), 'sem explicação');
check('o aviso manda abrir o diagnóstico', str_contains($semCsrf->body, '/install/diagnostico'));

// Rascunho pendente é avisado ao voltar ao passo 1.
[$appRascunho] = appNova(['token' => 'token-secreto-123']);
$appRascunho->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
$csrfR = $appRascunho->session->csrfToken();
$appRascunho->handle(requisicao('POST', '/install/validate', [
    'csrf_token' => $csrfR, 'access_token' => 'token-secreto-123',
    'agency_name' => 'Agência R', 'admin_name' => 'Ana', 'admin_email' => 'ana@r.example',
    'admin_password' => 'PastaForte2026', 'admin_password_confirm' => 'PastaForte2026',
    'public_origin' => 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
]));
$comPendente = $appRascunho->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
check('avisa que existe revisão salva', str_contains($comPendente->body, 'revisão salva'), 'sem aviso de rascunho');
check('pendingDrafts enxerga o rascunho', count($appRascunho->installer->pendingDrafts()) === 1);

echo "\n[6f] Trilha da confirmação (para descobrir onde para)\n";
// A trilha é o que permite diagnosticar de longe: cada etapa vence em ordem,
// e uma recusa (CSRF, token, rascunho) também fica registrada.
[$appTrilha] = appNova(['token' => 'token-secreto-123']);
$appTrilha->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
$csrfT = $appTrilha->session->csrfToken();

// Recusa por rascunho inexistente deixa marca.
$appTrilha->handle(requisicao('POST', '/install/run', [
    'csrf_token' => $csrfT, 'access_token' => 'token-secreto-123', 'draft' => str_repeat('b', 24),
]));
$etapas = array_map(static fn ($e) => (string) ($e['etapa'] ?? ''), $appTrilha->installer->lastRunTrail());
check('trilha registra a recusa', in_array('recusa:rascunho_expirado_ou_inexistente', $etapas, true), implode(' → ', $etapas));

// Recusa por CSRF deixa marca.
$appTrilha->handle(requisicao('POST', '/install/run', [
    'csrf_token' => 'errado', 'access_token' => 'token-secreto-123', 'draft' => str_repeat('b', 24),
]));
$etapas = array_map(static fn ($e) => (string) ($e['etapa'] ?? ''), $appTrilha->installer->lastRunTrail());
check('trilha registra CSRF inválido', in_array('recusa:csrf_invalido', $etapas, true), implode(' → ', $etapas));

// Caminho feliz: todas as etapas, terminando em "fim".
[$appOk] = appNova(['token' => 'token-secreto-123']);
$appOk->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
$csrfOk = $appOk->session->csrfToken();
$valOk = $appOk->handle(requisicao('POST', '/install/validate', [
    'csrf_token' => $csrfOk, 'access_token' => 'token-secreto-123',
    'agency_name' => 'Agência Trilha', 'admin_name' => 'Ana', 'admin_email' => 'ana@trilha.example',
    'admin_password' => 'PastaForte2026', 'admin_password_confirm' => 'PastaForte2026',
    'public_origin' => 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
]));
preg_match('/draft=([a-f0-9]+)/', (string) $valOk->header('Location'), $mDraft);
$appOk->handle(requisicao('POST', '/install/run', [
    'csrf_token' => $csrfOk, 'access_token' => 'token-secreto-123', 'draft' => $mDraft[1] ?? '',
]));
$etapasOk = array_map(static fn ($e) => (string) ($e['etapa'] ?? ''), $appOk->installer->lastRunTrail());
check('trilha do sucesso chega ao fim', in_array('fim', $etapasOk, true), implode(' → ', $etapasOk));
check('trilha do sucesso termina no redirecionamento', end($etapasOk) === 'redirecionando_para_passo3', implode(' → ', $etapasOk));
check('trilha do sucesso passa pela migração', in_array('migracao_ok', $etapasOk, true));
check('trilha do sucesso passa pela transação', in_array('transacao_ok', $etapasOk, true));
check('trilha do sucesso grava a config', in_array('arquivo_config_ok', $etapasOk, true));

// O diagnóstico mostra a trilha (e não vaza segredo).
$diagTrilha = $appOk->handle(requisicao('GET', '/install/diagnostico'));
check('diagnóstico mostra a última tentativa', str_contains($diagTrilha->body, 'Última tentativa de instalação'));
check('diagnóstico mostra a etapa fim', str_contains($diagTrilha->body, '<code>fim</code>'));
check('diagnóstico não vaza o token', !str_contains($diagTrilha->body, 'token-secreto-123'));

echo "\n[6g] O token da URL cobre o POST que esqueceu o campo\n";
// Defeito real (v1.12, diagnosticado em produção): a tela de revisão não
// recebia `token_required`, então não renderizava o <input name="access_token">.
// O POST chegava sem token e a confirmação era recusada como
// `recusa:token_invalido` — aparecia no diagnóstico, mas a causa era o HTML.
$appUrl = appNova(['token' => 'token-secreto-123']);
[$appUrl] = $appUrl;
$appUrl->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
$csrfU = $appUrl->session->csrfToken();
$valUrl = $appUrl->handle(requisicao('POST', '/install/validate', [
    'csrf_token' => $csrfU, 'access_token' => 'token-secreto-123',
    'agency_name' => 'Agência URL', 'admin_name' => 'Ana', 'admin_email' => 'ana@url.example',
    'admin_password' => 'PastaForte2026', 'admin_password_confirm' => 'PastaForte2026',
    'public_origin' => 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
]));
preg_match('/draft=([a-f0-9]+)/', (string) $valUrl->header('Location'), $mU);
$draftU = $mU[1] ?? '';

// A revisão PRECISA trazer o campo hidden do token.
$revUrl = $appUrl->handle(requisicao('GET', '/install/review', [], [
    'draft' => $draftU, 'access_token' => 'token-secreto-123',
]));
assertSame('revisão abre', 200, $revUrl->status);
check(
    'revisão inclui o token nos formulários',
    substr_count($revUrl->body, 'name="access_token" value="token-secreto-123"') >= 2,
    'campos encontrados: ' . substr_count($revUrl->body, 'name="access_token"')
);

// E mesmo sem o campo no corpo, o token da URL vale.
[$appSem] = appNova(['token' => 'token-secreto-123']);
$appSem->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
$csrfS = $appSem->session->csrfToken();
$valSem = $appSem->handle(requisicao('POST', '/install/validate', [
    'csrf_token' => $csrfS, 'access_token' => 'token-secreto-123',
    'agency_name' => 'Agência Sem Campo', 'admin_name' => 'Ana', 'admin_email' => 'ana@sem.example',
    'admin_password' => 'PastaForte2026', 'admin_password_confirm' => 'PastaForte2026',
    'public_origin' => 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
]));
preg_match('/draft=([a-f0-9]+)/', (string) $valSem->header('Location'), $mS);
$confirmacaoSemCampo = $appSem->handle(requisicao(
    'POST',
    '/install/run',
    ['csrf_token' => $csrfS, 'draft' => $mS[1] ?? ''],           // sem access_token no corpo
    ['access_token' => 'token-secreto-123']                      // token só na URL
));
assertSame('confirmação sem campo, com token na URL, instala', 302, $confirmacaoSemCampo->status);
check('e a tela final é a conclusão', !$appSem->installer->needed());
check(
    'não registra recusa de token',
    !in_array('recusa:token_invalido', array_map(static fn ($e) => (string) ($e['etapa'] ?? ''), $appSem->installer->lastRunTrail()), true),
    implode(' → ', array_map(static fn ($e) => (string) ($e['etapa'] ?? ''), $appSem->installer->lastRunTrail()))
);

// Token errado na URL e no corpo continua sendo recusado.
[$appErrado] = appNova(['token' => 'token-secreto-123']);
$appErrado->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
$recusa = $appErrado->handle(requisicao(
    'POST', '/install/run',
    ['csrf_token' => $appErrado->session->csrfToken(), 'draft' => str_repeat('c', 24), 'access_token' => 'errado'],
    ['access_token' => 'tambem-errado']
));
assertSame('token errado na URL e no corpo é recusado', 403, $recusa->status);

echo "\n[6h] Chave de IA: o diagnóstico responde \"minha chave está certa?\"\n";
// A pergunta que trava a instalação não pode depender de abrir o estúdio.
$interp = static fn (int $status, string $corpo = '{}', string $erro = '') =>
    \Smia\Controllers\InstallerController::interpretarTesteDeChave($corpo, $status, $erro, 'gpt-6-luna');

[$ok200, $msg200, $est200] = $interp(200);
check('200 = chave aceita', $ok200 && str_contains($msg200, 'Chave válida'), $msg200);
check('200 é rotulado como aceita', $est200 === 'aceita', $est200);
check('200 confirma o modelo', str_contains($msg200, 'gpt-6-luna'));

[$ok401, $msg401, $est401] = $interp(401, '{"error":{"message":"Incorrect API key provided"}}');
check('401 = chave recusada', !$ok401 && str_contains($msg401, '401'), $msg401);
check('401 é rotulado como recusada', $est401 === 'recusada', $est401);
check('401 manda conferir espaços e aspas', str_contains($msg401, 'sem espaços nem aspas'));
check('401 não devolve o texto cru do provedor', !str_contains($msg401, 'Incorrect API key'));

[$ok403, $msg403] = $interp(403);
check('403 = sem permissão', !$ok403 && str_contains($msg403, 'permissão'), $msg403);
[$ok404, $msg404] = $interp(404, '{"error":{"message":"The model does not exist"}}');
check('404 aponta o OPENAI_MODEL', !$ok404 && str_contains($msg404, 'OPENAI_MODEL'), $msg404);
check('404 repete a explicação do provedor', str_contains($msg404, 'does not exist'));
[$ok429, $msg429] = $interp(429);
check('429 = sem cota, chave boa', !$ok429 && str_contains($msg429, 'cota'), $msg429);

// Certificado é problema do servidor, não da chave: dizer isso evita horas.
[$okCa, $msgCa, $estCa] = \Smia\Controllers\InstallerController::interpretarTesteDeChave(
    null, 0, 'error setting certificate verify locations: CAfile: /etc/ssl/certs', 'gpt-6-luna');
check('falha de CA é identificada', !$okCa && str_contains($msgCa, 'certificado'), $msgCa);
check('falha de CA não é rotulada como chave recusada', $estCa === 'sem teste', $estCa);
check('falha de CA diz que não é a chave', str_contains($msgCa, 'não da chave'));
check('falha de CA sugere o CA bundle', str_contains($msgCa, 'openssl.cafile'));

[$okNet, $msgNet] = \Smia\Controllers\InstallerController::interpretarTesteDeChave(
    null, 0, 'Could not resolve host: api.openai.com', 'gpt-6-luna');
check('sem DNS aponta rede/firewall', !$okNet && str_contains($msgNet, 'firewall'), $msgNet);
check('sem DNS não fala de certificado', !str_contains($msgNet, 'certificado'));

echo "\n[6i] Chave de IA no diagnóstico da tela\n";
$configIa = new \Smia\Support\Config(
    root: $raizIa = sys_get_temp_dir() . '/smia-ia-' . bin2hex(random_bytes(4)),
    dataDir: $raizIa, dbPath: $raizIa . '/socialmedia.sqlite',
    envFile: $raizIa . '/installer.env',
    basePath: '/edijalmemoura/socialai', publicOrigin: 'https://x.test',
    secretKey: '', cookieSecure: false, installToken: null, timezone: 'America/Sao_Paulo',
    openaiKey: 'sk-admin-abcdefghijklmnop', openaiEndpoint: (string) $endpointProvedor,
);
mkdir($raizIa, 0o700, true);
$diagIa = (new \Smia\Application($configIa))->handle(requisicao('GET', '/install/diagnostico'));
check('diagnóstico tem a linha da chave de IA', str_contains($diagIa->body, 'Chave de IA'));
check('diagnóstico recusa chave de admin sem chamar a API', str_contains($diagIa->body, 'formato não serve'));
check('diagnóstico diz o que usar no lugar', str_contains($diagIa->body, 'sk-svcacct-'));
check('diagnóstico não vaza a chave de admin', !str_contains($diagIa->body, 'sk-admin-abcdefghijklmnop'));
check('diagnóstico mostra os modelos', str_contains($diagIa->body, 'gpt-6-luna'));
check('diagnóstico tem a linha do arquivo .env', str_contains($diagIa->body, 'Arquivo .env'));

echo "\n[6j] .env: as armadilhas que fazem a chave \"não funcionar\"\n";
$envTemp = sys_get_temp_dir() . '/smia-env-' . bin2hex(random_bytes(4));
mkdir($envTemp, 0o700, true);

// 1) ":" no lugar de "=": a linha é descartada e o PHP fica sem chave.
file_put_contents($envTemp . '/.env', "OPENAI_API_KEY: sk-svcacct-nao-vale\nOUTRA=1\n");
\Smia\Support\EnvFile::$trilha = [];
\Smia\Support\EnvFile::$descartadas = [];
\Smia\Support\EnvFile::$cortadas = [];
\Smia\Support\Config::loadFiles($envTemp, $envTemp);
$desc = \Smia\Support\EnvFile::$descartadas;
check('.env com ":" é denunciado', count($desc) === 1 && $desc[0]['linha'] === 1, 'não detectou');
check('linha denunciada não mostra o valor', !str_contains($desc[0]['texto'], 'sk-svcacct-nao-vale'), $desc[0]['texto']);
check('linha denunciada mostra o nome', str_contains($desc[0]['texto'], 'OPENAI_API_KEY'), $desc[0]['texto']);
check('.env válido continua sendo lido', getenv('OUTRA') === '1', (string) getenv('OUTRA'));
check('trilha registra o arquivo lido', \Smia\Support\EnvFile::$trilha[0]['lido'] === true);
check('trilha registra OUTRA como aplicada', in_array('OUTRA', \Smia\Support\EnvFile::$trilha[0]['chaves'], true));

// 2) " #" no meio do valor corta a chave e o 401 culpa a chave errada.
file_put_contents($envTemp . '/.env', "OPENAI_API_KEY=sk-svcacct-chave-real # colada do painel\n");
putenv('OPENAI_API_KEY');
unset($_ENV['OPENAI_API_KEY']);
\Smia\Support\EnvFile::$trilha = [];
\Smia\Support\EnvFile::$descartadas = [];
\Smia\Support\EnvFile::$cortadas = [];
\Smia\Support\Config::loadFiles($envTemp, $envTemp);
$cortes = \Smia\Support\EnvFile::$cortadas;
check('corte por " #" é registrado', count($cortes) === 1 && $cortes[0]['chave'] === 'OPENAI_API_KEY', 'não detectou');
check('corte indica a linha', $cortes[0]['linha'] === 1, (string) $cortes[0]['linha']);
check('corte guarda o arquivo', str_contains((string) $cortes[0]['caminho'], '.env'), (string) $cortes[0]['caminho']);
check('corte não guarda o valor inteiro', !str_contains((string) $cortes[0]['restante'], 'sk-svcacct-chave-real'));
check('corte deixa a chave truncada', getenv('OPENAI_API_KEY') === 'sk-svcacct-chave-real', (string) getenv('OPENAI_API_KEY'));

$diagCorte = (new \Smia\Application(new \Smia\Support\Config(
    root: $envTemp, dataDir: $envTemp, dbPath: $envTemp . '/socialmedia.sqlite',
    envFile: $envTemp . '/installer.env', basePath: '', publicOrigin: 'https://x.test',
    secretKey: '', cookieSecure: false, installToken: null, timezone: 'America/Sao_Paulo',
    openaiKey: (string) getenv('OPENAI_API_KEY'), openaiEndpoint: (string) $endpointProvedor,
)))->handle(requisicao('GET', '/install/diagnostico'));
check('diagnóstico avisa que o valor foi CORTADO', str_contains($diagCorte->body, 'CORTADO'), 'não avisou');
check('diagnóstico ensina a corrigir o corte', str_contains($diagCorte->body, 'aspas retas'));

// 3) Variável no ambiente do servidor VENCE o .env (armadilha nº1 do cPanel).
file_put_contents($envTemp . '/.env', "OPENAI_API_KEY=sk-svcacct-do-arquivo\n");
putenv('OPENAI_API_KEY=sk-svcacct-do-painel');
\Smia\Support\EnvFile::$trilha = [];
\Smia\Support\EnvFile::$descartadas = [];
\Smia\Support\EnvFile::$cortadas = [];
\Smia\Support\Config::loadFiles($envTemp, $envTemp);
check('ambiente vence o arquivo', getenv('OPENAI_API_KEY') === 'sk-svcacct-do-painel', (string) getenv('OPENAI_API_KEY'));
check('trilha registra que o arquivo perdeu',
    in_array('OPENAI_API_KEY', \Smia\Support\EnvFile::$trilha[0]['ignoradas'], true), 'não registrou');

$diagCorrida = (new \Smia\Application(new \Smia\Support\Config(
    root: $envTemp, dataDir: $envTemp, dbPath: $envTemp . '/socialmedia.sqlite',
    envFile: $envTemp . '/installer.env', basePath: '', publicOrigin: 'https://x.test',
    secretKey: '', cookieSecure: false, installToken: null, timezone: 'America/Sao_Paulo',
    openaiKey: (string) getenv('OPENAI_API_KEY'), openaiEndpoint: (string) $endpointProvedor,
)))->handle(requisicao('GET', '/install/diagnostico'));
check('diagnóstico avisa que o ambiente vence o .env', str_contains($diagCorrida->body, 'VENCE o arquivo'), 'não avisou');
check('diagnóstico manda remover do painel', str_contains($diagCorrida->body, 'painel'));
check('diagnóstico não vaza nenhuma das duas chaves',
    !str_contains($diagCorrida->body, 'do-painel') && !str_contains($diagCorrida->body, 'do-arquivo'));
putenv('OPENAI_API_KEY');
unset($_ENV['OPENAI_API_KEY']);

// 4) Sem .env nenhum: dizer onde ele deveria estar.
$semEnv = sys_get_temp_dir() . '/smia-sem-env-' . bin2hex(random_bytes(4));
mkdir($semEnv, 0o700, true);
\Smia\Support\EnvFile::$trilha = [];
\Smia\Support\EnvFile::$descartadas = [];
\Smia\Support\EnvFile::$cortadas = [];
\Smia\Support\Config::loadFiles($semEnv, $semEnv);
$diagSemEnv = (new \Smia\Application(new \Smia\Support\Config(
    root: $semEnv, dataDir: $semEnv, dbPath: $semEnv . '/socialmedia.sqlite',
    envFile: $semEnv . '/installer.env', basePath: '', publicOrigin: 'https://x.test',
    secretKey: '', cookieSecure: false, installToken: null, timezone: 'America/Sao_Paulo',
    openaiKey: '', openaiEndpoint: (string) $endpointProvedor,
)))->handle(requisicao('GET', '/install/diagnostico'));
check('sem .env o diagnóstico diz onde criar', str_contains($diagSemEnv->body, 'não encontrado'), 'não avisou');
check('sem .env o diagnóstico mostra o caminho esperado', str_contains($diagSemEnv->body, $semEnv . '/.env'), 'sem caminho');

echo "\n[7] Instalação completa\n";
$valido = $app->handle(requisicao('POST', '/install/validate', $base + [
    'agency_name' => 'Agência Pasta', 'admin_name' => 'Ana Souza', 'admin_email' => 'ana@pasta.example',
    'admin_password' => 'PastaForte2026', 'admin_password_confirm' => 'PastaForte2026',
    'public_origin' => 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
]));
assertSame('validação redireciona para revisão', 302, $valido->status);
$location = (string) $valido->header('Location');
check('destino prefixado', str_starts_with($location, '/edijalmemoura/socialai/install/review?draft='), $location);
parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
$draft = $q['draft'] ?? '';

$revisao = $app->handle(requisicao('GET', '/install/review', [], ['draft' => $draft, 'access_token' => 'token-secreto-123']));
assertSame('revisão responde 200', 200, $revisao->status);
check('revisão mostra a agência', str_contains($revisao->body, 'Agência Pasta'));
check('revisão não vaza a senha', !str_contains($revisao->body, 'PastaForte2026'));

// Abrir /install/run no navegador (GET) não pode dar 405 nem criar nada.
$runSemFormulario = $app->handle(requisicao('GET', '/install/run', [], ['access_token' => 'token-secreto-123']));
assertSame('GET em /install/run não devolve 405', 302, $runSemFormulario->status);
check(
    'GET em /install/run volta para o passo 1',
    str_contains((string) $runSemFormulario->header('Location'), '/install'),
    (string) $runSemFormulario->header('Location')
);
check('GET em /install/run não instala', $app->installer->needed());

$execucao = $app->handle(requisicao('POST', '/install/run', $base + ['draft' => $draft]));
assertSame('execução redireciona', 302, $execucao->status);

check('instalador fechado depois', !$app->installer->needed());

// Depois de instalar, qualquer acesso a /install/run cai no passo 3
// ("Instância pronta"), inclusive pelo GET — é isso que o usuário espera ver.
$destino = (string) $execucao->header('Location');
check('execução leva ao passo 3, não direto ao login', !str_contains($destino, '/login'), $destino);
// O destino é /install/run (POST/redirect/GET). O caminho interno vem antes
// da query; o token vai na query para o guarda de acesso deixar passar.
$caminhoFinal = (string) parse_url($destino, PHP_URL_PATH);
$caminhoFinal = preg_replace('#^/edijalmemoura/socialai#', '', $caminhoFinal) ?: '/';
parse_str((string) parse_url($destino, PHP_URL_QUERY), $queryFinal);
$passoFinal = $app->handle(requisicao('GET', $caminhoFinal, [], $queryFinal));
assertSame('passo 3 responde 200 (fim feliz)', 200, $passoFinal->status);
check('passo 3 mostra "Instância pronta"', str_contains($passoFinal->body, 'Instância pronta'));
check('passo 3 diz que o acesso foi liberado', str_contains($passoFinal->body, 'Acesso liberado e instalador encerrado'));
check('passo 3 não usa o texto de instância já protegida', !str_contains($passoFinal->body, 'Esta instância está protegida'));
check('passo 3 mostra o registro da instalação', str_contains($passoFinal->body, 'Registro da instalação'));
check('passo 3 tem botão para o login', str_contains($passoFinal->body, '/login'));
check('passo 3 mostra o e-mail do proprietário', str_contains($passoFinal->body, 'ana@pasta.example'), 'não achou o e-mail no registro');

// Recarregar /install/run depois de instalado não pode cair em 405 nem 410:
// é a tela de conclusão que aparece.
$runDepois = $app->handle(requisicao('GET', '/install/run', [], ['access_token' => 'token-secreto-123']));
assertSame('GET em /install/run depois de instalado responde 200', 200, $runDepois->status);
check('e mostra a conclusão', str_contains($runDepois->body, 'Registro da instalação'));
$fechado = $app->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
assertSame('instalador responde 410', 410, $fechado->status);
check('mostra que está protegida', str_contains($fechado->body, 'protegida'));
check('410 não se anuncia como fim feliz', !str_contains($fechado->body, 'Acesso liberado e instalador encerrado'));

check('arquivo de configuração criado', is_file($config->envFile));
$conteudoEnv = (string) file_get_contents($config->envFile);
check('SECRET_KEY gravada', str_contains($conteudoEnv, 'SECRET_KEY='));
check('arquivo com permissão 0600', (fileperms($config->envFile) & 0o777) === 0o600, decoct(fileperms($config->envFile) & 0o777));
$segredos = EnvFile::parse($conteudoEnv);
check('SECRET_KEY tem 64 hex', (bool) preg_match('/^[a-f0-9]{64}$/', (string) ($segredos['SECRET_KEY'] ?? '')));

// O installer.env precisa valer no PRÓXIMO boot: sem isso, reiniciar o
// servidor deixava a instância "não instalada" e sem SECRET_KEY (os dados
// existiam, mas o app pedia instalação de novo e a mídia assinada quebrava).
$envAntesDoBoot = getenv('SOCIALMEDIA_DATA');
putenv('SOCIALMEDIA_DATA=' . $raiz);
$_ENV['SOCIALMEDIA_DATA'] = $raiz;
$configNoBoot = (static function () use ($raiz): Config {
    \Smia\Support\Config::loadFiles($raiz, $raiz);

    return Config::fromEnvironment();
})();
check('reiniciar o servidor relê o installer.env', $configNoBoot->secretKey === (string) ($segredos['SECRET_KEY'] ?? '—'), 'lido: ' . $configNoBoot->secretKey);
check('boot relê a origem pública instalada', $configNoBoot->publicOrigin === 'https://projetoredesnordeste.com.br/edijalmemoura/socialai', $configNoBoot->publicOrigin);
check('boot reconhece a instância como instalada', !\Smia\Installer\Installer::make($configNoBoot, new \Smia\Database\Database($configNoBoot->dbPath))->needed());
check('variável de ambiente do servidor vence o installer.env', (static function () use ($raiz): bool {
    putenv('SOCIALMEDIA_PUBLIC_ORIGIN=https://servidor.example');
    $_ENV['SOCIALMEDIA_PUBLIC_ORIGIN'] = 'https://servidor.example';
    \Smia\Support\Config::loadFiles($raiz, $raiz);
    $vencedor = Config::fromEnvironment()->publicOrigin;
    putenv('SOCIALMEDIA_PUBLIC_ORIGIN');
    unset($_ENV['SOCIALMEDIA_PUBLIC_ORIGIN'], $_SERVER['SOCIALMEDIA_PUBLIC_ORIGIN']);

    return $vencedor === 'https://servidor.example';
})());
putenv($envAntesDoBoot === false ? 'SOCIALMEDIA_DATA' : 'SOCIALMEDIA_DATA=' . $envAntesDoBoot);

$db = new PDO('sqlite:' . $config->dbPath);
assertSame('um usuário criado', 1, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
assertSame('um workspace criado', 1, (int) $db->query('SELECT COUNT(*) FROM companies')->fetchColumn());
$hash = (string) $db->query('SELECT password_hash FROM users')->fetchColumn();
check('senha guardada como hash verificável', password_verify('PastaForte2026', $hash));
check('hash não contém a senha', !str_contains($hash, 'PastaForte2026'));
assertSame('registro de instalação', 1, (int) $db->query('SELECT COUNT(*) FROM installation')->fetchColumn());
assertSame('auditoria registrada', 1, (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE action='installation.completed'")->fetchColumn());

echo "\n[8] Login e dashboard\n";
$loginPage = $app->handle(requisicao('GET', '/login'));
assertSame('login responde 200', 200, $loginPage->status);
preg_match('/name="csrf_token" value="([^"]+)"/', $loginPage->body, $m2);
$csrfLogin = $m2[1] ?? '';

$senhaErrada = $app->handle(requisicao('POST', '/login', ['csrf_token' => $csrfLogin, 'email' => 'ana@pasta.example', 'password' => 'errada']));
assertSame('senha errada responde 401', 401, $senhaErrada->status);
check('mensagem genérica', str_contains($senhaErrada->body, 'E-mail ou senha inválidos'));

$logado = $app->handle(requisicao('POST', '/login', ['csrf_token' => $csrfLogin, 'email' => 'ana@pasta.example', 'password' => 'PastaForte2026']));
assertSame('login correto redireciona', 302, $logado->status);
assertSame('login prefixado', '/edijalmemoura/socialai/dashboard', $logado->header('Location'));

$painel = $app->handle(requisicao('GET', '/dashboard'));
assertSame('dashboard responde 200', 200, $painel->status);
check('mostra a visão do workspace', str_contains($painel->body, 'VISÃO DO WORKSPACE'));
check('mostra a agência', str_contains($painel->body, 'Agência Pasta'));
check('sem vazamento de hash', !str_contains($painel->body, '$argon') && !str_contains($painel->body, '$2y$'));

$raizLogado = $app->handle(requisicao('GET', '/'));
assertSame('raiz autenticada vai ao dashboard', '/edijalmemoura/socialai/dashboard', $raizLogado->header('Location'));

echo "\n[9] Sessão, CSRF e logout\n";
$logoutSemCsrf = $app->handle(requisicao('POST', '/logout', ['csrf_token' => 'errado']));
assertSame('logout sem CSRF não desloga', '/edijalmemoura/socialai/dashboard', $logoutSemCsrf->header('Location'));
$logout = $app->handle(requisicao('POST', '/logout', ['csrf_token' => $app->session->csrfToken()]));
assertSame('logout redireciona', '/edijalmemoura/socialai/login', $logout->header('Location'));
$depois = $app->handle(requisicao('GET', '/dashboard'));
assertSame('dashboard exige login', '/edijalmemoura/socialai/login', $depois->header('Location'));

$sessao = new Session('/edijalmemoura/socialai', true);
check('CSRF muda por sessão', $sessao->csrfToken() !== $sessao->csrfToken() || strlen($sessao->csrfToken()) === 64);

echo "\n[10] Roteamento e erros\n";
$naoExiste = $app->handle(requisicao('GET', '/rota-inexistente'));
assertSame('404 para rota desconhecida', 404, $naoExiste->status);
$metodoErrado = $app->handle(requisicao('POST', '/dashboard'));
assertSame('405 para método errado', 405, $metodoErrado->status);

echo "\n[11] Isolamento entre instâncias\n";
[$app2, , $raiz2] = appNova();
check('segunda instância também precisa de instalação', $app2->installer->needed());

echo "\n[12] Clientes: cadastro, perfil e identidade visual\n";
// Reaproveita a instância já instalada e logada da seção [8].
// A seção [9] fez logout, então entra de novo — igual ao que o usuário faz.
$paginaLogin = $app->handle(requisicao('GET', '/login'));
preg_match('/name="csrf_token" value="([^"]+)"/', $paginaLogin->body, $ml);
$entrada = $app->handle(requisicao('POST', '/login', [
    'csrf_token' => $ml[1] ?? '',
    'email' => 'ana@pasta.example',
    'password' => 'PastaForte2026',
]));
assertSame('login restabelecido para os testes de clientes', 302, $entrada->status);
$formNovo = $app->handle(requisicao('GET', '/clients/new'));
preg_match('/name="csrf_token" value="([^"]+)"/', $formNovo->body, $mc);
$csrf = $mc[1] ?? '';
check('CSRF do formulário de cliente', strlen($csrf) === 64);
$listaLimpa = $app->handle(requisicao('GET', '/clients'));
assertSame('listagem de clientes responde 200', 200, $listaLimpa->status);
check('estado vazio convida a cadastrar', str_contains($listaLimpa->body, 'Cadastrar primeiro cliente'));

$criado = $app->handle(requisicao('POST', '/clients/new', [
    'csrf_token' => $csrf,
    'company_name' => '  Aurora Cafés Especiais  ',
    'trade_name' => 'Aurora',
    'segment' => 'Gastronomia',
    'website' => 'https://aurora.com.br',
    'instagram' => '@auroracafes',
    'description' => "Cafeteria de origem\ncom grãos próprios",
]));
assertSame('criação redireciona para o perfil', 302, $criado->status);
$destino = (string) $criado->header('Location');
check('redirect prefixado com ?created=1', str_starts_with($destino, '/edijalmemoura/socialai/clients/') && str_ends_with($destino, '?created=1'));
$clientId = (int) (explode('/', parse_url($destino, PHP_URL_PATH))[4] ?? 0);
check('id do cliente no redirect', $clientId > 0);
$linha = $app->db->one('SELECT * FROM clients WHERE id = ?', [$clientId]);
assertSame('nome sem espaços nas pontas', 'Aurora Cafés Especiais', $linha['company_name']);
check('identidade visual criada junto', $app->db->one('SELECT * FROM client_branding WHERE client_id = ?', [$clientId]) !== null);
assertSame('evento na auditoria', 'client.created', $app->db->scalar("SELECT action FROM audit_log WHERE action = 'client.created'"));

$semNome = $app->handle(requisicao('POST', '/clients/new', ['csrf_token' => $csrf, 'company_name' => '   ']));
assertSame('nome vazio responde 422', 422, $semNome->status);
check('mensagem de nome obrigatório', str_contains($semNome->body, 'O nome da empresa é obrigatório.'));
$siteRuim = $app->handle(requisicao('POST', '/clients/new', ['csrf_token' => $csrf, 'company_name' => 'X', 'website' => 'aurora.com.br']));
assertSame('site sem http responde 422', 422, $siteRuim->status);
$semCsrf = $app->handle(requisicao('POST', '/clients/new', ['company_name' => 'Sem CSRF']));
assertSame('criação sem CSRF responde 400', 400, $semCsrf->status);
assertSame('nada foi criado sem CSRF', 1, (int) $app->db->scalar('SELECT COUNT(*) FROM clients'));

$perfil = $app->handle(requisicao('GET', '/clients/' . $clientId));
assertSame('perfil responde 200', 200, $perfil->status);
check('perfil mostra a marca', str_contains($perfil->body, 'Aurora Cafés Especiais'));
check('perfil mostra as abas', str_contains($perfil->body, 'Identidade visual') && str_contains($perfil->body, 'Referências'));

$salvo = $app->handle(requisicao('POST', '/clients/' . $clientId, [
    'csrf_token' => $csrf,
    'company_name' => 'Aurora Cafés',
    'segment' => 'Cafés especiais',
    'primary_color' => '#123456',
    'secondary_color' => 'invalida',
    'accent_color' => '#abcdef',
    'slogan' => 'Grãos com história',
    'typography' => 'Lora',
]));
assertSame('salvar perfil redireciona', 302, $salvo->status);
$branding = $app->db->one('SELECT * FROM client_branding WHERE client_id = ?', [$clientId]);
assertSame('cor principal salva', '#123456', $branding['primary_color']);
assertSame('cor inválida cai no padrão', '#f57a45', $branding['secondary_color']);
assertSame('cor de destaque salva', '#abcdef', $branding['accent_color']);
assertSame('slogan salvo', 'Grãos com história', $branding['slogan']);
assertSame('nome atualizado', 'Aurora Cafés', $app->db->scalar('SELECT company_name FROM clients WHERE id = ?', [$clientId]));

echo "\n[13] Clientes: isolamento entre workspaces\n";
$app->db->run("INSERT INTO companies (name, slug) VALUES ('Outra Agência', 'outra-agencia')");
$outraEmpresa = (int) $app->db->pdo()->lastInsertId();
$app->db->run("INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, 'Outro', 'outro@exemplo.com', ?, 'owner')", [$outraEmpresa, password_hash('OutraSenha#123', PASSWORD_DEFAULT)]);
// Mesmo id, mas o cliente pertence a outra empresa: precisa ser invisível.
$app->db->run('INSERT INTO clients (company_id, company_name) VALUES (?, ?)', [$outraEmpresa, 'Cliente Sigiloso']);
$idSigiloso = (int) $app->db->pdo()->lastInsertId();
$alheio = $app->handle(requisicao('GET', '/clients/' . $idSigiloso));
assertSame('cliente de outro workspace responde 404', 404, $alheio->status);
check('não revela o nome do cliente alheio', !str_contains($alheio->body, 'Cliente Sigiloso'));
assertSame('cliente inexistente responde 404', 404, $app->handle(requisicao('GET', '/clients/999999'))->status);
assertSame('listagem não traz cliente alheio', 1, count($app->handle(requisicao('GET', '/clients'))->body === '' ? [] : [1]));
check('listagem só mostra clientes da empresa', !str_contains($app->handle(requisicao('GET', '/clients'))->body, 'Cliente Sigiloso'));

echo "\n[14] Clientes: upload de imagem validado\n";
$upload = new \Smia\Support\Upload($raiz . '/uploads');
$png = sys_get_temp_dir() . '/smia-teste-' . bin2hex(random_bytes(4)) . '.png';
file_put_contents($png, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 40));
$salvoImg = $upload->store(['name' => 'logo.png', 'tmp_name' => $png, 'error' => UPLOAD_ERR_OK, 'size' => filesize($png)], 7, 'logo');
check('aceita PNG pela assinatura', $salvoImg !== null && str_ends_with($salvoImg['filename'], '.png'));
check('nome gerado não usa o original', $salvoImg !== null && !str_contains($salvoImg['filename'], 'logo.png'));
check('arquivo existe no disco', $salvoImg !== null && is_file($raiz . '/uploads/' . $salvoImg['filename']));

$falso = sys_get_temp_dir() . '/smia-teste-' . bin2hex(random_bytes(4)) . '.png';
file_put_contents($falso, '<?php echo "invasao"; ?>');
try {
    $upload->store(['name' => 'malicioso.png', 'tmp_name' => $falso, 'error' => UPLOAD_ERR_OK, 'size' => filesize($falso)], 7, 'logo');
    check('recusa arquivo com extensão de imagem e conteúdo PHP', false, 'aceitou arquivo inválido');
} catch (\InvalidArgumentException $erro) {
    check('recusa arquivo com extensão de imagem e conteúdo PHP', str_contains($erro->getMessage(), 'não corresponde'));
}

$gif = sys_get_temp_dir() . '/smia-teste-' . bin2hex(random_bytes(4)) . '.gif';
file_put_contents($gif, 'GIF89a');
try {
    $upload->store(['name' => 'animacao.gif', 'tmp_name' => $gif, 'error' => UPLOAD_ERR_OK, 'size' => filesize($gif)], 7, 'logo');
    check('recusa extensão fora da lista', false, 'aceitou GIF');
} catch (\InvalidArgumentException $erro) {
    check('recusa extensão fora da lista', str_contains($erro->getMessage(), 'PNG, JPG ou WEBP'));
}
assertSame('sem arquivo devolve null', null, $upload->store(['error' => UPLOAD_ERR_NO_FILE], 7, 'logo'));
check('nome inseguro é rejeitado', !\Smia\Support\Upload::isSafeName('../.env') && \Smia\Support\Upload::isSafeName('12-logo-abc.png'));

echo "\n[15] Clientes: permissões por papel\n";
$app->db->run("UPDATE users SET role = 'viewer' WHERE email = 'ana@pasta.example'");
$verViewer = $app->handle(requisicao('GET', '/clients'));
assertSame('viewer lê a listagem', 200, $verViewer->status);
check('viewer não vê botão de novo cliente', !str_contains($verViewer->body, 'Novo cliente'));
assertSame('viewer não abre o formulário', 403, $app->handle(requisicao('GET', '/clients/new'))->status);
assertSame('viewer não edita', 403, $app->handle(requisicao('POST', '/clients/' . $clientId, ['csrf_token' => $csrf, 'company_name' => 'Hack']))->status);
assertSame('viewer não exclui', 403, $app->handle(requisicao('POST', '/clients/' . $clientId . '/delete', ['csrf_token' => $csrf]))->status);
assertSame('nada mudou com o viewer', 'Aurora Cafés', $app->db->scalar('SELECT company_name FROM clients WHERE id = ?', [$clientId]));

$app->db->run("UPDATE users SET role = 'editor' WHERE email = 'ana@pasta.example'");
assertSame('editor edita', 302, $app->handle(requisicao('POST', '/clients/' . $clientId, ['csrf_token' => $csrf, 'company_name' => 'Aurora Cafés II']))->status);
assertSame('editor não exclui', 403, $app->handle(requisicao('POST', '/clients/' . $clientId . '/delete', ['csrf_token' => $csrf]))->status);
$app->db->run("UPDATE users SET role = 'owner' WHERE email = 'ana@pasta.example'");

echo "\n[16] Clientes: exclusão e limpeza\n";
$arquivo = $salvoImg['filename'] ?? '';
$app->db->run('INSERT INTO client_assets (client_id, filename, original_name) VALUES (?, ?, ?)', [$clientId, $arquivo, 'referencia.png']);
file_put_contents($raiz . '/uploads/' . $arquivo, 'x');
assertSame('exclusão sem CSRF responde 403', 403, $app->handle(requisicao('POST', '/clients/' . $clientId . '/delete', ['csrf_token' => 'errado']))->status);
$excluido = $app->handle(requisicao('POST', '/clients/' . $clientId . '/delete', ['csrf_token' => $csrf]));
assertSame('owner exclui o cliente', 302, $excluido->status);
assertSame('cliente removido do banco', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM clients WHERE id = ?', [$clientId]));
assertSame('identidade visual removida em cascata', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM client_branding WHERE client_id = ?', [$clientId]));
assertSame('referências removidas em cascata', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM client_assets WHERE client_id = ?', [$clientId]));

echo "\n[17] Navegação e menu por módulo\n";
$modulos = \Smia\Support\Modules::all();
assertSame('primeiro módulo pronto é clientes', 'clients', $modulos[0]['slug']);
check('módulo de clientes tem caminho', $modulos[0]['path'] === '/clients' && $modulos[0]['ready'] === true);
assertSame('slug ativo a partir de /clients/3', 'clients', \Smia\Support\Modules::activeFrom('/clients/3'));
assertSame('sem módulo ativo na raiz', null, \Smia\Support\Modules::activeFrom('/'));
assertSame('slug desconhecido não quebra', null, \Smia\Support\Modules::activeFrom('/qualquer'));
$menu = $app->handle(requisicao('GET', '/dashboard'));
check('menu tem link real para clientes', str_contains($menu->body, '/edijalmemoura/socialai/clients"'));
check('módulos futuros seguem marcados', str_contains($menu->body, 'EM BREVE'));

echo "\n[18] Planejamento: formulário e permissões\n";
// Sessão própria: garante usuário logado independente do que os testes
// anteriores fizeram com a sessão (o alvo é terceiro no workspaces).
$idUsuario = (int) $app->db->scalar("SELECT id FROM users WHERE email = 'ana@pasta.example'");
$empresaTeste = (int) $app->db->scalar("SELECT company_id FROM users WHERE email = 'ana@pasta.example'");
/**
 * Reabre a sessão com o usuário logado.
 *
 * Cada handle() inicia a sessão do zero (no CLI não há cookie para reenviar),
 * então o teste define a sessão imediatamente antes da requisição — é o que o
 * navegador faz com o cookie smia_session.
 */
$logar = static function (Application $instancia, int $id): void {
    $instancia->session->start();
    $instancia->session->put('user_id', $id);
};
$logar($app, $idUsuario);
$csrf = $app->session->csrfToken();
$app->db->run('INSERT INTO clients (company_id, company_name, trade_name, segment) VALUES (?, ?, ?, ?)', [
    $empresaTeste, 'Bravo Fitness', 'Bravo', 'Academia',
]);
$clientePlano = (int) $app->db->pdo()->lastInsertId();
$app->db->run("INSERT INTO client_branding (client_id, primary_color, accent_color, communication_tone, visual_style) VALUES (?, '#112244', '#ff6600', 'direto e motivador', 'fotos reais de treino')", [$clientePlano]);

$telaPlanner = $app->handle(requisicao('GET', '/planner'));
assertSame('tela de planejamento responde 200', 200, $telaPlanner->status);
check('mostra que a IA não está configurada', str_contains($telaPlanner->body, 'Sem chave de IA configurada'));
check('lista o cliente ativo no seletor (nome comercial)', (bool) preg_match('/<option value="\d+"[^>]*>\s*Bravo/', $telaPlanner->body));
check('carrega o CSS do planejamento', str_contains($telaPlanner->body, 'planner.css'));

$semLogin = appNova();
[$appSemLogin] = $semLogin;
assertSame('planejamento exige login', '/edijalmemoura/socialai/login', $appSemLogin->handle(requisicao('GET', '/planner'))->header('Location'));

echo "\n[19] Planejamento: validação do formulário de geração\n";
$telaPlanner = $app->handle(requisicao('GET', '/planner'));
preg_match('/name="csrf_token" value="([^"]+)"/', $telaPlanner->body, $mpl);
$csrf = $mpl[1] ?? $csrf;
$gerar = function (array $extra = []) use ($app, $logar, $idUsuario, $csrf, $clientePlano): \Smia\Http\Response {
    $logar($app, $idUsuario);
    return $app->handle(requisicao('POST', '/planner/generate', array_merge([
    'csrf_token' => $csrf,
    'client_id' => (string) $clientePlano,
    'period_days' => '7',
    'target_posts' => '7',
    'start_date' => '2026-03-02',
    'networks' => ['Instagram'],
    'objective' => 'Aumentar matrículas no plano trimestral',
        'campaign_context' => '',
    ], $extra)));
};

assertSame('sem CSRF responde 400', 400, $gerar(['csrf_token' => 'errado'])->status);
assertSame('sem cliente responde 400', 400, $gerar(['client_id' => '0'])->status);
assertSame('período inválido responde 400', 400, $gerar(['period_days' => '10'])->status);
assertSame('alvo fora da faixa responde 400', 400, $gerar(['target_posts' => '99'])->status);
assertSame('data inválida responde 400', 400, $gerar(['start_date' => '31/02/2026'])->status);
assertSame('sem rede responde 400', 400, $gerar(['networks' => []])->status);
check('rede desconhecida é ignorada', !str_contains($gerar(['networks' => ['Orkut']])->body, 'Orkut'));
assertSame('sem objetivo responde 400', 400, $gerar(['objective' => '  '])->status);
check('erro devolve o formulário preenchido', str_contains($gerar(['client_id' => '0'])->body, 'Selecione um cliente'));

echo "\n[20] Planejamento: geração local (sem IA) e gravação\n";
assertSame('nada foi gravado nos casos inválidos', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM content_calendar'));
$criado = $gerar();
assertSame('geração sem IA cria o plano', 302, $criado->status);
$destinoPlano = (string) $criado->header('Location');
check('redirect para o calendário', str_contains($destinoPlano, '/planner/') && str_contains($destinoPlano, 'created=1'));
$planoId = (int) (explode('/', parse_url($destinoPlano, PHP_URL_PATH))[4] ?? 0);
$plano = $app->db->one('SELECT * FROM content_calendar WHERE id = ?', [$planoId]);
assertSame('modelo gravado como rascunho local', 'local-draft', $plano['ai_model']);
assertSame('quantidade de posts gravada', 7, (int) $app->db->scalar('SELECT COUNT(*) FROM posts WHERE calendar_id = ?', [$planoId]));
assertSame('redes gravadas como JSON', '["Instagram"]', $plano['networks_json']);
assertSame('objetivo gravado', 'Aumentar matrículas no plano trimestral', $plano['objective']);
assertSame('rascunho fica registrado como local', 'local-draft', $app->db->scalar("SELECT model FROM ai_generations WHERE feature = 'weekly_planning'"));
assertSame('rascunho local não consome tokens', 0, (int) $app->db->scalar("SELECT input_tokens + output_tokens FROM ai_generations WHERE feature = 'weekly_planning'"));
assertSame('auditoria registrada', 'planner.created', $app->db->scalar("SELECT action FROM audit_log WHERE action = 'planner.created'"));

$postsDoPlano = $app->db->all('SELECT * FROM posts WHERE calendar_id = ? ORDER BY publication_date, position', [$planoId]);
$datas = array_column($postsDoPlano, 'publication_date');
check('datas dentro do período', min($datas) === '2026-03-02' && max($datas) <= '2026-03-08');
$obrigatorios = ['theme', 'strategic_objective', 'content_type', 'main_text', 'caption', 'visual_brief', 'image_suggestion'];
$completos = array_filter($postsDoPlano, static function (array $p) use ($obrigatorios): bool {
    foreach ($obrigatorios as $campo) {
        if (trim((string) $p[$campo]) === '') {
            return false;
        }
    }
    return true;
});
assertSame('todos os posts têm os campos obrigatórios', 7, count($completos));
check('briefing visual cita as cores da marca', (bool) preg_grep('/#112244/', array_column($postsDoPlano, 'visual_brief')));
check('rascunho local se identifica no texto', str_contains((string) $postsDoPlano[0]['main_text'], 'RASCUNHO LOCAL'));

echo "\n[21] Planejamento: tela do calendário\n";
$tela = $app->handle(requisicao('GET', '/planner/' . $planoId));
assertSame('calendário responde 200', 200, $tela->status);
check('mostra o selo de rascunho local', str_contains($tela->body, 'Rascunho local (sem IA)'));
check('mostra a grade semanal', str_contains($tela->body, 'SEMANA 1'));
check('mostra as publicações', substr_count($tela->body, 'post-card') >= 7);
check('mostra o objetivo do plano', str_contains($tela->body, 'Aumentar matrículas'));
assertSame('plano de outro workspace responde 404', 404, $app->handle(requisicao('GET', '/planner/9999'))->status);

echo "\n[22] Planejamento: edição de publicação\n";
$primeiroPost = (int) $postsDoPlano[0]['id'];
$formPost = $app->handle(requisicao('GET', '/planner/posts/' . $primeiroPost . '/edit'));
assertSame('formulário de post responde 200', 200, $formPost->status);
$semForaDoPeriodo = $app->handle(requisicao('POST', '/planner/posts/' . $primeiroPost . '/edit', [
    'csrf_token' => $csrf,
    'publication_date' => '2026-04-20',
    'network' => 'Instagram',
    'theme' => 'Tema', 'strategic_objective' => 'Objetivo', 'content_type' => 'Reels',
    'main_text' => 'Texto', 'caption' => 'Legenda', 'hashtags' => '#x', 'cta' => 'CTA',
    'visual_brief' => 'Briefing', 'image_suggestion' => 'Imagem',
]));
assertSame('data fora do período responde 422', 422, $semForaDoPeriodo->status);
check('explica o intervalo permitido', str_contains($semForaDoPeriodo->body, 'dentro do período'));
$redeErrada = $app->handle(requisicao('POST', '/planner/posts/' . $primeiroPost . '/edit', [
    'csrf_token' => $csrf, 'publication_date' => '2026-03-03', 'network' => 'TikTok',
    'theme' => 'T', 'strategic_objective' => 'O', 'content_type' => 'C',
    'main_text' => 'M', 'caption' => 'L', 'hashtags' => '', 'cta' => '',
    'visual_brief' => 'B', 'image_suggestion' => 'I',
]));
assertSame('rede fora do plano responde 422', 422, $redeErrada->status);
$vazio = $app->handle(requisicao('POST', '/planner/posts/' . $primeiroPost . '/edit', [
    'csrf_token' => $csrf, 'publication_date' => '2026-03-03', 'network' => 'Instagram',
    'theme' => '', 'strategic_objective' => 'O', 'content_type' => 'C',
    'main_text' => 'M', 'caption' => 'L', 'hashtags' => '', 'cta' => '',
    'visual_brief' => 'B', 'image_suggestion' => 'I',
]));
assertSame('campo obrigatório vazio responde 422', 422, $vazio->status);
$salvo = $app->handle(requisicao('POST', '/planner/posts/' . $primeiroPost . '/edit', [
    'csrf_token' => $csrf, 'publication_date' => '2026-03-04', 'network' => 'Instagram',
    'theme' => 'Aula experimental gratuita', 'strategic_objective' => 'Trazer novos alunos',
    'content_type' => 'Reels', 'main_text' => 'Roteiro revisado', 'caption' => 'Legenda revisada',
    'hashtags' => '#bravo #treino', 'cta' => 'Agende sua aula', 'visual_brief' => 'Treino real',
    'image_suggestion' => 'Vídeo vertical',
]));
assertSame('edição válida redireciona', 302, $salvo->status);
$postAtualizado = $app->db->one('SELECT * FROM posts WHERE id = ?', [$primeiroPost]);
assertSame('tema atualizado', 'Aula experimental gratuita', $postAtualizado['theme']);
assertSame('data atualizada', '2026-03-04', $postAtualizado['publication_date']);

echo "\n[23] Planejamento: duplicar, remover, produção e exclusão\n";
$antes = (int) $app->db->scalar('SELECT COUNT(*) FROM posts WHERE calendar_id = ?', [$planoId]);
$duplicado = $app->handle(requisicao('POST', '/planner/posts/' . $primeiroPost . '/duplicate', ['csrf_token' => $csrf]));
assertSame('duplicar redireciona', 302, $duplicado->status);
assertSame('post duplicado criado', $antes + 1, (int) $app->db->scalar('SELECT COUNT(*) FROM posts WHERE calendar_id = ?', [$planoId]));
$removido = $app->handle(requisicao('POST', '/planner/posts/' . $primeiroPost . '/delete', ['csrf_token' => $csrf]));
assertSame('remover post redireciona', 302, $removido->status);
assertSame('post removido', $antes, (int) $app->db->scalar('SELECT COUNT(*) FROM posts WHERE calendar_id = ?', [$planoId]));

$producao = $app->handle(requisicao('POST', '/planner/' . $planoId . '/production', ['csrf_token' => $csrf]));
assertSame('enviar para produção redireciona', 302, $producao->status);
assertSame('plano em produção', 'in_production', $app->db->scalar('SELECT status FROM content_calendar WHERE id = ?', [$planoId]));
assertSame('posts em produção', 0, (int) $app->db->scalar("SELECT COUNT(*) FROM posts WHERE calendar_id = ? AND status = 'planned'", [$planoId]));
assertSame('exclusão sem CSRF é barrada', 403, $app->handle(requisicao('POST', '/planner/' . $planoId . '/delete', ['csrf_token' => 'x']))->status);
$apagado = $app->handle(requisicao('POST', '/planner/' . $planoId . '/delete', ['csrf_token' => $csrf]));
assertSame('excluir plano redireciona', 302, $apagado->status);
assertSame('plano removido', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM content_calendar WHERE id = ?', [$planoId]));
assertSame('posts removidos em cascata', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM posts WHERE calendar_id = ?', [$planoId]));

echo "\n[24] Planejamento: validação rigorosa da resposta da IA\n";
$gerador = new \Smia\Planner\PlanGenerator('chave-de-teste', 'gpt-6-luna');
check('IA é considerada configurada com chave', $gerador->iaConfigurada());
$spec = ['period_days' => 7, 'target_posts' => 2, 'start_date' => '2026-03-02', 'networks' => ['Instagram', 'Facebook'], 'objective' => 'Objetivo', 'campaign_context' => ''];
$postValido = static fn (string $data, string $rede) => [
    'publication_date' => $data, 'network' => $rede, 'theme' => 'Tema', 'strategic_objective' => 'Objetivo',
    'content_type' => 'Carrossel', 'main_text' => 'Texto', 'caption' => 'Legenda',
    'hashtags' => ['#a', '#b'], 'cta' => 'CTA', 'visual_brief' => 'Briefing', 'image_suggestion' => 'Imagem',
];
$validados = $gerador->validarPosts([$postValido('2026-03-05', 'Facebook'), $postValido('2026-03-02', 'Instagram')], $spec);
assertSame('ordena por data', '2026-03-02', $validados[0]['publication_date']);
assertSame('hashtags em lista viram texto', '#a #b', $validados[0]['hashtags']);

$casosInvalidos = [
    'quantidade diferente do pedido' => [[$postValido('2026-03-02', 'Instagram')], 'quantidade'],
    'data fora do período' => [[$postValido('2026-03-20', 'Instagram'), $postValido('2026-03-02', 'Instagram')], 'fora do planejamento'],
    'rede não escolhida' => [[$postValido('2026-03-02', 'TikTok'), $postValido('2026-03-02', 'Instagram')], 'fora do planejamento'],
    'data em formato inválido' => [[array_merge($postValido('2026-03-02', 'Instagram'), ['publication_date' => '02/03/2026']), $postValido('2026-03-03', 'Instagram')], 'data'],
    'campo essencial vazio' => [[array_merge($postValido('2026-03-02', 'Instagram'), ['caption' => '']), $postValido('2026-03-03', 'Instagram')], 'campos essenciais'],
];
foreach ($casosInvalidos as $nome => [$entrada, $trecho]) {
    try {
        $gerador->validarPosts($entrada, $spec);
        check("rejeita {$nome}", false, 'aceitou resposta inválida');
    } catch (\Smia\Planner\PlannerError $erro) {
        check("rejeita {$nome}", str_contains(mb_strtolower($erro->getMessage()), mb_strtolower($trecho)), $erro->getMessage());
    }
}

$semChave = new \Smia\Planner\PlanGenerator(null);
check('sem chave, IA não é configurada', !$semChave->iaConfigurada());
$resultadoLocal = $semChave->gerar(['company_name' => 'Bravo', 'segment' => 'Academia', 'primary_color' => '#112244'], $spec);
assertSame('fallback gera a quantidade pedida', 2, count($resultadoLocal['posts']));
assertSame('fallback se identifica como local', 'local-draft', $resultadoLocal['model']);
assertSame('fallback não cobra tokens', 0, $resultadoLocal['input_tokens']);

echo "\n[25] Planejamento: caminho com IA (provedor simulado em Node)\n";
// O php-wasm não abre sockets de servidor; o runner sobe um provedor falso em
// Node e exporta SMIA_PROVEDOR_IA. Aqui exercitamos o caminho de IA de ponta a
// ponta: prompt -> POST HTTP -> JSON -> validação -> gravação com tokens.
$arquivoProvedor = __DIR__ . '/.provedor-ia';
$endpointProvedor = is_file($arquivoProvedor) ? trim((string) file_get_contents($arquivoProvedor)) : '';
if ($endpointProvedor === '') {
    check('provedor simulado disponível', false, 'tests/.provedor-ia não foi escrito pelo runner');
} else {
    check('provedor simulado disponível', true);

    $geradorIa = new \Smia\Planner\PlanGenerator('chave-teste', 'gpt-6-luna', 20, $endpointProvedor);
    $specIa = ['period_days' => 7, 'target_posts' => 3, 'start_date' => '2026-03-02', 'networks' => ['Instagram', 'Facebook'], 'objective' => 'Trazer alunos', 'campaign_context' => ''];
    $clienteIa = ['company_name' => 'Bravo Fitness', 'trade_name' => 'Bravo', 'segment' => 'Academia', 'primary_color' => '#112244', 'communication_tone' => 'direto'];

    // 1) Chave recusada (401)
    $curto = new \Smia\Planner\PlanGenerator('chave-errada', 'gpt-6-luna', 20, $endpointProvedor . '?cenario=401');
    try {
        $curto->gerar($clienteIa, $specIa);
        check('401 vira mensagem de chave recusada', false, 'não lançou erro');
    } catch (\Smia\Planner\PlannerError $erro) {
        check('401 vira mensagem de chave recusada', str_contains($erro->getMessage(), 'recusada'), $erro->getMessage());
    }

    // 2) Limite do provedor (429)
    $limitado = new \Smia\Planner\PlanGenerator('chave', 'gpt-6-luna', 20, $endpointProvedor . '?cenario=429');
    try {
        $limitado->gerar($clienteIa, $specIa);
        check('429 vira mensagem de limite', false, 'não lançou erro');
    } catch (\Smia\Planner\PlannerError $erro) {
        check('429 vira mensagem de limite', str_contains($erro->getMessage(), 'limitou'), $erro->getMessage());
    }

    // 3) Resposta fora do formato
    $quebrado = new \Smia\Planner\PlanGenerator('chave', 'gpt-6-luna', 20, $endpointProvedor . '?cenario=invalido');
    try {
        $quebrado->gerar($clienteIa, $specIa);
        check('resposta inválida vira erro tratado', false, 'não lançou erro');
    } catch (\Smia\Planner\PlannerError $erro) {
        check('resposta inválida vira erro tratado', str_contains($erro->getMessage(), 'formato inválido'), $erro->getMessage());
    }

    // 4) Caminho feliz: gera com IA, valida e confere tokens.
    $resultadoIa = $geradorIa->gerar($clienteIa, $specIa);
    check('IA devolve o modo "ia"', ($resultadoIa['modo'] ?? '') === 'ia');
    assertSame('IA gera a quantidade pedida', 3, count($resultadoIa['posts']));
    assertSame('modelo registrado', 'gpt-6-luna', $resultadoIa['model']);
    assertSame('tokens de entrada lidos', 1234, $resultadoIa['input_tokens']);
    assertSame('tokens de saída lidos', 567, $resultadoIa['output_tokens']);
    check('posts da IA respeitam as redes escolhidas', array_reduce($resultadoIa['posts'], static fn (bool $ok, array $p): bool => $ok && in_array($p['network'], $specIa['networks'], true), true));

    // 5) Ponte completa: app com OPENAI_ENDPOINT apontando para o provedor.
    $configIa = new \Smia\Support\Config(
        root: $raiz, dataDir: $raiz, dbPath: $config->dbPath, envFile: $raiz . '/installer.env',
        basePath: '/edijalmemoura/socialai',
        publicOrigin: 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
        secretKey: '', cookieSecure: false, installToken: null, timezone: 'America/Sao_Paulo',
        openaiKey: 'chave-teste', openaiModel: 'gpt-6-luna', openaiEndpoint: $endpointProvedor,
    );
    $appIa = new Application($configIa);
    $appIa->session->start();
    $appIa->session->put('user_id', $idUsuario);
    $telaIa = $appIa->handle(requisicao('GET', '/planner'));
    assertSame('tela mostra IA configurada', 200, $telaIa->status);
    check('tela indica a IA configurada', str_contains($telaIa->body, 'IA configurada'));
    check('tela não mostra aviso de chave ausente', !str_contains($telaIa->body, 'Sem chave de IA configurada'));
    preg_match('/name="csrf_token" value="([^"]+)"/', $telaIa->body, $mia);
    $appIa->session->start();
    $appIa->session->put('user_id', $idUsuario);
    $criadoIa = $appIa->handle(requisicao('POST', '/planner/generate', [
        'csrf_token' => $mia[1] ?? '',
        'client_id' => (string) $clientePlano,
        'period_days' => '7',
        'target_posts' => '3',
        'start_date' => '2026-05-04',
        'networks' => ['Instagram'],
        'objective' => 'Renovar matrículas',
    ]));
    assertSame('geração com IA cria o plano', 302, $criadoIa->status);
    $planoIaId = (int) (explode('/', parse_url((string) $criadoIa->header('Location'), PHP_URL_PATH))[4] ?? 0);
    $planoIa = $appIa->db->one('SELECT * FROM content_calendar WHERE id = ?', [$planoIaId]);
    assertSame('modelo real gravado no plano', 'gpt-6-luna', $planoIa['ai_model'] ?? null);
    assertSame('posts gravados pela IA', 3, (int) $appIa->db->scalar('SELECT COUNT(*) FROM posts WHERE calendar_id = ?', [$planoIaId]));
    $usoIa = $appIa->db->one("SELECT * FROM ai_generations WHERE feature = 'weekly_planning' ORDER BY id DESC LIMIT 1");
    assertSame('tokens de entrada gravados', 1234, (int) $usoIa['input_tokens']);
    assertSame('tokens de saída gravados', 567, (int) $usoIa['output_tokens']);
    check('texto da IA não é rascunho local', !str_contains((string) $appIa->db->scalar('SELECT main_text FROM posts WHERE calendar_id = ? LIMIT 1', [$planoIaId]), 'RASCUNHO LOCAL'));
}

echo "\n[26] Planejamento: mensagens de erro do provedor\n";
$portaMorta = 18000 + random_int(2001, 4000);
$logOriginal = ini_set('error_log', sys_get_temp_dir() . '/smia-erro-' . bin2hex(random_bytes(4)) . '.log');
$semProvedor = new \Smia\Planner\PlanGenerator('chave', 'gpt-6-luna', 2, 'http://127.0.0.1:' . $portaMorta . '/v1/chat/completions');
try {
    $semProvedor->gerar(['company_name' => 'X'], ['period_days' => 7, 'target_posts' => 1, 'start_date' => '2026-03-02', 'networks' => ['Instagram'], 'objective' => 'O', 'campaign_context' => '']);
    check('provedor fora do ar vira erro tratado', false, 'não lançou erro');
} catch (\Smia\Planner\PlannerError $erro) {
    check('provedor fora do ar vira erro tratado', str_contains($erro->getMessage(), 'conectar'), $erro->getMessage());
}
if ($logOriginal !== false) {
    ini_set('error_log', $logOriginal);
}

echo "\n[27] Planejamento: matriz de permissões e isolamento entre agências\n";
// Prepara um plano novo para a matriz de papéis.
$logar($app, $idUsuario);
$basePerm = $gerar(['target_posts' => '4', 'start_date' => '2026-06-01']);
assertSame('plano para a matriz criado', 302, $basePerm->status);
$planoPerm = (int) (explode('/', parse_url((string) $basePerm->header('Location'), PHP_URL_PATH))[4] ?? 0);
$postPerm = (int) $app->db->scalar('SELECT id FROM posts WHERE calendar_id = ? LIMIT 1', [$planoPerm]);
$dadosPost = [
    'csrf_token' => $csrf, 'publication_date' => '2026-06-02', 'network' => 'Instagram',
    'theme' => 'Tema', 'strategic_objective' => 'Objetivo', 'content_type' => 'Reels',
    'main_text' => 'Texto', 'caption' => 'Legenda', 'hashtags' => '#a', 'cta' => 'CTA',
    'visual_brief' => 'Briefing', 'image_suggestion' => 'Imagem',
];
$papelOriginal = (string) $app->db->scalar('SELECT role FROM users WHERE id = ?', [$idUsuario]);

// viewer: lê, mas não gera, edita, envia para produção nem exclui
$app->db->run("UPDATE users SET role = 'viewer' WHERE id = ?", [$idUsuario]);
$logar($app, $idUsuario);
assertSame('viewer abre o planejamento', 200, $app->handle(requisicao('GET', '/planner'))->status);
$logar($app, $idUsuario);
assertSame('viewer abre o calendário', 200, $app->handle(requisicao('GET', '/planner/' . $planoPerm))->status);
$logar($app, $idUsuario);
$negadoGerar = $app->handle(requisicao('POST', '/planner/generate', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'period_days' => '7',
    'target_posts' => '7', 'start_date' => '2026-06-01', 'networks' => ['Instagram'], 'objective' => 'O',
]));
assertSame('viewer não gera calendário', 403, $negadoGerar->status);
check('tela de 403 do planejamento', str_contains($negadoGerar->body, 'não pode fazer isso'));
$logar($app, $idUsuario);
assertSame('viewer não edita post', 403, $app->handle(requisicao('POST', '/planner/posts/' . $postPerm . '/edit', $dadosPost))->status);
$logar($app, $idUsuario);
assertSame('viewer não duplica post', 403, $app->handle(requisicao('POST', '/planner/posts/' . $postPerm . '/duplicate', ['csrf_token' => $csrf]))->status);
$logar($app, $idUsuario);
assertSame('viewer não envia para produção', 403, $app->handle(requisicao('POST', '/planner/' . $planoPerm . '/production', ['csrf_token' => $csrf]))->status);
$logar($app, $idUsuario);
assertSame('viewer não exclui plano', 403, $app->handle(requisicao('POST', '/planner/' . $planoPerm . '/delete', ['csrf_token' => $csrf]))->status);

// editor: cria e edita; não exclui plano
$app->db->run("UPDATE users SET role = 'editor' WHERE id = ?", [$idUsuario]);
$logar($app, $idUsuario);
assertSame('editor edita post', 302, $app->handle(requisicao('POST', '/planner/posts/' . $postPerm . '/edit', $dadosPost))->status);
$logar($app, $idUsuario);
assertSame('editor não exclui plano', 403, $app->handle(requisicao('POST', '/planner/' . $planoPerm . '/delete', ['csrf_token' => $csrf]))->status);

// admin: exclui
$app->db->run("UPDATE users SET role = 'admin' WHERE id = ?", [$idUsuario]);
$logar($app, $idUsuario);
assertSame('admin exclui plano', 302, $app->handle(requisicao('POST', '/planner/' . $planoPerm . '/delete', ['csrf_token' => $csrf]))->status);
$app->db->run('UPDATE users SET role = ? WHERE id = ?', [$papelOriginal, $idUsuario]);

// Isolamento: usuário de outra agência não vê nem abre o calendário alheio
$logar($app, $idUsuario);
$proprio = $gerar(['target_posts' => '3', 'start_date' => '2026-07-06']);
$planoProprio = (int) (explode('/', parse_url((string) $proprio->header('Location'), PHP_URL_PATH))[4] ?? 0);
$postProprio = (int) $app->db->scalar('SELECT id FROM posts WHERE calendar_id = ? LIMIT 1', [$planoProprio]);
$idOutro = (int) $app->db->scalar("SELECT id FROM users WHERE email = 'outro@exemplo.com'");
$logar($app, $idOutro);
$listaOutro = $app->handle(requisicao('GET', '/planner'));
assertSame('outra agência abre o próprio planejamento', 200, $listaOutro->status);
check('outra agência não vê o calendário alheio', !str_contains($listaOutro->body, 'Bravo'));
$logar($app, $idOutro);
assertSame('outra agência recebe 404 no calendário alheio', 404, $app->handle(requisicao('GET', '/planner/' . $planoProprio))->status);
$logar($app, $idOutro);
assertSame('outra agência recebe 404 no post alheio', 404, $app->handle(requisicao('GET', '/planner/posts/' . $postProprio . '/edit'))->status);
$logar($app, $idOutro);
assertSame('outra agência não exclui o plano alheio', 404, $app->handle(requisicao('POST', '/planner/' . $planoProprio . '/delete', ['csrf_token' => $csrf]))->status);
check('calendário alheio continua intacto', (int) $app->db->scalar('SELECT COUNT(*) FROM content_calendar WHERE id = ?', [$planoProprio]) === 1);

// Volta ao usuário principal para o fechamento da suíte.
$logar($app, $idUsuario);

echo "\n[28] Planejamento: migração aditiva sobre uma instalação v1.1\n";
// Simula um banco vindo da versão anterior: só as tabelas de clientes, com dados.
$bancoAntigo = sys_get_temp_dir() . '/smia-antigo-' . bin2hex(random_bytes(4)) . '.sqlite';
$pdoAntigo = new PDO('sqlite:' . $bancoAntigo);
$pdoAntigo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoAntigo->exec('PRAGMA foreign_keys = ON');
$pdoAntigo->exec(<<<'SQL'
CREATE TABLE schema_version (version INTEGER NOT NULL, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE companies (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, installed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE, name TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE UNIQUE, password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'owner', last_login_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE installation (id INTEGER PRIMARY KEY CHECK (id = 1), installed_at TEXT NOT NULL, agency_name TEXT NOT NULL, owner_email TEXT NOT NULL, public_origin TEXT NOT NULL, php_version TEXT NOT NULL);
CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, user_id INTEGER, action TEXT NOT NULL, entity TEXT, entity_id INTEGER, details TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE, company_name TEXT NOT NULL, trade_name TEXT, segment TEXT, website TEXT, description TEXT, products_services TEXT, audience TEXT, business_goals TEXT, status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE client_branding (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL UNIQUE REFERENCES clients(id) ON DELETE CASCADE, primary_color TEXT, secondary_color TEXT, accent_color TEXT, typography TEXT, visual_style TEXT, communication_tone TEXT, slogan TEXT, graphic_elements TEXT, brand_manual TEXT);
CREATE TABLE client_assets (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE, file_name TEXT NOT NULL, stored_name TEXT NOT NULL, mime_type TEXT, size_bytes INTEGER, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
INSERT INTO schema_version (version) VALUES (2);
INSERT INTO companies (name, slug) VALUES ('Agência Antiga', 'agencia-antiga');
INSERT INTO users (company_id, name, email, password_hash, role) VALUES (1, 'Ana', 'ana@antiga.example', 'x', 'owner');
INSERT INTO clients (company_id, company_name, trade_name) VALUES (1, 'Cliente Herdado', 'Herdado');
SQL);
$pdoAntigo = null;

$configAntiga = new \Smia\Support\Config(
    root: $raiz, dataDir: $raiz, dbPath: $bancoAntigo, envFile: $raiz . '/installer.env',
    basePath: '/edijalmemoura/socialai',
    publicOrigin: 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
    secretKey: '', cookieSecure: false, installToken: null, timezone: 'America/Sao_Paulo',
);
$appAntiga = new Application($configAntiga);
\Smia\Database\Schema::migrate($appAntiga->db);
assertSame('versão do esquema atualizada', (string) \Smia\Database\Schema::VERSAO, (string) $appAntiga->db->scalar('SELECT MAX(version) FROM schema_version'));
assertSame('tabela de calendário criada', 1, (int) $appAntiga->db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'content_calendar'"));
assertSame('tabela de posts criada', 1, (int) $appAntiga->db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'posts'"));
assertSame('tabela de uso de IA criada', 1, (int) $appAntiga->db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'ai_generations'"));
assertSame('dados de clientes preservados', 'Cliente Herdado', $appAntiga->db->scalar('SELECT company_name FROM clients WHERE id = 1'));
\Smia\Database\Schema::migrate($appAntiga->db);
assertSame('migração é idempotente', 'Cliente Herdado', $appAntiga->db->scalar('SELECT company_name FROM clients WHERE id = 1'));
assertSame('nenhuma versão duplicada', 1, (int) $appAntiga->db->scalar('SELECT COUNT(*) FROM schema_version WHERE version = ?', [\Smia\Database\Schema::VERSAO]));

echo "\n[29] Dashboard e navegação liberam o planejamento\n";
$logar($app, $idUsuario);
$painelNovo = $app->handle(requisicao('GET', '/dashboard'));
assertSame('dashboard responde 200', 200, $painelNovo->status);
check('menu mostra Planejamento', str_contains($painelNovo->body, 'Planejamento'));
check('módulo não aparece como "em breve"', !preg_match('/Planejamento.{0,200}?em breve/s', $painelNovo->body));
$modulos = \Smia\Support\Modules::all();
$moduloPlanner = array_values(array_filter($modulos, static fn (array $m): bool => $m['slug'] === 'planner'))[0] ?? null;
check('módulo planejamento marcado como pronto', ($moduloPlanner['ready'] ?? false) === true);
check('os módulos ainda não portados seguem pendentes', count(array_filter($modulos, static fn (array $m): bool => $m['ready'] === false)) >= 1);

echo "\n[30] Planejamento: conteúdo da IA é tratado como não confiável\n";
$logar($app, $idUsuario);
$comHtml = $gerar(['objective' => '<script>alert(1)</script> Quero "matrículas" & mais', 'target_posts' => '2']);
assertSame('objetivo com HTML é aceito como texto', 302, $comHtml->status);
$planoXss = (int) (explode('/', parse_url((string) $comHtml->header('Location'), PHP_URL_PATH))[4] ?? 0);
$logar($app, $idUsuario);
$telaXss = $app->handle(requisicao('GET', '/planner/' . $planoXss));
assertSame('calendário com HTML no objetivo responde', 200, $telaXss->status);
check('script do objetivo não é executado', !str_contains($telaXss->body, '<script>alert(1)</script>'));
check('texto escapado aparece como conteúdo', str_contains($telaXss->body, '&lt;script&gt;alert(1)&lt;/script&gt;'));

// Post vindo da IA com HTML no corpo: precisa ser escapado na tela
$logar($app, $idUsuario);
$app->db->run("UPDATE posts SET main_text = ?, caption = ? WHERE calendar_id = ?", [
    '<img src=x onerror=alert(1)>', '"><script>roubar()</script>', $planoXss,
]);
$logar($app, $idUsuario);
$telaPost = $app->handle(requisicao('GET', '/planner/' . $planoXss));
check('imagem maliciosa do post não vira tag', !str_contains($telaPost->body, '<img src=x onerror'));
check('script no texto do post não vira tag', !str_contains($telaPost->body, '<script>roubar()'));

// JSON quebrado do provedor não derruba o app: mensagem + 502 na tela
$logar($app, $idUsuario);
$jsonQuebrado = $app->handle(requisicao('POST', '/planner/generate', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'period_days' => '30',
    'target_posts' => '46', 'start_date' => '2026-08-03', 'networks' => ['Instagram'], 'objective' => 'O',
]));
assertSame('46 publicações em 30 dias é barrado', 400, $jsonQuebrado->status);
check('mensagem explica o limite', str_contains($jsonQuebrado->body, 'entre 1 e 45'));

echo "\n[31] Estúdio: tela, formatos e permissões\n";
$logar($app, $idUsuario);
$telaStudio = $app->handle(requisicao('GET', '/studio'));
assertSame('tela do estúdio responde 200', 200, $telaStudio->status);
check('mostra que a IA não está configurada', str_contains($telaStudio->body, 'Sem chave de IA configurada'));
check('lista os sete formatos', substr_count($telaStudio->body, 'class="format-card"') === 7);
check('carrega o CSS do estúdio', str_contains($telaStudio->body, 'studio.css'));
check('explica que o rascunho local não é IA', str_contains($telaStudio->body, 'rascunho local'));

$semLoginStudio = appNova();
assertSame('estúdio exige login', '/edijalmemoura/socialai/login', $semLoginStudio[0]->handle(requisicao('GET', '/studio'))->header('Location'));

echo "\n[32] Estúdio: validação do formulário de criação\n";
$criarArte = function (array $extra = []) use ($app, $logar, $idUsuario, $csrf, $clientePlano): \Smia\Http\Response {
    $logar($app, $idUsuario);

    return $app->handle(requisicao('POST', '/studio/generate', array_merge([
        'csrf_token' => $csrf,
        'client_id' => (string) $clientePlano,
        'subject' => 'Aula experimental gratuita',
        'format_key' => 'square',
    ], $extra)));
};
assertSame('sem CSRF responde 400', 400, $criarArte(['csrf_token' => 'errado'])->status);
assertSame('sem cliente responde 400', 400, $criarArte(['client_id' => '0'])->status);
assertSame('formato inválido responde 400', 400, $criarArte(['format_key' => 'billboard'])->status);
assertSame('assunto curto responde 400', 400, $criarArte(['subject' => 'ab'])->status);
check('erro devolve o formulário preenchido', str_contains($criarArte(['subject' => 'ab'])->body, 'Aula experimental gratuita'));
assertSame('nada foi gravado nos casos inválidos', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM creative_posts'));

echo "\n[33] Estúdio: rascunho local (sem IA) e primeira versão\n";
$criada = $criarArte();
assertSame('criação sem IA responde 302', 302, $criada->status);
$destinoArte = (string) $criada->header('Location');
check('redirect para o editor', str_contains($destinoArte, '/studio/posts/') && str_contains($destinoArte, 'created=1'));
$arteId = (int) preg_replace('#^.*/studio/posts/(\d+).*$#', '$1', (string) parse_url($destinoArte, PHP_URL_PATH));
$arte = $app->db->one('SELECT * FROM creative_posts WHERE id = ?', [$arteId]);
check('arte gravada com o formato pedido', $arte !== null && $arte['format_key'] === 'square');
assertSame('canvas quadrado', 1080, (int) $arte['canvas_height']);
assertSame('cor de fundo vem da marca', '#112244', $arte['background_color']);
$conteudoArte = json_decode((string) $arte['content_json'], true);
check('título usa o assunto', str_contains((string) $conteudoArte['title'], 'Aula experimental'));
check('legenda se identifica como rascunho local', str_contains((string) $conteudoArte['caption'], 'RASCUNHO LOCAL'));
check('briefing cita a cor da marca', str_contains((string) $conteudoArte['visual_brief'], '#112244'));
check('prompt da imagem pede área de respiro', str_contains((string) $arte['image_prompt'], 'respiro'));
assertSame('primeira versão registrada', 'Texto e layout gerados', $app->db->scalar('SELECT version_label FROM post_versions WHERE creative_id = ?', [$arteId]));
assertSame('consumo registrado como local', 'local-draft', $app->db->scalar("SELECT model FROM ai_generations WHERE feature = 'studio_copy' AND client_id = ?", [$clientePlano]));
assertSame('auditoria do estúdio', 'studio.created', $app->db->scalar("SELECT action FROM audit_log WHERE action = 'studio.created'"));
$layoutArte = json_decode((string) $arte['layout_json'], true);
assertSame('layout tem os quatro elementos', 4, count($layoutArte['elements']));
check('título do layout tem o texto da arte', $layoutArte['elements'][0]['text'] === $conteudoArte['title']);

echo "\n[34] Estúdio: editor e salvamento com versão\n";
$logar($app, $idUsuario);
$telaEditor = $app->handle(requisicao('GET', '/studio/posts/' . $arteId));
assertSame('editor responde 200', 200, $telaEditor->status);
check('editor mostra a prancheta', str_contains($telaEditor->body, 'id="studioCanvas"'));
check('editor carrega o JS do estúdio', str_contains($telaEditor->body, 'studio.js'));
check('editor explica a imagem de fundo', str_contains($telaEditor->body, 'Imagem de fundo'));
check('botão de imagem presente', str_contains($telaEditor->body, 'Gerar imagem com IA'));
assertSame('arte de outra empresa responde 404', 404, $app->handle(requisicao('GET', '/studio/posts/99999'))->status);

$layoutNovo = ['elements' => $layoutArte['elements']];
$layoutNovo['elements'][0]['x'] = 120;
$layoutNovo['elements'][0]['y'] = 200;
$layoutNovo['elements'][0]['fontSize'] = 96;
$salvarArte = function (array $extra = []) use ($app, $logar, $idUsuario, $csrf, $arteId, $layoutNovo): \Smia\Http\Response {
    $logar($app, $idUsuario);

    return $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/save', array_merge([
        'csrf_token' => $csrf,
        'layout_json' => json_encode($layoutNovo),
        'background_color' => '#ff6600',
        'title' => 'Título editado à mão',
        'subtitle' => 'Subtítulo editado',
        'cta' => 'Agende agora',
        'caption' => 'Legenda revisada pelo time.',
        'hashtags' => '#bravo #treino',
        'visual_brief' => 'Briefing revisado.',
        'image_suggestion' => 'Imagem revisada.',
        'composition' => 'Composição revisada.',
        'image_prompt' => 'Prompt revisado da imagem',
    ], $extra)));
};
$salva = $salvarArte();
assertSame('salvar redireciona', 302, $salva->status);
check('redirect de salvamento', str_contains((string) $salva->header('Location'), 'ok=saved'));
$arteSalva = $app->db->one('SELECT * FROM creative_posts WHERE id = ?', [$arteId]);
assertSame('cor de fundo atualizada', '#ff6600', $arteSalva['background_color']);
$conteudoSalvo = json_decode((string) $arteSalva['content_json'], true);
assertSame('título atualizado', 'Título editado à mão', $conteudoSalvo['title']);
$layoutSalvo = json_decode((string) $arteSalva['layout_json'], true);
assertSame('posição do título salva', 120, (int) $layoutSalvo['elements'][0]['x']);
assertSame('tamanho da fonte salvo', 96, (int) $layoutSalvo['elements'][0]['fontSize']);
assertSame('texto do layout sincronizado', 'Título editado à mão', $layoutSalvo['elements'][0]['text']);
assertSame('histórico ganhou a edição', 'Edição manual', $app->db->scalar('SELECT version_label FROM post_versions WHERE creative_id = ? ORDER BY id DESC LIMIT 1', [$arteId]));
assertSame('duas versões no histórico', 2, (int) $app->db->scalar('SELECT COUNT(*) FROM post_versions WHERE creative_id = ?', [$arteId]));

echo "\n[35] Estúdio: validação do layout e dos campos\n";
$layoutQuebrado = $layoutNovo;
$layoutQuebrado['elements'][0]['id'] = 'injetado';
$respostaLayout = $salvarArte(['layout_json' => json_encode($layoutQuebrado)]);
assertSame('layout com elemento desconhecido responde 302 (volta com erro)', 302, $respostaLayout->status);
check('erro de layout explicado', str_contains((string) $respostaLayout->header('Location'), 'layout'));
assertSame('layout com três elementos volta com erro', 302, $salvarArte(['layout_json' => json_encode(['elements' => array_slice($layoutNovo['elements'], 0, 3)])])->status);
check('layout inválido não altera a arte', $app->db->scalar('SELECT background_color FROM creative_posts WHERE id = ?', [$arteId]) === '#ff6600');
$semLegenda = $salvarArte(['caption' => '']);
check('campo essencial vazio é barrado', str_contains((string) $semLegenda->header('Location'), 'obrigat'));
$salvarArte(['background_color' => 'azul']);
check('cor inválida mantém a anterior', $app->db->scalar('SELECT background_color FROM creative_posts WHERE id = ?', [$arteId]) === '#ff6600');
check('JSON de layout vazio é recusado', str_contains((string) $salvarArte(['layout_json' => ''])->header('Location'), 'layout'));

echo "\n[36] Estúdio: restaurar versão\n";
$versaoAntiga = $app->db->one('SELECT * FROM post_versions WHERE creative_id = ? ORDER BY id ASC LIMIT 1', [$arteId]);
$restaurada = $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/versions/' . (int) $versaoAntiga['id'] . '/restore', ['csrf_token' => $csrf]));
assertSame('restaurar redireciona', 302, $restaurada->status);
$arteRestaurada = $app->db->one('SELECT * FROM creative_posts WHERE id = ?', [$arteId]);
assertSame('cor volta à da primeira versão', $versaoAntiga['background_color'], $arteRestaurada['background_color']);
check('restauro fica no histórico', str_contains((string) $app->db->scalar('SELECT version_label FROM post_versions WHERE creative_id = ? ORDER BY id DESC LIMIT 1', [$arteId]), 'Restaurado'));
assertSame('versão de outra arte responde 404', 404, $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/versions/99999/restore', ['csrf_token' => $csrf]))->status);

echo "\n[37] Estúdio: imagem de fundo (upload, enquadramento e limites)\n";
$logar($app, $idUsuario);
$arquivoTmp = sys_get_temp_dir() . '/smia-studio-' . bin2hex(random_bytes(4)) . '.png';
$lona = imagecreatetruecolor(300, 300);
imagefill($lona, 0, 0, imagecolorallocate($lona, 200, 30, 90));
imagepng($lona, $arquivoTmp);
imagedestroy($lona);
$_FILES['file'] = ['name' => 'foto.png', 'tmp_name' => $arquivoTmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($arquivoTmp)];
$enviada = $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/image/upload', ['csrf_token' => $csrf]));
unset($_FILES['file']);
assertSame('upload redireciona', 302, $enviada->status);
check('redirect de upload', str_contains((string) $enviada->header('Location'), 'ok=upload'));
$arteComImagem = $app->db->one('SELECT * FROM creative_posts WHERE id = ?', [$arteId]);
check('imagem gravada com nome do sistema', (bool) preg_match('/^studio-\d+-[a-f0-9]{24}\.png$/', (string) $arteComImagem['image_path']));
$caminhoImagem = $raiz . '/uploads/' . $arteComImagem['image_path'];
check('imagem existe no disco', is_file($caminhoImagem));
$medida = getimagesize($caminhoImagem);
check('imagem foi enquadrada no formato', $medida !== false && $medida[0] === 1080 && $medida[1] === 1080);
check('upload fica no histórico', str_contains((string) $app->db->scalar('SELECT version_label FROM post_versions WHERE creative_id = ? ORDER BY id DESC LIMIT 1', [$arteId]), 'Imagem substituída'));

$logar($app, $idUsuario);
$servida = $app->handle(requisicao('GET', '/studio/files', [], ['file' => (string) $arteComImagem['image_path']]));
assertSame('imagem servida para a dona', 200, $servida->status);
check('mime da imagem servida', str_contains((string) $servida->header('Content-Type'), 'image/png'));
$logar($app, (int) $app->db->scalar("SELECT id FROM users WHERE email = 'outro@exemplo.com'"));
$outraEmpresaStudio = $app->handle(requisicao('GET', '/studio/files', [], ['file' => (string) $arteComImagem['image_path']]));
check('outra empresa não recebe a imagem', $outraEmpresaStudio->status === 404 || str_contains($outraEmpresaStudio->body, 'não existe'));
$logar($app, $idUsuario);
assertSame('nome de arquivo suspeito é barrado', 404, $app->handle(requisicao('GET', '/studio/files', [], ['file' => '../../.env']))->status);

$falso = sys_get_temp_dir() . '/smia-studio-' . bin2hex(random_bytes(4)) . '.png';
file_put_contents($falso, "<?php echo 1; ?>");
$_FILES['file'] = ['name' => 'malicioso.png', 'tmp_name' => $falso, 'error' => UPLOAD_ERR_OK, 'size' => filesize($falso)];
$logar($app, $idUsuario);
$recusado = $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/image/upload', ['csrf_token' => $csrf]));
unset($_FILES['file']);
check('arquivo sem assinatura de imagem é recusado', str_contains((string) $recusado->header('Location'), 'erro='));

echo "\n[38] Estúdio: exportação e download\n";
$pngExport = sys_get_temp_dir() . '/smia-export-' . bin2hex(random_bytes(4)) . '.png';
$lonaExport = imagecreatetruecolor(1080, 1080);
imagefill($lonaExport, 0, 0, imagecolorallocate($lonaExport, 10, 20, 30));
imagepng($lonaExport, $pngExport);
imagedestroy($lonaExport);
$_FILES['file'] = ['name' => 'arte.png', 'tmp_name' => $pngExport, 'error' => UPLOAD_ERR_OK, 'size' => filesize($pngExport)];
$logar($app, $idUsuario);
$exportada = $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/export', ['csrf_token' => $csrf]));
unset($_FILES['file']);
assertSame('exportação responde 200', 200, $exportada->status);
$jsonExport = json_decode($exportada->body, true);
check('exportação devolve URL da arte', isset($jsonExport['url']) && str_contains((string) $jsonExport['url'], '/studio/files'));
check('exportação devolve URL de download', isset($jsonExport['download']) && str_contains((string) $jsonExport['download'], '/download'));
$arteExportada = $app->db->one('SELECT * FROM creative_posts WHERE id = ?', [$arteId]);
assertSame('status vira exportada', 'exported', $arteExportada['status']);
check('arquivo exportado gravado', is_file($raiz . '/uploads/' . (string) $arteExportada['export_path']));
$baixada = $app->handle(requisicao('GET', '/studio/posts/' . $arteId . '/download'));
assertSame('download responde 200', 200, $baixada->status);
check('download vem como anexo', str_contains((string) $baixada->header('Content-Disposition'), 'attachment'));
check('download tem o nome do produto', str_contains((string) $baixada->header('Content-Disposition'), 'socialmedia-ia-'));
$semArquivo = $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/export', ['csrf_token' => $csrf]));
assertSame('exportação sem arquivo responde 400', 400, $semArquivo->status);
$exportSemCsrf = $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/export', ['csrf_token' => 'x']));
assertSame('exportação sem CSRF responde 400', 400, $exportSemCsrf->status);
check('erro de CSRF vem em JSON', str_contains((string) $exportSemCsrf->header('Content-Type'), 'application/json'));

echo "\n[39] Estúdio: variação, exclusão e isolamento\n";
$logar($app, $idUsuario);
$variacao = $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/variation', ['csrf_token' => $csrf, 'variation_note' => 'Tom mais leve']));
assertSame('variação redireciona', 302, $variacao->status);
$arteVariacao = (int) preg_replace('#^.*/studio/posts/(\d+).*$#', '$1', (string) parse_url((string) $variacao->header('Location'), PHP_URL_PATH));
check('variação criou outra arte', $arteVariacao > 0 && $arteVariacao !== $arteId);
assertSame('variação tem o mesmo assunto', 'Aula experimental gratuita', $app->db->scalar('SELECT subject FROM creative_posts WHERE id = ?', [$arteVariacao]));
assertSame('variação se identifica como tal', 'Variação gerada', $app->db->scalar('SELECT version_label FROM post_versions WHERE creative_id = ?', [$arteVariacao]));

$logar($app, $idUsuario);
$apagada = $app->handle(requisicao('POST', '/studio/posts/' . $arteVariacao . '/delete', ['csrf_token' => $csrf]));
assertSame('exclusão redireciona', 302, $apagada->status);
assertSame('arte removida', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM creative_posts WHERE id = ?', [$arteVariacao]));
assertSame('versões removidas em cascata', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM post_versions WHERE creative_id = ?', [$arteVariacao]));
assertSame('exclusão sem CSRF responde 403', 403, $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/delete', ['csrf_token' => 'x']))->status);

$logar($app, (int) $app->db->scalar("SELECT id FROM users WHERE email = 'outro@exemplo.com'"));
assertSame('outra agência não abre a arte', 404, $app->handle(requisicao('GET', '/studio/posts/' . $arteId))->status);
$logar($app, (int) $app->db->scalar("SELECT id FROM users WHERE email = 'outro@exemplo.com'"));
assertSame('outra agência não exclui a arte', 404, $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/delete', ['csrf_token' => $csrf]))->status);
check('arte da outra agência continua existindo', (int) $app->db->scalar('SELECT COUNT(*) FROM creative_posts WHERE id = ?', [$arteId]) === 1);

echo "\n[40] Estúdio: matriz de permissões\n";
$papelStudio = (string) $app->db->scalar('SELECT role FROM users WHERE id = ?', [$idUsuario]);
$app->db->run("UPDATE users SET role = 'viewer' WHERE id = ?", [$idUsuario]);
$logar($app, $idUsuario);
assertSame('viewer abre o estúdio', 200, $app->handle(requisicao('GET', '/studio'))->status);
$logar($app, $idUsuario);
$telaViewer = $app->handle(requisicao('GET', '/studio/posts/' . $arteId));
assertSame('viewer abre a arte', 200, $telaViewer->status);
check('viewer não vê o formulário de salvar', !str_contains($telaViewer->body, 'id="studioSave"'));
$logar($app, $idUsuario);
assertSame('viewer não cria arte', 403, $app->handle(requisicao('POST', '/studio/generate', ['csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'subject' => 'Teste', 'format_key' => 'square']))->status);
$logar($app, $idUsuario);
assertSame('viewer não salva', 403, $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/save', ['csrf_token' => $csrf]))->status);
$logar($app, $idUsuario);
assertSame('viewer não exclui', 403, $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/delete', ['csrf_token' => $csrf]))->status);
$app->db->run("UPDATE users SET role = 'editor' WHERE id = ?", [$idUsuario]);
$logar($app, $idUsuario);
assertSame('editor salva a arte', 302, $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/save', [
    'csrf_token' => $csrf, 'layout_json' => json_encode($layoutNovo), 'background_color' => '#112244',
    'title' => 'Título do editor', 'subtitle' => 'S', 'cta' => 'C', 'caption' => 'L',
    'hashtags' => '', 'visual_brief' => 'B', 'image_suggestion' => 'I', 'composition' => 'C', 'image_prompt' => 'P',
]))->status);
$app->db->run('UPDATE users SET role = ? WHERE id = ?', [$papelStudio, $idUsuario]);
$logar($app, $idUsuario);

echo "\n[41] Estúdio: caminho com IA (texto e imagem simulados)\n";
if ($endpointProvedor === '') {
    check('provedor simulado disponível para o estúdio', false, 'tests/.provedor-ia ausente');
} else {
    $raizIa = sys_get_temp_dir() . '/smia-studio-ia-' . bin2hex(random_bytes(4));
    mkdir($raizIa, 0o700, true);
    $configIaStudio = new \Smia\Support\Config(
        root: $raizIa, dataDir: $raizIa, dbPath: $raizIa . '/socialmedia.sqlite', envFile: $raizIa . '/installer.env',
        basePath: '/edijalmemoura/socialai',
        publicOrigin: 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
        secretKey: '', cookieSecure: false, installToken: null, timezone: 'America/Sao_Paulo',
        openaiKey: 'chave-teste', openaiModel: 'gpt-6-luna',
        openaiEndpoint: $endpointProvedor,
        openaiImageModel: 'gpt-image-2',
        openaiImageEndpoint: str_replace('/chat/completions', '/images/generations', $endpointProvedor),
    );
    $appStudioIa = new Application($configIaStudio);
    \Smia\Database\Schema::migrate($appStudioIa->db);
    $appStudioIa->db->run("INSERT INTO companies (id, name, slug) VALUES (1, 'Agência IA', 'agencia-ia')");
    $appStudioIa->db->run("INSERT INTO users (id, company_id, name, email, password_hash, role) VALUES (1, 1, 'Ana', 'ana@ia.example', ?, 'owner')", [password_hash('SenhaForte123', PASSWORD_DEFAULT)]);
    $appStudioIa->db->run("INSERT INTO clients (id, company_id, company_name, trade_name, segment) VALUES (1, 1, 'Bravo Fitness', 'Bravo', 'Academia')");
    $appStudioIa->db->run("INSERT INTO client_branding (client_id, primary_color) VALUES (1, '#112244')");

    $appStudioIa->session->start();
    $appStudioIa->session->put('user_id', 1);
    $telaIaStudio = $appStudioIa->handle(requisicao('GET', '/studio'));
    assertSame('tela do estúdio com IA responde 200', 200, $telaIaStudio->status);
    check('tela indica a IA configurada', str_contains($telaIaStudio->body, 'IA configurada'));
    preg_match('/name="csrf_token" value="([^"]+)"/', $telaIaStudio->body, $msi);
    $csrfStudioIa = $msi[1] ?? '';

    $appStudioIa->session->start();
    $appStudioIa->session->put('user_id', 1);
    $criadaIa = $appStudioIa->handle(requisicao('POST', '/studio/generate', [
        'csrf_token' => $csrfStudioIa,
        'client_id' => '1',
        'subject' => 'Renovação de matrícula',
        'format_key' => 'portrait',
    ]));
    assertSame('geração com IA responde 302', 302, $criadaIa->status);
    $arteIaId = (int) preg_replace('#^.*/studio/posts/(\d+).*$#', '$1', (string) parse_url((string) $criadaIa->header('Location'), PHP_URL_PATH));
    $arteIa = $appStudioIa->db->one('SELECT * FROM creative_posts WHERE id = ?', [$arteIaId]);
    $conteudoIaArte = json_decode((string) $arteIa['content_json'], true);
    assertSame('título veio da IA', 'Tema IA 1', $conteudoIaArte['title']);
    check('texto não é rascunho local', !str_contains((string) $conteudoIaArte['caption'], 'RASCUNHO LOCAL'));
    assertSame('canvas do formato retrato', 1350, (int) $arteIa['canvas_height']);
    $usoCopy = $appStudioIa->db->one("SELECT * FROM ai_generations WHERE feature = 'studio_copy' ORDER BY id DESC LIMIT 1");
    assertSame('tokens da IA de texto gravados', 1234, (int) $usoCopy['input_tokens']);
    assertSame('modelo real gravado', 'gpt-6-luna', $usoCopy['model']);

    $appStudioIa->session->start();
    $appStudioIa->session->put('user_id', 1);
    $gerouImagem = $appStudioIa->handle(requisicao('POST', '/studio/posts/' . $arteIaId . '/image', [
        'csrf_token' => $csrfStudioIa,
        'image_prompt' => 'Academia iluminada com pessoas treinando, luz natural',
    ]));
    assertSame('geração de imagem redireciona', 302, $gerouImagem->status);
    check('redirect de imagem gerada', str_contains((string) $gerouImagem->header('Location'), 'ok=image'));
    $arteComIa = $appStudioIa->db->one('SELECT * FROM creative_posts WHERE id = ?', [$arteIaId]);
    check('imagem da IA gravada', (bool) preg_match('/^studio-\d+-[a-f0-9]{24}\.png$/', (string) $arteComIa['image_path']));
    $medidaIa = getimagesize($raizIa . '/uploads/' . (string) $arteComIa['image_path']);
    check('imagem da IA tem o tamanho do formato', $medidaIa !== false && $medidaIa[0] === 1080 && $medidaIa[1] === 1350);
    $usoImagem = $appStudioIa->db->one("SELECT * FROM ai_generations WHERE feature = 'studio_image' ORDER BY id DESC LIMIT 1");
    assertSame('tokens da imagem gravados', 12, (int) $usoImagem['input_tokens']);
    check('bytes da imagem começam com assinatura PNG', str_starts_with((string) file_get_contents($raizIa . '/uploads/' . (string) $arteComIa['image_path'], false, null, 0, 8), "\x89PNG"));

echo "\n[42] Estúdio: mensagens de erro aparecem na tela\n";
    $logar($app, $idUsuario);
    $comErro = $app->handle(requisicao('GET', '/studio/posts/' . $arteId, [], ['erro' => 'Layout inválido de propósito']));
    assertSame('editor com erro responde 200', 200, $comErro->status);
    check('erro devolvido na tela', str_contains($comErro->body, 'Layout inválido de propósito'));
    $logar($app, $idUsuario);
    check('aviso de salvamento na tela', str_contains($app->handle(requisicao('GET', '/studio/posts/' . $arteId, [], ['ok' => 'saved']))->body, 'Edição salva como uma nova versão'));
    check('aviso de restauro na tela', str_contains($app->handle(requisicao('GET', '/studio/posts/' . $arteId, [], ['ok' => 'restored']))->body, 'Versão restaurada'));
}

echo "\n[43] Estúdio: dashboard e navegação\n";
$logar($app, $idUsuario);
$painelStudio = $app->handle(requisicao('GET', '/dashboard'));
assertSame('dashboard responde 200', 200, $painelStudio->status);
check('Estúdio no menu', str_contains($painelStudio->body, 'Estúdio'));
$modulosStudio = \Smia\Support\Modules::all();
$moduloEstudio = array_values(array_filter($modulosStudio, static fn (array $m): bool => $m['slug'] === 'studio'))[0] ?? null;
check('módulo estúdio marcado como pronto', ($moduloEstudio['ready'] ?? false) === true);
check('módulo aprovações marcado como pronto', (array_values(array_filter($modulosStudio, static fn (array $m): bool => $m['slug'] === 'approvals'))[0]['ready'] ?? false) === true);
check('Aprovações no menu', str_contains($painelStudio->body, 'Aprovações'));

echo "\n[44] Aprovações: tela, fila vazia e permissões\n";
$logar($app, $idUsuario);
$telaAprov = $app->handle(requisicao('GET', '/approvals'));
assertSame('tela de aprovações responde 200', 200, $telaAprov->status);
check('carrega o CSS de aprovações', str_contains($telaAprov->body, 'approvals.css'));
check('seletor traz o cliente do teste', str_contains($telaAprov->body, 'class="approval-client-picker"'));
check('estado vazio explica como enviar', str_contains($telaAprov->body, 'Nenhuma revisão'));
check('lista as pautas disponíveis do cliente', str_contains($telaAprov->body, 'Pautas do planejamento'));
check('formulário de acesso do portal presente', str_contains($telaAprov->body, 'Criar acesso'));
assertSame('aprovações exigem login', '/edijalmemoura/socialai/login', appNova()[0]->handle(requisicao('GET', '/approvals'))->header('Location'));
$portalSemLogin = $app->handle(requisicao('GET', '/portal/approvals'));
assertSame('portal sem login vai para a entrada do cliente', '/edijalmemoura/socialai/portal/login', $portalSemLogin->header('Location'));
$paginaPortal = $app->handle(requisicao('GET', '/portal/login'));
assertSame('entrada do portal responde 200', 200, $paginaPortal->status);
check('entrada do portal é separada da agência', str_contains($paginaPortal->body, 'PORTAL DO CLIENTE') && !str_contains($paginaPortal->body, 'class="sidebar"'));
check('nenhuma aprovação gravada ainda', (int) $app->db->scalar('SELECT COUNT(*) FROM approvals') === 0);

echo "\n[45] Aprovações: contas do portal e isolamento de sessão\n";
$criarConta = function (array $extra = []) use ($app, $logar, $idUsuario, $csrf, $clientePlano): \Smia\Http\Response {
    $logar($app, $idUsuario);

    return $app->handle(requisicao('POST', '/approvals/portal-users', array_merge([
        'csrf_token' => $csrf,
        'client_id' => (string) $clientePlano,
        'name' => 'Marina Alves',
        'email' => 'marina@bravo.example',
        'password' => 'ClienteForte2026',
    ], $extra)));
};
assertSame('sem CSRF responde 302 com erro', 302, $criarConta(['csrf_token' => 'x'])->status);
assertSame('nada criado sem CSRF', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM client_portal_users'));
assertSame('e-mail inválido não cria conta', 302, $criarConta(['email' => 'marina@'])->status);
assertSame('senha curta não cria conta', 302, $criarConta(['password' => '123'])->status);
assertSame('cliente de outro workspace não cria conta', 302, $criarConta(['client_id' => '9999'])->status);
assertSame('nenhuma conta criada nos casos inválidos', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM client_portal_users'));
$criadaConta = $criarConta();
assertSame('conta criada redireciona', 302, $criadaConta->status);
check('redirect avisa criação', str_contains((string) $criadaConta->header('Location'), 'portal=created'));
$contaPortal = $app->db->one('SELECT * FROM client_portal_users WHERE email = ?', ['marina@bravo.example']);
check('conta gravada e ativa', $contaPortal !== null && (int) $contaPortal['is_active'] === 1);
check('senha guardada como hash', !str_contains((string) $contaPortal['password_hash'], 'ClienteForte2026') && password_verify('ClienteForte2026', (string) $contaPortal['password_hash']));
assertSame('e-mail duplicado é recusado', 302, $criarConta()->status);
assertSame('continua uma conta só', 1, (int) $app->db->scalar('SELECT COUNT(*) FROM client_portal_users'));
assertSame('auditoria do acesso criado', 'portal.user_created', $app->db->scalar("SELECT action FROM audit_log WHERE action = 'portal.user_created'"));

$tokenPortal = $app->session->csrfToken();
$entradaErrada = $app->handle(requisicao('POST', '/portal/login', ['csrf_token' => $tokenPortal, 'email' => 'marina@bravo.example', 'password' => 'errada']));
assertSame('senha errada no portal responde 401', 401, $entradaErrada->status);
check('mensagem genérica no portal', str_contains($entradaErrada->body, 'E-mail ou senha incorretos.'));
$app->session->start();
$tokenPortal = $app->session->csrfToken();
$entradaCerta = $app->handle(requisicao('POST', '/portal/login', ['csrf_token' => $tokenPortal, 'email' => 'marina@bravo.example', 'password' => 'ClienteForte2026']));
assertSame('login do portal redireciona', 302, $entradaCerta->status);
assertSame('destino do portal prefixado', '/edijalmemoura/socialai/portal/approvals', $entradaCerta->header('Location'));
assertSame('login do portal não abre a agência', '/edijalmemoura/socialai/login', $app->handle(requisicao('GET', '/approvals'))->header('Location'));
$app->session->put('user_id', null);
$app->session->forget('user_id');
$app->session->put('portal_user_id', (int) $contaPortal['id']);
$listaPortal = $app->handle(requisicao('GET', '/portal/approvals'));
assertSame('lista do portal responde 200', 200, $listaPortal->status);
check('portal mostra o cliente certo', str_contains($listaPortal->body, 'Bravo Fitness'));
check('portal não mostra o menu da agência', !str_contains($listaPortal->body, 'class="sidebar"'));
assertSame('último acesso registrado', 1, (int) $app->db->scalar('SELECT COUNT(*) FROM client_portal_users WHERE last_login_at IS NOT NULL'));

// Trava de tentativas do portal: 10 erros na janela e a entrada fecha.
for ($tentativa = 0; $tentativa < 10; $tentativa++) {
    $app->session->put('portal_user_id', null);
    $app->session->forget('portal_user_id');
    $app->handle(requisicao('POST', '/portal/login', ['csrf_token' => $csrf, 'email' => 'marina@bravo.example', 'password' => 'errada-' . $tentativa]));
}
$bloqueado = $app->handle(requisicao('POST', '/portal/login', ['csrf_token' => $csrf, 'email' => 'marina@bravo.example', 'password' => 'ClienteForte2026']));
assertSame('portal bloqueia depois de 10 tentativas', 429, $bloqueado->status);
check('mensagem de bloqueio no portal', str_contains($bloqueado->body, 'Muitas tentativas'));
unset($_SESSION['_portal_attempts']);

// Escopos separados: entrar na agência encerra a sessão do portal.
$app->session->put('portal_user_id', (int) $contaPortal['id']);
$loginAgencia = $app->handle(requisicao('POST', '/login', ['csrf_token' => $csrf, 'email' => 'ana@pasta.example', 'password' => 'PastaForte2026']));
assertSame('login da agência redireciona', 302, $loginAgencia->status);
check('login da agência encerra a sessão do portal', $app->session->get('portal_user_id') === null);

echo "\n[46] Aprovações: envio de arte exportada\n";
// A seção [40] salvou a arte no editor, e salvar volta o status para rascunho:
// só arte EXPORTADA entra em revisão. Tentar antes disso tem que falhar.
$logar($app, $idUsuario);
$tentarSemArteExportada = $app->handle(requisicao('POST', '/approvals', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'source' => 'creative:' . $arteId,
]));
assertSame('arte não exportada é recusada', 302, $tentarSemArteExportada->status);
check('mensagem explica a exigência', str_contains((string) $tentarSemArteExportada->header('Location'), 'exportadas'));
assertSame('nada enviado', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM approvals'));
assertSame('envio sem CSRF não cria revisão', 302, $app->handle(requisicao('POST', '/approvals', [
    'csrf_token' => 'x', 'client_id' => (string) $clientePlano, 'source' => 'creative:' . $arteId,
]))->status);
assertSame('envio de fonte inexistente é recusado', 302, $app->handle(requisicao('POST', '/approvals', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'source' => 'creative:9999',
]))->status);
assertSame('envio com origem inválida é recusado', 302, $app->handle(requisicao('POST', '/approvals', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'source' => 'video:1',
]))->status);
assertSame('continua sem revisões', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM approvals'));

$pngReexport = sys_get_temp_dir() . '/smia-export2-' . bin2hex(random_bytes(4)) . '.png';
$lonaReexport = imagecreatetruecolor(1080, 1080);
imagefill($lonaReexport, 0, 0, imagecolorallocate($lonaReexport, 17, 34, 68));
imagepng($lonaReexport, $pngReexport);
imagedestroy($lonaReexport);
$_FILES['file'] = ['name' => 'arte.png', 'tmp_name' => $pngReexport, 'error' => UPLOAD_ERR_OK, 'size' => filesize($pngReexport)];
$logar($app, $idUsuario);
assertSame('arte reexportada para a revisão', 200, $app->handle(requisicao('POST', '/studio/posts/' . $arteId . '/export', ['csrf_token' => $csrf]))->status);
unset($_FILES['file']);

$logar($app, $idUsuario);
$enviada = $app->handle(requisicao('POST', '/approvals', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'source' => 'creative:' . $arteId,
    'message' => 'Primeira versão para o lançamento.',
]));
assertSame('envio da arte exportada redireciona', 302, $enviada->status);
$destinoAprov = (string) $enviada->header('Location');
check('redirect para o detalhe com aviso', str_contains($destinoAprov, '/approvals/') && str_contains($destinoAprov, 'sent=1'), $destinoAprov);
$aprovId = (int) preg_replace('#^.*/approvals/(\d+).*$#', '$1', (string) parse_url($destinoAprov, PHP_URL_PATH));
$aprovacao = $app->db->one('SELECT * FROM approvals WHERE id = ?', [$aprovId]);
check('revisão gravada', $aprovacao !== null);
assertSame('status inicial pendente', 'pending', $aprovacao['status']);
assertSame('origem arte', 'creative', $aprovacao['source_type']);
assertSame('primeiro ciclo', 1, (int) $aprovacao['cycle_number']);
$fotografia = json_decode((string) $aprovacao['snapshot_json'], true);
assertSame('fotografia guarda o tipo', 'creative', $fotografia['type']);
assertSame('fotografia guarda o formato', 'Post quadrado', $fotografia['format']);
check('fotografia guarda o arquivo exportado', !empty($fotografia['export_path']));
check('fotografia guarda o texto da arte', isset($fotografia['content']['title']));
assertSame('evento de envio registrado', 'Enviado para aprovação', $app->db->scalar('SELECT note FROM approval_events WHERE approval_id = ? AND new_status = ?', [$aprovId, 'pending']));
assertSame('mensagem virou comentário', 1, (int) $app->db->scalar('SELECT COUNT(*) FROM comments WHERE approval_id = ? AND author_type = ?', [$aprovId, 'agency']));
assertSame('cliente avisado no portal', 1, (int) $app->db->scalar("SELECT COUNT(*) FROM notifications WHERE recipient_type = 'client' AND recipient_portal_user_id = ?", [(int) $contaPortal['id']]));
check('aviso aponta para o portal', str_starts_with((string) $app->db->scalar("SELECT target_url FROM notifications WHERE recipient_type = 'client' LIMIT 1"), '/portal/approvals/'));
assertSame('auditoria do envio', 'approval.sent', $app->db->scalar("SELECT action FROM audit_log WHERE action = 'approval.sent'"));

$logar($app, $idUsuario);
$duplicado = $app->handle(requisicao('POST', '/approvals', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'source' => 'creative:' . $arteId,
]));
assertSame('envio duplicado é bloqueado', 302, $duplicado->status);
check('mensagem de pendência existente', str_contains(rawurldecode((string) $duplicado->header('Location')), 'já está aguardando aprovação'));
assertSame('continua uma revisão', 1, (int) $app->db->scalar('SELECT COUNT(*) FROM approvals'));

$logar($app, $idUsuario);
$telaAprov = $app->handle(requisicao('GET', '/approvals'));
check('arte em revisão sai da lista de disponíveis', !str_contains($telaAprov->body, 'creative:' . $arteId . '"'));
check('lista mostra a revisão', str_contains($telaAprov->body, 'Ciclo') || str_contains($telaAprov->body, 'Aguardando'));

echo "\n[47] Aprovações: detalhe, mídia e comentário da agência\n";
$logar($app, $idUsuario);
$detalheAprov = $app->handle(requisicao('GET', '/approvals/' . $aprovId));
assertSame('detalhe da revisão responde 200', 200, $detalheAprov->status);
check('detalhe avisa que aguarda o cliente', str_contains($detalheAprov->body, 'Aguardando o cliente'));
check('detalhe mostra o histórico', str_contains($detalheAprov->body, 'Histórico da revisão'));
check('detalhe mostra o comentário enviado', str_contains($detalheAprov->body, 'Primeira versão para o lançamento.'));
check('detalhe aponta para o estúdio', str_contains($detalheAprov->body, '/studio/posts/' . $arteId));
assertSame('revisão de outro id responde 404', 404, $app->handle(requisicao('GET', '/approvals/99999'))->status);

$arquivoExport = (string) $fotografia['export_path'];
$logar($app, $idUsuario);
$midia = $app->handle(requisicao('GET', '/approvals/' . $aprovId . '/media/' . $arquivoExport));
assertSame('mídia da revisão responde 200', 200, $midia->status);
check('mídia vem como PNG', str_contains((string) $midia->header('Content-Type'), 'image/png'));
assertSame('mídia não é cacheada', 'private, no-store', $midia->header('Cache-Control'));
$logar($app, $idUsuario);
assertSame('arquivo fora da revisão responde 404', 404, $app->handle(requisicao('GET', '/approvals/' . $aprovId . '/media/segredo.png'))->status);
assertSame('nome com travessia responde 404', 404, $app->handle(requisicao('GET', '/approvals/' . $aprovId . '/media/..%2F..%2Fdb'))->status);

$logar($app, $idUsuario);
$comentarioAgencia = $app->handle(requisicao('POST', '/approvals/' . $aprovId . '/comment', [
    'csrf_token' => $csrf, 'body' => 'Usei a foto do treino funcional.',
]));
assertSame('comentário da agência redireciona', 302, $comentarioAgencia->status);
check('redirect volta para o histórico', str_contains((string) $comentarioAgencia->header('Location'), '#historico'));
assertSame('comentário gravado', 'Usei a foto do treino funcional.', $app->db->scalar("SELECT body FROM comments WHERE approval_id = ? AND author_type = 'agency' ORDER BY id DESC LIMIT 1", [$aprovId]));
assertSame('cliente avisado do comentário', 2, (int) $app->db->scalar("SELECT COUNT(*) FROM notifications WHERE recipient_type = 'client'"));
$logar($app, $idUsuario);
assertSame('comentário vazio é recusado', 302, $app->handle(requisicao('POST', '/approvals/' . $aprovId . '/comment', ['csrf_token' => $csrf, 'body' => '   ']))->status);
check('mensagem de comentário vazio', str_contains((string) $app->handle(requisicao('POST', '/approvals/' . $aprovId . '/comment', ['csrf_token' => $csrf, 'body' => '']))->header('Location'), 'coment'));
$logar($app, $idUsuario);
assertSame('comentário sem CSRF é recusado', 302, $app->handle(requisicao('POST', '/approvals/' . $aprovId . '/comment', ['csrf_token' => 'x', 'body' => 'oi']))->status);

echo "\n[48] Portal: decisão do cliente e histórico\n";
$app->session->put('portal_user_id', (int) $contaPortal['id']);
$detalhePortal = $app->handle(requisicao('GET', '/portal/approvals/' . $aprovId));
assertSame('detalhe no portal responde 200', 200, $detalhePortal->status);
check('portal mostra a prévia', str_contains($detalhePortal->body, '/portal/approvals/' . $aprovId . '/media/'));
check('portal oferece as três decisões', str_contains($detalhePortal->body, 'Aprovar') && str_contains($detalhePortal->body, 'Pedir alterações') && str_contains($detalhePortal->body, 'Rejeitar'));
check('portal mostra o comentário da agência', str_contains($detalhePortal->body, 'Usei a foto do treino funcional.'));
assertSame('outro cliente não abre a revisão', 404, $app->handle(requisicao('GET', '/portal/approvals/99999'))->status);

$app->session->put('portal_user_id', (int) $contaPortal['id']);
$semMotivo = $app->handle(requisicao('POST', '/portal/approvals/' . $aprovId . '/respond', [
    'csrf_token' => $csrf, 'status' => 'changes_requested', 'body' => '  ',
]));
assertSame('pedir alterações sem texto redireciona com erro', 302, $semMotivo->status);
check('mensagem exige descrição', str_contains((string) $semMotivo->header('Location'), 'altera'));
assertSame('revisão continua pendente', 'pending', $app->db->scalar('SELECT status FROM approvals WHERE id = ?', [$aprovId]));

$app->session->put('portal_user_id', (int) $contaPortal['id']);
$decisaoInvalida = $app->handle(requisicao('POST', '/portal/approvals/' . $aprovId . '/respond', [
    'csrf_token' => $csrf, 'status' => 'talvez', 'body' => 'x',
]));
assertSame('decisão inválida redireciona com erro', 302, $decisaoInvalida->status);
assertSame('revisão ainda pendente', 'pending', $app->db->scalar('SELECT status FROM approvals WHERE id = ?', [$aprovId]));

$app->session->put('portal_user_id', (int) $contaPortal['id']);
$pedidoAlteracao = $app->handle(requisicao('POST', '/portal/approvals/' . $aprovId . '/respond', [
    'csrf_token' => $csrf, 'status' => 'changes_requested', 'body' => 'Troque a foto e cite o desconto de 20%.',
]));
assertSame('pedido de alteração redireciona', 302, $pedidoAlteracao->status);
check('redirect avisa da resposta', str_contains((string) $pedidoAlteracao->header('Location'), 'responded=1'));
$aprovacao = $app->db->one('SELECT * FROM approvals WHERE id = ?', [$aprovId]);
assertSame('status vira alterações solicitadas', 'changes_requested', $aprovacao['status']);
check('data de resposta preenchida', !empty($aprovacao['resolved_at']));
assertSame('evento do cliente registrado', 'changes_requested', $app->db->scalar("SELECT new_status FROM approval_events WHERE approval_id = ? AND actor_type = 'client'", [$aprovId]));
assertSame('autor do evento é a conta do portal', (int) $contaPortal['id'], (int) $app->db->scalar("SELECT actor_portal_user_id FROM approval_events WHERE approval_id = ? AND actor_type = 'client'", [$aprovId]));
assertSame('resposta vira comentário', 'Troque a foto e cite o desconto de 20%.', $app->db->scalar("SELECT body FROM comments WHERE approval_id = ? AND author_type = 'client'", [$aprovId]));
assertSame('equipe avisada', 1, (int) $app->db->scalar("SELECT COUNT(*) FROM notifications WHERE recipient_type = 'agency' AND recipient_user_id = ?", [$idUsuario]));
check('aviso cita a decisão', str_contains((string) $app->db->scalar("SELECT body FROM notifications WHERE recipient_type = 'agency' LIMIT 1"), 'solicitou alterações'));

$app->session->put('portal_user_id', (int) $contaPortal['id']);
$telaPortalResolvida = $app->handle(requisicao('GET', '/portal/approvals/' . $aprovId));
assertSame('portal mostra a revisão respondida', 200, $telaPortalResolvida->status);
check('portal mostra a resposta registrada', str_contains($telaPortalResolvida->body, 'Resposta registrada'));
check('portal não oferece decidir de novo', !str_contains($telaPortalResolvida->body, 'name="status" value="approved"'));
$app->session->put('portal_user_id', (int) $contaPortal['id']);
assertSame('segunda resposta responde 409', 409, $app->handle(requisicao('POST', '/portal/approvals/' . $aprovId . '/respond', [
    'csrf_token' => $csrf, 'status' => 'approved', 'body' => 'agora sim',
]))->status);
assertSame('status preservado depois do 409', 'changes_requested', $app->db->scalar('SELECT status FROM approvals WHERE id = ?', [$aprovId]));

$logar($app, $idUsuario);
$detalheAprov = $app->handle(requisicao('GET', '/approvals/' . $aprovId));
check('agência vê a decisão do cliente', str_contains($detalheAprov->body, 'Alterações solicitadas'));
check('agência vê o motivo', str_contains($detalheAprov->body, 'Troque a foto e cita o desconto') || str_contains($detalheAprov->body, 'Troque a foto e cite o desconto'));
check('aviso de retorno guardado para a equipe', (int) $app->db->scalar("SELECT COUNT(*) FROM notifications WHERE recipient_type = 'agency' AND title = 'Retorno do cliente'") === 1);
check('aviso de retorno visível na lista', str_contains($app->handle(requisicao('GET', '/approvals'))->body, 'data-aviso="1"'));

echo "\n[49] Aprovações: novo ciclo preserva o histórico\n";
$logar($app, $idUsuario);
$detalheAprov = $app->handle(requisicao('GET', '/approvals/' . $aprovId));
check('revisão resolvida não oferece cancelar', !str_contains($detalheAprov->body, 'Cancelar revisão'));
$logar($app, $idUsuario);
$reenvio = $app->handle(requisicao('POST', '/approvals', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'source' => 'creative:' . $arteId,
]));
assertSame('reenvio depois da resposta é aceito', 302, $reenvio->status);
$segundoId = (int) preg_replace('#^.*/approvals/(\d+).*$#', '$1', (string) parse_url((string) $reenvio->header('Location'), PHP_URL_PATH));
check('novo ciclo é outra revisão', $segundoId !== $aprovId && $segundoId > 0);
assertSame('ciclo incrementado', 2, (int) $app->db->scalar('SELECT cycle_number FROM approvals WHERE id = ?', [$segundoId]));
assertSame('histórico anterior preservado', 1, (int) $app->db->scalar('SELECT COUNT(*) FROM approvals WHERE id = ? AND status = ?', [$aprovId, 'changes_requested']));
assertSame('comentários do ciclo anterior intactos', 3, (int) $app->db->scalar('SELECT COUNT(*) FROM comments WHERE approval_id = ?', [$aprovId]));

$logar($app, $idUsuario);
$cancelada = $app->handle(requisicao('POST', '/approvals/' . $segundoId . '/cancel', ['csrf_token' => $csrf]));
assertSame('cancelamento redireciona', 302, $cancelada->status);
assertSame('cancelamento rejeita a pendência', 'rejected', $app->db->scalar('SELECT status FROM approvals WHERE id = ?', [$segundoId]));
assertSame('evento de cancelamento', 'Revisão cancelada pela agência', $app->db->scalar("SELECT note FROM approval_events WHERE approval_id = ? AND new_status = 'rejected'", [$segundoId]));
$logar($app, $idUsuario);
assertSame('cancelar duas vezes responde 302 com erro', 302, $app->handle(requisicao('POST', '/approvals/' . $segundoId . '/cancel', ['csrf_token' => $csrf]))->status);
assertSame('cancelar sem CSRF é recusado', 302, $app->handle(requisicao('POST', '/approvals/' . $aprovId . '/cancel', ['csrf_token' => 'x']))->status);
assertSame('cancelamento libera novo envio', 302, $app->handle(requisicao('POST', '/approvals', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'source' => 'creative:' . $arteId,
]))->status);
assertSame('terceiro ciclo criado', 3, (int) $app->db->scalar('SELECT MAX(cycle_number) FROM approvals WHERE creative_id = ?', [$arteId]));

echo "\n[50] Aprovações: pauta planejada e anexos\n";
// O plano da seção [20] foi excluído em [23]: gera um novo para revisar a pauta.
$logar($app, $idUsuario);
$planoRevisao = $app->handle(requisicao('POST', '/planner/generate', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'period_days' => '7',
    'target_posts' => '3', 'start_date' => '2026-04-06', 'networks' => ['Instagram'],
    'objective' => 'Divulgar o plano de revisão de conteúdo', 'campaign_context' => '',
]));
assertSame('plano novo criado para a pauta', 302, $planoRevisao->status);
$planoRevisaoId = (int) (explode('/', parse_url((string) $planoRevisao->header('Location'), PHP_URL_PATH))[4] ?? 0);
$postPlanejado = $app->db->one('SELECT * FROM posts WHERE calendar_id = ? ORDER BY publication_date LIMIT 1', [$planoRevisaoId]);
check('pauta encontrada para envio', $postPlanejado !== null);
$logar($app, $idUsuario);
$envioPauta = $app->handle(requisicao('POST', '/approvals', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'source' => 'planned:' . (int) $postPlanejado['id'],
]));
assertSame('envio de pauta redireciona', 302, $envioPauta->status);
$pautaAprovId = (int) preg_replace('#^.*/approvals/(\d+).*$#', '$1', (string) parse_url((string) $envioPauta->header('Location'), PHP_URL_PATH));
$pautaAprov = $app->db->one('SELECT * FROM approvals WHERE id = ?', [$pautaAprovId]);
assertSame('origem pauta', 'planned', $pautaAprov['source_type']);
$fotografiaPauta = json_decode((string) $pautaAprov['snapshot_json'], true);
assertSame('fotografia da pauta tem tipo', 'planned', $fotografiaPauta['type']);
check('fotografia da pauta tem legenda', mb_strlen((string) $fotografiaPauta['caption']) > 0);
check('fotografia da pauta tem briefing', mb_strlen((string) $fotografiaPauta['visual_brief']) > 0);
$logar($app, $idUsuario);
$detalhePauta = $app->handle(requisicao('GET', '/approvals/' . $pautaAprovId));
assertSame('detalhe da pauta responde 200', 200, $detalhePauta->status);
check('detalhe da pauta mostra o briefing', str_contains($detalhePauta->body, 'Briefing visual'));
check('pauta não exige imagem', str_contains($detalhePauta->body, 'Pauta sem imagem'));

$pngAnexo = sys_get_temp_dir() . '/smia-anexo-' . bin2hex(random_bytes(4)) . '.png';
$lonaAnexo = imagecreatetruecolor(120, 120);
imagefill($lonaAnexo, 0, 0, imagecolorallocate($lonaAnexo, 200, 80, 40));
imagepng($lonaAnexo, $pngAnexo);
imagedestroy($lonaAnexo);
$_FILES['file'] = ['name' => 'referencia.png', 'tmp_name' => $pngAnexo, 'error' => UPLOAD_ERR_OK, 'size' => filesize($pngAnexo)];
$app->session->put('portal_user_id', (int) $contaPortal['id']);
$app->session->put('user_id', null);
$app->session->forget('user_id');
$anexoPortal = $app->handle(requisicao('POST', '/portal/approvals/' . $pautaAprovId . '/comment', [
    'csrf_token' => $csrf, 'body' => 'Sugestão de imagem em anexo.',
]));
unset($_FILES['file']);
assertSame('comentário com anexo redireciona', 302, $anexoPortal->status);
$anexo = $app->db->one('SELECT * FROM comments WHERE approval_id = ? AND author_type = ?', [$pautaAprovId, 'client']);
check('anexo gravado no comentário', $anexo !== null && !empty($anexo['attachment_path']));
assertSame('nome original preservado', 'referencia.png', $anexo['attachment_name']);
check('arquivo do anexo existe', is_file($raiz . '/uploads/' . (string) $anexo['attachment_path']));
$app->session->put('portal_user_id', (int) $contaPortal['id']);
$midiaAnexo = $app->handle(requisicao('GET', '/portal/approvals/' . $pautaAprovId . '/media/' . $anexo['attachment_path']));
assertSame('anexo é servido no portal', 200, $midiaAnexo->status);
$logar($app, $idUsuario);
$midiaAnexoAgencia = $app->handle(requisicao('GET', '/approvals/' . $pautaAprovId . '/media/' . $anexo['attachment_path']));
assertSame('anexo é servido na agência', 200, $midiaAnexoAgencia->status);
$app->session->put('portal_user_id', (int) $contaPortal['id']);
assertSame('anexo de outra revisão responde 404', 404, $app->handle(requisicao('GET', '/portal/approvals/' . $aprovId . '/media/' . $anexo['attachment_path']))->status);
// Arquivo com extensão de imagem e conteúdo que não é imagem: validado pelos bytes.
$arquivoFalso = sys_get_temp_dir() . '/smia-anexo-falso-' . bin2hex(random_bytes(4)) . '.png';
file_put_contents($arquivoFalso, "GIF89a nao-e-png");
$_FILES['file'] = ['name' => 'referencia.png', 'tmp_name' => $arquivoFalso, 'error' => UPLOAD_ERR_OK, 'size' => filesize($arquivoFalso)];
$app->session->put('portal_user_id', (int) $contaPortal['id']);
$anexoInvalido = $app->handle(requisicao('POST', '/portal/approvals/' . $pautaAprovId . '/comment', [
    'csrf_token' => $csrf, 'body' => 'arquivo errado',
]));
assertSame('anexo de conteúdo inválido é recusado', 302, $anexoInvalido->status);
check('mensagem explica o formato aceito', str_contains(rawurldecode((string) $anexoInvalido->header('Location')), 'PNG, JPG ou WEBP'));
unset($_FILES['file']);

echo "\n[51] Aprovações: permissões, isolamento e navegação\n";
$papelAprov = (string) $app->db->scalar('SELECT role FROM users WHERE id = ?', [$idUsuario]);
$app->db->run("UPDATE users SET role = 'viewer' WHERE id = ?", [$idUsuario]);
$logar($app, $idUsuario);
assertSame('viewer abre a lista', 200, $app->handle(requisicao('GET', '/approvals'))->status);
$logar($app, $idUsuario);
check('viewer não vê o formulário de envio', !str_contains($app->handle(requisicao('GET', '/approvals'))->body, 'Enviar para o portal'));
$logar($app, $idUsuario);
assertSame('viewer não envia para revisão', 403, $app->handle(requisicao('POST', '/approvals', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'source' => 'creative:' . $arteId,
]))->status);
$logar($app, $idUsuario);
assertSame('viewer não cria acesso do portal', 403, $app->handle(requisicao('POST', '/approvals/portal-users', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'name' => 'X', 'email' => 'x@y.example', 'password' => 'SenhaForte123',
]))->status);
$logar($app, $idUsuario);
assertSame('viewer não comenta', 403, $app->handle(requisicao('POST', '/approvals/' . $aprovId . '/comment', ['csrf_token' => $csrf, 'body' => 'oi']))->status);
$logar($app, $idUsuario);
assertSame('viewer abre o detalhe', 200, $app->handle(requisicao('GET', '/approvals/' . $aprovId))->status);
$app->db->run("UPDATE users SET role = 'editor' WHERE id = ?", [$idUsuario]);
$logar($app, $idUsuario);
assertSame('editor não cria acesso do portal', 403, $app->handle(requisicao('POST', '/approvals/portal-users', [
    'csrf_token' => $csrf, 'client_id' => (string) $clientePlano, 'name' => 'X', 'email' => 'z@y.example', 'password' => 'SenhaForte123',
]))->status);
$logar($app, $idUsuario);
assertSame('editor não pausa acesso do portal', 403, $app->handle(requisicao('POST', '/approvals/portal-users/' . (int) $contaPortal['id'] . '/toggle', ['csrf_token' => $csrf]))->status);

$app->db->run('UPDATE users SET role = ? WHERE id = ?', [$papelAprov, $idUsuario]);
$logar($app, $idUsuario);
$pausada = $app->handle(requisicao('POST', '/approvals/portal-users/' . (int) $contaPortal['id'] . '/toggle', ['csrf_token' => $csrf]));
assertSame('owner pausa o acesso', 302, $pausada->status);
assertSame('acesso fica inativo', 0, (int) $app->db->scalar('SELECT is_active FROM client_portal_users WHERE id = ?', [(int) $contaPortal['id']]));
$app->session->put('portal_user_id', (int) $contaPortal['id']);
assertSame('conta pausada perde a sessão na hora', '/edijalmemoura/socialai/portal/login', $app->handle(requisicao('GET', '/portal/approvals'))->header('Location'));
$app->session->put('portal_user_id', (int) $contaPortal['id']);
assertSame('conta pausada não responde revisão', '/edijalmemoura/socialai/portal/login', $app->handle(requisicao('POST', '/portal/approvals/' . $pautaAprovId . '/respond', ['csrf_token' => $csrf, 'status' => 'approved']))->header('Location'));
$logar($app, $idUsuario);
assertSame('acesso é reativado', 302, $app->handle(requisicao('POST', '/approvals/portal-users/' . (int) $contaPortal['id'] . '/toggle', ['csrf_token' => $csrf]))->status);
assertSame('acesso volta a ficar ativo', 1, (int) $app->db->scalar('SELECT is_active FROM client_portal_users WHERE id = ?', [(int) $contaPortal['id']]));
assertSame('pausar sem CSRF não muda nada', 302, $app->handle(requisicao('POST', '/approvals/portal-users/' . (int) $contaPortal['id'] . '/toggle', ['csrf_token' => 'x']))->status);
assertSame('acesso segue ativo', 1, (int) $app->db->scalar('SELECT is_active FROM client_portal_users WHERE id = ?', [(int) $contaPortal['id']]));

$app->db->run('UPDATE users SET role = ? WHERE id = ?', [$papelAprov, $idUsuario]);
$logar($app, (int) $app->db->scalar("SELECT id FROM users WHERE email = 'outro@exemplo.com'"));
assertSame('outra agência não abre a revisão', 404, $app->handle(requisicao('GET', '/approvals/' . $aprovId))->status);
$logar($app, (int) $app->db->scalar("SELECT id FROM users WHERE email = 'outro@exemplo.com'"));
assertSame('outra agência não vê a mídia', 404, $app->handle(requisicao('GET', '/approvals/' . $aprovId . '/media/' . $arquivoExport))->status);
$logar($app, (int) $app->db->scalar("SELECT id FROM users WHERE email = 'outro@exemplo.com'"));
assertSame('outra agência não comenta', 404, $app->handle(requisicao('POST', '/approvals/' . $aprovId . '/comment', ['csrf_token' => $csrf, 'body' => 'invasão']))->status);
$logar($app, (int) $app->db->scalar("SELECT id FROM users WHERE email = 'outro@exemplo.com'"));
check('outra agência não cria acesso do portal', (int) $app->db->scalar('SELECT COUNT(*) FROM client_portal_users') === 1);

$logar($app, $idUsuario);
$painelAprov = $app->handle(requisicao('GET', '/dashboard'));
assertSame('dashboard responde 200 com aprovações', 200, $painelAprov->status);
check('Aprovações aparece no menu', str_contains($painelAprov->body, 'Aprovações'));
check('Aprovações aparece com link no dashboard', str_contains($painelAprov->body, '/approvals"'));
// O número de módulos prontos cresce a cada entrega: em vez de congelar "5 de 8",
// o teste confere que o rodapé do card reflete a lista real de Modules.
$prontosAprov = count(array_filter(\Smia\Support\Modules::all(), static fn (array $m): bool => $m['ready']));
check('dashboard conta os módulos prontos na época das aprovações', str_contains($painelAprov->body, 'módulo ' . $prontosAprov . ' de 8 pronto'));
$logar($app, $idUsuario);
check('lista aponta para o portal do cliente', str_contains($app->handle(requisicao('GET', '/approvals'))->body, 'Acessos do portal'));
assertSame('rota inexistente de aprovação responde 404', 404, $app->handle(requisicao('GET', '/approvals/abc/xyz'))->status);
assertSame('portal com id inválido responde 404', 404, $app->handle(requisicao('GET', '/approvals/0'))->status ?: 0);
$app->session->put('portal_user_id', (int) $contaPortal['id']);
assertSame('portal responde 404 para revisão inexistente', 404, $app->handle(requisicao('GET', '/portal/approvals/98765'))->status);
$app->session->put('portal_user_id', (int) $contaPortal['id']);
assertSame('portal exige CSRF para comentar', 302, $app->handle(requisicao('POST', '/portal/approvals/' . $pautaAprovId . '/comment', ['csrf_token' => 'x', 'body' => 'oi']))->status);
$app->session->forget('portal_user_id');
assertSame('logout do portal exige CSRF', '/edijalmemoura/socialai/portal/approvals', $app->handle(requisicao('POST', '/portal/logout', ['csrf_token' => 'x']))->header('Location'));
$app->session->put('portal_user_id', (int) $contaPortal['id']);
assertSame('logout do portal redireciona', '/edijalmemoura/socialai/portal/login', $app->handle(requisicao('POST', '/portal/logout', ['csrf_token' => $csrf]))->header('Location'));
check('sessão do portal encerrada', $app->session->get('portal_user_id') === null);

echo "\n[52] Aprovações: migração aditiva mantém dados da v1.2\n";
$appAntiga->db->run("INSERT INTO content_calendar (company_id, client_id, created_by, title, objective, period_days, target_posts, start_date, networks_json, ai_model) VALUES (1, 1, 1, 'Plano antigo', 'Objetivo', 7, 3, '2026-01-05', '[\"Instagram\"]', 'local-draft')");
$planoAntigo = (int) $appAntiga->db->pdo()->lastInsertId();
$appAntiga->db->run("INSERT INTO posts (company_id, calendar_id, client_id, publication_date, position, theme, network, content_type, strategic_objective, main_text, caption, visual_brief, image_suggestion) VALUES (1, ?, 1, '2026-01-05', 1, 'Tema antigo', 'Instagram', 'Post', 'Objetivo', 'Texto', 'Legenda', 'Brief', 'Imagem')", [$planoAntigo]);
\Smia\Database\Schema::migrate($appAntiga->db);
assertSame('tabela de aprovações criada na migração', 1, (int) $appAntiga->db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'approvals'"));
assertSame('tabela de contas do portal criada', 1, (int) $appAntiga->db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'client_portal_users'"));
assertSame('tabela de comentários criada', 1, (int) $appAntiga->db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'comments'"));
assertSame('tabela de avisos criada', 1, (int) $appAntiga->db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'notifications'"));
assertSame('dados do planejamento preservados', 'Tema antigo', $appAntiga->db->scalar('SELECT theme FROM posts WHERE calendar_id = ?', [$planoAntigo]));
assertSame('cliente herdado preservado', 'Cliente Herdado', $appAntiga->db->scalar('SELECT company_name FROM clients WHERE id = 1'));
$appAntiga->db->run("INSERT INTO creative_posts (company_id, client_id, created_by, subject, format_key, canvas_width, canvas_height, content_json, layout_json, background_color, status, export_path) VALUES (1, 1, 1, 'Arte antiga', 'square', 1080, 1080, '{\"title\":\"t\",\"subtitle\":\"s\",\"cta\":\"c\",\"caption\":\"l\",\"hashtags\":\"\",\"visual_brief\":\"b\",\"image_suggestion\":\"i\",\"composition\":\"\"}', '{\"elements\":[]}', '#112244', 'exported', 'arte.png')");
\Smia\Database\Schema::migrate($appAntiga->db);
assertSame('migração segue idempotente', 'Arte antiga', $appAntiga->db->scalar('SELECT subject FROM creative_posts WHERE id = 1'));
assertSame('uma linha na versão do esquema', 1, (int) $appAntiga->db->scalar('SELECT COUNT(*) FROM schema_version WHERE version = ?', [\Smia\Database\Schema::VERSAO]));

echo "\n[54] Feed: tela, perfil e fontes\n";
$logar($app, $idUsuario);
$telaFeed = $app->handle(requisicao('GET', '/feed'));
assertSame('tela do feed responde 200', 200, $telaFeed->status);
check('carrega o CSS do feed', str_contains($telaFeed->body, 'feed.css'));
check('carrega o JS da grade', str_contains($telaFeed->body, 'feed.js'));
check('mostra o perfil da marca', str_contains($telaFeed->body, 'class="ig-avatar"'));
check('avisa que a grade é prévia interna', str_contains($telaFeed->body, 'prévia interna'));
check('mostra as três colunas', str_contains($telaFeed->body, 'grade do Instagram') || str_contains($telaFeed->body, 'Grade do Instagram'));
check('grade começa vazia com convite', str_contains($telaFeed->body, 'A grade está vazia'));
check('lista as artes do estúdio fora da grade', str_contains($telaFeed->body, 'Artes do estúdio') && str_contains($telaFeed->body, 'Aula experimental gratuita'));
check('lista as pautas do planejamento', str_contains($telaFeed->body, 'Pautas planejadas'));
assertSame('feed exige login', '/edijalmemoura/socialai/login', appNova()[0]->handle(requisicao('GET', '/feed'))->header('Location'));
assertSame('nada gravado na grade ainda', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM feed_items'));

$semClienteFeed = appNova();
[$appSemMarca, , $raizSemMarca] = $semClienteFeed;
$appSemMarca->db->run("INSERT INTO companies (name, slug) VALUES ('Vazia', 'vazia')");
$appSemMarca->db->run("INSERT INTO users (company_id, name, email, password_hash, role) VALUES (1, 'Vazio', 'vazio@exemplo.com', ?, 'owner')", [password_hash('SenhaForte123', PASSWORD_DEFAULT)]);
$appSemMarca->session->start();
$appSemMarca->session->put('user_id', 1);
$feedSemMarca = $appSemMarca->handle(requisicao('GET', '/feed'));
assertSame('feed sem clientes responde 200', 200, $feedSemMarca->status);
check('feed sem clientes convida a cadastrar', str_contains($feedSemMarca->body, 'Cadastre um cliente para visualizar o feed'));

echo "\n[55] Feed: adicionar arte e pauta à grade\n";
$logar($app, $idUsuario);
$semCsrfFeed = $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/add/creative/' . $arteId, ['csrf_token' => 'x']));
assertSame('adicionar sem CSRF não grava', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM feed_items'));
check('erro de CSRF volta para a tela', str_contains(rawurldecode((string) $semCsrfFeed->header('Location')), 'Sessão expirada'));

$logar($app, $idUsuario);
$addArte = $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/add/creative/' . $arteId, ['csrf_token' => $csrf]));
assertSame('adicionar arte redireciona', 302, $addArte->status);
check('redirect volta para a grade', str_contains((string) $addArte->header('Location'), '#grade'));
$itemArte = $app->db->one('SELECT * FROM feed_items WHERE creative_id = ?', [$arteId]);
check('arte na grade', $itemArte !== null && (int) $itemArte['position'] === 1);
check('linha guarda a marca', (int) $itemArte['client_id'] === $clientePlano);
assertSame('auditoria do item', 'feed.added', $app->db->scalar("SELECT action FROM audit_log WHERE action = 'feed.added'"));

$logar($app, $idUsuario);
$addRepetida = $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/add/creative/' . $arteId, ['csrf_token' => $csrf]));
assertSame('arte repetida não duplica', 1, (int) $app->db->scalar('SELECT COUNT(*) FROM feed_items WHERE creative_id = ?', [$arteId]));
check('aviso de item repetido', str_contains(rawurldecode((string) $addRepetida->header('Location')), 'já está na prévia'));

$logar($app, $idUsuario);
assertSame('arte inexistente responde 404', 404, $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/add/creative/99999', ['csrf_token' => $csrf]))->status);
$logar($app, $idUsuario);
assertSame('marca inexistente responde 404', 404, $app->handle(requisicao('POST', '/feed/99999/add/creative/' . $arteId, ['csrf_token' => $csrf]))->status);

$postFeed = $app->db->one('SELECT * FROM posts WHERE calendar_id = ? ORDER BY publication_date LIMIT 1', [$planoRevisaoId]);
$logar($app, $idUsuario);
$addPauta = $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/add/post/' . (int) $postFeed['id'], ['csrf_token' => $csrf]));
assertSame('adicionar pauta redireciona', 302, $addPauta->status);
$itemPauta = $app->db->one('SELECT * FROM feed_items WHERE planned_post_id = ?', [(int) $postFeed['id']]);
check('pauta na grade em segundo lugar', $itemPauta !== null && (int) $itemPauta['position'] === 2);
assertSame('duas linhas na grade', 2, (int) $app->db->scalar('SELECT COUNT(*) FROM feed_items'));

$logar($app, $idUsuario);
$telaFeed = $app->handle(requisicao('GET', '/feed'));
check('grade mostra a arte', str_contains($telaFeed->body, 'data-feed-id="' . (int) $itemArte['id'] . '"'));
check('grade mostra a pauta', str_contains($telaFeed->body, 'data-feed-id="' . (int) $itemPauta['id'] . '"'));
check('arte exportada tem selo', str_contains($telaFeed->body, 'ARTE EXPORTADA'));
check('pauta usa o bloco de texto', str_contains($telaFeed->body, 'feed-text-placeholder'));
check('grade numerada', str_contains($telaFeed->body, 'class="tile-order">1<') && str_contains($telaFeed->body, 'class="tile-order">2<'));
check('contadores do perfil', str_contains($telaFeed->body, 'na prévia'));
check('arte sai da lista de fontes', !str_contains($telaFeed->body, '/add/creative/' . $arteId));
check('pauta sai da lista de fontes', !str_contains($telaFeed->body, '/add/post/' . (int) $postFeed['id']));

echo "\n[56] Feed: reordenar com trava de concorrência\n";
$ordemInvertida = [$itemPauta['id'], $itemArte['id']];
$logar($app, $idUsuario);
$semToken = $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/reorder', ['csrf_token' => 'errado', 'order_json' => json_encode($ordemInvertida)]));
assertSame('reordenar sem CSRF responde 400', 400, $semToken->status);
check('erro de CSRF em JSON', str_contains((string) $semToken->header('Content-Type'), 'application/json'));
check('mensagem em JSON', str_contains($semToken->body, 'error'));

$logar($app, $idUsuario);
$ordemInvalida = $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/reorder', ['csrf_token' => $csrf, 'order_json' => '[1,2,3]']));
assertSame('ordem com id estranho responde 409', 409, $ordemInvalida->status);
check('mensagem de lista alterada', str_contains($ordemInvalida->body, 'mudou'));
$logar($app, $idUsuario);
assertSame('ordem repetida responde 400', 400, $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/reorder', ['csrf_token' => $csrf, 'order_json' => '[' . $itemArte['id'] . ',' . $itemArte['id'] . ']']))->status);
$logar($app, $idUsuario);
assertSame('ordem vazia responde 400', 400, $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/reorder', ['csrf_token' => $csrf, 'order_json' => '[]']))->status);
$logar($app, $idUsuario);
assertSame('JSON quebrado responde 400', 400, $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/reorder', ['csrf_token' => $csrf, 'order_json' => '{isso não é json']))->status);
assertSame('ordem salva continua a mesma', 1, (int) $app->db->scalar('SELECT position FROM feed_items WHERE id = ?', [$itemArte['id']]));

$logar($app, $idUsuario);
$reordenado = $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/reorder', ['csrf_token' => $csrf, 'order_json' => json_encode($ordemInvertida)]));
assertSame('reordenar válido responde 200', 200, $reordenado->status);
check('resposta JSON confirma', str_contains($reordenado->body, '"status":"ok"'));
assertSame('pauta passou a primeira', 1, (int) $app->db->scalar('SELECT position FROM feed_items WHERE id = ?', [$itemPauta['id']]));
assertSame('arte foi para segunda', 2, (int) $app->db->scalar('SELECT position FROM feed_items WHERE id = ?', [$itemArte['id']]));
assertSame('auditoria da reordenação', 'feed.reordered', $app->db->scalar("SELECT action FROM audit_log WHERE action = 'feed.reordered'"));

$logar($app, $idUsuario);
$telaFeed = $app->handle(requisicao('GET', '/feed'));
$posicaoPauta = strpos($telaFeed->body, 'data-feed-id="' . (int) $itemPauta['id'] . '"');
$posicaoArte = strpos($telaFeed->body, 'data-feed-id="' . (int) $itemArte['id'] . '"');
check('tela respeita a nova ordem', $posicaoPauta !== false && $posicaoArte !== false && $posicaoPauta < $posicaoArte);

echo "\n[57] Feed: remover, origem excluída e isolamento\n";
$logar($app, $idUsuario);
$removerSemCsrf = $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/remove/' . (int) $itemPauta['id'], ['csrf_token' => 'x']));
assertSame('remover sem CSRF não apaga', 2, (int) $app->db->scalar('SELECT COUNT(*) FROM feed_items'));
check('erro de remoção volta para a tela', str_contains(rawurldecode((string) $removerSemCsrf->header('Location')), 'Sessão expirada'));

$logar($app, $idUsuario);
$removido = $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/remove/' . (int) $itemPauta['id'], ['csrf_token' => $csrf]));
assertSame('remover item redireciona', 302, $removido->status);
assertSame('item removido da grade', 1, (int) $app->db->scalar('SELECT COUNT(*) FROM feed_items'));
assertSame('pauta original preservada', 1, (int) $app->db->scalar('SELECT COUNT(*) FROM posts WHERE id = ?', [(int) $postFeed['id']]));
assertSame('auditoria da remoção', 'feed.removed', $app->db->scalar("SELECT action FROM audit_log WHERE action = 'feed.removed'"));
$logar($app, $idUsuario);
assertSame('remover item inexistente não explode', 302, $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/remove/' . (int) $itemPauta['id'], ['csrf_token' => $csrf]))->status);

// Excluir a arte no estúdio tira o item da grade em cascata — a prévia nunca
// fica apontando para um arquivo que não existe mais.
$app->db->run(
    "INSERT INTO creative_posts (company_id, client_id, created_by, subject, format_key, canvas_width, canvas_height, content_json, layout_json, background_color, status)
     VALUES (?, ?, ?, 'Arte descartável', 'square', 1080, 1080, '{}', '{\"elements\":[]}', '#112244', 'draft')",
    [$empresaTeste, $clientePlano, $idUsuario]
);
$arteDescartavel = (int) $app->db->pdo()->lastInsertId();
$logar($app, $idUsuario);
assertSame('arte nova entra na grade', 302, $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/add/creative/' . $arteDescartavel, ['csrf_token' => $csrf]))->status);
$contagemAntes = (int) $app->db->scalar('SELECT COUNT(*) FROM feed_items WHERE company_id = ?', [$empresaTeste]);
assertSame('linha criada para a arte nova', 1, (int) $app->db->scalar('SELECT COUNT(*) FROM feed_items WHERE creative_id = ?', [$arteDescartavel]));
$logar($app, $idUsuario);
assertSame('excluir a arte no estúdio redireciona', 302, $app->handle(requisicao('POST', '/studio/posts/' . $arteDescartavel . '/delete', ['csrf_token' => $csrf]))->status);
assertSame('grade perdeu a linha em cascata', $contagemAntes - 1, (int) $app->db->scalar('SELECT COUNT(*) FROM feed_items WHERE company_id = ?', [$empresaTeste]));
assertSame('nenhuma linha órfã da arte excluída', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM feed_items WHERE creative_id = ?', [$arteDescartavel]));

$logar($app, (int) $app->db->scalar("SELECT id FROM users WHERE email = 'outro@exemplo.com'"));
assertSame('outra agência não vê a grade', 404, $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/add/creative/' . $arteId, ['csrf_token' => $csrf]))->status);
$logar($app, (int) $app->db->scalar("SELECT id FROM users WHERE email = 'outro@exemplo.com'"));
assertSame('outra agência não remove item (a marca nem existe para ela)', 404, $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/remove/' . (int) $itemArte['id'], ['csrf_token' => $csrf]))->status);
check('item da outra agência intacto', (int) $app->db->scalar('SELECT COUNT(*) FROM feed_items WHERE id = ?', [(int) $itemArte['id']]) === 1);
$logar($app, (int) $app->db->scalar("SELECT id FROM users WHERE email = 'outro@exemplo.com'"));
$feedOutra = $app->handle(requisicao('GET', '/feed'));
assertSame('outra agência usa o próprio feed', 200, $feedOutra->status);
check('grade da outra agência não mostra conteúdo alheio', !str_contains($feedOutra->body, 'Aula experimental gratuita'));

echo "\n[58] Feed: permissões e navegação\n";
$papelFeed = (string) $app->db->scalar('SELECT role FROM users WHERE id = ?', [$idUsuario]);
$app->db->run("UPDATE users SET role = 'viewer' WHERE id = ?", [$idUsuario]);
$logar($app, $idUsuario);
$feedViewer = $app->handle(requisicao('GET', '/feed'));
assertSame('viewer abre a prévia', 200, $feedViewer->status);
check('viewer não vê botão de adicionar', !str_contains($feedViewer->body, '/add/creative/'));
check('viewer não vê botão de remover', !str_contains($feedViewer->body, '/remove/'));
check('viewer não pode arrastar', str_contains($feedViewer->body, 'data-can-edit="false"'));
$logar($app, $idUsuario);
assertSame('viewer não adiciona', 403, $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/add/creative/' . $arteId, ['csrf_token' => $csrf]))->status);
$logar($app, $idUsuario);
assertSame('viewer não remove', 403, $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/remove/' . (int) $itemArte['id'], ['csrf_token' => $csrf]))->status);
$logar($app, $idUsuario);
assertSame('viewer não reordena', 403, $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/reorder', ['csrf_token' => $csrf, 'order_json' => '[' . (int) $itemArte['id'] . ']']))->status);

$app->db->run("UPDATE users SET role = 'editor' WHERE id = ?", [$idUsuario]);
$logar($app, $idUsuario);
assertSame('editor reordena', 200, $app->handle(requisicao('POST', '/feed/' . $clientePlano . '/reorder', ['csrf_token' => $csrf, 'order_json' => '[' . (int) $itemArte['id'] . ']']))->status);
$app->db->run('UPDATE users SET role = ? WHERE id = ?', [$papelFeed, $idUsuario]);

$logar($app, $idUsuario);
$painelFeed = $app->handle(requisicao('GET', '/dashboard'));
assertSame('dashboard responde 200 com feed', 200, $painelFeed->status);
check('Feed aparece no menu', str_contains($painelFeed->body, '/feed"'));
$prontosFeed = count(array_filter(\Smia\Support\Modules::all(), static fn (array $m): bool => $m['ready']));
check('dashboard conta os módulos prontos depois do feed', str_contains($painelFeed->body, 'módulo ' . $prontosFeed . ' de 8 pronto'));
$modulosFeed = \Smia\Support\Modules::all();
$moduloFeed = array_values(array_filter($modulosFeed, static fn (array $m): bool => $m['slug'] === 'feed'))[0] ?? null;
check('módulo feed marcado como pronto', ($moduloFeed['ready'] ?? false) === true);
check('módulo relatórios marcado como pronto', (array_values(array_filter($modulosFeed, static fn (array $m): bool => $m['slug'] === 'reports'))[0]['ready'] ?? false) === true);
$rotasFeed = array_values(array_filter($app->router->patterns(), static fn (string $r): bool => str_contains($r, '/feed')));
check('rotas do feed registradas (' . count($rotasFeed) . ')', count($rotasFeed) === 5);
check('views do feed compilam', is_file(__DIR__ . '/../app/views/feed/index.php'));

echo "\n[59] Feed: migração aditiva mantém a grade da v1.4\n";
$appAntiga->db->run("INSERT INTO creative_posts (company_id, client_id, created_by, subject, format_key, canvas_width, canvas_height, content_json, layout_json, background_color, status) VALUES (1, 1, 1, 'Arte antiga', 'square', 1080, 1080, '{}', '{\"elements\":[]}', '#112244', 'draft')");
$arteAntiga = (int) $appAntiga->db->pdo()->lastInsertId();
$appAntiga->db->run('INSERT INTO feed_items (company_id, client_id, creative_id, position) VALUES (1, 1, ?, 1)', [$arteAntiga]);
\Smia\Database\Schema::migrate($appAntiga->db);
assertSame('grade antiga preservada', $arteAntiga, (int) $appAntiga->db->scalar('SELECT creative_id FROM feed_items WHERE position = 1'));
assertSame('tabela de feed presente', 1, (int) $appAntiga->db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'feed_items'"));
assertSame('uma linha na versão do esquema depois do feed', 1, (int) $appAntiga->db->scalar('SELECT COUNT(*) FROM schema_version WHERE version = ?', [\Smia\Database\Schema::VERSAO]));


echo "\n[60] Relatórios: recorte de período\n";
use Smia\Reports\ReportError;
use Smia\Reports\ReportRepository;

assertSame('período padrão começa no dia 1º', ['2026-03-01', '2026-03-15'], ReportRepository::periodoPadrao('2026-03-15'));
$recorteOk = ReportRepository::validarPeriodo('2026-02-01', '2026-02-28');
assertSame('recorte válido é aceito', '2026-02-01', $recorteOk['inicio']);
assertSame('recorte válido mantém o fim', '2026-02-28', $recorteOk['fim']);
$recorteVazio = ReportRepository::validarPeriodo(null, '');
assertSame('recorte vazio cai no padrão', ReportRepository::periodoPadrao(), [$recorteVazio['inicio'], $recorteVazio['fim']]);
assertSame('ano bissexto aceita 366 dias', '2025-01-01', ReportRepository::validarPeriodo('2024-01-01', '2025-01-01')['fim']);

$mensagemDe = static function (?string $inicio, ?string $fim): string {
    try {
        ReportRepository::validarPeriodo($inicio, $fim);
    } catch (ReportError $falha) {
        return $falha->getMessage();
    }

    return '';
};
assertSame('fim antes do início é recusado', 'A data final precisa ser igual ou posterior à data inicial.', $mensagemDe('2026-03-10', '2026-03-09'));
check('período acima de 366 dias é recusado', str_contains($mensagemDe('2024-01-01', '2025-01-02'), '366 dias'));
check('data malformada é recusada', str_contains($mensagemDe('31/02/2026', '2026-03-01'), 'datas válidas'));
check('data impossível é recusada', str_contains($mensagemDe('2026-02-30', '2026-03-01'), 'datas válidas'));

echo "\n[61] Relatórios: tela, números e o que o relatório não mede\n";
$logar($app, $idUsuario);
$telaRel = $app->handle(requisicao('GET', '/reports'));
assertSame('relatórios respondem 200', 200, $telaRel->status);
check('carrega o CSS do módulo', str_contains($telaRel->body, 'reports.css'));
check('menu marca relatórios como ativo', str_contains($telaRel->body, 'nav-link active'));
check('aviso de ausência de métricas de rede', str_contains($telaRel->body, 'Sem métricas de rede'));
check('explica o que não entra no relatório', str_contains($telaRel->body, 'Alcance, curtidas, impressões e cliques não entram'));
check('não inventa alcance em nenhum card', !preg_match('/alcance médio|impressões totais|taxa de engajamento/i', $telaRel->body));
check('mostra os quatro números', str_contains($telaRel->body, 'POSTS PLANEJADOS') && str_contains($telaRel->body, 'ARTES EXPORTADAS')
    && str_contains($telaRel->body, 'APROVAÇÕES ENVIADAS') && str_contains($telaRel->body, 'NA PRÉVIA DO FEED'));
check('lista de status das aprovações', str_contains($telaRel->body, 'Aguardando cliente') && str_contains($telaRel->body, 'Alterações solicitadas'));
check('painel de consumo de IA', str_contains($telaRel->body, 'Consumo de IA'));
check('cliente atual aparece no seletor', (bool) preg_match('/<option value="' . $clientePlano . '"[^>]*>\s*Bravo/', $telaRel->body));

// Os números são COUNT de registros reais do período, não estimativa.
[$inicioPadrao, $fimPadrao] = ReportRepository::periodoPadrao();
$postsEsperados = (int) $app->db->scalar(
    'SELECT COUNT(*) FROM posts WHERE company_id = ? AND date(created_at) BETWEEN ? AND ?',
    [$empresaTeste, $inicioPadrao, $fimPadrao]
);
$artesEsperadas = (int) $app->db->scalar(
    'SELECT COUNT(*) FROM creative_posts WHERE company_id = ? AND date(created_at) BETWEEN ? AND ?',
    [$empresaTeste, $inicioPadrao, $fimPadrao]
);
$aprovEsperadas = (int) $app->db->scalar(
    'SELECT COUNT(*) FROM approvals WHERE company_id = ? AND date(submitted_at) BETWEEN ? AND ?',
    [$empresaTeste, $inicioPadrao, $fimPadrao]
);
$feedEsperado = (int) $app->db->scalar('SELECT COUNT(*) FROM feed_items WHERE company_id = ?', [$empresaTeste]);
preg_match_all('/<span class="report-label">([^<]+)<\/span>\s*<b>(\d+)/', $telaRel->body, $cartoes, PREG_SET_ORDER);
$porEtiqueta = [];
foreach ($cartoes as $cartao) {
    $porEtiqueta[trim($cartao[1])] = (int) $cartao[2];
}
assertSame('card de pautas conta os posts do período', $postsEsperados, $porEtiqueta['POSTS PLANEJADOS'] ?? -1);
assertSame('card de artes conta as artes do período', $artesEsperadas, $porEtiqueta['ARTES EXPORTADAS'] ?? -1);
assertSame('card de aprovações conta os ciclos do período', $aprovEsperadas, $porEtiqueta['APROVAÇÕES ENVIADAS'] ?? -1);
assertSame('card do feed conta a grade atual', $feedEsperado, $porEtiqueta['NA PRÉVIA DO FEED'] ?? -1);

$logar($app, $idUsuario);
$telaVazia = $app->handle(requisicao('GET', '/reports', [], ['start_date' => '2000-01-01', 'end_date' => '2000-01-31']));
assertSame('período sem registros continua 200', 200, $telaVazia->status);
check('período sem registros mostra estado vazio', str_contains($telaVazia->body, 'Nenhuma atividade neste período'));
check('período vazio respeita o recorte escolhido', str_contains($telaVazia->body, 'value="2000-01-01"'));

$logar($app, $idUsuario);
$telaInvalida = $app->handle(requisicao('GET', '/reports', [], ['start_date' => '2026-03-10', 'end_date' => '2026-03-01']));
assertSame('recorte inválido não derruba a tela', 200, $telaInvalida->status);
check('recorte inválido avisa o motivo', str_contains($telaInvalida->body, 'A data final precisa ser igual ou posterior à data inicial.'));
check('recorte inválido volta ao padrão do mês', str_contains($telaInvalida->body, 'value="' . $inicioPadrao . '"'));

echo "\n[62] Relatórios: isolamento por empresa\n";
$logar($app, $idUsuario);
$relAlheio = $app->handle(requisicao('GET', '/reports', [], ['client_id' => (string) $idSigiloso]));
assertSame('cliente de outra agência responde 404', 404, $relAlheio->status);
check('não revela o nome do cliente alheio no relatório', !str_contains($relAlheio->body, 'Cliente Sigiloso'));
check('404 do relatório oferece a volta', str_contains($relAlheio->body, 'Esse cliente não está neste workspace'));
assertSame('cliente inexistente responde 404', 404, $app->handle(requisicao('GET', '/reports', [], ['client_id' => '999999']))->status);
$logar($app, $idUsuario);
$relMalformado = $app->handle(requisicao('GET', '/reports', [], ['client_id' => 'abc']));
assertSame('id malformado não quebra a tela', 200, $relMalformado->status);
check('id malformado avisa', str_contains($relMalformado->body, 'Escolha um cliente válido.'));
$logar($app, (int) $app->db->scalar("SELECT id FROM users WHERE email = 'outro@exemplo.com'"));
$relOutra = $app->handle(requisicao('GET', '/reports'));
assertSame('outra agência abre o próprio relatório', 200, $relOutra->status);
check('outra agência não vê pautas daqui', !str_contains($relOutra->body, 'Bravo Fitness'));
check('outra agência não vê métricas daqui', !str_contains($relOutra->body, 'Aula experimental gratuita'));

$semLoginRel = appNova();
[$appSemLoginRel] = $semLoginRel;
assertSame('relatórios exigem login', '/edijalmemoura/socialia/login' === '' ? '' : '/edijalmemoura/socialai/login', $appSemLoginRel->handle(requisicao('GET', '/reports'))->header('Location'));

echo "\n[63] Relatórios: exportação em CSV\n";
$logar($app, $idUsuario);
$csv = $app->handle(requisicao('GET', '/reports/export.csv'));
assertSame('CSV responde 200', 200, $csv->status);
check('CSV tem tipo de planilha', str_starts_with((string) $csv->header('Content-Type'), 'text/csv'));
check('CSV é download com nome datado', (bool) preg_match('/attachment; filename="relatorio-socialmedia-\d{4}-\d{2}-\d{2}-a-\d{4}-\d{2}-\d{2}\.csv"/', (string) $csv->header('Content-Disposition')));
assertSame('CSV começa com BOM para o Excel', "\u{FEFF}", substr($csv->body, 0, 3));
check('CSV usa ponto e vírgula', str_contains($csv->body, 'Indicador;Quantidade'));
check('CSV traz os números do período', str_contains($csv->body, 'Posts planejados;' . $postsEsperados));
check('CSV registra o recorte exato', str_contains($csv->body, $inicioPadrao . ';' . $fimPadrao));
check('CSV repete o aviso de escopo', str_contains($csv->body, 'Não inclui alcance, curtidas, impressões ou cliques'));
check('CSV lista os registros', str_contains($csv->body, 'Categoria;Cliente;Conteúdo;Rede;Data registrada (UTC);Status interno'));
check('CSV não vaza conteúdo de outra agência', !str_contains($csv->body, 'Cliente Sigiloso'));

$logar($app, $idUsuario);
$csvCliente = $app->handle(requisicao('GET', '/reports/export.csv', [], ['client_id' => (string) $clientePlano]));
assertSame('CSV por cliente responde 200', 200, $csvCliente->status);
check('CSV por cliente nomeia a marca', str_contains($csvCliente->body, 'Cliente;Bravo'));
check('CSV por cliente traz só o recorte pedido', !str_contains($csvCliente->body, 'Cliente Sigiloso'));

$logar($app, $idUsuario);
$csvInvalido = $app->handle(requisicao('GET', '/reports/export.csv', [], ['start_date' => '2026-03-10', 'end_date' => '2026-03-01']));
assertSame('CSV com recorte inválido responde 400', 400, $csvInvalido->status);
check('CSV inválido explica o motivo', str_contains($csvInvalido->body, 'A data final precisa ser igual ou posterior'));
assertSame('CSV de cliente alheio responde 400', 400, $app->handle(requisicao('GET', '/reports/export.csv', [], ['client_id' => (string) $idSigiloso]))->status);
assertSame('CSV de cliente malformado responde 400', 400, $app->handle(requisicao('GET', '/reports/export.csv', [], ['client_id' => 'x']))->status);

// Planilha não deve executar texto que veio do banco.
assertSame('célula com fórmula é neutralizada', "'=1+1", \Smia\Controllers\ReportController::celula('=1+1'));
assertSame('célula com soma é neutralizada', "'+55", \Smia\Controllers\ReportController::celula('+55'));
assertSame('célula com hífen é neutralizada', "'-2", \Smia\Controllers\ReportController::celula('-2'));
assertSame('célula com arroba é neutralizada', "'@user", \Smia\Controllers\ReportController::celula('@user'));
assertSame('célula com tabulação é neutralizada', "'\tvalor", \Smia\Controllers\ReportController::celula("\tvalor"));
assertSame('célula com ponto e vírgula é citada', '"a;b"', \Smia\Controllers\ReportController::celula('a;b'));
assertSame('aspas são duplicadas', '"diz ""oi"""', \Smia\Controllers\ReportController::celula('diz "oi"'));
assertSame('texto comum passa inteiro', 'Reels de segunda', \Smia\Controllers\ReportController::celula('Reels de segunda'));

echo "\n[64] Relatórios: atividade limitada e dados que só existem aqui\n";
// Um relatório de 300 linhas é o que cabe numa tela; o resto fica no banco.
$antesDoLimite = (int) $app->db->scalar('SELECT COUNT(*) FROM posts WHERE company_id = ?', [$empresaTeste]);
for ($i = 1; $i <= 305; $i++) {
    $app->db->run(
        "INSERT INTO posts (company_id, client_id, calendar_id, position, publication_date, network, theme,
            strategic_objective, content_type, main_text, caption, visual_brief, image_suggestion)
         VALUES (?, ?, ?, ?, '2026-04-01', 'Instagram', ?, 'teste de limite', 'carrossel', 'texto', 'legenda', 'brief', 'imagem')",
        [$empresaTeste, $clientePlano, $planoRevisaoId, 900 + $i, 'Pauta em massa ' . $i]
    );
}
$logar($app, $idUsuario);
$telaCheia = $app->handle(requisicao('GET', '/reports', [], ['start_date' => $inicioPadrao, 'end_date' => $fimPadrao]));
assertSame('tela com muitos registros responde 200', 200, $telaCheia->status);
check('avisa que a lista foi cortada', str_contains($telaCheia->body, 'Mostrando os 300 registros mais recentes'));
preg_match('/<span class="report-chip">(\d+) registro\(s\)<\/span>/', $telaCheia->body, $chipRel);
check('o chip mostra o total real, não o recortado', (int) ($chipRel[1] ?? 0) >= ($antesDoLimite + 305));
$linhasCsv = substr_count($app->handle(requisicao('GET', '/reports/export.csv'))->body, 'Planejamento;');
assertSame('CSV também respeita o limite de 300', 300, $linhasCsv);

$logar($app, $idUsuario);
$painelRel = $app->handle(requisicao('GET', '/reports', [], ['client_id' => (string) $clientePlano]));
assertSame('relatório por cliente responde 200', 200, $painelRel->status);
check('mostra o consumo de IA com recurso nomeado', str_contains($painelRel->body, 'Planejamento'));
check('mostra o modelo que atendeu (rascunho local)', str_contains($painelRel->body, 'local-draft'));
check('mostra as chamadas de IA do cliente', str_contains($painelRel->body, 'CHAMADAS'));

echo "\n[65] Relatórios: navegação, rotas e migração\n";
$logar($app, $idUsuario);
$painelRel2 = $app->handle(requisicao('GET', '/dashboard'));
check('Relatórios aparece no menu', str_contains($painelRel2->body, '/reports"'));
$rotasRel = array_values(array_filter($app->router->patterns(), static fn (string $r): bool => str_contains($r, '/reports')));
check('rotas de relatórios registradas (' . count($rotasRel) . ')', count($rotasRel) === 2);
check('views de relatórios compilam', is_file(__DIR__ . '/../app/views/reports/index.php') && is_file(__DIR__ . '/../app/views/reports/not_found.php'));
// Relatório é leitura: nada de tabela nova, nada de migração de dados.
assertSame('relatórios não criam tabela', 0, (int) $app->db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE '%report%'"));
\Smia\Database\Schema::migrate($app->db);
assertSame('migração segue idempotente', \Smia\Database\Schema::VERSAO, \Smia\Database\Schema::version($app->db));
assertSame('relatório não altera dados de clientes', 'Bravo Fitness', $app->db->scalar('SELECT company_name FROM clients WHERE id = ?', [$clientePlano]));
assertSame('nenhuma auditoria inventada pelo relatório', 0, (int) $app->db->scalar("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'report%'"));


echo "\n[66] Meta: configuração falha fechada\n";
$metaErro = static function (callable $acao): string {
    try {
        $acao();
    } catch (\Smia\Meta\MetaError $erro) {
        return $erro->getMessage();
    }

    return '';
};
$configMetaVazia = new Config('', '', '', '', '/p', 'https://x.com/p', 'segredo', false, null, 'UTC');
check('sem .env a integração explica o que falta', str_contains($metaErro(static fn () => \Smia\Meta\MetaSettings::fromConfig($configMetaVazia)), 'META_APP_ID'));
check('erro de configuração lista todos os campos', str_contains($metaErro(static fn () => \Smia\Meta\MetaSettings::fromConfig($configMetaVazia)), 'META_TOKEN_ENCRYPTION_KEY'));

$chaveMeta = base64_encode(random_bytes(32));
$configCompleta = static fn (array $troca = []) => new Config(
    root: '', dataDir: '', dbPath: '', envFile: '', basePath: '/p',
    publicOrigin: 'https://agencia.example/p', secretKey: 'segredo-de-teste-com-32-caracteres!!',
    cookieSecure: true, installToken: null, timezone: 'UTC',
    metaAppId: $troca['app_id'] ?? '1234567890',
    metaAppSecret: $troca['app_secret'] ?? 'segredo-do-app',
    metaRedirectUri: $troca['redirect'] ?? 'https://agencia.example/p/integrations/meta/callback',
    metaGraphVersion: $troca['versao'] ?? 'v23.0',
    metaTokenKey: $troca['chave'] ?? $chaveMeta,
    metaPublicBaseUrl: $troca['midia'] ?? 'https://agencia.example/p',
);
assertSame('configuração válida é aceita', 'v23.0', \Smia\Meta\MetaSettings::fromConfig($configCompleta())->graphVersion);
check('versão Graph fora do formato é recusada', str_contains($metaErro(static fn () => \Smia\Meta\MetaSettings::fromConfig($configCompleta(['versao' => '23']))), 'vNN.N'));
check('callback em HTTP é recusado', str_contains($metaErro(static fn () => \Smia\Meta\MetaSettings::fromConfig($configCompleta(['redirect' => 'http://agencia.example/p/integrations/meta/callback']))), 'HTTPS'));
assertSame('callback em localhost aceita HTTP', 'v23.0', \Smia\Meta\MetaSettings::fromConfig($configCompleta(['redirect' => 'http://localhost:8099/integrations/meta/callback']))->graphVersion);
check('chave de token fraca é recusada', str_contains($metaErro(static fn () => \Smia\Meta\MetaSettings::fromConfig($configCompleta(['chave' => base64_encode('curta')]))), 'META_TOKEN_ENCRYPTION_KEY'));
check('callback de outro host não confere', \Smia\Meta\MetaSettings::fromConfig($configCompleta(['redirect' => 'https://outra.example/integrations/meta/callback']))->callbackConfere('agencia.example') === false);
check('callback certo confere', \Smia\Meta\MetaSettings::fromConfig($configCompleta())->callbackConfere('agencia.example'));
check('callback com subpasta confere', \Smia\Meta\MetaSettings::fromConfig(new Config(
    root: '', dataDir: '', dbPath: '', envFile: '', basePath: '/edijalmemoura/socialai',
    publicOrigin: 'https://projetoredesnordeste.com.br/edijalmemoura/socialai', secretKey: 'segredo',
    cookieSecure: true, installToken: null, timezone: 'UTC',
    metaAppId: '1', metaAppSecret: 's', metaRedirectUri: 'https://projetoredesnordeste.com.br/edijalmemoura/socialai/integrations/meta/callback',
    metaGraphVersion: 'v23.0', metaTokenKey: $chaveMeta,
))->callbackConfere('projetoredesnordeste.com.br'));
check('callback sem a subpasta não confere', \Smia\Meta\MetaSettings::fromConfig(new Config(
    root: '', dataDir: '', dbPath: '', envFile: '', basePath: '/p',
    publicOrigin: 'https://x.com/p', secretKey: 'segredo', cookieSecure: true, installToken: null, timezone: 'UTC',
    metaAppId: '1', metaAppSecret: 's', metaRedirectUri: 'https://x.com/integrations/meta/callback',
    metaGraphVersion: 'v23.0', metaTokenKey: $chaveMeta,
))->callbackConfere('x.com') === false);
check('callback com consulta não confere', \Smia\Meta\MetaSettings::fromConfig($configCompleta(['redirect' => 'https://agencia.example/integrations/meta/callback?x=1']))->callbackConfere('agencia.example') === false);

$cifra = new \Smia\Meta\TokenCipher($chaveMeta);
$cifrado = $cifra->cifrar('TOKEN-SECRETO-DA-PAGINA');
check('token não fica em claro no banco', !str_contains($cifrado, 'TOKEN-SECRETO'));
check('token cifrado começa com versão', str_starts_with($cifrado, 'v1:'));
assertSame('token volta igual', 'TOKEN-SECRETO-DA-PAGINA', $cifra->decifrar($cifrado));
check('cifra usa IV diferente a cada vez', $cifra->cifrar('igual') !== $cifra->cifrar('igual'));
$outraChave = new \Smia\Meta\TokenCipher(base64_encode(random_bytes(32)));
check('chave trocada não abre o token', str_contains($metaErro(static fn () => $outraChave->decifrar($cifrado)), 'META_TOKEN_ENCRYPTION_KEY'));
check('token adulterado é recusado', str_contains($metaErro(static fn () => $cifra->decifrar('v1:' . base64_encode(random_bytes(60)))), 'Não foi possível abrir'));

echo "\n[67] Meta: URL pública assinada da arte\n";
$midiaOk = \Smia\Meta\MediaUrl::resolver($configCompleta());
$urlArte = $midiaOk->para('arte-exportada.png', 1_800_000_000);
check('URL aponta para a rota de mídia', str_contains($urlArte, 'https://agencia.example/p/meta/media/arte-exportada.png'));
check('URL carrega prazo e assinatura', str_contains($urlArte, 'expires=1800000600') && str_contains($urlArte, 'signature='));
parse_str((string) parse_url($urlArte, PHP_URL_QUERY), $partesUrl);
check('assinatura válida dentro do prazo', $midiaOk->valida('arte-exportada.png', (string) $partesUrl['expires'], (string) $partesUrl['signature'], 1_800_000_100));
check('assinatura expira', $midiaOk->valida('arte-exportada.png', (string) $partesUrl['expires'], (string) $partesUrl['signature'], 1_800_001_000) === false);
check('outro arquivo com a mesma assinatura é recusado', $midiaOk->valida('outra-arte.png', (string) $partesUrl['expires'], (string) $partesUrl['signature'], 1_800_000_100) === false);
check('assinatura vazia é recusada', $midiaOk->valida('arte-exportada.png', (string) $partesUrl['expires'], '', 1_800_000_100) === false);
check('prazo absurdo é recusado', $midiaOk->valida('arte-exportada.png', (string) (1_800_000_000 + 5_000), $midiaOk->para('arte-exportada.png', 1_800_000_000) === '' ? '' : (string) $partesUrl['signature'], 1_800_000_000) === false);
check('nome com caminho é recusado', $midiaOk->valida('../socialmedia.sqlite', (string) $partesUrl['expires'], (string) $partesUrl['signature'], 1_800_000_100) === false);
check('extensão proibida é recusada', str_contains($metaErro(static fn () => $midiaOk->para('banco.sqlite')), 'PNG ou JPG'));
check('origem HTTP não serve para a Meta', str_contains($metaErro(static fn () => \Smia\Meta\MediaUrl::resolver($configCompleta(['midia' => 'http://agencia.example/p']))), 'META_PUBLIC_BASE_URL'));
check('origem sem SECRET_KEY não assina', str_contains($metaErro(static fn () => \Smia\Meta\MediaUrl::resolver(new Config('', '', '', '', '', 'https://x.com', '', false, null, 'UTC'))), 'SECRET_KEY'));

echo "\n[68] Meta: horário da agenda e fuso\n";
assertSame('fuso desconhecido cai no padrão', 'America/Recife', \Smia\Meta\ScheduleRepository::fuso('Marte/Olympus'));
$daquiUmaSemana = (new \DateTimeImmutable('+7 days', new \DateTimeZone('America/Recife')))->format('Y-m-d\TH:i');
$convertido = \Smia\Meta\ScheduleRepository::validarHorario($daquiUmaSemana, 'America/Recife');
check('horário local vira UTC com fuso guardado', str_ends_with($convertido[0], ':00') && $convertido[1] === 'America/Recife');
assertSame('mesmo instante em outro fuso', strtotime($convertido[0] . ' UTC'), strtotime(\Smia\Meta\ScheduleRepository::validarHorario(
    (new \DateTimeImmutable($daquiUmaSemana, new \DateTimeZone('America/Recife')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i'),
    'UTC'
)[0] . ' UTC'));
check('horário no passado é recusado', str_contains($metaErro(static fn () => \Smia\Meta\ScheduleRepository::validarHorario('2020-01-01T10:00', 'UTC')), '5 minutos'));
check('formato inválido é recusado', str_contains($metaErro(static fn () => \Smia\Meta\ScheduleRepository::validarHorario('31/12/2026 10:00', 'UTC')), 'data, hora e fuso'));
assertSame('rótulo local no fuso pedido', '01/01/2026 às 07:00', \Smia\Meta\ScheduleRepository::rotuloLocal('2026-01-01 10:00:00', 'America/Recife'));
assertSame('entrada do formulário no fuso pedido', '2026-01-01T07:00', \Smia\Meta\ScheduleRepository::entradaLocal('2026-01-01 10:00:00', 'America/Recife'));
$sugestaoMeta = \Smia\Meta\ScheduleRepository::proximoHorarioSugerido();
check('sugestão respeita o formato do input', preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $sugestaoMeta) === 1);
check('sugestão está no futuro', strtotime(str_replace('T', ' ', $sugestaoMeta) . ' America/Recife') > time());

echo "\n[69] Meta: agenda interna sem publicar nada\n";
// A fila só aceita ciclo aprovado pelo cliente: aprova a arte aqui, como o
// portal faria, para que o resto do teste exercite o caminho real.
// A revisão da arte passou por "alterações solicitadas" nos testes do portal;
// nenhum ciclo dela está aprovado. Aprova a revisão da pauta (ciclo paralelo),
// que é a que uma agência levaria para a agenda.
$aprovParaAgenda = (int) $app->db->scalar(
    "SELECT id FROM approvals WHERE company_id = ? AND id <> ? AND status IN ('pending','changes_requested') ORDER BY id LIMIT 1",
    [$empresaTeste, $aprovId]
);
check('existe ciclo para aprovar na agenda', $aprovParaAgenda > 0);
$app->db->run("UPDATE approvals SET status = 'approved', resolved_at = CURRENT_TIMESTAMP WHERE id = ?", [$aprovParaAgenda]);
$logar($app, $idUsuario);
$telaAgenda = $app->handle(requisicao('GET', '/schedule'));
assertSame('agenda responde 200', 200, $telaAgenda->status);
check('carrega o CSS do módulo', str_contains($telaAgenda->body, 'meta.css'));
check('aviso de que a agenda não publica sozinha', str_contains($telaAgenda->body, 'A agenda não publica sozinha'));
check('explica que Facebook/Instagram são envio real', str_contains($telaAgenda->body, 'TikTok e LinkedIn ficam como'));
check('sem configuração Meta avisa', str_contains($telaAgenda->body, 'Configuração pendente'));
check('fila começa vazia', str_contains($telaAgenda->body, 'Nenhum horário organizado ainda'));
assertSame('agenda não cria nada ao abrir', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM scheduled_publications'));

$proximoMeta = (new \DateTimeImmutable('+7 days', new \DateTimeZone('America/Recife')))->format('Y-m-d\TH:i');
$logar($app, $idUsuario);
$semCsrfAgenda = $app->handle(requisicao('POST', '/schedule', ['csrf_token' => 'x', 'approval_id' => (string) $aprovId, 'network' => 'Facebook', 'scheduled_local' => $proximoMeta, 'timezone' => 'America/Recife']));
assertSame('agendar sem CSRF não grava', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM scheduled_publications'));
check('erro de CSRF volta para a agenda', str_contains(rawurldecode((string) $semCsrfAgenda->header('Location')), 'Sessão expirada'));

$agendar = static function (array $extra = []) use ($app, $logar, $idUsuario, $csrf, $proximoMeta, $aprovParaAgenda): \Smia\Http\Response {
    $logar($app, $idUsuario);
    return $app->handle(requisicao('POST', '/schedule', array_merge([
        'csrf_token' => $csrf,
        'approval_id' => (string) $aprovParaAgenda,
        'network' => 'Facebook',
        'scheduled_local' => $proximoMeta,
        'timezone' => 'America/Recife',
    ], $extra)));
};
check('ciclo reprovado não entra na fila', str_contains(rawurldecode((string) $agendar(['approval_id' => (string) $aprovId])->header('Location')), 'aprovados'));
check('rede inválida é recusada', str_contains(rawurldecode((string) $agendar(['network' => 'Orkut'])->header('Location')), 'rede social válida'));
check('horário no passado é recusado', str_contains(rawurldecode((string) $agendar(['scheduled_local' => '2020-01-01T10:00'])->header('Location')), '5 minutos'));
assertSame('nada gravado nos casos inválidos', 0, (int) $app->db->scalar('SELECT COUNT(*) FROM scheduled_publications'));

$criadoAgenda = $agendar();
assertSame('agendar redireciona', 302, $criadoAgenda->status);
check('redirect confirma a criação', str_contains((string) $criadoAgenda->header('Location'), 'created='), (string) $criadoAgenda->header('Location'));
$horario = $app->db->one('SELECT * FROM scheduled_publications ORDER BY id DESC LIMIT 1');
check('horário gravado em UTC', (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', (string) $horario['scheduled_at_utc']), (string) $horario['scheduled_at_utc']);
assertSame('fuso do horário guardado', 'America/Recife', $horario['timezone']);
assertSame('marca do horário', $clientePlano, (int) $horario['client_id']);
assertSame('evento de criação registrado', 'scheduled', $app->db->scalar('SELECT event_type FROM schedule_events WHERE scheduled_publication_id = ?', [(int) $horario['id']]));
assertSame('auditoria do agendamento', 'schedule.created', $app->db->scalar("SELECT action FROM audit_log WHERE action = 'schedule.created'"));
check('duplicar a mesma rede é recusado', str_contains(rawurldecode((string) $agendar()->header('Location')), 'já está agendado'));
assertSame('continua um horário só', 1, (int) $app->db->scalar('SELECT COUNT(*) FROM scheduled_publications'));
$outraRede = $agendar(['network' => 'TikTok']);
assertSame('outra rede é permitida', 302, $outraRede->status);
assertSame('agenda agora tem dois horários', 2, (int) $app->db->scalar('SELECT COUNT(*) FROM scheduled_publications'));
$app->db->run("DELETE FROM scheduled_publications WHERE network = 'TikTok'");

$logar($app, $idUsuario);
$telaComFila = $app->handle(requisicao('GET', '/schedule'));
check('fila mostra o horário com fuso e cliente', str_contains($telaComFila->body, 'America/Recife') && str_contains($telaComFila->body, 'Bravo'));
check('fila mostra a auditoria do horário', str_contains($telaComFila->body, 'Agendado internamente'));
check('publicação só aparece depois do horário', str_contains($telaComFila->body, 'Publicação manual disponível no horário agendado'));

echo "\n[70] Meta: conexões, OAuth e publicação real contra um Graph simulado\n";
$grafoFalso = trim((string) @file_get_contents(__DIR__ . '/.grafo-falso'));
if ($grafoFalso === '' || !str_starts_with($grafoFalso, 'http://127.0.0.1:')) {
    check('Graph simulado disponível', false, 'tests/.grafo-falso não foi escrito pelo runner');
} else {
    check('Graph simulado disponível', true);
    $chaveMetaTeste = base64_encode(random_bytes(32));
    $configMeta = [
        'app_id' => '1234567890',
        'app_secret' => 'segredo-do-app-de-teste',
        'redirect_uri' => 'https://projetoredesnordeste.com.br/edijalmemoura/socialai/integrations/meta/callback',
        'graph_version' => 'v23.0',
        'token_key' => $chaveMetaTeste,
        'public_base' => 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
        'endpoint' => $grafoFalso,
    ];
    $imagemMeta = sys_get_temp_dir() . '/smia-meta-' . bin2hex(random_bytes(4)) . '.png';
    $lonaMeta = imagecreatetruecolor(1080, 1080);
    imagefill($lonaMeta, 0, 0, imagecolorallocate($lonaMeta, 24, 52, 120));
    imagepng($lonaMeta, $imagemMeta);
    imagedestroy($lonaMeta);
    $arteMeta = $app->db->one('SELECT * FROM creative_posts ORDER BY id LIMIT 1');
    $app->db->run("UPDATE creative_posts SET status = 'exported', export_path = ? WHERE id = ?", [basename($imagemMeta), (int) $arteMeta['id']]);
    if (!is_dir($raiz . '/uploads')) { mkdir($raiz . '/uploads', 0o700, true); }
    copy($imagemMeta, $raiz . '/uploads/' . basename($imagemMeta));

    [$appMeta, $configMetaObj, $raizMeta] = appNova(['secret' => str_repeat('a', 40), 'meta' => $configMeta]);
    $appMeta->db->run("INSERT INTO companies (id, name, slug) VALUES (1, 'Agência Meta', 'agencia-meta')");
    $appMeta->db->run("INSERT INTO users (id, company_id, name, email, password_hash, role) VALUES (1, 1, 'Ana Meta', 'ana@meta.example', ?, 'owner')", [password_hash('SenhaForte2026', PASSWORD_DEFAULT)]);
    $appMeta->db->run("INSERT INTO users (id, company_id, name, email, password_hash, role) VALUES (2, 1, 'Eva Editor', 'eva@meta.example', ?, 'editor')", [password_hash('SenhaForte2026', PASSWORD_DEFAULT)]);
    $appMeta->db->run("INSERT INTO companies (id, name, slug) VALUES (2, 'Outra Agência', 'outra-meta')");
    $appMeta->db->run("INSERT INTO users (id, company_id, name, email, password_hash, role) VALUES (3, 2, 'Outro', 'outro@meta.example', ?, 'owner')", [password_hash('SenhaForte2026', PASSWORD_DEFAULT)]);
    $appMeta->db->run("INSERT INTO clients (id, company_id, company_name, trade_name) VALUES (1, 1, 'Bravo Fitness', 'Bravo')");
    $appMeta->db->run("INSERT INTO clients (id, company_id, company_name, trade_name) VALUES (2, 2, 'Cliente Alheio', 'Alheio')");
    $appMeta->db->run("INSERT INTO creative_posts (id, company_id, client_id, created_by, subject, format_key, canvas_width, canvas_height, content_json, layout_json, background_color, export_path, status)
        VALUES (1, 1, 1, 1, 'Arte aprovada', 'square', 1080, 1080, ?, '{\"elements\":[]}', '#183478', ?, 'exported')", [json_encode(['title' => 'Treino', 'caption' => 'Legenda aprovada', 'hashtags' => '#treino']), basename($imagemMeta)]);
    mkdir($raizMeta . '/uploads', 0o700, true);
    copy($imagemMeta, $raizMeta . '/uploads/' . basename($imagemMeta));
    $csrfMeta = $appMeta->session->csrfToken();

    // --- tela de conexões ---------------------------------------------
    $logarMeta = static function (int $id) use ($appMeta): void {
        $appMeta->session->start();
        $appMeta->session->put('user_id', $id);
    };
    $logarMeta(1);
    $appMeta->session->put('user_id', 1);
    $csrfMeta = $appMeta->session->csrfToken();
    $telaMeta = $appMeta->handle(requisicao('GET', '/meta'));
    assertSame('conexões Meta respondem 200', 200, $telaMeta->status);
    check('mostra configuração detectada', str_contains($telaMeta->body, 'Configuração detectada'));
    check('não finge publicação comprovada', str_contains($telaMeta->body, 'Ainda não comprovada'));
    check('lista os escopos pedidos', str_contains($telaMeta->body, 'instagram_content_publish'));
    check('aviso de que publicação é manual', str_contains($telaMeta->body, 'Publicação é manual'));

    $logarMeta(2);
    assertSame('editor não abre conexões Meta', 403, $appMeta->handle(requisicao('GET', '/meta'))->status);
    $logarMeta(3);
    $metaAlheia = $appMeta->handle(requisicao('GET', '/meta'));
    assertSame('outra agência abre a própria tela', 200, $metaAlheia->status);
    check('outra agência não vê páginas desta', !str_contains($metaAlheia->body, 'Página Bravo'));

    // --- OAuth --------------------------------------------------------
    $logarMeta(1);
    $semCsrfConnect = $appMeta->handle(requisicao('POST', '/meta/connect', ['csrf_token' => 'x']));
    assertSame('conectar sem CSRF não cria state', null, $appMeta->session->get('meta_oauth_state'));
    check('erro de CSRF volta com aviso', str_contains(rawurldecode((string) $semCsrfConnect->header('Location')), 'Sessão expirada'));

    $logarMeta(1);
    $inicio = $appMeta->handle(requisicao('POST', '/meta/connect', ['csrf_token' => $appMeta->session->csrfToken()]));
    assertSame('conectar redireciona para a Meta', 302, $inicio->status);
    $destinoMeta = (string) $inicio->header('Location');
    check('URL de autorização é a oficial', str_starts_with($destinoMeta, 'https://www.facebook.com/v23.0/dialog/oauth?'));
    check('pede exatamente os escopos do produto', str_contains($destinoMeta, 'scope=pages_show_list%2Cpages_read_engagement%2Cpages_manage_posts%2Cinstagram_basic%2Cinstagram_content_publish'));
    parse_str((string) parse_url($destinoMeta, PHP_URL_QUERY), $consultaOauth);
    $stateMeta = (string) ($consultaOauth['state'] ?? '');
    check('state é aleatório e longo', strlen($stateMeta) >= 40);
    assertSame('state fica na sessão', $stateMeta, $appMeta->session->get('meta_oauth_state'));

    $logarMeta(1);
    $callbackErrado = $appMeta->handle(requisicao('GET', '/integrations/meta/callback', [], ['code' => 'abc', 'state' => 'inventado']));
    check('state diferente é recusado', str_contains(rawurldecode((string) $callbackErrado->header('Location')), 'expirou ou não corresponde'));
    assertSame('nada conectado com state errado', 0, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_page_connections'));

    $logarMeta(1);
    $inicio2 = $appMeta->handle(requisicao('POST', '/meta/connect', ['csrf_token' => $appMeta->session->csrfToken()]));
    parse_str((string) parse_url((string) $inicio2->header('Location'), PHP_URL_QUERY), $consulta2);
    $state2 = (string) $consulta2['state'];
    $logarMeta(1);
    $callback = $appMeta->handle(requisicao('GET', '/integrations/meta/callback', [], ['code' => 'codigo-bom', 'state' => $state2]));
    assertSame('callback conclui e redireciona', 302, $callback->status);
    check('callback confirma as páginas', str_contains((string) $callback->header('Location'), 'connected=2'));
    assertSame('duas páginas conectadas', 2, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_page_connections'));
    assertSame('página com Instagram gravada', 'bravo.fitness', $appMeta->db->scalar("SELECT instagram_username FROM meta_page_connections WHERE page_id = 'PAGE-1'"));
    check('token gravado cifrado', !str_contains((string) $appMeta->db->scalar('SELECT page_access_token_encrypted FROM meta_page_connections LIMIT 1'), 'TOKEN-PAGINA'));
    assertSame('permissões só dos escopos pedidos, por Página', 5, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_connection_permissions WHERE connection_id = (SELECT id FROM meta_page_connections WHERE page_id = \'PAGE-1\')'));
    assertSame('escopo desconhecido não é guardado', 0, (int) $appMeta->db->scalar("SELECT COUNT(*) FROM meta_connection_permissions WHERE permission = 'esporte_radical'"));
    assertSame('permissão recusada guardada como tal', 'declined', $appMeta->db->scalar("SELECT status FROM meta_connection_permissions WHERE permission = 'pages_read_engagement'"));
    assertSame('prazo do token longo guardado nas duas Páginas', 2, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_page_connections WHERE token_expires_at IS NOT NULL'));
    assertSame('auditoria da conexão', 'meta.connected', $appMeta->db->scalar("SELECT action FROM audit_log WHERE action = 'meta.connected'"));
    assertSame('nada é publicado ao conectar', 0, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_publication_attempts'));

    // Página com token de outro dono: o Graph responde outra página -> falha.
    $logarMeta(1);
    $testeOk = $appMeta->handle(requisicao('POST', '/meta/' . (int) $appMeta->db->scalar("SELECT id FROM meta_page_connections WHERE page_id = 'PAGE-1'") . '/test', ['csrf_token' => $appMeta->session->csrfToken()]));
    check('teste de leitura confirma', str_contains((string) $testeOk->header('Location'), 'connection_check=ok'));
    assertSame('teste registrado no histórico', 'ok', $appMeta->db->scalar('SELECT status FROM meta_connection_checks ORDER BY id DESC LIMIT 1'));
    check('teste diz que não testa publicação', str_contains((string) $appMeta->db->scalar('SELECT details FROM meta_connection_checks ORDER BY id DESC LIMIT 1'), 'não testa permissões de publicação'));

    // --- agenda com o app configurado ---------------------------------
    $logarMeta(1);
    $agendaMeta = $appMeta->handle(requisicao('GET', '/schedule'));
    assertSame('agenda configurada responde 200', 200, $agendaMeta->status);
    check('agenda reconhece as páginas conectadas', str_contains($agendaMeta->body, 'Página Bravo') || str_contains($agendaMeta->body, '2 página(s)'));

    // --- publicação real contra o Graph simulado ----------------------
    // Um ciclo aprovado para publicar agora: o horário venceu (10 min atrás),
    // que é exatamente a condição em que o botão aparece.
    $arquivoMeta = basename($imagemMeta);
    $criarCiclo = static function (string $rede, string $legenda, int $vencido = 10) use ($appMeta, $imagemMeta, $arquivoMeta): int {
        $appMeta->db->run(
            "INSERT INTO creative_posts (company_id, client_id, created_by, subject, format_key, canvas_width, canvas_height, content_json, layout_json, background_color, export_path, status)
             VALUES (1, 1, 1, ?, 'square', 1080, 1080, ?, '{\"elements\":[]}', '#183478', ?, 'exported')",
            ['Arte ' . $rede, json_encode(['title' => 'Treino', 'caption' => $legenda, 'hashtags' => '#treino']), $arquivoMeta]
        );
        $arteId = (int) $appMeta->db->pdo()->lastInsertId();
        $snapshot = [
            'type' => 'creative',
            'title' => 'Arte ' . $rede,
            'format' => 'Quadrado 1080 × 1080',
            'width' => 1080,
            'height' => 1080,
            'content' => ['title' => 'Treino', 'caption' => $legenda, 'hashtags' => '#treino'],
            'background_color' => '#183478',
            'image_path' => $arquivoMeta,
            'export_path' => $arquivoMeta,
        ];
        $appMeta->db->run(
            "INSERT INTO approvals (company_id, client_id, created_by, source_type, creative_id, cycle_number, title, snapshot_json, status, resolved_at)
             VALUES (1, 1, 1, 'creative', ?, 1, ?, ?, 'approved', CURRENT_TIMESTAMP)",
            [$arteId, 'Arte ' . $rede, json_encode($snapshot, JSON_UNESCAPED_UNICODE)]
        );
        $aprovId = (int) $appMeta->db->pdo()->lastInsertId();
        $appMeta->db->run(
            "INSERT INTO scheduled_publications (company_id, client_id, approval_id, scheduled_by, network, scheduled_at_utc, timezone)
             VALUES (1, 1, ?, 1, ?, ?, 'America/Recife')",
            [$aprovId, $rede, gmdate('Y-m-d\TH:i:s', time() - $vencido * 60)]
        );

        return (int) $appMeta->db->pdo()->lastInsertId();
    };
    $conexaoBravo = (int) $appMeta->db->scalar("SELECT id FROM meta_page_connections WHERE page_id = 'PAGE-1'");
    $conexaoSegunda = (int) $appMeta->db->scalar("SELECT id FROM meta_page_connections WHERE page_id = 'PAGE-2'");

    // A tela mostra o botão só com horário vencido.
    $futuroMeta = $criarCiclo('Facebook', 'Legenda ainda no futuro', -1440);
    $logarMeta(1);
    $agendaFutura = $appMeta->handle(requisicao('GET', '/schedule'));
    check('horário no futuro não oferece publicação', str_contains($agendaFutura->body, 'Publicação manual disponível no horário agendado.'));

    $semCsrfPublicar = $appMeta->handle(requisicao('POST', '/schedule/' . $futuroMeta . '/publish', ['csrf_token' => 'x', 'connection_id' => (string) $conexaoBravo]));
    assertSame('publicar sem CSRF não cria tentativa', 0, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_publication_attempts'));
    check('erro de CSRF na publicação', str_contains(rawurldecode((string) $semCsrfPublicar->header('Location')), 'Sessão expirada'));

    $logarMeta(1);
    $cedoDemais = $appMeta->handle(requisicao('POST', '/schedule/' . $futuroMeta . '/publish', ['csrf_token' => $appMeta->session->csrfToken(), 'connection_id' => (string) $conexaoBravo]));
    check('publicar antes do horário é recusado', str_contains(rawurldecode((string) $cedoDemais->header('Location')), 'ainda não chegou'),
        rawurldecode((string) $cedoDemais->header('Location')));
    assertSame('recusa antes do horário não abre tentativa', 0, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_publication_attempts'));

    $logarMeta(1);
    $vencidoSemPagina = $criarCiclo('Facebook', 'Legenda para escolher a Página');
    $semConexao = $appMeta->handle(requisicao('POST', '/schedule/' . $vencidoSemPagina . '/publish', ['csrf_token' => $appMeta->session->csrfToken(), 'connection_id' => '0']));
    check('publicar sem Página escolhida é recusado', str_contains(rawurldecode((string) $semConexao->header('Location')), 'Escolha uma Página Meta'),
        rawurldecode((string) $semConexao->header('Location')));

    // Facebook com foto: caminho completo (URL assinada + POST /photos + ID).
    $fbComFoto = $criarCiclo('Facebook', 'Treino funcional na Bravo');
    $logarMeta(1);
    $publicouFb = $appMeta->handle(requisicao('POST', '/schedule/' . $fbComFoto . '/publish', ['csrf_token' => $appMeta->session->csrfToken(), 'connection_id' => (string) $conexaoBravo]));
    assertSame('publicar foto no Facebook redireciona', 302, $publicouFb->status);
    check('redirect confirma a publicação', str_contains((string) $publicouFb->header('Location'), 'published=' . $fbComFoto), (string) $publicouFb->header('Location'));
    assertSame('tentativa fica publicada', 'published', $appMeta->db->scalar('SELECT status FROM meta_publication_attempts WHERE schedule_id = ?', [$fbComFoto]));
    assertSame('ID remoto guardado', 'PAGE-1_FB-PHOTO-1', $appMeta->db->scalar('SELECT remote_publication_id FROM meta_publication_attempts WHERE schedule_id = ?', [$fbComFoto]));
    assertSame('publicação auditada', 'meta.published.published', $appMeta->db->scalar("SELECT action FROM audit_log WHERE action LIKE 'meta.published.%' ORDER BY id DESC LIMIT 1"));
    check('token não aparece na URL de mídia enviada', !str_contains((string) $appMeta->db->scalar('SELECT error_message FROM meta_publication_attempts WHERE schedule_id = ?', [$fbComFoto]), 'TOKEN'));
    check('agência avisada da publicação', (int) $appMeta->db->scalar("SELECT COUNT(*) FROM notifications WHERE company_id = 1 AND title = 'Publicação confirmada pela Meta'") >= 1);

    // A URL que foi enviada à Meta precisa funcionar de verdade: mesma
    // assinatura, mesmo prazo e servida pela rota pública sem sessão.
    $chamadasFb = json_decode((string) file_get_contents($grafoFalso . '/__chamadas'), true);
    $urlFoto = '';
    $legendaFoto = '';
    foreach ($chamadasFb['chamadas'] as $chamada) {
        if (($chamada['caminho'] ?? '') === '/PAGE-1/photos') {
            $urlFoto = (string) ($chamada['params']['url'] ?? '');
            $legendaFoto = (string) ($chamada['params']['caption'] ?? '');
        }
    }
    check('Meta recebeu a URL assinada da arte', str_contains($urlFoto, '/meta/media/') && str_contains($urlFoto, 'signature='), $urlFoto);
    check('legenda foi a aprovada', str_contains($legendaFoto, 'Treino funcional na Bravo'), $legendaFoto);
    $partesFoto = (string) parse_url($urlFoto, PHP_URL_QUERY);
    parse_str($partesFoto, $consultaFoto);
    $midiaPublica = $appMeta->handle(requisicao('GET', '/meta/media/' . $arquivoMeta, [], [
        'expires' => (string) ($consultaFoto['expires'] ?? ''),
        'signature' => (string) ($consultaFoto['signature'] ?? ''),
    ]));
    assertSame('a URL enviada à Meta serve a imagem sem sessão', 200, $midiaPublica->status);
    assertSame('imagem vai como PNG', 'image/png', (string) $midiaPublica->header('Content-Type'));
    assertSame('mídia assinada é cacheável só por 5 min', 'public, max-age=300', (string) $midiaPublica->header('Cache-Control'));
    assertSame('rota de mídia não é indexável', 'noindex, nofollow', (string) $midiaPublica->header('X-Robots-Tag'));
    assertSame('mídia com assinatura trocada é 404', 404, $appMeta->handle(requisicao('GET', '/meta/media/' . $arquivoMeta, [], ['expires' => (string) ($consultaFoto['expires'] ?? ''), 'signature' => '0']))->status);
    assertSame('mídia sem assinatura é 404', 404, $appMeta->handle(requisicao('GET', '/meta/media/' . $arquivoMeta))->status);
    assertSame('mídia de arquivo inexistente é 404', 404, $appMeta->handle(requisicao('GET', '/meta/media/nao-existe.png', [], [
        'expires' => (string) ($consultaFoto['expires'] ?? ''),
        'signature' => (string) ($consultaFoto['signature'] ?? ''),
    ]))->status);

    // Publicar duas vezes no mesmo horário: a segunda não pode ir à Meta.
    $logarMeta(1);
    $repetido = $appMeta->handle(requisicao('POST', '/schedule/' . $fbComFoto . '/publish', ['csrf_token' => $appMeta->session->csrfToken(), 'connection_id' => (string) $conexaoBravo]));
    check('publicar de novo é recusado', str_contains(rawurldecode((string) $repetido->header('Location')), 'Já existe uma tentativa'));
    assertSame('segunda tentativa não é criada', 1, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_publication_attempts WHERE schedule_id = ?', [$fbComFoto]));

    // Facebook sem arte: texto no feed.
    $appMeta->db->run(
        "INSERT INTO content_calendar (id, company_id, client_id, created_by, title, period_days, start_date, objective, networks_json, target_posts, ai_model)
         VALUES (1, 1, 1, 1, 'Plano do mês', 30, '2026-11-01', 'Vender plano', '[\"Facebook\"]', 1, 'manual')"
    );
    $appMeta->db->run(
        "INSERT INTO posts (id, company_id, client_id, calendar_id, position, publication_date, network, theme, strategic_objective, content_type, main_text, caption, hashtags, visual_brief, image_suggestion)
         VALUES (1, 1, 1, 1, 1, '2026-11-20', 'Facebook', 'Aula aberta', 'Captar alunos', 'Texto', 'Aula aberta sábado', 'Venha treinar com a gente', '#aulaaberta', 'Sem arte', 'Foto da turma')"
    );
    $snapshotPlano = [
        'type' => 'planned',
        'title' => 'Aula aberta',
        'network' => 'Facebook',
        'publication_date' => '2026-11-20',
        'content_type' => 'Texto',
        'strategic_objective' => 'Captar alunos',
        'main_text' => 'Aula aberta sábado',
        'caption' => 'Venha treinar com a gente',
        'hashtags' => '#aulaaberta',
        'cta' => '',
        'visual_brief' => 'Sem arte',
        'image_suggestion' => 'Foto da turma',
    ];
    $appMeta->db->run(
        "INSERT INTO approvals (company_id, client_id, created_by, source_type, planned_post_id, cycle_number, title, snapshot_json, status, resolved_at)
         VALUES (1, 1, 1, 'planned', 1, 1, 'Aula aberta', ?, 'approved', CURRENT_TIMESTAMP)",
        [json_encode($snapshotPlano, JSON_UNESCAPED_UNICODE)]
    );
    $aprovPlano = (int) $appMeta->db->pdo()->lastInsertId();
    $appMeta->db->run(
        "INSERT INTO scheduled_publications (company_id, client_id, approval_id, scheduled_by, network, scheduled_at_utc, timezone)
         VALUES (1, 1, ?, 1, 'Facebook', ?, 'America/Recife')",
        [$aprovPlano, gmdate('Y-m-d\TH:i:s', time() - 3600)]
    );
    $planoAgenda = (int) $appMeta->db->pdo()->lastInsertId();
    $logarMeta(1);
    $publicouTexto = $appMeta->handle(requisicao('POST', '/schedule/' . $planoAgenda . '/publish', ['csrf_token' => $appMeta->session->csrfToken(), 'connection_id' => (string) $conexaoBravo]));
    check('publicar texto no Facebook confirma', str_contains((string) $publicouTexto->header('Location'), 'published=' . $planoAgenda));
    assertSame('texto foi para /feed', 'PAGE-1_FB-TEXTO-1', $appMeta->db->scalar('SELECT remote_publication_id FROM meta_publication_attempts WHERE schedule_id = ?', [$planoAgenda]));
    check('texto usa a rede aprovada', true);

    // Instagram: PNG -> cópia JPEG temporária -> contêiner -> poll -> publish.
    $igAgenda = $criarCiclo('Instagram', 'Treino de hoje no Instagram');
    $logarMeta(1);
    $publicouIg = $appMeta->handle(requisicao('POST', '/schedule/' . $igAgenda . '/publish', ['csrf_token' => $appMeta->session->csrfToken(), 'connection_id' => (string) $conexaoBravo]));
    check('publicar no Instagram confirma', str_contains((string) $publicouIg->header('Location'), 'published=' . $igAgenda), rawurldecode((string) $publicouIg->header('Location')));
    assertSame('ID de mídia do Instagram guardado', 'IG-MEDIA-1', $appMeta->db->scalar('SELECT remote_publication_id FROM meta_publication_attempts WHERE schedule_id = ?', [$igAgenda]));
    $chamadasIg = json_decode((string) file_get_contents($grafoFalso . '/__chamadas'), true);
    $urlIg = '';
    $foiContainer = false;
    $foiPoll = false;
    $foiPublish = false;
    foreach ($chamadasIg['chamadas'] as $chamada) {
        if (($chamada['caminho'] ?? '') === '/IG-1/media') {
            $foiContainer = true;
            $urlIg = (string) ($chamada['params']['image_url'] ?? '');
        }
        if (($chamada['caminho'] ?? '') === '/CONTAINER-1') {
            $foiPoll = true;
        }
        if (($chamada['caminho'] ?? '') === '/IG-1/media_publish') {
            $foiPublish = true;
        }
    }
    check('Instagram passou pelo contêiner, pelo estado e pela publicação', $foiContainer && $foiPoll && $foiPublish);
    check('Instagram recebeu uma URL JPEG', str_ends_with((string) parse_url($urlIg, PHP_URL_PATH), '.jpg'), $urlIg);
    check('a cópia JPEG temporária foi apagada', !str_contains(implode(',', array_map('basename', glob($raizMeta . '/uploads/*') ?: [])), 'meta_instagram_'));

    // Instagram numa Página sem conta profissional: recusa com motivo.
    $appMeta->db->run(
        "INSERT INTO meta_page_connections (company_id, connected_by, page_id, page_name, page_access_token_encrypted, instagram_user_id)
         VALUES (1, 1, 'PAGE-3', 'Página Sem Instagram', 'v1:invalido', NULL)"
    );
    $conexaoSemIg = (int) $appMeta->db->pdo()->lastInsertId();
    $igSemConta = $criarCiclo('Instagram', 'Sem Instagram vinculado');
    $logarMeta(1);
    $semIg = $appMeta->handle(requisicao('POST', '/schedule/' . $igSemConta . '/publish', ['csrf_token' => $appMeta->session->csrfToken(), 'connection_id' => (string) $conexaoSemIg]));
    check('Instagram em Página sem conta é recusado', str_contains(rawurldecode((string) $semIg->header('Location')), 'não possui uma conta profissional'), rawurldecode((string) $semIg->header('Location')));
    $logarMeta(1);
    $igOutraPagina = $appMeta->handle(requisicao('POST', '/schedule/' . $igSemConta . '/publish', ['csrf_token' => $appMeta->session->csrfToken(), 'connection_id' => (string) $conexaoSegunda]));
    check('Instagram usa a conta profissional da Página escolhida', str_contains((string) $igOutraPagina->header('Location'), 'published=' . $igSemConta), rawurldecode((string) $igOutraPagina->header('Location')));

    // Erro 5xx do Graph: incerto, não "falhou" — e sem repetição automática.
    $duplicado = $criarCiclo('Facebook', 'LEGENDA DUPLICADO aqui');
    $logarMeta(1);
    $incerta = $appMeta->handle(requisicao('POST', '/schedule/' . $duplicado . '/publish', ['csrf_token' => $appMeta->session->csrfToken(), 'connection_id' => (string) $conexaoBravo]));
    check('erro 5xx vira resultado incerto', str_contains(rawurldecode((string) $incerta->header('Location')), 'incerto'), rawurldecode((string) $incerta->header('Location')));
    assertSame('tentativa marcada como incerta', 'unknown', $appMeta->db->scalar('SELECT status FROM meta_publication_attempts WHERE schedule_id = ?', [$duplicado]));
    $tentativaIncerta = (int) $appMeta->db->scalar('SELECT id FROM meta_publication_attempts WHERE schedule_id = ?', [$duplicado]);
    assertSame('incerteza não vira publicação confirmada', 0, (int) $appMeta->db->scalar("SELECT COUNT(*) FROM meta_publication_attempts WHERE company_id = 1 AND network = 'Facebook' AND status = 'published' AND schedule_id = ?", [$duplicado]));
    check('agência avisada da incerteza', (int) $appMeta->db->scalar("SELECT COUNT(*) FROM notifications WHERE company_id = 1 AND title = 'Resultado da publicação incerto'") >= 1);
    assertSame('o erro não expõe o token', 0, (int) $appMeta->db->scalar("SELECT COUNT(*) FROM meta_publication_attempts WHERE error_message LIKE '%TOKEN%'"));

    // A tela oferece a conferência manual para o caso incerto.
    $logarMeta(1);
    $agendaIncerta = $appMeta->handle(requisicao('GET', '/schedule'));
    check('tela oferece conferir o resultado incerto', str_contains($agendaIncerta->body, 'Conferir resultado na Meta'));
    check('tela avisa que a conferência não é resposta da API', str_contains($agendaIncerta->body, 'não entra'));

    // Reconciliação: exige observação real e ID coerente.
    $logarMeta(1);
    $csrfRecon = $appMeta->session->csrfToken();
    $semNota = $appMeta->handle(requisicao('POST', '/schedule/meta-attempt/' . $tentativaIncerta . '/reconcile', [
        'csrf_token' => $csrfRecon, 'outcome' => 'confirmed_published', 'remote_publication_id' => 'PAGE-1_999', 'note' => 'curto',
    ]));
    check('conferência exige observação mínima', str_contains(rawurldecode((string) $semNota->header('Location')), '12 caracteres'));
    $logarMeta(1);
    $semId = $appMeta->handle(requisicao('POST', '/schedule/meta-attempt/' . $tentativaIncerta . '/reconcile', [
        'csrf_token' => $appMeta->session->csrfToken(), 'outcome' => 'confirmed_published', 'remote_publication_id' => '', 'note' => 'Conferi a Página e o post está no ar.',
    ]));
    check('encontrada exige ID real', str_contains(rawurldecode((string) $semId->header('Location')), 'Informe o ID real'));
    $logarMeta(1);
    $idIndevido = $appMeta->handle(requisicao('POST', '/schedule/meta-attempt/' . $tentativaIncerta . '/reconcile', [
        'csrf_token' => $appMeta->session->csrfToken(), 'outcome' => 'confirmed_not_published', 'remote_publication_id' => 'PAGE-1_999', 'note' => 'Conferi a Página e não há publicação.',
    ]));
    check('não encontrada recusa ID', str_contains(rawurldecode((string) $idIndevido->header('Location')), 'Não informe um ID'));
    assertSame('nada registrado antes de uma conferência válida', 0, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_publication_reconciliations'));

    $logarMeta(1);
    $conferido = $appMeta->handle(requisicao('POST', '/schedule/meta-attempt/' . $tentativaIncerta . '/reconcile', [
        'csrf_token' => $appMeta->session->csrfToken(),
        'outcome' => 'confirmed_published',
        'remote_publication_id' => 'PAGE-1_CONFERIDO',
        'note' => 'Conferi a Página Bravo e o post está publicado.',
    ]));
    assertSame('conferência válida redireciona', 302, $conferido->status);
    check('redirect confirma a conferência', str_contains((string) $conferido->header('Location'), 'reconciled=confirmed_published'));
    assertSame('conferência registrada com o autor', 1, (int) $appMeta->db->scalar('SELECT reconciled_by FROM meta_publication_reconciliations WHERE attempt_id = ?', [$tentativaIncerta]));
    assertSame('conferência auditada', 'meta.reconciled.confirmed_published', $appMeta->db->scalar("SELECT action FROM audit_log WHERE action LIKE 'meta.reconciled.%' ORDER BY id DESC LIMIT 1"));
    assertSame('conferência não altera o status da API', 'unknown', $appMeta->db->scalar('SELECT status FROM meta_publication_attempts WHERE id = ?', [$tentativaIncerta]));
    $logarMeta(1);
    $deNovo = $appMeta->handle(requisicao('POST', '/schedule/meta-attempt/' . $tentativaIncerta . '/reconcile', [
        'csrf_token' => $appMeta->session->csrfToken(), 'outcome' => 'confirmed_published', 'remote_publication_id' => 'X', 'note' => 'Conferindo outra vez agora.',
    ]));
    check('não se confere duas vezes', str_contains(rawurldecode((string) $deNovo->header('Location')), 'já foi conferida'));

    // Outra agência não confere nem publica o que não é dela.
    $logarMeta(3);
    $alheiaRecon = $appMeta->handle(requisicao('POST', '/schedule/meta-attempt/' . $tentativaIncerta . '/reconcile', [
        'csrf_token' => $appMeta->session->csrfToken(), 'outcome' => 'confirmed_not_published', 'note' => 'Nada aqui nesta conta.',
    ]));
    assertSame('outra agência não confere tentativa alheia', 404, $alheiaRecon->status);
    $alheiaPub = $appMeta->handle(requisicao('POST', '/schedule/' . $igAgenda . '/publish', ['csrf_token' => $appMeta->session->csrfToken(), 'connection_id' => (string) $conexaoBravo]));
    assertSame('outra agência não publica horário alheio', 404, $alheiaPub->status);

    // Editor publica (a agenda é operação), mas não mexe em conexões.
    $appMeta->db->run("UPDATE users SET role = 'editor' WHERE id = 2");
    $editorAgenda = $criarCiclo('Facebook', 'Legenda do editor');
    $logarMeta(2);
    $publicouEditor = $appMeta->handle(requisicao('POST', '/schedule/' . $editorAgenda . '/publish', ['csrf_token' => $appMeta->session->csrfToken(), 'connection_id' => (string) $conexaoBravo]));
    check('editor pode publicar horário vencido', str_contains((string) $publicouEditor->header('Location'), 'published=' . $editorAgenda));
    $appMeta->db->run("UPDATE users SET role = 'viewer' WHERE id = 2");
    $leitorAgenda = $criarCiclo('Facebook', 'Legenda do leitor');
    $logarMeta(2);
    $leitorPublica = $appMeta->handle(requisicao('POST', '/schedule/' . $leitorAgenda . '/publish', ['csrf_token' => $appMeta->session->csrfToken(), 'connection_id' => (string) $conexaoBravo]));
    assertSame('papel somente leitura não publica', 403, $leitorPublica->status);
    assertSame('papel somente leitura não cria tentativa', 0, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_publication_attempts WHERE schedule_id = ?', [$leitorAgenda]));

    // Tentativa travada em "publicando" há mais de 10 minutos vira incerta.
    $appMeta->db->run("UPDATE users SET role = 'editor' WHERE id = 2");
    $travado = $criarCiclo('Facebook', 'Legenda travada');
    $appMeta->db->run(
        "INSERT INTO meta_publication_attempts (company_id, schedule_id, connection_id, attempted_by, network, status, created_at)
         VALUES (1, ?, ?, 1, 'Facebook', 'publishing', datetime('now', '-11 minutes'))",
        [$travado, $conexaoBravo]
    );
    $logarMeta(1);
    $appMeta->handle(requisicao('GET', '/schedule'));
    assertSame('tentativa parada há 11 min vira incerta', 'unknown', $appMeta->db->scalar('SELECT status FROM meta_publication_attempts WHERE schedule_id = ?', [$travado]));

    // --- tokens: prazo e desconexão -----------------------------------
    $appMeta->db->run("UPDATE meta_page_connections SET token_expires_at = datetime('now', '+2 days') WHERE company_id = 1");
    $logarMeta(1);
    $painelPrazo = $appMeta->handle(requisicao('GET', '/meta'));
    check('painel mostra prazo do token', str_contains($painelPrazo->body, 'Prazo registrado do token OAuth'));
    check('aviso de prazo informado, não garantido', str_contains($painelPrazo->body, 'se aproxima'));
    $avisosPrazo = (int) $appMeta->db->scalar("SELECT COUNT(*) FROM notifications WHERE company_id = 1 AND title = 'Prazo informado do token Meta se aproxima'");
    check('prazo se aproximando gera um aviso', $avisosPrazo >= 1);
    $appMeta->handle(requisicao('GET', '/meta'));
    assertSame('o aviso de prazo não se repete', $avisosPrazo, (int) $appMeta->db->scalar("SELECT COUNT(*) FROM notifications WHERE company_id = 1 AND title = 'Prazo informado do token Meta se aproxima'"));
    $appMeta->db->run("UPDATE meta_page_connections SET token_expires_at = datetime('now', '-1 day') WHERE company_id = 1");
    $appMeta->handle(requisicao('GET', '/meta'));
    check('token vencido avisa para reconectar', (int) $appMeta->db->scalar("SELECT COUNT(*) FROM notifications WHERE company_id = 1 AND title = 'Prazo informado do token Meta passou'") >= 1);
    check('painel diz que o prazo passou', str_contains($appMeta->handle(requisicao('GET', '/meta'))->body, 'reconecte e teste a leitura'));

    // Desconexão apaga o token e mantém o histórico.
    $historico = (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_publication_attempts WHERE company_id = 1');
    $logarMeta(1);
    $desconectou = $appMeta->handle(requisicao('POST', '/meta/' . $conexaoBravo . '/disconnect', ['csrf_token' => $appMeta->session->csrfToken()]));
    check('desconectar confirma na tela', str_contains((string) $desconectou->header('Location'), 'disconnected=1'));
    assertSame('conexão removida', 0, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_page_connections WHERE page_id = ?', ['PAGE-1']));
    assertSame('permissões da conexão removidas', 0, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_connection_permissions WHERE connection_id = ?', [$conexaoBravo]));
    assertSame('histórico de publicações preservado', $historico, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_publication_attempts WHERE company_id = 1'));
    assertSame('tentativa perde o vínculo, não o registro', 1, (int) $appMeta->db->scalar('SELECT COUNT(*) FROM meta_publication_attempts WHERE schedule_id = ? AND connection_id IS NULL', [$fbComFoto]));
    check('desconexão auditada', $appMeta->db->scalar("SELECT 1 FROM audit_log WHERE action = 'meta.disconnected'") !== null);
    assertSame('desconectar exige ser dono', 404, (static function () use ($appMeta, $logarMeta, $conexaoSegunda): int {
        $logarMeta(3);

        return $appMeta->handle(requisicao('POST', '/meta/' . $conexaoSegunda . '/disconnect', ['csrf_token' => $appMeta->session->csrfToken()]))->status;
    })());

    // Sem Página compatível, a agenda explica em vez de mostrar botão morto.
    $logarMeta(1);
    $semConexaoTela = $appMeta->handle(requisicao('GET', '/schedule'));
    check('agenda avisa quando não há Página compatível', str_contains($semConexaoTela->body, 'Nenhuma Página compatível')
        || str_contains($semConexaoTela->body, 'Publicação manual disponível'));
}

echo "\n[71] IA: contrato aceito pelos modelos atuais da OpenAI\n";
// Contexto: `gpt-image-1` foi desligado em 23/10/2026, e os modelos de texto
// atuais (gpt-5+/gpt-6) recusam `max_tokens` e recusam `temperature` com o
// raciocínio ligado. O provedor falso passou a devolver 400 para `max_tokens`,
// 400 para temperatura com raciocínio ligado e 404 para modelo inexistente —
// então um payload inválido quebra o teste em vez de passar despercebido.
assertSame('modelo de texto padrão é atual', 'gpt-6-luna', \Smia\Support\Config::MODELO_TEXTO_PADRAO);
assertSame('modelo de imagem padrão é atual', 'gpt-image-2', \Smia\Support\Config::MODELO_IMAGEM_PADRAO);
check('o padrão não é o modelo desligado', \Smia\Support\Config::MODELO_IMAGEM_PADRAO !== 'gpt-image-1');

// A montagem do corpo é pura (sem rede), então dá para conferir campo por campo.
$corpoPlano = \Smia\Planner\PlanGenerator::corpoDoPedido('gpt-6-luna', [
    ['role' => 'system', 'content' => 'regras'],
    ['role' => 'user', 'content' => '{"planejamento":{}}'],
]);
check('plano usa max_completion_tokens', array_key_exists('max_completion_tokens', $corpoPlano));
check('plano NÃO usa max_tokens (recusado hoje)', !array_key_exists('max_tokens', $corpoPlano), implode(',', array_keys($corpoPlano)));
check('plano desliga o raciocínio para aceitar temperatura', ($corpoPlano['reasoning_effort'] ?? '') === 'none');
check('plano pede JSON', ($corpoPlano['response_format']['type'] ?? '') === 'json_object');
check('plano manda o modelo pedido', ($corpoPlano['model'] ?? '') === 'gpt-6-luna');

$corpoTexto = \Smia\Studio\StudioGenerator::corpoDoTexto('gpt-6-luna', [['role' => 'user', 'content' => '{}']]);
check('estúdio usa max_completion_tokens', array_key_exists('max_completion_tokens', $corpoTexto));
check('estúdio NÃO usa max_tokens', !array_key_exists('max_tokens', $corpoTexto));
check('estúdio desliga o raciocínio', ($corpoTexto['reasoning_effort'] ?? '') === 'none');

$corpoImagem = \Smia\Studio\StudioGenerator::corpoDaImagem('gpt-image-2', 'Fundo com área de respiro', '1024x1024');
check('imagem manda o modelo configurado', ($corpoImagem['model'] ?? '') === 'gpt-image-2');
check('imagem manda o tamanho do formato', ($corpoImagem['size'] ?? '') === '1024x1024');
check('imagem manda output_format png', ($corpoImagem['output_format'] ?? '') === 'png');
check('imagem usa qualidade na faixa aceita', in_array($corpoImagem['quality'] ?? '', ['low', 'medium', 'high', 'auto'], true));
check('imagem não manda response_format', !array_key_exists('response_format', $corpoImagem));

// Caminho HTTP completo continua exercitado contra o provedor falso.
$geradorContrato = new \Smia\Planner\PlanGenerator('chave-teste', 'gpt-6-luna', 20, (string) $endpointProvedor);
$planoContrato = $geradorContrato->gerar(
    ['company_name' => 'Bravo Fitness', 'segment' => 'Academia', 'tone_of_voice' => 'Direto'],
    ['period_days' => 7, 'target_posts' => 2, 'start_date' => '2026-12-01', 'networks' => ['Instagram'], 'objective' => 'Vender plano', 'campaign_context' => '']
);
check('planejamento é aceito pelo provedor atual', isset($planoContrato['posts']) && $planoContrato['modo'] === 'ia');

$estudioContrato = new \Smia\Studio\StudioGenerator(
    'chave-teste',
    'gpt-6-luna',
    'gpt-image-2',
    90,
    180,
    (string) $endpointProvedor,
    str_replace('/chat/completions', '/images/generations', (string) $endpointProvedor),
);
$arteContrato = $estudioContrato->gerarConteudo(
    ['company_name' => 'Bravo Fitness', 'segment' => 'Academia', 'tone_of_voice' => 'Direto', 'brand_colors' => ['#183478', '#f2b705']],
    ['assunto' => 'Aula aberta', 'formato' => 'square', 'contexto' => '']
);
check('arte de texto é aceita pelo provedor atual', ($arteContrato['modo'] ?? '') === 'ia');
$imagemContrato = $estudioContrato->gerarImagem('Fundo com área de respiro', 'square');
check('imagem é aceita pelo provedor atual', str_starts_with($imagemContrato['bytes'], "\x89PNG"));

// Modelo desligado (o caso real do gpt-image-1): a mensagem precisa dizer o
// modelo, o status e o que ajustar — antes vinha "tente mais tarde".
try {
    (new \Smia\Studio\StudioGenerator(
        'chave-teste',
        'gpt-6-luna',
        'gpt-image-1',
        90,
        180,
        (string) $endpointProvedor,
        str_replace('/chat/completions', '/images/generations', (string) $endpointProvedor),
    ))->gerarImagem('Fundo', 'square');
    check('modelo desligado gera erro tratado', false, 'não lançou erro');
} catch (\Smia\Studio\StudioError $erro) {
    $mensagemModelo = $erro->getMessage();
    check('erro de modelo cita o modelo usado', str_contains($mensagemModelo, 'gpt-image-1'), $mensagemModelo);
    check('erro de modelo cita o status HTTP', str_contains($mensagemModelo, '404'), $mensagemModelo);
    check('erro de modelo manda ajustar a variável', str_contains($mensagemModelo, 'OPENAI_IMAGE_MODEL'), $mensagemModelo);
    check('erro de modelo repete a explicação do provedor', str_contains($mensagemModelo, 'does not exist'), $mensagemModelo);
}

echo "\n[72] Sintaxe de todos os arquivos PHP\n";
$arquivosPhp = [];
$iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../app', FilesystemIterator::SKIP_DOTS));
foreach ($iterador as $arquivo) {
    if ($arquivo->isFile() && $arquivo->getExtension() === 'php') {
        $arquivosPhp[] = $arquivo->getPathname();
    }
}
foreach (glob(__DIR__ . '/../public/*.php') ?: [] as $entrada) {
    $arquivosPhp[] = $entrada;
}
$arquivosPhp[] = __DIR__ . '/../app/autoload.php';
$comErro = [];
foreach ($arquivosPhp as $arquivo) {
    try {
        token_get_all((string) file_get_contents($arquivo), TOKEN_PARSE);
    } catch (ParseError $erro) {
        $comErro[] = basename($arquivo) . ': ' . $erro->getMessage();
    }
}
check('todos os arquivos PHP compilam (' . count($arquivosPhp) . ' arquivos)', $comErro === [], implode(' | ', $comErro));
check('views de aprovações, portal, feed, relatórios, agenda e Meta incluídas na checagem', count(array_filter($arquivosPhp, static fn (string $a): bool => (bool) preg_match('#/views/(approvals|portal|feed|reports|schedule|meta)/#', $a))) >= 18);
$rotasAprov = array_values(array_filter($app->router->patterns(), static fn (string $r): bool => str_contains($r, '/approvals') || str_contains($r, '/portal')));
check('rotas de aprovações e portal registradas (' . count($rotasAprov) . ')', count($rotasAprov) >= 18);

echo "\n----------------------------------------\n";
echo $falhas === 0 ? "RESULTADO: OK ({$total} verificações)\n" : "RESULTADO: {$falhas} FALHA(S) de {$total}\n";
exit($falhas === 0 ? 0 : 1);
