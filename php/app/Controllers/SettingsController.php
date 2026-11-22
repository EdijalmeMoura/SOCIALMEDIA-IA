<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Admin\AdminRepository;
use Smia\Admin\SettingsRepository;
use Smia\Admin\SystemReport;
use Smia\Auth\Authenticator;
use Smia\Http\Request;
use Smia\Installer\Installer;
use Smia\Http\Response;
use Smia\Support\Config;
use Smia\Studio\StudioGenerator;
use Smia\Support\Modules;
use Smia\Support\Session;
use Smia\Support\View;

/**
 * Configurações da instância, em cinco abas.
 *
 * Regras que esta tela respeita:
 *  - a chave entra por aqui e **nunca** sai: nem mascarada, nem com pedaço;
 *  - gravar e testar são ações separadas, e o teste acontece no servidor;
 *  - **todos veem** as abas; só dono/admin altera IA, agência e preferências
 *    (os campos aparecem bloqueados para quem só lê);
 *  - "Minha conta" é sempre da própria pessoa — inclusive trocar a senha.
 */
final class SettingsController
{
    private const PAPEIS_ADMIN = ['owner', 'admin'];

    public function __construct(
        private readonly Config $config,
        private readonly SettingsRepository $settings,
        private readonly AdminRepository $admin,
        private readonly Authenticator $auth,
        private readonly Session $session,
        private readonly View $view,
        private readonly SystemReport $sistema,
        private readonly Installer $instalador,
    ) {
    }

    public function index(Request $req): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }

        $aba = (string) ($req->input('aba') ?? 'ia');

        return $this->render('settings/index', $req, $this->dados($usuario, $aba) + [
            'aba' => $this->abaValida($usuario, $aba) ? $aba : 'ia',
        ], 200, 'settings');
    }

    /** Grava a chave nova. Testar é um passo separado e explícito. */
    public function saveKey(Request $req): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        if (!$this->podeGerenciar($usuario)) {
            return $this->render('settings/index', $req, $this->dados($usuario) + [
                'erro' => 'Somente o proprietário ou um administrador troca a chave.',
            ], 403, 'settings');
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltar($req, 'error', 'A sessão do navegador venceu. Recarregue a página e envie de novo.');
        }

        $chave = trim((string) $req->input('openai_api_key', ''));
        if ($chave === '') {
            return $this->voltar($req, 'error', 'Cole a chave no campo antes de salvar.');
        }
        // Colar a chave com anotação junto é o erro nº1: " #" ou espaço no meio.
        if (str_contains($chave, ' ') || str_contains($chave, "\t") || str_contains($chave, "\n")) {
            return $this->voltar($req, 'error', 'A chave colada tem espaço ou quebra de linha no meio. Copie só o valor, sem comentário junto.');
        }
        if (str_contains($chave, '#')) {
            return $this->voltar($req, 'error', 'A chave colada tem "#". Tudo depois do "#" seria descartado: cole apenas o valor da chave.');
        }
        if (!str_starts_with($chave, 'sk-')) {
            return $this->voltar($req, 'error', 'Isso não parece uma chave da OpenAI: ela começa com "sk-". Confira o que foi copiado.');
        }
        if (strlen($chave) < 40) {
            return $this->voltar($req, 'error', 'A chave está curta demais (' . strlen($chave) . ' caracteres). Copie o valor completo.');
        }
        if (str_starts_with($chave, 'sk-admin-')) {
            return $this->voltar($req, 'error', 'Essa é uma chave de ADMINISTRAÇÃO (sk-admin-): ela não gera conteúdo. Use a chave de service account (sk-svcacct-).');
        }

        $this->settings->gravarChaveIa($chave, (int) $usuario['id']);
        $this->admin->registrar((int) $usuario['id'], (int) $usuario['company_id'], 'settings.ai_key_saved',
            'Chave de IA gravada (' . strlen($chave) . ' caracteres)');

        return $this->voltar($req, 'success', 'Chave gravada. Ela está nos Configurações e tem prioridade sobre o .env. Use "Testar chave agora" para confirmar com o provedor.');
    }

    /** Remove a chave gravada pela tela (volta a valer o .env, se houver). */
    public function forgetKey(Request $req): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        if (!$this->podeGerenciar($usuario)) {
            return $this->voltar($req, 'error', 'Somente o proprietário ou um administrador remove a chave.', 403);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltar($req, 'error', 'A sessão do navegador venceu. Recarregue a página e envie de novo.');
        }
        $this->settings->forget(SettingsRepository::CHAVE_IA);
        $this->admin->registrar((int) $usuario['id'], (int) $usuario['company_id'], 'settings.ai_key_removed');

        return $this->voltar($req, 'success', 'Chave removida deste painel. Se existir uma chave no .env, ela volta a valer.');
    }

    /** Testa no servidor, com 1 token, e mostra o desfecho. Nunca mostra a chave. */
    public function testKey(Request $req): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        if (!$this->podeGerenciar($usuario)) {
            return $this->voltar($req, 'error', 'Somente o proprietário ou um administrador testa a chave.', 403);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltar($req, 'error', 'A sessão do navegador venceu. Recarregue a página e envie de novo.');
        }

        $efetiva = $this->settings->configEfetiva($this->config);
        if (trim($efetiva->openaiKey) === '') {
            $this->session->put('ia_teste', [
                'ok' => false,
                'titulo' => 'Nenhuma chave configurada',
                'detalhe' => 'Cole uma chave acima ou defina OPENAI_API_KEY no .env. Sem chave, o planejamento e o estúdio funcionam em modo rascunho local.',
                'quando' => gmdate('H:i') . ' UTC',
            ]);

            return $this->voltar($req, 'success', 'Teste concluído.');
        }

        [$corpo, $status, $erroRede, $modelo] = $this->chamarProvedor($efetiva);
        [$ok, $detalhe, $estado] = InstallerController::interpretarTesteDeChave($corpo, $status, $erroRede, $modelo);
        $this->session->put('ia_teste', [
            'ok' => $ok,
            'titulo' => $ok ? 'Chave aceita' : ($estado === 'sem teste' ? 'Não deu para testar' : 'Chave recusada'),
            'detalhe' => $detalhe,
            'quando' => gmdate('H:i') . ' UTC',
        ]);
        $this->admin->registrar((int) $usuario['id'], (int) $usuario['company_id'],
            'settings.ai_key_tested', $ok ? 'ok' : $estado);

        return $this->voltar($req, 'success', 'Teste concluído.');
    }

    /** Troca os modelos. Nome de modelo não é segredo, então é campo normal. */
    public function saveModels(Request $req): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }
        if (!$this->podeGerenciar($usuario)) {
            return $this->voltar($req, 'error', 'Somente o proprietário ou um administrador troca os modelos.', 403);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltar($req, 'error', 'A sessão do navegador venceu. Recarregue a página e envie de novo.');
        }
        $texto = trim((string) $req->input('openai_model', ''));
        $imagem = trim((string) $req->input('openai_image_model', ''));
        foreach (['texto' => $texto, 'imagem' => $imagem] as $tipo => $valor) {
            if ($valor !== '' && !preg_match('/^[a-z0-9][a-z0-9.\-]{1,60}$/i', $valor)) {
                return $this->voltar($req, 'error', 'O nome do modelo de ' . $tipo . ' tem caracteres que não existem em nome de modelo.');
            }
        }
        if ($texto !== '') {
            $this->settings->set(SettingsRepository::MODELO_TEXTO, $texto, (int) $usuario['id']);
        }
        if ($imagem !== '') {
            $this->settings->set(SettingsRepository::MODELO_IMAGEM, $imagem, (int) $usuario['id']);
        }
        $this->admin->registrar((int) $usuario['id'], (int) $usuario['company_id'], 'settings.models_saved',
            $texto . ' / ' . $imagem);

        return $this->voltar($req, 'success', 'Modelos atualizados: ' . $texto . ' · ' . $imagem . '.');
    }


    /** Grava nome, razão social, documento e contato da agência. */
    public function saveCompany(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeGerenciar($usuario)) {
            return $this->voltar($req, 'error', 'Somente o proprietário ou um administrador muda os dados da agência.', 403, 'agencia');
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltar($req, 'error', 'A sessão do navegador venceu. Recarregue a página e envie de novo.', 302, 'agencia');
        }

        $nome = trim((string) $req->input('name', ''));
        $email = trim((string) $req->input('contact_email', ''));
        $documento = preg_replace('/\D+/', '', (string) $req->input('document', '')) ?? '';
        $site = trim((string) $req->input('website', ''));

        $erros = [];
        if (mb_strlen($nome) < 2) {
            $erros[] = 'o nome da agência precisa ter pelo menos 2 letras';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $erros[] = 'o e-mail de contato não é válido';
        }
        if ($documento !== '' && !in_array(strlen($documento), [11, 14], true)) {
            $erros[] = 'o CPF/CNPJ precisa ter 11 ou 14 dígitos';
        }
        if ($site !== '' && filter_var($site, FILTER_VALIDATE_URL) === false) {
            $erros[] = 'o site precisa começar com https://';
        }
        if ($erros !== []) {
            return $this->voltar($req, 'error', 'Não salvei: ' . implode('; ', $erros) . '.', 302, 'agencia');
        }

        $this->settings->gravarAgencia((int) $usuario['company_id'], [
            'nome' => $nome,
            'razao' => mb_substr(trim((string) $req->input('legal_name', '')), 0, 160),
            'documento' => $documento,
            'email' => $email,
            'telefone' => mb_substr(trim((string) $req->input('contact_phone', '')), 0, 40),
            'site' => $site,
        ]);
        $this->admin->registrar((int) $usuario['id'], (int) $usuario['company_id'], 'settings.company_saved', $nome);

        return $this->voltar($req, 'success', 'Dados da agência salvos.', 302, 'agencia');
    }

    /** Preferências com que o estúdio já abre preenchido. */
    public function savePreferences(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeGerenciar($usuario)) {
            return $this->voltar($req, 'error', 'Somente o proprietário ou um administrador muda as preferências.', 403, 'preferencias');
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltar($req, 'error', 'A sessão do navegador venceu. Recarregue a página e envie de novo.', 302, 'preferencias');
        }

        $rede = (string) $req->input('default_network', 'instagram');
        $tom = (string) $req->input('default_tone', 'marca');
        $tags = trim((string) $req->input('default_hashtags', ''));
        $assinatura = mb_substr(trim((string) $req->input('default_signature', '')), 0, 100);

        $erros = [];
        if (!isset(StudioGenerator::REDES[$rede])) {
            $erros[] = 'escolha uma rede da lista';
        }
        if (!isset(StudioGenerator::TONS[$tom])) {
            $erros[] = 'escolha um tom da lista';
        }
        // Hashtag sem "#" vira bagunça na legenda: normaliza em vez de recusar.
        $lista = preg_split('/[\s,]+/', $tags) ?: [];
        $limpas = [];
        foreach ($lista as $tag) {
            $tag = ltrim(trim($tag), '#');
            if ($tag !== '' && preg_match('/^[\p{L}\p{N}_]{1,60}$/u', $tag)) {
                $limpas[] = '#' . $tag;
            }
        }
        $limpas = array_values(array_unique($limpas));
        if (count($limpas) > 30) {
            $erros[] = 'use no máximo 30 hashtags padrão';
        }
        if ($erros !== []) {
            return $this->voltar($req, 'error', 'Não salvei: ' . implode('; ', $erros) . '.', 302, 'preferencias');
        }

        $this->settings->gravarPreferencias((int) $usuario['company_id'], [
            'rede' => $rede,
            'tom' => $tom,
            'tags' => implode(' ', $limpas),
            'assinatura' => $assinatura,
        ], (int) $usuario['id']);
        $this->admin->registrar((int) $usuario['id'], (int) $usuario['company_id'], 'settings.preferences_saved',
            StudioGenerator::REDES[$rede]['label'] . ' · ' . StudioGenerator::TONS[$tom]);

        return $this->voltar($req, 'success', 'Preferências salvas: o estúdio já abre com ' . StudioGenerator::REDES[$rede]['label'] . ' e tom ' . mb_strtolower(StudioGenerator::TONS[$tom]) . '.', 302, 'preferencias');
    }

    /** Volta às preferências de fábrica (nada do conteúdo é apagado). */
    public function resetPreferences(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeGerenciar($usuario)) {
            return $this->voltar($req, 'error', 'Somente o proprietário ou um administrador muda as preferências.', 403, 'preferencias');
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltar($req, 'error', 'A sessão do navegador venceu. Recarregue a página e envie de novo.', 302, 'preferencias');
        }
        $this->settings->limparPreferencias((int) $usuario['company_id']);
        $this->admin->registrar((int) $usuario['id'], (int) $usuario['company_id'], 'settings.preferences_reset');

        return $this->voltar($req, 'success', 'Preferências voltaram ao padrão. Suas artes e pautas não foram tocadas.', 302, 'preferencias');
    }

    /**
     * Minha conta: nome, e-mail e senha.
     *
     * É a única parte que qualquer pessoa pode mudar — e só a própria.
     * Trocar a senha exige a senha atual: sem isso, um notebook aberto na
     * mesa vira troca de dono da conta.
     */
    public function saveAccount(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltar($req, 'error', 'A sessão do navegador venceu. Recarregue a página e envie de novo.', 302, 'conta');
        }

        $id = (int) $usuario['id'];
        $nome = trim((string) $req->input('name', ''));
        $email = mb_strtolower(trim((string) $req->input('email', '')));
        $atual = (string) $req->input('current_password', '');
        $nova = (string) $req->input('new_password', '');
        $confirmacao = (string) $req->input('new_password_confirm', '');

        $erros = [];
        if (mb_strlen($nome) < 2) {
            $erros[] = 'o nome precisa ter pelo menos 2 letras';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $erros[] = 'o e-mail não é válido';
        } elseif ($this->admin->emailEmUso($email, $id)) {
            $erros[] = 'já existe outro usuário com esse e-mail';
        }
        if ($erros !== []) {
            return $this->voltar($req, 'error', 'Não salvei: ' . implode('; ', $erros) . '.', 302, 'conta');
        }

        $this->admin->atualizarUsuario($id, $nome, $email, (string) $usuario['role'], (int) ($usuario['is_super'] ?? 0) === 1);
        $mensagem = 'Perfil atualizado.';

        // Só entra na troca de senha quem preencheu a nova senha: deixar a
        // "senha atual" preenchida sozinha não é pedido de troca.
        if ($nova !== '' || $confirmacao !== '') {
            $linha = $this->admin->usuario($id);
            if ($linha === null || !password_verify($atual, (string) $linha['password_hash'])) {
                // O nome/e-mail já foram salvos; a senha não. A mensagem diz
                // exatamente isso para a pessoa não achar que nada aconteceu.
                $this->admin->registrar($id, (int) $usuario['company_id'], 'settings.account_password_refused');

                return $this->voltar($req, 'error', 'Perfil salvo, mas a senha NÃO foi trocada: a senha atual não confere.', 302, 'conta');
            }
            if (mb_strlen($nova) < 8) {
                return $this->voltar($req, 'error', 'Perfil salvo, mas a senha NÃO foi trocada: a nova senha precisa ter pelo menos 8 caracteres.', 302, 'conta');
            }
            if ($nova !== $confirmacao) {
                return $this->voltar($req, 'error', 'Perfil salvo, mas a senha NÃO foi trocada: a confirmação não é igual à nova senha.', 302, 'conta');
            }
            $this->admin->trocarSenha($id, Installer::hashPassword($nova));
            $this->admin->registrar($id, (int) $usuario['company_id'], 'settings.account_password_changed');
            $mensagem = 'Perfil e senha atualizados. Use a nova senha no próximo acesso.';
        }

        return $this->voltar($req, 'success', $mensagem, 302, 'conta');
    }

    /**
     * Baixa uma cópia do banco.
     *
     * Fica atrás de confirmação porque o arquivo tem tudo: clientes, artes,
     * legendas. Só dono/admin baixa.
     */
    public function downloadBackup(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        // Backup do banco inteiro é da instalação, não da agência: só o dono.
        if (!$this->ehDonoInstalacao($usuario)) {
            return $this->voltar($req, 'error', 'O backup do banco inteiro é exclusivo do dono da instalação.', 403, 'sistema');
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltar($req, 'error', 'A sessão do navegador venceu. Recarregue a página e envie de novo.', 302, 'sistema');
        }

        try {
            $arquivo = $this->sistema->copiarBanco();
            $conteudo = (string) file_get_contents($arquivo);
        } catch (\Throwable $erro) {
            error_log('[SMIA][backup] ' . $erro::class . ': ' . $erro->getMessage());

            return $this->voltar($req, 'error', 'Não foi possível gerar o backup: ' . $erro->getMessage(), 302, 'sistema');
        } finally {
            if (isset($arquivo) && is_file($arquivo)) {
                @unlink($arquivo);
            }
        }

        $this->admin->registrar((int) $usuario['id'], (int) $usuario['company_id'], 'settings.backup_downloaded',
            number_format(strlen($conteudo) / 1024, 1, ',', '.') . ' KB');

        return Response::bytes(
            $conteudo,
            'application/vnd.sqlite3'
        )->withHeader('Content-Disposition', 'attachment; filename="' . $this->sistema->nomeDoBackup() . '"')
            ->withSecurityHeaders();
    }

    /** @return array<string,mixed>|Response */
    private function precisaLogin(): array|Response
    {
        $usuario = $this->auth->user();

        return $usuario ?? Response::redirect($this->config->url('/login'));
    }

    /**
     * Chama o provedor com uma requisição mínima.
     *
     * @return array{0:?string,1:int,2:string,3:string} corpo, status, erro, modelo
     */
    private function chamarProvedor(Config $config): array
    {
        $url = trim($config->openaiEndpoint) !== ''
            ? trim($config->openaiEndpoint)
            : 'https://api.openai.com/v1/chat/completions';
        if (!function_exists('curl_init')) {
            return [null, 0, 'A extensão cURL do PHP não está ativa neste servidor.', $config->openaiModel];
        }
        $ch = curl_init($url);
        $opcoes = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $config->openaiModel,
                'max_completion_tokens' => 1,
                'messages' => [['role' => 'user', 'content' => 'ok']],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $config->openaiKey,
                'Content-Type: application/json',
            ],
        ];
        if (str_starts_with(strtolower($url), 'http://')) {
            $opcoes[CURLOPT_SSL_VERIFYPEER] = false;
        }
        curl_setopt_array($ch, $opcoes);
        $resposta = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro = (string) curl_error($ch);
        curl_close($ch);

        return [$resposta === false ? null : (string) $resposta, $status, $erro, $config->openaiModel];
    }

    /**
     * Dados que a tela sempre precisa.
     *
     * A aba de sistema faz leitura de disco, então ela só é montada quando é
     * pedida — abrir Configurações para trocar a chave não precisa varrer
     * pasta nenhuma.
     *
     * @return array<string,mixed>
     */
    private function dados(array $usuario, string $aba = ''): array
    {
        $empresa = (int) ($usuario['company_id'] ?? 0);
        $dados = [
            'pode_gerenciar' => $this->podeGerenciar($usuario),
            'situacao' => $this->settings->situacaoChave($this->config),
            'modelo_texto' => $this->settings->modeloTexto($this->config),
            'modelo_imagem' => $this->settings->modeloImagem($this->config),
            'cofre_disponivel' => $this->settings->cofre()->disponivel(),
            'equipe' => $this->admin->usuarios($empresa),
            'resultado_teste' => $this->session->get('ia_teste'),
            'aviso' => $this->session->get('ia_aviso'),
            'agencia' => $this->settings->agencia($empresa),
            'preferencias' => $this->settings->preferencias($empresa, StudioGenerator::REDES, StudioGenerator::TONS),
            'redes' => StudioGenerator::REDES,
            'tons' => StudioGenerator::TONS,
            'abas_permitidas' => $this->abasPermitidas($usuario),
            'conta' => [
                'nome' => (string) ($usuario['name'] ?? ''),
                'email' => (string) ($usuario['email'] ?? ''),
                'papel' => (string) ($usuario['role'] ?? ''),
                'super' => (int) ($usuario['is_super'] ?? 0) === 1,
            ],
        ];
        if ($aba === 'sistema') {
            $dados['sistema_versoes'] = $this->sistema->versoes();
            $dados['sistema_ambiente'] = $this->sistema->ambiente();
            $dados['sistema_arquivos'] = $this->sistema->arquivos();
            $dados['banco_tamanho'] = is_file($this->config->dbPath) ? (int) filesize($this->config->dbPath) : 0;
        }

        return $dados;
    }

    private function podeGerenciar(array $usuario): bool
    {
        return (int) ($usuario['is_super'] ?? 0) === 1
            || in_array((string) ($usuario['role'] ?? ''), self::PAPEIS_ADMIN, true);
    }

    /** Dono da instalação (`is_super`): a única conta que vê a aba Sistema. */
    private function ehDonoInstalacao(array $usuario): bool
    {
        return (int) ($usuario['is_super'] ?? 0) === 1;
    }

    /**
     * Abas que esta conta pode abrir.
     *
     * A aba Sistema mostra versões dos arquivos, caminhos do servidor e o backup
     * do banco inteiro — é diagnóstico da instalação, não da agência. Por isso
     * ela é exclusiva do dono (`is_super`), como a área `/admin`. As demais
     * abas continuam visíveis para todos (regra da v1.17).
     *
     * @return array<int,string>
     */
    private function abasPermitidas(array $usuario): array
    {
        $abas = ['ia', 'agencia', 'preferencias', 'conta'];
        if ($this->ehDonoInstalacao($usuario)) {
            $abas[] = 'sistema';
        }

        return $abas;
    }

    private function abaValida(array $usuario, string $aba): bool
    {
        return in_array($aba, $this->abasPermitidas($usuario), true);
    }

    /**
     * Volta para a aba certa com a mensagem.
     *
     * O flash e a aba vão no mesmo redirecionamento: sem a aba, salvar a
     * agência jogaria a pessoa de volta na aba de IA e pareceria que nada
     * aconteceu.
     */
    private function voltar(Request $req, string $tipo, string $mensagem, int $status = 302, string $aba = 'ia'): Response
    {
        if ($status !== 302) {
            // Render direto: o flash ainda não foi consumido, então a mensagem
            // aparece uma vez só, na própria página.
            $usuario = $this->auth->user();
            if ($usuario !== null && !$this->abaValida($usuario, $aba)) {
                $aba = 'ia';
            }

            return $this->render('settings/index', $req, $this->dados($usuario ?? [], $aba) + [
                'erro' => $mensagem,
                'aba' => $aba,
            ], $status, 'settings');
        }
        $this->session->flash($tipo, $mensagem);

        return Response::redirect($this->config->url('/settings' . ($aba === 'ia' ? '' : '?aba=' . $aba)));
    }

    /** @param array<string,mixed> $dados */
    private function render(string $view, Request $req, array $dados, int $status = 200, string $ativo = 'settings'): Response
    {
        return $this->view->render($view, $dados + [
            'user' => $this->auth->user(),
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
            'modules' => Modules::all(),
            'active' => $ativo,
            'current_path' => $req->path,
        ], $status)->withSecurityHeaders();
    }
}
