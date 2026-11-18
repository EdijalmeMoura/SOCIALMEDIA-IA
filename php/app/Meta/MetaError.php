<?php

declare(strict_types=1);

namespace Smia\Meta;

/**
 * Erro que pode ser mostrado ao usuário sem vazar token, segredo nem URL.
 *
 * `incerta` marca o caso mais delicado do módulo: a chamada saiu e não houve
 * resposta confiável. Pode ter publicado. Nesse caso o produto NÃO repete a
 * operação sozinho — quem decide é a pessoa que confere a conta na Meta.
 */
final class MetaError extends \RuntimeException
{
    public function __construct(string $mensagem, public readonly bool $incerta = false)
    {
        parent::__construct($mensagem);
    }
}
