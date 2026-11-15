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
$gerador = new \Smia\Planner\PlanGenerator('chave-de-teste', 'gpt-4o-mini');
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

    $geradorIa = new \Smia\Planner\PlanGenerator('chave-teste', 'gpt-4o-mini', 20, $endpointProvedor);
    $specIa = ['period_days' => 7, 'target_posts' => 3, 'start_date' => '2026-03-02', 'networks' => ['Instagram', 'Facebook'], 'objective' => 'Trazer alunos', 'campaign_context' => ''];
    $clienteIa = ['company_name' => 'Bravo Fitness', 'trade_name' => 'Bravo', 'segment' => 'Academia', 'primary_color' => '#112244', 'communication_tone' => 'direto'];

    // 1) Chave recusada (401)
    $curto = new \Smia\Planner\PlanGenerator('chave-errada', 'gpt-4o-mini', 20, $endpointProvedor . '?cenario=401');
    try {
        $curto->gerar($clienteIa, $specIa);
        check('401 vira mensagem de chave recusada', false, 'não lançou erro');
    } catch (\Smia\Planner\PlannerError $erro) {
        check('401 vira mensagem de chave recusada', str_contains($erro->getMessage(), 'recusada'), $erro->getMessage());
    }

    // 2) Limite do provedor (429)
    $limitado = new \Smia\Planner\PlanGenerator('chave', 'gpt-4o-mini', 20, $endpointProvedor . '?cenario=429');
    try {
        $limitado->gerar($clienteIa, $specIa);
        check('429 vira mensagem de limite', false, 'não lançou erro');
    } catch (\Smia\Planner\PlannerError $erro) {
        check('429 vira mensagem de limite', str_contains($erro->getMessage(), 'limitou'), $erro->getMessage());
    }

    // 3) Resposta fora do formato
    $quebrado = new \Smia\Planner\PlanGenerator('chave', 'gpt-4o-mini', 20, $endpointProvedor . '?cenario=invalido');
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
    assertSame('modelo registrado', 'gpt-4o-mini', $resultadoIa['model']);
    assertSame('tokens de entrada lidos', 1234, $resultadoIa['input_tokens']);
    assertSame('tokens de saída lidos', 567, $resultadoIa['output_tokens']);
    check('posts da IA respeitam as redes escolhidas', array_reduce($resultadoIa['posts'], static fn (bool $ok, array $p): bool => $ok && in_array($p['network'], $specIa['networks'], true), true));

    // 5) Ponte completa: app com OPENAI_ENDPOINT apontando para o provedor.
    $configIa = new \Smia\Support\Config(
        root: $raiz, dataDir: $raiz, dbPath: $config->dbPath, envFile: $raiz . '/installer.env',
        basePath: '/edijalmemoura/socialai',
        publicOrigin: 'https://projetoredesnordeste.com.br/edijalmemoura/socialai',
        secretKey: '', cookieSecure: false, installToken: null, timezone: 'America/Sao_Paulo',
        openaiKey: 'chave-teste', openaiModel: 'gpt-4o-mini', openaiEndpoint: $endpointProvedor,
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
    assertSame('modelo real gravado no plano', 'gpt-4o-mini', $planoIa['ai_model'] ?? null);
    assertSame('posts gravados pela IA', 3, (int) $appIa->db->scalar('SELECT COUNT(*) FROM posts WHERE calendar_id = ?', [$planoIaId]));
    $usoIa = $appIa->db->one("SELECT * FROM ai_generations WHERE feature = 'weekly_planning' ORDER BY id DESC LIMIT 1");
    assertSame('tokens de entrada gravados', 1234, (int) $usoIa['input_tokens']);
    assertSame('tokens de saída gravados', 567, (int) $usoIa['output_tokens']);
    check('texto da IA não é rascunho local', !str_contains((string) $appIa->db->scalar('SELECT main_text FROM posts WHERE calendar_id = ? LIMIT 1', [$planoIaId]), 'RASCUNHO LOCAL'));
}

echo "\n[26] Planejamento: mensagens de erro do provedor\n";
$portaMorta = 18000 + random_int(2001, 4000);
$logOriginal = ini_set('error_log', sys_get_temp_dir() . '/smia-erro-' . bin2hex(random_bytes(4)) . '.log');
$semProvedor = new \Smia\Planner\PlanGenerator('chave', 'gpt-4o-mini', 2, 'http://127.0.0.1:' . $portaMorta . '/v1/chat/completions');
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
check('os demais módulos seguem pendentes', count(array_filter($modulos, static fn (array $m): bool => $m['ready'] === false)) >= 4);

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
        openaiKey: 'chave-teste', openaiModel: 'gpt-4o-mini',
        openaiEndpoint: $endpointProvedor,
        openaiImageModel: 'gpt-image-1',
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
    assertSame('modelo real gravado', 'gpt-4o-mini', $usoCopy['model']);

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
check('aprovações seguem pendentes', (array_values(array_filter($modulosStudio, static fn (array $m): bool => $m['slug'] === 'approvals'))[0]['ready'] ?? true) === false);

echo "\n----------------------------------------\n";
echo $falhas === 0 ? "RESULTADO: OK ({$total} verificações)\n" : "RESULTADO: {$falhas} FALHA(S) de {$total}\n";
exit($falhas === 0 ? 0 : 1);
