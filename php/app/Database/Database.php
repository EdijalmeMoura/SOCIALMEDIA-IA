<?php

declare(strict_types=1);

namespace Smia\Database;

use PDO;

/**
 * Conexão SQLite com as mesmas garantias do produto Python:
 * chaves estrangeiras ligadas, journal WAL e arquivo 0600 na primeira criação.
 */
final class Database
{
    private PDO $pdo;

    public function __construct(private readonly string $arquivo)
    {
        $diretorio = dirname($arquivo);
        if (!is_dir($diretorio) && !mkdir($diretorio, 0o700, true) && !is_dir($diretorio)) {
            throw new \RuntimeException('Não foi possível criar o diretório de dados: ' . $diretorio);
        }
        $novo = !file_exists($arquivo);
        $this->pdo = new PDO('sqlite:' . $arquivo, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($novo) {
            @chmod($arquivo, 0o600);
        }
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function path(): string
    {
        return $this->arquivo;
    }

    /** @param array<string,mixed> $parametros */
    public function run(string $sql, array $parametros = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parametros);

        return $stmt;
    }

    /** @param array<string,mixed> $parametros */
    public function one(string $sql, array $parametros = []): ?array
    {
        $linha = $this->run($sql, $parametros)->fetch();

        return $linha === false ? null : $linha;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(string $sql, array $parametros = []): array
    {
        return $this->run($sql, $parametros)->fetchAll();
    }

    /** @param array<string,mixed> $parametros */
    public function scalar(string $sql, array $parametros = []): mixed
    {
        $valor = $this->run($sql, $parametros)->fetchColumn();

        return $valor === false ? null : $valor;
    }

    public function transaction(callable $acao): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $resultado = $acao($this);
            $this->pdo->commit();

            return $resultado;
        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $erro;
        }
    }
}
