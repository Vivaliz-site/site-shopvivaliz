"""Unit tests: passive detection must not call or authorize paid providers."""
import importlib.util
from io import BytesIO
import json
from pathlib import Path
import sqlite3
import stat
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("ai_sentinel", ROOT/"scripts/ai-free-provider-sentinel.py")
assert spec and spec.loader
s = importlib.util.module_from_spec(spec)
spec.loader.exec_module(s)


class Response:
    def __enter__(self):
        return self
    def __exit__(self, *_a):
        return False
    def read(self, n):
        return json.dumps({
            "endpoint":"ai-squad-codex-bridge","ok":False,
            "auth_mode":"disabled","policy":"manual_approval_required",
        }).encode()


class Tests(unittest.TestCase):
    def test_health_rejects_enabled_and_wrong_endpoint(self):
        valid={"endpoint":"ai-squad-codex-bridge","ok":False,
               "auth_mode":"disabled","policy":"manual_approval_required"}
        self.assertTrue(s.classify_health("codex",valid))
        for key,value in [("ok",True),("auth_mode","enabled"),("policy","allow"),("endpoint","other")]:
            bad=dict(valid);bad[key]=value
            self.assertFalse(s.classify_health("codex",bad))

    def test_health_is_get_only(self):
        calls=[]
        class Client:
            def open(self,request,timeout):
                calls.append(request.get_method())
                return Response()
        with patch.object(s,"build_opener",return_value=Client()):
            self.assertTrue(s.bridge_disabled("codex"))
        self.assertEqual(calls,["GET"])

    def test_daemon_is_exempt_not_claude_sessions(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            for pid,comm in [(10,"claude"),(20,"claude"),(30,"codex")]:
                p=root/str(pid);p.mkdir()
                (p/"comm").write_text(comm)
            self.assertEqual(s.proc_potential_paid(10,root),
                             ["PAID_PROCESS_CLAUDE","PAID_PROCESS_CODEX"])

    def test_codex_db_counter_and_drift(self):
        with tempfile.TemporaryDirectory() as d:
            db=Path(d)/"state.sqlite"
            con=sqlite3.connect(db)
            con.execute("CREATE TABLE threads(tokens_used INTEGER)")
            con.execute("INSERT INTO threads VALUES (99)")
            con.commit()
            self.assertEqual(s.codex_counter(db),(99,1))
            con.execute("INSERT INTO threads VALUES (1)")
            con.commit()
            self.assertEqual(s.codex_counter(db),(100,2))
            self.assertEqual(s.compare_counters({"codex_counters":{"p":[99,1]}},{"p":[100,2]}),
                             ["CODEX_TOKEN_COUNTER_INCREASE"])
            self.assertEqual(s.compare_counters({},{"p":[100,2]}),[])
            con.close()

    def test_claude_usage_timestamp_not_file_mtime(self):
        import datetime
        def event(ts,model="claude-haiku-4-5",out=5):
            return json.dumps({"type":"assistant",
                               "timestamp":datetime.datetime.fromtimestamp(
                                   ts,datetime.timezone.utc).isoformat(),
                               "message":{"model":model,"usage":{"output_tokens":out}}})
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/"session.jsonl"
            p.write_text(event(100)+"\n"+event(180)+"\n"+event(185,out=0)+"\n")
            self.assertEqual(s.assistant_usage_after(p,150,200),1)
            self.assertEqual(s.assistant_usage_after(p,190,200),0)

    def test_dedupe_and_recovery(self):
        state={}
        alert,fp=s.evaluate_notification(state,["FREE_ROUTER_MISSING"],1000)
        self.assertEqual(alert,"ALERT")
        state={"notified_fingerprint":fp,"notified_epoch":1000}
        self.assertEqual(s.evaluate_notification(state,["FREE_ROUTER_MISSING"],1200)[0],"")
        self.assertEqual(s.evaluate_notification(state,["FREE_ROUTER_MISSING"],100000)[0],"ALERT")
        self.assertEqual(s.evaluate_notification(state,[],1300)[0],"RECOVERED")

    def test_state_private_and_atomic(self):
        with tempfile.TemporaryDirectory() as d:
            state=Path(d)/"state"/"state.json"
            s.store_state(state,{"last_status":"HEALTHY"})
            self.assertEqual(s.load_state(state)["last_status"],"HEALTHY")
            self.assertEqual(stat.S_IMODE(state.stat().st_mode),0o600)
            self.assertEqual(stat.S_IMODE(state.parent.stat().st_mode),0o700)

    def test_no_notifications_for_healthy(self):
        with tempfile.TemporaryDirectory() as d:
            with (patch.object(s,"policy_anomalies",return_value=[]),
                  patch.object(s,"proc_potential_paid",return_value=[]),
                  patch.object(s,"service_pid",return_value=0),
                  patch.object(s,"codex_snapshot",return_value=({},[])),
                  patch.object(s,"recent_claude_usage",return_value=0),
                  patch.object(s,"github_comment") as notify):
                issues,_=s.run(state_dir=Path(d),now=1000)
                self.assertEqual(issues,[])
                notify.assert_not_called()

    def test_alert_only_once_then_recovery(self):
        with tempfile.TemporaryDirectory() as d:
            with (patch.object(s,"policy_anomalies",side_effect=[["FREE_ROUTER_MISSING"],
                       ["FREE_ROUTER_MISSING"],[]]),
                  patch.object(s,"proc_potential_paid",return_value=[]),
                  patch.object(s,"service_pid",return_value=0),
                  patch.object(s,"codex_snapshot",return_value=({},[])),
                  patch.object(s,"recent_claude_usage",return_value=0),
                  patch.object(s,"github_comment",return_value=True) as notify):
                for now in (1000,1300,1600):
                    s.run(state_dir=Path(d),now=now)
                self.assertEqual(notify.call_count,2)
                self.assertIn("RECOVERED",notify.call_args.args[0])

    def test_service_files_invoke_readonly_sentinel(self):
        timer=(ROOT/"deploy/systemd/shopvivaliz-ai-free-guard.timer").read_text()
        unit=(ROOT/"deploy/systemd/shopvivaliz-ai-free-guard.service").read_text()
        installer=(ROOT/"scripts/install-ai-free-provider-sentinel.sh").read_text()
        self.assertIn("OnUnitActiveSec=5min",timer)
        self.assertIn("StateDirectory=shopvivaliz-ai-free-guard",unit)
        self.assertIn("systemctl enable --now shopvivaliz-ai-free-guard.timer",installer)


if __name__=="__main__":
    unittest.main()
