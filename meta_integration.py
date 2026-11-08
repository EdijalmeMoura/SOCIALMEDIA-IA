"""Server-side Meta OAuth and Graph API helpers.

Live calls are isolated here so the app can fail closed when the Meta app is
not configured. Never log or return access tokens to the browser.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from urllib.parse import urlparse

from cryptography.fernet import Fernet, InvalidToken

META_SCOPES = (
    "pages_show_list",
    "pages_read_engagement",
    "pages_manage_posts",
    "instagram_basic",
    "instagram_content_publish",
)
GRAPH_HOST = "https://graph.facebook.com"
FACEBOOK_HOST = "https://www.facebook.com"


class MetaIntegrationError(Exception):
    """Safe-to-display configuration or Graph API error."""

    def __init__(self, message, uncertain=False):
        super().__init__(message)
        self.uncertain = uncertain


@dataclass(frozen=True)
class MetaSettings:
    app_id: str
    app_secret: str
    redirect_uri: str
    graph_version: str
    token_encryption_key: str


def load_meta_settings(env=None):
    env = os.environ if env is None else env
    app_id = env.get("META_APP_ID", "").strip()
    app_secret = env.get("META_APP_SECRET", "").strip()
    redirect_uri = env.get("META_REDIRECT_URI", "").strip()
    graph_version = env.get("META_GRAPH_VERSION", "").strip()
    encryption_key = env.get("META_TOKEN_ENCRYPTION_KEY", "").strip()
    missing = [
        label for value, label in (
            (app_id, "META_APP_ID"),
            (app_secret, "META_APP_SECRET"),
            (redirect_uri, "META_REDIRECT_URI"),
            (graph_version, "META_GRAPH_VERSION"),
            (encryption_key, "META_TOKEN_ENCRYPTION_KEY"),
        ) if not value
    ]
    if missing:
        raise MetaIntegrationError("Configure no ambiente: " + ", ".join(missing) + ".")
    if not re.fullmatch(r"v[0-9]+\.[0-9]+", graph_version):
        raise MetaIntegrationError("META_GRAPH_VERSION deve usar o formato vNN.N, conforme a versão habilitada no seu app Meta.")
    parsed = urlparse(redirect_uri)
    local_http = parsed.scheme == "http" and parsed.hostname in {"localhost", "127.0.0.1"}
    if parsed.scheme != "https" and not local_http:
        raise MetaIntegrationError("META_REDIRECT_URI precisa usar HTTPS (HTTP só é aceito em localhost para desenvolvimento).")
    if not parsed.netloc or parsed.username or parsed.password or parsed.fragment:
        raise MetaIntegrationError("META_REDIRECT_URI não é uma URL de callback válida.")
    try:
        Fernet(encryption_key.encode("ascii"))
    except (ValueError, TypeError, UnicodeEncodeError):
        raise MetaIntegrationError("META_TOKEN_ENCRYPTION_KEY inválida. Gere uma chave Fernet dedicada e mantenha-a em segredo.")
    return MetaSettings(app_id, app_secret, redirect_uri, graph_version, encryption_key)


def authorization_url(settings: MetaSettings, state: str):
    query = urllib.parse.urlencode({
        "client_id": settings.app_id,
        "redirect_uri": settings.redirect_uri,
        "response_type": "code",
        "scope": ",".join(META_SCOPES),
        "state": state,
    })
    return f"{FACEBOOK_HOST}/{settings.graph_version}/dialog/oauth?{query}"


def _request_json(method, path, settings, params=None, access_token=None, appsecret_proof=False):
    params = dict(params or {})
    if access_token:
        params["access_token"] = access_token
        if appsecret_proof:
            params["appsecret_proof"] = hmac.new(settings.app_secret.encode(), access_token.encode(), hashlib.sha256).hexdigest()
    url = f"{GRAPH_HOST}/{settings.graph_version}/{path.lstrip('/')}"
    if method.upper() == "GET":
        if params:
            url += "?" + urllib.parse.urlencode(params)
        request = urllib.request.Request(url, headers={"Accept": "application/json"}, method="GET")
    else:
        body = urllib.parse.urlencode(params).encode("utf-8")
        request = urllib.request.Request(url, data=body, headers={"Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded"}, method=method.upper())
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            data = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        try:
            payload = json.loads(exc.read().decode("utf-8"))
            error = payload.get("error", {})
            message = error.get("message", "Resposta de erro do Meta Graph API.")
            code = error.get("code")
        except (ValueError, AttributeError):
            message, code = "Resposta de erro do Meta Graph API.", exc.code
        suffix = f" (código {code})" if code is not None else ""
        raise MetaIntegrationError(f"Meta Graph API{suffix}: {message}", uncertain=exc.code >= 500)
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        raise MetaIntegrationError("Não foi possível alcançar o Meta Graph API deste servidor. O resultado da publicação pode ser incerto; confira a Página/conta antes de tentar novamente.", uncertain=True) from exc
    except (UnicodeDecodeError, json.JSONDecodeError):
        raise MetaIntegrationError("O Meta Graph API retornou uma resposta inválida; confira a Página/conta antes de tentar novamente.", uncertain=True)
    if not isinstance(data, dict):
        raise MetaIntegrationError("O Meta Graph API retornou um formato inesperado.")
    if data.get("error"):
        error = data["error"]
        code = error.get("code")
        suffix = f" (código {code})" if code is not None else ""
        raise MetaIntegrationError(f"Meta Graph API{suffix}: {error.get('message', 'Erro não especificado.')}")
    return data


def exchange_code(code, settings):
    if not code:
        raise MetaIntegrationError("O Meta não retornou um código de autorização.")
    return _request_json("GET", "oauth/access_token", settings, {
        "client_id": settings.app_id,
        "client_secret": settings.app_secret,
        "redirect_uri": settings.redirect_uri,
        "code": code,
    })


def exchange_long_lived_token(short_token, settings):
    if not short_token:
        raise MetaIntegrationError("O Meta não retornou um token de usuário.")
    return _request_json("GET", "oauth/access_token", settings, {
        "grant_type": "fb_exchange_token",
        "client_id": settings.app_id,
        "client_secret": settings.app_secret,
        "fb_exchange_token": short_token,
    })


def fetch_pages(user_token, settings):
    fields = "id,name,access_token,instagram_business_account{id,username}"
    payload = _request_json("GET", "me/accounts", settings, {"fields": fields, "limit": "100"}, user_token, appsecret_proof=True)
    pages = payload.get("data", [])
    if not isinstance(pages, list):
        raise MetaIntegrationError("O Meta não retornou uma lista de páginas válida.")
    normalized = []
    for page in pages:
        if not isinstance(page, dict) or not page.get("id") or not page.get("name") or not page.get("access_token"):
            continue
        instagram = page.get("instagram_business_account") or {}
        if not isinstance(instagram, dict):
            instagram = {}
        normalized.append({
            "page_id": str(page["id"]),
            "page_name": str(page["name"])[:200],
            "page_access_token": str(page["access_token"]),
            "instagram_user_id": str(instagram.get("id", "")) or None,
            "instagram_username": str(instagram.get("username", ""))[:120] or None,
        })
    return normalized


def fetch_permissions(user_token, settings):
    payload = _request_json("GET", "me/permissions", settings, {"fields": "permission,status"}, user_token, appsecret_proof=True)
    rows = payload.get("data", [])
    if not isinstance(rows, list):
        raise MetaIntegrationError("O Meta não retornou uma lista de permissões válida.")
    permissions = {}
    requested = set(META_SCOPES)
    for row in rows:
        if not isinstance(row, dict) or row.get("permission") not in requested:
            continue
        status = str(row.get("status", "unknown")).lower()
        permissions[str(row["permission"])] = status if status in {"granted", "declined"} else "unknown"
    return permissions


def check_page_connection(page_id, page_access_token, settings):
    result = _request_json("GET", str(page_id), settings, {"fields": "id,name"}, page_access_token, appsecret_proof=True)
    if str(result.get("id", "")) != str(page_id):
        raise MetaIntegrationError("O Graph API respondeu com uma Página diferente da que está salva nesta conexão.")
    return {"page_id": str(result["id"]), "page_name": str(result.get("name", ""))[:200]}


def _validate_public_image_url(image_url):
    parsed = urlparse(image_url or "")
    if parsed.scheme != "https" or not parsed.netloc or parsed.username or parsed.password:
        raise MetaIntegrationError("A imagem precisa ter uma URL HTTPS pública acessível aos servidores da Meta.")


def publish_instagram_image(instagram_user_id, page_access_token, image_url, caption, settings):
    _validate_public_image_url(image_url)
    container = _request_json("POST", f"{urllib.parse.quote(str(instagram_user_id), safe='')}/media", settings, {
        "image_url": image_url,
        "caption": (caption or "")[:2200],
    }, page_access_token, appsecret_proof=True)
    creation_id = container.get("id")
    if not creation_id:
        raise MetaIntegrationError("A Meta não retornou o ID do contêiner de imagem.")
    published = _request_json("POST", f"{urllib.parse.quote(str(instagram_user_id), safe='')}/media_publish", settings, {
        "creation_id": str(creation_id),
    }, page_access_token, appsecret_proof=True)
    if not published.get("id"):
        raise MetaIntegrationError("O resultado da publicação no Instagram é incerto. Confira a conta antes de tentar novamente.", uncertain=True)
    return str(published["id"])


def publish_facebook_photo(page_id, page_access_token, image_url, caption, settings):
    _validate_public_image_url(image_url)
    published = _request_json("POST", f"{urllib.parse.quote(str(page_id), safe='')}/photos", settings, {
        "url": image_url,
        "caption": (caption or "")[:63206],
    }, page_access_token, appsecret_proof=True)
    remote_id = published.get("post_id") or published.get("id")
    if not remote_id:
        raise MetaIntegrationError("O resultado da publicação no Facebook é incerto. Confira a Página antes de tentar novamente.", uncertain=True)
    return str(remote_id)


def publish_facebook_text(page_id, page_access_token, message, settings):
    if not (message or "").strip():
        raise MetaIntegrationError("A publicação não tem texto para enviar ao Facebook.")
    published = _request_json("POST", f"{urllib.parse.quote(str(page_id), safe='')}/feed", settings, {
        "message": (message or "")[:63206],
    }, page_access_token, appsecret_proof=True)
    if not published.get("id"):
        raise MetaIntegrationError("O resultado da publicação no Facebook é incerto. Confira a Página antes de tentar novamente.", uncertain=True)
    return str(published["id"])


def encrypt_token(token, settings):
    if not token:
        raise MetaIntegrationError("O Meta retornou um token vazio.")
    return Fernet(settings.token_encryption_key.encode("ascii")).encrypt(token.encode("utf-8")).decode("ascii")


def decrypt_token(encrypted_token, settings):
    try:
        return Fernet(settings.token_encryption_key.encode("ascii")).decrypt(encrypted_token.encode("ascii")).decode("utf-8")
    except (InvalidToken, ValueError, UnicodeEncodeError, UnicodeDecodeError) as exc:
        raise MetaIntegrationError("Não foi possível abrir o token armazenado. Confirme se META_TOKEN_ENCRYPTION_KEY permaneceu a mesma desde a conexão.") from exc
