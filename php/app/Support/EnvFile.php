<?php

declare(strict_types=1);

namespace Smia\Support;

/**
 * Leitor de `.env` equivalente ao do produto Python.
 *
 * Aceita KEY=VALUE, comentários com #, `export` opcional e aspas opcionais.
 * Variáveis já presentes no ambiente SEMPRE vencem o arquivo (mesma regra do
 * lado Python: cPanel/Docker > .env > installer.env).
 */
final class EnvFile
{
    /** @return array<string,string> */
    public static function parse(string $conteudo): array
    {
        $valores = [];
        foreach (preg_split('/\R/', $conteudo) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha === '' || $linha[0] === '#') {
                continue;
            }
            if (stripos($linha, 'export ') === 0) {
                $linha = trim(substr($linha, 7));
            }
            $posicao = strpos($linha, '=');
            if ($posicao === false) {
                continue;
            }
            $chave = trim(substr($linha, 0, $posicao));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,80}$/', $chave)) {
                continue;
            }
            $valor = trim(substr($linha, $posicao + 1));
            $tamanho = strlen($valor);
            if ($tamanho >= 2 && ($valor[0] === '"' || $valor[0] === "'") && $valor[$tamanho - 1] === $valor[0]) {
                $valor = substr($valor, 1, -1);
            } elseif (($corte = strpos($valor, ' #')) !== false) {
                $valor = rtrim(substr($valor, 0, $corte));
            }
            $valores[$chave] = $valor;
        }

        return $valores;
    }

    /** @return array<string,string> valores aplicados como fallback */
    public static function load(string $arquivo, ?array &$destino = null): array
    {
        if (!is_file($arquivo) || !is_readable($arquivo)) {
            return [];
        }
        $valores = self::parse((string) file_get_contents($arquivo));
        foreach ($valores as $chave => $valor) {
            if (getenv($chave) === false && !isset($_ENV[$chave])) {
                putenv($chave . '=' . $valor);
                $_ENV[$chave] = $valor;
                $_SERVER[$chave] = $valor;
            }
        }

        return $valores;
    }
}
