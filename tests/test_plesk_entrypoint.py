import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class PleskEntrypointTests(unittest.TestCase):
    def run_entrypoint(self, **settings):
        env = os.environ.copy()
        for key in ("SECRET_KEY", "COOKIE_SECURE", "SOCIALMEDIA_DATA", "SOCIALMEDIA_DB"):
            env.pop(key, None)
        env.update(settings)
        return subprocess.run(
            [sys.executable, "-c", "import passenger_wsgi; assert callable(passenger_wsgi.application)"],
            cwd=ROOT, env=env, capture_output=True, text=True,
        )

    def test_requires_production_configuration(self):
        result = self.run_entrypoint()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("SECRET_KEY", result.stderr)
        result = self.run_entrypoint(SECRET_KEY="x" * 40, COOKIE_SECURE="0")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("COOKIE_SECURE", result.stderr)
        result = self.run_entrypoint(SECRET_KEY="x" * 40, COOKIE_SECURE="1", SOCIALMEDIA_DATA="relative")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("SOCIALMEDIA_DATA", result.stderr)

    def test_loads_application_with_valid_configuration(self):
        with tempfile.TemporaryDirectory() as data:
            result = self.run_entrypoint(SECRET_KEY="x" * 40, COOKIE_SECURE="1", SOCIALMEDIA_DATA=data)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertTrue((Path(data) / "socialmedia.db").exists())


if __name__ == "__main__":
    unittest.main()
