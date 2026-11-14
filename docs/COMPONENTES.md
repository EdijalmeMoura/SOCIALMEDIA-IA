# Componentes do SocialMedia IA

> Documento gerado a partir do código em 2026-11-14 00:53 UTC. Regere com `python component_map.py`.

## Índice

1. [Visão geral](#visão-geral)
2. [Arquitetura em uma tela](#arquitetura-em-uma-tela)
3. [Componentes de código](#componentes-de-código)
4. [Rotas HTTP](#rotas-http)
5. [Banco de dados](#banco-de-dados)
6. [Templates e interface](#templates-e-interface)
7. [Variáveis de ambiente](#variáveis-de-ambiente)
8. [Dependências Python](#dependências-python)
9. [Arquivos e diretórios em runtime](#arquivos-e-diretórios-em-runtime)
10. [Caminhos de instalação](#caminhos-de-instalação)
11. [Componentes de execução (Docker e operação)](#componentes-de-execução-docker-e-operação)
12. [Qualidade e testes](#qualidade-e-testes)
13. [Integrações externas (opcionais)](#integrações-externas-opcionais)
14. [Checklist de instalação (mínimo para funcionar)](#checklist-de-instalação-mínimo-para-funcionar)

## Visão geral

Esta é a lista completa de componentes de uma instância do **SocialMedia IA**:
o que precisa existir para a aplicação rodar, o que é gerado em runtime, o que
é opcional (IA/Meta) e como cada peça se conecta.

| Item | Valor |
| --- | --- |
| Produto | SocialMedia IA — núcleo SaaS para agências (etapas 01–08) |
| Servidor aplicacional | Flask 3 + Waitress (4 threads) |
| Banco | SQLite em arquivo único (`$SOCIALMEDIA_DATA/socialmedia.db`) |
| Arquivos | Uploads e artes em `$SOCIALMEDIA_DATA/uploads` |
| Interface | Jinja2 + CSS/JS puro, sem build de front-end |
| Autenticação | Sessão assinada (Flask) + CSRF por token |
| Instalação | Automática por variáveis, por botão ou assistente em `/install` |
| Áreas | agência, portal do cliente, instalador |
| Rotas registradas | 81 |
| Tabelas SQLite | 26 |
| Templates | 32 |
| Dependências Python | 4 |

Documento gerado automaticamente em 2026-11-14 00:53 UTC por `python component_map.py`.

## Arquitetura em uma tela

```
navegador
   │  HTTPS (proxy reverso do host)
   ▼
Waitress ──► app.py (Flask: rotas, sessão, CSRF, multi-tenant)
   │             ├── installer.py      (instalação automática e /install)
   │             ├── meta_integration.py (OAuth/Graph, fora do browser)
   │             ├── templates/*.html  (Jinja2)
   │             └── static/*.css|js   (interface, sem build)
   ├──► SQLite  $SOCIALMEDIA_DATA/socialmedia.db
   ├──► arquivos $SOCIALMEDIA_DATA/uploads
   ├──► configuração privada $SOCIALMEDIA_DATA/installer.env   (0600)
   ├──► registro $SOCIALMEDIA_DATA/installer-state.json        (0600)
   └──► APIs externas: api.openai.com, graph.facebook.com (opcionais)
```

## Componentes de código

| Arquivo | Linhas | Funções | Papel |
| --- | --- | --- | --- |
| `app.py` | 2903 | 141 |  |
| `installer.py` | 1049 | 60 | First-run installation, automatic or through the browser. |
| `meta_integration.py` | 318 | 16 | Server-side Meta OAuth and Graph API helpers. |
| `component_map.py` | 498 | 14 | Gera o mapa completo de componentes da instância. |

- **app.py** — tudo que é produto: autenticação, clientes, planejamento, estúdio,
  feed, aprovações, agenda, relatórios, notificações, Meta e health check.
- **installer.py** — validação do workspace, arquivo privado `0600`, lock de
  instalação, CSRF próprio, token de acesso, boot automático e telas `/install`.
- **meta_integration.py** — OAuth, troca de tokens, criptografia Fernet,
  chamadas Graph com `appsecret_proof` e publicação manual.
- **component_map.py** — gera este documento a partir do código real.

## Rotas HTTP

81 rotas registradas (a rota `static` é omitida).

| Rota | Métodos | Área | Endpoint |
| --- | --- | --- | --- |
| `/` | GET | interno | `index` |
| `/admin/componentes` | GET | interno | `component_map_page` |
| `/admin/componentes.md` | GET | interno | `component_map_download` |
| `/approvals` | GET/POST | interno | `approvals_page` |
| `/approvals/<int:approval_id>` | GET | interno | `approval_detail` |
| `/approvals/<int:approval_id>/comment` | POST | interno | `agency_approval_comment` |
| `/approvals/<int:approval_id>/media/<path:filename>` | GET | interno | `approval_media` |
| `/approvals/portal-users` | POST | interno | `create_portal_user` |
| `/approvals/portal-users/<int:portal_user_id>/toggle` | POST | interno | `toggle_portal_user` |
| `/clients` | GET | interno | `clients_page` |
| `/clients/<int:client_id>` | GET/POST | interno | `client_detail` |
| `/clients/<int:client_id>/assets` | POST | interno | `upload_asset` |
| `/clients/<int:client_id>/assets/<int:asset_id>/delete` | POST | interno | `delete_asset` |
| `/clients/<int:client_id>/branding/logo` | POST | interno | `upload_logo` |
| `/clients/<int:client_id>/delete` | POST | interno | `client_delete` |
| `/clients/new` | GET/POST | interno | `client_new` |
| `/dashboard` | GET | interno | `dashboard` |
| `/feed` | GET | interno | `feed_page` |
| `/feed/<int:client_id>/add/creative/<int:creative_id>` | POST | interno | `feed_add_creative` |
| `/feed/<int:client_id>/add/post/<int:post_id>` | POST | interno | `feed_add_planned_post` |
| `/feed/<int:client_id>/remove/<int:feed_id>` | POST | interno | `feed_remove_item` |
| `/feed/<int:client_id>/reorder` | POST | interno | `feed_reorder` |
| `/health` | GET | interno | `health` |
| `/install` | GET | instalador | `installer.installer_page` |
| `/install/cancel` | POST | instalador | `installer.installer_cancel` |
| `/install/done` | GET | instalador | `installer.installer_done` |
| `/install/review` | GET | instalador | `installer.installer_review` |
| `/install/run` | POST | instalador | `installer.installer_run` |
| `/install/validate` | POST | instalador | `installer.installer_validate` |
| `/integrations/meta` | GET | meta | `meta_settings_page` |
| `/integrations/meta/<int:connection_id>/check` | POST | meta | `meta_connection_test` |
| `/integrations/meta/<int:connection_id>/disconnect` | POST | meta | `meta_disconnect` |
| `/integrations/meta/callback` | GET | meta | `meta_oauth_callback` |
| `/integrations/meta/connect` | POST | meta | `meta_oauth_start` |
| `/integrations/meta/graph-connectivity-check` | POST | meta | `meta_graph_connectivity_check` |
| `/integrations/meta/public-media-check` | POST | meta | `meta_public_media_check` |
| `/integrations/meta/validation-guide` | GET | meta | `meta_validation_guide` |
| `/login` | GET/POST | interno | `login` |
| `/logout` | POST | interno | `logout` |
| `/meta/media/<path:filename>` | GET | meta | `meta_public_media` |
| `/notifications` | GET | interno | `notification_center` |
| `/notifications/<int:notification_id>/open` | POST | interno | `notification_open` |
| `/notifications/read-all` | POST | interno | `notification_read_all` |
| `/planner` | GET | interno | `planner_page` |
| `/planner/<int:plan_id>` | GET | interno | `planner_detail` |
| `/planner/<int:plan_id>/delete` | POST | interno | `planner_delete` |
| `/planner/<int:plan_id>/production` | POST | interno | `planner_send_to_production` |
| `/planner/generate` | POST | interno | `planner_generate` |
| `/planner/posts/<int:post_id>/delete` | POST | interno | `planner_post_delete` |
| `/planner/posts/<int:post_id>/duplicate` | POST | interno | `planner_post_duplicate` |
| `/planner/posts/<int:post_id>/edit` | GET/POST | interno | `planner_post_edit` |
| `/planner/posts/<int:post_id>/regenerate` | POST | interno | `planner_post_regenerate` |
| `/portal/approvals` | GET | portal | `portal_approvals` |
| `/portal/approvals/<int:approval_id>` | GET | portal | `portal_approval_detail` |
| `/portal/approvals/<int:approval_id>/comment` | POST | portal | `portal_approval_comment` |
| `/portal/approvals/<int:approval_id>/media/<path:filename>` | GET | portal | `portal_approval_media` |
| `/portal/approvals/<int:approval_id>/respond` | POST | portal | `portal_approval_respond` |
| `/portal/login` | GET/POST | portal | `portal_login` |
| `/portal/logout` | POST | portal | `portal_logout` |
| `/register` | GET/POST | interno | `register` |
| `/reports` | GET | interno | `reports_page` |
| `/reports/export.csv` | GET | interno | `reports_export` |
| `/schedule` | GET/POST | interno | `schedule_page` |
| `/schedule/<int:schedule_id>/cancel` | POST | interno | `schedule_cancel` |
| `/schedule/<int:schedule_id>/publish` | POST | interno | `schedule_publish_now` |
| `/schedule/<int:schedule_id>/reschedule` | POST | interno | `schedule_reschedule` |
| `/schedule/meta-attempt/<int:attempt_id>/reconcile` | POST | meta | `schedule_reconcile_meta_attempt` |
| `/studio` | GET | interno | `studio_page` |
| `/studio/<int:creative_id>` | GET | interno | `studio_editor` |
| `/studio/<int:creative_id>/delete` | POST | interno | `studio_delete` |
| `/studio/<int:creative_id>/download` | GET | interno | `studio_download` |
| `/studio/<int:creative_id>/export` | POST | interno | `studio_export` |
| `/studio/<int:creative_id>/image` | POST | interno | `studio_generate_image` |
| `/studio/<int:creative_id>/image/upload` | POST | interno | `studio_upload_image` |
| `/studio/<int:creative_id>/save` | POST | interno | `studio_save` |
| `/studio/<int:creative_id>/variation` | POST | interno | `studio_variation` |
| `/studio/<int:creative_id>/versions/<int:version_id>/restore` | POST | interno | `studio_restore_version` |
| `/studio/files/<path:filename>` | GET | interno | `studio_file` |
| `/studio/generate` | POST | interno | `studio_generate` |
| `/team` | GET/POST | interno | `team_page` |
| `/uploads/<path:filename>` | GET | interno | `uploaded_file` |

## Banco de dados

SQLite único, criado/migrado no boot por `init_db()` (`CREATE TABLE IF NOT
EXISTS`). Chaves estrangeiras e `busy_timeout` ativos por conexão.

| Tabela | Grupo |
| --- | --- |
| `ai_generations` | Conteúdo e IA |
| `approval_events` | Aprovação do cliente |
| `approvals` | Aprovação do cliente |
| `client_assets` | Clientes e marca |
| `client_branding` | Clientes e marca |
| `client_portal_users` | Núcleo e acesso |
| `clients` | Clientes e marca |
| `comments` | Aprovação do cliente |
| `companies` | Núcleo e acesso |
| `content_calendar` | Conteúdo e IA |
| `creative_posts` | Conteúdo e IA |
| `feed_items` | Conteúdo e IA |
| `meta_connection_checks` | Meta (integração) |
| `meta_connection_permissions` | Meta (integração) |
| `meta_graph_connectivity_checks` | Meta (integração) |
| `meta_page_connections` | Meta (integração) |
| `meta_public_media_checks` | Meta (integração) |
| `meta_publication_attempts` | Meta (integração) |
| `meta_publication_reconciliations` | Meta (integração) |
| `meta_token_expiry_notices` | Meta (integração) |
| `notifications` | Núcleo e acesso |
| `post_versions` | Conteúdo e IA |
| `posts` | Conteúdo e IA |
| `schedule_events` | Agenda interna |
| `scheduled_publications` | Agenda interna |
| `users` | Núcleo e acesso |

Convenções: `company_id` isola o tenant em todas as consultas; datas são UTC;
arquivos privados ficam em `uploads/` com nome aleatório e verificação por
conteúdo.

## Templates e interface

### Templates (32)
| Arquivo | Linhas |
| --- | --- |
| `templates/approval_detail.html` | 7 |
| `templates/approvals.html` | 11 |
| `templates/auth.html` | 10 |
| `templates/base.html` | 36 |
| `templates/client_detail.html` | 8 |
| `templates/client_form.html` | 5 |
| `templates/clients.html` | 6 |
| `templates/component_map.html` | 14 |
| `templates/dashboard.html` | 10 |
| `templates/error.html` | 1 |
| `templates/feed.html` | 14 |
| `templates/install.html` | 115 |
| `templates/install_access.html` | 29 |
| `templates/install_auto.html` | 40 |
| `templates/install_base.html` | 49 |
| `templates/install_closed.html` | 32 |
| `templates/install_done.html` | 53 |
| `templates/install_review.html` | 56 |
| `templates/meta_integrations.html` | 9 |
| `templates/notifications.html` | 4 |
| `templates/planner.html` | 15 |
| `templates/planner_detail.html` | 10 |
| `templates/planner_post_edit.html` | 4 |
| `templates/portal_approval_detail.html` | 7 |
| `templates/portal_approvals.html` | 4 |
| `templates/portal_base.html` | 1 |
| `templates/portal_login.html` | 1 |
| `templates/reports.html` | 10 |
| `templates/schedule.html` | 9 |
| `templates/studio.html` | 7 |
| `templates/studio_editor.html` | 9 |
| `templates/team.html` | 5 |

### Assets (14)
| Arquivo | Linhas | Tamanho |
| --- | --- | --- |
| `static/app.css` | 37 | 32,209 B |
| `static/app.js` | 18 | 3,927 B |
| `static/approvals.css` | 4 | 17,237 B |
| `static/feed.css` | 4 | 11,216 B |
| `static/feed.js` | 24 | 4,023 B |
| `static/install.css` | 116 | 11,296 B |
| `static/install.js` | 43 | 1,678 B |
| `static/meta.css` | 7 | 6,360 B |
| `static/notifications.css` | 1 | 1,835 B |
| `static/planner.css` | 4 | 12,887 B |
| `static/reports.css` | 1 | 5,305 B |
| `static/schedule.css` | 3 | 8,222 B |
| `static/studio.css` | 4 | 11,618 B |
| `static/studio.js` | 54 | 7,440 B |

## Variáveis de ambiente

Lidas do processo na inicialização. O arquivo privado gerado pelo instalador
(`installer.env`) é **fallback**: variáveis reais sempre vencem. Segredos nunca
são exibidos, logados ou versionados.

### Instalação e proteção
| Variável | Nível | Para que serve |
| --- | --- | --- |
| `SOCIALMEDIA_AUTO_INSTALL` | opcional | 1/true/on ativa a instalação automática no boot |
| `SOCIALMEDIA_AGENCY_NAME` | auto | nome da agência criada na instalação automática |
| `SOCIALMEDIA_OWNER_NAME` | auto | nome do proprietário criado na instalação automática |
| `SOCIALMEDIA_OWNER_EMAIL` | auto | e-mail de acesso do proprietário |
| `SOCIALMEDIA_OWNER_PASSWORD` | auto | senha inicial (8+ caracteres, sem senhas óbvias); remover após instalar |
| `SOCIALMEDIA_PUBLIC_ORIGIN` | auto | origem HTTPS pública; base do callback Meta e dos cookies |
| `SOCIALMEDIA_INSTALL_TOKEN` | recomendado | token para abrir /install e /install?auto=1 |

### Armazenamento
| Variável | Nível | Para que serve |
| --- | --- | --- |
| `SOCIALMEDIA_DATA` | opcional | diretório de dados/uploads (padrão instance/) |
| `SOCIALMEDIA_DB` | opcional | caminho do arquivo SQLite (padrão $SOCIALMEDIA_DATA/socialmedia.db) |
| `SOCIALMEDIA_INSTALLER_ENV` | opcional | caminho do arquivo privado de configuração gerado pelo instalador |
| `SOCIALMEDIA_ENV_FILE` | opcional | arquivo de segredos já existente, reaproveitado pelo instalador |
| `SOCIALMEDIA_DOTENV` | opcional | caminho alternativo do .env carregado no boot (padrão: ./.env) |

### Servidor e segurança
| Variável | Nível | Para que serve |
| --- | --- | --- |
| `SECRET_KEY` | obrigatório em produção | chave de assinatura de sessão; 32+ caracteres, persistente |
| `COOKIE_SECURE` | obrigatório com TLS | 1 grava cookies com atributo Secure (exigido atrás de HTTPS) |
| `PORT` | opcional | porta TCP escutada pela aplicação (padrão 5000) |
| `FLASK_DEBUG` | nunca em produção | 1 liga o modo debug do servidor de desenvolvimento |

### Inteligência artificial (OpenAI)
| Variável | Nível | Para que serve |
| --- | --- | --- |
| `OPENAI_API_KEY` | opcional | chave server-side; sem ela planejamento e estúdio ficam desabilitados |
| `OPENAI_MODEL` | opcional | modelo de texto (padrão gpt-4o-mini) |
| `OPENAI_IMAGE_MODEL` | opcional | modelo de imagem (padrão gpt-image-1) |

### Integração Meta
| Variável | Nível | Para que serve |
| --- | --- | --- |
| `META_APP_ID` | opcional | App ID do App Meta |
| `META_APP_SECRET` | opcional | App Secret; assina appsecret_proof |
| `META_GRAPH_VERSION` | opcional | versão do Graph no formato vNN.N |
| `META_REDIRECT_URI` | opcional | callback exato <origem>/integrations/meta/callback |
| `META_TOKEN_ENCRYPTION_KEY` | opcional | chave Fernet que cifra tokens de Página |
| `META_PUBLIC_BASE_URL` | opcional | origem HTTPS que a Meta usa para buscar imagens |
Regras práticas:

- `SOCIALMEDIA_OWNER_PASSWORD` só vale na **criação**; remova depois de instalar.
- Trocar `SECRET_KEY` invalida sessões e links assinados.
- Perder `META_TOKEN_ENCRYPTION_KEY` impede abrir tokens Meta já salvos.
- IA e Meta são **opcionais**: sem elas o núcleo funciona com recursos
  desabilitados (sem dados simulados).

## Dependências Python

Requisitos: **Python 3.11+** (a imagem Docker usa 3.12-slim).

| Pacote | Versão |
| --- | --- |
| `Flask` | `>=3.0,<4.0` |
| `Pillow` | `>=10,<13` |
| `cryptography` | `>=41,<46` |
| `waitress` | `>=3,<4` |

Nada além disso: sem Redis, sem Node em runtime, sem banco externo obrigatório.
O JavaScript é servido como arquivo estático, sem etapa de build.

## Arquivos e diretórios em runtime

| Caminho | Permissão | Conteúdo | Recriável? |
| --- | --- | --- | --- |
| `$SOCIALMEDIA_DATA/socialmedia.db` | 0644 | todo o estado do produto | Não (backup!) |
| `$SOCIALMEDIA_DATA/uploads/` | 0750 | logos, referências, artes, exports, anexos | Não (backup!) |
| `$SOCIALMEDIA_DATA/installer.env` | **0600** | `SECRET_KEY`, `OPENAI_*`, `META_*` | Não (backup separado) |
| `$SOCIALMEDIA_DATA/installer-state.json` | **0600** | registro da instalação (sem segredos) | Sim |
| `$SOCIALMEDIA_DATA/.installer.lock` | 0600 | lock de instalação concorrente | Sim |
| `backups/` | — | arquivos `.tar.gz` do script de backup | Ignorado pelo Git |
| `instance/` | — | diretório padrão de dados em desenvolvimento | Ignorado pelo Git |

Nada sensível é gravado fora desse diretório e nenhum segredo aparece em log.

## Caminhos de instalação

| Modo | Como dispara | Interação | Resultado |
| --- | --- | --- | --- |
| Automático (boot) | `SOCIALMEDIA_AUTO_INSTALL=1` + 5 variáveis | nenhuma | workspace criado antes do primeiro request |
| 1 clique | `GET /install?auto=1` | 1 clique | mesma rotina, com tela de conclusão |
| Assistente | `GET /install` | 3 telas | revisão com segredos mascarados antes de gravar |
| Manual/legado | variáveis de ambiente | — | `/install` responde `410` |

Todos os caminhos: mesma validação, arquivo `0600`, transação única e fechamento
permanente da rota. `/health` informa `installation.automatic`, `pending` e
`recorded`.

## Componentes de execução (Docker e operação)

| Componente | Papel |
| --- | --- |
| `Dockerfile` | imagem `python:3.12-slim`, usuário não-root (uid 10001), healthcheck em `/health` |
| `docker-entrypoint.sh` | valida `COOKIE_SECURE`/`SECRET_KEY`, modo instalador, instalação automática, sobe o Waitress |
| `compose.yaml` | instância única, volume `socialmedia-ia-data`, bind apenas em `127.0.0.1`, `cap_drop: ALL` |
| `scripts/backup-data.sh` | para o serviço, arquiva o volume em ponto consistente e reinicia |
| `.github/workflows/ci.yml` | testes, build da imagem, smoke test do contêiner e da instalação automática |

Requisitos de ambiente que **não** são código: DNS, TLS, proxy reverso,
firewall de saída para `api.openai.com`/`graph.facebook.com` e aprovação do App
Meta.

## Qualidade e testes

| Arquivo | Casos |
| --- | --- |
| `tests/test_app.py` | 5 |
| `tests/test_approvals.py` | 10 |
| `tests/test_backup_script.py` | 2 |
| `tests/test_component_map.py` | 9 |
| `tests/test_deployment.py` | 7 |
| `tests/test_dotenv.py` | 7 |
| `tests/test_feed.py` | 3 |
| `tests/test_installer.py` | 23 |
| `tests/test_installer_auto.py` | 18 |
| `tests/test_meta_integration.py` | 19 |
| `tests/test_meta_publication.py` | 13 |
| `tests/test_planner.py` | 6 |
| `tests/test_reports.py` | 3 |
| `tests/test_studio.py` | 6 |
| `tests/test_subpath.py` | 23 |
| **Total** | **154** |

Cobrem autenticação/CSRF, isolamento multi-tenant, permissões, planejamento,
estúdio, feed, aprovações, agenda, relatórios, Meta via mocks, instalador
(assistente, token, 1 clique, boot automático, entrypoint) e backup.

Rodar: `python -m unittest discover -s tests -v`

## Integrações externas (opcionais)

| Serviço | Usado para | Fica desabilitado sem |
| --- | --- | --- |
| OpenAI Chat Completions | planejamento editorial e textos do estúdio | `OPENAI_API_KEY` |
| OpenAI Images | geração das artes | `OPENAI_API_KEY` |
| Meta Graph / OAuth | conectar Páginas, publicar manualmente | `META_APP_ID`, `META_APP_SECRET`, `META_GRAPH_VERSION`, `META_REDIRECT_URI`, `META_TOKEN_ENCRYPTION_KEY` |
| Meta busca de mídia | imagem do Instagram | `META_PUBLIC_BASE_URL` (HTTPS público) |

Nenhuma publicação automática em background. Toda ação na Meta é manual,
disparada por um usuário owner/admin e registrada com auditoria.

## Checklist de instalação (mínimo para funcionar)

1. Servidor com **Python 3.11+** e as dependências de `requirements.txt`.
2. Diretório de dados gravável e **persistente** (`SOCIALMEDIA_DATA`).
3. Instalação concluída (automática, 1 clique ou assistente) → `SECRET_KEY`
   persistente gerado.
4. HTTPS no proxy + `COOKIE_SECURE=1`.
5. Backup periódico do banco, dos uploads e do `installer.env`.
6. Opcional: `OPENAI_API_KEY` para IA; `META_*` para Facebook/Instagram.
7. Confirmar `GET /health` com `status: ok` e `installation.pending: false`.
8. Definir `SOCIALMEDIA_INSTALL_TOKEN` **antes** de expor a URL publicamente.

---

Fontes: `app.py`, `installer.py`, `meta_integration.py`, `requirements.txt`, `templates/`, `static/`, `tests/`, `docs/`. Nenhum segredo é lido ou impresso por este gerador.
