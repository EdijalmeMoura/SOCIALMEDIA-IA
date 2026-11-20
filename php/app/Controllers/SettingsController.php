<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Admin\AdminRepository;
use Smia\Admin\SettingsRepository;
use Smia\Auth\Authenticator;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Support\Config;
use Smia\Support\Modules;
use Smia\Support\Session;
use Smia\Support\View;

/**
 * Configurações da instância: chave de IA, modelos e equipe.
 *
 * Regras que esta tela respeita:
 *  - a chave entra por aqui e **nunca** sai: nem mascarada, nem com pedaço;
 *  - gravar e testar são ações separadas, e o teste acontece no servidor;
 *  - quem não é dono/admin não vê a chave nem troca modelo.
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
    ) {
    }

    public function index(Request $req): Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }

        return $this->render('settings/index', $req, [
            'pode_gerenciar' => $this->podeGerenciar($usuario),
            'situacao' => $this->settings->situacaoChave($this->config),
            'modelo_texto' => $this->settings->modeloTexto($this->config),
            'modelo_imagem' => $this->settings->modeloImagem($this->config),
            'cofre_disponivel' => $this->settings->cofre()->disponivel(),
            'equipe' => $this->admin->usuarios((int) $usuario['company_id']),
            'resultado_teste' => $this->session->get('ia_teste'),
            'aviso' => $this->session->get('ia_aviso'),
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

    /** @return array<string,mixed> dados que a tela sempre precisa */
    private function dados(array $usuario): array
    {
        return [
            'pode_gerenciar' => $this->podeGerenciar($usuario),
            'situacao' => $this->settings->situacaoChave($this->config),
            'modelo_texto' => $this->settings->modeloTexto($this->config),
            'modelo_imagem' => $this->settings->modeloImagem($this->config),
            'cofre_disponivel' => $this->settings->cofre()->disponivel(),
            'equipe' => $this->admin->usuarios((int) $usuario['company_id']),
            'resultado_teste' => $this->session->get('ia_teste'),
            'aviso' => $this->session->get('ia_aviso'),
        ];
    }

    private function podeGerenciar(array $usuario): bool
    {
        return (int) ($usuario['is_super'] ?? 0) === 1
            || in_array((string) ($usuario['role'] ?? ''), self::PAPEIS_ADMIN, true);
    }

    private function voltar(Request $req, string $tipo, string $mensagem, int $status = 302): Response
    {
        if ($status !== 302) {
            // Render direto: o flash ainda não foi consumido, então a mensagem
            // aparece uma vez só, na própria página.
            $usuario = $this->auth->user();

            return $this->render('settings/index', $req, $this->dados($usuario ?? []) + ['erro' => $mensagem], $status, 'settings');
        }
        $this->session->flash($tipo, $mensagem);

        return Response::redirect($this->config->url('/settings'));
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
