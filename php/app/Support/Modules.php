<?php

declare(strict_types=1);

namespace Smia\Support;

/**
 * Navegação do produto, num lugar só.
 *
 * O layout autenticado e o dashboard leem desta lista, então um módulo novo
 * aparece no menu simplesmente ficando pronto aqui — sem duplicar nomes.
 */
final class Modules
{
    /**
     * @return array<int,array{slug:string,name:string,hint:string,path:?string,icon:string,ready:bool}>
     */
    public static function all(): array
    {
        return [
            ['slug' => 'clients', 'name' => 'Clientes', 'hint' => 'Contas, identidade visual e briefing', 'path' => '/clients', 'icon' => '◫', 'ready' => true],
            ['slug' => 'planner', 'name' => 'Planejamento', 'hint' => 'Calendário editorial e pautas', 'path' => '/planner', 'icon' => '▤', 'ready' => true],
            ['slug' => 'studio', 'name' => 'Estúdio', 'hint' => 'Criação de artes e legendas', 'path' => '/studio', 'icon' => '✦', 'ready' => true],
            ['slug' => 'approvals', 'name' => 'Aprovações', 'hint' => 'Portal do cliente e revisões', 'path' => '/approvals', 'icon' => '◷', 'ready' => true],
            ['slug' => 'feed', 'name' => 'Feed', 'hint' => 'Prévia do perfil e grade', 'path' => '/feed', 'icon' => '▦', 'ready' => false],
            ['slug' => 'reports', 'name' => 'Relatórios', 'hint' => 'Métricas e entregas', 'path' => '/reports', 'icon' => '◑', 'ready' => false],
            ['slug' => 'meta', 'name' => 'Conexões Meta', 'hint' => 'Páginas, Instagram e publicação', 'path' => '/meta', 'icon' => '◎', 'ready' => false],
            ['slug' => 'settings', 'name' => 'Configurações', 'hint' => 'Equipe, segurança e integrações', 'path' => '/settings', 'icon' => '⚙', 'ready' => false],
        ];
    }

    /** Slug do módulo ativo a partir do caminho interno ("/clients/3" -> "clients"). */
    public static function activeFrom(?string $path): ?string
    {
        if ($path === null || $path === '' || $path === '/') {
            return null;
        }
        $primeiro = explode('/', ltrim($path, '/'))[0];

        foreach (self::all() as $modulo) {
            if ($modulo['slug'] === $primeiro) {
                return $modulo['slug'];
            }
        }

        return null;
    }

    /** Rótulo para o breadcrumb da topbar. */
    public static function labelFor(?string $slug): ?string
    {
        foreach (self::all() as $modulo) {
            if ($modulo['slug'] === $slug) {
                return $modulo['name'];
            }
        }

        return null;
    }
}
