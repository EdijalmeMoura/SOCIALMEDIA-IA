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
    /**
     * Arquivos lidos e variáveis que cada um forneceu de fato.
     *
     * Serve para o diagnóstico responder "de onde veio esta chave?" — a
     * pergunta que decide entre "editei o .env e não mudou nada" (variável do
     * painel vence) e "o .env nem foi lido".
     *
     * @var list<array{caminho:string, lido:bool, chaves:list<string>, ignoradas:list<string>}>
     */
    public static array $trilha = [];

    /**
     * Linhas que não são `CHAVE=valor` válido e por isso foram descartadas.
     * Quase sempre é o erro de digitação que quebra a chave.
     *
     * @var list<array{caminho:string, linha:int, texto:string}>
     */
    public static array $descartadas = [];

    /**
     * Valores que o ` #` cortou. A chave vira um prefixo silencioso e o
     * provedor devolve 401 sem explicar nada.
     *
     * @var list<array{chave:string, restante:string, caminho:string, linha:int}>
     */
    public static array $cortadas = [];

    /** @return array<string,string> */
    public static function parse(string $conteudo): array
    {
        $valores = [];
        self::$cortadas = [];
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
                // Fora de aspas, " #" inicia comentário: corta o valor. É
                // exatamente o que quebra uma chave colada com anotação junto.
                $valor = rtrim(substr($valor, 0, $corte));
                self::$cortadas[] = [
                    'chave' => $chave,
                    'restante' => trim(substr($valor, $corte)),
                    'caminho' => '',
                    'linha' => 0,
                ];
            }
            $valores[$chave] = $valor;
        }

        return $valores;
    }

    /** @return array<string,string> valores aplicados como fallback */
    public static function load(string $arquivo, ?array &$destino = null): array
    {
        if (!is_file($arquivo) || !is_readable($arquivo)) {
            self::$trilha[] = ['caminho' => $arquivo, 'lido' => false, 'chaves' => [], 'ignoradas' => []];

            return [];
        }
        $conteudo = (string) file_get_contents($arquivo);
        $valores = self::parse($conteudo);

        // Fotografa o ambiente ANTES de aplicar o arquivo: sem isso não há como
        // provar que o arquivo perdeu para uma variável do painel.
        $doAmbiente = [];
        foreach (array_keys($valores) as $chave) {
            if (getenv($chave) !== false || isset($_ENV[$chave])) {
                $doAmbiente[] = $chave;
            }
        }

        // Completa os cortes com o arquivo e a linha onde aconteceram.
        foreach (self::$cortadas as $i => $corte) {
            $numero = 0;
            foreach (preg_split('/\R/', $conteudo) ?: [] as $linha) {
                $numero++;
                if (preg_match('/^\s*(export\s+)?' . preg_quote($corte['chave'], '/') . '\s*=/', $linha)) {
                    self::$cortadas[$i]['caminho'] = $arquivo;
                    self::$cortadas[$i]['linha'] = $numero;
                    break;
                }
            }
        }

        $aplicadas = [];
        $ignoradas = [];
        foreach ($valores as $chave => $valor) {
            if (in_array($chave, $doAmbiente, true)) {
                // Já existia no ambiente antes da leitura: o arquivo perdeu.
                // É a armadilha nº 1 do cPanel ("editei e nada mudou").
                $ignoradas[] = $chave;
                continue;
            }
            putenv($chave . '=' . $valor);
            $_ENV[$chave] = $valor;
            $_SERVER[$chave] = $valor;
            $aplicadas[] = $chave;
        }
        self::$trilha[] = ['caminho' => $arquivo, 'lido' => true, 'chaves' => $aplicadas, 'ignoradas' => $ignoradas];

        // Aponta a linha que não virou variável (sem mostrar o valor).
        $numero = 0;
        foreach (preg_split('/\R/', $conteudo) ?: [] as $linha) {
            $numero++;
            $texto = trim($linha);
            if ($texto === '' || $texto[0] === '#') {
                continue;
            }
            $semExport = stripos($texto, 'export ') === 0 ? trim(substr($texto, 7)) : $texto;
            $posicao = strpos($semExport, '=');
            $nome = $posicao === false ? '' : trim(substr($semExport, 0, $posicao));
            if ($posicao === false || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,80}$/', $nome)) {
                self::$descartadas[] = [
                    'caminho' => $arquivo,
                    'linha' => $numero,
                    'texto' => self::mascararLinha($texto),
                ];
            }
        }

        return $valores;
    }

    /**
     * Devolve a linha com o valor trocado por `•••`. Nenhum segredo sai do
     * servidor — nem para a tela, nem para o log.
     */
    public static function mascararLinha(string $linha): string
    {
        $posicao = strpos($linha, '=');
        if ($posicao === false) {
            return strlen($linha) > 24 ? substr($linha, 0, 24) . '…' : $linha;
        }
        $nome = substr($linha, 0, $posicao);
        $valor = trim(substr($linha, $posicao + 1));

        return $nome . '=' . ($valor === '' ? '' : '•••(' . strlen($valor) . ' caracteres)');
    }
}
