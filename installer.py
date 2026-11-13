"""First-run installation, automatic or through the browser.

Bootstraps an instance without a shell: inspects the environment, validates
the data directory, generates local secrets, writes a private configuration
file (``0600``) and creates the owner account. It stays available only while
the instance has no installation record and no accounts, then closes forever.

Two entry points share the same validation and persistence:

* ``run_automatic_installation`` — runs at boot when the host provides
  ``SOCIALMEDIA_AUTO_INSTALL=1`` plus the owner credentials. Nothing is asked
  in the browser; this is what "deploy and forget" needs.
* ``create_installer_blueprint`` — the ``/install`` assistant, for the case
  where the operator prefers to configure everything on screen.

Secrets are never logged, never echoed to the browser and never written to the
installation record. The configuration file is read back by the application on
every boot, so the installation survives restarts without extra hosting steps.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import logging
import os
import re
import secrets
import sqlite3
import time
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit

from cryptography.fernet import Fernet
from flask import Blueprint, current_app, make_response, redirect, render_template, request, session, url_for
from werkzeug.security import generate_password_hash

try:  # POSIX advisory locking; Windows falls back to a best-effort no-op.
    import fcntl
except ImportError:  # pragma: no cover - Windows only
    fcntl = None

INSTALLER_SCHEMA = 1
STATE_FILENAME = "installer-state.json"
ENV_FILENAME = "installer.env"
CSRF_COOKIE = "smia_install_csrf"
CSRF_FIELD = "install_csrf"
ACCESS_TOKEN_ENV = "SOCIALMEDIA_INSTALL_TOKEN"
AUTO_INSTALL_ENV = "SOCIALMEDIA_AUTO_INSTALL"
AUTO_INSTALL_KEYS = (
    "SOCIALMEDIA_AGENCY_NAME",
    "SOCIALMEDIA_OWNER_NAME",
    "SOCIALMEDIA_OWNER_EMAIL",
    "SOCIALMEDIA_OWNER_PASSWORD",
    "SOCIALMEDIA_PUBLIC_ORIGIN",
)
MIN_PASSWORD_LENGTH = 8
DRAFT_TTL_SECONDS = 1800
DONE_TICKET_TTL_SECONDS = 900
MAX_DRAFTS = 8
ACCESS_ATTEMPT_LIMIT = 10
ACCESS_ATTEMPT_WINDOW = 300

# Only these keys are read back from the generated file. Anything else is
# ignored, so a tampered file cannot inject arbitrary process settings.
CONFIG_ENV_KEYS = (
    "SECRET_KEY",
    "COOKIE_SECURE",
    "OPENAI_API_KEY",
    "OPENAI_MODEL",
    "OPENAI_IMAGE_MODEL",
    "META_APP_ID",
    "META_APP_SECRET",
    "META_GRAPH_VERSION",
    "META_REDIRECT_URI",
    "META_TOKEN_ENCRYPTION_KEY",
    "META_PUBLIC_BASE_URL",
)

ENV_VALUE_RE = re.compile(r"[A-Za-z0-9_\-.:/+@=%,&?~]{1,600}")
EMAIL_RE = re.compile(r"[^@\s]+@[^@\s]+\.[^@\s]+")
MODEL_RE = re.compile(r"[A-Za-z0-9._:\-]{1,64}")
GRAPH_VERSION_RE = re.compile(r"v[0-9]+\.[0-9]+")
OPENAI_KEY_RE = re.compile(r"sk-[A-Za-z0-9_\-]{16,200}")
META_APP_ID_RE = re.compile(r"[0-9]{5,32}")
WEAK_PASSWORDS = {
    "12345678", "123456789", "1234567890", "senha123", "senha1234",
    "password", "password1", "password123", "admin123", "admin12345",
    "socialmedia", "qwerty123", "instalador",
}

LOGGER = logging.getLogger("socialmedia.installer")

_DRAFTS: dict[str, dict] = {}
_DONE_TICKETS: dict[str, float] = {}
_ACCESS_ATTEMPTS: dict[str, tuple[int, float]] = {}


class InstallerError(Exception):
    """Safe-to-display installer failure."""


@dataclass(frozen=True)
class InstallerPaths:
    data_dir: Path
    db_path: Path
    env_path: Path

    @property
    def state_path(self) -> Path:
        # The installation record always sits next to the private config file,
        # so moving SOCIALMEDIA_INSTALLER_ENV moves both.
        configured = os.environ.get("SOCIALMEDIA_INSTALLER_ENV", "").strip()
        if configured:
            candidate = Path(configured)
            return candidate.parent / STATE_FILENAME
        return Path(self.data_dir) / STATE_FILENAME


# --- primitives --------------------------------------------------------------


def clean(value, limit=500) -> str:
    return (value if isinstance(value, str) else "").strip()[:limit]


def mask(secret: str, keep: int = 4) -> str:
    """Non-reversible hint for a secret; the value itself is never returned."""
    if not secret:
        return "não configurado"
    tail = secret[-keep:] if len(secret) > keep else ""
    return f"definido • final {tail}" if tail else "definido"


def mask_email(email: str) -> str:
    """Show only the first character and the domain of an e-mail address."""
    email = clean(email, 160)
    if "@" not in email:
        return "•••"
    local, _, domain = email.partition("@")
    return f"{local[:1]}•••@{domain}"


def now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def write_private_file(path, text: str) -> Path:
    """Atomic write with 0600 permissions, safe for bind-mounted volumes."""
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(f".{path.name}.{secrets.token_hex(6)}.tmp")
    descriptor = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            handle.write(text)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp, path)
        os.chmod(path, 0o600)
    except BaseException:
        try:
            tmp.unlink(missing_ok=True)
        except OSError:
            pass
        raise
    return path


def parse_env_text(text: str) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw_line in text.splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        key, value = key.strip(), value.strip()
        if key in CONFIG_ENV_KEYS and ENV_VALUE_RE.fullmatch(value):
            values[key] = value
    return values


def load_env_file(path) -> dict[str, str]:
    if not path:
        return {}
    try:
        return parse_env_text(Path(path).read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError):
        return {}


def apply_env_file(path, env=None) -> dict[str, str]:
    """Load generated configuration as a fallback: real env vars always win."""
    env = os.environ if env is None else env
    values = load_env_file(path)
    for key, value in values.items():
        env.setdefault(key, value)
    return values


def write_env_file(path, values: dict[str, str], header: str = "") -> Path:
    serialized: list[str] = []
    for key, value in values.items():
        if key not in CONFIG_ENV_KEYS:
            raise InstallerError(f"Chave de configuração não permitida: {key}.")
        if not ENV_VALUE_RE.fullmatch(value or ""):
            raise InstallerError(f"Valor inválido para {key}. Use apenas caracteres seguros, sem espaços ou aspas.")
        serialized.append(f"{key}={value}")
    lines = [
        "# Configuração gerada pelo instalador pelo navegador do SocialMedia IA.",
        "# Contém segredos: mantenha permissão 0600 e inclua em backup seguro.",
        f"# Gerado em {now_iso()}.",
    ]
    if header:
        lines.append(f"# {header}")
    return write_private_file(path, "\n".join([*lines, "", *serialized]) + "\n")


def load_state(state_path) -> dict | None:
    try:
        payload = json.loads(Path(state_path).read_text(encoding="utf-8"))
    except (OSError, ValueError, UnicodeDecodeError):
        return None
    return payload if isinstance(payload, dict) and payload.get("installed_at") else None


def save_state(state_path, record: dict) -> None:
    write_private_file(state_path, json.dumps(record, indent=2, ensure_ascii=False) + "\n")


def count_accounts(db_path) -> int:
    """Read-only account count; a missing or unreadable database counts as empty."""
    db_path = Path(db_path)
    if not db_path.exists():
        return 0
    try:
        connection = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True, timeout=5)
    except sqlite3.Error:
        return 0
    try:
        return int(connection.execute("SELECT COUNT(*) FROM users").fetchone()[0])
    except sqlite3.Error:
        return 0
    finally:
        connection.close()


def installer_needed(paths: InstallerPaths) -> bool:
    """True while there is no installation record and no account."""
    if load_state(paths.state_path) is not None:
        return False
    return count_accounts(paths.db_path) == 0


def access_token_configured(env=None) -> str:
    env = os.environ if env is None else env
    return (env.get(ACCESS_TOKEN_ENV) or "").strip()


def environment_checks(paths: InstallerPaths, secure_request: bool, env=None) -> list[dict]:
    """Operational checklist for the first screen. Never includes secrets."""
    env = os.environ if env is None else env
    checks: list[dict] = []

    try:
        probe = Path(paths.data_dir) / f".installer-probe-{secrets.token_hex(4)}"
        probe.parent.mkdir(parents=True, exist_ok=True)
        probe.write_text("ok", encoding="utf-8")
        probe.unlink()
        checks.append({"label": "Armazenamento de dados", "status": "ok",
                       "detail": f"Diretório gravável: {paths.data_dir}"})
    except OSError as exc:
        checks.append({"label": "Armazenamento de dados", "status": "fail",
                       "detail": f"Sem permissão de escrita em {paths.data_dir} ({exc.__class__.__name__})."})

    accounts = count_accounts(paths.db_path)
    checks.append({
        "label": "Banco de dados",
        "status": "ok" if accounts == 0 else "fail",
        "detail": "SQLite pronto para o primeiro workspace." if accounts == 0
        else f"Já existem {accounts} conta(s): instalação encerrada.",
    })

    if load_env_file(paths.env_path):
        checks.append({"label": "Configuração existente", "status": "warn",
                       "detail": f"Já existe {paths.env_path}; as chaves salvas serão preservadas."})
    else:
        checks.append({"label": "Arquivo de configuração", "status": "ok",
                       "detail": f"Será criado com permissão 0600: {paths.env_path}"})

    https_ok = secure_request or env.get("COOKIE_SECURE") == "1"
    checks.append({
        "label": "HTTPS e cookies",
        "status": "ok" if https_ok else "warn",
        "detail": "Conexão segura detectada; cookies com atributo Secure." if https_ok
        else "Sem HTTPS confirmado: os cookies serão gravados sem o atributo Secure.",
    })

    checks.append({
        "label": "Proteção do instalador",
        "status": "ok" if access_token_configured(env) else "warn",
        "detail": "Token de instalação exigido no link." if access_token_configured(env)
        else f"Sem {ACCESS_TOKEN_ENV}: qualquer pessoa com acesso à URL pode instalar até a conta ser criada.",
    })
    return checks


# --- request helpers ---------------------------------------------------------


def detected_origin(request_obj) -> str:
    proto = (request_obj.headers.get("X-Forwarded-Proto", "") or "").split(",")[0].strip().lower()
    scheme = proto if proto in {"http", "https"} else request_obj.scheme
    return f"{scheme}://{request_obj.host}"


def request_is_secure(request_obj) -> bool:
    proto = (request_obj.headers.get("X-Forwarded-Proto", "") or "").split(",")[0].strip().lower()
    return proto == "https" or request_obj.is_secure


def validate_public_origin(raw: str) -> str:
    parsed = urlsplit(raw or "")
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise InstallerError("Informe a URL pública com esquema e domínio, por exemplo https://app.suaagencia.com.")
    if parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in {"", "/"}:
        raise InstallerError("A URL pública deve ter apenas esquema e domínio, sem caminho, parâmetros ou credenciais.")
    if parsed.scheme != "https" and parsed.hostname not in {"localhost", "127.0.0.1"}:
        raise InstallerError("A URL pública precisa usar HTTPS (HTTP só é aceito em localhost para desenvolvimento).")
    return f"{parsed.scheme}://{parsed.netloc}".rstrip("/")


def _register_access_failure(bucket: str) -> bool:
    """Record a rejected access attempt; False once the caller is throttled."""
    attempts, since = _ACCESS_ATTEMPTS.get(bucket, (0, time.time()))
    if time.time() - since > ACCESS_ATTEMPT_WINDOW:
        attempts, since = 0, time.time()
    attempts += 1
    _ACCESS_ATTEMPTS[bucket] = (attempts, since)
    return attempts <= ACCESS_ATTEMPT_LIMIT


def reset_access_failures(bucket: str) -> None:
    _ACCESS_ATTEMPTS.pop(bucket, None)


# --- validation --------------------------------------------------------------


def build_installation(form, *, existing_env: dict[str, str], origin: str, secure_request: bool,
                       require_confirmation: bool = True):
    """Validate the submitted form and return ``(payload, errors)``.

    The password is hashed right away; the plaintext never enters the payload.
    ``require_confirmation`` is disabled for automatic installs, where the
    operator cannot retype the password.
    """
    errors: list[str] = []
    agency = clean(form.get("agency_name"), 120)
    name = clean(form.get("admin_name"), 100)
    email = clean(form.get("admin_email"), 160).lower()
    password = form.get("admin_password", "") or ""
    confirmation = form.get("admin_password_confirm", "") or ""

    if not agency:
        errors.append("Informe o nome da agência.")
    if not name:
        errors.append("Informe o nome do administrador.")
    if not EMAIL_RE.fullmatch(email):
        errors.append("Informe um e-mail válido para o administrador.")
    if len(password) < MIN_PASSWORD_LENGTH:
        errors.append(f"A senha precisa ter pelo menos {MIN_PASSWORD_LENGTH} caracteres.")
    elif password.lower() in WEAK_PASSWORDS or password.lower() == email:
        errors.append("Escolha uma senha menos previsível.")
    elif require_confirmation and not confirmation:
        errors.append("Confirme a senha do administrador.")
    elif require_confirmation and password != confirmation:
        errors.append("A confirmação da senha não confere.")

    try:
        public_origin = validate_public_origin(clean(form.get("public_origin"), 200) or origin)
    except InstallerError as exc:
        public_origin = ""
        errors.append(str(exc))

    ai = None
    api_key = clean(form.get("openai_api_key"), 400)
    stored_key = existing_env.get("OPENAI_API_KEY", "")
    model = clean(form.get("openai_model"), 64) or existing_env.get("OPENAI_MODEL") or "gpt-4o-mini"
    image_model = clean(form.get("openai_image_model"), 64) or existing_env.get("OPENAI_IMAGE_MODEL") or "gpt-image-1"
    if api_key or stored_key:
        if api_key and not OPENAI_KEY_RE.fullmatch(api_key):
            errors.append("A chave da OpenAI deve começar com sk- e usar apenas letras, números, _ e -.")
        if not MODEL_RE.fullmatch(model) or not MODEL_RE.fullmatch(image_model):
            errors.append("Os modelos devem usar apenas letras, números, ponto, dois-pontos, _ e -.")
        ai = {"api_key": api_key or None, "model": model, "image_model": image_model}

    meta = None
    app_id = clean(form.get("meta_app_id"), 40)
    app_secret = clean(form.get("meta_app_secret"), 200)
    graph_version = clean(form.get("meta_graph_version"), 20) or existing_env.get("META_GRAPH_VERSION") or "v23.0"
    if app_id or app_secret:
        if not META_APP_ID_RE.fullmatch(app_id):
            errors.append("O App ID da Meta deve conter de 5 a 32 dígitos.")
        if len(app_secret) < 16 or not ENV_VALUE_RE.fullmatch(app_secret):
            errors.append("O App Secret da Meta deve ter ao menos 16 caracteres seguros, sem espaços.")
        if not GRAPH_VERSION_RE.fullmatch(graph_version):
            errors.append("A versão do Graph deve seguir o formato vNN.N, por exemplo v23.0.")
        if public_origin and urlsplit(public_origin).scheme != "https" and urlsplit(public_origin).hostname not in {"localhost", "127.0.0.1"}:
            errors.append("A integração Meta exige uma URL pública HTTPS.")
        if public_origin:
            meta = {
                "app_id": app_id,
                "app_secret": app_secret,
                "graph_version": graph_version,
                "redirect_uri": f"{public_origin}/integrations/meta/callback",
                "public_media_base_url": existing_env.get("META_PUBLIC_BASE_URL") or public_origin,
            }

    if errors:
        return None, errors

    secure_origin = public_origin.startswith("https://")
    cookie_secure = "1" if (os.environ.get("COOKIE_SECURE") == "1" or secure_request or secure_origin) else "0"
    payload = {
        "agency_name": agency,
        "admin_name": name,
        "admin_email": email,
        "password_hash": generate_password_hash(password),
        "public_origin": public_origin,
        "cookie_secure": cookie_secure,
        "ai": ai,
        "meta": meta,
        "created_at": time.time(),
    }
    return payload, []


def build_env_values(payload: dict, existing_env: dict[str, str]) -> dict[str, str]:
    """Merge generated secrets with the configuration already on disk."""
    values = {key: value for key, value in existing_env.items() if key in CONFIG_ENV_KEYS}
    values["SECRET_KEY"] = existing_env.get("SECRET_KEY") or secrets.token_urlsafe(48)
    values["COOKIE_SECURE"] = "1" if payload.get("cookie_secure") == "1" else "0"
    if payload.get("ai"):
        values["OPENAI_API_KEY"] = payload["ai"]["api_key"] or existing_env.get("OPENAI_API_KEY", "")
        values["OPENAI_MODEL"] = payload["ai"]["model"]
        values["OPENAI_IMAGE_MODEL"] = payload["ai"]["image_model"]
    if payload.get("meta"):
        meta = payload["meta"]
        values["META_APP_ID"] = meta["app_id"]
        values["META_APP_SECRET"] = meta["app_secret"]
        values["META_GRAPH_VERSION"] = meta["graph_version"]
        values["META_REDIRECT_URI"] = meta["redirect_uri"]
        values["META_PUBLIC_BASE_URL"] = meta["public_media_base_url"]
        values["META_TOKEN_ENCRYPTION_KEY"] = (
            existing_env.get("META_TOKEN_ENCRYPTION_KEY") or Fernet.generate_key().decode("ascii")
        )
    return {key: value for key, value in values.items() if value}


def create_owner_account(db_path, payload: dict) -> tuple[int, int]:
    """Create agency and owner in one transaction; abort if someone got there first."""
    slug_base = re.sub(r"[^a-z0-9]+", "-", payload["agency_name"].lower()).strip("-") or "agencia"
    slug = f"{slug_base[:60]}-{secrets.token_hex(3)}"
    connection = sqlite3.connect(str(db_path), timeout=10, isolation_level=None)
    try:
        connection.execute("PRAGMA foreign_keys = ON")
        connection.execute("BEGIN IMMEDIATE")
        if connection.execute("SELECT COUNT(*) FROM users").fetchone()[0]:
            connection.execute("ROLLBACK")
            raise InstallerError("Já existe uma conta nesta instância. O instalador foi encerrado.")
        company_id = connection.execute(
            "INSERT INTO companies(name,slug) VALUES(?,?)", (payload["agency_name"], slug)
        ).lastrowid
        user_id = connection.execute(
            "INSERT INTO users(company_id,name,email,password_hash,role) VALUES(?,?,?,?,'owner')",
            (company_id, payload["admin_name"], payload["admin_email"], payload["password_hash"]),
        ).lastrowid
        connection.execute("COMMIT")
        return int(company_id), int(user_id)
    except BaseException:
        try:
            connection.execute("ROLLBACK")
        except sqlite3.Error:
            pass
        raise
    finally:
        connection.close()


@contextmanager
def installation_lock(data_dir, timeout: float = 15.0):
    """Serialise installations so two browser tabs cannot interleave writes."""
    if fcntl is None:  # pragma: no cover - Windows only
        yield
        return
    lock_path = Path(data_dir) / ".installer.lock"
    try:
        lock_path.parent.mkdir(parents=True, exist_ok=True)
        descriptor = os.open(lock_path, os.O_WRONLY | os.O_CREAT, 0o600)
    except OSError:
        yield
        return
    try:
        deadline = time.monotonic() + timeout
        while True:
            try:
                fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except OSError:
                if time.monotonic() > deadline:
                    raise InstallerError("Outra instalação está em andamento nesta instância. Aguarde e tente novamente.")
                time.sleep(0.2)
        yield
    finally:
        try:
            fcntl.flock(descriptor, fcntl.LOCK_UN)
        finally:
            os.close(descriptor)


def execute_installation(payload: dict, paths: InstallerPaths, apply_runtime_config=None) -> dict:
    """Persist configuration, then create the owner and record the installation.

    Configuration is written first: a failure can never leave an installed
    workspace running with the development secret.
    """
    with installation_lock(Path(paths.data_dir)):
        if load_state(paths.state_path) is not None:
            raise InstallerError("Esta instância já está instalada.")
        if count_accounts(paths.db_path):
            raise InstallerError("Já existe uma conta nesta instância. O instalador foi encerrado.")

        env_existed = Path(paths.env_path).exists()
        values = build_env_values(payload, load_env_file(paths.env_path))
        write_env_file(paths.env_path, values, header=f"Origem pública: {payload['public_origin']}")
        try:
            company_id, user_id = create_owner_account(paths.db_path, payload)
        except BaseException:
            # Nothing was installed: never delete a configuration file that was
            # already on disk before this attempt.
            if not env_existed:
                try:
                    Path(paths.env_path).unlink(missing_ok=True)
                except OSError:
                    pass
            raise

        record = {
            "schema": INSTALLER_SCHEMA,
            "installed_at": now_iso(),
            "agency_name": payload["agency_name"],
            "owner_email": payload["admin_email"],
            "env_file": str(paths.env_path),
            "public_origin": payload["public_origin"],
            "configured": {
                "secret_key": True,
                "ai": bool(payload.get("ai")),
                "meta": bool(payload.get("meta")),
                "public_media": bool(payload.get("meta")),
                "cookie_secure": values.get("COOKIE_SECURE") == "1",
            },
            "company_id": company_id,
            "user_id": user_id,
        }
        try:
            save_state(paths.state_path, record)
        except OSError as exc:
            raise InstallerError(
                f"A conta foi criada, mas o registro da instalação não pôde ser gravado ({exc.__class__.__name__})."
            ) from exc
        if apply_runtime_config is not None:
            apply_runtime_config(values)
        return record


# --- automatic installation (boot from environment) --------------------------


def auto_install_requested(env=None) -> bool:
    env = os.environ if env is None else env
    return (env.get(AUTO_INSTALL_ENV) or "").strip().lower() in {"1", "true", "yes", "on"}


def auto_install_missing(env=None) -> list[str]:
    env = os.environ if env is None else env
    return [key for key in AUTO_INSTALL_KEYS if not (env.get(key) or "").strip()]


def automatic_form(env=None) -> dict:
    """Translate the deployment variables into the installer's form shape."""
    env = os.environ if env is None else env
    definition = {
        "agency_name": "SOCIALMEDIA_AGENCY_NAME",
        "admin_name": "SOCIALMEDIA_OWNER_NAME",
        "admin_email": "SOCIALMEDIA_OWNER_EMAIL",
        "admin_password": "SOCIALMEDIA_OWNER_PASSWORD",
        "public_origin": "SOCIALMEDIA_PUBLIC_ORIGIN",
        "openai_api_key": "OPENAI_API_KEY",
        "openai_model": "OPENAI_MODEL",
        "openai_image_model": "OPENAI_IMAGE_MODEL",
        "meta_app_id": "META_APP_ID",
        "meta_app_secret": "META_APP_SECRET",
        "meta_graph_version": "META_GRAPH_VERSION",
    }
    return {field: (env.get(key) or "").strip() for field, key in definition.items()}


def run_automatic_installation(paths: InstallerPaths, env=None, apply_runtime_config=None):
    """Install without any browser interaction.

    Returns ``(status, record_or_none)`` where status is one of ``installed``,
    ``already_installed``, ``skipped`` (flag off) or ``failed``. The caller
    decides whether a failure is fatal; the installer blueprint stays available
    as a fallback on failure.
    """
    env = os.environ if env is None else env
    if not auto_install_requested(env):
        return "skipped", None
    if not installer_needed(paths):
        return "already_installed", load_state(paths.state_path)

    missing = auto_install_missing(env)
    if missing:
        LOGGER.error(
            "Instalação automática ignorada: defina %s.", ", ".join(missing)
        )
        return "failed", None

    payload, errors = build_installation(
        automatic_form(env),
        existing_env=load_env_file(paths.env_path),
        origin=clean(env.get("SOCIALMEDIA_PUBLIC_ORIGIN"), 200),
        secure_request=False,
        require_confirmation=False,
    )
    if errors:
        LOGGER.error("Instalação automática rejeitada: %s", " ".join(errors))
        return "failed", None

    try:
        record = execute_installation(payload, paths, apply_runtime_config=apply_runtime_config)
    except InstallerError as exc:
        LOGGER.error("Instalação automática falhou: %s", exc)
        return "failed", None
    except (OSError, sqlite3.Error) as exc:
        LOGGER.exception("Instalação automática falhou (%s)", exc.__class__.__name__)
        return "failed", None

    LOGGER.info(
        "Instalação automática concluída: agência=%s proprietário=%s origem=%s",
        record["agency_name"], mask_email(record["owner_email"]), record["public_origin"],
    )
    LOGGER.warning(
        "Remova SOCIALMEDIA_OWNER_PASSWORD do ambiente do serviço e mantenha o arquivo %s em backup seguro.",
        record["env_file"],
    )
    return "installed", record


def installation_banner(paths: InstallerPaths, env=None):
    """Short status for the login page: how the workspace was created."""
    env = os.environ if env is None else env
    if installer_needed(paths):
        return {"state": "pending", "message": "Instância ainda não instalada."}
    record = load_state(paths.state_path)
    if record:
        return {"state": "installed", "message": f"Instância instalada em {record['installed_at']}."}
    return {"state": "external", "message": "Instância configurada fora do instalador pelo navegador."}


# --- drafts and tickets ------------------------------------------------------
# Drafts stay in process memory: this is a single-process installer and nothing
# sensitive (not even API keys) hits the disk before the final confirmation.


def prune_drafts(now: float | None = None) -> None:
    now = time.time() if now is None else now
    for draft_id, draft in list(_DRAFTS.items()):
        if now - draft.get("created_at", 0) > DRAFT_TTL_SECONDS:
            _DRAFTS.pop(draft_id, None)


def create_draft(payload: dict) -> str:
    prune_drafts()
    while len(_DRAFTS) >= MAX_DRAFTS:
        oldest = min(_DRAFTS, key=lambda key: _DRAFTS[key].get("created_at", 0))
        _DRAFTS.pop(oldest, None)
    draft_id = secrets.token_hex(12)
    _DRAFTS[draft_id] = {"created_at": time.time(), "payload": payload}
    return draft_id


def load_draft(draft_id: str) -> dict | None:
    prune_drafts()
    draft = _DRAFTS.get(draft_id) if draft_id else None
    return draft["payload"] if draft else None


def drop_draft(draft_id: str) -> None:
    _DRAFTS.pop(draft_id, None)


def issue_done_ticket() -> str:
    now = time.time()
    for ticket, expires in list(_DONE_TICKETS.items()):
        if expires < now:
            _DONE_TICKETS.pop(ticket, None)
    ticket = secrets.token_urlsafe(24)
    _DONE_TICKETS[ticket] = now + DONE_TICKET_TTL_SECONDS
    return ticket


def done_ticket_valid(ticket: str) -> bool:
    expires = _DONE_TICKETS.get(ticket or "")
    if expires is None:
        return False
    if expires < time.time():
        _DONE_TICKETS.pop(ticket, None)
        return False
    return True


def review_summary(payload: dict, paths: InstallerPaths, existing_env: dict[str, str]) -> dict:
    """What the review screen shows; secrets are masked."""
    ai, meta = payload.get("ai"), payload.get("meta")
    return {
        "workspace": [
            {"label": "Agência", "value": payload["agency_name"]},
            {"label": "Administrador", "value": payload["admin_name"]},
            {"label": "E-mail de acesso", "value": payload["admin_email"]},
            {"label": "Senha", "value": "definida e armazenada apenas com hash"},
        ],
        "security": [
            {"label": "Arquivo de configuração", "value": str(paths.env_path)},
            {"label": "Permissão do arquivo", "value": "0600 (somente o dono do processo)"},
            {"label": "Chave de sessão", "value": "SECRET_KEY nova e persistente"},
            {"label": "Cookies", "value": "Secure ativo" if payload.get("cookie_secure") == "1" else "sem Secure (HTTP local)"},
        ],
        "ai": [
            {"label": "Chave da OpenAI", "value": mask(ai.get("api_key") or existing_env.get("OPENAI_API_KEY", ""))},
            {"label": "Modelo de texto", "value": ai["model"]},
            {"label": "Modelo de imagem", "value": ai["image_model"]},
        ] if ai else None,
        "meta": [
            {"label": "App ID", "value": meta["app_id"]},
            {"label": "App Secret", "value": mask(meta["app_secret"])},
            {"label": "Versão do Graph", "value": meta["graph_version"]},
            {"label": "Callback OAuth", "value": meta["redirect_uri"]},
            {"label": "URL pública de mídia", "value": meta["public_media_base_url"]},
            {"label": "Criptografia de tokens", "value": "chave Fernet gerada no servidor"},
        ] if meta else None,
    }


# --- blueprint ---------------------------------------------------------------


def create_installer_blueprint(*, paths, apply_runtime_config, login_endpoint: str = "login") -> Blueprint:
    """Create the ``/install`` blueprint.

    ``paths`` is a callable returning :class:`InstallerPaths` so tests can move
    the data directory between requests.
    """
    blueprint = Blueprint("installer", __name__)

    def current_paths() -> InstallerPaths:
        return paths()

    def csrf_secret() -> bytes:
        return str(current_app.config.get("SECRET_KEY") or "smia-installer").encode("utf-8")

    def csrf_sign(nonce: str) -> str:
        return hmac.new(csrf_secret(), nonce.encode("utf-8"), hashlib.sha256).hexdigest()

    def csrf_valid() -> bool:
        nonce = request.cookies.get(CSRF_COOKIE, "")
        submitted = request.form.get(CSRF_FIELD, "")
        return bool(nonce and submitted) and hmac.compare_digest(csrf_sign(nonce), submitted)

    def access_allowed() -> bool:
        expected = access_token_configured()
        if not expected:
            return True
        return hmac.compare_digest((request.values.get("access_token") or "").strip(), expected)

    def page(template: str, status: int = 200, **context):
        nonce = secrets.token_urlsafe(24)
        context.setdefault("errors", [])
        context.setdefault("login_url", url_for(login_endpoint, installed=1) if request.args.get("installed") else url_for(login_endpoint))
        context.setdefault("access_token_required", bool(access_token_configured()))
        context.setdefault("access_token", clean(request.values.get("access_token"), 200))
        context.setdefault("origin", detected_origin(request))
        context[CSRF_FIELD] = csrf_sign(nonce)
        response = make_response(render_template(template, **context), status)
        response.headers["Cache-Control"] = "private, no-store"
        response.headers["X-Robots-Tag"] = "noindex, nofollow"
        response.set_cookie(CSRF_COOKIE, nonce, max_age=DRAFT_TTL_SECONDS, httponly=True,
                            samesite="Lax", secure=False, path="/install")
        return response

    def closed_page(status: int = 410):
        state = load_state(current_paths().state_path)
        if state:
            state = {**state, "owner_email": mask_email(state.get("owner_email", ""))}
        return page("install_closed.html", status, state=state)

    def closed_or_none():
        if installer_needed(current_paths()):
            return None
        return closed_page() if request.method == "GET" else closed_page(403)

    def denied_page():
        """Reject a bad/missing access token and throttle repeated attempts."""
        if not _register_access_failure(request.remote_addr or "unknown"):
            return page("install_access.html", 429,
                        error="Muitas tentativas de acesso. Aguarde alguns minutos antes de tentar novamente.")
        return page("install_access.html", 403, error=request.args.get("error"))

    def form_context(paths_now: InstallerPaths, form: dict, **extra):
        env_file = load_env_file(paths_now.env_path)
        missing = auto_install_missing()
        context = {
            "checks": environment_checks(paths_now, request_is_secure(request)),
            "paths": paths_now,
            "form": form,
            # One-click path is offered only when the deploy variables are complete.
            "auto_ready": not missing,
            "auto_requested": auto_install_requested(),
            "auto_missing": missing,
            "existing": {
                "secret_key": bool(env_file.get("SECRET_KEY")),
                "openai": bool(env_file.get("OPENAI_API_KEY")),
                "meta": bool(env_file.get("META_APP_ID") and env_file.get("META_APP_SECRET")),
            },
        }
        context.update(extra)
        return context

    @blueprint.get("/install")
    def installer_page():
        closed = closed_or_none()
        if closed is not None:
            return closed
        # One-click install: /install?auto=1 builds the owner from the deploy
        # variables and confirms the same consequences on the final screen.
        if clean(request.args.get("auto"), 8) == "1":
            return _automatic_install()
        if not access_allowed():
            return denied_page()
        return page("install.html", **form_context(current_paths(), {}))

    def _automatic_install():
        paths_now = current_paths()
        missing = auto_install_missing()
        if missing:
            return page("install_auto.html", 409, paths=paths_now, missing=missing,
                        checks=environment_checks(paths_now, request_is_secure(request)))
        if not access_allowed():
            return denied_page()
        status, record = run_automatic_installation(paths_now, apply_runtime_config=apply_runtime_config)
        if status == "installed":
            session.clear()
            ticket = issue_done_ticket()
            response = redirect(url_for("installer.installer_done", ticket=ticket))
            response.delete_cookie("session", path="/")
            return response
        if status == "already_installed":
            return closed_page()
        return page("install.html", 409, **form_context(paths_now, {}, errors=[
            "A instalação automática não foi concluída. Revise os dados do servidor e conclua manualmente abaixo."
        ]))

    @blueprint.post("/install/validate")
    def installer_validate():
        closed = closed_or_none()
        if closed is not None:
            return closed
        paths_now = current_paths()
        if not csrf_valid():
            return page("install.html", 400, **form_context(
                paths_now, {}, errors=["Sessão do instalador expirada. Revise os dados e envie novamente."]))
        if not access_allowed():
            return denied_page()
        safe_form = {key: clean(value, 200) for key, value in request.form.items()
                     if key in {"agency_name", "admin_name", "admin_email", "public_origin",
                                "openai_model", "openai_image_model", "meta_app_id", "meta_graph_version"}}
        payload, errors = build_installation(
            request.form, existing_env=load_env_file(paths_now.env_path),
            origin=detected_origin(request), secure_request=request_is_secure(request),
        )
        if errors:
            return page("install.html", 400, **form_context(paths_now, safe_form, errors=errors))
        draft_id = create_draft(payload)
        return redirect(url_for("installer.installer_review", draft=draft_id,
                                access_token=clean(request.values.get("access_token"), 200) or None))

    @blueprint.get("/install/review")
    def installer_review():
        closed = closed_or_none()
        if closed is not None:
            return closed
        if not access_allowed():
            return denied_page()
        draft_id = clean(request.args.get("draft"), 64)
        payload = load_draft(draft_id)
        if not payload:
            return redirect(url_for("installer.installer_page",
                                    access_token=clean(request.args.get("access_token"), 200) or None))
        paths_now = current_paths()
        return page("install_review.html", draft_id=draft_id,
                    summary=review_summary(payload, paths_now, load_env_file(paths_now.env_path)),
                    login_url=url_for(login_endpoint))

    @blueprint.post("/install/run")
    def installer_run():
        closed = closed_or_none()
        if closed is not None:
            return closed
        draft_id = clean(request.form.get("draft"), 64)
        if not csrf_valid():
            return redirect(url_for("installer.installer_page"))
        if not access_allowed():
            return denied_page()
        payload = load_draft(draft_id)
        if not payload:
            return redirect(url_for("installer.installer_page"))
        paths_now = current_paths()
        try:
            execute_installation(payload, paths_now, apply_runtime_config=apply_runtime_config)
        except InstallerError as exc:
            return page("install_review.html", 409, draft_id=draft_id,
                        errors=[str(exc)], login_url=url_for(login_endpoint),
                        summary=review_summary(payload, paths_now, load_env_file(paths_now.env_path)))
        except (OSError, sqlite3.Error) as exc:
            current_app.logger.exception("Instalação pelo navegador falhou")
            return page("install_review.html", 500, draft_id=draft_id,
                        errors=[f"Não foi possível concluir a instalação ({exc.__class__.__name__}). "
                                "Confira as permissões do diretório de dados e tente novamente."],
                        login_url=url_for(login_endpoint),
                        summary=review_summary(payload, paths_now, load_env_file(paths_now.env_path)))
        drop_draft(draft_id)
        reset_access_failures(request.remote_addr or "unknown")
        ticket = issue_done_ticket()
        response = redirect(url_for("installer.installer_done", ticket=ticket))
        response.delete_cookie("session", path="/")
        return response

    @blueprint.get("/install/done")
    def installer_done():
        paths_now = current_paths()
        record = load_state(paths_now.state_path)
        if record is None:
            return redirect(url_for("installer.installer_page"))
        ticket_ok = done_ticket_valid(clean(request.args.get("ticket"), 80))
        token_ok = access_allowed() if access_token_configured() else False
        if not (ticket_ok or token_ok):
            return denied_page()
        values = load_env_file(paths_now.env_path)
        return page("install_done.html", record=record, login_url=url_for(login_endpoint, installed=1),
                    configured=record.get("configured", {}), env_file=paths_now.env_path,
                    meta_configured=bool(values.get("META_APP_ID")),
                    callback_url=values.get("META_REDIRECT_URI", ""),
                    public_media_url=values.get("META_PUBLIC_BASE_URL", ""),
                    ai_configured=bool(values.get("OPENAI_API_KEY")),
                    cookie_secure=values.get("COOKIE_SECURE") == "1")

    @blueprint.post("/install/cancel")
    def installer_cancel():
        if not installer_needed(current_paths()):
            return closed_page(403)
        if csrf_valid():
            drop_draft(clean(request.form.get("draft"), 64))
        return redirect(url_for("installer.installer_page",
                                access_token=clean(request.form.get("access_token"), 200) or None))

    return blueprint
