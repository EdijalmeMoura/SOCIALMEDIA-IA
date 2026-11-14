import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class DotenvParsingTests(unittest.TestCase):
    """`.env` é o arquivo que o operador edita à mão no cPanel/VPS."""

    def setUp(self):
        import installer

        self.installer = installer

    def test_parses_plain_quoted_exported_and_commented_lines(self):
        valores = self.installer.parse_dotenv_text(
            "\n".join([
                "# comentário",
                "",
                "SECRET_KEY=chave_simples-123",
                'META_APP_SECRET="segredo entre aspas"',
                "export COOKIE_SECURE=1",
                "OPENAI_MODEL=gpt-4o-mini   # modelo barato",
                "linha sem igual",
                "=sem_chave",
                "9INVALIDO=x",
            ])
        )
        self.assertEqual(valores["SECRET_KEY"], "chave_simples-123")
        self.assertEqual(valores["META_APP_SECRET"], "segredo entre aspas")
        self.assertEqual(valores["COOKIE_SECURE"], "1")
        self.assertEqual(valores["OPENAI_MODEL"], "gpt-4o-mini")
        self.assertNotIn("=sem_chave", valores)
        self.assertNotIn("9INVALIDO", valores)
        self.assertNotIn("linha sem igual", valores)

    def test_accepts_any_supported_key_not_only_installer_keys(self):
        valores = self.installer.parse_dotenv_text(
            "SOCIALMEDIA_BASE_PATH=/pasta/app\nSOCIALMEDIA_AUTO_INSTALL=1\n"
            "PORT=5000\nSOCIALMEDIA_DATA=/data\n"
        )
        self.assertEqual(valores["SOCIALMEDIA_BASE_PATH"], "/pasta/app")
        self.assertEqual(valores["SOCIALMEDIA_AUTO_INSTALL"], "1")
        self.assertEqual(valores["PORT"], "5000")

    def test_environment_wins_over_the_file(self):
        with tempfile.TemporaryDirectory() as tmp:
            arquivo = Path(tmp) / ".env"
            arquivo.write_text("OPENAI_MODEL=do-arquivo\nCOOKIE_SECURE=0\n", encoding="utf-8")
            env = {"OPENAI_MODEL": "do-ambiente"}
            self.installer.load_dotenv_file(arquivo, env)
            self.assertEqual(env["OPENAI_MODEL"], "do-ambiente", "variável real deve vencer")
            self.assertEqual(env["COOKIE_SECURE"], "0", "chave ausente entra como fallback")

    def test_missing_or_unreadable_file_is_ignored(self):
        env = {}
        self.assertEqual(self.installer.load_dotenv_file("/caminho/inexistente/.env", env), {})
        self.assertEqual(env, {})


class DotenvBootstrapTests(unittest.TestCase):
    """O `.env` precisa valer no boot, ANTES de a configuração ser lida."""

    def run_app(self, dotenv_text, extra_env=None):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / ".env").write_text(dotenv_text, encoding="utf-8")
            env = os.environ.copy()
            env.update({
                "PYTHONPATH": str(ROOT),
                "SOCIALMEDIA_DATA": str(root / "dados"),
                "SOCIALMEDIA_DB": str(root / "dados" / "db.sqlite"),
                "SOCIALMEDIA_DOTENV": str(root / ".env"),
            })
            for chave in ("SOCIALMEDIA_AUTO_INSTALL", "SOCIALMEDIA_INSTALLER_ENV",
                          "SECRET_KEY", "COOKIE_SECURE", "SOCIALMEDIA_BASE_PATH",
                          "OPENAI_MODEL"):
                env.pop(chave, None)
            env.update(extra_env or {})
            script = (
                "import os, app\n"
                "print('base=' + app.SCRIPT_BASE)\n"
                "print('root=' + app.app.config['APPLICATION_ROOT'])\n"
                "print('secret=' + app.app.config['SECRET_KEY'][:10])\n"
                "print('secure=' + str(app.app.config['SESSION_COOKIE_SECURE']))\n"
                "print('model=' + os.environ.get('OPENAI_MODEL', ''))\n"
            )
            resultado = subprocess.run([sys.executable, "-c", script], cwd=ROOT, env=env,
                                       capture_output=True, text=True, timeout=180)
            self.assertEqual(resultado.returncode, 0, resultado.stderr[-1500:])
            return dict(linha.split("=", 1) for linha in resultado.stdout.strip().splitlines())

    def test_boot_reads_the_file(self):
        saida = self.run_app(
            "SOCIALMEDIA_BASE_PATH=/edijalmemoura/socialai\n"
            "SECRET_KEY=uma-chave-bem-comprida-do-arquivo-dotenv-123\n"
            "COOKIE_SECURE=1\n"
            "OPENAI_MODEL=gpt-4o-mini\n"
        )
        self.assertEqual(saida["base"], "/edijalmemoura/socialai")
        self.assertEqual(saida["root"], "/edijalmemoura/socialai")
        self.assertEqual(saida["secret"], "uma-chave-")
        self.assertEqual(saida["secure"], "True")
        self.assertEqual(saida["model"], "gpt-4o-mini")

    def test_real_environment_overrides_the_file(self):
        saida = self.run_app(
            "SECRET_KEY=chave-do-arquivo-que-nao-deve-vencer-000\nCOOKIE_SECURE=0\n",
            extra_env={"SECRET_KEY": "chave-do-ambiente-que-deve-vencer-999",
                       "SOCIALMEDIA_BASE_PATH": "/do/ambiente"},
        )
        self.assertEqual(saida["secret"], "chave-do-a", "o ambiente deve vencer o .env")
        self.assertEqual(saida["base"], "/do/ambiente")
        self.assertEqual(saida["secure"], "False")

    def test_absent_dotenv_keeps_the_app_working(self):
        """Sem `.env`, o comportamento anterior não muda (instalador no boot)."""
        saida = self.run_app("")
        self.assertEqual(saida["base"], "", "sem prefixo configurado, raiz normal")

        with tempfile.TemporaryDirectory() as tmp:
            dados = Path(tmp) / "dados"
            dados.mkdir()
            env = {**os.environ, "PYTHONPATH": str(ROOT), "SOCIALMEDIA_DATA": str(dados),
                   "SOCIALMEDIA_DB": str(dados / "db.sqlite"),
                   "SOCIALMEDIA_DOTENV": str(Path(tmp) / "nao-existe.env"),
                   "SOCIALMEDIA_AUTO_INSTALL": "0"}
            env.pop("SOCIALMEDIA_BASE_PATH", None)
            cliente = subprocess.run(
                [sys.executable, "-c",
                 "import app\n"
                 "c = app.app.test_client()\n"
                 "r = c.get('/')\n"
                 "print(r.status_code, r.headers.get('Location'))"],
                cwd=ROOT, capture_output=True, text=True, timeout=180, env=env,
            )
            self.assertEqual(cliente.returncode, 0, cliente.stderr[-1000:])
            self.assertIn("302 /install", cliente.stdout)


if __name__ == "__main__":
    unittest.main()
