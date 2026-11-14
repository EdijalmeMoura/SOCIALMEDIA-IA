import os
import re
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

TEST_ROOT = tempfile.TemporaryDirectory()
os.environ["SOCIALMEDIA_DATA"] = TEST_ROOT.name
os.environ["SOCIALMEDIA_DB"] = str(Path(TEST_ROOT.name) / "bootstrap.db")
os.environ.pop("SOCIALMEDIA_AUTO_INSTALL", None)
os.environ.pop("SOCIALMEDIA_BASE_PATH", None)
import app as app_module  # noqa: E402
import installer  # noqa: E402

PREFIX = "/edijalmemoura/socialai"


class SubpathDeploymentTests(unittest.TestCase):
    """App published inside a folder (cPanel): every URL must carry the prefix."""

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        app_module.DB_PATH = self.root / "socialmedia.db"
        app_module.DATA_DIR = self.root
        app_module.UPLOAD_DIR = self.root / "uploads"
        app_module.UPLOAD_DIR.mkdir()
        app_module.INSTALLER_ENV_PATH = self.root / "installer.env"
        app_module.init_db()
        app_module.app.config.update(TESTING=True, SECRET_KEY="test-secret-subpath",
                                     APPLICATION_ROOT=PREFIX)
        installer._DRAFTS.clear()
        installer._DONE_TICKETS.clear()
        installer._ACCESS_ATTEMPTS.clear()
        self.client = app_module.app.test_client()
        self.paths = app_module.installer_paths()

    def tearDown(self):
        self.temp.cleanup()

    @staticmethod
    def mounted(path="/", prefix=PREFIX):
        """Request seen *by the app*: the web server already stripped the prefix.

        SCRIPT_NAME is the mount point the proxy announces (Passenger) and
        PATH_INFO is what it forwards (``/``, ``/install`` …). ``overrides``
        keeps Flask's test client from concatenating the two into the path.
        """
        return {"environ_overrides": {"SCRIPT_NAME": prefix, "PATH_INFO": path}}

    def install_token(self, path="/install"):
        page = self.client.get(path, **self.mounted(path))
        self.assertEqual(page.status_code, 200, page.get_data(as_text=True)[:500])
        return page.get_data(as_text=True).split('name="install_csrf" value="')[1].split('"')[0]

    def form(self, **overrides):
        data = {
            "agency_name": "Agência Pasta",
            "admin_name": "Ana Souza",
            "admin_email": "ana@pasta.example",
            "admin_password": "PastaForte2026",
            "admin_password_confirm": "PastaForte2026",
            "public_origin": f"https://projetos.example{PREFIX}",
            "openai_model": "gpt-4o-mini",
            "openai_image_model": "gpt-image-1",
            "meta_graph_version": "v23.0",
        }
        data.update(overrides)
        return data

    def test_root_redirects_into_installer_with_the_prefix(self):
        response = self.client.get("/", **self.mounted())
        self.assertEqual(response.status_code, 302)
        self.assertEqual(response.headers["Location"], f"{PREFIX}/install")

    def test_installer_page_uses_prefixed_links_and_form_action(self):
        page = self.client.get("/install", **self.mounted("/install"))
        self.assertEqual(page.status_code, 200)
        html = page.get_data(as_text=True)
        self.assertIn(f'href="{PREFIX}/static/app.css"', html)
        self.assertIn(f'href="{PREFIX}/static/install.css"', html)
        self.assertIn(f'action="{PREFIX}/install/validate"', html)
        self.assertIn("Ambiente detectado", html)

    def test_script_name_root_keeps_the_app_routable(self):
        """Passenger announces SCRIPT_NAME and forwards PATH_INFO="/"."""
        response = self.client.get("/", environ_base={"SCRIPT_NAME": PREFIX})
        self.assertEqual(response.status_code, 302)
        self.assertTrue(response.headers["Location"].startswith(f"{PREFIX}/install"),
                        response.headers["Location"])

    def test_empty_path_info_is_normalised_to_the_root_route(self):
        """Some servers forward PATH_INFO="" for the root page; it must still work."""
        response = self.client.get("/", **self.mounted(""))
        self.assertEqual(response.status_code, 302)
        self.assertEqual(response.headers["Location"], f"{PREFIX}/install")

    def test_full_installation_flow_inside_a_subdirectory(self):
        token = self.install_token()

        validated = self.client.post("/install/validate", data={**self.form(), "install_csrf": token},
                                     **self.mounted("/install/validate"))
        self.assertEqual(validated.status_code, 302)
        location = validated.headers["Location"]
        self.assertTrue(location.startswith(f"{PREFIX}/install/review"), location)
        draft_id = location.split("draft=")[1]

        review = self.client.get("/install/review", query_string={"draft": draft_id},
                                 **self.mounted("/install/review"))
        self.assertEqual(review.status_code, 200)
        review_html = review.get_data(as_text=True)
        self.assertIn(f'action="{PREFIX}/install/run"', review_html)
        token2 = review_html.split('name="install_csrf" value="')[1].split('"')[0]

        run = self.client.post("/install/run", data={"install_csrf": token2, "draft": draft_id},
                               **self.mounted("/install/run"))
        self.assertEqual(run.status_code, 302)
        self.assertTrue(run.headers["Location"].startswith(f"{PREFIX}/install/done"), run.headers["Location"])

        # Public origin keeps the subdirectory, so Meta callbacks stay correct.
        values = installer.load_env_file(self.paths.env_path)
        state = installer.load_state(self.paths.state_path)
        self.assertEqual(state["public_origin"], f"https://projetos.example{PREFIX}")

        connection = sqlite3.connect(self.paths.db_path)
        try:
            self.assertEqual(connection.execute("SELECT COUNT(*) FROM users").fetchone()[0], 1)
        finally:
            connection.close()
        self.assertEqual(self.client.get("/install", **self.mounted("/install")).status_code, 410)

    def test_login_and_dashboard_work_under_the_prefix(self):
        # Create the owner directly through the installer engine.
        payload, errors = installer.build_installation(
            self.form(), existing_env={}, origin=f"https://projetos.example{PREFIX}",
            secure_request=True,
        )
        self.assertEqual(errors, [])
        installer.execute_installation(payload, self.paths)

        login_page = self.client.get("/login", **self.mounted("/login"))
        self.assertEqual(login_page.status_code, 200)
        token = login_page.get_data(as_text=True).split('name="csrf_token" value="')[1].split('"')[0]
        logged = self.client.post("/login",
                                  data={"csrf_token": token, "email": "ana@pasta.example", "password": "PastaForte2026"},
                                  **self.mounted("/login"))
        self.assertEqual(logged.status_code, 302)
        self.assertTrue(logged.headers["Location"].startswith(f"{PREFIX}/dashboard"),
                        logged.headers["Location"])

        dashboard = self.client.get("/dashboard", **self.mounted("/dashboard"))
        self.assertEqual(dashboard.status_code, 200)
        self.assertIn("Visão do workspace", dashboard.get_data(as_text=True))
        self.assertIn(f'href="{PREFIX}/clients"', dashboard.get_data(as_text=True))

    def test_public_origin_accepts_and_validates_subdirectories(self):
        self.assertEqual(installer.validate_public_origin("https://host.example/pasta/"),
                         "https://host.example/pasta")
        self.assertEqual(installer.validate_public_origin("https://host.example"),
                         "https://host.example")
        for invalid in ("https://host.example/a/../b", "https://host.example/?x=1", "ftp://host/p",
                        "https://user:pass@host/p"):
            with self.assertRaises(installer.InstallerError):
                installer.validate_public_origin(invalid)
        with self.assertRaises(installer.InstallerError):
            installer.validate_public_origin("http://host.example/pasta")

    def test_meta_settings_keep_the_subdirectory_callback(self):
        payload, errors = installer.build_installation(
            self.form(meta_app_id="123456789012345", meta_app_secret="segredo-meta-1234567890"),
            existing_env={}, origin=f"https://projetos.example{PREFIX}", secure_request=True,
        )
        self.assertEqual(errors, [])
        env_values = installer.build_env_values(payload, {})
        self.assertEqual(env_values["META_REDIRECT_URI"],
                         f"https://projetos.example{PREFIX}/integrations/meta/callback")
        self.assertEqual(env_values["META_PUBLIC_BASE_URL"], f"https://projetos.example{PREFIX}")

    def test_meta_public_base_url_accepts_a_subdirectory(self):
        with mock.patch.dict(os.environ, {"META_PUBLIC_BASE_URL": f"https://projetos.example{PREFIX}"}):
            self.assertEqual(app_module.meta_public_base_url(), f"https://projetos.example{PREFIX}")
        with mock.patch.dict(os.environ, {"META_PUBLIC_BASE_URL": "https://projetos.example/a/../b"}):
            with self.assertRaises(app_module.MetaIntegrationError):
                app_module.meta_public_base_url()

    def test_environment_check_reports_the_configured_subdirectory(self):
        checks = installer.environment_checks(self.paths, secure_request=True,
                                              env={"SOCIALMEDIA_BASE_PATH": PREFIX})
        labels = {check["label"]: check for check in checks}
        self.assertIn("Subpasta da aplicação", labels)
        self.assertIn(PREFIX, labels["Subpasta da aplicação"]["detail"])

    def test_mount_fix_only_normalises_the_empty_root(self):
        captured = {}

        def app_stub(environ, start_response):
            captured.update(environ)
            start_response("200 OK", [])
            return [b"ok"]

        middleware = app_module.MountedPathFix(app_stub)
        list(middleware({"SCRIPT_NAME": PREFIX, "PATH_INFO": ""}, lambda *a: None))
        self.assertEqual(captured["PATH_INFO"], "/")
        captured.clear()
        list(middleware({"SCRIPT_NAME": PREFIX, "PATH_INFO": "/dashboard"}, lambda *a: None))
        self.assertEqual(captured["PATH_INFO"], "/dashboard")
        captured.clear()
        list(middleware({"SCRIPT_NAME": "", "PATH_INFO": ""}, lambda *a: None))
        self.assertEqual(captured["PATH_INFO"], "")

    def test_configured_base_path_is_exposed_as_application_root(self):
        """`SOCIALMEDIA_BASE_PATH` must reach APPLICATION_ROOT (cookies, url_for)."""
        root = Path(__file__).resolve().parents[1]
        data = tempfile.mkdtemp()
        env = os.environ.copy()
        env.update({"SOCIALMEDIA_DATA": data, "SOCIALMEDIA_DB": str(Path(data) / "db.sqlite"),
                    "SOCIALMEDIA_BASE_PATH": PREFIX, "PYTHONPATH": str(root)})
        env.pop("SOCIALMEDIA_AUTO_INSTALL", None)
        script = (
            "import app\n"
            "print('root=' + app.app.config['APPLICATION_ROOT'])\n"
            "c = app.app.test_client()\n"
            "r = c.get('/', environ_overrides={'SCRIPT_NAME': %r, 'PATH_INFO': '/'},\n"
            "          headers={'X-Forwarded-Prefix': %r})\n"
            "print('status=%%d location=%%s' %% (r.status_code, r.headers.get('Location')))\n"
            % (PREFIX, PREFIX)
        )
        result = subprocess.run([sys.executable, "-c", script], cwd=root, env=env,
                                capture_output=True, text=True, timeout=120)
        self.assertEqual(result.returncode, 0, result.stderr[-1500:])
        self.assertIn(f"root={PREFIX}", result.stdout)
        self.assertIn(f"location={PREFIX}/install", result.stdout)


class CgiEntryPointTests(unittest.TestCase):
    """`deploy/cpanel/app.cgi` precisa reconstruir o prefixo antes de chamar a app.

    Em CGI o servidor manda `SCRIPT_NAME` terminando em `/app.cgi` e, conforme a
    hospedagem, `PATH_INFO` vazio (rewrite) ou o caminho depois do executável.
    Errar isso significa 404 ou links com `/app.cgi/` no meio.
    """

    CGI = Path(__file__).resolve().parents[1] / "deploy/cpanel/app.cgi"

    def run_cgi(self, script_name, request_uri, path_info):
        data = tempfile.mkdtemp()
        env = {
            "PATH": "/usr/bin:/bin:/usr/local/bin",
            "PYTHONPATH": str(self.CGI.parents[2]),
            "SOCIALMEDIA_DATA": data,
            "SOCIALMEDIA_DB": str(Path(data) / "db.sqlite"),
            "SOCIALMEDIA_AUTO_INSTALL": "0",
            "REQUEST_METHOD": "GET",
            "QUERY_STRING": "",
            "SCRIPT_NAME": script_name,
            "REQUEST_URI": request_uri,
            "PATH_INFO": path_info,
            "SERVER_NAME": "projetoredesnordeste.com.br",
            "SERVER_PORT": "443",
            "SERVER_PROTOCOL": "HTTP/1.1",
            "HTTPS": "on",
            "HTTP_HOST": "projetoredesnordeste.com.br",
            "GATEWAY_INTERFACE": "CGI/1.1",
        }
        result = subprocess.run([sys.executable, str(self.CGI)], env=env,
                                capture_output=True, text=True, timeout=180)
        self.assertEqual(result.returncode, 0, result.stderr[-1500:])
        headers = {}
        for line in result.stdout.split("\r\n\r\n")[0].splitlines():
            if ":" in line:
                key, value = line.split(":", 1)
                headers[key.strip().lower()] = value.strip()
        return headers, result.stdout

    def test_root_without_path_info_redirects_into_the_installer(self):
        headers, _ = self.run_cgi(f"{PREFIX}/app.cgi", f"{PREFIX}/", "")
        self.assertEqual(headers.get("status"), "302 FOUND")
        self.assertEqual(headers.get("location"), f"{PREFIX}/install")

    def test_path_info_after_the_executable_is_kept(self):
        headers, body = self.run_cgi(f"{PREFIX}/app.cgi", f"{PREFIX}/install", "/install")
        self.assertEqual(headers.get("status"), "200 OK")
        self.assertIn("Ambiente detectado", body)

    def test_rewrite_putting_the_path_in_script_name_is_understood(self):
        """Algum Apache entrega `SCRIPT_NAME=/pasta/app.cgi/install`, sem PATH_INFO."""
        headers, body = self.run_cgi(f"{PREFIX}/app.cgi/install", f"{PREFIX}/app.cgi/install", "")
        self.assertEqual(headers.get("status"), "200 OK", body[:400])
        self.assertIn(f"{PREFIX}/static/app.css", body)

    def test_static_asset_from_request_uri(self):
        headers, body = self.run_cgi(f"{PREFIX}/app.cgi", f"{PREFIX}/static/app.css", "")
        self.assertEqual(headers.get("status"), "200 OK")
        self.assertIn("text/css", headers.get("content-type", ""))

    def test_htaccess_does_not_deny_the_entry_point(self):
        """O erro do anexo era 403: o .htaccess não pode bloquear o próprio CGI."""
        htaccess = (self.CGI.parent / ".htaccess").read_text()
        deny_blocks = re.findall(
            r'<FilesMatch\s+"([^"]+)"\s*>(.*?)</FilesMatch>', htaccess, re.MULTILINE | re.DOTALL
        )
        self.assertTrue(deny_blocks, "o .htaccess deveria bloquear código/dados privados")
        for pattern, body in deny_blocks:
            if "denied" in body:
                self.assertNotRegex("app.cgi", pattern, f"regra bloqueia o entry point: {pattern}")
                self.assertNotRegex("static/app.css", pattern, "regra bloquearia assets")
        self.assertIn("DirectoryIndex app.cgi", htaccess)
        self.assertIn("RewriteRule ^(.*)$ app.cgi/$1", htaccess)
        self.assertIn("+ExecCGI", htaccess)


if __name__ == "__main__":
    unittest.main()
