from __future__ import annotations

import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "configure-production-runtime.py"
KEYS = (
    "DB_HOST",
    "DB_PORT",
    "DB_NAME",
    "DB_USER",
    "DB_PASS",
    "SHOPVIVALIZ_AGENT_KEY",
    "MERCADOPAGO_ACCESS_TOKEN",
    "MERCADOPAGO_PUBLIC_KEY",
    "MERCADOPAGO_WEBHOOK_SECRET",
)


def payload(overrides: dict[str, str]) -> bytes:
    values = {
        "DB_HOST": "db.internal",
        "DB_PORT": "3306",
        "DB_NAME": "shopvivaliz",
        "DB_USER": "shop_runtime",
        "DB_PASS": "database-password",
        "SHOPVIVALIZ_AGENT_KEY": "a" * 40,
        "MERCADOPAGO_ACCESS_TOKEN": "mp-access",
        "MERCADOPAGO_PUBLIC_KEY": "mp-public",
        "MERCADOPAGO_WEBHOOK_SECRET": "mp-webhook",
    }
    values.update(overrides)
    return b"\0".join(values[key].encode() for key in KEYS) + b"\0"


def run_script(target: Path, data: bytes) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(
        [sys.executable, str(SCRIPT), str(target)],
        input=data,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


class ConfigureProductionRuntimeTests(unittest.TestCase):
    def test_rejects_root_without_modifying_file(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / ".env"
            original = "DB_USER=working_user\nDB_PASS=working-password\n"
            target.write_text(original, encoding="utf-8")

            result = run_script(target, payload({"DB_USER": "root"}))

            self.assertEqual(result.returncode, 2)
            self.assertIn(b"root database user is forbidden", result.stderr)
            self.assertEqual(target.read_text(encoding="utf-8"), original)
            self.assertEqual(list(root.glob(".env.backup.*")), [])

    def test_writes_static_tuple_and_preserves_managed_oauth(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / ".env"
            target.write_text(
                "EXISTING=value\n"
                "OLIST_ACCESS_TOKEN=active-access\n"
                "OLIST_REFRESH_TOKEN=active-refresh\n"
                "TINY_CLIENT_SECRET=active-client-secret\n",
                encoding="utf-8",
            )

            result = run_script(target, payload({}))

            self.assertEqual(result.returncode, 0, result.stderr.decode())
            updated = target.read_text(encoding="utf-8")
            self.assertIn("DB_USER=shop_runtime", updated)
            self.assertIn("DB_NAME=shopvivaliz", updated)
            self.assertIn("DB_PASS=database-password", updated)
            self.assertIn("OLIST_ACCESS_TOKEN=active-access", updated)
            self.assertIn("OLIST_REFRESH_TOKEN=active-refresh", updated)
            self.assertIn("TINY_CLIENT_SECRET=active-client-secret", updated)
            self.assertIn("database_user_safe=true", result.stdout.decode())
            self.assertIn("managed_oauth_mutation=blocked", result.stdout.decode())
            if os.name != "nt":
                self.assertEqual(target.stat().st_mode & 0o777, 0o600)
            self.assertEqual(len(list(root.glob(".env.backup.*"))), 1)

    def test_rejects_legacy_oauth_payload_without_modifying_file(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / ".env"
            original = "OLIST_REFRESH_TOKEN=active-refresh\n"
            target.write_text(original, encoding="utf-8")

            result = run_script(target, payload({}) + b"legacy-oauth-field\0")

            self.assertEqual(result.returncode, 2)
            self.assertIn(b"payload field count mismatch", result.stderr)
            self.assertEqual(target.read_text(encoding="utf-8"), original)


if __name__ == "__main__":
    unittest.main()
