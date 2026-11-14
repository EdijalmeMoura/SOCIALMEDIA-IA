"""Gera o mapa completo de componentes da instância.

Uso:
    python component_map.py             # escreve docs/COMPONENTES.md
    python component_map.py --stdout    # imprime sem gravar

O documento é montado a partir do código real: rotas Flask registradas,
tabelas do SQLite, arquivos de template/estático, dependências de
`requirements.txt`, variáveis de ambiente conhecidas e testes. Assim ele não
desatualiza quando o produto evolui — basta rodar de novo (ou usar
`/admin/componentes` com a instância instalada).
"""
from __future__ import annotations

import argparse
import ast
import os
import re
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DOC_PATH = ROOT / "docs" / "COMPONENTES.md"

# Ordem de leitura do documento; o restante entra em "outras variáveis".
ENV_GROUPS = (
    ("Instalação e proteção", (
        ("SOCIALMEDIA_AUTO_INSTALL", "1/true/on ativa a instalação automática no boot", "opcional"),
        ("SOCIALMEDIA_AGENCY_NAME", "nome da agência criada na instalação automática", "auto"),
        ("SOCIALMEDIA_OWNER_NAME", "nome do proprietário criado na instalação automática", "auto"),
        ("SOCIALMEDIA_OWNER_EMAIL", "e-mail de acesso do proprietário", "auto"),
        ("SOCIALMEDIA_OWNER_PASSWORD", "senha inicial (8+ caracteres, sem senhas óbvias); remover após instalar", "auto"),
        ("SOCIALMEDIA_PUBLIC_ORIGIN", "origem HTTPS pública; base do callback Meta e dos cookies", "auto"),
        ("SOCIALMEDIA_INSTALL_TOKEN", "token para abrir /install e /install?auto=1", "recomendado"),
    )),
    ("Armazenamento", (
        ("SOCIALMEDIA_DATA", "diretório de dados/uploads (padrão instance/)", "opcional"),
        ("SOCIALMEDIA_DB", "caminho do arquivo SQLite (padrão $SOCIALMEDIA_DATA/socialmedia.db)", "opcional"),
        ("SOCIALMEDIA_INSTALLER_ENV", "caminho do arquivo privado de configuração gerado pelo instalador", "opcional"),
        ("SOCIALMEDIA_ENV_FILE", "arquivo de segredos já existente, reaproveitado pelo instalador", "opcional"),
        ("SOCIALMEDIA_DOTENV", "caminho alternativo do .env carregado no boot (padrão: ./.env)", "opcional"),
    )),
    ("Servidor e segurança", (
        ("SECRET_KEY", "chave de assinatura de sessão; 32+ caracteres, persistente", "obrigatório em produção"),
        ("COOKIE_SECURE", "1 grava cookies com atributo Secure (exigido atrás de HTTPS)", "obrigatório com TLS"),
        ("PORT", "porta TCP escutada pela aplicação (padrão 5000)", "opcional"),
        ("FLASK_DEBUG", "1 liga o modo debug do servidor de desenvolvimento", "nunca em produção"),
    )),
    ("Inteligência artificial (OpenAI)", (
        ("OPENAI_API_KEY", "chave server-side; sem ela planejamento e estúdio ficam desabilitados", "opcional"),
        ("OPENAI_MODEL", "modelo de texto (padrão gpt-4o-mini)", "opcional"),
        ("OPENAI_IMAGE_MODEL", "modelo de imagem (padrão gpt-image-1)", "opcional"),
    )),
    ("Integração Meta", (
        ("META_APP_ID", "App ID do App Meta", "opcional"),
        ("META_APP_SECRET", "App Secret; assina appsecret_proof", "opcional"),
        ("META_GRAPH_VERSION", "versão do Graph no formato vNN.N", "opcional"),
        ("META_REDIRECT_URI", "callback exato <origem>/integrations/meta/callback", "opcional"),
        ("META_TOKEN_ENCRYPTION_KEY", "chave Fernet que cifra tokens de Página", "opcional"),
        ("META_PUBLIC_BASE_URL", "origem HTTPS que a Meta usa para buscar imagens", "opcional"),
    )),
)

COMPONENT_GROUPS = (
    ("Núcleo da aplicação", ("app.py", "installer.py", "meta_integration.py", "component_map.py")),
    ("Interface (templates)", None),
    ("Estilos e scripts (static)", None),
    ("Operação e deploy", (
        "Dockerfile", "compose.yaml", "docker-entrypoint.sh", "requirements.txt",
        ".dockerignore", ".gitignore", "scripts/backup-data.sh",
    )),
    ("Qualidade", None),
    ("Documentação", None),
)


def rel(path) -> str:
    path = Path(path)
    try:
        return str(path.relative_to(ROOT)).replace(os.sep, "/")
    except ValueError:
        return str(path).replace(os.sep, "/")


def table_of_contents(sections) -> str:
    return "\n".join(f"{index}. [{title}](#{anchor})" for index, (title, _body, anchor) in enumerate(sections, start=1))


def anchor(text: str) -> str:
    slug = re.sub(r"[^\w\s-]", "", text.lower(), flags=re.UNICODE)
    return re.sub(r"\s+", "-", slug.strip())


# --- leitura do código -------------------------------------------------------


def load_app_module(data_dir: Path | None = None):
    """Importa a aplicação usando um diretório temporário isolado."""
    temp = tempfile.TemporaryDirectory()
    root = data_dir or Path(temp.name)
    os.environ["SOCIALMEDIA_DATA"] = str(root)
    os.environ["SOCIALMEDIA_DB"] = str(root / "components.db")
    for key in ("SOCIALMEDIA_AUTO_INSTALL", "SOCIALMEDIA_INSTALLER_ENV", "SOCIALMEDIA_ENV_FILE",
                "SOCIALMEDIA_DOTENV",
                "SECRET_KEY", "SOCIALMEDIA_INSTALL_TOKEN"):
        os.environ.pop(key, None)
    sys.path.insert(0, str(ROOT))
    import app as app_module  # noqa: WPS433 (import tardio proposital)

    return app_module, temp


def flask_app(app_module):
    """Accept either the module (``app.app``) or the Flask instance itself."""
    return getattr(app_module, "app", app_module)


def route_rows(app_module) -> list[dict]:
    rows = []
    for rule in sorted(flask_app(app_module).url_map.iter_rules(), key=lambda item: str(item)):
        if rule.endpoint == "static":
            continue
        methods = sorted(rule.methods - {"HEAD", "OPTIONS"})
        pages = sorted(rule.arguments)
        rows.append({
            "rule": str(rule),
            "methods": methods,
            "endpoint": rule.endpoint,
            "area": "instalador" if rule.endpoint.startswith("installer.") else
                    "portal" if rule.endpoint.startswith("portal") else
                    "meta" if "meta" in rule.endpoint else
                    "interno",
            "args": pages,
        })
    return rows


TABLE_GROUPS = (
    ("Núcleo e acesso", ("companies", "users", "client_portal_users", "notifications")),
    ("Clientes e marca", ("clients", "client_branding", "client_assets")),
    ("Conteúdo e IA", ("content_calendar", "posts", "ai_generations", "creative_posts",
                       "post_versions", "feed_items")),
    ("Aprovação do cliente", ("approvals", "approval_events", "comments")),
    ("Agenda interna", ("scheduled_publications", "schedule_events")),
)


def table_group(name: str) -> str:
    for label, names in TABLE_GROUPS:
        if name in names:
            return label
    return "Meta (integração)" if name.startswith("meta_") else "Outros"


def table_rows(app_module) -> list[dict]:
    schema = getattr(app_module, "SCHEMA", None)
    if schema is None:  # Flask instance passed instead of the module
        schema = (ROOT / "app.py").read_text(encoding="utf-8")
    names = re.findall(r"CREATE TABLE IF NOT EXISTS ([a-z_]+)", schema)
    return [{"name": name, "group": table_group(name)} for name in sorted(set(names))]


def module_summary(path: Path) -> dict:
    source = path.read_text(encoding="utf-8")
    tree = ast.parse(source)
    functions = [node.name for node in ast.walk(tree) if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))]
    routes = sum(1 for node in ast.walk(tree) if isinstance(node, ast.FunctionDef)
                 and not node.name.startswith("_"))
    return {
        "path": rel(path),
        "lines": len(source.splitlines()),
        "functions": len(functions),
        "docstring": (ast.get_docstring(tree) or "").strip().splitlines()[0] if ast.get_docstring(tree) else "",
    }


def file_rows(pattern: str, folder: str) -> list[dict]:
    rows = []
    for path in sorted((ROOT / folder).glob(pattern)):
        if path.is_file():
            rows.append({"path": rel(path), "lines": len(path.read_text(encoding="utf-8", errors="ignore").splitlines()),
                         "bytes": path.stat().st_size})
    return rows


def requirement_rows() -> list[dict]:
    rows = []
    for line in (ROOT / "requirements.txt").read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        name, _, version = line.partition(">=")
        rows.append({"name": name.strip(), "constraint": version.strip() or "-", "raw": line})
    return rows


def test_rows() -> list[dict]:
    rows = []
    for path in sorted((ROOT / "tests").glob("test_*.py")):
        source = path.read_text(encoding="utf-8")
        cases = len(re.findall(r"\n    def test_", source))
        rows.append({"path": rel(path), "cases": cases})
    return rows


# --- render ------------------------------------------------------------------


def build_document(app_module, *, generated_at: str | None = None, static_only: bool = False) -> str:
    generated_at = generated_at or datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    routes = route_rows(app_module)
    tables = table_rows(app_module)
    requirements = requirement_rows()
    tests = test_rows()
    templates = file_rows("*.html", "templates")
    static_files = file_rows("*", "static")
    if static_only:  # no per-file diff needed, but keeps the option explicit
        pass
    sections = []

    # 1. Visão geral ----------------------------------------------------------
    sections.append(("Visão geral", f"""
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
| Rotas registradas | {len(routes)} |
| Tabelas SQLite | {len(tables)} |
| Templates | {len(templates)} |
| Dependências Python | {len(requirements)} |

Documento gerado automaticamente em {generated_at} por `python component_map.py`.
""", anchor("Visão geral")))

    # 2. Arquitetura ----------------------------------------------------------
    sections.append(("Arquitetura em uma tela", """
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
""", anchor("Arquitetura em uma tela")))

    # 3. Componentes de código ------------------------------------------------
    core_rows = "\n".join(
        f"| `{row['path']}` | {row['lines']} | {row['functions']} | {row['docstring'][:90]} |"
        for row in (module_summary(ROOT / name) for name in COMPONENT_GROUPS[0][1] if (ROOT / name).exists())
    )
    sections.append(("Componentes de código", f"""
| Arquivo | Linhas | Funções | Papel |
| --- | --- | --- | --- |
{core_rows}

- **app.py** — tudo que é produto: autenticação, clientes, planejamento, estúdio,
  feed, aprovações, agenda, relatórios, notificações, Meta e health check.
- **installer.py** — validação do workspace, arquivo privado `0600`, lock de
  instalação, CSRF próprio, token de acesso, boot automático e telas `/install`.
- **meta_integration.py** — OAuth, troca de tokens, criptografia Fernet,
  chamadas Graph com `appsecret_proof` e publicação manual.
- **component_map.py** — gera este documento a partir do código real.
""", anchor("Componentes de código")))

    # 4. Rotas ---------------------------------------------------------------
    route_lines = "\n".join(
        f"| `{row['rule']}` | {'/'.join(row['methods'])} | {row['area']} | `{row['endpoint']}` |"
        for row in routes
    )
    sections.append(("Rotas HTTP", f"""
{len(routes)} rotas registradas (a rota `static` é omitida).

| Rota | Métodos | Área | Endpoint |
| --- | --- | --- | --- |
{route_lines}
""", anchor("Rotas http")))

    # 5. Banco ---------------------------------------------------------------
    table_lines = "\n".join(f"| `{row['name']}` | {row['group']} |" for row in tables)
    sections.append(("Banco de dados", f"""
SQLite único, criado/migrado no boot por `init_db()` (`CREATE TABLE IF NOT
EXISTS`). Chaves estrangeiras e `busy_timeout` ativos por conexão.

| Tabela | Grupo |
| --- | --- |
{table_lines}

Convenções: `company_id` isola o tenant em todas as consultas; datas são UTC;
arquivos privados ficam em `uploads/` com nome aleatório e verificação por
conteúdo.
""", anchor("Banco de dados")))

    # 6. Templates -----------------------------------------------------------
    template_lines = "\n".join(f"| `{row['path']}` | {row['lines']} |" for row in templates)
    static_lines = "\n".join(f"| `{row['path']}` | {row['lines']} | {row['bytes']:,} B |" for row in static_files)
    sections.append(("Templates e interface", f"""
### Templates ({len(templates)})
| Arquivo | Linhas |
| --- | --- |
{template_lines}

### Assets ({len(static_files)})
| Arquivo | Linhas | Tamanho |
| --- | --- | --- |
{static_lines}
""", anchor("Templates e interface")))

    # 7. Variáveis de ambiente ----------------------------------------------
    known = set()
    blocks = []
    for title, entries in ENV_GROUPS:
        rows = []
        for name, description, level in entries:
            known.add(name)
            rows.append(f"| `{name}` | {level} | {description} |")
        blocks.append(f"### {title}\n| Variável | Nível | Para que serve |\n| --- | --- | --- |\n" + "\n".join(rows))
    sections.append(("Variáveis de ambiente", """
Lidas do processo na inicialização. O arquivo privado gerado pelo instalador
(`installer.env`) é **fallback**: variáveis reais sempre vencem. Segredos nunca
são exibidos, logados ou versionados.

""" + "\n\n".join(blocks) + """
Regras práticas:

- `SOCIALMEDIA_OWNER_PASSWORD` só vale na **criação**; remova depois de instalar.
- Trocar `SECRET_KEY` invalida sessões e links assinados.
- Perder `META_TOKEN_ENCRYPTION_KEY` impede abrir tokens Meta já salvos.
- IA e Meta são **opcionais**: sem elas o núcleo funciona com recursos
  desabilitados (sem dados simulados).
""", anchor("Variáveis de ambiente")))

    # 8. Dependências -------------------------------------------------------
    dep_lines = "\n".join(f"| `{row['name']}` | `>={row['constraint']}` |" for row in requirements)
    sections.append(("Dependências Python", f"""
Requisitos: **Python 3.11+** (a imagem Docker usa 3.12-slim).

| Pacote | Versão |
| --- | --- |
{dep_lines}

Nada além disso: sem Redis, sem Node em runtime, sem banco externo obrigatório.
O JavaScript é servido como arquivo estático, sem etapa de build.
""", anchor("Dependências python")))

    # 9. Runtime ------------------------------------------------------------
    sections.append(("Arquivos e diretórios em runtime", """
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
""", anchor("Arquivos e diretórios em runtime")))

    # 10. Instalação -------------------------------------------------------
    sections.append(("Caminhos de instalação", """
| Modo | Como dispara | Interação | Resultado |
| --- | --- | --- | --- |
| Automático (boot) | `SOCIALMEDIA_AUTO_INSTALL=1` + 5 variáveis | nenhuma | workspace criado antes do primeiro request |
| 1 clique | `GET /install?auto=1` | 1 clique | mesma rotina, com tela de conclusão |
| Assistente | `GET /install` | 3 telas | revisão com segredos mascarados antes de gravar |
| Manual/legado | variáveis de ambiente | — | `/install` responde `410` |

Todos os caminhos: mesma validação, arquivo `0600`, transação única e fechamento
permanente da rota. `/health` informa `installation.automatic`, `pending` e
`recorded`.
""", anchor("Caminhos de instalação")))

    # 11. Deploy -----------------------------------------------------------
    sections.append(("Componentes de execução (Docker e operação)", """
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
""", anchor("Componentes de execução docker e operação")))

    # 12. Qualidade --------------------------------------------------------
    test_lines = "\n".join(f"| `{row['path']}` | {row['cases']} |" for row in tests)
    total_cases = sum(row["cases"] for row in tests)
    sections.append(("Qualidade e testes", f"""
| Arquivo | Casos |
| --- | --- |
{test_lines}
| **Total** | **{total_cases}** |

Cobrem autenticação/CSRF, isolamento multi-tenant, permissões, planejamento,
estúdio, feed, aprovações, agenda, relatórios, Meta via mocks, instalador
(assistente, token, 1 clique, boot automático, entrypoint) e backup.

Rodar: `python -m unittest discover -s tests -v`
""", anchor("Qualidade e testes")))

    # 13. Integrações externas ---------------------------------------------
    sections.append(("Integrações externas (opcionais)", """
| Serviço | Usado para | Fica desabilitado sem |
| --- | --- | --- |
| OpenAI Chat Completions | planejamento editorial e textos do estúdio | `OPENAI_API_KEY` |
| OpenAI Images | geração das artes | `OPENAI_API_KEY` |
| Meta Graph / OAuth | conectar Páginas, publicar manualmente | `META_APP_ID`, `META_APP_SECRET`, `META_GRAPH_VERSION`, `META_REDIRECT_URI`, `META_TOKEN_ENCRYPTION_KEY` |
| Meta busca de mídia | imagem do Instagram | `META_PUBLIC_BASE_URL` (HTTPS público) |

Nenhuma publicação automática em background. Toda ação na Meta é manual,
disparada por um usuário owner/admin e registrada com auditoria.
""", anchor("Integrações externas opcionais")))

    # 14. Checklist --------------------------------------------------------
    sections.append(("Checklist de instalação (mínimo para funcionar)", """
1. Servidor com **Python 3.11+** e as dependências de `requirements.txt`.
2. Diretório de dados gravável e **persistente** (`SOCIALMEDIA_DATA`).
3. Instalação concluída (automática, 1 clique ou assistente) → `SECRET_KEY`
   persistente gerado.
4. HTTPS no proxy + `COOKIE_SECURE=1`.
5. Backup periódico do banco, dos uploads e do `installer.env`.
6. Opcional: `OPENAI_API_KEY` para IA; `META_*` para Facebook/Instagram.
7. Confirmar `GET /health` com `status: ok` e `installation.pending: false`.
8. Definir `SOCIALMEDIA_INSTALL_TOKEN` **antes** de expor a URL publicamente.
""", anchor("Checklist de instalação mínimo para funcionar")))

    parts = [
        "# Componentes do SocialMedia IA",
        "",
        f"> Documento gerado a partir do código em {generated_at}. "
        "Regere com `python component_map.py`.",
        "",
        "## Índice",
        "",
        table_of_contents(sections),
        "",
    ]
    for title, body, _anchor in sections:
        parts.extend([f"## {title}", "", body.strip(), ""])
    parts.extend([
        "---",
        "",
        "Fontes: `app.py`, `installer.py`, `meta_integration.py`, `requirements.txt`, "
        "`templates/`, `static/`, `tests/`, `docs/`. Nenhum segredo é lido ou "
        "impresso por este gerador.",
        "",
    ])
    return "\n".join(parts)


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="Gera docs/COMPONENTES.md a partir do código.")
    parser.add_argument("--stdout", action="store_true", help="imprime o documento sem gravar")
    parser.add_argument("--output", default=str(DOC_PATH), help="caminho de saída")
    args = parser.parse_args(argv)

    app_module, temp = load_app_module()
    try:
        document = build_document(app_module)
    finally:
        temp.cleanup()

    if args.stdout:
        print(document)
        return 0
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(document, encoding="utf-8")
    print(f"{rel(output)} atualizado ({len(document.splitlines())} linhas).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
