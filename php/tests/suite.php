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

function appNova(array $env = []): array
{
    $raiz = sys_get_temp_dir() . '/smia-teste-' . bin2hex(random_bytes(4));
    mkdir($raiz, 0o700, true);
    $config = new Config(
        root: $raiz,
        dataDir: $raiz,
        dbPath: $raiz . '/socialmedia.sqlite',
        envFile: $raiz . '/installer.env',
        basePath: '/edijalmemoura/socialai',
        publicOrigin: 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
        secretKey: '',
        cookieSecure: true,
        installToken: $env['token'] ?? null,
        timezone: 'America/Sao_Paulo',
    );

    return [new Application($config), $config, $raiz];
}

echo "\n[1] Configuração\n";
assertSame('base path normalizado', '/edijalmemoura/socialai', Config::normalizeBasePath('/edijalmemoura/socialai/'));
assertSame('raiz sem prefixo', '', Config::normalizeBasePath('/'));
assertSame('URL com prefixo', '/edijalmemoura/socialai/install', (new Config('', '', '', '', '/edijalmemoura/socialai', '', '', false, null, 'UTC'))->url('/install'));
assertSame('URL absoluta com subpasta', 'https://x.com/p/install', (new Config('', '', '', '', '/p', 'https://x.com/p', '', false, null, 'UTC'))->absoluteUrl('/install'));

echo "\n[2] .env (fallback, ambiente vence)\n";
$valores = EnvFile::parse("SECRET_KEY=abc\n# comentário\nexport COOKIE_SECURE=1\nMETA_APP_SECRET=\"segredo aqui\"\nMODELO=gpt-4o-mini # barato\n");
assertSame('.env simples', 'abc', $valores['SECRET_KEY']);
assertSame('.env com export', '1', $valores['COOKIE_SECURE']);
assertSame('.env com aspas', 'segredo aqui', $valores['META_APP_SECRET']);
assertSame('.env com comentário no fim', 'gpt-4o-mini', $valores['MODELO']);

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

$execucao = $app->handle(requisicao('POST', '/install/run', $base + ['draft' => $draft]));
assertSame('execução redireciona', 302, $execucao->status);
check('vai para o login', str_starts_with((string) $execucao->header('Location'), '/edijalmemoura/socialai/login'));

check('instalador fechado depois', !$app->installer->needed());
$fechado = $app->handle(requisicao('GET', '/install', [], ['access_token' => 'token-secreto-123']));
assertSame('instalador responde 410', 410, $fechado->status);
check('mostra que está protegida', str_contains($fechado->body, 'protegida'));

check('arquivo de configuração criado', is_file($config->envFile));
$conteudoEnv = (string) file_get_contents($config->envFile);
check('SECRET_KEY gravada', str_contains($conteudoEnv, 'SECRET_KEY='));
check('arquivo com permissão 0600', (fileperms($config->envFile) & 0o777) === 0o600, decoct(fileperms($config->envFile) & 0o777));
$segredos = EnvFile::parse($conteudoEnv);
check('SECRET_KEY tem 64 hex', (bool) preg_match('/^[a-f0-9]{64}$/', (string) ($segredos['SECRET_KEY'] ?? '')));

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

echo "\n----------------------------------------\n";
echo $falhas === 0 ? "RESULTADO: OK ({$total} verificações)\n" : "RESULTADO: {$falhas} FALHA(S) de {$total}\n";
exit($falhas === 0 ? 0 : 1);
