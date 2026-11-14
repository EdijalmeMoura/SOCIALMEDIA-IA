import json
import os
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

TEST_ROOT = tempfile.TemporaryDirectory()
# Sem .env real: a suíte pressupõe raiz "/" e ambiente limpo.
os.environ["SOCIALMEDIA_DOTENV"] = str(Path(TEST_ROOT.name) / "sem-dotenv.env")
os.environ.pop("SOCIALMEDIA_BASE_PATH", None)
os.environ.setdefault("SOCIALMEDIA_DATA", TEST_ROOT.name)
os.environ.setdefault("SOCIALMEDIA_DB", str(Path(TEST_ROOT.name) / "bootstrap.db"))
import app as app_module  # noqa: E402


class SocialMediaOperationalReportTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        app_module.DB_PATH = Path(self.temp.name) / "reports.db"
        app_module.UPLOAD_DIR = Path(self.temp.name) / "uploads"
        app_module.UPLOAD_DIR.mkdir()
        app_module.init_db()
        app_module.app.config.update(TESTING=True, SECRET_KEY="reports-test-secret")
        self.client = app_module.app.test_client()
        self.client.get("/register")
        self.client.post("/register", data={"csrf_token": self.csrf(), "name": "Agency Reports", "email": "reports@example.com", "company": "Reports Agency", "password": "StrongPass123"})
        self.client.post("/clients/new", data={"csrf_token": self.csrf(), "company_name": "Cliente Relatórios", "trade_name": "Marca Um"})
        with app_module.app.app_context():
            db = app_module.get_db()
            user = db.execute("SELECT id,company_id FROM users").fetchone()
            self.user_id, self.company_id = user["id"], user["company_id"]
            self.client_id = db.execute("SELECT id FROM clients WHERE company_id=?", (self.company_id,)).fetchone()[0]
            today = datetime.now(timezone.utc).date().isoformat()
            calendar_id = db.execute("INSERT INTO content_calendar(company_id,client_id,created_by,title,period_days,start_date,objective,campaign_context,networks_json,target_posts,ai_model) VALUES(?,?,?,?,?,?,?,?,?,?,?)", (self.company_id, self.client_id, self.user_id, "Plano Relatórios", 7, today, "Awareness", "", json.dumps(["Instagram"]), 1, "test-model")).lastrowid
            post_id = db.execute("INSERT INTO posts(company_id,client_id,calendar_id,position,publication_date,network,theme,strategic_objective,content_type,main_text,caption,hashtags,cta,visual_brief,image_suggestion,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", (self.company_id, self.client_id, calendar_id, 1, today, "Instagram", '=HYPERLINK("https://bad.example")', "Awareness", "Feed", "Texto", "Legenda", "#marca", "Veja mais", "Visual", "Foto", "planned")).lastrowid
            creative_id = db.execute("INSERT INTO creative_posts(company_id,client_id,created_by,subject,format_key,canvas_width,canvas_height,content_json,layout_json,background_color,image_prompt,export_path,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)", (self.company_id, self.client_id, self.user_id, "Arte exportada", "square", 1080, 1080, json.dumps({}), json.dumps({}), "#ffffff", "prompt", "exported.png", "exported")).lastrowid
            approved_snapshot = {"type": "planned", "title": "Publicação aprovada", "network": "Instagram"}
            approved_id = db.execute("INSERT INTO approvals(company_id,client_id,created_by,source_type,planned_post_id,cycle_number,title,snapshot_json,status,resolved_at) VALUES(?,?,?,?,?,?,?,?, 'approved',CURRENT_TIMESTAMP)", (self.company_id, self.client_id, self.user_id, "planned", post_id, 1, "Publicação aprovada", json.dumps(approved_snapshot))).lastrowid
            db.execute("INSERT INTO approval_events(approval_id,company_id,actor_type,actor_user_id,previous_status,new_status,note) VALUES(?,?,'agency',?,NULL,'pending','Enviado')", (approved_id, self.company_id, self.user_id))
            db.execute("INSERT INTO approval_events(approval_id,company_id,actor_type,actor_user_id,previous_status,new_status,note) VALUES(?,?,'agency',?,'pending','approved','Aprovado')", (approved_id, self.company_id, self.user_id))
            pending_snapshot = {"type": "creative", "title": "Arte pendente"}
            pending_id = db.execute("INSERT INTO approvals(company_id,client_id,created_by,source_type,creative_id,cycle_number,title,snapshot_json,status) VALUES(?,?,?,?,?,?,?,?,'pending')", (self.company_id, self.client_id, self.user_id, "creative", creative_id, 1, "Arte pendente", json.dumps(pending_snapshot))).lastrowid
            db.execute("INSERT INTO approval_events(approval_id,company_id,actor_type,actor_user_id,previous_status,new_status,note) VALUES(?,?,'agency',?,NULL,'pending','Enviado')", (pending_id, self.company_id, self.user_id))
            scheduled = (datetime.now(timezone.utc) + timedelta(days=1)).isoformat(timespec="seconds")
            db.execute("INSERT INTO scheduled_publications(company_id,client_id,approval_id,scheduled_by,network,scheduled_at_utc,timezone,status) VALUES(?,?,?,?,?,?,?,'scheduled')", (self.company_id, self.client_id, approved_id, self.user_id, "Instagram", scheduled, "UTC"))
            other_company = db.execute("INSERT INTO companies(name,slug) VALUES(?,?)", ("Outra Agência", "other-reports-agency")).lastrowid
            other_user = db.execute("INSERT INTO users(company_id,name,email,password_hash,role) VALUES(?,?,?,?,?)", (other_company, "Outro", "other-reports@example.com", app_module.generate_password_hash("OtherPass123"), "owner")).lastrowid
            other_client = db.execute("INSERT INTO clients(company_id,company_name) VALUES(?,?)", (other_company, "Cliente privado de outra agência")).lastrowid
            other_calendar = db.execute("INSERT INTO content_calendar(company_id,client_id,created_by,title,period_days,start_date,objective,campaign_context,networks_json,target_posts,ai_model) VALUES(?,?,?,?,?,?,?,?,?,?,?)", (other_company, other_client, other_user, "Plano privado", 7, today, "Awareness", "", json.dumps(["Facebook"]), 1, "test-model")).lastrowid
            db.execute("INSERT INTO posts(company_id,client_id,calendar_id,position,publication_date,network,theme,strategic_objective,content_type,main_text,caption,hashtags,cta,visual_brief,image_suggestion,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", (other_company, other_client, other_calendar, 1, today, "Facebook", "SEGREDO DE OUTRO TENANT", "Awareness", "Feed", "Texto", "Legenda", "", "", "Visual", "Foto", "planned"))
            db.commit()
            self.other_client_id = other_client

    def tearDown(self):
        self.temp.cleanup()

    def csrf(self):
        with self.client.session_transaction() as session:
            return session.get("csrf_token")

    def period(self):
        today = datetime.now(timezone.utc).date().isoformat()
        return {"start_date": today, "end_date": today}

    def test_filtered_report_uses_only_real_tenant_records(self):
        params = self.period()
        params["client_id"] = str(self.client_id)
        response = self.client.get("/reports", query_string=params)
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"Publica\xc3\xa7\xc3\xa3o aprovada", response.data)
        self.assertIn(b"Arte exportada", response.data)
        self.assertIn(b"1 aprovadas", response.data)
        self.assertNotIn(b"Cliente privado de outra ag\xc3\xaancia", response.data)

    def test_csv_export_is_tenant_scoped_and_safe_for_spreadsheets(self):
        params = self.period()
        response = self.client.get("/reports/export.csv", query_string=params)
        self.assertEqual(response.status_code, 200)
        self.assertIn("text/csv", response.headers["Content-Type"])
        self.assertIn("no-store", response.headers.get("Cache-Control", ""))
        self.assertTrue(response.data.startswith(b"\xef\xbb\xbf"))
        self.assertIn(b"'=HYPERLINK", response.data)
        self.assertNotIn(b"SEGREDO DE OUTRO TENANT", response.data)
        denied = self.client.get("/reports/export.csv", query_string={**params, "client_id": self.other_client_id})
        self.assertEqual(denied.status_code, 404)

    def test_invalid_report_ranges_are_rejected_or_explained(self):
        response = self.client.get("/reports", query_string={"start_date": "2026-12-01", "end_date": "2026-01-01"})
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"data final precisa ser", response.data)
        bad_export = self.client.get("/reports/export.csv", query_string={"start_date": "not-a-date", "end_date": "2026-01-01"})
        self.assertEqual(bad_export.status_code, 400)


if __name__ == "__main__":
    unittest.main()
