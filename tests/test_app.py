import base64
import io
import os
import tempfile
import unittest
from pathlib import Path

TEST_ROOT = tempfile.TemporaryDirectory()
os.environ["SOCIALMEDIA_DATA"] = TEST_ROOT.name
os.environ["SOCIALMEDIA_DB"] = str(Path(TEST_ROOT.name) / "bootstrap.db")
import app as app_module  # noqa: E402
app = app_module.app
init_db = app_module.init_db


class SocialMediaStageOneTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        app_module.DB_PATH = Path(self.temp.name) / "socialmedia.db"
        app_module.UPLOAD_DIR = Path(self.temp.name) / "uploads"
        app_module.UPLOAD_DIR.mkdir()
        init_db()
        app.config.update(TESTING=True, SECRET_KEY="test-secret")
        self.client = app.test_client()

    def tearDown(self):
        self.temp.cleanup()

    def token(self):
        with self.client.session_transaction() as sess:
            return sess.get("csrf_token")

    def register(self, name="Test User", email="owner@example.com", company="Studio Test"):
        self.client.get("/register")
        return self.client.post("/register", data={
            "csrf_token": self.token(), "name": name, "email": email,
            "company": company, "password": "StrongPass123",
        }, follow_redirects=True)

    def create_client(self, name="Aurora Café"):
        return self.client.post("/clients/new", data={
            "csrf_token": self.token(), "company_name": name,
            "trade_name": "Aurora", "segment": "Gastronomia",
            "description": "Café especial", "audience": "Pessoas apaixonadas por café",
            "business_goals": "Aumentar pedidos no WhatsApp",
        }, follow_redirects=True)

    def test_registration_creates_isolated_workspace_and_dashboard(self):
        response = self.register()
        self.assertEqual(response.status_code, 200)
        self.assertIn("Visão do workspace".encode(), response.data)
        with app.app_context():
            db = app_module.get_db()
            self.assertEqual(db.execute("SELECT COUNT(*) FROM companies").fetchone()[0], 1)
            self.assertEqual(db.execute("SELECT role FROM users").fetchone()[0], "owner")

    def test_client_create_edit_brand_identity_and_delete(self):
        self.register()
        response = self.create_client()
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"Identidade visual", response.data)
        with app.app_context():
            row = app_module.get_db().execute("SELECT id FROM clients").fetchone()
            client_id = row["id"]
        response = self.client.post(f"/clients/{client_id}", data={
            "csrf_token": self.token(), "company_name": "Aurora Café Ltda",
            "trade_name": "Aurora", "segment": "Cafeteria",
            "primary_color": "#123abc", "secondary_color": "#ef7733", "accent_color": "#16a68a",
            "typography": "Manrope", "slogan": "Café com calma",
            "visual_style": "Natural", "communication_tone": "Acolhedor",
        }, follow_redirects=True)
        self.assertEqual(response.status_code, 200)
        self.assertIn("Alterações salvas".encode(), response.data)
        with app.app_context():
            db = app_module.get_db()
            self.assertEqual(db.execute("SELECT company_name FROM clients WHERE id=?", (client_id,)).fetchone()[0], "Aurora Café Ltda")
            self.assertEqual(db.execute("SELECT primary_color FROM client_branding WHERE client_id=?", (client_id,)).fetchone()[0], "#123abc")
        response = self.client.post(f"/clients/{client_id}/delete", data={"csrf_token": self.token()}, follow_redirects=True)
        self.assertEqual(response.status_code, 200)
        with app.app_context():
            self.assertEqual(app_module.get_db().execute("SELECT COUNT(*) FROM clients").fetchone()[0], 0)

    def test_upload_logo_and_reference_images(self):
        self.register()
        self.create_client()
        with app.app_context():
            client_id = app_module.get_db().execute("SELECT id FROM clients").fetchone()["id"]
        for endpoint, field in [(f"/clients/{client_id}/branding/logo", "logo"), (f"/clients/{client_id}/assets", "reference")]:
            response = self.client.post(endpoint, data={
                "csrf_token": self.token(), "file": (io.BytesIO(base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII=")), f"{field}.png", "image/png")
            }, content_type="multipart/form-data")
            self.assertEqual(response.status_code, 302)
        with app.app_context():
            db = app_module.get_db()
            self.assertTrue(db.execute("SELECT logo_path FROM client_branding").fetchone()[0])
            self.assertEqual(db.execute("SELECT COUNT(*) FROM client_assets").fetchone()[0], 1)

    def test_tenant_isolation_and_role_permissions(self):
        self.register()
        self.create_client()
        with app.app_context():
            owner = app_module.get_db().execute("SELECT id FROM users").fetchone()["id"]
            client_id = app_module.get_db().execute("SELECT id FROM clients").fetchone()["id"]
        # A second agency cannot see or delete the first agency's client.
        self.client.post("/logout", data={"csrf_token": self.token()})
        self.register("Other User", "other@example.com", "Other Studio")
        self.assertEqual(self.client.get(f"/clients/{client_id}").status_code, 404)
        # The account owner can add a read-only collaborator.
        self.client.post("/logout", data={"csrf_token": self.token()})
        self.client.get("/login")
        self.client.post("/login", data={"csrf_token": self.token(), "email": "owner@example.com", "password": "StrongPass123"})
        response = self.client.post("/team", data={"csrf_token": self.token(), "name": "Read Only", "email": "reader@example.com", "password": "ReaderPass123", "role": "viewer"}, follow_redirects=True)
        self.assertEqual(response.status_code, 200)
        self.client.post("/logout", data={"csrf_token": self.token()})
        self.client.get("/login")
        self.client.post("/login", data={"csrf_token": self.token(), "email": "reader@example.com", "password": "ReaderPass123"})
        self.assertEqual(self.client.get(f"/clients/{client_id}").status_code, 200)
        denied = self.client.post(f"/clients/{client_id}", data={"csrf_token": self.token(), "company_name": "Alterado"})
        self.assertEqual(denied.status_code, 403)
        self.assertEqual(self.client.post("/clients/new", data={"csrf_token": self.token(), "company_name": "No"}).status_code, 403)

    def test_csrf_and_password_validation(self):
        response = self.client.post("/register", data={"name": "Bad", "email": "bad@example.com", "company": "Bad", "password": "123"})
        self.assertEqual(response.status_code, 400)
        self.client.get("/register")
        response = self.client.post("/register", data={"csrf_token": self.token(), "name": "Bad", "email": "bad@example.com", "company": "Bad", "password": "123"})
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"pelo menos 8", response.data)


if __name__ == "__main__":
    unittest.main()
