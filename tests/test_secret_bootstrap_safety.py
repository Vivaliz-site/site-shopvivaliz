#!/usr/bin/env python3
from __future__ import annotations

import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[1]
MATERIALIZER = REPO_ROOT / "scripts" / "sincronizar_secrets_github.py"
SHELL_WRAPPER = REPO_ROOT / "scripts" / "sincronizar_secrets_github.sh"
BOOTSTRAP = REPO_ROOT / "scripts" / "bootstrap.sh"
INSTALLER = REPO_ROOT / "scripts" / "install.sh"
SYSTEMD_SETUP = REPO_ROOT / "scripts" / "setup-auto-sync-linux.sh"


def clean_environment(**extra: str) -> dict[str, str]:
    env = {
        "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
        "HOME": os.environ.get("HOME", "/tmp"),
        "LANG": os.environ.get("LANG", "C.UTF-8"),
    }
    env.update(extra)
    return env


class SecretMaterializerSafetyTests(unittest.TestCase):
    def run_materializer(self, directory: Path, **extra_env: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            ["python3", str(MATERIALIZER)],
            cwd=directory,
            env=clean_environment(**extra_env),
            text=True,
            capture_output=True,
            check=False,
        )

    def test_existing_values_are_preserved_without_runtime_replacements(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            env_file = root / ".env.local"
            original = "DB_HOST=database.internal\nDB_PASS=sentinel-secret\n"
            env_file.write_text(original, encoding="utf-8")

            result = self.run_materializer(root)

            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(env_file.read_text(encoding="utf-8"), original)
            self.assertIn("valores existentes foram preservados", result.stdout)

    def test_only_explicit_non_empty_runtime_values_are_updated(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            env_file = root / ".env.local"
            env_file.write_text(
                "DB_HOST=old-host\nDB_PASS=sentinel-secret\nFTP_PASSWORD=keep-me\n",
                encoding="utf-8",
            )

            result = self.run_materializer(root, DB_HOST="new-host", DB_PASS="")
            content = env_file.read_text(encoding="utf-8")

            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("DB_HOST=new-host", content)
            self.assertIn("DB_PASS=sentinel-secret", content)
            self.assertIn("FTP_PASSWORD=keep-me", content)
            self.assertNotIn("(do GitHub)", content)

    def test_missing_file_and_missing_runtime_values_fail_without_creating_file(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)

            result = self.run_materializer(root)

            self.assertEqual(result.returncode, 2)
            self.assertFalse((root / ".env.local").exists())
            self.assertIn("nao podem ser recuperados", result.stderr)

    def test_placeholder_is_rejected_without_overwriting_existing_file(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            env_file = root / ".env.local"
            original = "DB_PASS=sentinel-secret\n"
            env_file.write_text(original, encoding="utf-8")

            result = self.run_materializer(root, DB_PASS="(do GitHub)")

            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(env_file.read_text(encoding="utf-8"), original)

    def test_shell_wrapper_uses_same_non_destructive_contract(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            env_file = root / ".env.local"
            env_file.write_text("DB_PASS=sentinel-secret\n", encoding="utf-8")

            result = subprocess.run(
                ["bash", str(SHELL_WRAPPER)],
                cwd=root,
                env=clean_environment(),
                text=True,
                capture_output=True,
                check=False,
            )

            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(env_file.read_text(encoding="utf-8"), "DB_PASS=sentinel-secret\n")


class BootstrapSafetyTests(unittest.TestCase):
    def make_fixture(self, validator_exit: int) -> Path:
        root = Path(tempfile.mkdtemp())
        scripts = root / "scripts"
        scripts.mkdir()
        shutil.copy2(BOOTSTRAP, scripts / "bootstrap.sh")
        shutil.copy2(MATERIALIZER, scripts / "sincronizar_secrets_github.py")
        (root / ".env.local").write_text("DB_PASS=sentinel-secret\n", encoding="utf-8")
        (scripts / "validar_secrets.py").write_text(
            "#!/usr/bin/env python3\n"
            "from pathlib import Path\n"
            "Path('validator-ran').write_text('yes')\n"
            f"raise SystemExit({validator_exit})\n",
            encoding="utf-8",
        )
        (scripts / "auto-sincronizar.sh").write_text(
            "#!/usr/bin/env bash\n"
            "printf started > auto-sync-started\n"
            "sleep 30\n",
            encoding="utf-8",
        )
        return root

    def test_validation_failure_stops_before_auto_sync_and_propagates_exit_code(self) -> None:
        root = self.make_fixture(7)
        self.addCleanup(shutil.rmtree, root, True)

        result = subprocess.run(
            ["bash", "scripts/bootstrap.sh", "--start-auto-sync"],
            cwd=root,
            env=clean_environment(),
            text=True,
            capture_output=True,
            check=False,
        )

        self.assertEqual(result.returncode, 7, result.stdout + result.stderr)
        self.assertTrue((root / "validator-ran").exists())
        self.assertFalse((root / "auto-sync-started").exists())
        self.assertNotIn("Bootstrap validado com sucesso", result.stdout)

    def test_installers_do_not_mask_secret_or_service_failures(self) -> None:
        for script in (INSTALLER, SYSTEMD_SETUP):
            content = script.read_text(encoding="utf-8")
            self.assertNotIn("validar_secrets.py || true", content)
            self.assertNotIn('sincronizar_secrets_github.sh" > /dev/null 2>&1 || true', content)
            self.assertIn("set -euo pipefail", content)

    def test_default_success_does_not_start_auto_sync(self) -> None:
        root = self.make_fixture(0)
        self.addCleanup(shutil.rmtree, root, True)

        result = subprocess.run(
            ["bash", "scripts/bootstrap.sh"],
            cwd=root,
            env=clean_environment(),
            text=True,
            capture_output=True,
            check=False,
        )

        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse((root / "auto-sync-started").exists())
        self.assertIn("Nao iniciado", result.stdout)
        self.assertIn("Bootstrap validado com sucesso", result.stdout)


if __name__ == "__main__":
    unittest.main()
