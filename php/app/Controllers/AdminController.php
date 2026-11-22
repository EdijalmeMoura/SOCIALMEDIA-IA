<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Admin\AdminRepository;
use Smia\Admin\Updater;
use Smia\Auth\Authenticator;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Installer\Installer;
use Smia\Support\Config;
use Smia\Support\Session;
use Smia\Support\View;

/**
 * Área do dono: todas as agências e todos os usuários da instalação.
 *
 * É a única parte do produto que atravessa o isolamento por agência, então a
 * checagem de `is_super` fica num método só (`guardar`) e é a primeira linha de
 * toda ação. Não existe rota aqui acessível por owner de agência.
 *
 * Travas que o banco não dá:
 *  - ninguém desativa a própria conta (perderia o acesso que acabou de usar);
 *  - ninguém remove o último dono ativo da instância;
 *  - agência com usuário ativo não é desativada por engano sem aviso.
 */
final class AdminController
{
    public function __construct(
        private readonly Config $config,
        private readonly AdminRepository $admin,
        private readonly Authenticator $auth,
        private readonly Session $session,
        private readonly View $view,
        private readonly ?Updater $updater = null,
    ) {
    }

    public function index(Request $req): Response
    {
        $usuario = $this->auth->user();
        if (($bloqueio = $this->guardar($req, $usuario, false)) !== null) {
            return $bloqueio;
        }

        return $this->render('admin/index', $req, [
            'resumo' => $this->admin->resumo(),
            'agencias' => $this->admin->agencias(),
            'usuarios' => $this->admin->todosUsuarios(),
            'acoes' => $this->admin->ultimasAcoes(15),
            'papeis' => $this->papeis(),
            'atualizacao' => $this->estadoDaAtualizacao($req),
        ]);
    }

    public function createCompany(Request $req): Response
    {
        $usuario = $this->auth->user();
        if (($bloqueio = $this->guardar($req, $usuario, true)) !== null) {
            return $bloqueio;
        }
        $nome = trim((string) $req->input('name', ''));
        if (mb_strlen($nome) < 2) {
            return $this->voltar($req, 'error', 'Informe o nome da agência (pelo menos 2 letras).');
        }
        $email = trim((string) $req->input('owner_email', ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->voltar($req, 'error', 'O e-mail do responsável não é válido.');
        }

        try {
            $id = $this->admin->criarAgencia($nome, $email !== '' ? $email : null, trim((string) $req->input('notes', '')));
        } catch (\Throwable $erro) {
            self::log('falha ao criar agência: ' . self::motivo($erro));
            throw $erro;
        }
        $this->admin->registrar((int) $usuario['id'], $id, 'admin.company_created', $nome);

        return $this->voltar($req, 'success', 'Agência "' . $nome . '" criada. Agora crie o primeiro usuário dela em "Usuários".');
    }

    public function updateCompany(Request $req): Response
    {
        $usuario = $this->auth->user();
        if (($bloqueio = $this->guardar($req, $usuario, true)) !== null) {
            return $bloqueio;
        }
        $id = (int) ($req->input('company_id') ?? 0);
        $agencia = $this->admin->agencia($id);
        if ($agencia === null) {
            return $this->voltar($req, 'error', 'Agência não encontrada. A lista pode ter mudado; recarregue a página.');
        }
        $nome = trim((string) $req->input('name', ''));
        if (mb_strlen($nome) < 2) {
            return $this->voltar($req, 'error', 'O nome da agência precisa ter pelo menos 2 letras.');
        }
        $email = trim((string) $req->input('owner_email', ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->voltar($req, 'error', 'O e-mail do responsável não é válido.');
        }
        $this->admin->atualizarAgencia($id, $nome, $email !== '' ? $email : null, trim((string) $req->input('notes', '')));
        $this->admin->registrar((int) $usuario['id'], $id, 'admin.company_updated', $nome);

        return $this->voltar($req, 'success', 'Dados de "' . $nome . '" atualizados.');
    }

    public function toggleCompany(Request $req): Response
    {
        $usuario = $this->auth->user();
        if (($bloqueio = $this->guardar($req, $usuario, true)) !== null) {
            return $bloqueio;
        }
        $id = (int) ($req->input('company_id') ?? 0);
        $agencia = $this->admin->agencia($id);
        if ($agencia === null) {
            return $this->voltar($req, 'error', 'Agência não encontrada.');
        }
        if ((int) $usuario['company_id'] === $id) {
            return $this->voltar($req, 'error', 'Você não pode desativar a agência à qual a sua conta pertence.');
        }
        $ativar = (int) ($agencia['is_active'] ?? 1) === 0;
        if (!$ativar) {
            $donos = $this->admin->administradoresAtivos($id);
            if ($donos > 0) {
                // Desativar a agência tira o acesso de gente que está usando.
                // A mensagem nomeia o que fazer antes, em vez de só recusar.
                return $this->voltar($req, 'error',
                    'A agência "' . (string) $agencia['name'] . '" ainda tem ' . $donos
                    . ' proprietário(s) ativo(s). Desative esses usuários primeiro (aba Usuários) e depois a agência.');
            }
        }
        $this->admin->alternarAgencia($id, $ativar);
        $this->admin->registrar((int) $usuario['id'], $id, $ativar ? 'admin.company_enabled' : 'admin.company_disabled',
            (string) $agencia['name']);

        return $this->voltar($req, 'success', $ativar
            ? 'Agência "' . (string) $agencia['name'] . '" reativada.'
            : 'Agência "' . (string) $agencia['name'] . '" desativada. Nenhum usuário dela consegue entrar enquanto estiver assim.');
    }

    public function createUser(Request $req): Response
    {
        try {
            return $this->criarUsuarioInterno($req);
        } catch (\Throwable $erro) {
            self::log('createUser falhou: ' . self::motivo($erro));
            throw $erro;
        }
    }

    private function criarUsuarioInterno(Request $req): Response
    {
        $usuario = $this->auth->user();
        if (($bloqueio = $this->guardar($req, $usuario, true)) !== null) {
            return $bloqueio;
        }
        $companyId = (int) ($req->input('company_id') ?? 0);
        if ($this->admin->agencia($companyId) === null) {
            return $this->voltar($req, 'error', 'Escolha a agência do novo usuário.');
        }
        $nome = trim((string) $req->input('name', ''));
        $email = mb_strtolower(trim((string) $req->input('email', '')));
        $papel = (string) $req->input('role', 'editor');
        $senha = (string) $req->input('password', '');
        $super = $req->input('is_super') === '1';

        $erros = [];
        if (mb_strlen($nome) < 2) {
            $erros[] = 'o nome precisa ter pelo menos 2 letras';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $erros[] = 'o e-mail não é válido';
        } elseif ($this->admin->emailEmUso($email)) {
            $erros[] = 'já existe um usuário com esse e-mail';
        }
        if (!array_key_exists($papel, $this->papeis())) {
            $erros[] = 'o papel escolhido não existe';
        }
        if (mb_strlen($senha) < 8) {
            $erros[] = 'a senha precisa ter pelo menos 8 caracteres';
        }
        if ($erros !== []) {
            return $this->voltar($req, 'error', 'Não criei o usuário: ' . implode('; ', $erros) . '.');
        }

        try {
            $id = $this->admin->criarUsuario($companyId, $nome, $email, Installer::hashPassword($senha), $papel, $super);
        } catch (\Throwable $erro) {
            self::log('falha ao criar usuário: ' . self::motivo($erro));
            $this->session->flash('error', 'Não foi possível criar o usuário: ' . self::motivo($erro));
            throw $erro;
        }
        $this->admin->registrar((int) $usuario['id'], $companyId, 'admin.user_created', $nome . ' <' . $email . '>');
        self::log('usuário criado #' . $id);

        return $this->voltar($req, 'success', 'Usuário "' . $nome . '" criado. Envie o e-mail e a senha para ele entrar.');
    }

    public function updateUser(Request $req): Response
    {
        $usuario = $this->auth->user();
        if (($bloqueio = $this->guardar($req, $usuario, true)) !== null) {
            return $bloqueio;
        }
        $id = (int) ($req->input('user_id') ?? 0);
        $alvo = $this->admin->usuario($id);
        if ($alvo === null) {
            return $this->voltar($req, 'error', 'Usuário não encontrado.');
        }
        $nome = trim((string) $req->input('name', ''));
        $email = mb_strtolower(trim((string) $req->input('email', '')));
        $papel = (string) $req->input('role', (string) $alvo['role']);
        $super = $req->input('is_super') === '1';

        $erros = [];
        if (mb_strlen($nome) < 2) {
            $erros[] = 'o nome precisa ter pelo menos 2 letras';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $erros[] = 'o e-mail não é válido';
        } elseif ($this->admin->emailEmUso($email, $id)) {
            $erros[] = 'outro usuário já usa esse e-mail';
        }
        if (!array_key_exists($papel, $this->papeis())) {
            $erros[] = 'o papel escolhido não existe';
        }
        if ($id === (int) $usuario['id'] && !$super && (int) ($usuario['is_super'] ?? 0) === 1) {
            $erros[] = 'você não pode remover o seu próprio acesso de dono';
        }
        if ($erros !== []) {
            return $this->voltar($req, 'error', 'Não salvei: ' . implode('; ', $erros) . '.');
        }

        $this->admin->atualizarUsuario($id, $nome, $email, $papel, $super);
        $this->admin->registrar((int) $usuario['id'], (int) $alvo['company_id'], 'admin.user_updated', $nome . ' <' . $email . '>');

        return $this->voltar($req, 'success', 'Dados de "' . $nome . '" atualizados.');
    }

    public function resetPassword(Request $req): Response
    {
        $usuario = $this->auth->user();
        if (($bloqueio = $this->guardar($req, $usuario, true)) !== null) {
            return $bloqueio;
        }
        $id = (int) ($req->input('user_id') ?? 0);
        $alvo = $this->admin->usuario($id);
        if ($alvo === null) {
            return $this->voltar($req, 'error', 'Usuário não encontrado.');
        }
        $senha = (string) $req->input('password', '');
        if (mb_strlen($senha) < 8) {
            return $this->voltar($req, 'error', 'A senha nova precisa ter pelo menos 8 caracteres. Nada foi alterado.');
        }
        try {
            $this->admin->trocarSenha($id, Installer::hashPassword($senha));
        } catch (\Throwable $erro) {
            self::log('falha ao trocar senha: ' . self::motivo($erro));
            throw $erro;
        }
        $this->admin->registrar((int) $usuario['id'], (int) $alvo['company_id'], 'admin.password_reset', (string) $alvo['email']);

        return $this->voltar($req, 'success', 'Senha de "' . (string) $alvo['name'] . '" trocada. Envie a nova senha para ele.');
    }

    public function toggleUser(Request $req): Response
    {
        try {
            return $this->alternarUsuarioInterno($req);
        } catch (\Throwable $erro) {
            self::log('toggleUser falhou: ' . self::motivo($erro));
            throw $erro;
        }
    }

    private function alternarUsuarioInterno(Request $req): Response
    {
        $usuario = $this->auth->user();
        if (($bloqueio = $this->guardar($req, $usuario, true)) !== null) {
            return $bloqueio;
        }
        $id = (int) ($req->input('user_id') ?? 0);
        $alvo = $this->admin->usuario($id);
        if ($alvo === null) {
            return $this->voltar($req, 'error', 'Usuário não encontrado.');
        }
        if ($id === (int) $usuario['id']) {
            return $this->voltar($req, 'error', 'Você não pode desativar a sua própria conta.');
        }
        $ativar = (int) ($alvo['is_active'] ?? 1) === 0;
        if (!$ativar) {
            if ((int) ($alvo['is_super'] ?? 0) === 1 && $this->admin->totalDonos() <= 1) {
                return $this->voltar($req, 'error', 'Este é o último dono ativo da instância. Promova outra pessoa a dono antes de desativar esta conta.');
            }
            $ehAdministrador = in_array((string) $alvo['role'], ['owner', 'admin'], true)
                || (int) ($alvo['is_super'] ?? 0) === 1;
            if ($ehAdministrador && $this->admin->administradoresAtivos((int) $alvo['company_id']) <= 1) {
                return $this->voltar($req, 'error',
                    'Esse é o único administrador ativo da agência. Crie ou promova outro antes de desativar este, '
                    . 'senão a agência fica sem ninguém que possa gerenciá-la.');
            }
        }
        try {
            $this->admin->alternarUsuario($id, $ativar);
        } catch (\Throwable $erro) {
            self::log('falha ao alternar usuário: ' . self::motivo($erro));
            throw $erro;
        }
        $this->admin->registrar((int) $usuario['id'], (int) $alvo['company_id'],
            $ativar ? 'admin.user_enabled' : 'admin.user_disabled', (string) $alvo['email']);

        return $this->voltar($req, 'success', $ativar
            ? '"' . (string) $alvo['name'] . '" pode entrar de novo.'
            : '"' . (string) $alvo['name'] . '" foi desativado: o login dele passa a ser recusado.');
    }

    /** @return array<string,string> papel => descrição */
    private function papeis(): array
    {
        return [
            'owner' => 'Proprietário da agência — tudo dentro da agência',
            'admin' => 'Administrador — tudo, menos excluir a agência',
            'editor' => 'Editor — cria e edita conteúdo',
            'viewer' => 'Leitor — só consulta',
        ];
    }

    /**
     * Primeira linha de toda ação: precisa estar logado e ser dono da
     * instância. Sem isso, a área atravessaria o isolamento entre agências.
     */
    private function guardar(Request $req, ?array $usuario, bool $escrita): ?Response
    {
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        if ((int) ($usuario['is_super'] ?? 0) !== 1) {
            return $this->render('admin/forbidden', $req, [
                'motivo' => 'Esta área é do proprietário da instalação. Sua conta gerencia apenas a própria agência.',
            ], 403);
        }
        if ($escrita && !$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltar($req, 'error', 'A sessão do navegador venceu. Recarregue a página e envie de novo.');
        }

        return null;
    }

    private function voltar(Request $req, string $tipo, string $mensagem): Response
    {
        $this->session->flash($tipo, $mensagem);

        return Response::redirect($this->config->url('/admin'));
    }

    private static function log(string $mensagem): void
    {
        error_log('[SMIA][admin] ' . $mensagem);
    }

    /** Mensagem curta de uma exceção para a trilha (nunca conteúdo de arquivo). */
    private static function motivo(\Throwable $erro): string
    {
        return $erro::class . ': ' . $erro->getMessage() . ' @ ' . basename($erro->getFile()) . ':' . $erro->getLine();
    }

    /** @param array<string,mixed> $dados */
    private function render(string $view, Request $req, array $dados, int $status = 200): Response
    {
        return $this->view->render($view, $dados + [
            'user' => $this->auth->user(),
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
            'modules' => \Smia\Support\Modules::all(),
            'active' => 'admin',
            'current_path' => $req->path,
        ], $status)->withSecurityHeaders();
    }

    // ==================================================================
    // Atualização do sistema
    // ==================================================================

    /** GET /admin/atualizacao/consultar — pergunta ao GitHub o que existe. */
    public function checkUpdate(Request $req): Response
    {
        $usuario = $this->auth->user();
        if (($bloqueio = $this->guardar($req, $usuario, false)) !== null) {
            return $bloqueio;
        }
        $atualizador = $this->atualizador($req);
        if ($atualizador instanceof Response) {
            return $atualizador;
        }

        $consulta = $atualizador->consultarGitHub();
        $this->session->put('update_check', $consulta);

        if ($consulta['ok'] !== true) {
            $this->session->flash('error', $consulta['erro'] . ' ' . $consulta['dica']);

            return $this->voltarAtualizacao($req);
        }

        $comparacao = $atualizador->comparar((string) $consulta['versao'], $atualizador->versaoAtual());
        if ($comparacao <= 0) {
            $this->session->flash('success', 'Você já está na versão mais nova (v' . $atualizador->versaoAtual() . ').');

            return $this->voltarAtualizacao($req);
        }

        $this->session->flash('success', 'Existe versão nova: v' . $consulta['versao'] . '. Leia as notas e confirme a atualização.');

        return $this->voltarAtualizacao($req);
    }

    /**
     * POST /admin/atualizacao/aplicar — baixa, confere, faz backup e aplica.
     *
     * Falha em qualquer etapa e nada é alterado. O nome do pacote confirmado
     * precisa ser o que foi inspecionado: entre a tela e o clique, o arquivo
     * pode ter mudado.
     */
    public function applyUpdate(Request $req): Response
    {
        $usuario = $this->auth->user();
        if (($bloqueio = $this->guardar($req, $usuario, true)) !== null) {
            return $bloqueio;
        }
        $atualizador = $this->atualizador($req);
        if ($atualizador instanceof Response) {
            return $atualizador;
        }

        $versao = trim((string) $req->input('versao', ''));
        if (preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $versao) !== 1) {
            $this->session->flash('error', 'Não sei qual versão aplicar. Consulte de novo e repita o passo.');

            return $this->voltarAtualizacao($req);
        }
        if ($atualizador->comparar($versao, $atualizador->versaoAtual()) <= 0) {
            $this->session->flash('error', 'A versão ' . $versao . ' não é mais nova que a instalada (v' . $atualizador->versaoAtual() . '). Nada foi alterado.');

            return $this->voltarAtualizacao($req);
        }

        $origem = (string) $req->input('origem', 'github');
        $zip = '';

        if ($origem === 'upload') {
            $enviado = $_FILES['pacote'] ?? [];
            [$zip, $erroUpload] = $this->receberPacote($enviado);
            if ($zip === '') {
                $this->session->flash('error', $erroUpload);

                return $this->voltarAtualizacao($req);
            }
        } else {
            $consulta = $this->session->get('update_check');
            $url = is_array($consulta) ? (string) ($consulta['url'] ?? '') : '';
            if ($url === '') {
                $this->session->flash('error', 'A consulta ao GitHub venceu. Clique em "Verificar versão nova" de novo.');

                return $this->voltarAtualizacao($req);
            }
            $baixado = $atualizador->baixar($url);
            if ($baixado['ok'] !== true) {
                $this->session->flash('error', 'Não consegui baixar o pacote: ' . $baixado['erro'] . ' Você pode enviar o zip pelo painel.');

                return $this->voltarAtualizacao($req);
            }
            $zip = $baixado['caminho'];
        }

        $resultado = $atualizador->aplicar($zip, $versao);

        if ($resultado['ok'] !== true) {
            $this->admin->registrar((int) $usuario['id'], (int) $usuario['company_id'], 'update.failed');
            $this->session->flash('error', $resultado['erro'] . ' ' . $resultado['dica']);

            return $this->voltarAtualizacao($req);
        }

        $atualizador->limparBackups(5);
        $this->admin->registrar((int) $usuario['id'], (int) $usuario['company_id'], 'update.applied');

        $aviso = 'Atualizado para v' . (string) $resultado['versao'] . '. A cópia da versão anterior está em data/backups/' . basename((string) $resultado['backup']) . '.';
        $aviso .= ' Recarregue a página: a migração do banco roda na primeira abertura.';
        $this->session->flash('success', $aviso);

        return $this->voltarAtualizacao($req);
    }

    /** POST /admin/atualizacao/restaurar — volta a cópia escolhida. */
    public function restoreUpdate(Request $req): Response
    {
        $usuario = $this->auth->user();
        if (($bloqueio = $this->guardar($req, $usuario, true)) !== null) {
            return $bloqueio;
        }
        $atualizador = $this->atualizador($req);
        if ($atualizador instanceof Response) {
            return $atualizador;
        }

        $escolhida = basename((string) $req->input('copia', ''));
        $pasta = null;
        if ($escolhida !== '') {
            foreach ($atualizador->backups() as $copia) {
                if ($copia['nome'] === $escolhida) {
                    $pasta = $copia['pasta'];
                    break;
                }
            }
            if ($pasta === null) {
                $this->session->flash('error', 'Não encontrei a cópia "' . $escolhida . '". Nada foi alterado.');

                return $this->voltarAtualizacao($req);
            }
        }

        $resultado = $atualizador->restaurar($pasta);
        if ($resultado['ok'] !== true) {
            $this->admin->registrar((int) $usuario['id'], (int) $usuario['company_id'], 'update.restore_failed');
            $this->session->flash('error', $resultado['erro'] . ' ' . $resultado['dica']);

            return $this->voltarAtualizacao($req);
        }

        $this->admin->registrar((int) $usuario['id'], (int) $usuario['company_id'], 'update.restored');
        $this->session->flash(
            'success',
            'Sistema restaurado para a versão v' . (string) $resultado['versao'] . ', a partir de ' . basename((string) $resultado['de']) . '. '
            . 'O banco NÃO é substituído automaticamente: se precisar dele, o arquivo banco.sqlite está dentro da mesma pasta.'
        );

        return $this->voltarAtualizacao($req);
    }

    /**
     * O que a aba de atualização mostra agora: versão no ar, cópias guardadas e
     * o resultado da última consulta. Não consulta a rede ao abrir a página —
     * abrir a tela não deve depender de internet.
     *
     * @return array<string,mixed>
     */
    private function estadoDaAtualizacao(Request $req): array
    {
        $atualizador = $this->updater;
        if ($atualizador === null) {
            return ['disponivel' => false, 'motivo' => 'Atualização indisponível neste ambiente.', 'atual' => Config::VERSAO, 'copias' => [], 'consulta' => null];
        }

        $consulta = $this->session->get('update_check');
        $podeAtualizar = true;
        $motivos = [];
        foreach (['app', 'public'] as $pasta) {
            $caminho = $this->config->root . '/' . $pasta;
            if (!is_dir($caminho) || !is_writable($caminho)) {
                $podeAtualizar = false;
                $motivos[] = $pasta . '/ precisa de permissão de escrita';
            }
        }
        if (!class_exists('ZipArchive')) {
            $podeAtualizar = false;
            $motivos[] = 'a extensão Zip do PHP está desativada';
        }
        if (!is_writable($this->config->dataDir)) {
            $podeAtualizar = false;
            $motivos[] = 'data/ precisa de permissão de escrita (para a cópia de segurança)';
        }

        return [
            'disponivel' => true,
            'motivo' => implode('; ', $motivos),
            'pode' => $podeAtualizar,
            'atual' => $atualizador->versaoAtual(),
            'consulta' => is_array($consulta) ? $consulta : null,
            'copias' => $atualizador->backups(),
            'tem_curl' => function_exists('curl_init'),
        ];
    }

    private function atualizador(Request $req): Updater|Response
    {
        if ($this->updater === null) {
            return $this->render('admin/forbidden', $req, [
                'motivo' => 'A atualização pelo painel não está disponível nesta instalação.',
            ], 403);
        }

        return $this->updater;
    }

    private function voltarAtualizacao(Request $req): Response
    {
        return Response::redirect($this->config->url('/admin#atualizacao'));
    }

    /**
     * Guarda o zip enviado na pasta temporária.
     *
     * Não usa o Upload de imagens: aqui o arquivo é um zip, com outro limite e
     * outra validação (quem valida de verdade é o Updater::inspecionar).
     *
     * @param array<string,mixed> $enviado
     * @return array{0:string,1:string} caminho (vazio em caso de erro), mensagem
     */
    private function receberPacote(array $enviado): array
    {
        $erro = (int) ($enviado['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($erro === UPLOAD_ERR_NO_FILE) {
            return ['', 'Escolha o arquivo .zip do pacote antes de enviar.'];
        }
        if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) {
            return ['', 'O zip é maior do que o servidor aceita (' . ini_get('upload_max_filesize') . '). Envie direto pelo cPanel ou aumente o limite.'];
        }
        if ($erro !== UPLOAD_ERR_OK) {
            return ['', 'O envio falhou (código ' . $erro . '). Tente de novo.'];
        }
        $tamanho = (int) ($enviado['size'] ?? 0);
        if ($tamanho <= 0 || $tamanho > Updater::TAMANHO_MAXIMO) {
            return ['', 'O arquivo tem ' . round($tamanho / 1024) . ' KB, fora do esperado para um pacote (até 25 MB).'];
        }
        $tmp = (string) ($enviado['tmp_name'] ?? '');
        // `is_uploaded_file` é a garantia de que o caminho veio de um upload de
        // verdade. O harness de teste monta $_FILES à mão (o php-wasm não
        // popula sozinho), então ele liga SMIA_TEST_UPLOADS — em produção a
        // constante não existe e a checagem continua valendo integralmente.
        $veioDeUpload = is_uploaded_file($tmp);
        $ehHarness = defined('SMIA_TEST_UPLOADS') && SMIA_TEST_UPLOADS === true && is_file($tmp);
        if ($tmp === '' || (!$veioDeUpload && !$ehHarness)) {
            return ['', 'O arquivo enviado não chegou ao servidor.'];
        }
        $assinatura = (string) @file_get_contents($tmp, false, null, 0, 4);
        if (!str_starts_with($assinatura, "PK")) {
            return ['', 'O arquivo não é um zip. Escolha o SOCIALMEDIA-IA-php-vX.Y.zip baixado da página de releases.'];
        }

        $pasta = $this->config->dataDir . '/update-tmp';
        if (!is_dir($pasta) && !@mkdir($pasta, 0o755, true) && !is_dir($pasta)) {
            return ['', 'Não consegui criar a pasta temporária em data/. Confira a permissão.'];
        }
        $destino = $pasta . '/enviado-' . bin2hex(random_bytes(4)) . '.zip';
        $moveu = $veioDeUpload ? @move_uploaded_file($tmp, $destino) : @copy($tmp, $destino);
        if (!$moveu) {
            return ['', 'Não consegui guardar o arquivo em data/update-tmp.'];
        }

        return [$destino, ''];
    }
}
