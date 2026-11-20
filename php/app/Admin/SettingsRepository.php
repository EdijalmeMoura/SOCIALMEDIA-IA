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

    private SecretBox $cofre;

    public function __construct(private readonly Database $db, Config $config)
    {
        $this->cofre = new SecretBox($config->secretKey);
    }

    public function cofre(): SecretBox
    {
        return $this->cofre;
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
