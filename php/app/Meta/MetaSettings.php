<?php

declare(strict_types=1);

namespace Smia\Meta;

use Smia\Support\Config;

/**
 * Configuração da integração Meta, validada uma vez.
 *
 * O produto **falha fechado**: sem configuração completa e correta, nenhuma
 * chamada ao Graph é feita e a tela explica o que falta. É melhor uma conexão
 * indisponível do que um token guardado de qualquer jeito.
 */
final class MetaSettings
{
    public const ESCOPOS = [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'instagram_basic',
        'instagram_content_publish',
    ];

    public const CALLBACK = '/integrations/meta/callback';

    public function __construct(
        public readonly string $appId,
        public readonly string $appSecret,
        public readonly string $redirectUri,
        public readonly string $graphVersion,
        public readonly string $chaveTokens,
        public readonly string $basePath = '',
    ) {
    }

    /** Lê o .env da instância e valida tudo — ou explica exatamente o que falta. */
    public static function fromConfig(Config $config): self
    {
        $faltando = [];
        foreach ([
            'META_APP_ID' => $config->metaAppId,
            'META_APP_SECRET' => $config->metaAppSecret,
            'META_REDIRECT_URI' => $config->metaRedirectUri,
            'META_GRAPH_VERSION' => $config->metaGraphVersion,
            'META_TOKEN_ENCRYPTION_KEY' => $config->metaTokenKey,
        ] as $nome => $valor) {
            if (trim($valor) === '') {
                $faltando[] = $nome;
            }
        }
        if ($faltando !== []) {
            throw new MetaError('Configure no .env: ' . implode(', ', $faltando) . '.');
        }

        $versao = trim($config->metaGraphVersion);
        if (preg_match('/^v\d+\.\d+$/', $versao) !== 1) {
            throw new MetaError('META_GRAPH_VERSION deve usar o formato vNN.N, conforme a versão habilitada no seu app Meta.');
        }

        $uri = trim($config->metaRedirectUri);
        $partes = parse_url($uri);
        $local = is_array($partes)
            && ($partes['scheme'] ?? '') === 'http'
            && in_array($partes['host'] ?? '', ['localhost', '127.0.0.1'], true);
        if (!is_array($partes) || ($partes['scheme'] ?? '') !== 'https' && !$local) {
            throw new MetaError('META_REDIRECT_URI precisa usar HTTPS (HTTP só é aceito em localhost para desenvolvimento).');
        }
        if (empty($partes['host']) || isset($partes['user']) || isset($partes['pass']) || isset($partes['fragment'])) {
            throw new MetaError('META_REDIRECT_URI não é uma URL de callback válida.');
        }

        $chave = trim($config->metaTokenKey);
        $bytes = base64_decode($chave, true);
        if ($bytes === false || strlen($bytes) !== 32) {
            throw new MetaError('META_TOKEN_ENCRYPTION_KEY inválida. Gere 32 bytes aleatórios em Base64 (php -r "echo base64_encode(random_bytes(32));") e mantenha em segredo.');
        }

        return new self(trim($config->metaAppId), trim($config->metaAppSecret), $uri, $versao, $chave, $config->basePath);
    }

    /** O callback configurado aponta para esta instalação e para a rota certa? */
    public function callbackConfere(string $host): bool
    {
        $partes = parse_url($this->redirectUri);
        if (!is_array($partes)) {
            return false;
        }
        // O caminho precisa ser a rota de callback da PRÓPRIA instalação — que
        // pode estar em subpasta (/edijalmemoura/socialai/integrations/...).
        if (isset($partes['query']) || ($partes['path'] ?? '') !== $this->basePath . self::CALLBACK) {
            return false;
        }

        return strtolower((string) ($partes['host'] ?? '')) === strtolower($host);
    }

    /** Tenta a configuração sem lançar: devolve [configuração, erro]. */
    public static function tentar(Config $config): array
    {
        try {
            return [self::fromConfig($config), null];
        } catch (MetaError $erro) {
            return [null, $erro->getMessage()];
        }
    }
}
