import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from urllib.parse import parse_qs, urlparse
import urllib.parse
import urllib.error

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
            self.assertIn(b"Publicar no Facebook", page.data)
            self.assertIn(b"Publicar no Instagram", page.data)
            self.assertIn(b"recusada", page.data)
            self.assertNotIn(b"page-access-token", page.data)
            with patch.object(app_module, "check_page_connection", return_value={"page_id": "page-7", "page_name": "Página Aurora"}) as check_page:
                checked = self.client.post(f"/integrations/meta/{connection_id}/check", data={"csrf_token": self.csrf()})
            self.assertIn("connection_check=ok", checked.location)
            check_page.assert_called_once_with("page-7", "page-access-token", meta_integration.load_meta_settings())
            page = self.client.get("/integrations/meta")
            self.assertIn(b"Leitura Graph confirmada", page.data)
            deleted = self.client.post(f"/integrations/meta/{connection_id}/disconnect", data={"csrf_token": self.csrf()})
            self.assertIn("disconnected=1", deleted.location)
            with app_module.app.app_context():
                self.assertEqual(app_module.get_db().execute("SELECT COUNT(*) FROM meta_page_connections").fetchone()[0], 0)

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
        image_url = "https://social.example.test/meta/media/art.png?expires=123&signature=abc"
        with patch("meta_integration.urllib.request.urlopen", side_effect=[FakeResponse({"id": "container-1"}), FakeResponse({"id": "ig-publication-9"})]) as open_url:
            result = meta_integration.publish_instagram_image("ig-user-4", "page-token-secret", image_url, "Legenda #arte", settings)
        self.assertEqual(result, "ig-publication-9")
        self.assertEqual(open_url.call_count, 2)
        create_request = open_url.call_args_list[0].args[0]
        publish_request = open_url.call_args_list[1].args[0]
        self.assertEqual(create_request.full_url, "https://graph.facebook.com/v22.0/ig-user-4/media")
        self.assertEqual(create_request.get_method(), "POST")
        self.assertNotIn("page-token-secret", create_request.full_url)
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
            self.assertNotIn(b"test-app-secret-not-real", page.data)

    def test_oauth_callback_rejects_state_mismatch_without_graph_calls(self):
        with patch.dict(os.environ, self.meta_env, clear=False), patch.object(app_module, "exchange_code") as exchange:
            self.client.post("/integrations/meta/connect", data={"csrf_token": self.csrf()})
            response = self.client.get("/integrations/meta/callback", query_string={"state": "wrong", "code": "should-not-be-used"})
            self.assertIn("error=", response.location)
            exchange.assert_not_called()


if __name__ == "__main__":
    unittest.main()
