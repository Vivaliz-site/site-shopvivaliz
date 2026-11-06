from __future__ import annotations

import base64
from datetime import datetime, timezone
import importlib.util
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/ops/authorize-kocepsv-gui-validation-lease.py"
SPEC = importlib.util.spec_from_file_location("kocepsv_gui_validation_issuer", SCRIPT)
assert SPEC and SPEC.loader
m = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)

PR = 3696
HEAD = "a" * 40
MERGE = "b" * 40
CRED = "unit-test-placeholder-" + "x" * 24
NOW = datetime(2026, 11, 1, 22, 0, tzinfo=timezone.utc)


def grant():
    return {
        "schema": "SHOPVIVALIZ_OWNER_PR_GRANT_V1",
        "repository": m.REPOSITORY,
        "pr_number": PR,
        "resource": m.RESOURCE,
        "principal": m.PRINCIPAL,
        "host": "KOCEPSV",
        "controller_host": m.CONTROLLER,
        "capabilities": [m.CAPABILITY],
        "operation": m.OPERATION,
        "allowed_actions": m.ACTIONS,
        "target_origin": "https://shopvivaliz.com.br",
        "one_use": True,
        "ttl_seconds": 300,
        "expires_at_utc": "2026-11-12T00:00:00Z",
    }


def fake_api(payload):
    encoded = base64.b64encode(json.dumps(payload).encode()).decode()
    def api(path, token):
        if path == "/user":
            return {"login": "fredmourao-ai"}
        if path.endswith(f"/pulls/{PR}"):
            return {"head": {"sha": HEAD}, "merge_commit_sha": MERGE}
        if path.endswith(f"/contents/security/owner-approvals/{PR}.json?ref=main"):
            return {"encoding": "base64", "content": encoded}
        raise AssertionError("unsanctioned GitHub URL: " + path)
    return api


def successful_verifier(*args, **kwargs):
    return SimpleNamespace(returncode=0, stdout="OWNER_PR_GRANT=AUTHORIZED reason=owner_authorization_verified\n", stderr="")


class KocepsvGuiValidationLeaseTests(unittest.TestCase):
    def test_final_grant_exactly_matches_official_request(self):
        self.assertEqual("mcp:kocepsv:gui-validation", m.RESOURCE)
        self.assertEqual(["desktop_open", "desktop_click", "desktop_type"], m.ACTIONS)
        self.assertEqual(300, m.TTL)
        self.assertEqual("shopvivaliz-central-mcp", m.PRINCIPAL)

    def test_repository_grants_match_the_exact_owner_approved_scope(self):
        # Both P2 grants were individually owner-approved and have been consumed.
        # 3705 explicitly re-authorized a second one-use attempt after 3702.
        # Freeze the known grant IDs here: a third grant (or any widened scope)
        # must fail CI and receive its own formal owner approval/test update.
        found = []
        for path in sorted((ROOT / "security" / "owner-approvals").glob("*.json")):
            data = json.loads(path.read_text(encoding="utf-8"))
            if data.get("resource") == m.RESOURCE:
                found.append((path, data))
        self.assertEqual(
            {3702, 3705}, {data.get("pr_number") for _, data in found},
            "unexpected KOCEPSV GUI grant; review its explicit P2 authority",
        )
        self.assertEqual(2, len(found), "duplicate PR IDs cannot hide an extra grant")
        for path, on_disk in found:
            with self.subTest(grant_path=path.name):
                self.assertEqual(f"{on_disk['pr_number']}.json", path.name)
                expected = dict(grant(), pr_number=on_disk["pr_number"])
                self.assertEqual(expected, on_disk)
                self.assertTrue(on_disk["one_use"])
                self.assertEqual(300, on_disk["ttl_seconds"])
                self.assertEqual("https://shopvivaliz.com.br", on_disk["target_origin"])

    def test_pr_reviewed_and_merged_can_pass_read_only_proof(self):
        with patch.object(m, "github_json", side_effect=fake_api(grant())), \
             patch.object(m.subprocess, "run", side_effect=successful_verifier), \
             patch.dict(os.environ, {"SHOPVIVALIZ_SECURITY_OWNER_LOGIN": "shopvivaliz-human"}):
            result = m.verify_approved_pr(PR, CRED, now=NOW)
        self.assertEqual(PR, result["pr_number"])
        self.assertEqual(HEAD, result["head_sha"])

    def test_unavailable_protected_owner_or_non_authorized_pr_denies(self):
        with patch.object(m, "github_json", side_effect=fake_api(grant())), \
             patch.object(m.subprocess, "run", side_effect=successful_verifier), \
             patch.dict(os.environ, {"SHOPVIVALIZ_SECURITY_OWNER_LOGIN": "not-the-owner"}):
            with self.assertRaisesRegex(m.GuiValidationDenied, "protected_owner_identity_unavailable"):
                m.verify_approved_pr(PR, CRED, now=NOW)
        fail = SimpleNamespace(returncode=1, stdout="OWNER_PR_GRANT=DENIED reason=pr_not_merged\n", stderr="")
        with patch.object(m, "github_json", side_effect=fake_api(grant())), \
             patch.object(m.subprocess, "run", return_value=fail), \
             patch.dict(os.environ, {"SHOPVIVALIZ_SECURITY_OWNER_LOGIN": "shopvivaliz-human"}):
            with self.assertRaisesRegex(m.GuiValidationDenied, "owner_pr_grant_not_authorized"):
                m.verify_approved_pr(PR, CRED, now=NOW)

    def test_owner_token_not_accepted_as_technical_actor(self):
        def wrong_identity(path, token):
            if path == "/user":
                return {"login": "shopvivaliz-human"}
            raise AssertionError("should abort before other API calls")
        with patch.object(m, "github_json", side_effect=wrong_identity):
            with self.assertRaisesRegex(m.GuiValidationDenied, "technical_actor_not_independent"):
                m.verify_approved_pr(PR, CRED, now=NOW)

    def test_refuses_widened_or_expired_grant_after_pr_verification(self):
        base = grant()
        invalids = [
            dict(base, host="Fred-Win"),
            dict(base, controller_host="retired-host"),
            dict(base, allowed_actions=["desktop_open", "desktop_click", "desktop_type", "service_action"]),
            dict(base, ttl_seconds=3600),
            dict(base, one_use=False),
            dict(base, target_origin="https://chatgpt.com"),
            dict(base, added_root=True),
            dict(base, expires_at_utc="2026-10-30T00:00:00Z"),
        ]
        for bad in invalids:
            with self.subTest(bad=bad), \
                 patch.object(m, "github_json", side_effect=fake_api(bad)), \
                 patch.object(m.subprocess, "run", side_effect=successful_verifier), \
                 patch.dict(os.environ, {"SHOPVIVALIZ_SECURITY_OWNER_LOGIN": "shopvivaliz-human"}):
                with self.assertRaises(m.GuiValidationDenied):
                    m.verify_approved_pr(PR, CRED, now=NOW)

    def test_single_issue_writes_protected_receipt_and_replay_is_denied(self):
        proof = {"pr_number": PR, "head_sha": HEAD, "merge_sha": MERGE, "expires_at_utc": "2026-11-12T00:00:00Z"}
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            called = []
            def issue(kind, owner, ttl, actions):
                called.append((kind, owner, ttl, actions))
                return {"lease_id": "lease-unit", "fencing_token": 17,
                        "expires_at_epoch": 1234567}
            with patch.object(m, "STATE_ROOT", root), \
                 patch.object(m, "LEDGER_DIR", root / "_desktop-kocepsv-gui-validation-approvals"), \
                 patch.object(m.runtime_lock, "acquire_runtime_lock", side_effect=issue):
                first = m.issue_one_use(PR, proof)
                self.assertEqual("SCOPED_RUNTIME_LEASE_ISSUED", first["status"])
                self.assertEqual([("maintenance", f"shopvivaliz-kocepsv-gui-validation:{PR}",
                                   300, m.ACTIONS)], called)
                self.assertEqual("lease-unit", json.loads((root / "_desktop-kocepsv-gui-validation-approvals"
                                                           / f"pr-{PR}.json").read_text())["lease_id"])
                with self.assertRaisesRegex(m.GuiValidationDenied, "one_use_owner_grant_already_consumed"):
                    m.issue_one_use(PR, proof)
                self.assertEqual(1, len(called))

    def test_issuance_never_uses_an_expired_approval(self):
        proof = {"pr_number": PR, "head_sha": HEAD, "merge_sha": MERGE, "expires_at_utc": "2026-10-30T00:00:00Z"}
        with tempfile.TemporaryDirectory() as temp, patch.object(m, "STATE_ROOT", Path(temp)), \
             patch.object(m, "LEDGER_DIR", Path(temp) / "_desktop-kocepsv-gui-validation-approvals"), \
             patch.object(m.runtime_lock, "acquire_runtime_lock") as acquire:
            with self.assertRaisesRegex(m.GuiValidationDenied, "owner_grant_expired_before_issuance"):
                m.issue_one_use(PR, proof)
            acquire.assert_not_called()

    def test_lock_contention_never_steals_another_owner(self):
        proof = {"pr_number": PR, "head_sha": HEAD, "merge_sha": MERGE, "expires_at_utc": "2026-11-12T00:00:00Z"}
        with tempfile.TemporaryDirectory() as temp, \
             patch.object(m, "STATE_ROOT", Path(temp)), \
             patch.object(m, "LEDGER_DIR", Path(temp) / "_desktop-kocepsv-gui-validation-approvals"), \
             patch.object(m.runtime_lock, "acquire_runtime_lock",
                          side_effect=m.runtime_lock.RuntimeLockConflict("occupied")):
            with self.assertRaisesRegex(m.GuiValidationDenied, "runtime_lock_busy_or_unavailable"):
                m.issue_one_use(PR, proof)

    def test_release_requires_exact_owner_lease_and_fencing(self):
        exact = {"owner_id": f"shopvivaliz-kocepsv-gui-validation:{PR}",
                 "lease_id": "lease-123", "fencing_token": 14}
        with patch.object(m.runtime_lock, "get_runtime_lock", return_value=exact), \
             patch.object(m.runtime_lock, "release_runtime_lock") as release:
            for key, val in (("lease_id", "not-the-owner"), ("fencing_token", 3)):
                with self.subTest(key=key):
                    kw = dict(exact)
                    kw[key] = val
                    with self.assertRaisesRegex(m.GuiValidationDenied, "maintenance_lease_owner_mismatch"):
                        m.release_owned(PR, kw["lease_id"], kw["fencing_token"])
            result = m.release_owned(PR, "lease-123", 14)
            self.assertEqual("SCOPED_RUNTIME_LEASE_RELEASED", result["status"])
            release.assert_called_once_with("lease-123", 14, "kocepsv_gui_validation_completed")


if __name__ == "__main__":
    unittest.main()
