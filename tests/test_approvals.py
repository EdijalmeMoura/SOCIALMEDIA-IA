import base64
import io
import json
import os
import tempfile
import unittest
from pathlib import Path

TEST_ROOT = tempfile.TemporaryDirectory()
os.environ.setdefault("SOCIALMEDIA_DATA", TEST_ROOT.name)
os.environ.setdefault("SOCIALMEDIA_DB", str(Path(TEST_ROOT.name) / "bootstrap.db"))
import app as app_module  # noqa: E402


class SocialMediaStageFiveTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        app_module.DB_PATH = Path(self.temp.name) / "socialmedia.db"
        app_module.UPLOAD_DIR = Path(self.temp.name) / "uploads"
        app_module.UPLOAD_DIR.mkdir()
        app_module.init_db()
        app_module.app.config.update(TESTING=True, SECRET_KEY="test-secret")
        self.client = app_module.app.test_client()
        self.client.get("/register")
        self.client.post("/register", data={"csrf_token": self.csrf(), "name": "Agency Owner", "email": "agency-approval@example.com", "company": "Approval Studio", "password": "StrongPass123"})
        self.client.post("/clients/new", data={"csrf_token": self.csrf(), "company_name": "Aurora Café", "trade_name": "Aurora", "segment": "Cafeteria"})
        with app_module.app.app_context():
            db = app_module.get_db()
            self.company_id = db.execute("SELECT company_id FROM users").fetchone()[0]
            self.user_id = db.execute("SELECT id FROM users").fetchone()[0]
            self.client_id = db.execute("SELECT id FROM clients").fetchone()[0]
            (app_module.UPLOAD_DIR / "approval-final.png").write_bytes(base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII="))
            self.creative_id = db.execute("INSERT INTO creative_posts(company_id,client_id,created_by,subject,format_key,canvas_width,canvas_height,content_json,layout_json,background_color,image_prompt,export_path,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)", (self.company_id, self.client_id, self.user_id, "Café da casa", "square", 1080, 1080, json.dumps({"title": "Um café com história", "subtitle": "Feito para sua pausa", "caption": "Venha tomar um café especial.", "hashtags": "#cafesespeciais", "cta": "Visite a Aurora", "visual_brief": "Azul profundo, luz quente"}), json.dumps({"elements": []}), "#234567", "Café coado", "approval-final.png", "exported")).lastrowid
            db.commit()
        self.client.post("/approvals/portal-users", data={"csrf_token": self.csrf(), "client_id": str(self.client_id), "name": "Cliente Aurora", "email": "client-approval@example.com", "password": "ClientPass123"})

    def tearDown(self):
        self.temp.cleanup()

    def csrf(self):
        with self.client.session_transaction() as session:
            return session.get("csrf_token")

    def create_approval(self):
        return self.client.post("/approvals", data={"csrf_token": self.csrf(), "client_id": str(self.client_id), "source": f"creative:{self.creative_id}", "message": "Confira a primeira versão, por favor."})

    def portal_login(self):
        self.client.post("/logout", data={"csrf_token": self.csrf()})
        self.client.get("/portal/login")
        return self.client.post("/portal/login", data={"csrf_token": self.csrf(), "email": "client-approval@example.com", "password": "ClientPass123"}, follow_redirects=True)

    def agency_login(self):
        self.client.post("/portal/logout", data={"csrf_token": self.csrf()})
        self.client.get("/login")
        return self.client.post("/login", data={"csrf_token": self.csrf(), "email": "agency-approval@example.com", "password": "StrongPass123"}, follow_redirects=True)

    def test_private_portal_approval_changes_request_and_cycle_history(self):
        response = self.client.get("/approvals")
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"Acesso ao portal do cliente", response.data)
        response = self.create_approval()
        self.assertEqual(response.status_code, 302)
        approval_id = int(response.location.split("/approvals/")[1].split("?")[0])
        with app_module.app.app_context():
            db = app_module.get_db()
            db.execute("UPDATE creative_posts SET content_json=? WHERE id=?", (json.dumps({"title": "Texto alterado depois"}), self.creative_id))
            db.commit()
        response = self.portal_login()
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"Caf\xc3\xa9 da casa", response.data)
        detail = self.client.get(f"/portal/approvals/{approval_id}")
        self.assertEqual(detail.status_code, 200)
        self.assertIn(b"Um caf\xc3\xa9 com hist\xc3\xb3ria", detail.data)
        self.assertIn(b"approval-final.png", detail.data)
        response = self.client.post(f"/portal/approvals/{approval_id}/respond", data={"csrf_token": self.csrf(), "status": "changes_requested", "body": "Aumentar um pouco o título."}, follow_redirects=True)
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"Sua resposta foi registrada", response.data)
        self.agency_login()
        agency_view = self.client.get(f"/approvals/{approval_id}")
        self.assertEqual(agency_view.status_code, 200)
        self.assertIn(b"Aumentar um pouco o t\xc3\xadtulo", agency_view.data)
        self.assertIn(b"Altera\xc3\xa7\xc3\xb5es solicitadas", agency_view.data)
        response = self.create_approval()
        self.assertEqual(response.status_code, 302)
        second_id = int(response.location.split("/approvals/")[1].split("?")[0])
        with app_module.app.app_context():
            db = app_module.get_db()
            self.assertEqual(db.execute("SELECT cycle_number FROM approvals WHERE id=?", (second_id,)).fetchone()[0], 2)
            self.assertEqual(db.execute("SELECT COUNT(*) FROM approval_events WHERE approval_id=?", (approval_id,)).fetchone()[0], 2)

    def test_pending_duplicate_is_rejected_and_single_active_cycle_is_preserved(self):
        first = self.create_approval()
        self.assertEqual(first.status_code, 302)
        second = self.create_approval()
        self.assertIn("error=Este+conte%C3%BAdo", second.location)
        with app_module.app.app_context():
            db = app_module.get_db()
            self.assertEqual(db.execute("SELECT COUNT(*) FROM approvals WHERE status='pending'").fetchone()[0], 1)
            self.assertEqual(db.execute("SELECT COUNT(*) FROM approval_events").fetchone()[0], 1)

    def test_client_approves_and_agency_audit_is_visible(self):
        self.create_approval()
        with app_module.app.app_context():
            approval_id = app_module.get_db().execute("SELECT id FROM approvals").fetchone()[0]
        self.portal_login()
        response = self.client.post(f"/portal/approvals/{approval_id}/respond", data={"csrf_token": self.csrf(), "status": "approved", "body": "Aprovado!"}, follow_redirects=True)
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"Aprovado", response.data)
        response = self.client.post(f"/portal/approvals/{approval_id}/respond", data={"csrf_token": self.csrf(), "status": "rejected"})
        self.assertEqual(response.status_code, 409)
        self.agency_login()
        with app_module.app.app_context():
            db = app_module.get_db()
            self.assertEqual(db.execute("SELECT status FROM approvals WHERE id=?", (approval_id,)).fetchone()[0], "approved")
            self.assertEqual(db.execute("SELECT new_status FROM approval_events WHERE approval_id=? ORDER BY id DESC LIMIT 1", (approval_id,)).fetchone()[0], "approved")
            self.assertEqual(db.execute("SELECT body FROM comments WHERE approval_id=? ORDER BY id DESC LIMIT 1", (approval_id,)).fetchone()[0], "Aprovado!")

    def test_portal_is_scoped_and_change_request_requires_comment(self):
        self.create_approval()
        with app_module.app.app_context():
            approval_id = app_module.get_db().execute("SELECT id FROM approvals").fetchone()[0]
        self.portal_login()
        empty = self.client.post(f"/portal/approvals/{approval_id}/respond", data={"csrf_token": self.csrf(), "status": "changes_requested"})
        self.assertEqual(empty.status_code, 400)
        with app_module.app.app_context():
            db = app_module.get_db()
            other_client = db.execute("INSERT INTO clients(company_id,company_name) VALUES(?,?)", (self.company_id, "Outro Cliente")).lastrowid
            db.execute("INSERT INTO client_portal_users(company_id,client_id,name,email,password_hash) VALUES(?,?,?,?,?)", (self.company_id, other_client, "Outro", "another-client@example.com", app_module.generate_password_hash("OtherPass123")))
            db.commit()
        self.assertEqual(self.client.get(f"/portal/approvals/{approval_id + 999}").status_code, 404)
        self.client.post("/portal/logout", data={"csrf_token": self.csrf()})
        self.client.get("/portal/login")
        self.client.post("/portal/login", data={"csrf_token": self.csrf(), "email": "another-client@example.com", "password": "OtherPass123"})
        self.assertEqual(self.client.get(f"/portal/approvals/{approval_id}").status_code, 404)

    def test_agency_and_client_can_exchange_private_reference_images(self):
        self.create_approval()
        with app_module.app.app_context():
            approval_id = app_module.get_db().execute("SELECT id FROM approvals").fetchone()[0]
        png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII=")
        response = self.client.post(f"/approvals/{approval_id}/comment", data={"csrf_token": self.csrf(), "body": "Referência da paleta", "file": (io.BytesIO(png), "palette.png", "image/png")}, content_type="multipart/form-data")
        self.assertEqual(response.status_code, 302)
        self.assertNotIn("error=", response.location)
        self.assertIn("#history", response.location)
        with app_module.app.app_context():
            attachment = app_module.get_db().execute("SELECT attachment_path FROM comments WHERE approval_id=? AND attachment_path IS NOT NULL", (approval_id,)).fetchone()[0]
        self.assertIsNotNone(attachment)
        media_response = self.client.get(f"/approvals/{approval_id}/media/{attachment}")
        self.assertEqual(media_response.status_code, 200)
        media_response.close()
        self.portal_login()
        response = self.client.post(f"/portal/approvals/{approval_id}/respond", data={"csrf_token": self.csrf(), "status": "changes_requested", "body": "Considerar esta referência também.", "file": (io.BytesIO(png), "client-ref.png", "image/png")}, content_type="multipart/form-data")
        self.assertEqual(response.status_code, 302)
        with app_module.app.app_context():
            client_attachment = app_module.get_db().execute("SELECT attachment_path FROM comments WHERE approval_id=? AND author_type='client'", (approval_id,)).fetchone()[0]
        media_response = self.client.get(f"/portal/approvals/{approval_id}/media/{client_attachment}")
        self.assertEqual(media_response.status_code, 200)
        self.assertIn("no-store", media_response.headers.get("Cache-Control", ""))
        media_response.close()

    def test_draft_art_cannot_be_sent_as_final_approval(self):
        with app_module.app.app_context():
            db = app_module.get_db()
            db.execute("UPDATE creative_posts SET export_path=NULL,status='draft' WHERE id=?", (self.creative_id,))
            db.commit()
        response = self.create_approval()
        self.assertIn("error=Selecione", response.location)
        with app_module.app.app_context():
            self.assertEqual(app_module.get_db().execute("SELECT COUNT(*) FROM approvals").fetchone()[0], 0)


if __name__ == "__main__":
    unittest.main()
