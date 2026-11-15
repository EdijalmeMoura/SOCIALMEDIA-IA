<?php

declare(strict_types=1);

namespace Smia\Support;

/**
 * Renderizador de views em PHP puro com escape automático.
 *
 * `e()` (helpers.php) cobre tudo que vem de usuário/banco; é obrigatório
 * nas views. Nunca interpole variável direto no HTML.
 */
final class View
{
    public function __construct(private readonly string $diretorio, private readonly Config $config)
    {
    }

    /** @param array<string,mixed> $dados */
    public function render(string $nome, array $dados = [], int $status = 200): \Smia\Http\Response
    {
        $arquivo = $this->diretorio . '/' . $nome . '.php';
        if (!is_file($arquivo)) {
            throw new \RuntimeException('View não encontrada: ' . $nome);
        }
        $contexto = $dados + [
            'config' => $this->config,
            'url' => fn (string $caminho = '/'): string => $this->config->url($caminho),
        ];

        ob_start();
        (static function (string $__arquivo, array $__contexto): void {
            extract($__contexto, EXTR_SKIP);
            require $__arquivo;
        })($arquivo, $contexto);
        $corpo = (string) ob_get_clean();

        return \Smia\Http\Response::html($corpo, $status);
    }
}
