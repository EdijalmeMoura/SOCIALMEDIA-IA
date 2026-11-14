import os
import re
import tempfile
import unittest
from email.message import Message
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch
from urllib.parse import parse_qs, urlparse
import urllib.parse
import urllib.error

from cryptography.fernet import Fernet

TEST_ROOT = tempfile.TemporaryDirectory()
# Sem .env real: a suíte pressupõe raiz "/" e ambiente limpo.
os.environ["SOCIALMEDIA_DOTENV"] = str(Path(TEST_ROOT.name) / "sem-dotenv.env")
os.environ.pop("SOCIALMEDIA_BASE_PATH", None)
os.environ.setdefault("SOCIALMEDIA_DATA", TEST_ROOT.name)
os.environ.setdefault("SOCIALMEDIA_DB", str(Path(TEST_ROOT.name) / "bootstrap.db"))
import app as app_module  # noqa: E402
import meta_integration  # noqa: E402


class FakeProbeResponse:
    status = 200

    def __init__(self, image_url, payload):
        self.image_url = image_url
        self.payload = payload
        self.headers = Message()
        self.headers.add_header("Content-Type", "image/jpeg")

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def geturl(self):
        return self.image_url

    def read(self, size=-1):
        return self.payload[:size] if size >= 0 else self.payload


class FakeTLSStream:
    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def version(self):
        return "TLSv1.3"


class FakeSocket:
    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False


class FakeTLSContext:
    def __init__(self):
        self.hostname = None

    def wrap_socket(self, connection, server_hostname):
        self.hostname = server_hostname
        return FakeTLSStream()


class FakeResponse:
    def __init__(self, payload):
        import json
        self.payload = json.dumps(payload).encode()

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def read(self):
        return self.payload


class MetaIntegrationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        app_module.DB_PATH = Path(self.temp.name) / "meta.db"
        app_module.UPLOAD_DIR = Path(self.temp.name) / "uploads"
        app_module.UPLOAD_DIR.mkdir()
        app_module.init_db()
        app_module.app.config.update(TESTING=True, SECRET_KEY="meta-test-secret")
        self.client = app_module.app.test_client()
        self.client.get("/register")
        self.client.post("/register", data={"csrf_token": self.csrf(), "name": "Meta Admin", "email": "meta-admin@example.com", "company": "Meta Test Agency", "password": "StrongPass123"})
        self.encryption_key = Fernet.generate_key().decode()
        self.meta_env = {
            "META_APP_ID": "123456789",
            "META_APP_SECRET": "test-app-secret-not-real",
            "META_REDIRECT_URI": "https://agency.example.test/integrations/meta/callback",
            "META_GRAPH_VERSION": "v22.0",
            "META_TOKEN_ENCRYPTION_KEY": self.encryption_key,
            "META_PUBLIC_BASE_URL": "https://agency.example.test",
        }

    def tearDown(self):
        self.temp.cleanup()

    def csrf(self):
        with self.client.session_transaction() as session:
            return session.get("csrf_token")

    def test_health_checks_sqlite_and_hides_database_errors(self):
        healthy = self.client.get("/health")
        self.assertEqual(healthy.status_code, 200)
        self.assertEqual(healthy.get_json()["database"], "sqlite")
        with self.client.session_transaction() as session:
            session.clear()
        with patch.object(app_module, "get_db", side_effect=app_module.sqlite3.OperationalError("sensitive database path")):
            unavailable = self.client.get("/health")
        self.assertEqual(unavailable.status_code, 503)
        self.assertEqual(unavailable.get_json()["database"], "unavailable")
        self.assertNotIn(b"sensitive database path", unavailable.data)

    def test_meta_callback_uri_requires_exact_host_and_route(self):
        valid = "https://agency.example.test/integrations/meta/callback"
        self.assertTrue(app_module.meta_callback_uri_matches(valid, "agency.example.test"))
        self.assertFalse(app_module.meta_callback_uri_matches(valid, "other.example.test"))
        self.assertFalse(app_module.meta_callback_uri_matches("https://agency.example.test/callback", "agency.example.test"))
        self.assertFalse(app_module.meta_callback_uri_matches(valid + "?next=/", "agency.example.test"))
        self.assertFalse(app_module.meta_callback_uri_matches("http://agency.example.test/integrations/meta/callback", "agency.example.test"))
        self.assertTrue(app_module.meta_callback_uri_matches("http://localhost:5000/integrations/meta/callback", "localhost:5000"))

    def test_meta_configuration_and_oauth_authorization_url(self):
        with patch.dict(os.environ, self.meta_env, clear=False):
            settings = meta_integration.load_meta_settings()
            url = meta_integration.authorization_url(settings, "state-123")
        parsed = urlparse(url)
        self.assertEqual(parsed.scheme, "https")
        self.assertEqual(parsed.netloc, "www.facebook.com")
        self.assertEqual(parsed.path, "/v22.0/dialog/oauth")
        query = parse_qs(parsed.query)
        self.assertEqual(query["state"], ["state-123"])
        self.assertIn("instagram_content_publish", query["scope"][0])
        token = "page-token-secret"
        encrypted = meta_integration.encrypt_token(token, settings)
        self.assertNotEqual(encrypted, token)
        self.assertEqual(meta_integration.decrypt_token(encrypted, settings), token)
        with self.assertRaises(meta_integration.MetaIntegrationError):
            meta_integration.load_meta_settings({})

    def test_graph_page_discovery_uses_https_and_appsecret_proof(self):
        settings = meta_integration.load_meta_settings(self.meta_env)
        payload = {"data": [{"id": "page-7", "name": "Página Aurora", "access_token": "page-access-token", "instagram_business_account": {"id": "ig-9", "username": "aurora"}}]}
        with patch("meta_integration.urllib.request.urlopen", return_value=FakeResponse(payload)) as open_url:
            pages = meta_integration.fetch_pages("short-lived-user-token", settings)
        self.assertEqual(len(pages), 1)
        self.assertEqual(pages[0]["instagram_user_id"], "ig-9")
        self.assertEqual(pages[0]["instagram_username"], "aurora")
        request = open_url.call_args.args[0]
        self.assertTrue(request.full_url.startswith("https://graph.facebook.com/v22.0/me/accounts?"))
        self.assertIn("appsecret_proof=", request.full_url)
        self.assertNotIn(settings.app_secret, request.full_url)

    def test_page_discovery_follows_cursors_and_deduplicates_pages(self):
        settings = meta_integration.load_meta_settings(self.meta_env)
        first = {"data": [{"id": "page-1", "name": "Uma", "access_token": "token-1"}, {"id": "page-2", "name": "Duas", "access_token": "token-2"}], "paging": {"next": "https://graph.facebook.com/next", "cursors": {"after": "cursor-page-1"}}}
        second = {"data": [{"id": "page-2", "name": "Duas duplicada", "access_token": "token-2-new"}, {"id": "page-3", "name": "Três", "access_token": "token-3"}]}
        with patch("meta_integration.urllib.request.urlopen", side_effect=[FakeResponse(first), FakeResponse(second)]) as open_url:
            pages = meta_integration.fetch_pages("user-token", settings)
        self.assertEqual([page["page_id"] for page in pages], ["page-1", "page-2", "page-3"])
        self.assertEqual(pages[1]["page_name"], "Duas")
        self.assertEqual(open_url.call_count, 2)
        second_query = parse_qs(urlparse(open_url.call_args_list[1].args[0].full_url).query)
        self.assertEqual(second_query["after"], ["cursor-page-1"])
        self.assertEqual(second_query["limit"], ["100"])

    def test_page_discovery_rejects_repeated_pagination_cursor(self):
        settings = meta_integration.load_meta_settings(self.meta_env)
        repeated = {"data": [], "paging": {"next": "https://graph.facebook.com/next", "cursors": {"after": "same-cursor"}}}
        with patch("meta_integration.urllib.request.urlopen", side_effect=[FakeResponse(repeated), FakeResponse(repeated)]):
            with self.assertRaisesRegex(meta_integration.MetaIntegrationError, "cursor de paginação ausente ou repetido"):
                meta_integration.fetch_pages("user-token", settings)

    def test_oauth_callback_stores_only_encrypted_page_token_and_disconnects(self):
        with patch.dict(os.environ, self.meta_env, clear=False):
            started = self.client.post("/integrations/meta/connect", data={"csrf_token": self.csrf()})
            self.assertEqual(started.status_code, 302)
            self.assertTrue(started.location.startswith("https://www.facebook.com/v22.0/dialog/oauth?"))
            with self.client.session_transaction() as session:
                state = session["meta_oauth_state"]
            with patch.object(app_module, "exchange_code", return_value={"access_token": "short-user-token"}) as short_exchange, patch.object(app_module, "exchange_long_lived_token", return_value={"access_token": "long-user-token", "expires_in": 5184000}) as long_exchange, patch.object(app_module, "fetch_pages", return_value=[{"page_id": "page-7", "page_name": "Página Aurora", "page_access_token": "page-access-token", "instagram_user_id": "ig-9", "instagram_username": "aurora"}]) as pages_fetch, patch.object(app_module, "fetch_permissions", return_value={"pages_manage_posts": "granted", "instagram_content_publish": "declined"}) as permissions_fetch:
                callback = self.client.get("/integrations/meta/callback", query_string={"state": state, "code": "oauth-code"})
            self.assertIn("connected=1", callback.location)
            short_exchange.assert_called_once()
            long_exchange.assert_called_once()
            pages_fetch.assert_called_once_with("long-user-token", meta_integration.load_meta_settings())
            permissions_fetch.assert_called_once_with("long-user-token", meta_integration.load_meta_settings())
            with app_module.app.app_context():
                db = app_module.get_db()
                stored = db.execute("SELECT * FROM meta_page_connections WHERE company_id=(SELECT company_id FROM users WHERE email='meta-admin@example.com')").fetchone()
                self.assertEqual(stored["page_id"], "page-7")
                self.assertNotIn("page-access-token", stored["page_access_token_encrypted"])
                self.assertEqual(meta_integration.decrypt_token(stored["page_access_token_encrypted"], meta_integration.load_meta_settings()), "page-access-token")
                connection_id = stored["id"]
                permissions = {row["permission"]: row["status"] for row in db.execute("SELECT permission,status FROM meta_connection_permissions WHERE connection_id=?", (connection_id,)).fetchall()}
                self.assertEqual(permissions["pages_manage_posts"], "granted")
                self.assertEqual(permissions["instagram_content_publish"], "declined")
                self.assertEqual(permissions["pages_show_list"], "unknown")
            page = self.client.get("/integrations/meta")
            self.assertEqual(page.status_code, 200)
            self.assertIn(b"@aurora", page.data)
            self.assertIn(b"Prazo registrado do token OAuth:", page.data)
            self.assertIn(b"O que falta para validar a integra\xc3\xa7\xc3\xa3o", page.data)
            self.assertIn(b"Callback OAuth", page.data)
            self.assertIn(b"Revisar configura\xc3\xa7\xc3\xa3o", page.data)
            self.assertIn(b"Seguran\xc3\xa7a da sess\xc3\xa3o", page.data)
            self.assertIn(b"Pendente no deploy", page.data)
            self.assertIn(b"Facebook \xc2\xb7 publica\xc3\xa7\xc3\xa3o real", page.data)
            self.assertIn(b"Ainda n\xc3\xa3o comprovada", page.data)
            self.assertIn(b"Publicar no Facebook", page.data)
            self.assertIn(b"Publicar no Instagram", page.data)
            self.assertIn(b"Consentimento OAuth capturado em", page.data)
            self.assertIn("Atualizar conexão e permissões".encode(), page.data)
            self.assertIn("acessibilidade externa não foi testada".encode(), page.data)
            self.assertIn(b"recusada", page.data)
            self.assertNotIn(b"page-access-token", page.data)
            with patch.object(app_module, "check_page_connection", return_value={"page_id": "page-7", "page_name": "Página Aurora"}) as check_page:
                checked = self.client.post(f"/integrations/meta/{connection_id}/check", data={"csrf_token": self.csrf()})
            self.assertIn("connection_check=ok", checked.location)
            check_page.assert_called_once_with("page-7", "page-access-token", meta_integration.load_meta_settings())
            page = self.client.get("/integrations/meta")
            self.assertIn(b"Leitura Graph confirmada", page.data)
            guide = self.client.get("/integrations/meta/validation-guide")
            self.assertEqual(guide.status_code, 200)
            self.assertIn(b"Roteiro de valida\xc3\xa7\xc3\xa3o ao vivo", guide.data)
            guide.close()
            soon_expiry = (datetime.now(timezone.utc) + timedelta(days=2)).isoformat(timespec="seconds")
            with app_module.app.app_context():
                db = app_module.get_db()
                db.execute("UPDATE meta_page_connections SET token_expires_at=? WHERE id=?", (soon_expiry, connection_id))
                db.commit()
            self.client.get("/integrations/meta")
            self.client.get("/integrations/meta")
            with app_module.app.app_context():
                db = app_module.get_db()
                notices = db.execute("SELECT * FROM meta_token_expiry_notices WHERE connection_id=?", (connection_id,)).fetchall()
                self.assertEqual(len(notices), 1)
                self.assertEqual(notices[0]["notice_type"], "expiring")
                notification_count = db.execute("SELECT COUNT(*) FROM notifications WHERE company_id=? AND title='Prazo informado do token Meta se aproxima'", (stored["company_id"],)).fetchone()[0]
                self.assertEqual(notification_count, 1)
            expired_at = (datetime.now(timezone.utc) - timedelta(days=1)).isoformat(timespec="seconds")
            with app_module.app.app_context():
                db = app_module.get_db()
                db.execute("UPDATE meta_page_connections SET token_expires_at=? WHERE id=?", (expired_at, connection_id))
                db.commit()
            self.client.get("/integrations/meta")
            notification_page = self.client.get("/notifications")
            self.assertIn(b"Prazo informado do token Meta passou", notification_page.data)
            with app_module.app.app_context():
                db = app_module.get_db()
                self.assertEqual(db.execute("SELECT COUNT(*) FROM meta_token_expiry_notices WHERE connection_id=?", (connection_id,)).fetchone()[0], 2)
                self.assertEqual(db.execute("SELECT COUNT(*) FROM notifications WHERE company_id=? AND title='Prazo informado do token Meta passou'", (stored["company_id"],)).fetchone()[0], 1)
            deleted = self.client.post(f"/integrations/meta/{connection_id}/disconnect", data={"csrf_token": self.csrf()})
            self.assertIn("disconnected=1", deleted.location)
            with app_module.app.app_context():
                self.assertEqual(app_module.get_db().execute("SELECT COUNT(*) FROM meta_page_connections").fetchone()[0], 0)

    def test_graph_tls_probe_uses_verified_hostname_and_timeout(self):
        socket = FakeSocket()
        context = FakeTLSContext()
        with patch.object(meta_integration.socket, "create_connection", return_value=socket) as connect, patch.object(meta_integration.ssl, "create_default_context", return_value=context):
            result = meta_integration.check_graph_tls_connectivity(timeout=3)
        self.assertEqual(result, "TLSv1.3")
        connect.assert_called_once_with(("graph.facebook.com", 443), timeout=3)
        self.assertEqual(context.hostname, "graph.facebook.com")

    def test_meta_graph_tls_button_records_success_and_failure_without_secrets(self):
        with patch.object(app_module, "check_graph_tls_connectivity", return_value="TLSv1.3") as check_tls:
            success = self.client.post("/integrations/meta/graph-connectivity-check", data={"csrf_token": self.csrf()})
        self.assertIn("graph_check=ok", success.location)
        check_tls.assert_called_once_with(timeout=5)
        self.assertIn(b"Handshake TLS com o Graph da Meta confirmado", self.client.get(success.location).data)
        with patch.object(app_module, "check_graph_tls_connectivity", side_effect=OSError("sensitive socket details")):
            failure = self.client.post("/integrations/meta/graph-connectivity-check", data={"csrf_token": self.csrf()})
        self.assertIn("graph_check=failed", failure.location)
        failure_page = self.client.get(failure.location)
        self.assertIn(b"O servidor n\xc3\xa3o alcan\xc3\xa7ou graph.facebook.com por TLS", failure_page.data)
        self.assertNotIn(b"sensitive socket details", failure_page.data)
        with app_module.app.app_context():
            rows = app_module.get_db().execute("SELECT status,details FROM meta_graph_connectivity_checks ORDER BY id").fetchall()
            self.assertEqual([row["status"] for row in rows], ["ok", "failed"])
            self.assertNotIn("sensitive socket details", " ".join(row["details"] for row in rows))

    def test_public_media_probe_requires_exact_jpeg_from_same_url(self):
        image_url = "https://agency.example.test/meta/media/probe.jpg?signature=test"
        class ProbeOpener:
            def __init__(self, response):
                self.response = response
                self.request = None
                self.timeout = None

            def open(self, request, timeout):
                self.request, self.timeout = request, timeout
                return self.response

        opener = ProbeOpener(FakeProbeResponse(image_url, b"jpeg-bytes"))
        with patch.object(app_module.urllib.request, "build_opener", return_value=opener) as build_opener:
            self.assertTrue(app_module.verify_meta_public_media_probe(image_url, b"jpeg-bytes"))
        self.assertEqual(opener.timeout, 8)
        self.assertEqual(opener.request.method, "GET")
        self.assertIsInstance(build_opener.call_args.args[0], app_module._RejectMetaMediaRedirects)
        wrong_body = ProbeOpener(FakeProbeResponse(image_url, b"different"))
        with patch.object(app_module.urllib.request, "build_opener", return_value=wrong_body):
            self.assertFalse(app_module.verify_meta_public_media_probe(image_url, b"jpeg-bytes"))

    def test_public_media_check_records_server_probe_and_removes_temporary_image(self):
        with patch.dict(os.environ, self.meta_env, clear=False), patch.object(app_module, "verify_meta_public_media_probe", return_value=True) as probe:
            response = self.client.post("/integrations/meta/public-media-check", data={"csrf_token": self.csrf()})
        self.assertIn("media_check=ok", response.location)
        image_url, image_bytes = probe.call_args.args
        filename = Path(urlparse(image_url).path).name
        self.assertTrue(image_bytes.startswith(bytes([0xff, 0xd8])))
        self.assertFalse((app_module.UPLOAD_DIR / filename).exists())
        with app_module.app.app_context():
            check = app_module.get_db().execute("SELECT status,details FROM meta_public_media_checks ORDER BY id DESC LIMIT 1").fetchone()
            self.assertEqual(check["status"], "ok")
            self.assertIn("não comprova que os servidores da Meta", check["details"])
        with patch.dict(os.environ, self.meta_env, clear=False):
            page = self.client.get(response.location)
        self.assertIn(b"Servidor buscou o JPEG", page.data)
        self.assertIn(b"Isso n\xc3\xa3o comprova acesso pelos servidores da Meta", page.data)

    def test_permission_discovery_normalizes_only_requested_meta_scopes(self):
        settings = meta_integration.load_meta_settings(self.meta_env)
        payload = {"data": [{"permission": "pages_manage_posts", "status": "granted"}, {"permission": "instagram_content_publish", "status": "declined"}, {"permission": "unrequested_scope", "status": "granted"}, {"permission": "pages_show_list", "status": "future-status"}]}
        with patch("meta_integration.urllib.request.urlopen", return_value=FakeResponse(payload)) as open_url:
            permissions = meta_integration.fetch_permissions("user-token", settings)
        self.assertEqual(permissions["pages_manage_posts"], "granted")
        self.assertEqual(permissions["instagram_content_publish"], "declined")
        self.assertEqual(permissions["pages_show_list"], "unknown")
        self.assertNotIn("unrequested_scope", permissions)
        self.assertIn("/me/permissions?", open_url.call_args.args[0].full_url)
        self.assertIn("appsecret_proof=", open_url.call_args.args[0].full_url)

    def test_page_connection_check_queries_meta_and_rejects_a_mismatched_page(self):
        settings = meta_integration.load_meta_settings(self.meta_env)
        with patch("meta_integration.urllib.request.urlopen", return_value=FakeResponse({"id": "page-7", "name": "Página Aurora"})) as open_url:
            result = meta_integration.check_page_connection("page-7", "page-token", settings)
        self.assertEqual(result["page_name"], "Página Aurora")
        request = open_url.call_args.args[0]
        self.assertTrue(request.full_url.startswith("https://graph.facebook.com/v22.0/page-7?"))
        self.assertIn("appsecret_proof=", request.full_url)
        with patch("meta_integration.urllib.request.urlopen", return_value=FakeResponse({"id": "other-page", "name": "Outra"})):
            with self.assertRaises(meta_integration.MetaIntegrationError):
                meta_integration.check_page_connection("page-7", "page-token", settings)

    def test_publish_helpers_send_documented_graph_requests_without_leaking_tokens_in_urls(self):
        settings = meta_integration.load_meta_settings(self.meta_env)
        image_url = "https://social.example.test/meta/media/art.jpg?expires=123&signature=abc"
        with patch("meta_integration.urllib.request.urlopen", side_effect=[FakeResponse({"id": "container-1"}), FakeResponse({"id": "container-1", "status_code": "FINISHED"}), FakeResponse({"id": "ig-publication-9"})]) as open_url:
            result = meta_integration.publish_instagram_image("ig-user-4", "page-token-secret", image_url, "Legenda #arte", settings)
        self.assertEqual(result, "ig-publication-9")
        self.assertEqual(open_url.call_count, 3)
        create_request = open_url.call_args_list[0].args[0]
        status_request = open_url.call_args_list[1].args[0]
        publish_request = open_url.call_args_list[2].args[0]
        self.assertEqual(create_request.full_url, "https://graph.facebook.com/v22.0/ig-user-4/media")
        self.assertEqual(create_request.get_method(), "POST")
        self.assertNotIn("page-token-secret", create_request.full_url)
        self.assertEqual(status_request.get_method(), "GET")
        status_query = parse_qs(urlparse(status_request.full_url).query)
        self.assertEqual(status_query["fields"], ["status_code"])
        self.assertTrue(re.fullmatch(r"[a-f0-9]{64}", status_query["appsecret_proof"][0]))
        create_body = urllib.parse.parse_qs(create_request.data.decode())
        publish_body = urllib.parse.parse_qs(publish_request.data.decode())
        self.assertEqual(create_body["image_url"], [image_url])
        self.assertEqual(publish_body["creation_id"], ["container-1"])
        self.assertRegex(create_body["appsecret_proof"][0], r"^[a-f0-9]{64}$")
        self.assertRegex(publish_body["appsecret_proof"][0], r"^[a-f0-9]{64}$")
        with patch("meta_integration.urllib.request.urlopen", return_value=FakeResponse({"post_id": "fb-post-8", "id": "photo-8"})) as open_url:
            result = meta_integration.publish_facebook_photo("page-2", "page-token-secret", image_url, "Legenda", settings)
        self.assertEqual(result, "fb-post-8")
        self.assertEqual(open_url.call_args.args[0].full_url, "https://graph.facebook.com/v22.0/page-2/photos")
        photo_body = urllib.parse.parse_qs(open_url.call_args.args[0].data.decode())
        self.assertRegex(photo_body["appsecret_proof"][0], r"^[a-f0-9]{64}$")
        with patch("meta_integration.urllib.request.urlopen", return_value=FakeResponse({"id": "fb-post-10"})) as open_url:
            result = meta_integration.publish_facebook_text("page-2", "page-token-secret", "Texto", settings)
        self.assertEqual(result, "fb-post-10")
        self.assertEqual(open_url.call_args.args[0].full_url, "https://graph.facebook.com/v22.0/page-2/feed")
        text_body = urllib.parse.parse_qs(open_url.call_args.args[0].data.decode())
        self.assertRegex(text_body["appsecret_proof"][0], r"^[a-f0-9]{64}$")

    def test_instagram_rejects_non_jpeg_url_before_graph_call(self):
        settings = meta_integration.load_meta_settings(self.meta_env)
        with patch("meta_integration.urllib.request.urlopen") as open_url:
            with self.assertRaisesRegex(meta_integration.MetaIntegrationError, "exige uma URL de arquivo JPEG"):
                meta_integration.publish_instagram_image("ig-user-4", "page-token", "https://agency.example.test/art.png", "caption", settings)
        open_url.assert_not_called()

    def test_instagram_waits_for_container_readiness_and_never_publishes_failed_container(self):
        settings = meta_integration.load_meta_settings(self.meta_env)
        with patch("meta_integration.urllib.request.urlopen", side_effect=[FakeResponse({"id": "container-8"}), FakeResponse({"status_code": "IN_PROGRESS"}), FakeResponse({"status_code": "FINISHED"}), FakeResponse({"id": "instagram-media-8"})]) as open_url, patch("meta_integration.time.sleep") as sleep:
            result = meta_integration.publish_instagram_image("ig-user-4", "page-token", "https://agency.example.test/art.jpg", "caption", settings)
        self.assertEqual(result, "instagram-media-8")
        self.assertEqual(open_url.call_count, 4)
        self.assertEqual(open_url.call_args_list[1].kwargs["timeout"], 5)
        sleep.assert_called_once_with(meta_integration.INSTAGRAM_CONTAINER_POLL_INTERVAL_SECONDS)
        with patch("meta_integration.urllib.request.urlopen", side_effect=[FakeResponse({"id": "container-error"}), FakeResponse({"status_code": "ERROR", "status": "Invalid image"})]) as open_url:
            with self.assertRaises(meta_integration.MetaIntegrationError) as caught:
                meta_integration.publish_instagram_image("ig-user-4", "page-token", "https://agency.example.test/art.jpg", "caption", settings)
        self.assertFalse(caught.exception.uncertain)
        self.assertIn("publicação não foi iniciada", str(caught.exception).lower())
        self.assertEqual(open_url.call_count, 2)
        pending = [FakeResponse({"id": "container-pending"})] + [FakeResponse({"status_code": "IN_PROGRESS"})] * meta_integration.INSTAGRAM_CONTAINER_MAX_CHECKS
        with patch("meta_integration.urllib.request.urlopen", side_effect=pending) as open_url, patch("meta_integration.time.sleep") as sleep:
            with self.assertRaises(meta_integration.MetaIntegrationError) as pending_error:
                meta_integration.publish_instagram_image("ig-user-4", "page-token", "https://agency.example.test/art.jpg", "caption", settings)
        self.assertFalse(pending_error.exception.uncertain)
        self.assertIn("a publicação não foi iniciada", str(pending_error.exception).lower())
        self.assertEqual(open_url.call_count, 1 + meta_integration.INSTAGRAM_CONTAINER_MAX_CHECKS)
        self.assertEqual(sleep.call_count, meta_integration.INSTAGRAM_CONTAINER_MAX_CHECKS - 1)

    def test_graph_timeout_is_marked_as_uncertain_to_prevent_blind_retry(self):
        settings = meta_integration.load_meta_settings(self.meta_env)
        with patch("meta_integration.urllib.request.urlopen", side_effect=urllib.error.URLError("offline")):
            with self.assertRaises(meta_integration.MetaIntegrationError) as caught:
                meta_integration.publish_facebook_text("page-2", "page-token-secret", "Texto", settings)
        self.assertTrue(caught.exception.uncertain)

    def test_missing_meta_configuration_fails_closed_without_showing_credentials(self):
        with patch.dict(os.environ, {}, clear=True):
            page = self.client.get("/integrations/meta")
            self.assertEqual(page.status_code, 200)
            self.assertIn(b"Configura\xc3\xa7\xc3\xa3o pendente", page.data)
            self.assertIn(b"META_APP_ID", page.data)
            self.assertIn(b"origem HTTPS pendente", page.data)
            self.assertNotIn(b"test-app-secret-not-real", page.data)

    def test_oauth_callback_rejects_state_mismatch_without_graph_calls(self):
        with patch.dict(os.environ, self.meta_env, clear=False), patch.object(app_module, "exchange_code") as exchange:
            self.client.post("/integrations/meta/connect", data={"csrf_token": self.csrf()})
            response = self.client.get("/integrations/meta/callback", query_string={"state": "wrong", "code": "should-not-be-used"})
            self.assertIn("error=", response.location)
            exchange.assert_not_called()


if __name__ == "__main__":
    unittest.main()
