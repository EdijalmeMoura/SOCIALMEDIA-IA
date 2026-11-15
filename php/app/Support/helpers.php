<?php

declare(strict_types=1);

/**
 * Helpers globais usados nas views (carregados pelo autoload).
 * `e()` escapa para HTML; nunca interpole dados de usuário sem passar por ele.
 */
function e(null|string|int|float|bool $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function smia_asset(string $caminho): string
{
    $caminho = '/' . ltrim($caminho, '/');
    $base = $GLOBALS['smia_base_path'] ?? '';
    $absoluto = __DIR__ . '/../../public' . $caminho;
    $versao = is_file($absoluto) ? substr((string) filemtime($absoluto), -6) : '1';

    return $base . $caminho . '?v=' . $versao;
}
