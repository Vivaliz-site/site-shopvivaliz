#!/usr/bin/env python3
"""Pure offline authorization and regression tests: NEVER touch real crontab/systemd."""
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import sys
from contextlib import ExitStack
from unittest.mock import patch
import time
import unittest
import uuid

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("maintenance_cart", ROOT / "remote-control-mcp/maintenance_cart.py")
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
# Load the standalone source helper as a module without invoking its CLI,
# impersonating no production host and performing no system operation.
sys.modules["maintenance_cart"] = m
source_spec = importlib.util.spec_from_file_location("marina_source_helper", ROOT / "remote-control-mcp/retire-origin-abandoned-cart.py")
source_helper = importlib.util.module_from_spec(source_spec)
source_spec.loader.exec_module(source_helper)

class FakeLease:
    def __init__(self):
        self.count = 0
        self.released = 0
        self.busy = False
    def acquire_runtime_lock(self, kind, owner, ttl, actions):
        if self.busy: raise RuntimeError("owner_busy")
        assert kind == "maintenance" and owner.startswith("cart-source-retire:")
        assert actions == [m.APPROVAL_ACTION] and ttl <= 90
        self.count += 1
        return {"lease_id": "internal-only", "fencing_token": self.count}
    def assert_runtime_lock(self, lease_id, fencing_token, action):
        if self.busy or lease_id != "internal-only" or action != m.APPROVAL_ACTION:
            raise RuntimeError("lease no longer live")
        return {"lease_id":lease_id, "fencing_token":fencing_token}
    def release_runtime_lock(self, lease_id, fencing_token, reason):
        assert lease_id == "internal-only"
        self.released += 1

class SignedMaintenanceTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.registry_parent = self.root / "protected-registry"
        self.registry_parent.mkdir(mode=0o700)
        self.operator_file = self.registry_parent / "approvers.json"
        self.key = Ed25519PrivateKey.generate()
        pub = self.key.public_key().public_bytes(encoding=serialization.Encoding.Raw,
                                                  format=serialization.PublicFormat.Raw)
        self.operator_file.write_text(json.dumps({"schema_version":1,"operators":{
            "fredmourao-ai":{"enabled":True,"ed25519_public_key_b64":base64.b64encode(pub).decode()}}}))
        self.operator_file.chmod(0o600)
        self.cron_sha = hashlib.sha256(b"expected-crontab-before-change\n").hexdigest()
        now = int(time.time())
        self.ticket = {"version":1, "approval_id":str(uuid.uuid4()), "operator_id":"fredmourao-ai",
                       "action":m.APPROVAL_ACTION, "source_host":m.SOURCE_HOST,
                       "destination_host":m.DEST_HOST, "timer":m.TIMER,
                       "source_cron_sha256":self.cron_sha,
                       "issued_at":now, "expires_at":now+120}
        self.lock = FakeLease()
        self.executions = []
        self.source_legacy = True

    def sign(self):
        return base64.b64encode(self.key.sign(m._canonical(self.ticket))).decode()

    def fake_run(self, host, cmd):
        if host == m.SOURCE_HOST:
            assert cmd == m.SOURCE_PROGRAM + " --inspect"
            return {"exit_code":0,"stdout":json.dumps({"host":host,"fence_present":True,
                "cron_entry_count":int(self.source_legacy),
                "cron_sha256":self.cron_sha if self.source_legacy else hashlib.sha256(b"").hexdigest(),
                "timer_active":"active" if self.source_legacy else "inactive",
                "timer_enabled":"enabled" if self.source_legacy else "disabled"})}
        assert host == m.DEST_HOST and "systemctl" in cmd
        return {"exit_code":0,"stdout":json.dumps({"host":host,"active":"active","enabled":"enabled"})}

    def fake_execution(self, ticket, signature):
        self.executions.append(ticket["approval_id"])
        assert signature == self.sign()
        self.source_legacy = False
        return {"exit_code":0,"stdout":json.dumps({"retirement":"APPLIED","source_host":m.SOURCE_HOST,
            "timer_active":"inactive","timer_enabled":"disabled","cron_entry_count":0})}

    def apply(self, signature=None, runner=None):
        return m.apply(self.ticket, signature if signature is not None else self.sign(),
                       run_host=self.fake_run,
                       execute_source=runner or self.fake_execution, runtime_lock=self.lock,
                       registry_path=self.operator_file, state_dir=self.root / "state",
                       owner_uid=os.getuid())

    def test_approval_signature_and_single_private_lease(self):
        result = self.apply()
        self.assertTrue(result["applied"])
        self.assertFalse(result["reboot_and_two_windows_verified"])
        self.assertEqual(self.lock.count, 1)
        self.assertEqual(self.lock.released, 1)
        self.assertEqual(len(self.executions), 1)

    def test_replayed_ticket_not_executed_again(self):
        self.apply()
        self.source_legacy = True
        with self.assertRaisesRegex(m.MaintenanceDenied, "approval_already_consumed"):
            self.apply()
        self.assertEqual(len(self.executions), 1)

    def test_bad_signature_fails_before_runtime_lock(self):
        with self.assertRaisesRegex(m.MaintenanceDenied, "ticket_signature_invalid"):
            self.apply(signature=base64.b64encode(bytes(64)).decode())
        self.assertEqual(self.lock.count, 0)

    def test_chat_consent_without_registered_operator_is_not_enough(self):
        self.operator_file.unlink()
        with self.assertRaisesRegex(m.MaintenanceDenied, "operator_registry_unavailable"):
            self.apply()
        self.assertEqual(self.lock.count, 0)

    def test_ticket_cannot_authorize_arbitrary_service(self):
        self.ticket["action"] = "service_action"
        with self.assertRaisesRegex(m.MaintenanceDenied, "ticket_scope_invalid"):
            self.apply()

    def test_expired_ticket_rejected(self):
        self.ticket["issued_at"] -= 600
        self.ticket["expires_at"] -= 600
        with self.assertRaisesRegex(m.MaintenanceDenied, "ticket_expired"):
            self.apply()

    def test_cron_change_conflict_never_issues_lease(self):
        self.ticket["source_cron_sha256"] = "0" * 64
        with self.assertRaisesRegex(m.MaintenanceDenied, "source_cron_cas_mismatch"):
            self.apply()
        self.assertEqual(self.lock.count, 0)

    def test_concurrent_lease_denied(self):
        self.lock.busy = True
        with self.assertRaisesRegex(m.MaintenanceDenied, "runtime_lease_unavailable"):
            self.apply()
        self.assertEqual(len(self.executions), 0)

    def test_group_writable_registry_parent_is_denied(self):
        self.registry_parent.chmod(0o770)
        with self.assertRaisesRegex(m.MaintenanceDenied, "operator_registry_parent_untrusted"):
            self.apply()
        self.assertEqual(self.lock.count, 0)

    def test_registry_symlink_parent_is_denied(self):
        alt = self.root / "alternate-trust"
        alt.mkdir(mode=0o700)
        alt_file = alt / "approvers.json"
        alt_file.write_bytes(self.operator_file.read_bytes())
        alt_file.chmod(0o600)
        self.operator_file.unlink()
        self.registry_parent.rmdir()
        self.registry_parent.symlink_to(alt, target_is_directory=True)
        with self.assertRaisesRegex(m.MaintenanceDenied, "operator_registry_parent_untrusted"):
            self.apply()

    def test_world_readable_operator_registry_denied(self):
        self.operator_file.chmod(0o644)
        with self.assertRaisesRegex(m.MaintenanceDenied, "operator_registry_untrusted"):
            self.apply()

    def test_read_only_preview_does_not_create_lease(self):
        response = m.preview(self.fake_run)
        self.assertFalse(response["lease_issued"])
        self.assertEqual(self.lock.count, 0)

    def test_indeterminate_remote_result_never_replayed(self):
        def fail(*_): raise TimeoutError("remote outcome unknown")
        with self.assertRaises(TimeoutError):
            self.apply(runner=fail)
        journal = json.loads((self.root/"state"/(self.ticket["approval_id"]+".json")).read_text())
        self.assertEqual(journal["status"], "indeterminate")
        self.assertEqual(self.lock.count, self.lock.released)
        with self.assertRaisesRegex(m.MaintenanceDenied, "approval_already_consumed"):
            self.apply()

    def test_integrated_mcp_does_not_log_signed_capability(self):
        server = (ROOT/"remote-control-mcp"/"server.py").read_text()
        self.assertIn('if tool == "marina_cart_retire_source":', server)
        self.assertIn('"signed_approval_redacted": True', server)
        self.assertIn('"SHOPVIVALIZ_CART_MAINTENANCE_ENABLED"', server)
        self.assertIn("source_ssh_route_untrusted", server)
        self.assertIn("marina_private_route_not_provisioned", server)
        self.assertIn("marina_cart_preview", server)

    def test_source_cron_removal_preserves_all_unrelated_lines(self):
        expected = source_helper.EXPECTED_CRON
        other = "0 1 * * * /usr/local/bin/other-safe-job"
        before = (other + "\n" + expected + "\n# keep this comment\n").encode()
        calls = []
        def fake_run(argv, *, data=None, check=True):
            calls.append((argv, data))
        with patch.object(source_helper, "_read_cron", return_value=before), patch.object(source_helper, "_run", side_effect=fake_run):
            source_helper._remove_one_cron(before)
        self.assertEqual(calls, [(["crontab", "-u", "ubuntu", "-"], (other + "\n# keep this comment\n").encode())])

    def test_source_cron_removal_denies_change_since_preflight(self):
        before = (source_helper.EXPECTED_CRON + "\n").encode()
        with patch.object(source_helper, "_read_cron", return_value=before + b"# race\n"), patch.object(source_helper, "_run") as run:
            with self.assertRaisesRegex(m.MaintenanceDenied, "crontab_changed_during_transaction"):
                source_helper._remove_one_cron(before)
            run.assert_not_called()

    def test_source_rejects_concurrent_sender_file_lock(self):
        import fcntl
        cron = self.root / "cart-legacy.lock"
        timer = self.root / "cart-timer.lock"
        with patch.object(source_helper, "CRON_LOCK", cron), patch.object(source_helper, "TIMER_LOCK", timer):
            with cron.open("a+b") as current_sender:
                fcntl.flock(current_sender.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                with ExitStack() as locked:
                    with self.assertRaisesRegex(m.MaintenanceDenied, "sender_currently_running"):
                        source_helper._lock_writers(locked)

    def test_fixed_origin_script_requires_signed_stdin_and_two_locks(self):
        script = (ROOT/"remote-control-mcp"/"retire-origin-abandoned-cart.py").read_text()
        for value in ("verify_ticket(ticket, signature_b64)", "sys.stdin.buffer.read(2049)",
                      "CRON_LOCK, TIMER_LOCK", '["systemctl", "disable", TIMER]',
                      '["systemctl", "stop", TIMER]', "sender_currently_running",
                      "cron.backup", "origin_retirement_postcondition_failed"):
            self.assertIn(value, script)
        self.assertNotIn("StrictHostKeyChecking=no", script)

if __name__ == "__main__":
    unittest.main()
