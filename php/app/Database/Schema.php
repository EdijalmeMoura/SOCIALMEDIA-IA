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
 *           3 = planejamento (calendário editorial e publicações)
 *           4 = estúdio (artes, versões e itens de feed)
 */
final class Schema
{
    public const VERSAO = 4;

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

        // --- v3: planejamento editorial --------------------------------
        $db->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS content_calendar (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    created_by INTEGER NOT NULL REFERENCES users(id),
    title TEXT NOT NULL,
    period_days INTEGER NOT NULL CHECK (period_days IN (7,15,30)),
    start_date TEXT NOT NULL,
    objective TEXT NOT NULL,
    campaign_context TEXT NOT NULL DEFAULT '',
    networks_json TEXT NOT NULL,
    target_posts INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','in_production')),
    ai_model TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_calendar_tenant_date ON content_calendar(company_id, start_date, status);
CREATE INDEX IF NOT EXISTS idx_calendar_client ON content_calendar(client_id, created_at);

CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    calendar_id INTEGER NOT NULL REFERENCES content_calendar(id) ON DELETE CASCADE,
    position INTEGER NOT NULL DEFAULT 1,
    publication_date TEXT NOT NULL,
    network TEXT NOT NULL,
    theme TEXT NOT NULL,
    strategic_objective TEXT NOT NULL,
    content_type TEXT NOT NULL,
    main_text TEXT NOT NULL,
    caption TEXT NOT NULL,
    hashtags TEXT NOT NULL DEFAULT '',
    cta TEXT NOT NULL DEFAULT '',
    visual_brief TEXT NOT NULL,
    image_suggestion TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'planned' CHECK (status IN ('planned','in_production')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_posts_calendar_date ON posts(calendar_id, publication_date, position);
CREATE INDEX IF NOT EXISTS idx_posts_tenant_client ON posts(company_id, client_id, publication_date);

CREATE TABLE IF NOT EXISTS ai_generations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id),
    feature TEXT NOT NULL,
    model TEXT NOT NULL,
    input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ai_generations_tenant ON ai_generations(company_id, created_at);

-- Estúdio: artes criadas a partir de um assunto, com histórico de versões.
CREATE TABLE IF NOT EXISTS creative_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    created_by INTEGER NOT NULL REFERENCES users(id),
    subject TEXT NOT NULL,
    format_key TEXT NOT NULL,
    canvas_width INTEGER NOT NULL,
    canvas_height INTEGER NOT NULL,
    content_json TEXT NOT NULL,
    layout_json TEXT NOT NULL,
    background_color TEXT NOT NULL DEFAULT '#ffffff',
    image_prompt TEXT NOT NULL DEFAULT '',
    image_path TEXT,
    export_path TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','exported')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_creatives_tenant ON creative_posts(company_id, created_at);
CREATE INDEX IF NOT EXISTS idx_creatives_client ON creative_posts(client_id, created_at);

-- Cada salvamento guarda uma cópia: restaurar nunca perde trabalho.
CREATE TABLE IF NOT EXISTS post_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    creative_id INTEGER NOT NULL REFERENCES creative_posts(id) ON DELETE CASCADE,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id),
    content_json TEXT NOT NULL,
    layout_json TEXT NOT NULL,
    background_color TEXT NOT NULL,
    image_path TEXT,
    export_path TEXT,
    version_label TEXT NOT NULL DEFAULT 'Salvamento',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_post_versions_creative ON post_versions(creative_id, created_at DESC);

-- O feed do perfil monta artes e posts planejados na ordem escolhida.
-- A tabela entra agora porque o estúdio já alimenta o feed; a tela vem depois.
CREATE TABLE IF NOT EXISTS feed_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    creative_id INTEGER REFERENCES creative_posts(id) ON DELETE CASCADE,
    planned_post_id INTEGER REFERENCES posts(id) ON DELETE CASCADE,
    position INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK ((creative_id IS NOT NULL AND planned_post_id IS NULL)
        OR (creative_id IS NULL AND planned_post_id IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS idx_feed_client_position ON feed_items(company_id, client_id, position);
CREATE UNIQUE INDEX IF NOT EXISTS idx_feed_creative_unique ON feed_items(company_id, client_id, creative_id) WHERE creative_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_feed_planned_unique ON feed_items(company_id, client_id, planned_post_id) WHERE planned_post_id IS NOT NULL;
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
