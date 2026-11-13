import os
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
ENTRYPOINT = ROOT / "docker-entrypoint.sh"
VALID_SECRET = "ci-only-secret-not-for-production-0123456789abc"


class ProductionEntrypointTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.data = Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def mark_installed(self, with_accounts=False):
        (self.data / "installer-state.json").write_text('{"installed_at": "2026-01-01T00:00:00Z"}')
        if with_accounts:
            import sqlite3
            connection = sqlite3.connect(self.data / "socialmedia.db")
            connection.execute("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)")
            connection.execute("INSERT INTO users(email) VALUES('owner@example.com')")
            connection.commit()
            connection.close()

    def run_entrypoint(self, secret_key, cookie_secure="1", data_dir=None, check=True):
        env = os.environ.copy()
        for key in ("SECRET_KEY", "SOCIALMEDIA_ENV_FILE", "SOCIALMEDIA_INSTALLER_ENV", "SOCIALMEDIA_DB"):
            env.pop(key, None)
        env["COOKIE_SECURE"] = cookie_secure
        env["PORT"] = "5000"
        env["SOCIALMEDIA_DATA"] = str(data_dir or self.data)
        if secret_key is not None:
            env["SECRET_KEY"] = secret_key
        command = ["sh", str(ENTRYPOINT)] + (["--check"] if check else [])
        return subprocess.run(command, cwd=ROOT, env=env, capture_output=True, text=True, timeout=20)

    def test_fails_closed_without_secure_cookie(self):
        missing_cookie = self.run_entrypoint(VALID_SECRET, cookie_secure="0")
        self.assertNotEqual(missing_cookie.returncode, 0)
        self.assertIn("COOKIE_SECURE=1 is required", missing_cookie.stderr)

    def test_fresh_instance_starts_browser_installer_mode(self):
        result = self.run_entrypoint(None)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("Browser installer mode", result.stderr)
        self.assertIn("/install", result.stderr)
        self.assertIn("Configuration OK", result.stdout)

    def test_short_or_development_secret_is_replaced_only_before_installation(self):
        for weak in ("short", "dev-only-change-this-secret-before-deploying"):
            result = self.run_entrypoint(weak)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("Browser installer mode", result.stderr)

    def test_installed_instance_requires_persistent_secret(self):
        self.mark_installed()
        result = self.run_entrypoint(None)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Set a persistent SECRET_KEY", result.stderr)
        self.assertNotIn("Browser installer mode", result.stderr)

    def test_existing_accounts_block_installer_mode_even_without_state(self):
        self.mark_installed(with_accounts=True)
        (self.data / "installer-state.json").unlink()
        result = self.run_entrypoint("short")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Set a persistent SECRET_KEY", result.stderr)

    def test_valid_secret_skips_installer_mode(self):
        result = self.run_entrypoint(VALID_SECRET)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("Browser installer mode", result.stderr)
        self.assertIn("Configuration OK", result.stdout)

    def test_invalid_port_still_fails(self):
        env = os.environ.copy()
        env.update(COOKIE_SECURE="1", PORT="abc", SECRET_KEY=VALID_SECRET, SOCIALMEDIA_DATA=str(self.data))
        result = subprocess.run(["sh", str(ENTRYPOINT), "--check"], cwd=ROOT, env=env,
                                capture_output=True, text=True, timeout=20)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("PORT must be a numeric TCP port", result.stderr)


if __name__ == "__main__":
    unittest.main()
