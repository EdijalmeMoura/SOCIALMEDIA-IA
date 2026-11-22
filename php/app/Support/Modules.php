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
     * Módulos que a conta pode ver.
     *
     * `admin` é do dono da instalação: o menu não deve oferecer uma porta que
     * a pessoa não pode abrir. O bloqueio de verdade continua no controller.
     *
     * @param bool $super
     * @return array<int,array{slug:string,name:string,hint:string,path:?string,icon:string,ready:bool}>
     */
    public static function paraUsuario(bool $super): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $modulo): bool => $modulo['slug'] !== 'admin' || $super
        ));
    }

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
            ['slug' => 'feed', 'name' => 'Feed', 'hint' => 'Prévia do perfil e grade', 'path' => '/feed', 'icon' => '▦', 'ready' => true],
            ['slug' => 'reports', 'name' => 'Relatórios', 'hint' => 'Entregas registradas e exportação', 'path' => '/reports', 'icon' => '◑', 'ready' => true],
            ['slug' => 'meta', 'name' => 'Conexões Meta', 'hint' => 'Agenda, Páginas e publicação', 'path' => '/meta', 'icon' => '◎', 'ready' => true],
            ['slug' => 'users', 'name' => 'Usuários', 'hint' => 'Equipe da agência e permissões', 'path' => '/settings?aba=ia', 'icon' => '☺', 'ready' => true],
            ['slug' => 'settings', 'name' => 'Configurações', 'hint' => 'IA, agência, preferências e sistema', 'path' => '/settings', 'icon' => '⚙', 'ready' => true],
            // Só aparece para quem é dono da instalação; o layout filtra.
            ['slug' => 'admin', 'name' => 'Área do dono', 'hint' => 'Agências, usuários e atualização do sistema', 'path' => '/admin', 'icon' => '◆', 'ready' => true],
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
