import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

TEST_ROOT = tempfile.TemporaryDirectory()
os.environ.setdefault("SOCIALMEDIA_DATA", TEST_ROOT.name)
os.environ.setdefault("SOCIALMEDIA_DB", str(Path(TEST_ROOT.name) / "bootstrap.db"))
import app as app_module  # noqa: E402


class FakeResponse:
    def __init__(self, payload):
        self.payload = json.dumps(payload).encode("utf-8")

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def read(self):
        return self.payload


class SocialMediaStageTwoTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        app_module.DB_PATH = Path(self.temp.name) / "socialmedia.db"
        app_module.UPLOAD_DIR = Path(self.temp.name) / "uploads"
        app_module.UPLOAD_DIR.mkdir()
        app_module.init_db()
        app_module.app.config.update(TESTING=True, SECRET_KEY="test-secret")
        self.client = app_module.app.test_client()
        self.client.get("/register")
        with self.client.session_transaction() as sess:
            csrf = sess["csrf_token"]
        self.client.post("/register", data={"csrf_token": csrf, "name": "Planner Owner", "email": "planner@example.com", "company": "Planner Studio", "password": "StrongPass123"})
        with self.client.session_transaction() as sess:
            csrf = sess["csrf_token"]
        self.client.post("/clients/new", data={
            "csrf_token": csrf, "company_name": "Aurora Pizzaria", "trade_name": "Aurora Pizza",
            "segment": "Gastronomia", "description": "Pizzaria artesanal", "products_services": "Pizzas artesanais e sobremesas",
            "audience": "Famílias e jovens da região", "business_goals": "Aumentar pedidos pelo WhatsApp",
        })
        with app_module.app.app_context():
            self.client_id = app_module.get_db().execute("SELECT id FROM clients").fetchone()["id"]
            app_module.get_db().execute("UPDATE client_branding SET primary_color='#a12345',visual_style='Quente e artesanal',communication_tone='Próximo' WHERE client_id=?", (self.client_id,))
            app_module.get_db().commit()

    def tearDown(self):
        self.temp.cleanup()

    def csrf(self):
        with self.client.session_transaction() as sess:
            return sess["csrf_token"]

    @staticmethod
    def ai_payload(count=2):
        posts = []
        for index in range(count):
            posts.append({
                "publication_date": ["2026-11-08", "2026-11-11"][index % 2],
                "network": "Instagram", "theme": f"Sabor da casa {index + 1}",
                "strategic_objective": "Aumentar pedidos pelo WhatsApp",
                "content_type": "Carrossel", "main_text": f"Texto principal {index + 1}",
                "caption": f"Uma pizza artesanal feita para compartilhar. Peça no WhatsApp. {index + 1}",
                "hashtags": ["#pizzaria", "#feitoartesanalmente"], "cta": "Peça pelo WhatsApp",
                "visual_brief": "Fotografia apetitosa, cores quentes alinhadas à marca.",
                "image_suggestion": "Pizza artesanal saindo do forno a lenha.",
            })
        return {"choices": [{"message": {"content": json.dumps({"posts": posts}, ensure_ascii=False)}}], "usage": {"prompt_tokens": 530, "completion_tokens": 410}}

    def form(self):
        return {"csrf_token": self.csrf(), "client_id": str(self.client_id), "period_days": "7", "start_date": "2026-11-08", "objective": "Aumentar pedidos pelo WhatsApp", "campaign_context": "Destacar a pizza da casa", "target_posts": "2", "networks": ["Instagram"]}

    def generate_plan(self):
        os.environ["OPENAI_API_KEY"] = "unit-test-key"
        fake = FakeResponse(self.ai_payload())
        with patch.object(app_module.urllib.request, "urlopen", return_value=fake) as urlopen:
            response = self.client.post("/planner/generate", data=self.form())
        return response, urlopen

    def test_planner_builder_loads_tenant_clients(self):
        response = self.client.get("/planner")
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"Aurora Pizza", response.data)
        self.assertIn(b"Come\xc3\xa7ar um planejamento", response.data)

    def test_real_provider_request_uses_brand_context_and_persists_plan(self):
        response, urlopen = self.generate_plan()
        self.assertEqual(response.status_code, 302)
        self.assertIn("/planner/1?created=1", response.location)
        sent_request = urlopen.call_args.args[0]
        self.assertEqual(sent_request.full_url, "https://api.openai.com/v1/chat/completions")
        self.assertEqual(sent_request.get_header("Authorization"), "Bearer unit-test-key")
        body = json.loads(sent_request.data)
        user_prompt = json.loads(body["messages"][1]["content"])
        self.assertEqual(user_prompt["cliente"]["produtos_servicos"], "Pizzas artesanais e sobremesas")
        self.assertEqual(user_prompt["cliente"]["identidade_visual"]["cor_principal"], "#a12345")
        with app_module.app.app_context():
            db = app_module.get_db()
            plan = db.execute("SELECT * FROM content_calendar").fetchone()
            posts = db.execute("SELECT * FROM posts ORDER BY position").fetchall()
            usage = db.execute("SELECT * FROM ai_generations").fetchone()
            self.assertEqual(plan["period_days"], 7)
            self.assertEqual(plan["status"], "draft")
            self.assertEqual(len(posts), 2)
            self.assertEqual(posts[0]["hashtags"], "#pizzaria #feitoartesanalmente")
            self.assertEqual(usage["input_tokens"], 530)

    def test_editorial_calendar_edit_duplicate_delete_and_production(self):
        response, _ = self.generate_plan()
        plan_id = int(response.location.split("/planner/")[1].split("?")[0])
        response = self.client.get(f"/planner/{plan_id}")
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"Calend\xc3\xa1rio editorial", response.data)
        self.assertIn(b"Sabor da casa 1", response.data)
        with app_module.app.app_context():
            post_id = app_module.get_db().execute("SELECT id FROM posts ORDER BY id LIMIT 1").fetchone()["id"]
        edit_url = f"/planner/posts/{post_id}/edit"
        self.assertEqual(self.client.get(edit_url).status_code, 200)
        data = {
            "csrf_token": self.csrf(), "publication_date": "2026-11-09", "network": "Instagram",
            "theme": "Noite de pizza", "strategic_objective": "Conversão de pedidos",
            "content_type": "Reel", "main_text": "Massa fresca, forno quente.",
            "caption": "Uma noite especial merece pizza artesanal.", "hashtags": "#pizza #jantar",
            "cta": "Faça seu pedido", "visual_brief": "Visual em tons da identidade da marca.",
            "image_suggestion": "Pizza sendo finalizada no forno.",
        }
        response = self.client.post(edit_url, data=data)
        self.assertEqual(response.status_code, 302)
        response = self.client.post(f"/planner/posts/{post_id}/duplicate", data={"csrf_token": self.csrf()})
        self.assertEqual(response.status_code, 302)
        with app_module.app.app_context():
            db = app_module.get_db()
            duplicate = db.execute("SELECT id FROM posts WHERE id<>? ORDER BY id DESC LIMIT 1", (post_id,)).fetchone()["id"]
            self.assertEqual(db.execute("SELECT target_posts FROM content_calendar WHERE id=?", (plan_id,)).fetchone()[0], 3)
        response = self.client.post(f"/planner/posts/{duplicate}/delete", data={"csrf_token": self.csrf()})
        self.assertEqual(response.status_code, 302)
        response = self.client.post(f"/planner/{plan_id}/production", data={"csrf_token": self.csrf()})
        self.assertEqual(response.status_code, 302)
        with app_module.app.app_context():
            db = app_module.get_db()
            self.assertEqual(db.execute("SELECT status FROM content_calendar WHERE id=?", (plan_id,)).fetchone()[0], "in_production")
            self.assertEqual(db.execute("SELECT status FROM posts WHERE id=?", (post_id,)).fetchone()[0], "in_production")
            self.assertEqual(tuple(db.execute("SELECT publication_date,theme FROM posts WHERE id=?", (post_id,)).fetchone()), ("2026-11-09", "Noite de pizza"))

    def test_post_regeneration_calls_provider_and_records_usage(self):
        response, _ = self.generate_plan()
        plan_id = int(response.location.split("/planner/")[1].split("?")[0])
        with app_module.app.app_context():
            post_id = app_module.get_db().execute("SELECT id FROM posts WHERE calendar_id=? ORDER BY id LIMIT 1", (plan_id,)).fetchone()["id"]
        payload = self.ai_payload(count=1)
        payload["choices"][0]["message"]["content"] = json.dumps({"posts": [{**json.loads(payload["choices"][0]["message"]["content"])["posts"][0], "theme": "Nova criação para a marca"}]}, ensure_ascii=False)
        with patch.object(app_module.urllib.request, "urlopen", return_value=FakeResponse(payload)) as urlopen:
            response = self.client.post(f"/planner/posts/{post_id}/regenerate", data={"csrf_token": self.csrf()})
        self.assertEqual(response.status_code, 302)
        self.assertIn("regenerated=1", response.location)
        request_body = json.loads(urlopen.call_args.args[0].data)
        prompt = json.loads(request_body["messages"][1]["content"])
        self.assertEqual(prompt["planejamento"]["quantidade_publicacoes"], 1)
        with app_module.app.app_context():
            db = app_module.get_db()
            self.assertEqual(db.execute("SELECT theme FROM posts WHERE id=?", (post_id,)).fetchone()[0], "Nova criação para a marca")
            self.assertEqual(db.execute("SELECT COUNT(*) FROM ai_generations WHERE feature='post_regeneration'").fetchone()[0], 1)

    def test_missing_key_returns_clear_error_without_fake_plan(self):
        os.environ.pop("OPENAI_API_KEY", None)
        response = self.client.post("/planner/generate", data=self.form())
        self.assertEqual(response.status_code, 503)
        self.assertIn(b"OPENAI_API_KEY", response.data)
        with app_module.app.app_context():
            db = app_module.get_db()
            self.assertEqual(db.execute("SELECT COUNT(*) FROM content_calendar").fetchone()[0], 0)
            self.assertEqual(db.execute("SELECT COUNT(*) FROM posts").fetchone()[0], 0)

    def test_tenant_cannot_read_another_agencys_plan(self):
        response, _ = self.generate_plan()
        plan_id = int(response.location.split("/planner/")[1].split("?")[0])
        self.client.post("/logout", data={"csrf_token": self.csrf()})
        self.client.get("/register")
        csrf = self.csrf()
        self.client.post("/register", data={"csrf_token": csrf, "name": "Other", "email": "other-planner@example.com", "company": "Other Planner", "password": "StrongPass123"})
        self.assertEqual(self.client.get(f"/planner/{plan_id}").status_code, 404)


if __name__ == "__main__":
    unittest.main()
