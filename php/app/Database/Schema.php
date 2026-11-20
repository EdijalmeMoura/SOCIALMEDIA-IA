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
 *           5 = aprovações (revisões, histórico, portal do cliente, avisos)
 *           6 = Meta (agenda de publicação, conexões de Página, tentativas)
 *           7 = administração (status de usuário, papel do dono, configurações)
 *           8 = estúdio com contexto (rede, objetivo, tom e referência da arte)
 *           9 = configurações da agência (identidade, contato e preferências)
 */
final class Schema
{
    public const VERSAO = 9;

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

-- Aprovações: o cliente revisa a arte exportada ou a pauta planejada.
CREATE TABLE IF NOT EXISTS client_portal_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    email TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    last_login_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_portal_users_client ON client_portal_users(company_id, client_id, is_active);

CREATE TABLE IF NOT EXISTS approvals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    created_by INTEGER NOT NULL REFERENCES users(id),
    source_type TEXT NOT NULL CHECK (source_type IN ('creative','planned')),
    creative_id INTEGER REFERENCES creative_posts(id) ON DELETE CASCADE,
    planned_post_id INTEGER REFERENCES posts(id) ON DELETE CASCADE,
    cycle_number INTEGER NOT NULL DEFAULT 1,
    title TEXT NOT NULL,
    snapshot_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','approved','changes_requested','rejected')),
    submitted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK ((source_type = 'creative' AND creative_id IS NOT NULL AND planned_post_id IS NULL)
        OR (source_type = 'planned' AND creative_id IS NULL AND planned_post_id IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS idx_approvals_tenant ON approvals(company_id, status, submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_approvals_client ON approvals(client_id, submitted_at DESC);
-- Uma única pendência por conteúdo e um ciclo por vez: o índice protege
-- contra duplo envio mesmo com dois cliques simultâneos.
CREATE UNIQUE INDEX IF NOT EXISTS idx_approvals_pending_creative ON approvals(company_id, client_id, creative_id) WHERE status = 'pending' AND creative_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_approvals_pending_planned ON approvals(company_id, client_id, planned_post_id) WHERE status = 'pending' AND planned_post_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_approvals_creative_cycle ON approvals(company_id, client_id, creative_id, cycle_number) WHERE creative_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_approvals_planned_cycle ON approvals(company_id, client_id, planned_post_id, cycle_number) WHERE planned_post_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS approval_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    approval_id INTEGER NOT NULL REFERENCES approvals(id) ON DELETE CASCADE,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    actor_type TEXT NOT NULL CHECK (actor_type IN ('agency','client')),
    actor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    actor_portal_user_id INTEGER REFERENCES client_portal_users(id) ON DELETE SET NULL,
    previous_status TEXT,
    new_status TEXT NOT NULL,
    note TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_approval_events_approval ON approval_events(approval_id, created_at);

CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    approval_id INTEGER NOT NULL REFERENCES approvals(id) ON DELETE CASCADE,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    author_type TEXT NOT NULL CHECK (author_type IN ('agency','client')),
    author_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    author_portal_user_id INTEGER REFERENCES client_portal_users(id) ON DELETE SET NULL,
    body TEXT NOT NULL DEFAULT '',
    attachment_path TEXT,
    attachment_name TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK ((author_type = 'agency' AND author_user_id IS NOT NULL AND author_portal_user_id IS NULL)
        OR (author_type = 'client' AND author_portal_user_id IS NOT NULL AND author_user_id IS NULL))
);
CREATE INDEX IF NOT EXISTS idx_comments_approval ON comments(approval_id, created_at);

-- Avisos dentro do produto (o cPanel não envia e-mail): cada pessoa vê os seus.
CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    recipient_type TEXT NOT NULL CHECK (recipient_type IN ('agency','client')),
    recipient_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    recipient_portal_user_id INTEGER REFERENCES client_portal_users(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    body TEXT NOT NULL DEFAULT '',
    target_url TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at TEXT,
    CHECK ((recipient_type = 'agency' AND recipient_user_id IS NOT NULL AND recipient_portal_user_id IS NULL)
        OR (recipient_type = 'client' AND recipient_user_id IS NULL AND recipient_portal_user_id IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS idx_notifications_agency_unread ON notifications(company_id, recipient_user_id, read_at, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_client_unread ON notifications(company_id, recipient_portal_user_id, read_at, created_at DESC);

-- Agenda interna: quando cada conteúdo aprovado deve sair. A agenda NÃO
-- publica sozinha — o envio à Meta é sempre um clique de uma pessoa, e só em
-- Facebook/Instagram. TikTok e LinkedIn ficam como registro interno.
CREATE TABLE IF NOT EXISTS scheduled_publications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    approval_id INTEGER NOT NULL REFERENCES approvals(id) ON DELETE CASCADE,
    scheduled_by INTEGER NOT NULL REFERENCES users(id),
    network TEXT NOT NULL CHECK (network IN ('Instagram','Facebook','TikTok','LinkedIn')),
    scheduled_at_utc TEXT NOT NULL,
    timezone TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled','cancelled')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_scheduled_publications_tenant ON scheduled_publications(company_id, status, scheduled_at_utc);
-- Um horário ativo por conteúdo + rede: dois horários iguais seriam ambíguos.
CREATE UNIQUE INDEX IF NOT EXISTS idx_scheduled_publications_active_channel ON scheduled_publications(approval_id, network) WHERE status = 'scheduled';
CREATE TABLE IF NOT EXISTS schedule_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scheduled_publication_id INTEGER NOT NULL REFERENCES scheduled_publications(id) ON DELETE CASCADE,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    actor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    event_type TEXT NOT NULL CHECK (event_type IN ('scheduled','rescheduled','cancelled')),
    scheduled_at_utc TEXT NOT NULL,
    timezone TEXT NOT NULL,
    note TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_schedule_events_item ON schedule_events(scheduled_publication_id, created_at);

-- Conexões Meta: um registro por Página do Facebook da agência. O token da
-- Página é guardado CRIPTOGRAFADO (AES-256-GCM) e nunca volta para a tela.
CREATE TABLE IF NOT EXISTS meta_page_connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    connected_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    page_id TEXT NOT NULL,
    page_name TEXT NOT NULL,
    instagram_user_id TEXT,
    instagram_username TEXT,
    page_access_token_encrypted TEXT NOT NULL,
    token_expires_at TEXT,
    connected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (company_id, page_id)
);
CREATE INDEX IF NOT EXISTS idx_meta_connections_tenant ON meta_page_connections(company_id, page_name);
CREATE TABLE IF NOT EXISTS meta_token_expiry_notices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    connection_id INTEGER NOT NULL REFERENCES meta_page_connections(id) ON DELETE CASCADE,
    token_expires_at TEXT NOT NULL,
    notice_type TEXT NOT NULL CHECK (notice_type IN ('expiring','expired')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (connection_id, token_expires_at, notice_type)
);
CREATE TABLE IF NOT EXISTS meta_connection_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    connection_id INTEGER NOT NULL REFERENCES meta_page_connections(id) ON DELETE CASCADE,
    permission TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('granted','declined','unknown')),
    checked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (connection_id, permission)
);
CREATE INDEX IF NOT EXISTS idx_meta_permissions_tenant ON meta_connection_permissions(company_id, connection_id);
CREATE TABLE IF NOT EXISTS meta_connection_checks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    connection_id INTEGER NOT NULL REFERENCES meta_page_connections(id) ON DELETE CASCADE,
    checked_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    status TEXT NOT NULL CHECK (status IN ('ok','failed')),
    details TEXT NOT NULL DEFAULT '',
    checked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_meta_connection_checks_latest ON meta_connection_checks(company_id, connection_id, checked_at DESC);
CREATE TABLE IF NOT EXISTS meta_graph_connectivity_checks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    checked_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    status TEXT NOT NULL CHECK (status IN ('ok','failed')),
    details TEXT NOT NULL DEFAULT '',
    checked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS meta_public_media_checks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    checked_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    base_url TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL CHECK (status IN ('ok','failed')),
    details TEXT NOT NULL DEFAULT '',
    checked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Cada clique em "publicar" vira uma tentativa. O índice único garante, no
-- banco, que um horário nunca tenha duas publicações ativas ao mesmo tempo.
CREATE TABLE IF NOT EXISTS meta_publication_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    schedule_id INTEGER NOT NULL REFERENCES scheduled_publications(id) ON DELETE CASCADE,
    connection_id INTEGER REFERENCES meta_page_connections(id) ON DELETE SET NULL,
    attempted_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    network TEXT NOT NULL CHECK (network IN ('Instagram','Facebook')),
    status TEXT NOT NULL CHECK (status IN ('publishing','published','failed','unknown')),
    remote_publication_id TEXT,
    error_message TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_meta_publications_schedule ON meta_publication_attempts(company_id, schedule_id, created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_meta_publications_single_active ON meta_publication_attempts(schedule_id) WHERE status IN ('publishing','published','unknown');
CREATE TABLE IF NOT EXISTS meta_publication_reconciliations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    attempt_id INTEGER NOT NULL UNIQUE REFERENCES meta_publication_attempts(id) ON DELETE CASCADE,
    reconciled_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    outcome TEXT NOT NULL CHECK (outcome IN ('confirmed_published','confirmed_not_published')),
    remote_publication_id TEXT,
    note TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);

        // --- v7: administração -----------------------------------------
        // `users.role` já existia; o dono do sistema é um papel novo. "super"
        // enxerga todas as agências; owner/admin/editor/viewer seguem valendo
        // dentro da própria agência.
        $db->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '',
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS admin_actions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    target_company_id INTEGER,
    action TEXT NOT NULL,
    detail TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_admin_actions_created ON admin_actions(created_at DESC);
SQL);

        // Colunas novas entram por ALTER: criação de tabela não altera tabela
        // que já existe em quem instalou antes.
        self::addColumn($db, 'users', 'is_active', 'INTEGER NOT NULL DEFAULT 1');
        // `role` tem CHECK ('owner','admin','editor','viewer') desde a v1.
        // Acrescentar 'super' exigiria reconstruir a tabela (e perder dados em
        // quem já instalou); uma coluna booleana diz a mesma coisa sem risco.
        self::addColumn($db, 'users', 'is_super', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumn($db, 'companies', 'is_active', 'INTEGER NOT NULL DEFAULT 1');
        self::addColumn($db, 'companies', 'owner_email', 'TEXT');
        self::addColumn($db, 'companies', 'notes', 'TEXT');
        self::addColumn($db, 'users', 'last_seen_at', 'TEXT');

        // --- v8: o pedido da arte fica guardado -------------------------
        // A criação agora pergunta para qual rede, com qual objetivo e em que
        // tom. Sem guardar, a próxima edição perderia essas escolhas e o
        // contador de caracteres não teria com o que comparar.
        // Linhas antigas ficam com o padrão previsto no ALTER (nada a migrar).
        self::addColumn($db, 'creative_posts', 'network', "TEXT NOT NULL DEFAULT 'generico'");
        self::addColumn($db, 'creative_posts', 'objective', "TEXT NOT NULL DEFAULT ''");
        self::addColumn($db, 'creative_posts', 'tone', "TEXT NOT NULL DEFAULT 'marca'");
        self::addColumn($db, 'creative_posts', 'reference', "TEXT NOT NULL DEFAULT ''");

        // --- v9: configurações da agência ------------------------------
        // Dados que a agência assina embaixo (razão social, CNPJ, contato) e
        // que antes só existiam no nome criado na instalação. Nada obrigatório:
        // quem já instalou continua funcionando com os campos vazios.
        self::addColumn($db, 'companies', 'legal_name', 'TEXT');
        self::addColumn($db, 'companies', 'document', 'TEXT');
        self::addColumn($db, 'companies', 'contact_email', 'TEXT');
        self::addColumn($db, 'companies', 'contact_phone', 'TEXT');
        self::addColumn($db, 'companies', 'website', 'TEXT');

        $versao = (int) ($db->scalar('SELECT MAX(version) FROM schema_version') ?? 0);
        if ($versao < self::VERSAO) {
            $db->run('INSERT INTO schema_version (version) VALUES (?)', [self::VERSAO]);
        }
    }

    /**
     * Acrescenta uma coluna só se ela ainda não existir.
     *
     * SQLite não tem `ADD COLUMN IF NOT EXISTS`, e repetir o ALTER derruba a
     * migração inteira. Por isso a checagem é feita em pragma_table_info.
     */
    private static function addColumn(Database $db, string $tabela, string $coluna, string $definicao): void
    {
        $existe = $db->scalar(
            'SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?',
            [$tabela, $coluna]
        );
        if ((int) ($existe ?? 0) === 0) {
            $db->pdo()->exec('ALTER TABLE ' . $tabela . ' ADD COLUMN ' . $coluna . ' ' . $definicao);
        }
    }

    public static function version(Database $db): int
    {
        return (int) ($db->scalar('SELECT MAX(version) FROM schema_version') ?? 0);
    }
}
