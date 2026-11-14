import os
import sqlite3
import tempfile
import unittest
from pathlib import Path

TEST_ROOT = tempfile.TemporaryDirectory()
# Sem .env real: a suíte pressupõe raiz "/" e ambiente limpo.
os.environ["SOCIALMEDIA_DOTENV"] = str(Path(TEST_ROOT.name) / "sem-dotenv.env")
os.environ.pop("SOCIALMEDIA_BASE_PATH", None)
os.environ["SOCIALMEDIA_DATA"] = TEST_ROOT.name
os.environ["SOCIALMEDIA_DB"] = str(Path(TEST_ROOT.name) / "bootstrap.db")
os.environ.pop("SOCIALMEDIA_AUTO_INSTALL", None)
import app as app_module  # noqa: E402
import component_map  # noqa: E402


class ComponentMapDocumentTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        root = Path(cls.temp.name)
        cls.app_module, cls.app_temp = component_map.load_app_module(root)
        cls.document = component_map.build_document(cls.app_module, generated_at="2026-01-01 00:00 UTC")

    @classmethod
    def tearDownClass(cls):
        cls.app_temp.cleanup()
        cls.temp.cleanup()

    def test_document_contains_every_section(self):
        for heading in [
            "# Componentes do SocialMedia IA",
            "## Visão geral",
            "## Arquitetura em uma tela",
            "## Componentes de código",
            "## Rotas HTTP",
            "## Banco de dados",
            "## Templates e interface",
            "## Variáveis de ambiente",
            "## Dependências Python",
            "## Arquivos e diretórios em runtime",
            "## Caminhos de instalação",
            "## Componentes de execução (Docker e operação)",
            "## Qualidade e testes",
            "## Integrações externas (opcionais)",
            "## Checklist de instalação (mínimo para funcionar)",
        ]:
            self.assertIn(heading, self.document)

    def test_routes_and_tables_come_from_the_real_app(self):
        routes = component_map.route_rows(self.app_module)
        self.assertGreaterEqual(len(routes), 70)
        rules = {row["rule"] for row in routes}
        for expected in ("/install", "/install/run", "/portal/approvals", "/integrations/meta",
                         "/schedule", "/reports", "/health"):
            self.assertIn(expected, rules)
        # The document lists them verbatim.
        self.assertIn("| `/install/validate` |", self.document)

        tables = {row["name"] for row in component_map.table_rows(self.app_module)}
        self.assertIn("companies", tables)
        self.assertIn("meta_page_connections", tables)
        self.assertGreaterEqual(len(tables), 20)
        self.assertIn("| `scheduled_publications` |", self.document)

    def test_documents_all_environment_variables(self):
        for key in ("SOCIALMEDIA_AUTO_INSTALL", "SOCIALMEDIA_OWNER_PASSWORD", "SOCIALMEDIA_INSTALL_TOKEN",
                    "SOCIALMEDIA_DATA", "SECRET_KEY", "COOKIE_SECURE", "OPENAI_API_KEY",
                    "META_TOKEN_ENCRYPTION_KEY", "META_PUBLIC_BASE_URL"):
            self.assertIn(f"`{key}`", self.document)

    def test_never_contains_secret_values(self):
        os.environ["OPENAI_API_KEY"] = "sk-" + "z" * 30
        os.environ["META_APP_SECRET"] = "segredo-meta-nao-vaze"
        try:
            document = component_map.build_document(self.app_module)
        finally:
            os.environ.pop("OPENAI_API_KEY", None)
            os.environ.pop("META_APP_SECRET", None)
        self.assertNotIn("z" * 30, document)
        self.assertNotIn("segredo-meta-nao-vaze", document)

    def test_generator_writes_file_and_stdout_mode(self):
        output = Path(self.temp.name) / "COMPONENTES.md"
        self.assertEqual(component_map.main(["--output", str(output)]), 0)
        self.assertTrue(output.exists())
        self.assertIn("# Componentes do SocialMedia IA", output.read_text())


class ComponentMapRouteTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        app_module.DB_PATH = Path(self.temp.name) / "socialmedia.db"
        app_module.DATA_DIR = Path(self.temp.name)
        app_module.INSTALLER_ENV_PATH = Path(self.temp.name) / "installer.env"
        app_module.init_db()
        app_module.app.config.update(TESTING=True, SECRET_KEY="test-secret")
        self.client = app_module.app.test_client()
        connection = sqlite3.connect(app_module.DB_PATH)
        connection.execute("INSERT INTO companies(name,slug) VALUES('Agência','agencia')")
        from werkzeug.security import generate_password_hash
        connection.execute(
            "INSERT INTO users(company_id,name,email,password_hash,role) VALUES(1,'Owner','owner@example.com',?,'owner')",
            (generate_password_hash("OwnerPass123"),),
        )
        connection.commit()
        connection.close()

    def tearDown(self):
        self.temp.cleanup()

    def login(self, email="owner@example.com", password="OwnerPass123"):
        self.client.get("/login")
        with self.client.session_transaction() as session:
            token = session.get("csrf_token")
        return self.client.post("/login", data={"csrf_token": token, "email": email, "password": password},
                                follow_redirects=True)

    def test_route_renders_for_owner_and_protects_download(self):
        self.assertEqual(self.client.get("/admin/componentes").status_code, 302)  # anônimo -> login
        self.login()
        page = self.client.get("/admin/componentes")
        self.assertEqual(page.status_code, 200)
        html = page.get_data(as_text=True)
        self.assertIn("Componentes do sistema", html)
        self.assertIn("Rotas HTTP", html)
        self.assertIn("SOCIALMEDIA_AUTO_INSTALL", html)
        self.assertEqual(page.headers["Cache-Control"], "private, no-store")

        download = self.client.get("/admin/componentes.md")
        self.assertEqual(download.status_code, 200)
        self.assertTrue(download.headers["Content-Type"].startswith("text/markdown"))
        self.assertIn("COMPONENTES.md", download.headers["Content-Disposition"])
        self.assertIn("# Componentes do SocialMedia IA", download.get_data(as_text=True))

    def test_route_denies_viewer_role(self):
        connection = sqlite3.connect(app_module.DB_PATH)
        from werkzeug.security import generate_password_hash
        connection.execute(
            "INSERT INTO users(company_id,name,email,password_hash,role) VALUES(1,'Viewer','viewer@example.com',?,'viewer')",
            (generate_password_hash("ViewerPass123"),),
        )
        connection.commit()
        connection.close()
        self.login("viewer@example.com", "ViewerPass123")
        self.assertEqual(self.client.get("/admin/componentes").status_code, 403)
        self.assertEqual(self.client.get("/admin/componentes.md").status_code, 403)

    def test_navigation_link_visible_for_owner(self):
        self.login()
        dashboard = self.client.get("/dashboard").get_data(as_text=True)
        self.assertIn("Componentes do sistema", dashboard)

    def test_live_document_reflects_running_app_without_writing_data_dir(self):
        self.login()
        before = set(os.listdir(self.temp.name))
        self.client.get("/admin/componentes")
        after = set(os.listdir(self.temp.name))
        self.assertEqual(before, after)  # nothing extra persisted


if __name__ == "__main__":
    unittest.main()
