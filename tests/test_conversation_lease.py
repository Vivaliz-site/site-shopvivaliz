import importlib.util
import os
import tempfile
import time
import unittest
from unittest import mock
from pathlib import Path

MODULE = Path(__file__).resolve().parents[1] / "scripts" / "continuity" / "conversation_lease.py"

def load_module():
    spec = importlib.util.spec_from_file_location("conversation_lease", MODULE)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module

class ConversationLeaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.old = os.environ.get("SHOPVIVALIZ_AGENT_TASK_STATE_DIR")
        os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = self.tmp.name
    def tearDown(self):
        if self.old is None: os.environ.pop("SHOPVIVALIZ_AGENT_TASK_STATE_DIR", None)
        else: os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = self.old
    def test_acquire_rejects_live_owner_and_increments_fence_after_expiry(self):
        m=load_module(); first=m.acquire_conversation_lease("conv-1","foreground","turn-a",7,1,["read"])
        self.assertEqual(first["fencing_token"],1)
        with self.assertRaises(m.LeaseConflict): m.acquire_conversation_lease("conv-1","durable-recovery","worker-b",7,30,["read"])
        time.sleep(1.05); second=m.acquire_conversation_lease("conv-1","durable-recovery","worker-b",7,30,["read"])
        self.assertEqual(second["fencing_token"],2); self.assertNotEqual(second["lease_id"],first["lease_id"])
    def test_stale_token_cannot_renew_or_release_newer_lease(self):
        m=load_module(); first=m.acquire_conversation_lease("conv-2","foreground","turn-a",3,1,["read"])
        time.sleep(1.05); second=m.acquire_conversation_lease("conv-2","durable-recovery","worker-b",3,30,["read","send"])
        with self.assertRaises(m.LeaseConflict): m.renew_conversation_lease("conv-2",first["lease_id"],first["fencing_token"],30)
        with self.assertRaises(m.LeaseConflict): m.release_conversation_lease("conv-2",first["lease_id"],first["fencing_token"],"stale")
        current=m.get_conversation_lease("conv-2"); self.assertEqual(current["lease_id"],second["lease_id"]); self.assertIsNone(current["released_at"])
    def test_assert_requires_current_token_and_allowed_action(self):
        m=load_module(); lease=m.acquire_conversation_lease("conv-3","foreground","turn-a",11,30,["read"])
        current=m.assert_conversation_lease("conv-3",lease["lease_id"],lease["fencing_token"],"read"); self.assertEqual(current["checkpoint_version"],11)
        with self.assertRaises(m.LeaseConflict): m.assert_conversation_lease("conv-3",lease["lease_id"],lease["fencing_token"],"send")
    def test_privileged_writer_inherits_owner_for_json_and_lock(self):
        """Root recovery writers must leave shared leases readable by the service user."""
        m=load_module()
        owner=m._state_root().stat()
        changes=[]
        with mock.patch.object(m.os,"geteuid",return_value=0), mock.patch.object(
            m.os,"fchown",side_effect=lambda fd,uid,gid: changes.append((uid,gid))
        ):
            lease=m.acquire_conversation_lease("root-interoperability","maintenance","owner",1,30,["read"])
        self.assertEqual(lease["fencing_token"],1)
        self.assertEqual(changes,[(owner.st_uid,owner.st_gid)]*2)
        self.assertEqual(m.get_conversation_lease("root-interoperability")["lease_id"],lease["lease_id"])

    def test_unprivileged_writer_never_changes_file_ownership(self):
        m=load_module()
        with mock.patch.object(m.os,"geteuid",return_value=1001), mock.patch.object(
            m.os,"fchown",side_effect=AssertionError("unexpected chown")
        ):
            lease=m.acquire_conversation_lease("normal-interoperability","foreground","owner",1,30,["read"])
            renewed=m.renew_conversation_lease("normal-interoperability",lease["lease_id"],lease["fencing_token"],30)
        self.assertEqual(renewed["fencing_token"],lease["fencing_token"])

if __name__ == "__main__": unittest.main()
