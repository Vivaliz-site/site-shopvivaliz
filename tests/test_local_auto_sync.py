from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "local-auto-sync.ps1"
PWSH = shutil.which("pwsh") or shutil.which("powershell")


@unittest.skipUnless(PWSH, "PowerShell is required")
class LocalAutoSyncTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="shopvivaliz-sync-test-")
        root = Path(self.temp.name)
        self.remote = root / "remote.git"
        self.seed = root / "seed"
        self.work = root / "work"
        self.git("init", "--bare", str(self.remote), cwd=root)
        self.git("clone", str(self.remote), str(self.seed), cwd=root)
        self.git("config", "user.email", "sync-test@example.invalid", cwd=self.seed)
        self.git("config", "user.name", "Sync Test", cwd=self.seed)
        self.git("switch", "-c", "main", cwd=self.seed)
        (self.seed / "tracked.txt").write_text("initial\n", encoding="utf-8")
        self.git("add", "tracked.txt", cwd=self.seed)
        self.git("commit", "-m", "initial", cwd=self.seed)
        self.git("push", "-u", "origin", "main", cwd=self.seed)
        self.git("symbolic-ref", "HEAD", "refs/heads/main", cwd=self.remote)
        self.git("clone", str(self.remote), str(self.work), cwd=root)
        self.git("config", "user.email", "sync-test@example.invalid", cwd=self.work)
        self.git("config", "user.name", "Sync Test", cwd=self.work)

    def tearDown(self) -> None:
        self.temp.cleanup()

    def git(self, *args: str, cwd: Path) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            ["git", *args], cwd=cwd, text=True, capture_output=True, check=True
        )

    def run_sync(self) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [
                str(PWSH),
                "-NoProfile",
                "-ExecutionPolicy",
                "Bypass",
                "-File",
                str(SCRIPT),
                "-OneTime",
                "-RepositoryPath",
                str(self.work),
            ],
            text=True,
            capture_output=True,
            check=False,
        )

    def test_fast_forwards_clean_checkout(self) -> None:
        (self.seed / "tracked.txt").write_text("remote\n", encoding="utf-8")
        self.git("commit", "-am", "remote update", cwd=self.seed)
        self.git("push", cwd=self.seed)
        result = self.run_sync()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual((self.work / "tracked.txt").read_text(encoding="utf-8"), "remote\n")

    def test_blocks_dirty_checkout_without_committing(self) -> None:
        (self.work / "tracked.txt").write_text("dirty\n", encoding="utf-8")
        before = self.git("rev-parse", "HEAD", cwd=self.work).stdout.strip()
        result = self.run_sync()
        self.assertEqual(result.returncode, 3, result.stdout + result.stderr)
        self.assertEqual(self.git("rev-parse", "HEAD", cwd=self.work).stdout.strip(), before)

    def test_blocks_diverged_history(self) -> None:
        (self.work / "local.txt").write_text("local\n", encoding="utf-8")
        self.git("add", "local.txt", cwd=self.work)
        self.git("commit", "-m", "local update", cwd=self.work)
        (self.seed / "remote.txt").write_text("remote\n", encoding="utf-8")
        self.git("add", "remote.txt", cwd=self.seed)
        self.git("commit", "-m", "remote update", cwd=self.seed)
        self.git("push", cwd=self.seed)
        result = self.run_sync()
        self.assertEqual(result.returncode, 4, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
