import json
import os
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch
from urllib.parse import urlparse

from cryptography.fernet import Fernet

TEST_ROOT = tempfile.TemporaryDirectory()
os.environ.setdefault("SOCIALMEDIA_DATA", TEST_ROOT.name)
os.environ.setdefault("SOCIALMEDIA_DB", str(Path(TEST_ROOT.name) / "bootstrap.db"))
import app as app_module  # noqa: E402
import meta_integration  # noqa: E402


class MetaManualPublishingRouteTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        app_module.DB_PATH = Path(self.temp.name) / "publish.db"
        app_module.UPLOAD_DIR = Path(self.temp.name) / "uploads"
        app_module.UPLOAD_DIR.mkdir()
        app_module.init_db()
        app_module.app.config.update(TESTING=True, SECRET_KEY="manual-meta-publish-test-secret")
        self.client = app_module.app.test_client()
        self.client.get("/register")
        self.client.post("/register", data={"csrf_token": self.csrf(), "name": "Meta Publisher", "email": "publisher@example.com", "company": "Publisher Agency", "password": "StrongPass123"})
        self.client.post("/clients/new", data={"csrf_token": self.csrf(), "company_name": "Aurora Café", "trade_name": "Aurora"})
        with app_module.app.app_context():
            db = app_module.get_db()
            owner = db.execute("SELECT id,company_id FROM users WHERE email='publisher@example.com'").fetchone()
            self.user_id, self.company_id = owner["id"], owner["company_id"]
            self.client_id = db.execute("SELECT id FROM clients WHERE company_id=?", (self.company_id,)).fetchone()[0]
        self.meta_env = {
            "META_APP_ID": "123456789",
            "META_APP_SECRET": "test-meta-secret",
            "META_REDIRECT_URI": "https://agency.example.test/integrations/meta/callback",
            "META_GRAPH_VERSION": "v22.0",
            "META_TOKEN_ENCRYPTION_KEY": Fernet.generate_key().decode(),
            "META_PUBLIC_BASE_URL": "https://agency.example.test",
        }

    def tearDown(self):
        self.temp.cleanup()

    def csrf(self):
        with self.client.session_transaction() as session:
            return session.get("csrf_token")

    def make_due_schedule(self, network="Instagram", with_image=True):
        image_name = "approved-art.png" if with_image else None
        if image_name:
            (app_module.UPLOAD_DIR / image_name).write_bytes(b"test-png-bytes")
        snapshot = {
            "type": "creative" if with_image else "planned",
            "title": "Publicação aprovada Aurora",
            "export_path": image_name,
            "content": {"caption": "Um café especial", "hashtags": "#aurora"},
        }
        if not with_image:
            snapshot.update(network=network, caption="Texto autorizado", hashtags="#aurora")
        with app_module.app.app_context():
            db = app_module.get_db()
            approval_id = db.execute("INSERT INTO approvals(company_id,client_id,created_by,source_type,creative_id,planned_post_id,cycle_number,title,snapshot_json,status,resolved_at) VALUES(?,?,?,?,?,?,?,?,?,'approved',CURRENT_TIMESTAMP)", (self.company_id, self.client_id, self.user_id, "creative" if with_image else "planned", 501 if with_image else None, None if with_image else 701, 1, snapshot["title"], json.dumps(snapshot))).lastrowid
            scheduled = (datetime.now(timezone.utc) - timedelta(minutes=10)).isoformat(timespec="seconds")
            schedule_id = db.execute("INSERT INTO scheduled_publications(company_id,client_id,approval_id,scheduled_by,network,scheduled_at_utc,timezone) VALUES(?,?,?,?,?,?,?)", (self.company_id, self.client_id, approval_id, self.user_id, network, scheduled, "UTC")).lastrowid
            settings = meta_integration.load_meta_settings(self.meta_env)
            connection_id = db.execute("INSERT INTO meta_page_connections(company_id,connected_by,page_id,page_name,instagram_user_id,instagram_username,page_access_token_encrypted) VALUES(?,?,?,?,?,?,?)", (self.company_id, self.user_id, "page-44", "Aurora Page", "ig-user-95" if network == "Instagram" else None, "aurora.cafe" if network == "Instagram" else None, meta_integration.encrypt_token("page-access-token", settings))).lastrowid
            db.commit()
        return schedule_id, connection_id

    def test_due_approved_instagram_art_publishes_manually_once_and_records_real_result(self):
        schedule_id, connection_id = self.make_due_schedule()
        with patch.dict(os.environ, self.meta_env, clear=False), patch.object(app_module, "publish_instagram_image", return_value="ig-publication-123") as publish:
            preview = self.client.get("/schedule")
            self.assertEqual(preview.status_code, 200)
            self.assertIn(b"Publicar via Meta", preview.data)
            response = self.client.post(f"/schedule/{schedule_id}/publish", data={"csrf_token": self.csrf(), "connection_id": str(connection_id)})
            self.assertIn("published=", response.location)
            publish.assert_called_once()
            self.assertEqual(publish.call_args.args[0], "ig-user-95")
            self.assertIn("https://agency.example.test/meta/media/approved-art.png?", publish.call_args.args[2])
            duplicate = self.client.post(f"/schedule/{schedule_id}/publish", data={"csrf_token": self.csrf(), "connection_id": str(connection_id)})
            self.assertIn("tentativa+ativa", duplicate.location)
            self.assertEqual(publish.call_count, 1)
            page = self.client.get("/schedule")
            self.assertEqual(page.status_code, 200)
            self.assertIn(b"Publicado via Meta", page.data)
        with app_module.app.app_context():
            db = app_module.get_db()
            attempt = db.execute("SELECT * FROM meta_publication_attempts WHERE schedule_id=?", (schedule_id,)).fetchone()
            self.assertEqual(attempt["status"], "published")
            self.assertEqual(attempt["remote_publication_id"], "ig-publication-123")
            self.assertEqual(db.execute("SELECT COUNT(*) FROM notifications WHERE recipient_type='agency' AND title='Publicação confirmada pela Meta'").fetchone()[0], 1)

    def test_facebook_text_post_uses_selected_page_and_timeout_blocks_blind_retry(self):
        schedule_id, connection_id = self.make_due_schedule(network="Facebook", with_image=False)
        with patch.dict(os.environ, self.meta_env, clear=False), patch.object(app_module, "publish_facebook_text", side_effect=meta_integration.MetaIntegrationError("timeout", uncertain=True)) as publish:
            response = self.client.post(f"/schedule/{schedule_id}/publish", data={"csrf_token": self.csrf(), "connection_id": str(connection_id)})
            self.assertIn("timeout", response.location)
            publish.assert_called_once()
            self.assertEqual(publish.call_args.args[:3], ("page-44", "page-access-token", "Texto autorizado\n\n#aurora"))
            duplicate = self.client.post(f"/schedule/{schedule_id}/publish", data={"csrf_token": self.csrf(), "connection_id": str(connection_id)})
            self.assertIn("incerta", duplicate.location)
            self.assertEqual(publish.call_count, 1)
        with app_module.app.app_context():
            attempt = app_module.get_db().execute("SELECT status FROM meta_publication_attempts WHERE schedule_id=?", (schedule_id,)).fetchone()
            self.assertEqual(attempt["status"], "unknown")

    def test_unknown_attempt_can_be_manually_confirmed_without_faking_api_success(self):
        schedule_id, connection_id = self.make_due_schedule(network="Facebook", with_image=False)
        with patch.dict(os.environ, self.meta_env, clear=False), patch.object(app_module, "publish_facebook_text", side_effect=meta_integration.MetaIntegrationError("timeout", uncertain=True)) as publish:
            self.client.post(f"/schedule/{schedule_id}/publish", data={"csrf_token": self.csrf(), "connection_id": str(connection_id)})
            with app_module.app.app_context():
                attempt_id = app_module.get_db().execute("SELECT id FROM meta_publication_attempts WHERE schedule_id=?", (schedule_id,)).fetchone()[0]
            page = self.client.get("/schedule")
            self.assertIn(b"Conferir resultado na Meta", page.data)
            response = self.client.post(f"/schedule/meta-attempt/{attempt_id}/reconcile", data={"csrf_token": self.csrf(), "outcome": "confirmed_published", "remote_publication_id": "fb-post-verified-19", "note": "Conferido na Página publicada, ID comparado na Meta"})
            self.assertIn("confirmed_published", response.location)
            with app_module.app.app_context():
                db = app_module.get_db()
                attempt = db.execute("SELECT * FROM meta_publication_attempts WHERE id=?", (attempt_id,)).fetchone()
                resolution = db.execute("SELECT * FROM meta_publication_reconciliations WHERE attempt_id=?", (attempt_id,)).fetchone()
                self.assertEqual(attempt["status"], "unknown")
                self.assertIsNone(attempt["remote_publication_id"])
                self.assertEqual(resolution["outcome"], "confirmed_published")
                self.assertEqual(resolution["remote_publication_id"], "fb-post-verified-19")
            page = self.client.get("/schedule")
            self.assertIn(b"Confirmado manualmente na Meta", page.data)
            self.assertIn(b"fb-post-verified-19", page.data)
            self.assertNotIn(b"Registrar confer\xc3\xaancia", page.data)
            report = self.client.get("/reports")
            self.assertIn(b"0 confirmadas pela API", report.data)
            self.assertIn(b"1 conferidas manualmente", report.data)
            blocked = self.client.post(f"/schedule/{schedule_id}/publish", data={"csrf_token": self.csrf(), "connection_id": str(connection_id)})
            self.assertIn("incerta", blocked.location)
            publish.assert_called_once()

    def test_confirming_unknown_not_published_releases_only_after_manual_check(self):
        schedule_id, connection_id = self.make_due_schedule(network="Facebook", with_image=False)
        with patch.dict(os.environ, self.meta_env, clear=False), patch.object(app_module, "publish_facebook_text", side_effect=[meta_integration.MetaIntegrationError("timeout", uncertain=True), "fb-post-after-check"]) as publish:
            self.client.post(f"/schedule/{schedule_id}/publish", data={"csrf_token": self.csrf(), "connection_id": str(connection_id)})
            with app_module.app.app_context():
                attempt_id = app_module.get_db().execute("SELECT id FROM meta_publication_attempts WHERE schedule_id=?", (schedule_id,)).fetchone()[0]
            self.client.post(f"/schedule/meta-attempt/{attempt_id}/reconcile", data={"csrf_token": self.csrf(), "outcome": "confirmed_not_published", "remote_publication_id": "", "note": "Conferido no feed da Página, nenhum post foi criado"})
            with app_module.app.app_context():
                db = app_module.get_db()
                self.assertEqual(db.execute("SELECT status FROM meta_publication_attempts WHERE id=?", (attempt_id,)).fetchone()[0], "failed")
            page = self.client.get("/schedule")
            self.assertIn(b"Publicar via Meta", page.data)
            self.client.post(f"/schedule/{schedule_id}/publish", data={"csrf_token": self.csrf(), "connection_id": str(connection_id)})
            self.assertEqual(publish.call_count, 2)
            with app_module.app.app_context():
                statuses = [row[0] for row in app_module.get_db().execute("SELECT status FROM meta_publication_attempts WHERE schedule_id=? ORDER BY id", (schedule_id,)).fetchall()]
                self.assertEqual(statuses, ["failed", "published"])

    def test_signed_public_media_is_time_limited_and_signature_checked(self):
        (app_module.UPLOAD_DIR / "approved-art.png").write_bytes(b"image bytes")
        with patch.dict(os.environ, self.meta_env, clear=False):
            signed = app_module.create_meta_image_url("approved-art.png")
            parsed = urlparse(signed)
            allowed = self.client.get(parsed.path + "?" + parsed.query)
            self.assertEqual(allowed.status_code, 200)
            self.assertIn("noindex", allowed.headers.get("X-Robots-Tag", ""))
            allowed.close()
            forged = self.client.get(parsed.path + "?expires=9999999999&signature=bad")
            self.assertEqual(forged.status_code, 404)
        self.assertEqual(self.client.get("/meta/media/../../README.md").status_code, 404)

    def test_known_declined_meta_publish_scope_blocks_send_and_explains_reconnect(self):
        schedule_id, connection_id = self.make_due_schedule(network="Facebook", with_image=False)
        with app_module.app.app_context():
            app_module.get_db().execute("INSERT INTO meta_connection_permissions(company_id,connection_id,permission,status) VALUES(?,?,?,?)", (self.company_id, connection_id, "pages_manage_posts", "declined"))
            app_module.get_db().commit()
        with patch.dict(os.environ, self.meta_env, clear=False), patch.object(app_module, "publish_facebook_text") as publish:
            page = self.client.get("/schedule")
            self.assertIn(b"A Meta recusou uma permiss\xc3\xa3o de publica\xc3\xa7\xc3\xa3o", page.data)
            response = self.client.post(f"/schedule/{schedule_id}/publish", data={"csrf_token": self.csrf(), "connection_id": str(connection_id)})
            self.assertIn("pages_manage_posts", response.location)
            publish.assert_not_called()
        with app_module.app.app_context():
            self.assertEqual(app_module.get_db().execute("SELECT COUNT(*) FROM meta_publication_attempts WHERE schedule_id=?", (schedule_id,)).fetchone()[0], 0)

    def test_future_schedule_is_not_sent_to_graph_api(self):
        schedule_id, connection_id = self.make_due_schedule()
        with app_module.app.app_context():
            future = (datetime.now(timezone.utc) + timedelta(days=1)).isoformat(timespec="seconds")
            app_module.get_db().execute("UPDATE scheduled_publications SET scheduled_at_utc=? WHERE id=?", (future, schedule_id))
            app_module.get_db().commit()
        with patch.dict(os.environ, self.meta_env, clear=False), patch.object(app_module, "publish_instagram_image") as publish:
            response = self.client.post(f"/schedule/{schedule_id}/publish", data={"csrf_token": self.csrf(), "connection_id": str(connection_id)})
            self.assertIn("ainda+n%C3%A3o+chegou", response.location)
            publish.assert_not_called()


if __name__ == "__main__":
    unittest.main()
