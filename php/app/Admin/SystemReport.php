<?php

declare(strict_types=1);

namespace Smia\Admin;

use Smia\Database\Database;
use Smia\Installer\Installer;
use Smia\Support\Config;

/**
 * Sistema: o que a instalação tem e como tirar uma cópia do banco.
 *
 * Existe porque as perguntas que chegam depois de uns meses são sempre as
 * mesmas: "qual versão está no ar?", "por que não grava?", "como faço backup
 * sem phpMyAdmin?". A tela responde as três sem precisar de SSH.
 *
 * Nada aqui devolve segredo: o `.env` aparece como existente/ausente e qual a
 * permissão, nunca o conteúdo.
 */
final class SystemReport
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly Installer $installer,
    ) {
    }

    /**
     * Versão do app, do esquema e do pacote em uso.
     *
     * @return array<int,array{rotulo:string,valor:string,ok:bool}>
     */
    public function versoes(): array
    {
        $esquema = (int) ($this->db->scalar('SELECT MAX(version) FROM schema_version') ?? 0);

        return [
            ['rotulo' => 'Aplicação', 'valor' => 'v' . Config::VERSAO, 'ok' => true],
            [
                'rotulo' => 'Banco (esquema)',
                'valor' => 'v' . $esquema . ($esquema >= \Smia\Database\Schema::VERSAO ? '' : ' — desatualizado, recarregue a página'),
                'ok' => $esquema >= \Smia\Database\Schema::VERSAO,
            ],
            ['rotulo' => 'PHP', 'valor' => PHP_VERSION, 'ok' => PHP_VERSION_ID >= 80000],
            ['rotulo' => 'Fuso da instância', 'valor' => $this->config->timezone, 'ok' => true],
        ];
    }

    /** Checagens de ambiente do instalador, reaproveitadas aqui. */
    /** @return array<int,array{label:string,status:string,detail:string}> */
    public function ambiente(): array
    {
        return $this->installer->environmentChecks();
    }

    /**
     * Arquivos e pastas que precisam existir e ser graváveis.
     *
     * @return array<int,array{rotulo:string,caminho:string,ok:bool,detalhe:string}>
     */
    public function arquivos(): array
    {
        $itens = [];
        $dataExiste = is_dir($this->config->dataDir);
        $itens[] = [
            'rotulo' => 'Pasta de dados',
            'caminho' => $this->config->dataDir,
            'ok' => $dataExiste && is_writable($this->config->dataDir),
            'detalhe' => $dataExiste
                ? (is_writable($this->config->dataDir) ? 'existe e aceita escrita' : 'existe, mas está sem permissão de escrita')
                : 'não existe',
        ];

        $banco = is_file($this->config->dbPath);
        $itens[] = [
            'rotulo' => 'Banco SQLite',
            'caminho' => $this->config->dbPath,
            'ok' => $banco && is_readable($this->config->dbPath),
            'detalhe' => $banco
                ? number_format((int) filesize($this->config->dbPath) / 1024, 1, ',', '.') . ' KB'
                : 'arquivo não encontrado',
        ];

        $env = is_file($this->config->envFile);
        $itens[] = [
            'rotulo' => 'Arquivo .env',
            'caminho' => $this->config->envFile,
            'ok' => $env,
            'detalhe' => $env
                ? 'existe' . (is_readable($this->config->envFile) ? ' e é legível' : ' (sem leitura)')
                : 'não existe — a instalação está usando dados/installer.env',
        ];

        // Mesma pasta que o Upload usa (definida em Application): data/uploads.
        $uploads = $this->config->dataDir . '/uploads';
        $itens[] = [
            'rotulo' => 'Pasta de imagens',
            'caminho' => $uploads,
            'ok' => is_dir($uploads) && is_writable($uploads),
            'detalhe' => is_dir($uploads) ? 'existe' : 'será criada na primeira imagem',
        ];

        return $itens;
    }

    /**
     * Cópia consistente do banco para baixar.
     *
     * Não copia o arquivo cru: em SQLite o `-wal` pode estar com o que ainda
     * não foi para o principal, e a cópia sairia sem as últimas gravações.
     * `VACUUM INTO` deixa o banco em um arquivo só, íntegro e já compactado.
     */
    public function copiarBanco(?string $destino = null): string
    {
        $destino ??= $this->config->dataDir . '/backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
        $this->db->pdo()->exec("VACUUM INTO '" . str_replace("'", "''", $destino) . "'");
        @chmod($destino, 0o600);

        return $destino;
    }

    /** Nome do arquivo sugerido no download. */
    public function nomeDoBackup(): string
    {
        return 'socialmedia-ia-backup-' . Config::VERSAO . '-' . gmdate('Ymd-His') . '.sqlite';
    }
}
