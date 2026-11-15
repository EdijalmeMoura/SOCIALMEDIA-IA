<?php

declare(strict_types=1);

namespace Smia\Database;

/**
 * Esquema inicial da instância.
 *
 * Nesta primeira entrega entram as tabelas que a base funcional usa:
 * empresas, usuários (proprietário/equipe), estado da instalação e sessões
 * de auditoria. As demais (clientes, publicações, aprovações, mídia, Meta)
 * entram nos módulos seguintes, sempre por migração aditiva — nada aqui
 * apaga dados de uma instalação existente.
 */
final class Schema
{
    public const VERSAO = 1;

    public static function migrate(Database $db): void
    {
        $db->pdo()->exec('PRAGMA foreign_keys = ON');
        $db->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS schema_version (
    version INTEGER NOT NULL,
    applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS companies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    installed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    email TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'owner' CHECK (role IN ('owner','admin','editor','viewer')),
    last_login_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_users_company ON users(company_id);

CREATE TABLE IF NOT EXISTS installation (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    installed_at TEXT NOT NULL,
    agency_name TEXT NOT NULL,
    owner_email TEXT NOT NULL,
    public_origin TEXT NOT NULL,
    php_version TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER,
    user_id INTEGER,
    action TEXT NOT NULL,
    detail TEXT,
    ip_hash TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at);
SQL);

        $versao = (int) ($db->scalar('SELECT MAX(version) FROM schema_version') ?? 0);
        if ($versao < self::VERSAO) {
            $db->run('INSERT INTO schema_version (version) VALUES (?)', [self::VERSAO]);
        }
    }

    public static function version(Database $db): int
    {
        return (int) ($db->scalar('SELECT MAX(version) FROM schema_version') ?? 0);
    }
}
