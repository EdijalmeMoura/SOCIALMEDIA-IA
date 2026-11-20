<?php

declare(strict_types=1);

namespace Smia\Http;

/**
 * Roteador com parâmetros de rota ("/clients/{id}").
 *
 * Compara apenas o caminho INTERNO: o prefixo da subpasta já foi removido de
 * Request::path, então a mesma tabela de rotas serve para raiz de domínio e
 * para /edijalmemoura/socialai.
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,params:array<int,string>,handler:callable}> */
    private array $rotas = [];

    public function get(string $padrao, callable $handler): void
    {
        $this->add('GET', $padrao, $handler);
    }

    public function post(string $padrao, callable $handler): void
    {
        $this->add('POST', $padrao, $handler);
    }

    /**
     * Rota que responde a GET **e** POST.
     *
     * Serve para páginas que o usuário pode abrir pelo endereço (GET) e que um
     * formulário também envia. O handler recebe o método para decidir o que
     * fazer — sem isso, abrir uma ação POST no navegador devolvia um 405 cru,
     * sem explicar nada.
     */
    public function any(string $padrao, callable $handler): void
    {
        $this->add('GET', $padrao, $handler);
        $this->add('POST', $padrao, $handler);
    }

    public function add(string $metodo, string $padrao, callable $handler): void
    {
        $parametros = [];
        $regex = preg_replace_callback(
            '#\{([a-z_][a-z0-9_]*)\}#i',
            static function (array $m) use (&$parametros): string {
                $parametros[] = $m[1];

                return '([^/]+)';
            },
            $padrao
        ) ?? $padrao;

        $this->rotas[] = [
            'method' => $metodo,
            'pattern' => $padrao,
            'regex' => '#^' . $regex . '$#',
            'params' => $parametros,
            'handler' => $handler,
        ];
    }

    /**
     * @return array{0:Response,1:?string} resposta e o padrão que casou (para diagnóstico)
     */
    public function dispatch(Request $requisicao): Response
    {
        $caminho = $requisicao->path === '/' ? '/' : rtrim($requisicao->path, '/');
        if ($caminho === '') {
            $caminho = '/';
        }

        foreach ($this->rotas as $rota) {
            if ($rota['method'] !== $requisicao->method) {
                continue;
            }
            if (!preg_match($rota['regex'], $caminho, $achados)) {
                continue;
            }
            array_shift($achados);
            $argumentos = [];
            foreach ($rota['params'] as $indice => $nome) {
                $argumentos[$nome] = rawurldecode($achados[$indice] ?? '');
            }

            return ($rota['handler'])($requisicao, $argumentos);
        }

        // 405 explícito ajuda a diagnosticar integração (POST caindo em GET).
        foreach ($this->rotas as $rota) {
            if ($rota['method'] !== $requisicao->method && preg_match($rota['regex'], $caminho)) {
                return Response::html('<h1>405</h1><p>Método não permitido para esta rota.</p>', 405);
            }
        }

        return Response::html('<h1>404</h1><p>Página não encontrada.</p>', 404);
    }

    /** @return array<int,string> */
    public function patterns(): array
    {
        return array_map(static fn (array $r): string => $r['method'] . ' ' . $r['pattern'], $this->rotas);
    }
}
