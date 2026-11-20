<?php

declare(strict_types=1);

namespace Smia\Admin;

use Smia\Database\Database;
use Smia\Support\Config;

/**
 * Configuração editável pela interface.
 *
 * Por que não editar o `.env` pela tela: em cPanel o arquivo é lido uma vez por
 * requisição, mas variável de ambiente do painel vence o arquivo — e a pessoa
 * fica editando um lugar que não manda. Aqui a chave fica no banco, com
 * precedência explícita: banco > `.env` > ambiente.
 *
 * O que a tela nunca faz: devolver a chave. Só o estado.
 */
final class SettingsRepository
{
    public const CHAVE_IA = 'openai_api_key';
    public const MODELO_TEXTO = 'openai_model';
    public const MODELO_IMAGEM = 'openai_image_model';

    /**
     * Preferências com que o estúdio e o planejamento já vêm preenchidos.
     *
     * São valores por agência: a escolha de "Instagram, tom direto" de uma
     * agência não pode mudar o padrão de outra no mesmo banco.
     */
    public const PREF_REDE = 'studio.default_network';
    public const PREF_TOM = 'studio.default_tone';
    public const PREF_TAGS = 'studio.default_hashtags';
    public const PREF_ASSINATURA = 'studio.default_signature';

    private SecretBox $cofre;

    public function __construct(private readonly Database $db, Config $config)
    {
        $this->cofre = new SecretBox($config->secretKey);
    }

    public function cofre(): SecretBox
    {
        return $this->cofre;
    }

    /**
     * Dados da agência que a pessoa vê e (se for admin) edita aqui.
     *
     * Lê direto de `companies`: são colunas comuns, sem segredo nenhum, e a
     * tela precisa mostrá-las mesmo para quem só pode ler.
     *
     * @return array{nome:string,slug:string,razao:string,documento:string,email:string,telefone:string,site:string,desde:string,ativa:bool}
     */
    public function agencia(int $companyId): array
    {
        $linha = $this->db->one(
            'SELECT name, slug, legal_name, document, contact_email, contact_phone, website, installed_at, is_active
             FROM companies WHERE id = ?',
            [$companyId]
        );

        return [
            'nome' => (string) ($linha['name'] ?? ''),
            'slug' => (string) ($linha['slug'] ?? ''),
            'razao' => (string) ($linha['legal_name'] ?? ''),
            'documento' => (string) ($linha['document'] ?? ''),
            'email' => (string) ($linha['contact_email'] ?? ''),
            'telefone' => (string) ($linha['contact_phone'] ?? ''),
            'site' => (string) ($linha['website'] ?? ''),
            'desde' => (string) ($linha['installed_at'] ?? ''),
            'ativa' => (int) ($linha['is_active'] ?? 1) === 1,
        ];
    }

    /** Grava os dados da agência. A rota já garante que é admin da própria agência. */
    public function gravarAgencia(int $companyId, array $dados): void
    {
        $this->db->run(
            'UPDATE companies
             SET name = ?, legal_name = ?, document = ?, contact_email = ?, contact_phone = ?, website = ?
             WHERE id = ?',
            [
                $dados['nome'], $dados['razao'], $dados['documento'],
                $dados['email'], $dados['telefone'], $dados['site'],
                $companyId,
            ]
        );
    }

    /**
     * Preferências em vigor, já com o padrão do produto quando ninguém mexeu.
     *
     * @return array{rede:string,tom:string,tags:string,assinatura:string,padrao:bool}
     */
    public function preferencias(int $companyId, array $redes, array $tons): array
    {
        $rede = $this->get($this->chaveDaEmpresa(self::PREF_REDE, $companyId), 'instagram');
        $tom = $this->get($this->chaveDaEmpresa(self::PREF_TOM, $companyId), 'marca');
        $tags = $this->get($this->chaveDaEmpresa(self::PREF_TAGS, $companyId), '');
        $assinatura = $this->get($this->chaveDaEmpresa(self::PREF_ASSINATURA, $companyId), '');
        $nenhuma = $this->get($this->chaveDaEmpresa(self::PREF_REDE, $companyId)) === '';

        return [
            'rede' => isset($redes[$rede]) ? $rede : 'instagram',
            'tom' => isset($tons[$tom]) ? $tom : 'marca',
            'tags' => $tags,
            'assinatura' => $assinatura,
            'padrao' => $nenhuma,
        ];
    }

    /** @param array{rede:string,tom:string,tags:string,assinatura:string} $prefs */
    public function gravarPreferencias(int $companyId, array $prefs, ?int $autor = null): void
    {
        $this->set($this->chaveDaEmpresa(self::PREF_REDE, $companyId), $prefs['rede'], $autor);
        $this->set($this->chaveDaEmpresa(self::PREF_TOM, $companyId), $prefs['tom'], $autor);
        $this->set($this->chaveDaEmpresa(self::PREF_TAGS, $companyId), $prefs['tags'], $autor);
        $this->set($this->chaveDaEmpresa(self::PREF_ASSINATURA, $companyId), $prefs['assinatura'], $autor);
    }

    public function limparPreferencias(int $companyId): void
    {
        foreach ([self::PREF_REDE, self::PREF_TOM, self::PREF_TAGS, self::PREF_ASSINATURA] as $chave) {
            $this->forget($this->chaveDaEmpresa($chave, $companyId));
        }
    }

    /**
     * Chave de `app_settings` com o dono embutido.
     *
     * A tabela é uma só para o banco inteiro (várias agências convivem nela),
     * então a empresa faz parte da chave — não existe "preferência global".
     */
    private function chaveDaEmpresa(string $chave, int $companyId): string
    {
        return 'company:' . $companyId . ':' . $chave;
    }

    public function get(string $chave, string $padrao = ''): string
    {
        $valor = $this->db->scalar('SELECT value FROM app_settings WHERE key = ?', [$chave]);

        return $valor === null ? $padrao : (string) $valor;
    }

    /** @param int|null $autor id do usuário que gravou (auditoria) */
    public function set(string $chave, string $valor, ?int $autor = null): void
    {
        $this->db->run(
            'INSERT INTO app_settings (key, value, updated_by, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value,
                                            updated_by = excluded.updated_by,
                                            updated_at = excluded.updated_at',
            [$chave, $valor, $autor, gmdate('Y-m-d H:i:s')]
        );
    }

    public function forget(string $chave): void
    {
        $this->db->run('DELETE FROM app_settings WHERE key = ?', [$chave]);
    }

    public function chaveIaGravada(): string
    {
        $guardado = $this->get(self::CHAVE_IA);
        if ($guardado === '') {
            return '';
        }

        return $this->cofre->decifrar($guardado);
    }

    public function gravarChaveIa(string $chave, ?int $autor = null): void
    {
        $this->set(self::CHAVE_IA, $this->cofre->cifrar(trim($chave)), $autor);
    }

    public function modeloTexto(Config $config): string
    {
        return $this->get(self::MODELO_TEXTO, $config->openaiModel);
    }

    public function modeloImagem(Config $config): string
    {
        return $this->get(self::MODELO_IMAGEM, $config->openaiImageModel);
    }

    /**
     * Resolve a configuração efetiva de IA. O banco ganha de tudo: se a pessoa
     * gravou pela tela, é esse valor que vale — e o contrário (`.env` ganhar)
     * é justamente a armadilha que confunde.
     */
    public function configEfetiva(Config $config): Config
    {
        $chave = $this->chaveIaGravada();
        $texto = $this->modeloTexto($config);
        $imagem = $this->modeloImagem($config);

        return $config->withAi(
            $chave !== '' ? $chave : $config->openaiKey,
            $texto,
            $imagem
        );
    }

    /**
     * Situação da chave, sem revelar nada dela.
     *
     * @return array{configurada:bool,origem:string,tamanho:int,prefixo:string,cifrada:bool,formato:string}
     */
    public function situacaoChave(Config $config): array
    {
        $doBanco = $this->chaveIaGravada();
        if ($doBanco !== '') {
            return $this->descrever($doBanco, 'Configurações (este painel)', true);
        }
        if (trim($config->openaiKey) !== '') {
            return $this->descrever($config->openaiKey, '.env / ambiente do servidor', false);
        }

        return [
            'configurada' => false,
            'origem' => 'nenhuma',
            'tamanho' => 0,
            'prefixo' => '',
            'cifrada' => false,
            'formato' => 'sem chave — planejamento e estúdio ficam em modo rascunho local',
        ];
    }

    /** @return array{configurada:bool,origem:string,tamanho:int,prefixo:string,cifrada:bool,formato:string} */
    private function descrever(string $chave, string $origem, bool $cifrada): array
    {
        $prefixo = '';
        $formato = 'formato desconhecido — confira no painel da OpenAI';
        foreach ([
            'sk-svcacct-' => 'service account (sk-svcacct-) — recomendada',
            'sk-proj-' => 'chave de usuário (sk-proj-) — funciona, mas cai se a pessoa sair do projeto',
            'sk-admin-' => 'chave de ADMINISTRAÇÃO (sk-admin-) — não gera conteúdo',
            'sk-' => 'formato antigo (sk-…) — pode estar desativada',
        ] as $inicio => $descricao) {
            if (str_starts_with($chave, $inicio)) {
                $prefixo = $inicio;
                $formato = $descricao;
                break;
            }
        }

        return [
            'configurada' => true,
            'origem' => $origem,
            'tamanho' => strlen($chave),
            'prefixo' => $prefixo,
            'cifrada' => $cifrada && $this->cofre->disponivel(),
            'formato' => $formato,
        ];
    }
}
