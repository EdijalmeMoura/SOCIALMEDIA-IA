import os
import subprocess
import tempfile
import unittest
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[1]
BACKUP_SCRIPT = REPO_ROOT / "scripts" / "backup-data.sh"


class BackupScriptTests(unittest.TestCase):
    def run_backup(self, fail=False):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name)
        bin_dir = root / "bin"
        bin_dir.mkdir()
        log = root / "docker.log"
        fake_docker = bin_dir / "docker"
        fake_docker.write_text(
            "#!/usr/bin/env python3\n"
            "import os, pathlib, sys\n"
            "args = sys.argv[1:]\n"
            "with open(os.environ['DOCKER_LOG'], 'a') as stream: stream.write(' '.join(args) + '\\n')\n"
            "if args[:4] == ['compose', '-f', 'compose.yaml', 'ps']:\n"
            "    print('running-container')\n"
            "elif args and args[0] == 'run':\n"
            "    if os.environ.get('DOCKER_FAIL') == '1': sys.exit(9)\n"
            "    mount = next(value for value in args if value.startswith('type=bind,source='))\n"
            "    destination = mount.split('source=', 1)[1].split(',target=', 1)[0]\n"
            "    archive = next(value for value in args if value.endswith('.tar.gz'))\n"
            "    pathlib.Path(destination, pathlib.Path(archive).name).write_bytes(b'backup')\n"
        )
        fake_docker.chmod(0o755)
        backup_dir = root / "backups"
        env = os.environ.copy()
        env.update({"PATH": f"{bin_dir}:{env['PATH']}", "DOCKER_LOG": str(log)})
        if fail:
            env["DOCKER_FAIL"] = "1"
        completed = subprocess.run(
            ["sh", str(BACKUP_SCRIPT), str(backup_dir)],
            cwd=REPO_ROOT,
            env=env,
            capture_output=True,
            text=True,
            check=False,
        )
        calls = log.read_text().splitlines()
        return temporary, completed, backup_dir, calls

    def test_backup_stops_service_copies_volume_and_restarts_running_service(self):
        temporary, result, backup_dir, calls = self.run_backup()
        with temporary:
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("Backup created:", result.stdout)
            self.assertTrue(next(backup_dir.glob("*.tar.gz")).is_file())
            stop_index = next(index for index, call in enumerate(calls) if " stop web" in call)
            run_index = next(index for index, call in enumerate(calls) if call.startswith("run "))
            start_index = next(index for index, call in enumerate(calls) if " start web" in call)
            self.assertLess(stop_index, run_index)
            self.assertLess(run_index, start_index)

    def test_backup_restarts_service_when_archive_command_fails(self):
        temporary, result, _backup_dir, calls = self.run_backup(fail=True)
        with temporary:
            self.assertNotEqual(result.returncode, 0)
            self.assertTrue(any(" stop web" in call for call in calls))
            self.assertTrue(any(" start web" in call for call in calls))


if __name__ == "__main__":
    unittest.main()
