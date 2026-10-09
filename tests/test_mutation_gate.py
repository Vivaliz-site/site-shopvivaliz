import importlib.util, time, unittest
from pathlib import Path
MODULE=Path(__file__).resolve().parents[1]/"scripts"/"continuity"/"mutation_gate.py"
def load():
    spec=importlib.util.spec_from_file_location("mutation_gate",MODULE); m=importlib.util.module_from_spec(spec); assert spec.loader; spec.loader.exec_module(m); return m

def lease(kind="durable-recovery", version=7, session="fred", actions=None):
    return {"conversation_id":"conv-12345678","owner_kind":kind,"owner_id":"owner","checkpoint_version":version,"allowed_actions":actions or ["continuation_send"],"session_identity":session,"expires_at_epoch":time.time()+60,"released_at":None}
def runtime(actions=None):
    return {"owner_kind":"durable-recovery","owner_id":"owner","allowed_actions":actions or ["continuation_send"],"expires_at_epoch":time.time()+60,"released_at":None}
class MutationGateTests(unittest.TestCase):
    def test_live_foreground_lease_rejects_background_mutation(self):
        m=load(); out=m.authorize_mutation("conv-12345678",7,lease("foreground"),runtime(),"continuation_send","fred"); self.assertFalse(out["authorized"]); self.assertEqual(out["reason"],"foreground_active")
    def test_matching_recovery_ownership_authorizes(self):
        m=load(); out=m.authorize_mutation("conv-12345678",7,lease(),runtime(),"continuation_send","fred"); self.assertTrue(out["authorized"])
    def test_stale_checkpoint_fails_closed(self):
        m=load(); out=m.authorize_mutation("conv-12345678",8,lease(version=7),runtime(),"continuation_send","fred"); self.assertFalse(out["authorized"]); self.assertEqual(out["reason"],"stale_checkpoint")
    def test_wrong_session_fails_closed(self):
        m=load(); out=m.authorize_mutation("conv-12345678",7,lease(session="atendimento"),runtime(),"continuation_send","fred"); self.assertFalse(out["authorized"]); self.assertEqual(out["reason"],"session_mismatch")
    def test_cooldown_and_duplicate_action_fail_closed(self):
        m=load(); a=lease(); a["cooldown_until_epoch"]=time.time()+60; out=m.authorize_mutation("conv-12345678",7,a,runtime(),"continuation_send","fred"); self.assertEqual(out["reason"],"cooldown_active"); b=lease(); b["completed_actions"]=["continuation_send"]; out=m.authorize_mutation("conv-12345678",7,b,runtime(),"continuation_send","fred"); self.assertEqual(out["reason"],"duplicate_action")
if __name__=="__main__": unittest.main()
