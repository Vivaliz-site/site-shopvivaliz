from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "ci_performance_fetch.py"


class CiPerformanceFetchTest(unittest.TestCase):
    def setUp(self) -> None:
        if not SCRIPT.is_file():
            self.fail("scripts/ci_performance_fetch.py is missing")

    def _fake_gh(self, root: Path) -> Path:
        bindir = root / "bin"
        bindir.mkdir()
        gh = bindir / "gh"
        gh.write_text(
            """#!/usr/bin/env python3
import json
import os
import sys
from pathlib import Path

log = Path(os.environ["FAKE_GH_LOG"])
with log.open("a", encoding="utf-8") as handle:
    handle.write(" ".join(sys.argv[1:]) + "\\n")
mode = os.environ.get("FAKE_GH_MODE", "ok")
counter = Path(os.environ["FAKE_GH_COUNTER"])
n = int(counter.read_text() or "0") if counter.exists() else 0
n += 1
counter.write_text(str(n), encoding="utf-8")
if mode == "fail":
    print("fake gh failure", file=sys.stderr)
    raise SystemExit(7)
total = 1000 if mode == "saturated" else 1
run = {
    "id": n,
    "name": "Gate",
    "status": "completed",
    "conclusion": "success",
    "run_started_at": "2026-09-26T10:00:00Z",
    "updated_at": "2026-09-26T10:01:00Z",
}
print(json.dumps([{"total_count": total, "workflow_runs": [run]}]))
""",
            encoding="utf-8",
        )
        gh.chmod(0o755)
        return bindir

    def _run(self, mode: str = "ok", repository: str = "Vivaliz-site/site-shopvivaliz"):
        temp = tempfile.TemporaryDirectory()
        root = Path(temp.name)
        bindir = self._fake_gh(root)
        output = root / "runs.json"
        env = os.environ.copy()
        env.update(
            {
                "PATH": f"{bindir}:{env.get('PATH', '')}",
                "FAKE_GH_LOG": str(root / "calls.log"),
                "FAKE_GH_COUNTER": str(root / "counter.txt"),
                "FAKE_GH_MODE": mode,
            }
        )
        result = subprocess.run(
            [
                sys.executable,
                str(SCRIPT),
                "--repository",
                repository,
                "--output",
                str(output),
                "--window-hours",
                "24",
                "--slice-minutes",
                "60",
                "--end-time",
                "2026-09-26T12:00:00Z",
            ],
            env=env,
            text=True,
            capture_output=True,
        )
        return temp, root, output, result

    def test_fetches_24_non_overlapping_hour_slices(self) -> None:
        temp, root, output, result = self._run()
        self.addCleanup(temp.cleanup)
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        calls = (root / "calls.log").read_text(encoding="utf-8").splitlines()
        self.assertEqual(len(calls), 24)
        self.assertIn(
            "created=2026-09-25T12:00:00Z..2026-09-25T12:59:59Z",
            calls[0],
        )
        self.assertIn(
            "created=2026-09-26T11:00:00Z..2026-09-26T11:59:59Z",
            calls[-1],
        )
        payload = json.loads(output.read_text(encoding="utf-8"))
        self.assertEqual(len(payload), 24)
        self.assertEqual(sum(len(page["workflow_runs"]) for page in payload), 24)

    def test_saturated_slice_fails_closed_and_does_not_publish_output(self) -> None:
        temp, root, output, result = self._run(mode="saturated")
        self.addCleanup(temp.cleanup)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("saturated", (result.stderr + result.stdout).lower())
        self.assertFalse(output.exists())
        calls = (root / "calls.log").read_text(encoding="utf-8").splitlines()
        self.assertEqual(len(calls), 1)

    def test_gh_failure_fails_closed_and_does_not_publish_output(self) -> None:
        temp, root, output, result = self._run(mode="fail")
        self.addCleanup(temp.cleanup)
        self.assertEqual(result.returncode, 7)
        self.assertFalse(output.exists())

    def test_rejects_invalid_repository_before_calling_gh(self) -> None:
        temp, root, output, result = self._run(repository="bad;rm -rf /")
        self.addCleanup(temp.cleanup)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("repository", (result.stderr + result.stdout).lower())
        self.assertFalse((root / "calls.log").exists())
        self.assertFalse(output.exists())


if __name__ == "__main__":
    unittest.main()
