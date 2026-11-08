import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from urllib.parse import parse_qs, urlparse

from cryptography.fernet import Fernet

TEST_ROOT = tempfile.TemporaryDirectory()
os.environ.setdefault("SOCIALMEDIA_DATA", TEST_ROOT.name)
os.environ.setdefault("SOCIALMEDIA_DB", str(Path(TEST_ROOT.name) / "bootstrap.db"))
import app as app_module  # noqa: E402
import meta_integration  # noqa: E402


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
        }

    def tearDown(self):
        self.temp.cleanup()

    def csrf(self):
        with self.client.session_transaction() as session:
            return session.get("csrf_token")

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

    def test_oauth_callback_stores_only_encrypted_page_token_and_disconnects(self):
        with patch.dict(os.environ, self.meta_env, clear=False):
            started = self.client.post("/integrations/meta/connect", data={"csrf_token": self.csrf()})
            self.assertEqual(started.status_code, 302)
            self.assertTrue(started.location.startswith("https://www.facebook.com/v22.0/dialog/oauth?"))
            with self.client.session_transaction() as session:
                state = session["meta_oauth_state"]
            with patch.object(app_module, "exchange_code", return_value={"access_token": "short-user-token"}) as short_exchange, patch.object(app_module, "exchange_long_lived_token", return_value={"access_token": "long-user-token", "expires_in": 5184000}) as long_exchange, patch.object(app_module, "fetch_pages", return_value=[{"page_id": "page-7", "page_name": "Página Aurora", "page_access_token": "page-access-token", "instagram_user_id": "ig-9", "instagram_username": "aurora"}]) as pages_fetch:
                callback = self.client.get("/integrations/meta/callback", query_string={"state": state, "code": "oauth-code"})
            self.assertIn("connected=1", callback.location)
            short_exchange.assert_called_once()
            long_exchange.assert_called_once()
            pages_fetch.assert_called_once_with("long-user-token", meta_integration.load_meta_settings())
            with app_module.app.app_context():
                db = app_module.get_db()
                stored = db.execute("SELECT * FROM meta_page_connections WHERE company_id=(SELECT company_id FROM users WHERE email='meta-admin@example.com')").fetchone()
                self.assertEqual(stored["page_id"], "page-7")
                self.assertNotIn("page-access-token", stored["page_access_token_encrypted"])
                self.assertEqual(meta_integration.decrypt_token(stored["page_access_token_encrypted"], meta_integration.load_meta_settings()), "page-access-token")
                connection_id = stored["id"]
            page = self.client.get("/integrations/meta")
            self.assertEqual(page.status_code, 200)
            self.assertIn(b"@aurora", page.data)
            self.assertNotIn(b"page-access-token", page.data)
            deleted = self.client.post(f"/integrations/meta/{connection_id}/disconnect", data={"csrf_token": self.csrf()})
            self.assertIn("disconnected=1", deleted.location)
            with app_module.app.app_context():
                self.assertEqual(app_module.get_db().execute("SELECT COUNT(*) FROM meta_page_connections").fetchone()[0], 0)

    def test_missing_meta_configuration_fails_closed_without_showing_credentials(self):
        with patch.dict(os.environ, {}, clear=True):
            page = self.client.get("/integrations/meta")
            self.assertEqual(page.status_code, 200)
            self.assertIn(b"Configura\xc3\xa7\xc3\xa3o pendente", page.data)
            self.assertIn(b"META_APP_ID", page.data)
            self.assertNotIn(b"test-app-secret-not-real", page.data)

    def test_oauth_callback_rejects_state_mismatch_without_graph_calls(self):
        with patch.dict(os.environ, self.meta_env, clear=False), patch.object(app_module, "exchange_code") as exchange:
            self.client.post("/integrations/meta/connect", data={"csrf_token": self.csrf()})
            response = self.client.get("/integrations/meta/callback", query_string={"state": "wrong", "code": "should-not-be-used"})
            self.assertIn("error=", response.location)
            exchange.assert_not_called()


if __name__ == "__main__":
    unittest.main()
