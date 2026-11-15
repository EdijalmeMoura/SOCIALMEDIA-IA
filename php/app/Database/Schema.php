<?php

declare(strict_types=1);

namespace Smia\Database;

/**
 * Esquema inicial da instância.
 *
 * Cada módulo acrescenta tabelas aqui, sempre por migração aditiva: nada é
 * apagado de uma instalação existente. Criar tabela é idempotente
 * (IF NOT EXISTS), então subir a versão nunca quebra quem já instalou.
 *
 * Versões: 1 = base (empresa, usuários, instalação, auditoria)
 *           2 = clientes (perfil, identidade visual, biblioteca de imagens)
 */
final class Schema
{
    public const VERSAO = 2;

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

        // --- v2: módulo de clientes -------------------------------------
        // Mesmas colunas da versão Python, para que dados exportados de lá
        // continuem válidos. client_branding é 1-para-1 com o cliente.
        $db->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    company_name TEXT NOT NULL,
    trade_name TEXT,
    segment TEXT,
    description TEXT,
    products_services TEXT,
    audience TEXT,
    address TEXT,
    phone TEXT,
    whatsapp TEXT,
    website TEXT,
    instagram TEXT,
    facebook TEXT,
    business_goals TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_clients_company ON clients(company_id, status, company_name);

CREATE TABLE IF NOT EXISTS client_branding (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL UNIQUE REFERENCES clients(id) ON DELETE CASCADE,
    logo_path TEXT,
    primary_color TEXT NOT NULL DEFAULT '#3158d8',
    secondary_color TEXT NOT NULL DEFAULT '#f57a45',
    accent_color TEXT NOT NULL DEFAULT '#16a68a',
    typography TEXT,
    visual_style TEXT,
    communication_tone TEXT,
    slogan TEXT,
    graphic_elements TEXT,
    brand_manual TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS client_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    filename TEXT NOT NULL,
    original_name TEXT NOT NULL,
    asset_type TEXT NOT NULL DEFAULT 'reference',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_assets_client ON client_assets(client_id);
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
