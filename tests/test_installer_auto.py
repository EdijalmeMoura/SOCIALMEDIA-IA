import os
import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest import mock

TEST_ROOT = tempfile.TemporaryDirectory()
# A suíte pressupõe raiz "/": neutraliza um .env real na pasta do projeto
os.environ["SOCIALMEDIA_DOTENV"] = str(Path(TEST_ROOT.name) / "sem-dotenv.env")
os.environ.pop("SOCIALMEDIA_BASE_PATH", None)
os.environ["SOCIALMEDIA_DATA"] = TEST_ROOT.name
os.environ["SOCIALMEDIA_DB"] = str(Path(TEST_ROOT.name) / "bootstrap.db")
import app as app_module  # noqa: E402
import installer  # noqa: E402
from werkzeug.security import check_password_hash  # noqa: E402

AUTO_ENV = {
    "SOCIALMEDIA_AUTO_INSTALL": "1",
    "SOCIALMEDIA_AGENCY_NAME": "Estúdio Automático",
    "SOCIALMEDIA_OWNER_NAME": "Ana Souza",
    "SOCIALMEDIA_OWNER_EMAIL": "ana@auto.example",
    "SOCIALMEDIA_OWNER_PASSWORD": "AutoForte2026",
    "SOCIALMEDIA_PUBLIC_ORIGIN": "https://auto.example",
}


class AutomaticInstallationTests(unittest.TestCase):
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
        self.client = app_module.app.test_client()
        self.paths = app_module.installer_paths()
        for key in [*installer.AUTO_INSTALL_KEYS, installer.AUTO_INSTALL_ENV]:
            os.environ.pop(key, None)

    def tearDown(self):
        self.temp.cleanup()
        for key in [*installer.AUTO_INSTALL_KEYS, installer.AUTO_INSTALL_ENV]:
            os.environ.pop(key, None)

    def users(self):
        connection = sqlite3.connect(self.paths.db_path)
        try:
            return connection.execute(
                "SELECT name,email,password_hash,role FROM users"
            ).fetchall()
        finally:
            connection.close()

    def apply_env(self, extra=None):
        os.environ.update({**(extra or {})})

    # engine ------------------------------------------------------------------
    def test_skipped_without_flag(self):
        self.apply_env(AUTO_ENV | {"SOCIALMEDIA_AUTO_INSTALL": "0"})
        status, record = installer.run_automatic_installation(self.paths)
        self.assertEqual((status, record), ("skipped", None))
        self.assertEqual(self.users(), [])

    def test_installs_from_environment_without_browser(self):
        self.apply_env(AUTO_ENV)
        status, record = installer.run_automatic_installation(self.paths)
        self.assertEqual(status, "installed")
        self.assertEqual(record["owner_email"], "ana@auto.example")
        self.assertEqual(record["public_origin"], "https://auto.example")

        users = self.users()
        self.assertEqual(len(users), 1)
        name, email, password_hash, role = users[0]
        self.assertEqual((name, email, role), ("Ana Souza", "ana@auto.example", "owner"))
        self.assertTrue(check_password_hash(password_hash, "AutoForte2026"))

        values = installer.load_env_file(self.paths.env_path)
        self.assertGreaterEqual(len(values["SECRET_KEY"]), 32)
        self.assertEqual(values["COOKIE_SECURE"], "1")  # https origin
        self.assertEqual(installer.load_state(self.paths.state_path)["agency_name"], "Estúdio Automático")

        # Plaintext password never reaches disk.
        self.assertNotIn("AutoForte2026", self.paths.env_path.read_text())
        self.assertNotIn("AutoForte2026", self.paths.state_path.read_text())
        self.assertFalse(installer.installer_needed(self.paths))

        # Idempotent: a second boot does nothing.
        status_again, record_again = installer.run_automatic_installation(self.paths)
        self.assertEqual(status_again, "already_installed")
        self.assertEqual(record_again["owner_email"], "ana@auto.example")
        self.assertEqual(len(self.users()), 1)

    def test_missing_variables_fail_without_creating_anything(self):
        self.apply_env(AUTO_ENV | {"SOCIALMEDIA_OWNER_PASSWORD": ""})
        with self.assertLogs("socialmedia.installer", level="ERROR") as logs:
            status, record = installer.run_automatic_installation(self.paths)
        self.assertEqual((status, record), ("failed", None))
        self.assertEqual(self.users(), [])
        self.assertFalse(self.paths.env_path.exists())
        self.assertIn("SOCIALMEDIA_OWNER_PASSWORD", logs.output[0])
        self.assertEqual(installer.auto_install_missing(), ["SOCIALMEDIA_OWNER_PASSWORD"])

    def test_weak_or_invalid_values_are_rejected(self):
        self.apply_env(AUTO_ENV | {"SOCIALMEDIA_OWNER_PASSWORD": "12345678"})
        self.assertEqual(installer.run_automatic_installation(self.paths)[0], "failed")
        self.apply_env(AUTO_ENV | {"SOCIALMEDIA_PUBLIC_ORIGIN": "http://auto.example"})
        self.assertEqual(installer.run_automatic_installation(self.paths)[0], "failed")
        self.assertEqual(self.users(), [])

    def test_configures_ai_and_meta_from_environment(self):
        self.apply_env(AUTO_ENV | {
            "OPENAI_API_KEY": "sk-" + "c1" * 16,
            "META_APP_ID": "123456789012345",
            "META_APP_SECRET": "segredo-meta-super-secreto",
            "META_GRAPH_VERSION": "v23.0",
        })
        status, _ = installer.run_automatic_installation(self.paths)
        self.assertEqual(status, "installed")
        values = installer.load_env_file(self.paths.env_path)
        self.assertEqual(values["OPENAI_API_KEY"], "sk-" + "c1" * 16)
        self.assertEqual(values["META_APP_SECRET"], "segredo-meta-super-secreto")
        self.assertEqual(values["META_REDIRECT_URI"], "https://auto.example/integrations/meta/callback")
        self.assertTrue(values["META_TOKEN_ENCRYPTION_KEY"])

    def test_runtime_config_callback_receives_generated_secret(self):
        self.apply_env(AUTO_ENV)
        received = {}
        installer.run_automatic_installation(self.paths, apply_runtime_config=lambda values: received.update(values))
        self.assertEqual(received["SECRET_KEY"], installer.load_env_file(self.paths.env_path)["SECRET_KEY"])

    def test_automatic_install_never_overrides_a_persistent_secret(self):
        installer.write_env_file(self.paths.env_path, {"SECRET_KEY": "k" * 44})
        self.apply_env(AUTO_ENV)
        installer.run_automatic_installation(self.paths)
        self.assertEqual(installer.load_env_file(self.paths.env_path)["SECRET_KEY"], "k" * 44)

    def test_failure_keeps_browser_installer_available(self):
        self.apply_env(AUTO_ENV)
        with mock.patch.object(installer, "create_owner_account", side_effect=sqlite3.OperationalError("io")):
            status, _ = installer.run_automatic_installation(self.paths)
        self.assertEqual(status, "failed")
        self.assertFalse(self.paths.env_path.exists())
        self.assertEqual(self.client.get("/install").status_code, 200)

    # one-click route ---------------------------------------------------------
    def test_one_click_route_installs_and_closes(self):
        self.apply_env(AUTO_ENV)
        response = self.client.get("/install?auto=1")
        self.assertEqual(response.status_code, 302)
        self.assertIn("/install/done", response.headers["Location"])
        self.assertEqual(len(self.users()), 1)
        self.assertEqual(self.client.get("/install").status_code, 410)
        self.assertEqual(self.client.get("/").headers["Location"], "/login")

    def test_one_click_route_reports_missing_variables(self):
        self.apply_env({"SOCIALMEDIA_AUTO_INSTALL": "1"})
        response = self.client.get("/install?auto=1")
        self.assertEqual(response.status_code, 409)
        page = response.get_data(as_text=True)
        self.assertIn("Faltam dados no servidor", page)
        self.assertIn("SOCIALMEDIA_OWNER_EMAIL", page)
        self.assertEqual(self.users(), [])

    def test_installer_page_offers_one_click_when_variables_are_ready(self):
        self.apply_env(AUTO_ENV)
        page = self.client.get("/install").get_data(as_text=True)
        self.assertIn("Instalação automática disponível", page)
        self.assertIn("/install?auto=1", page)

    def test_installer_page_hints_when_variables_are_incomplete(self):
        self.apply_env({"SOCIALMEDIA_AUTO_INSTALL": "1"})
        page = self.client.get("/install").get_data(as_text=True)
        self.assertIn("Instalação automática incompleta", page)
        self.assertNotIn("/install?auto=1", page)

    def test_one_click_requires_access_token_when_configured(self):
        self.apply_env(AUTO_ENV)
        os.environ[installer.ACCESS_TOKEN_ENV] = "token-de-instalacao"
        try:
            self.assertEqual(self.client.get("/install?auto=1").status_code, 403)
            ok = self.client.get("/install?auto=1&access_token=token-de-instalacao")
            self.assertEqual(ok.status_code, 302)
            self.assertEqual(len(self.users()), 1)
        finally:
            os.environ.pop(installer.ACCESS_TOKEN_ENV, None)

    # banner ------------------------------------------------------------------
    def test_banner_reports_each_state(self):
        self.assertEqual(installer.installation_banner(self.paths)["state"], "pending")
        self.apply_env(AUTO_ENV)
        installer.run_automatic_installation(self.paths)
        banner = installer.installation_banner(self.paths)
        self.assertEqual(banner["state"], "installed")
        self.assertIn("instalada em", banner["message"])

        fresh = tempfile.TemporaryDirectory()
        try:
            other = installer.InstallerPaths(data_dir=Path(fresh.name), db_path=self.paths.db_path,
                                             env_path=self.paths.env_path)
            self.assertEqual(installer.installation_banner(other)["state"], "external")
        finally:
            fresh.cleanup()


class AutomaticInstallationBootTests(unittest.TestCase):
    """The app must boot with a workspace already created from the environment."""

    def test_boot_creates_workspace_and_login_works(self):
        import subprocess
        import sys
        import json

        self.temp = tempfile.TemporaryDirectory()
        data = Path(self.temp.name)
        env = os.environ.copy()
        env.update(AUTO_ENV)
        # Sem .env real do projeto: o teste assume raiz "/" e segredos isolados.
        env["SOCIALMEDIA_DOTENV"] = str(data / "sem-dotenv.env")
        for key in ("SECRET_KEY", "SOCIALMEDIA_INSTALLER_ENV", "SOCIALMEDIA_ENV_FILE",
                    "SOCIALMEDIA_BASE_PATH"):
            env.pop(key, None)
        env.update({
            "SOCIALMEDIA_DATA": str(data),
            "SOCIALMEDIA_DB": str(data / "socialmedia.db"),
            "PYTHONPATH": str(Path(__file__).resolve().parents[1]),
        })
        script = (
            "import json, re\n"
            "import app\n"
            "from installer import load_env_file\n"
            "values = load_env_file(app.INSTALLER_ENV_PATH)\n"
            "client = app.app.test_client()\n"
            "root_before_login = client.get('/').headers.get('Location')\n"
            "page = client.get('/login').get_data(as_text=True)\n"
            "token = re.search(r'name=\"csrf_token\" value=\"([^\"]+)\"', page).group(1)\n"
            "login = client.post('/login', data={'csrf_token': token, 'email': 'ana@auto.example',"
            " 'password': 'AutoForte2026'}, follow_redirects=True)\n"
            "print(json.dumps({\n"
            "  'auto_status': app.AUTO_INSTALL_STATUS,\n"
            "  'install_closed': client.get('/install').status_code,\n"
            "  'login_ok': 'Visão do workspace' in login.get_data(as_text=True),\n"
            "  'root_before_login': root_before_login,\n"
            "  'secret_from_file': app.app.config['SECRET_KEY'] == values.get('SECRET_KEY'),\n"
            "  'banner_state': app.installation_banner(app.installer_paths())['state'],\n"
            "  'banner_html': 'auth-install-note' in page,\n"
            "}))\n"
        )
        result = subprocess.run([sys.executable, "-c", script], cwd=Path(__file__).resolve().parents[1],
                                env=env, capture_output=True, text=True, timeout=120)
        self.assertEqual(result.returncode, 0, result.stderr[-2000:])
        payload = json.loads(result.stdout.strip().splitlines()[-1])
        self.assertEqual(payload["auto_status"], "installed")
        self.assertEqual(payload["install_closed"], 410)
        self.assertEqual(payload["root_before_login"], "/login")
        self.assertTrue(payload["login_ok"])
        self.assertTrue(payload["secret_from_file"])
        self.assertEqual(payload["banner_state"], "installed")
        self.assertTrue(payload["banner_html"])
        self.temp.cleanup()


class EntrypointAutomaticInstallTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.data = Path(self.temp.name)
        self.root = Path(__file__).resolve().parents[1]

    def tearDown(self):
        self.temp.cleanup()

    def run_entrypoint(self, extra):
        import subprocess
        env = os.environ.copy()
        for key in [*installer.AUTO_INSTALL_KEYS, installer.AUTO_INSTALL_ENV, "SECRET_KEY",
                    "SOCIALMEDIA_INSTALLER_ENV", "SOCIALMEDIA_ENV_FILE"]:
            env.pop(key, None)
        # The ephemeral bootstrap secret is only minted for ISO-formatted dates;
        # the real container runs on python:3.12-slim. Give the test one.
        env.update({"COOKIE_SECURE": "1", "PORT": "5000", "SOCIALMEDIA_DATA": str(self.data),
                    "SOCIALMEDIA_DOTENV": str(self.data / "sem-dotenv.env"),
                    "SOCIALMEDIA_DB": str(self.data / "socialmedia.db"),
                    "SECRET_KEY": "bootstrap-test-secret-0123456789abcdef"})
        env.update(extra)
        return subprocess.run(["sh", str(self.root / "docker-entrypoint.sh"), "--check"],
                              cwd=self.root, env=env, capture_output=True, text=True, timeout=30)

    def test_complete_variables_enable_automatic_install(self):
        result = self.run_entrypoint(AUTO_ENV)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("Automatic installation enabled", result.stderr)
        self.assertIn("Configuration OK", result.stdout)

    def test_incomplete_variables_fail_fast(self):
        result = self.run_entrypoint({"SOCIALMEDIA_AUTO_INSTALL": "1", "SOCIALMEDIA_AGENCY_NAME": "X"})
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("SOCIALMEDIA_OWNER_PASSWORD", result.stderr)
        self.assertIn("SOCIALMEDIA_PUBLIC_ORIGIN", result.stderr)

    def test_installed_instance_skips_automatic_install(self):
        (self.data / "installer-state.json").write_text('{"installed_at": "2026-01-01T00:00:00Z"}')
        result = self.run_entrypoint(AUTO_ENV | {"SECRET_KEY": "p" * 44})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("already installed", result.stderr)
        self.assertNotIn("Browser installer mode", result.stderr)


if __name__ == "__main__":
    unittest.main()
