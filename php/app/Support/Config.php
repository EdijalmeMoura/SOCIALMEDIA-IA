<?php

declare(strict_types=1);

namespace Smia\Support;

/**
 * Configuração da instância, resolvida uma única vez no boot.
 *
 * Todos os caminhos são absolutos e nenhum deles fica dentro de public/,
 * que é a única pasta exposta pelo servidor web.
 */
final class Config
{
    /**
     * Modelos padrão. A lista da OpenAI muda e modelo desligado derruba a
     * geração com 404 — por isso os dois ficam aqui, num lugar só, e podem ser
     * trocados sem editar código (OPENAI_MODEL / OPENAI_IMAGE_MODEL).
     *
     * gpt-image-1 foi desligado em 23/10/2026; gpt-image-2 é o substituto.
     */
    /**
     * Versão deste pacote. Aparece no rodapé do instalador porque a pergunta
     * mais comum ao dar erro é "o arquivo que subiu é o novo?".
     */
    public const VERSAO = '1.18';

    public const MODELO_TEXTO_PADRAO = 'gpt-6-luna';
    public const MODELO_IMAGEM_PADRAO = 'gpt-image-2';

    public function __construct(
        public readonly string $root,
        public readonly string $dataDir,
        public readonly string $dbPath,
        public readonly string $envFile,
        public readonly string $basePath,
        public readonly string $publicOrigin,
        public readonly string $secretKey,
        public readonly bool $cookieSecure,
        public readonly ?string $installToken,
        public readonly string $timezone,
        public readonly string $openaiKey = '',
        public readonly string $openaiModel = self::MODELO_TEXTO_PADRAO,
        public readonly string $openaiEndpoint = '',
        public readonly string $openaiImageModel = self::MODELO_IMAGEM_PADRAO,
        public readonly string $openaiImageEndpoint = '',
        public readonly string $metaAppId = '',
        public readonly string $metaAppSecret = '',
        public readonly string $metaRedirectUri = '',
        public readonly string $metaGraphVersion = '',
        public readonly string $metaTokenKey = '',
        public readonly string $metaPublicBaseUrl = '',
        public readonly string $metaGraphEndpoint = '',
        /** Mostra a causa técnica (arquivo:linha) nas telas de erro. */
        public readonly bool $debug = false,
    ) {
    }

    /**
     * Carrega os arquivos de configuração na ordem que o produto promete:
     * variável de ambiente do servidor > `.env` (raiz) > `installer.env`
     * (gravado pelo instalador).
     *
     * Só define o que ainda não existe — por isso a precedência funciona sem
     * precisar de mais nenhuma regra espalhada pelo código.
     */
    public static function loadFiles(string $raiz, string $dataDir): void
    {
        $arquivos = [];
        foreach ([$raiz . '/.env', dirname($raiz) . '/.env'] as $candidato) {
            if (is_file($candidato)) {
                $arquivos[] = $candidato;
                break;
            }
        }
        $arquivos[] = $dataDir . '/installer.env';

        foreach ($arquivos as $arquivo) {
            EnvFile::load($arquivo);
        }
    }

    public static function fromEnvironment(): self
    {
        $root = defined('SMIA_ROOT') ? SMIA_ROOT : dirname(__DIR__, 2);
        $dataDir = self::env('SOCIALMEDIA_DATA') ?: $root . '/data';
        $dbPath = self::env('SOCIALMEDIA_DB') ?: $dataDir . '/socialmedia.sqlite';
        $envFile = self::env('SOCIALMEDIA_INSTALLER_ENV')
            ?: (self::env('SOCIALMEDIA_ENV_FILE') ?: $dataDir . '/installer.env');

        return new self(
            root: $root,
            dataDir: rtrim($dataDir, '/'),
            dbPath: $dbPath,
            envFile: $envFile,
            basePath: self::normalizeBasePath(self::env('SOCIALMEDIA_BASE_PATH')),
            publicOrigin: rtrim(self::env('SOCIALMEDIA_PUBLIC_ORIGIN') ?? '', '/'),
            secretKey: self::env('SECRET_KEY') ?? '',
            cookieSecure: self::env('COOKIE_SECURE') === '1',
            installToken: self::env('SOCIALMEDIA_INSTALL_TOKEN') ?: null,
            timezone: self::env('SOCIALMEDIA_TIMEZONE') ?: 'America/Sao_Paulo',
            openaiKey: self::env('OPENAI_API_KEY') ?? '',
            openaiModel: self::env('OPENAI_MODEL') ?: self::MODELO_TEXTO_PADRAO,
            openaiEndpoint: self::env('OPENAI_ENDPOINT') ?: '',
            openaiImageModel: self::env('OPENAI_IMAGE_MODEL') ?: self::MODELO_IMAGEM_PADRAO,
            openaiImageEndpoint: self::env('OPENAI_IMAGE_ENDPOINT') ?: '',
            metaAppId: self::env('META_APP_ID') ?? '',
            metaAppSecret: self::env('META_APP_SECRET') ?? '',
            metaRedirectUri: self::env('META_REDIRECT_URI') ?? '',
            metaGraphVersion: self::env('META_GRAPH_VERSION') ?: 'v23.0',
            metaTokenKey: self::env('META_TOKEN_ENCRYPTION_KEY') ?? '',
            metaPublicBaseUrl: self::env('META_PUBLIC_BASE_URL') ?? '',
            metaGraphEndpoint: self::env('META_GRAPH_ENDPOINT') ?: \Smia\Meta\Graph::HOST,
            debug: self::env('SOCIALMEDIA_DEBUG') === '1',
        );
    }

    /** Prefixo da subpasta, sempre começando com "/" e sem barra no fim. */
    /**
     * Cópia com o prefixo detectado na requisição.
     *
     * O servidor pode não ter SOCIALMEDIA_BASE_PATH configurado e ainda assim
     * entregar a aplicação em /pasta: nesse caso o prefixo vem da própria
     * requisição e precisa valer para TODAS as URLs (e para o cookie de sessão).
     */
    /**
     * Cópia com os valores de IA trocados.
     *
     * Existe porque a chave pode vir do banco (gravada na tela de
     * Configurações) e o resto do app não pode saber de onde ela veio: recebe
     * a Config inteira e usa `openaiKey` como sempre.
     */
    public function withAi(string $chave, string $modelo, string $modeloImagem): self
    {
        return new self(
            root: $this->root,
            dataDir: $this->dataDir,
            dbPath: $this->dbPath,
            envFile: $this->envFile,
            basePath: $this->basePath,
            publicOrigin: $this->publicOrigin,
            secretKey: $this->secretKey,
            cookieSecure: $this->cookieSecure,
            installToken: $this->installToken,
            timezone: $this->timezone,
            openaiKey: $chave,
            openaiModel: $modelo,
            openaiEndpoint: $this->openaiEndpoint,
            openaiImageModel: $modeloImagem,
            openaiImageEndpoint: $this->openaiImageEndpoint,
            metaAppId: $this->metaAppId,
            metaAppSecret: $this->metaAppSecret,
            metaRedirectUri: $this->metaRedirectUri,
            metaGraphVersion: $this->metaGraphVersion,
            metaTokenKey: $this->metaTokenKey,
            metaPublicBaseUrl: $this->metaPublicBaseUrl,
            metaGraphEndpoint: $this->metaGraphEndpoint,
            debug: $this->debug,
        );
    }

    public function withBasePath(string $basePath): self
    {
        return new self(
            root: $this->root,
            dataDir: $this->dataDir,
            dbPath: $this->dbPath,
            envFile: $this->envFile,
            basePath: $basePath,
            publicOrigin: $this->publicOrigin,
            secretKey: $this->secretKey,
            cookieSecure: $this->cookieSecure,
            installToken: $this->installToken,
            timezone: $this->timezone,
            openaiKey: $this->openaiKey,
            openaiModel: $this->openaiModel,
            openaiEndpoint: $this->openaiEndpoint,
            openaiImageModel: $this->openaiImageModel,
            openaiImageEndpoint: $this->openaiImageEndpoint,
            metaAppId: $this->metaAppId,
            metaAppSecret: $this->metaAppSecret,
            metaRedirectUri: $this->metaRedirectUri,
            metaGraphVersion: $this->metaGraphVersion,
            metaTokenKey: $this->metaTokenKey,
            metaPublicBaseUrl: $this->metaPublicBaseUrl,
            metaGraphEndpoint: $this->metaGraphEndpoint,
        );
    }

    public static function normalizeBasePath(?string $valor): string
    {
        $valor = trim((string) $valor);
        if ($valor === '' || $valor === '/') {
            return '';
        }
        $valor = '/' . trim($valor, '/');

        return $valor === '/' ? '' : $valor;
    }

    /** URL absoluta para um caminho interno, respeitando a subpasta. */
    public function url(string $caminho = '/'): string
    {
        $caminho = '/' . ltrim($caminho, '/');

        return $this->basePath . ($caminho === '/' ? '/' : $caminho);
    }

    /** Origem pública para callbacks (Meta) e URLs assinadas.
     *
     * `publicOrigin` é a URL COMPLETA da instância e já pode conter a subpasta
     * (https://host/pasta). O caminho interno é anexado sem repetir o prefixo;
     * se a origem vier sem a pasta, ela é acrescentada uma única vez.
     */
    public function absoluteUrl(string $caminho = '/'): string
    {
        $origem = rtrim($this->publicOrigin, '/');
        if ($origem === '') {
            return $this->url($caminho);
        }
        $base = $this->basePath;
        if ($base !== '' && !str_ends_with($origem, $base)) {
            $origem .= $base;
        }

        return $origem . '/' . ltrim($caminho, '/');
    }

    public static function env(string $chave): ?string
    {
        $valor = getenv($chave);
        if ($valor === false) {
            $valor = $_ENV[$chave] ?? $_SERVER[$chave] ?? null;
        }
        if ($valor === null) {
            return null;
        }
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
