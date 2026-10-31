"""Negative/mutation tests: rule loading cannot silently pass with missing proof."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location(
    "preflight", Path(__file__).resolve().parents[1] / "scripts/quality/agent_rules_preflight.py"
)
pf = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(pf)


def git(root, *args):
    return subprocess.check_output(
        ["git", "-C", str(root), *args], stderr=subprocess.DEVNULL
    ).decode().strip()


class RulesPreflightTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / "project"
        self.root.mkdir()
        git(self.root, "init", "-q", "-b", "main")
        git(self.root, "config", "user.name", "test")
        git(self.root, "config", "user.email", "test@example.invalid")
        for path in pf.REQUIRED:
            f = self.root / path
            f.parent.mkdir(parents=True, exist_ok=True)
            f.write_text(path + "\n" + "\n".join(pf.MARKERS.get(path, ())) + "\n")
        git(self.root, "add", ".")
        git(self.root, "commit", "-qm", "canonical rules")
        self.receipt = Path(self.tmp.name) / "receipt.json"
        self.agent = "test-finalizer"

    def capture(self):
        return pf.capture(self.root, "HEAD", self.receipt, self.agent, pf.ACK)

    def verify(self):
        return pf.verify(self.root, "HEAD", self.receipt, self.agent)

    def test_real_read_receipt_is_verified(self):
        self.capture()
        p = self.verify()
        self.assertEqual(len(p["rules_loaded"]), len(pf.REQUIRED))
        self.assertEqual(p["revision"], git(self.root, "rev-parse", "HEAD"))

    def test_no_ack_rejected(self):
        with self.assertRaisesRegex(ValueError, "acknowledge"):
            pf.capture(self.root, "HEAD", self.receipt, self.agent, "")
        self.assertFalse(self.receipt.exists())

    def test_missing_rule_prevents_receipt(self):
        (self.root / "AUDIT_POLICY.md").unlink()
        git(self.root, "add", "-A")
        git(self.root, "commit", "-qm", "remove policy")
        with self.assertRaises(subprocess.CalledProcessError):
            self.capture()

    def test_removed_marker_prevents_receipt(self):
        (self.root / "AUDIT_POLICY.md").write_text("not a real policy\n")
        git(self.root, "add", "-A")
        git(self.root, "commit", "-qm", "erase governance")
        with self.assertRaisesRegex(ValueError, "missing invariant"):
            self.capture()

    def test_changed_main_sha_invalidates_receipt(self):
        self.capture()
        (self.root / "AUDIT_POLICY.md").write_text(
            "extra\n" + "\n".join(pf.MARKERS["AUDIT_POLICY.md"])
        )
        git(self.root, "add", "-A")
        git(self.root, "commit", "-qm", "change rules")
        with self.assertRaisesRegex(ValueError, "revision changed"):
            self.verify()

    def test_not_current_agent_invalidates_receipt(self):
        self.capture()
        with self.assertRaisesRegex(ValueError, "agent identity"):
            pf.verify(self.root, "HEAD", self.receipt, "other-agent")

    def test_tampered_hash_invalidates_receipt(self):
        data = self.capture()
        data["rules_loaded"][0]["sha256"] = "0" * 64
        self.receipt.write_text(json.dumps(data))
        with self.assertRaisesRegex(ValueError, "content differs"):
            self.verify()

    def test_attestation_expiration_and_future_rejected(self):
        data = self.capture()
        for stamp in [1, 9999999999]:
            data["utc_epoch"] = stamp
            self.receipt.write_text(json.dumps(data))
            with self.assertRaisesRegex(ValueError, "expired or future"):
                self.verify()

    def test_symlink_receipt_rejected(self):
        self.capture()
        self.receipt.rename(self.receipt.with_suffix(".target"))
        self.receipt.symlink_to(self.receipt.with_suffix(".target"))
        with self.assertRaisesRegex(ValueError, "symlink"):
            self.verify()

    def test_missing_manifest_item_rejected(self):
        data = self.capture()
        data["rules_loaded"].pop()
        self.receipt.write_text(json.dumps(data))
        with self.assertRaisesRegex(ValueError, "incomplete"):
            self.verify()


if __name__ == "__main__":
    unittest.main(verbosity=2)
