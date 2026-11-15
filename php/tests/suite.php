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

echo "\n----------------------------------------\n";
echo $falhas === 0 ? "RESULTADO: OK ({$total} verificações)\n" : "RESULTADO: {$falhas} FALHA(S) de {$total}\n";
exit($falhas === 0 ? 0 : 1);
