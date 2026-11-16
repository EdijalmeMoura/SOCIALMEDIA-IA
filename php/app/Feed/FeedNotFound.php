<?php

declare(strict_types=1);

namespace Smia\Feed;

/**
 * O conteúdo pedido não existe nesta marca — a resposta é 404, não um aviso
 * na tela: para quem está logado em outra agência não pode haver diferença
 * entre "não existe" e "não é seu".
 */
final class FeedNotFound extends FeedError
{
}
