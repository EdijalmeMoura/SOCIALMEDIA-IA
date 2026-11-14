import os
import re
import sqlite3
import stat
import tempfile
import unittest
from pathlib import Path

TEST_ROOT = tempfile.TemporaryDirectory()
# A suíte pressupõe raiz "/": neutraliza um .env real na pasta do projeto
os.environ["SOCIALMEDIA_DOTENV"] = str(Path(TEST_ROOT.name) / "sem-dotenv.env")
os.environ.pop("SOCIALMEDIA_BASE_PATH", None)
os.environ["SOCIALMEDIA_DATA"] = TEST_ROOT.name
os.environ["SOCIALMEDIA_DB"] = str(Path(TEST_ROOT.name) / "bootstrap.db")
import app as app_module  # noqa: E402
import installer  # noqa: E402
from werkzeug.security import check_password_hash  # noqa: E402

CSRF_RE = re.compile(r'name="install_csrf" value="([^"]+)"')


class InstallerTestCase(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        app_module.DB_PATH = self.root / "socialmedia.db"
        app_module.DATA_DIR = self.root
        app_module.UPLOAD_DIR = self.root / "uploads"
        app_module.UPLOAD_DIR.mkdir()
        app_module.INSTALLER_ENV_PATH = self.root / installer.ENV_FILENAME
        app_module.init_db()
        app_module.app.config.update(TESTING=True, SECRET_KEY="test-secret-for-installer")
        installer._DRAFTS.clear()
        installer._DONE_TICKETS.clear()
        installer._ACCESS_ATTEMPTS.clear()
        os.environ.pop(installer.ACCESS_TOKEN_ENV, None)
        self.client = app_module.app.test_client()
        self.paths = app_module.installer_paths()

    def tearDown(self):
        self.temp.cleanup()

    # helpers -----------------------------------------------------------------
    def get_token(self, path="/install"):
        response = self.client.get(path)
        match = CSRF_RE.search(response.get_data(as_text=True))
        return (match.group(1) if match else None), response

    def form(self, **overrides):
        data = {
            "agency_name": "Estúdio Aurora",
            "admin_name": "Ana Souza",
            "admin_email": "ana@aurora.example",
            "admin_password": "AuroraForte2026",
            "admin_password_confirm": "AuroraForte2026",
            "public_origin": "https://app.aurora.example",
            "openai_api_key": "",
            "openai_model": "gpt-4o-mini",
            "openai_image_model": "gpt-image-1",
            "meta_app_id": "",
            "meta_app_secret": "",
            "meta_graph_version": "v23.0",
        }
        data.update(overrides)
        return data

    def validate(self, **overrides):
        token, _ = self.get_token()
        return self.client.post("/install/validate", data={**self.form(**overrides), "install_csrf": token})

    def draft_id_from(self, response):
        location = response.headers.get("Location", "")
        match = re.search(r"draft=([a-f0-9]+)", location)
        return match.group(1) if match else None

    def run_install(self, **overrides):
        validated = self.validate(**overrides)
        draft_id = self.draft_id_from(validated)
        review_token, _ = self.get_token(f"/install/review?draft={draft_id}")
        return self.client.post("/install/run", data={"install_csrf": review_token, "draft": draft_id})

    def users(self):
        connection = sqlite3.connect(self.paths.db_path)
        try:
            return connection.execute(
                "SELECT u.name,u.email,u.password_hash,u.role,c.name FROM users u JOIN companies c ON c.id=u.company_id"
            ).fetchall()
        finally:
            connection.close()

    # tests -------------------------------------------------------------------
    def test_fresh_instance_redirects_root_to_installer(self):
        response = self.client.get("/")
        self.assertEqual(response.status_code, 302)
        self.assertIn("/install", response.headers["Location"])

    def test_installer_page_lists_environment_checks(self):
        _, response = self.get_token()
        self.assertEqual(response.status_code, 200)
        page = response.get_data(as_text=True)
        self.assertIn("Ambiente detectado", page)
        self.assertIn("Armazenamento de dados", page)
        self.assertIn("Banco de dados", page)
        self.assertIn("Proteção do instalador", page)
        self.assertIn('name="install_csrf"', page)
        self.assertEqual(response.headers["X-Robots-Tag"], "noindex, nofollow")
        self.assertIn("no-store", response.headers["Cache-Control"])

    def test_validation_rejects_bad_input_without_writing_anything(self):
        _, response = self.get_token()
        response = self.validate(admin_email="invalido", admin_password="123", public_origin="ftp://x")
        self.assertEqual(response.status_code, 400)
        page = response.get_data(as_text=True)
        self.assertIn("e-mail válido", page)
        self.assertIn("pelo menos 8 caracteres", page)
        self.assertFalse(self.paths.env_path.exists())
        self.assertEqual(self.users(), [])

    def test_validation_requires_confirmation_and_blocks_weak_password(self):
        response = self.validate(admin_password_confirm="OutraSenha123")
        self.assertIn("confirmação da senha não confere", response.get_data(as_text=True))
        response = self.validate(admin_password="password123", admin_password_confirm="password123")
        self.assertIn("menos previsível", response.get_data(as_text=True))

    def test_csrf_required_for_validation(self):
        response = self.client.post("/install/validate", data=self.form())
        self.assertEqual(response.status_code, 400)
        self.assertIn("Sessão do instalador expirada", response.get_data(as_text=True))

    def test_review_masks_secrets_and_never_shows_values(self):
        response = self.validate(openai_api_key="sk-" + "a1" * 20,
                                 meta_app_id="123456789012345",
                                 meta_app_secret="segredo-meta-super-secreto")
        self.assertEqual(response.status_code, 302)
        draft_id = self.draft_id_from(response)
        self.assertTrue(draft_id)
        review = self.client.get(f"/install/review?draft={draft_id}")
        self.assertEqual(review.status_code, 200)
        page = review.get_data(as_text=True)
        self.assertNotIn("AuroraForte2026", page)
        self.assertNotIn("segredo-meta-super-secreto", page)
        self.assertNotIn("a1" * 20, page.replace(" ", ""))
        self.assertIn("https://app.aurora.example/integrations/meta/callback", page)
        self.assertIn("Criar instância agora", page)

    def test_full_installation_creates_owner_and_private_config(self):
        response = self.run_install()
        self.assertEqual(response.status_code, 302)
        self.assertIn("/install/done", response.headers["Location"])

        users = self.users()
        self.assertEqual(len(users), 1)
        name, email, password_hash, role, company = users[0]
        self.assertEqual((name, email, role, company), ("Ana Souza", "ana@aurora.example", "owner", "Estúdio Aurora"))
        self.assertTrue(check_password_hash(password_hash, "AuroraForte2026"))

        env_path = self.paths.env_path
        self.assertTrue(env_path.exists())
        self.assertEqual(stat.S_IMODE(env_path.stat().st_mode), 0o600)
        values = installer.load_env_file(env_path)
        self.assertGreaterEqual(len(values["SECRET_KEY"]), 32)
        self.assertEqual(values["COOKIE_SECURE"], "1")
        self.assertNotIn("META_REDIRECT_URI", values)
        self.assertIn("# Origem pública: https://app.aurora.example", env_path.read_text())
        self.assertNotIn("AuroraForte2026", env_path.read_text())

        state = installer.load_state(self.paths.state_path)
        self.assertEqual(state["owner_email"], "ana@aurora.example")
        self.assertEqual(stat.S_IMODE(self.paths.state_path.stat().st_mode), 0o600)

        # Runtime config picks up the generated secret immediately.
        self.assertEqual(app_module.app.config["SECRET_KEY"], values["SECRET_KEY"])
        self.assertTrue(app_module.app.config["SESSION_COOKIE_SECURE"])

    def test_generates_meta_encryption_key_only_when_meta_configured(self):
        self.run_install()
        self.assertNotIn("META_TOKEN_ENCRYPTION_KEY", installer.load_env_file(self.paths.env_path))

        self.setUp()
        self.run_install(meta_app_id="123456789012345", meta_app_secret="segredo-meta-super-secreto")
        values = installer.load_env_file(self.paths.env_path)
        self.assertEqual(values["META_APP_ID"], "123456789012345")
        self.assertEqual(values["META_REDIRECT_URI"],
                         "https://app.aurora.example/integrations/meta/callback")
        self.assertEqual(values["META_PUBLIC_BASE_URL"], "https://app.aurora.example")
        self.assertTrue(values["META_TOKEN_ENCRYPTION_KEY"])
        self.assertNotIn("META_TOKEN_ENCRYPTION_KEY", str(installer.load_state(self.paths.state_path)))

    def test_installer_closes_after_installation(self):
        self.run_install()
        closed = self.client.get("/install")
        self.assertEqual(closed.status_code, 410)
        page = closed.get_data(as_text=True)
        self.assertIn("já está instalada", page)
        self.assertNotIn("ana@aurora.example", page)  # owner e-mail stays masked
        self.assertIn("a•••@aurora.example", page)
        self.assertEqual(self.client.post("/install/validate", data=self.form()).status_code, 403)

        # A second installation is refused even with a valid draft flow.
        self.assertEqual(self.validate().status_code, 403)
        self.assertEqual(len(self.users()), 1)

        # Root now sends anonymous visitors to the login page.
        self.assertEqual(self.client.get("/").headers["Location"], "/login")

    def test_login_works_with_installed_account(self):
        self.run_install()
        self.client.get("/login")
        with self.client.session_transaction() as session:
            token = session.get("csrf_token")
        response = self.client.post("/login", data={"csrf_token": token,
                                                    "email": "ana@aurora.example",
                                                    "password": "AuroraForte2026"},
                                    follow_redirects=True)
        self.assertEqual(response.status_code, 200)
        self.assertIn("Visão do workspace".encode(), response.data)

    def test_dashboard_exposes_installation_status_to_owner(self):
        self.run_install()
        self.client.get("/login")
        with self.client.session_transaction() as session:
            token = session.get("csrf_token")
        dashboard = self.client.post("/login", data={"csrf_token": token,
                                                     "email": "ana@aurora.example",
                                                     "password": "AuroraForte2026"},
                                     follow_redirects=True)
        page = dashboard.get_data(as_text=True)
        self.assertIn("Instalação da instância", page)
        self.assertIn("O instalador pelo navegador está encerrado", page)
        self.assertNotIn(installer.load_env_file(self.paths.env_path)["SECRET_KEY"], page)

    def test_install_route_closed_when_account_exists_without_state(self):
        connection = sqlite3.connect(self.paths.db_path)
        connection.execute("INSERT INTO companies(name,slug) VALUES('Legado','legado')")
        connection.execute(
            "INSERT INTO users(company_id,name,email,password_hash,role) VALUES(1,'Old','old@example.com','x','owner')"
        )
        connection.commit()
        connection.close()
        response = self.client.get("/install")
        self.assertEqual(response.status_code, 410)
        self.assertIn("não há registro do instalador", response.get_data(as_text=True))
        self.assertEqual(self.client.get("/").headers["Location"], "/login")

    def test_env_file_is_not_written_before_confirmation(self):
        self.validate(openai_api_key="sk-" + "b" * 24, meta_app_id="123456789012345",
                      meta_app_secret="segredo-meta-super-secreto")
        self.assertFalse(self.paths.env_path.exists())
        self.assertIsNone(installer.load_state(self.paths.state_path))
        self.assertEqual(self.users(), [])

    def test_access_token_gates_every_installer_route(self):
        os.environ[installer.ACCESS_TOKEN_ENV] = "token-secreto-de-instalacao"
        try:
            self.assertEqual(self.client.get("/install").status_code, 403)
            self.assertEqual(self.client.get("/install?access_token=errado").status_code, 403)
            page = self.client.get("/install?access_token=token-secreto-de-instalacao")
            self.assertEqual(page.status_code, 200)
            self.assertIn("Ambiente detectado", page.get_data(as_text=True))
            self.assertIn("token de instalação exigido", page.get_data(as_text=True).lower())

            token, _ = self.get_token("/install?access_token=token-secreto-de-instalacao")
            denied = self.client.post("/install/validate", data=self.form(access_token="errado",
                                                                         install_csrf=token))
            self.assertEqual(denied.status_code, 403)
            self.assertFalse(self.paths.env_path.exists())
        finally:
            os.environ.pop(installer.ACCESS_TOKEN_ENV, None)

    def test_repeated_bad_tokens_are_throttled(self):
        os.environ[installer.ACCESS_TOKEN_ENV] = "token-secreto-de-instalacao"
        try:
            codes = [self.client.get("/install?access_token=errado").status_code for _ in range(11)]
            self.assertEqual(codes[:10], [403] * 10)
            self.assertEqual(codes[10], 429)
            # The correct token still works after the lockout window is reset.
            installer.reset_access_failures("127.0.0.1")
            self.assertEqual(self.client.get("/install?access_token=token-secreto-de-instalacao").status_code, 200)
        finally:
            os.environ.pop(installer.ACCESS_TOKEN_ENV, None)
            installer.reset_access_failures("127.0.0.1")

    def test_done_page_masks_nothing_and_requires_ticket(self):
        self.run_install()
        self.assertEqual(self.client.get("/install/done").status_code, 403)
        done = self.client.get("/install/done?ticket=" + next(iter(installer._DONE_TICKETS)))
        self.assertEqual(done.status_code, 200)
        page = done.get_data(as_text=True)
        self.assertIn("instância está protegida", page)
        self.assertIn("app.aurora.example", page)
        self.assertNotIn(installer.load_env_file(self.paths.env_path)["SECRET_KEY"], page)

    def test_env_file_values_win_only_as_fallback(self):
        values = {"SECRET_KEY": "x" * 40, "COOKIE_SECURE": "1"}
        installer.write_env_file(self.paths.env_path, values)
        env = {"SECRET_KEY": "explicit-host-value"}
        installer.apply_env_file(self.paths.env_path, env)
        self.assertEqual(env["SECRET_KEY"], "explicit-host-value")
        self.assertEqual(env["COOKIE_SECURE"], "1")

    def test_env_file_ignores_unknown_or_unsafe_lines(self):
        self.paths.env_path.write_text(
            "SECRET_KEY=ok-value\n"
            "FLASK_DEBUG=1\n"
            "OPENAI_API_KEY=bad value with spaces\n"
            "META_APP_ID=123456789\n"
            "EVIL=rm -rf /\n"
        )
        values = installer.load_env_file(self.paths.env_path)
        self.assertEqual(values, {"SECRET_KEY": "ok-value", "META_APP_ID": "123456789"})

    def test_transaction_failure_leaves_installer_available(self):
        original = installer.create_owner_account

        def explode(db_path, payload):
            raise sqlite3.OperationalError("disk I/O error")

        installer.create_owner_account = explode
        try:
            response = self.run_install()
        finally:
            installer.create_owner_account = original
        self.assertEqual(response.status_code, 500)
        self.assertFalse(self.paths.env_path.exists())
        self.assertEqual(self.client.get("/install").status_code, 200)

    def test_existing_secret_is_preserved_on_install(self):
        installer.write_env_file(self.paths.env_path, {"SECRET_KEY": "z" * 44, "OPENAI_MODEL": "gpt-4o"})
        response = self.validate(openai_model="")
        draft_id = self.draft_id_from(response)
        token, _ = self.get_token(f"/install/review?draft={draft_id}")
        self.client.post("/install/run", data={"install_csrf": token, "draft": draft_id})
        values = installer.load_env_file(self.paths.env_path)
        self.assertEqual(values["SECRET_KEY"], "z" * 44)
        self.assertEqual(values["OPENAI_MODEL"], "gpt-4o")

    def test_existing_env_file_survives_failed_account_creation(self):
        installer.write_env_file(self.paths.env_path, {"SECRET_KEY": "y" * 44})
        original = installer.create_owner_account

        def explode(db_path, payload):
            raise sqlite3.OperationalError("disk I/O error")

        installer.create_owner_account = explode
        try:
            response = self.run_install()
        finally:
            installer.create_owner_account = original
        self.assertEqual(response.status_code, 500)
        self.assertTrue(self.paths.env_path.exists())
        self.assertEqual(installer.load_env_file(self.paths.env_path)["SECRET_KEY"], "y" * 44)
        self.assertEqual(self.client.get("/install").status_code, 200)

    def test_concurrent_install_attempts_create_a_single_owner(self):
        import threading
        validated = self.validate()
        draft_id = self.draft_id_from(validated)
        payload = installer.load_draft(draft_id)
        self.assertIsNotNone(payload)
        results, errors = [], []

        def attempt():
            try:
                results.append(installer.execute_installation(payload, self.paths))
            except installer.InstallerError as exc:
                errors.append(str(exc))

        threads = [threading.Thread(target=attempt) for _ in range(3)]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join()
        self.assertEqual(len(results), 1)
        self.assertEqual(len(errors), 2)
        self.assertTrue(all("já está instalada" in error or "Já existe uma conta" in error for error in errors))
        self.assertEqual(len(self.users()), 1)

    def test_health_still_reports_ok_after_installation(self):
        self.run_install()
        health = self.client.get("/health")
        self.assertEqual(health.status_code, 200)
        self.assertEqual(health.get_json()["status"], "ok")


if __name__ == "__main__":
    unittest.main()
