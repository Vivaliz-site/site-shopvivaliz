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
    def test_gemini_billing_guard_requires_wrapper_and_router(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            wrapper = root / "run_background_gemini.py"
            router = root / "autonomous-provider-failover.sh"
            wrapper.write_text(
                "FREE_TIER_BILLING_GUARD_V1\\n"
                "free_tier_approved_credentials(\\n"
                "background_gemini_error=free_tier_not_verified\\n"
                "SHOPVIVALIZ_RESUME_BACKGROUND\\n"
            )
            router.write_text("env SHOPVIVALIZ_RESUME_BACKGROUND=1\\n")
            self.assertTrue(s.gemini_free_billing_guard_present(root))
            router.write_text("python3 run_background_gemini.py\\n")
            self.assertFalse(s.gemini_free_billing_guard_present(root))
            router.write_text("env SHOPVIVALIZ_RESUME_BACKGROUND=1\\n")
            wrapper.write_text("FREE_TIER_BILLING_GUARD_V1\\n")
            self.assertFalse(s.gemini_free_billing_guard_present(root))

    def test_gemini_billing_guard_missing_files_fail_closed(self):
        with tempfile.TemporaryDirectory() as d:
            self.assertFalse(s.gemini_free_billing_guard_present(Path(d)))

    def test_gemini_billing_drift_reported_but_no_model_invoked(self):
        with (patch.object(s, "active", return_value=True),
              patch.object(s, "service_pid", return_value=100),
              patch.object(s, "selected_environment",
                           return_value={x: "0" for x in (*s.FLAGS, s.INTERACTIVE_FLAG)}),
              patch.object(s, "free_router_present", return_value=True),
              patch.object(s, "runtime_gemini_guard_present", return_value=False),
              patch.object(s, "bridge_disabled", return_value=True)):
            self.assertIn(
                "GEMINI_FREE_BILLING_GUARD_DRIFT",
                s.policy_anomalies("shopvivaliz-free-a1"),
            )
        with (patch.object(s, "active", return_value=True),
              patch.object(s, "service_pid", return_value=100),
              patch.object(s, "selected_environment",
                           return_value={x: "0" for x in (*s.FLAGS, s.INTERACTIVE_FLAG)}),
              patch.object(s, "free_router_present", return_value=True),
              patch.object(s, "runtime_gemini_guard_present", return_value=True),
              patch.object(s, "bridge_disabled", return_value=True)):
            self.assertNotIn(
                "GEMINI_FREE_BILLING_GUARD_DRIFT",
                s.policy_anomalies("shopvivaliz-free-a1"),
            )

    def test_marina_passive_host_has_no_invented_ollama_dependency(self):
        # Marina hosts web/pricing/returns; the remote AI model runner is
        # not deployed. Existing process and scheduled-launcher surveillance
        # still runs in sentinel.run for this host.
        self.assertEqual(s.policy_anomalies("marina-a1-4c24g"), [])
        self.assertIn("HOST_IDENTITY_UNKNOWN", s.policy_anomalies("unrecognized-host"))

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

    def _schedule_fixture(self, root, active):
        return s.scheduled_paid_launchers(
            systemd_dir=root/"systemd", user_timer_dir=root/"user-systemd",
            cron_dir=root/"cron.d", cron_main=root/"crontab",
            user_crontabs=(), periodic_dirs=(), active_timers=active,
            allowed_script_roots=(root,),
        )

    def test_scheduled_claude_direct_cron_is_alerted(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/"cron.d").mkdir()
            (root/"cron.d"/"paid").write_text(
                "*/5 * * * * ubuntu claude -p 'repeat this job'\n")
            self.assertEqual(
                self._schedule_fixture(root, set()),
                ["PAID_RECURRING_CRON_LAUNCHER"],
            )

    def test_scheduled_nested_shell_paid_job_is_alerted(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/"cron.d").mkdir();(root/"scripts").mkdir()
            job=root/"scripts"/"continuity.sh"
            job.write_text("#!/bin/sh\nclaude --print 'again'\n")
            (root/"cron.d"/"every-hour").write_text(
                f"0 * * * * ubuntu /bin/bash {job}\n"
            )
            self.assertEqual(
                self._schedule_fixture(root, set()),
                ["PAID_RECURRING_CRON_LAUNCHER"],
            )

    def test_active_systemd_codex_remote_timer_alerted(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/"systemd").mkdir()
            (root/"systemd"/"ai.timer").write_text(
                "[Timer]\nOnCalendar=hourly\n")
            (root/"systemd"/"ai.service").write_text(
                "[Service]\nExecStart=/usr/bin/codex exec --remote --model paid\n")
            self.assertEqual(
                self._schedule_fixture(root, {"ai.timer"}),
                ["PAID_RECURRING_SYSTEMD_LAUNCHER"],
            )
            self.assertEqual(self._schedule_fixture(root, set()), [])

    def test_systemd_timer_unit_override_and_nested_python(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/"systemd").mkdir();(root/"scripts").mkdir()
            (root/"systemd"/"watch.timer").write_text(
                "[Timer]\nOnCalendar=daily\nUnit=actual.service\n")
            (root/"systemd"/"actual.service").write_text(
                f"[Service]\nExecStart=/usr/bin/python3 {root}/scripts/job.py\n")
            (root/"scripts"/"job.py").write_text(
                "import subprocess\nsubprocess.run(['claude','-p','do work'])\n")
            self.assertEqual(
                self._schedule_fixture(root, {"watch.timer"}),
                ["PAID_RECURRING_SYSTEMD_LAUNCHER"],
            )

    def test_readonly_sentinel_timer_exemption_is_exact_and_narrow(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d); (root/"systemd").mkdir()
            (root/"systemd"/"shopvivaliz-ai-free-guard.timer").write_text(
                "[Timer]\nOnUnitActiveSec=5min\n")
            service=root/"systemd"/"shopvivaliz-ai-free-guard.service"
            service.write_text(
                "[Service]\nExecStart=/usr/bin/python3 "
                "/usr/local/lib/shopvivaliz/ai-free-provider-sentinel.py\n"
            )
            self.assertEqual(
                self._schedule_fixture(root, {"shopvivaliz-ai-free-guard.timer"}), []
            )
            service.write_text(service.read_text() +
                "ExecStartPre=/usr/bin/claude -p unauthorized\n")
            self.assertEqual(
                self._schedule_fixture(root, {"shopvivaliz-ai-free-guard.timer"}),
                ["PAID_RECURRING_SYSTEMD_LAUNCHER"],
            )

    def test_idle_claude_daemon_and_free_schedule_stay_healthy(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/"systemd").mkdir();(root/"cron.d").mkdir()
            (root/"systemd"/"local.timer").write_text(
                "[Timer]\nOnCalendar=hourly\n")
            (root/"systemd"/"local.service").write_text(
                "[Service]\nExecStart=/usr/bin/ollama list\n")
            (root/"systemd"/"remote-control.service").write_text(
                "[Service]\nExecStart=/usr/bin/claude remote-control\n")
            (root/"cron.d"/"health").write_text(
                "*/10 * * * * ubuntu curl -fsS http://127.0.0.1:11434/api/tags\n")
            self.assertEqual(self._schedule_fixture(root, {"local.timer"}), [])

    def test_scan_never_reports_sensitive_script_contents(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/"cron.d").mkdir()
            (root/"cron.d"/"private").write_text(
                "# secret fixture must not appear in alert\n"
                "*/5 * * * * ubuntu claude -p 'PRIVATE_FIXTURE_SECRET'\n")
            issues=self._schedule_fixture(root,set())
            self.assertIn("PAID_RECURRING_CRON_LAUNCHER", issues)
            self.assertNotIn("PRIVATE_FIXTURE_SECRET", str(issues))

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

    def test_codex_cache_skips_unchanged_sqlite_and_detects_updates(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            folder = root / "profile"
            folder.mkdir()
            db = folder / "state_5.sqlite"
            con = sqlite3.connect(db)
            con.execute("CREATE TABLE threads(tokens_used INTEGER)")
            con.execute("INSERT INTO threads VALUES (5)")
            con.commit()
            with patch.object(s, "codex_counter", wraps=s.codex_counter) as read:
                counters, errors, fingerprints = s.codex_snapshot_cached({}, root=root)
                self.assertEqual(errors, [])
                self.assertEqual(read.call_count, 1)
                previous = {
                    "codex_counters": counters,
                    "codex_fingerprints": fingerprints,
                }
                same, errors, fingerprints2 = s.codex_snapshot_cached(previous, root=root)
                self.assertEqual((same, errors, fingerprints2),
                                 (counters, [], fingerprints))
                self.assertEqual(read.call_count, 1, "unchanged DB must not be rescanned")
                con.execute("UPDATE threads SET tokens_used=17")
                con.commit()
                # Nanosecond mtime/ctime ensures the data fingerprint changes.
                st = db.stat()
                import os
                os.utime(db, ns=(st.st_atime_ns, st.st_mtime_ns + 1000000))
                changed, errors, fingerprints3 = s.codex_snapshot_cached(previous, root=root)
                self.assertEqual(errors, [])
                self.assertEqual(read.call_count, 2)
                self.assertNotEqual(fingerprints, fingerprints3)
                self.assertEqual(next(iter(changed.values())), [17, 1])
                self.assertEqual(
                    s.compare_counters(previous, changed),
                    ["CODEX_TOKEN_COUNTER_INCREASE"],
                )
            con.close()

    def test_codex_cache_detects_wal_content_change(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            folder = root / "profile"
            folder.mkdir()
            db = folder / "state_5.sqlite"
            con = sqlite3.connect(db)
            con.execute("PRAGMA journal_mode=WAL")
            con.execute("CREATE TABLE threads(tokens_used INTEGER)")
            con.execute("INSERT INTO threads VALUES (3)")
            con.commit()
            previous_counts, errors, previous_fps = s.codex_snapshot_cached({}, root=root)
            self.assertEqual(errors, [])
            con.execute("INSERT INTO threads VALUES (7)")
            con.commit()
            changed, errors, changed_fps = s.codex_snapshot_cached(
                {"codex_counters": previous_counts, "codex_fingerprints": previous_fps},
                root=root,
            )
            self.assertEqual(errors, [])
            self.assertNotEqual(previous_fps, changed_fps)
            self.assertEqual(next(iter(changed.values())), [10, 2])
            con.close()

    def test_claude_scan_budget_incomplete_never_claims_complete(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            project = root / "project"
            project.mkdir()
            (project / "session.jsonl").write_text('{"type":"other"}\n')
            count, complete = s.recent_claude_usage_bounded(
                100, 200, root=root, budget_seconds=0.0
            )
            self.assertEqual(count, 0)
            self.assertFalse(complete)
            count, complete = s.recent_claude_usage_bounded(
                100, 200, root=root, budget_seconds=5.0
            )
            self.assertEqual((count, complete), (0, True))

    def test_incomplete_claude_scan_retains_old_watermark_and_alerts(self):
        with tempfile.TemporaryDirectory() as d:
            state = Path(d)
            s.store_state(state / "state.json", {
                "last_checked_epoch": 1000,
                "codex_counters": {},
                "notified_fingerprint": "",
            })
            with (patch.object(s, "policy_anomalies", side_effect=[[], []]),
                  patch.object(s, "proc_potential_paid", return_value=[]),
                  patch.object(s, "service_pid", return_value=0),
                  patch.object(s, "codex_snapshot_cached", return_value=({}, [], {})),
                  patch.object(s, "scheduled_paid_launchers", return_value=[]),
                  patch.object(s, "recent_claude_usage_bounded",
                               side_effect=[(0, False), (0, True)]) as scan,
                  patch.object(s, "github_comment") as notify):
                first, _ = s.run(state_dir=state, do_notify=False, now=1300)
                self.assertIn("CLAUDE_USAGE_SCAN_INCOMPLETE", first)
                incomplete = s.load_state(state / "state.json")
                self.assertEqual(incomplete["last_status"], "ALERT")
                self.assertEqual(incomplete["claude_scan_since_epoch"], 1000)
                second, _ = s.run(state_dir=state, do_notify=False, now=1600)
                self.assertEqual(second, [])
                completed = s.load_state(state / "state.json")
                self.assertEqual(completed["last_status"], "HEALTHY")
                self.assertEqual(completed["claude_scan_since_epoch"], 1600)
                self.assertEqual(scan.call_args_list[0].args, (1000.0, 1300))
                self.assertEqual(scan.call_args_list[1].args, (1000.0, 1600))
                notify.assert_not_called()

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
                  patch.object(s,"codex_snapshot_cached",return_value=({},[],{})),
                  patch.object(s,"recent_claude_usage_bounded",return_value=(0,True)),
                  patch.object(s,"scheduled_paid_launchers",return_value=[]),
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
                  patch.object(s,"codex_snapshot_cached",return_value=({},[],{})),
                  patch.object(s,"recent_claude_usage_bounded",return_value=(0,True)),
                  patch.object(s,"scheduled_paid_launchers",return_value=[]),
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
        self.assertIn("CPUQuota=75%", unit)
        self.assertIn("Nice=0", unit)
        self.assertIn("IOSchedulingClass=best-effort", unit)
        self.assertIn("IOSchedulingPriority=4", unit)
        self.assertIn("TimeoutStartSec=90", unit)
        self.assertIn("systemctl enable --now shopvivaliz-ai-free-guard.timer",installer)


if __name__=="__main__":
    unittest.main()
