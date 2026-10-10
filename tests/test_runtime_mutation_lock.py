import importlib.util, os, tempfile, time, unittest
from pathlib import Path
MODULE=Path(__file__).resolve().parents[1]/"scripts"/"continuity"/"runtime_lock.py"
def load_module():
    spec=importlib.util.spec_from_file_location("runtime_lock",MODULE); mod=importlib.util.module_from_spec(spec); assert spec.loader; spec.loader.exec_module(mod); return mod
class RuntimeMutationLockTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.old=os.environ.get("SHOPVIVALIZ_AGENT_TASK_STATE_DIR"); os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"]=self.tmp.name
    def tearDown(self):
        if self.old is None: os.environ.pop("SHOPVIVALIZ_AGENT_TASK_STATE_DIR",None)
        else: os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"]=self.old
    def test_competing_writer_rejected_and_fence_increments_after_expiry(self):
        m=load_module(); a=m.acquire_runtime_lock("maintenance","owner-a",1,["controller_promote"]); self.assertEqual(a["fencing_token"],1)
        with self.assertRaises(m.RuntimeLockConflict): m.acquire_runtime_lock("durable-recovery","owner-b",30,["browser_click"])
        time.sleep(1.05); b=m.acquire_runtime_lock("durable-recovery","owner-b",30,["browser_click"]); self.assertEqual(b["fencing_token"],2)
    def test_lock_artifacts_are_group_writable_under_restrictive_umask(self):
        m=load_module()
        previous=os.umask(0o077)
        try:
            m.acquire_runtime_lock("foreground","root-writer",30,["browser_click"])
        finally:
            os.umask(previous)
        root=Path(self.tmp.name)/"_runtime-lock"
        self.assertEqual(root.stat().st_mode & 0o7777, 0o2770)
        self.assertEqual((root/"lock.json").stat().st_mode & 0o777, 0o660)
        self.assertEqual((root/"lock.lock").stat().st_mode & 0o777, 0o660)

    def test_stale_writer_cannot_assert_or_release_newer_lock(self):
        m=load_module(); a=m.acquire_runtime_lock("maintenance","owner-a",1,["controller_promote"]); time.sleep(1.05); b=m.acquire_runtime_lock("durable-recovery","owner-b",30,["browser_click"])
        with self.assertRaises(m.RuntimeLockConflict): m.assert_runtime_lock(a["lease_id"],a["fencing_token"],"controller_promote")
        with self.assertRaises(m.RuntimeLockConflict): m.release_runtime_lock(a["lease_id"],a["fencing_token"],"stale")
        self.assertEqual(m.assert_runtime_lock(b["lease_id"],b["fencing_token"],"browser_click")["owner_id"],"owner-b")
if __name__=="__main__": unittest.main()
