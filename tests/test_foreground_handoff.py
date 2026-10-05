import importlib.util
import os
import tempfile
import time
import unittest
from pathlib import Path
from unittest import mock

ROOT=Path(__file__).resolve().parents[1]
AGENT_PATH=ROOT/"scripts"/"agent_task_state.py"
HANDOFF_PATH=ROOT/"scripts"/"continuity"/"foreground_handoff.py"

def load(path,name):
    spec=importlib.util.spec_from_file_location(name,path); mod=importlib.util.module_from_spec(spec); assert spec.loader; spec.loader.exec_module(mod); return mod

class ForegroundHandoffTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.old=os.environ.get("SHOPVIVALIZ_AGENT_TASK_STATE_DIR"); os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"]=self.tmp.name
        self.agent=load(AGENT_PATH,"agent_task_state_handoff_test")
        self.agent.start_task("task-a","do durable work","chatgpt","Vivaliz-site/site-shopvivaliz")
    def tearDown(self):
        if self.old is None: os.environ.pop("SHOPVIVALIZ_AGENT_TASK_STATE_DIR",None)
        else: os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"]=self.old
    def test_submits_exactly_once_and_returns_without_wait_or_sleep(self):
        m=load(HANDOFF_PATH,"foreground_handoff_test_one")
        calls=[]
        def submit(command): calls.append(list(command)); return {"task_id":"durable-1","queue_position":1,"state":"queued"}
        with mock.patch.object(m.time,"sleep",side_effect=AssertionError("foreground must not sleep")):
            out=m.handoff_foreground("task-a","conversation_12345678",1,["python3","worker.py"],_submitter=submit)
        self.assertEqual(calls,[["python3","worker.py"]]); self.assertEqual(out["durable_execution_id"],"durable-1")
        self.assertEqual(out["conversation_id"],"conversation_12345678"); self.assertEqual(out["queue_position"],1)
        self.assertLess(out["foreground_duration_ms"],5000)
        state=self.agent.load_task("task-a"); self.assertEqual(state["status"],"RUNNING"); self.assertEqual(state["durable_execution_id"],"durable-1")
    def test_queue_saturation_returns_immediately_without_waiting(self):
        m=load(HANDOFF_PATH,"foreground_handoff_test_queue")
        started=time.monotonic()
        out=m.handoff_foreground("task-a","conversation_12345678",1,["long","job"],_submitter=lambda cmd:{"task_id":"durable-9","queue_position":9,"state":"queued"})
        self.assertEqual(out["queue_position"],9); self.assertLess(time.monotonic()-started,1.0)
    def test_submission_failure_releases_new_lease_and_leaves_task_nonterminal(self):
        m=load(HANDOFF_PATH,"foreground_handoff_test_failure")
        def fail(_): raise RuntimeError("queue unavailable")
        with self.assertRaisesRegex(RuntimeError,"queue unavailable"):
            m.handoff_foreground("task-a","conversation_12345678",1,["job"],_submitter=fail)
        state=self.agent.load_task("task-a"); self.assertEqual(state["status"],"RUNNING"); self.assertNotIn("durable_execution_id",state)
        lease=m.conversation_lease.get_conversation_lease("conversation_12345678"); self.assertIsNotNone(lease["released_at"]); self.assertEqual(lease["release_reason"],"durable_submission_failed")
if __name__=="__main__": unittest.main()
