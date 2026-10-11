"""Regression tests: do not re-request existing ShopVivaliz ChatGPT passwords."""
from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "scripts/chatgpt_credential_preflight.py"
SPEC = importlib.util.spec_from_file_location("credential_preflight", SOURCE)
assert SPEC and SPEC.loader
probe = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(probe)


class CredentialPreflightTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.profile = Path(self.temp.name) / "corporate-chrome"
        self.keyring = Path(self.temp.name) / "missing-bus"

    def add_saved_login(self, account: str, *, path: str = "Profile 1/Login Data",
                        origin: str = "https://auth.openai.com/log-in/password"):
        database = self.profile / path
        database.parent.mkdir(parents=True, exist_ok=True)
        with sqlite3.connect(database) as db:
            db.execute(
                "CREATE TABLE IF NOT EXISTS logins "
                "(origin_url TEXT, signon_realm TEXT, username_value TEXT, password_value BLOB)"
            )
            db.execute(
                "INSERT INTO logins VALUES (?, ?, ?, ?)",
                (origin, ("https://auth.openai.com/" if origin.startswith("https://auth.openai.com/") else "https://unrelated.invalid/"), account, b"FAKE_SECRET_FIXTURE")
            )

    def inspect(self, session="atendimento"):
        return probe.inspect(session, profile_root=self.profile,
                             keyring_socket=self.keyring)

    def test_existing_atendimento_credential_is_reused_without_disclosure(self):
        self.add_saved_login("atendimento@shopvivaliz.com.br")
        result = self.inspect()
        self.assertEqual(result["saved_entry"], "PRESENT")
        self.assertEqual(result["password_request_gate"], "DENY_EXISTING_CREDENTIAL")
        self.assertEqual(result["required_next_step"],
                         "REUSE_APPROVED_BROWSER_CREDENTIAL_FLOW")
        self.assertFalse(result["may_request_password"])
        self.assertFalse(result["session_authenticated"])
        self.assertFalse(result["password_or_token_accessed"])
        self.assertTrue(result["no_secret_exposure"])
        self.assertNotIn("FAKE_SECRET_FIXTURE", json.dumps(result))
        self.assertNotIn("password_value", json.dumps(result))

    def test_dev_profile_never_reuses_atendimento_credential(self):
        self.add_saved_login("atendimento@shopvivaliz.com.br",
                             path="Default/Login Data")
        result = self.inspect("dev")
        self.assertEqual(result["saved_entry"], "NOT_VERIFIED")
        self.assertEqual(result["password_request_gate"], "DENY_ABSENCE_NOT_PROVEN")
        self.assertFalse(result["may_request_password"])

    def test_missing_profile_is_unknown_not_proof_of_absence(self):
        result = self.inspect()
        self.assertEqual(result["saved_entry"], "NOT_VERIFIED")
        self.assertEqual(result["required_next_step"],
                         "CHECK_REMAINING_AUTHORIZED_SOURCES")
        self.assertFalse(result["may_request_password"])

    def test_unavailable_database_does_not_trigger_reprompt(self):
        db = self.profile / "Profile 1/Login Data"
        db.parent.mkdir(parents=True)
        db.write_bytes(b"not a sqlite file")
        result = self.inspect()
        self.assertEqual(result["saved_entry"], "NOT_VERIFIED")
        self.assertFalse(result["may_request_password"])

    def test_fallback_can_prove_presence_but_not_absence(self):
        self.add_saved_login("atendimento@shopvivaliz.com.br")
        original_connect = probe.sqlite3.connect
        seen = []

        def busy_on_first(database, **kwargs):
            seen.append(database)
            if "mode=ro" in database:
                raise sqlite3.OperationalError("database is locked")
            return original_connect(database, **kwargs)

        with mock.patch.object(probe.sqlite3, "connect", side_effect=busy_on_first):
            result = self.inspect()
        self.assertEqual(result["saved_entry"], "PRESENT")
        self.assertTrue(any("immutable=1" in uri for uri in seen))

    def test_wrong_origin_is_not_matched(self):
        self.add_saved_login("atendimento@shopvivaliz.com.br",
                             origin="https://auth.openai.com.example.invalid/")
        result = self.inspect()
        self.assertEqual(result["saved_entry"], "NOT_VERIFIED")
        self.assertFalse(result["may_request_password"])

    def test_script_sql_only_reads_non_secret_metadata(self):
        self.assertIn("SELECT 1 FROM logins", probe.SAVED_LOGIN_EXISTS_SQL)
        self.assertNotIn("password_value", probe.SAVED_LOGIN_EXISTS_SQL)
        self.assertNotIn("encrypted", probe.SAVED_LOGIN_EXISTS_SQL)
        with self.assertRaises(ValueError):
            probe.inspect("personal-account")

    def test_agent_bootstrap_has_mandatory_gate(self):
        for path in ("AGENTS.md", "CLAUDE.md",
                     "docs/knowledge/atendimento-chatgpt-bootstrap.md",
                     "docs/knowledge/chatgpt-vm-auth.md"):
            body = (ROOT / path).read_text(encoding="utf-8")
            self.assertIn("CREDENTIAL_REPROMPT_GATE_V1", body, path)
            self.assertIn("chatgpt_credential_preflight.py", body, path)


if __name__ == "__main__":
    unittest.main()
