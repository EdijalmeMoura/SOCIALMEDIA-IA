import base64
import io
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from PIL import Image

TEST_ROOT = tempfile.TemporaryDirectory()
# Sem .env real: a suíte pressupõe raiz "/" e ambiente limpo.
os.environ["SOCIALMEDIA_DOTENV"] = str(Path(TEST_ROOT.name) / "sem-dotenv.env")
os.environ.pop("SOCIALMEDIA_BASE_PATH", None)
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


class SocialMediaStageThreeTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        app_module.DB_PATH = Path(self.temp.name) / "socialmedia.db"
        app_module.UPLOAD_DIR = Path(self.temp.name) / "uploads"
        app_module.UPLOAD_DIR.mkdir()
        app_module.init_db()
        app_module.app.config.update(TESTING=True, SECRET_KEY="test-secret")
        self.client = app_module.app.test_client()
        self.client.get("/register")
        csrf = self.csrf()
        self.client.post("/register", data={"csrf_token": csrf, "name": "Studio Owner", "email": "studio@example.com", "company": "Studio Brand", "password": "StrongPass123"})
        self.client.post("/clients/new", data={
            "csrf_token": self.csrf(), "company_name": "Aurora Café", "trade_name": "Aurora",
            "segment": "Cafeteria", "description": "Café especial e confeitaria artesanal",
            "products_services": "Cafés filtrados e bolos artesanais", "audience": "Pessoas que apreciam café especial",
            "business_goals": "Aumentar o movimento da cafeteria",
        })
        with app_module.app.app_context():
            db = app_module.get_db()
            self.client_id = db.execute("SELECT id FROM clients").fetchone()["id"]
            db.execute("UPDATE client_branding SET primary_color='#263f86',secondary_color='#f47d53',typography='Manrope',visual_style='Editorial e artesanal',communication_tone='Acolhedor',slogan='Um bom café muda o dia',logo_path=NULL WHERE client_id=?", (self.client_id,))
            db.commit()

    def tearDown(self):
        os.environ.pop("OPENAI_API_KEY", None)
        self.temp.cleanup()

    def csrf(self):
        with self.client.session_transaction() as sess:
            return sess.get("csrf_token")

    @staticmethod
    def text_payload(title="Café passado na hora"):
        post = {"title": title, "subtitle": "Seu momento começa aqui", "caption": "Sinta o aroma do nosso café especial, preparado com cuidado. Visite a Aurora e aproveite uma pausa gostosa no seu dia.", "hashtags": ["#cafesespeciais", "#cafeteria"], "cta": "Passe para tomar um café", "visual_brief": "Composição editorial acolhedora, tons azul profundo e terracota, área segura para tipografia.", "image_suggestion": "Xícara de café coado em mesa de madeira clara, luz da manhã entrando pela janela.", "composition": "Xícara em destaque à direita e espaço negativo à esquerda para texto."}
        return {"choices": [{"message": {"content": json.dumps(post, ensure_ascii=False)}}], "usage": {"prompt_tokens": 220, "completion_tokens": 180}}

    def create_creative(self):
        os.environ["OPENAI_API_KEY"] = "unit-test-key"
        with patch.object(app_module.urllib.request, "urlopen", return_value=FakeResponse(self.text_payload())) as provider:
            response = self.client.post("/studio/generate", data={"csrf_token": self.csrf(), "client_id": str(self.client_id), "subject": "Café especial para começar o dia", "format_key": "square"})
        self.assertEqual(response.status_code, 302)
        creative_id = int(response.location.split("/studio/")[1].split("?")[0])
        return creative_id, provider

    @staticmethod
    def png_bytes(size=(120, 80), color=(160, 90, 40)):
        image = Image.new("RGB", size, color)
        buffer = io.BytesIO()
        image.save(buffer, "PNG")
        return buffer.getvalue()

    def test_studio_generator_uses_client_profile_and_builds_editable_format(self):
        creative_id, provider = self.create_creative()
        request = provider.call_args.args[0]
        spec = json.loads(json.loads(request.data)["messages"][1]["content"])
        self.assertEqual(request.full_url, "https://api.openai.com/v1/chat/completions")
        self.assertEqual(spec["cliente"]["produtos_servicos"], "Cafés filtrados e bolos artesanais")
        self.assertEqual(spec["cliente"]["identidade_visual"]["cores"][0], "#263f86")
        response = self.client.get(f"/studio/{creative_id}")
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"Editor de arte", response.data)
        with app_module.app.app_context():
            db = app_module.get_db()
            creative = db.execute("SELECT * FROM creative_posts WHERE id=?", (creative_id,)).fetchone()
            layout = json.loads(creative["layout_json"])
            self.assertEqual((creative["canvas_width"], creative["canvas_height"]), (1080, 1080))
            self.assertEqual(len(layout["elements"]), 4)
            self.assertEqual(layout["elements"][-1]["text"], "Aurora")
            self.assertEqual(db.execute("SELECT feature FROM ai_generations").fetchone()[0], "studio_copy")

    def test_image_generation_editor_save_and_png_jpg_export(self):
        creative_id, _ = self.create_creative()
        os.environ["OPENAI_API_KEY"] = "unit-test-key"
        image_response = {"data": [{"b64_json": base64.b64encode(self.png_bytes()).decode()}], "usage": {"input_tokens": 30, "output_tokens": 160}}
        with patch.object(app_module.urllib.request, "urlopen", return_value=FakeResponse(image_response)) as image_provider:
            response = self.client.post(f"/studio/{creative_id}/image", data={"csrf_token": self.csrf(), "image_prompt": "Xícara de café em ambiente aconchegante, luz da manhã."})
        self.assertEqual(response.status_code, 302)
        self.assertIn("image=generated", response.location)
        image_request = image_provider.call_args.args[0]
        self.assertEqual(image_request.full_url, "https://api.openai.com/v1/images/generations")
        self.assertEqual(json.loads(image_request.data)["model"], "gpt-image-1")
        with app_module.app.app_context():
            db = app_module.get_db()
            creative = db.execute("SELECT * FROM creative_posts WHERE id=?", (creative_id,)).fetchone()
            image_path = app_module.UPLOAD_DIR / creative["image_path"]
            with Image.open(image_path) as image:
                self.assertEqual(image.size, (1080, 1080))
            layout = json.loads(creative["layout_json"])
            layout["elements"][0]["x"] = 220
            content = json.loads(creative["content_json"])
        data = {"csrf_token": self.csrf(), "title": "Novo título editorial", "subtitle": content["subtitle"], "caption": content["caption"], "hashtags": content["hashtags"], "cta": content["cta"], "visual_brief": content["visual_brief"], "image_suggestion": content["image_suggestion"], "composition": content["composition"], "image_prompt": "Café especial em uma cafeteria", "background_color": "#123456", "layout_json": json.dumps(layout)}
        response = self.client.post(f"/studio/{creative_id}/save", data=data)
        self.assertEqual(response.status_code, 302)
        with app_module.app.app_context():
            db = app_module.get_db()
            creative = db.execute("SELECT * FROM creative_posts WHERE id=?", (creative_id,)).fetchone()
            self.assertEqual(json.loads(creative["content_json"])["title"], "Novo título editorial")
            self.assertEqual(json.loads(creative["layout_json"])["elements"][0]["x"], 220)
            self.assertEqual(creative["background_color"], "#123456")
        response = self.client.post(f"/studio/{creative_id}/export", data={"csrf_token": self.csrf(), "file": (io.BytesIO(self.png_bytes()), "design.png", "image/png")}, content_type="multipart/form-data")
        self.assertEqual(response.status_code, 200)
        exported = response.get_json()
        self.assertTrue(exported["download"].endswith("/download"))
        response = self.client.get(exported["download"])
        self.assertEqual(response.status_code, 200)
        with Image.open(io.BytesIO(response.data)) as image:
            self.assertEqual(image.size, (1080, 1080))
        response.close()
        with app_module.app.app_context():
            db = app_module.get_db()
            self.assertEqual(db.execute("SELECT COUNT(*) FROM post_versions WHERE creative_id=?", (creative_id,)).fetchone()[0], 4)
            self.assertEqual(db.execute("SELECT status FROM creative_posts WHERE id=?", (creative_id,)).fetchone()[0], "exported")
            first_version = db.execute("SELECT id FROM post_versions WHERE creative_id=? ORDER BY id LIMIT 1", (creative_id,)).fetchone()["id"]
        response = self.client.post(f"/studio/{creative_id}/versions/{first_version}/restore", data={"csrf_token": self.csrf()})
        self.assertEqual(response.status_code, 302)
        with app_module.app.app_context():
            db = app_module.get_db()
            restored = db.execute("SELECT content_json,status FROM creative_posts WHERE id=?", (creative_id,)).fetchone()
            self.assertEqual(json.loads(restored["content_json"])["title"], "Café passado na hora")
            self.assertEqual(restored["status"], "draft")
            self.assertEqual(db.execute("SELECT COUNT(*) FROM post_versions WHERE creative_id=?", (creative_id,)).fetchone()[0], 5)

    def test_create_text_variation_as_separate_editable_art(self):
        creative_id, _ = self.create_creative()
        payload = FakeResponse(self.text_payload("Uma pausa com café"))
        with patch.object(app_module.urllib.request, "urlopen", return_value=payload):
            response = self.client.post(f"/studio/{creative_id}/variation", data={"csrf_token": self.csrf(), "variation_note": "tom mais descontraído"})
        self.assertEqual(response.status_code, 302)
        new_id = int(response.location.split("/studio/")[1].split("?")[0])
        self.assertNotEqual(new_id, creative_id)
        with app_module.app.app_context():
            db = app_module.get_db()
            self.assertEqual(db.execute("SELECT COUNT(*) FROM creative_posts WHERE client_id=?", (self.client_id,)).fetchone()[0], 2)
            self.assertEqual(db.execute("SELECT feature FROM ai_generations ORDER BY id DESC LIMIT 1").fetchone()[0], "studio_variation")

    def test_studio_page_and_missing_key_do_not_fake_content(self):
        os.environ.pop("OPENAI_API_KEY", None)
        response = self.client.get("/studio")
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"APIs n\xc3\xa3o configuradas", response.data)
        response = self.client.post("/studio/generate", data={"csrf_token": self.csrf(), "client_id": str(self.client_id), "subject": "Café especial", "format_key": "story"})
        self.assertEqual(response.status_code, 503)
        with app_module.app.app_context():
            self.assertEqual(app_module.get_db().execute("SELECT COUNT(*) FROM creative_posts").fetchone()[0], 0)

    def test_uploading_replacement_image_normalizes_canvas_dimensions(self):
        creative_id, _ = self.create_creative()
        response = self.client.post(f"/studio/{creative_id}/image/upload", data={"csrf_token": self.csrf(), "file": (io.BytesIO(self.png_bytes()), "ref.png", "image/png")}, content_type="multipart/form-data")
        self.assertEqual(response.status_code, 302)
        with app_module.app.app_context():
            creative = app_module.get_db().execute("SELECT image_path FROM creative_posts WHERE id=?", (creative_id,)).fetchone()
            with Image.open(app_module.UPLOAD_DIR / creative["image_path"]) as image:
                self.assertEqual(image.size, (1080, 1080))

    def test_tenant_cannot_read_another_agencys_creative(self):
        creative_id, _ = self.create_creative()
        self.client.post("/logout", data={"csrf_token": self.csrf()})
        self.client.get("/register")
        self.client.post("/register", data={"csrf_token": self.csrf(), "name": "Other", "email": "other-studio@example.com", "company": "Other Agency", "password": "StrongPass123"})
        self.assertEqual(self.client.get(f"/studio/{creative_id}").status_code, 404)


if __name__ == "__main__":
    unittest.main()
