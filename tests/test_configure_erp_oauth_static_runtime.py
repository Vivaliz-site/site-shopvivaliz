from __future__ import annotations

import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "configure-erp-oauth-static-runtime.py"
KEYS = (
    "OLIST_CLIENT_ID",
    "OLIST_CLIENT_SECRET",
    "TINY_CLIENT_ID",
    "TINY_CLIENT_SECRET",
)


def payload(overrides: dict[str, str] | None = None) -> bytes:
    values = {
        "OLIST_CLIENT_ID": "olist-client-id",
        "OLIST_CLIENT_SECRET": "olist-client-secret",
        "TINY_CLIENT_ID": "tiny-client-id",
        "TINY_CLIENT_SECRET": "tiny-client-secret",
    }
    values.update(overrides or {})
    return b"\0".join(values[key].encode() for key in KEYS) + b"\0"


def run_script(target: Path, data: bytes) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(
        [sys.executable, str(SCRIPT), str(target)],
        input=data,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


class ConfigureErpOauthStaticRuntimeTests(unittest.TestCase):
    def test_restores_static_clients_without_changing_rotating_tokens(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / ".env"
            legacy_key = "TOKEN_" + "API_OLIST"
            target.write_text(
                "UNCHANGED=value\n"
                "OLIST_ACCESS_TOKEN=active-access\n"
                "OLIST_REFRESH_TOKEN=active-refresh\n"
                "TINY_ACCESS_TOKEN=tiny-active\n"
                "TINY_REFRESH_TOKEN=tiny-refresh\n"
                f"{legacy_key}=legacy-live\n",
                encoding="utf-8",
            )
            if os.name != "nt":
                os.chmod(target, 0o640)

            result = run_script(target, payload())

            self.assertEqual(result.returncode, 0, result.stderr.decode())
            updated = target.read_text(encoding="utf-8")
            self.assertIn("OLIST_CLIENT_ID=olist-client-id", updated)
            self.assertIn("OLIST_CLIENT_SECRET=olist-client-secret", updated)
            self.assertIn("TINY_CLIENT_ID=tiny-client-id", updated)
            self.assertIn("TINY_CLIENT_SECRET=tiny-client-secret", updated)
            self.assertIn("OLIST_ACCESS_TOKEN=active-access", updated)
            self.assertIn("OLIST_REFRESH_TOKEN=active-refresh", updated)
            self.assertIn("TINY_ACCESS_TOKEN=tiny-active", updated)
            self.assertIn("TINY_REFRESH_TOKEN=tiny-refresh", updated)
            self.assertNotIn(f"{legacy_key}=", updated)
            self.assertIn("UNCHANGED=value", updated)
            self.assertIn("protected_rotating_tokens_preserved=true", result.stdout.decode())
            self.assertEqual(len(list(root.glob(".env.erp-oauth-backup.*"))), 1)
            if os.name != "nt":
                self.assertEqual(target.stat().st_mode & 0o777, 0o640)

    def test_accepts_short_opaque_provider_secret(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / ".env"
            target.write_text("OLIST_REFRESH_TOKEN=active-refresh\n", encoding="utf-8")

            result = run_script(target, payload({"OLIST_CLIENT_SECRET": "a1b2c3"}))

            self.assertEqual(result.returncode, 0, result.stderr.decode())
            updated = target.read_text(encoding="utf-8")
            self.assertIn("OLIST_CLIENT_SECRET=a1b2c3", updated)
            self.assertIn("OLIST_REFRESH_TOKEN=active-refresh", updated)

    def test_rejects_incomplete_olist_pair_without_modifying_file(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / ".env"
            original = "OLIST_REFRESH_TOKEN=active-refresh\n"
            target.write_text(original, encoding="utf-8")

            result = run_script(target, payload({"OLIST_CLIENT_SECRET": ""}))

            self.assertEqual(result.returncode, 2)
            self.assertIn(b"complete Olist client credential pair is required", result.stderr)
            self.assertEqual(target.read_text(encoding="utf-8"), original)
            self.assertEqual(list(target.parent.glob(".env.erp-oauth-backup.*")), [])

    def test_rejects_partial_tiny_pair(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / ".env"
            target.write_text("UNCHANGED=value\n", encoding="utf-8")

            result = run_script(target, payload({"TINY_CLIENT_SECRET": ""}))

            self.assertEqual(result.returncode, 2)
            self.assertIn(b"Tiny client credentials must be supplied as a complete pair", result.stderr)

    def test_rejects_placeholder_secret(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / ".env"
            original = "UNCHANGED=value\n"
            target.write_text(original, encoding="utf-8")

            result = run_script(target, payload({"OLIST_CLIENT_SECRET": "replace_me_secret"}))

            self.assertEqual(result.returncode, 2)
            self.assertIn(b"placeholder value refused", result.stderr)
            self.assertEqual(target.read_text(encoding="utf-8"), original)

    def test_rejects_extra_token_payload_field(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / ".env"
            original = "OLIST_REFRESH_TOKEN=active-refresh\n"
            target.write_text(original, encoding="utf-8")

            result = run_script(target, payload() + b"must-not-write-token\0")

            self.assertEqual(result.returncode, 2)
            self.assertIn(b"payload field count mismatch", result.stderr)
            self.assertEqual(target.read_text(encoding="utf-8"), original)


if __name__ == "__main__":
    unittest.main()
