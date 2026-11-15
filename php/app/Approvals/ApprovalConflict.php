<?php

declare(strict_types=1);

namespace Smia\Approvals;

/**
 * O pedido mudou de estado entre a leitura e a escrita (duas abas, dois
 * cliques). Vira 409 na tela: pedir para recarregar é mais honesto que
 * sobrescrever a decisão de outra pessoa.
 */
final class ApprovalConflict extends ApprovalError
{
}
