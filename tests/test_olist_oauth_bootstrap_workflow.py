#!/usr/bin/env python3
from __future__ import annotations

import os
import subprocess
import tempfile
import unittest
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = REPO_ROOT / ".github" / "workflows" / "refresh-olist-token-2h.yml"
BOOTSTRAP = REPO_ROOT / "scripts" / "bootstrap-olist-oauth-runtime.py"
OAUTH_VALUES = {
    "OLIST_CLIENT_ID": "olist-client-id-sentinel",
    "OLIST_CLIENT_SECRET": "olist-client-secret-sentinel",
    "OLIST_ACCESS_TOKEN": "olist-access-sentinel",
    "OLIST_REFRESH_TOKEN": "olist-refresh-sentinel",
    "TINY_CLIENT_ID": "tiny-client-id-sentinel",
    "TINY_CLIENT_SECRET": "tiny-client-secret-sentinel",
    "TINY_ACCESS_TOKEN": "tiny-access-sentinel",
    "TINY_REFRESH_TOKEN": "tiny-refresh-sentinel",
}


class OlistOAuthBootstrapWorkflowTests(unittest.TestCase):
    def test_manual_refresh_can_seed_missing_runtime_from_production_secrets(self) -> None:
        text = WORKFLOW.read_text(encoding="utf-8")
        expected = {
            "OLIST_CLIENT_ID_VALUE": "${{ secrets.OLIST_CLIENT_ID }}",
            "OLIST_CLIENT_SECRET_VALUE": "${{ secrets.OLIST_CLIENT_SECRET }}",
            "OLIST_REFRESH_TOKEN_VALUE": "${{ secrets.OLIST_REFRESH_TOKEN }}",
            "TINY_CLIENT_ID_VALUE": "${{ secrets.TINY_CLIENT_ID }}",
            "TINY_CLIENT_SECRET_VALUE": "${{ secrets.TINY_CLIENT_SECRET }}",
            "TINY_REFRESH_TOKEN_VALUE": "${{ secrets.TINY_REFRESH_TOKEN }}",
        }
        for name, expression in expected.items():
            self.assertIn(f"{name}: {expression}", text)

        self.assertIn("bootstrap-olist-oauth-runtime.py", text)
        self.assertNotIn("OLIST_ACCESS_TOKEN_VALUE:", text)
        self.assertNotIn("TINY_ACCESS_TOKEN_VALUE:", text)

    def test_bootstrap_is_fail_closed_and_cleans_temporary_seed(self) -> None:
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("set -Eeuo pipefail", text)
        self.assertIn("oauth-bootstrap.seed", text)
        self.assertIn("if: always()", text)
        self.assertIn("Remove OAuth bootstrap seed", text)


class OlistOAuthBootstrapScriptTests(unittest.TestCase):
    def run_bootstrap(self, target: Path, seed: Path) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            ["python3", str(BOOTSTRAP), str(target), str(seed)],
            cwd=REPO_ROOT,
            env={"PATH": os.environ.get("PATH", "/usr/bin:/bin")},
            text=True,
            capture_output=True,
            check=False,
        )

    def write_seed(self, path: Path, values: dict[str, str] | None = None) -> None:
        values = OAUTH_VALUES if values is None else values
        path.write_text("".join(f"{key}={value}\n" for key, value in values.items()), encoding="utf-8")

    def test_merge_preserves_unrelated_keys_and_adds_complete_oauth_tuple(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            target = root / ".env"
            seed = root / "oauth-bootstrap.seed"
            target.write_text("DB_HOST=db.internal\nSHOPVIVALIZ_AGENT_KEY=keep-me\n", encoding="utf-8")
            target.chmod(0o600)
            self.write_seed(seed)

            result = self.run_bootstrap(target, seed)
            content = target.read_text(encoding="utf-8")

            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertIn("DB_HOST=db.internal", content)
            self.assertIn("SHOPVIVALIZ_AGENT_KEY=keep-me", content)
            for key, value in OAUTH_VALUES.items():
                self.assertIn(f"{key}={value}", content)
            self.assertEqual(target.stat().st_mode & 0o777, 0o600)
            self.assertIn("oauth_runtime_seed_merged=true", result.stdout)

    def test_missing_required_oauth_key_fails_without_mutating_target(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            target = root / ".env"
            seed = root / "oauth-bootstrap.seed"
            original = "DB_HOST=db.internal\n"
            target.write_text(original, encoding="utf-8")
            values = dict(OAUTH_VALUES)
            values.pop("TINY_REFRESH_TOKEN")
            self.write_seed(seed, values)

            result = self.run_bootstrap(target, seed)

            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(target.read_text(encoding="utf-8"), original)
            self.assertIn("missing_oauth_keys", result.stderr)

    def test_logs_never_expose_oauth_values(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            target = root / ".env"
            seed = root / "oauth-bootstrap.seed"
            target.write_text("DB_HOST=db.internal\n", encoding="utf-8")
            self.write_seed(seed)

            result = self.run_bootstrap(target, seed)
            combined = result.stdout + result.stderr

            self.assertEqual(result.returncode, 0, combined)
            for value in OAUTH_VALUES.values():
                self.assertNotIn(value, combined)


if __name__ == "__main__":
    unittest.main()
