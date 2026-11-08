import base64
import json
import os
import tempfile
import unittest
from pathlib import Path

TEST_ROOT = tempfile.TemporaryDirectory()
os.environ.setdefault("SOCIALMEDIA_DATA", TEST_ROOT.name)
os.environ.setdefault("SOCIALMEDIA_DB", str(Path(TEST_ROOT.name) / "bootstrap.db"))
import app as app_module  # noqa: E402


class SocialMediaStageFourTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        app_module.DB_PATH = Path(self.temp.name) / "socialmedia.db"
        app_module.UPLOAD_DIR = Path(self.temp.name) / "uploads"
        app_module.UPLOAD_DIR.mkdir()
        app_module.init_db()
        app_module.app.config.update(TESTING=True, SECRET_KEY="test-secret")
        self.client = app_module.app.test_client()
        self.client.get("/register")
        self.client.post("/register", data={"csrf_token": self.csrf(), "name": "Feed Owner", "email": "feed@example.com", "company": "Feed Studio", "password": "StrongPass123"})
        self.client.post("/clients/new", data={"csrf_token": self.csrf(), "company_name": "Aurora Café", "trade_name": "Aurora", "segment": "Cafeteria", "instagram": "@auroracafe"})
        with app_module.app.app_context():
            db = app_module.get_db()
            self.company_id = db.execute("SELECT company_id FROM users").fetchone()[0]
            self.user_id = db.execute("SELECT id FROM users").fetchone()[0]
            self.client_id = db.execute("SELECT id FROM clients").fetchone()[0]
            db.execute("UPDATE client_branding SET primary_color='#3454a3' WHERE client_id=?", (self.client_id,))
            (app_module.UPLOAD_DIR / "feed-demo.png").write_bytes(base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII="))
            self.creative_id = db.execute("INSERT INTO creative_posts(company_id,client_id,created_by,subject,format_key,canvas_width,canvas_height,content_json,layout_json,background_color,image_prompt,status,export_path) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)", (self.company_id, self.client_id, self.user_id, "Café de origem", "square", 1080, 1080, json.dumps({"title": "Café de origem", "caption": "Conheça nosso café.", "hashtags": "#cafe", "visual_brief": "Tons quentes"}), json.dumps({"elements": []}), "#3454a3", "Café especial em destaque", "exported", "feed-demo.png")).lastrowid
            plan_id = db.execute("INSERT INTO content_calendar(company_id,client_id,created_by,title,period_days,start_date,objective,campaign_context,networks_json,target_posts,ai_model) VALUES(?,?,?,?,?,?,?,?,?,?,?)", (self.company_id, self.client_id, self.user_id, "Plano semanal", 7, "2026-11-08", "Aumentar visitas", "", '["Instagram"]', 1, "gpt-4o-mini")).lastrowid
            self.post_id = db.execute("INSERT INTO posts(company_id,client_id,calendar_id,position,publication_date,network,theme,strategic_objective,content_type,main_text,caption,hashtags,cta,visual_brief,image_suggestion) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", (self.company_id, self.client_id, plan_id, 1, "2026-11-10", "Instagram", "Como nasce um bom café", "Construir reconhecimento", "Carrossel", "Conheça a origem", "Cada xícara tem uma história.", "#cafedeminas", "Venha conhecer", "Foto de grãos selecionados.", "Grãos de café",)).lastrowid
            db.commit()

    def tearDown(self):
        self.temp.cleanup()

    def csrf(self):
        with self.client.session_transaction() as session:
            return session.get("csrf_token")

    def test_feed_preview_adds_art_and_planned_post_without_claiming_schedule(self):
        response = self.client.get(f"/feed?client_id={self.client_id}")
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"Feed preview", response.data)
        self.assertIn(b"@auroracafe", response.data)
        self.assertIn("não estão agendadas".encode(), response.data)
        response = self.client.post(f"/feed/{self.client_id}/add/creative/{self.creative_id}", data={"csrf_token": self.csrf()})
        self.assertEqual(response.status_code, 302)
        response = self.client.post(f"/feed/{self.client_id}/add/post/{self.post_id}", data={"csrf_token": self.csrf()})
        self.assertEqual(response.status_code, 302)
        response = self.client.get(f"/feed?client_id={self.client_id}")
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"ARTE EXPORTADA", response.data)
        self.assertIn(b"Como nasce um bom caf\xc3\xa9", response.data)
        self.assertIn(b"Planejada \xc2\xb7 sem arte", response.data)
        with app_module.app.app_context():
            items = app_module.get_db().execute("SELECT id,creative_id,planned_post_id FROM feed_items WHERE client_id=? ORDER BY position", (self.client_id,)).fetchall()
            self.assertEqual(len(items), 2)
            self.assertEqual(items[0]["creative_id"], self.creative_id)
            self.assertEqual(items[1]["planned_post_id"], self.post_id)

    def test_drag_reorder_csrf_tenant_scope_and_removal(self):
        self.client.post(f"/feed/{self.client_id}/add/creative/{self.creative_id}", data={"csrf_token": self.csrf()})
        self.client.post(f"/feed/{self.client_id}/add/post/{self.post_id}", data={"csrf_token": self.csrf()})
        with app_module.app.app_context():
            ids = [row[0] for row in app_module.get_db().execute("SELECT id FROM feed_items WHERE client_id=? ORDER BY position", (self.client_id,)).fetchall()]
        response = self.client.post(f"/feed/{self.client_id}/reorder", json={"order": list(reversed(ids))}, headers={"X-CSRF-Token": self.csrf()})
        self.assertEqual(response.status_code, 200)
        with app_module.app.app_context():
            result = [row[0] for row in app_module.get_db().execute("SELECT id FROM feed_items WHERE client_id=? ORDER BY position", (self.client_id,)).fetchall()]
            self.assertEqual(result, list(reversed(ids)))
        bad = self.client.post(f"/feed/{self.client_id}/reorder", json={"order": [ids[0], ids[0]]}, headers={"X-CSRF-Token": self.csrf()})
        self.assertEqual(bad.status_code, 400)
        csrf = self.csrf()
        self.client.post("/logout", data={"csrf_token": csrf})
        self.client.get("/register")
        self.client.post("/register", data={"csrf_token": self.csrf(), "name": "Other", "email": "other-feed@example.com", "company": "Other Feed", "password": "StrongPass123"})
        self.assertEqual(self.client.post(f"/feed/{self.client_id}/add/creative/{self.creative_id}", data={"csrf_token": self.csrf()}).status_code, 404)
        self.assertEqual(self.client.get(f"/feed?client_id={self.client_id}").status_code, 200)

    def test_planner_post_delete_cascades_feed_reference(self):
        self.client.post(f"/feed/{self.client_id}/add/post/{self.post_id}", data={"csrf_token": self.csrf()})
        with app_module.app.app_context():
            post = app_module.get_db().execute("SELECT calendar_id FROM posts WHERE id=?", (self.post_id,)).fetchone()
        response = self.client.post(f"/planner/posts/{self.post_id}/delete", data={"csrf_token": self.csrf()})
        self.assertEqual(response.status_code, 302)
        with app_module.app.app_context():
            self.assertEqual(app_module.get_db().execute("SELECT COUNT(*) FROM feed_items WHERE planned_post_id=?", (self.post_id,)).fetchone()[0], 0)


if __name__ == "__main__":
    unittest.main()
