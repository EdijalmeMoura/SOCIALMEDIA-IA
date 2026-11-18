<?php

declare(strict_types=1);

namespace Smia\Meta;

/**
 * Cliente do Meta Graph API — a única parte do produto que fala com a Meta.
 *
 * Regras que valem para todo método daqui:
 *  - o token nunca vai para log, tela ou mensagem de erro;
 *  - erro de rede/timeout é **incerto**: a chamada pode ter chegado;
 *  - erro 5xx também é incerto; 4xx é resposta clara da Meta e é definitivo;
 *  - nada é repetido automaticamente. Publicar duas vezes é pior do que pedir
 *    para a pessoa conferir.
 *
 * O endpoint é injetável para que a suíte teste o caminho de verdade (com um
 * servidor local) sem tocar em graph.facebook.com.
 */
final class Graph
{
    public const HOST = 'https://graph.facebook.com';
    public const LOGIN_HOST = 'https://www.facebook.com';
    public const MAX_PAGINAS = 20;

    public function __construct(
        private readonly MetaSettings $settings,
        private readonly string $endpoint = self::HOST,
        private readonly int $timeout = 20,
    ) {
    }

    /** URL de autorização (o navegador do cliente vai para lá). */
    public function authorizationUrl(string $state): string
    {
        return self::LOGIN_HOST . '/' . $this->settings->graphVersion . '/dialog/oauth?' . http_build_query([
            'client_id' => $this->settings->appId,
            'redirect_uri' => $this->settings->redirectUri,
            'response_type' => 'code',
            'scope' => implode(',', MetaSettings::ESCOPOS),
            'state' => $state,
        ]);
    }

    /** Código de autorização -> token curto do usuário. */
    public function exchangeCode(string $code): array
    {
        if (trim($code) === '') {
            throw new MetaError('O Meta não retornou um código de autorização.');
        }

        return $this->request('GET', 'oauth/access_token', [
            'client_id' => $this->settings->appId,
            'client_secret' => $this->settings->appSecret,
            'redirect_uri' => $this->settings->redirectUri,
            'code' => $code,
        ]);
    }

    /** Token curto -> token longo (60 dias), que é o prazo que a tela mostra. */
    public function exchangeLongLivedToken(string $curto): array
    {
        if (trim($curto) === '') {
            throw new MetaError('O Meta não retornou um token de usuário.');
        }

        return $this->request('GET', 'oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->settings->appId,
            'client_secret' => $this->settings->appSecret,
            'fb_exchange_token' => $curto,
        ]);
    }

    /**
     * Páginas do usuário, com Instagram vinculado e token de Página.
     *
     * Paginação é seguida com limites: cursor repetido ou lista gigante vira
     * erro em vez de laço infinito.
     *
     * @return array<int,array{page_id:string,page_name:string,page_access_token:string,instagram_user_id:?string,instagram_username:?string}>
     */
    public function fetchPages(string $tokenUsuario): array
    {
        $normalizadas = [];
        $vistas = [];
        $cursores = [];
        $after = null;

        for ($pagina = 0; $pagina < self::MAX_PAGINAS; $pagina++) {
            $parametros = ['fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => '100'];
            if ($after !== null) {
                $parametros['after'] = $after;
            }
            $resposta = $this->request('GET', 'me/accounts', $parametros, $tokenUsuario, true);
            $itens = $resposta['data'] ?? [];
            if (!is_array($itens)) {
                throw new MetaError('O Meta não retornou uma lista de páginas válida.');
            }
            foreach ($itens as $item) {
                if (!is_array($item) || empty($item['id']) || empty($item['name']) || empty($item['access_token'])) {
                    continue;
                }
                $pageId = (string) $item['id'];
                if (isset($vistas[$pageId])) {
                    continue;
                }
                $vistas[$pageId] = true;
                $instagram = is_array($item['instagram_business_account'] ?? null) ? $item['instagram_business_account'] : [];
                $normalizadas[] = [
                    'page_id' => $pageId,
                    'page_name' => mb_substr((string) $item['name'], 0, 200),
                    'page_access_token' => (string) $item['access_token'],
                    'instagram_user_id' => trim((string) ($instagram['id'] ?? '')) ?: null,
                    'instagram_username' => mb_substr(trim((string) ($instagram['username'] ?? '')), 0, 120) ?: null,
                ];
            }

            $paging = is_array($resposta['paging'] ?? null) ? $resposta['paging'] : [];
            if (empty($paging['next'])) {
                return $normalizadas;
            }
            $proximo = is_array($paging['cursors'] ?? null) ? (string) ($paging['cursors']['after'] ?? '') : '';
            if ($proximo === '' || isset($cursores[$proximo])) {
                throw new MetaError('O Meta retornou um cursor de paginação ausente ou repetido; a lista de Páginas ficou incompleta.');
            }
            $cursores[$proximo] = true;
            $after = $proximo;
        }

        throw new MetaError('A lista do Meta excedeu o limite seguro de 2.000 Páginas; refine o acesso do usuário e tente novamente.');
    }

    /**
     * Permissões concedidas/recusadas, só os escopos que o produto pede.
     *
     * @return array<string,string> permissão => granted|declined|unknown
     */
    public function fetchPermissions(string $tokenUsuario): array
    {
        $resposta = $this->request('GET', 'me/permissions', ['fields' => 'permission,status'], $tokenUsuario, true);
        $linhas = $resposta['data'] ?? [];
        if (!is_array($linhas)) {
            throw new MetaError('O Meta não retornou uma lista de permissões válida.');
        }
        $permissoes = [];
        foreach ($linhas as $linha) {
            $nome = is_array($linha) ? (string) ($linha['permission'] ?? '') : '';
            if (!in_array($nome, MetaSettings::ESCOPOS, true)) {
                continue;
            }
            $status = strtolower((string) ($linha['status'] ?? 'unknown'));
            $permissoes[$nome] = in_array($status, ['granted', 'declined'], true) ? $status : 'unknown';
        }

        return $permissoes;
    }

    /** Leitura simples da Página: prova que o token abre, não que publica. */
    public function checkPage(string $pageId, string $tokenPagina): array
    {
        $resposta = $this->request('GET', rawurlencode($pageId), ['fields' => 'id,name'], $tokenPagina, true);
        if ((string) ($resposta['id'] ?? '') !== $pageId) {
            throw new MetaError('O Graph API respondeu com uma Página diferente da que está salva nesta conexão.');
        }

        return ['page_id' => $pageId, 'page_name' => mb_substr((string) ($resposta['name'] ?? ''), 0, 200)];
    }

    /**
     * Instagram: cria o contêiner, espera o processamento e publica.
     *
     * O passo do contêiner é o ponto delicado: depois de `media_publish` não
     * há como desfazer, então qualquer resposta ambígua vira erro incerto.
     */
    public function publishInstagram(string $instagramUserId, string $tokenPagina, string $imageUrl, string $legenda): string
    {
        $this->validarImagemPublica($imageUrl, true);
        $conta = rawurlencode($instagramUserId);
        $container = $this->request('POST', $conta . '/media', [
            'image_url' => $imageUrl,
            'caption' => mb_substr($legenda, 0, 2200),
        ], $tokenPagina, true);
        $creationId = (string) ($container['id'] ?? '');
        if ($creationId === '') {
            throw new MetaError('A Meta não retornou o ID do contêiner de imagem.');
        }

        $pronto = false;
        for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
            try {
                $estado = $this->request('GET', rawurlencode($creationId), ['fields' => 'status_code'], $tokenPagina, true, 5);
            } catch (MetaError $erro) {
                throw new MetaError('Não foi possível verificar o processamento do contêiner ' . $creationId . '; a publicação ainda não foi iniciada. ' . $erro->getMessage(), $erro->incerta);
            }
            $codigo = strtoupper((string) ($estado['status_code'] ?? ''));
            if ($codigo === 'FINISHED') {
                $pronto = true;
                break;
            }
            if ($codigo === 'PUBLISHED') {
                throw new MetaError('O contêiner ' . $creationId . ' já aparece como publicado, mas não há ID de mídia confirmado. Confira o Instagram antes de qualquer nova tentativa.', true);
            }
            if (in_array($codigo, ['ERROR', 'EXPIRED'], true)) {
                $detalhe = mb_substr((string) ($estado['status'] ?? ''), 0, 180);

                throw new MetaError('A Meta marcou o contêiner ' . $creationId . ' como ' . $codigo . '. ' . $detalhe . ' A publicação não foi iniciada.');
            }
            if ($codigo !== 'IN_PROGRESS') {
                throw new MetaError('A Meta retornou o estado inesperado "' . ($codigo !== '' ? $codigo : 'vazio') . '" para o contêiner ' . $creationId . '; a publicação não foi iniciada.');
            }
            if ($tentativa < 3) {
                usleep(2_000_000);
            }
        }
        if (!$pronto) {
            throw new MetaError('O contêiner ' . $creationId . ' continuou processando após 3 verificações; a publicação não foi iniciada. Tente novamente mais tarde.');
        }

        $publicado = $this->request('POST', $conta . '/media_publish', ['creation_id' => $creationId], $tokenPagina, true);
        $mediaId = (string) ($publicado['id'] ?? '');
        if ($mediaId === '') {
            throw new MetaError('O resultado da publicação no Instagram é incerto. Confira a conta antes de tentar novamente.', true);
        }

        return $mediaId;
    }

    /** Facebook: foto da Página (quando há arte) ou texto no feed. */
    public function publishFacebookPhoto(string $pageId, string $tokenPagina, string $imageUrl, string $legenda): string
    {
        $this->validarImagemPublica($imageUrl, false);
        $publicado = $this->request('POST', rawurlencode($pageId) . '/photos', [
            'url' => $imageUrl,
            'caption' => mb_substr($legenda, 0, 63206),
        ], $tokenPagina, true);
        $id = (string) ($publicado['post_id'] ?? $publicado['id'] ?? '');
        if ($id === '') {
            throw new MetaError('O resultado da publicação no Facebook é incerto. Confira a Página antes de tentar novamente.', true);
        }

        return $id;
    }

    public function publishFacebookText(string $pageId, string $tokenPagina, string $mensagem): string
    {
        if (trim($mensagem) === '') {
            throw new MetaError('A publicação não tem texto para enviar ao Facebook.');
        }
        $publicado = $this->request('POST', rawurlencode($pageId) . '/feed', [
            'message' => mb_substr($mensagem, 0, 63206),
        ], $tokenPagina, true);
        $id = (string) ($publicado['id'] ?? '');
        if ($id === '') {
            throw new MetaError('O resultado da publicação no Facebook é incerto. Confira a Página antes de tentar novamente.', true);
        }

        return $id;
    }

    /** Handshake TLS com o host do Graph (diagnóstico de saída do servidor). */
    public function tlsVersion(int $timeout = 5): string
    {
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        if ($host === '') {
            throw new MetaError('O endereço do Graph API não tem host válido.');
        }
        $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $conexao = @stream_socket_client('ssl://' . $host . ':443', $codigo, $mensagem, $timeout, STREAM_CLIENT_CONNECT, $contexto);
        if ($conexao === false) {
            throw new MetaError('Não foi possível abrir TLS com ' . $host . '.');
        }
        $meta = stream_get_meta_data($conexao);
        fclose($conexao);

        return (string) ($meta['crypto']['protocol'] ?? 'TLS');
    }

    // ------------------------------------------------------------------

    /**
     * Executa a chamada e devolve o JSON. Tudo que pode dar errado vira
     * MetaError com mensagem pronta — sem token, sem URL completa.
     *
     * @param array<string,string> $parametros
     * @return array<string,mixed>
     */
    private function request(
        string $metodo,
        string $caminho,
        array $parametros = [],
        ?string $token = null,
        bool $provaAppSecret = false,
        ?int $timeout = null,
    ): array {
        if (!function_exists('curl_init')) {
            throw new MetaError('A extensão cURL do PHP não está ativa. Ative em Select PHP Version > Extensions para usar a Meta.');
        }
        if ($token !== null && $token !== '') {
            $parametros['access_token'] = $token;
            if ($provaAppSecret) {
                $parametros['appsecret_proof'] = hash_hmac('sha256', $token, $this->settings->appSecret);
            }
        }

        $url = rtrim($this->endpoint, '/') . '/' . $this->settings->graphVersion . '/' . ltrim($caminho, '/');
        $metodo = strtoupper($metodo);
        $ch = curl_init();
        $opcoes = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout ?? $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if ($metodo === 'GET') {
            if ($parametros !== []) {
                $url .= '?' . http_build_query($parametros);
            }
            $opcoes[CURLOPT_HTTPGET] = true;
        } else {
            $opcoes[CURLOPT_POST] = true;
            $opcoes[CURLOPT_POSTFIELDS] = http_build_query($parametros);
            $opcoes[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $opcoes[CURLOPT_URL] = $url;
        curl_setopt_array($ch, $opcoes);

        $bruto = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroRede = curl_error($ch);
        curl_close($ch);

        if ($bruto === false || $status === 0) {
            // Sem resposta: a chamada pode ter chegado. Nunca repetir sozinho.
            error_log('[SMIA] Meta Graph: falha de rede (' . $erroRede . ')');

            throw new MetaError('Não foi possível alcançar o Meta Graph API deste servidor. O resultado da publicação pode ser incerto; confira a Página/conta antes de tentar novamente.', true);
        }

        $dados = json_decode((string) $bruto, true);
        if (!is_array($dados)) {
            throw new MetaError('O Meta Graph API retornou uma resposta inválida; confira a Página/conta antes de tentar novamente.', $status >= 500);
        }

        $erro = $dados['error'] ?? null;
        if ($status >= 400 || is_array($erro)) {
            $mensagem = is_array($erro) ? (string) ($erro['message'] ?? '') : '';
            $codigo = is_array($erro) ? ($erro['code'] ?? null) : $status;
            $sufixo = $codigo !== null ? ' (código ' . $codigo . ')' : '';
            if ($mensagem === '') {
                $mensagem = 'Resposta de erro do Meta Graph API.';
            }
            if ($status >= 500) {
                throw new MetaError('Meta Graph API' . $sufixo . ': ' . $mensagem, true);
            }

            throw new MetaError('Meta Graph API' . $sufixo . ': ' . $mensagem);
        }

        return $dados;
    }

    private function validarImagemPublica(string $url, bool $exigeJpeg): void
    {
        $partes = parse_url($url);
        if (!is_array($partes) || ($partes['scheme'] ?? '') !== 'https' || empty($partes['host'])
            || isset($partes['user']) || isset($partes['pass'])) {
            throw new MetaError('A imagem precisa ter uma URL HTTPS pública acessível aos servidores da Meta.');
        }
        if ($exigeJpeg) {
            $caminho = strtolower((string) ($partes['path'] ?? ''));
            if (!str_ends_with($caminho, '.jpg') && !str_ends_with($caminho, '.jpeg')) {
                throw new MetaError('O endpoint de imagem do Instagram exige uma URL de arquivo JPEG.');
            }
        }
    }
}
