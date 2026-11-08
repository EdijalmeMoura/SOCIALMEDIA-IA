import os
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
ENTRYPOINT = ROOT / "docker-entrypoint.sh"


class ProductionEntrypointTests(unittest.TestCase):
    def run_entrypoint(self, secret_key, cookie_secure):
        env = os.environ.copy()
        env.pop("SECRET_KEY", None)
        env["COOKIE_SECURE"] = cookie_secure
        env["PORT"] = "5000"
        if secret_key is not None:
            env["SECRET_KEY"] = secret_key
        return subprocess.run(["sh", str(ENTRYPOINT)], cwd=ROOT, env=env, capture_output=True, text=True, timeout=5)

    def test_entrypoint_fails_closed_without_secure_cookie_and_persistent_secret(self):
        missing_cookie = self.run_entrypoint("a" * 48, "0")
        self.assertNotEqual(missing_cookie.returncode, 0)
        self.assertIn("COOKIE_SECURE=1 is required", missing_cookie.stderr)

        missing_secret = self.run_entrypoint(None, "1")
        self.assertNotEqual(missing_secret.returncode, 0)
        self.assertIn("Set a persistent SECRET_KEY", missing_secret.stderr)

        short_secret = self.run_entrypoint("short", "1")
        self.assertNotEqual(short_secret.returncode, 0)
        self.assertIn("at least 32 characters", short_secret.stderr)

        development_secret = self.run_entrypoint("dev-only-change-this-secret-before-deploying", "1")
        self.assertNotEqual(development_secret.returncode, 0)
        self.assertIn("Set a persistent SECRET_KEY", development_secret.stderr)


if __name__ == "__main__":
    unittest.main()
