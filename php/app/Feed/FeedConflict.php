<?php

declare(strict_types=1);

namespace Smia\Feed;

/**
 * A grade mudou entre a tela e o clique (outra aba adicionou/removeu algo).
 * Vira 409 com "atualize a página": melhor recusar do que reorganizar no escuro.
 */
final class FeedConflict extends FeedError
{
}
