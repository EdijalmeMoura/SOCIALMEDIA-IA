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

    /**
     * Quem alimenta o seletor de agência do menu lateral.
     *
     * Fica na View porque o menu aparece em todas as telas: pedir essa lista a
     * cada controller seria repetir a mesma consulta em 10 arquivos e, mais
     * cedo ou mais tarde, alguém esqueceria um — e o menu apareceria vazio
     * numa tela só.
     */
    private ?\Closure $seletorDeAgencia = null;

    /** Caminho interno da requisição em curso (para o menu saber onde estamos). */
    private static ?string $caminhoAtual = null;

    public static function informarCaminho(?string $caminho): void
    {
        self::$caminhoAtual = $caminho;
    }

    /** @param \Closure(array<string,mixed>):array{agencias:array<int,array{id:int,name:string,slug:string}>,ativa:array{id:int,name:string,slug:string}} $resolvedor */
    public function comSeletorDeAgencia(\Closure $resolvedor): void
    {
        $this->seletorDeAgencia = $resolvedor;
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

        // Destino do "voltar" depois de trocar de agência. O caminho interno da
        // requisição em curso já está no Request da instância; assim o layout
        // não depende de cada controller lembrar de passar `current_path`.
        if (!isset($contexto['current_path']) && self::$caminhoAtual !== null) {
            $contexto['current_path'] = self::$caminhoAtual;
        }

        // Menu lateral: agências disponíveis + a que está em uso. Só calcula
        // quando há usuário logado e quando a tela ainda não mandou os dados.
        if ($this->seletorDeAgencia !== null && is_array($contexto['user'] ?? null) && !isset($contexto['agencias'])) {
            $resolvido = ($this->seletorDeAgencia)($contexto['user']);
            $contexto['agencias'] = $resolvido['agencias'];
            $contexto['agencia_ativa'] = $resolvido['ativa'];
        }

        ob_start();
        (static function (string $__arquivo, array $__contexto): void {
            extract($__contexto, EXTR_SKIP);
            require $__arquivo;
        })($arquivo, $contexto);
        $corpo = (string) ob_get_clean();

        return \Smia\Http\Response::html($corpo, $status);
    }
}
