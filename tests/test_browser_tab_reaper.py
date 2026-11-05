import importlib.util
import json
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "browser_tab_reaper.py"
spec = importlib.util.spec_from_file_location("browser_tab_reaper", SCRIPT)
reaper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(reaper)

HOUR = 3600.0


class FakeCdp:
    """Loopback stand-in for a browser remote-debugging HTTP endpoint."""

    def __init__(self, tabs):
        self.tabs = list(tabs)
        self.closed = []
        outer = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_GET(self):
                if self.path == "/json/list":
                    body = json.dumps(outer.tabs).encode()
                elif self.path.startswith("/json/close/"):
                    target = self.path.rsplit("/", 1)[1]
                    outer.closed.append(target)
                    outer.tabs = [t for t in outer.tabs if t["id"] != target]
                    body = b"Target is closing"
                else:
                    self.send_response(404)
                    self.end_headers()
                    return
                self.send_response(200)
                self.end_headers()
                self.wfile.write(body)

        self.server = HTTPServer(("127.0.0.1", 0), Handler)
        self.port = self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def stop(self):
        self.server.shutdown()
        self.server.server_close()


def page(tab_id, url, title="t"):
    return {"id": tab_id, "type": "page", "url": url, "title": title}


class BrowserTabReaperTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.state_dir = self.root / "task-state"
        self.state_dir.mkdir()
        self.state_file = self.root / "reaper.json"
        self.servers = []

    def tearDown(self):
        for server in self.servers:
            server.stop()
        self.tmp.cleanup()

    def cdp(self, tabs):
        server = FakeCdp(tabs)
        self.servers.append(server)
        return server

    def task(self, name, conversation, status, session=None):
        payload = {"conversation_id": conversation, "status": status}
        if session:
            payload["browser_session"] = session
        (self.state_dir / f"{name}.json").write_text(json.dumps(payload), encoding="utf-8")

    def run_reaper(self, server, now, *extra):
        args = ["--browser", f"dev={server.port}", "--state-dir", str(self.state_dir),
                "--state-file", str(self.state_file), *extra]
        import contextlib
        import io
        buffer = io.StringIO()
        with contextlib.redirect_stdout(buffer):
            code = reaper.main(args, now=now)
        report = json.loads(buffer.getvalue().splitlines()[0])
        return code, report["browsers"][0], report

    def test_dry_run_never_closes(self):
        server = self.cdp([page("AAAAAAAA", "https://example.com/a"), page("BBBBBBBB", "https://example.com/b")])
        self.run_reaper(server, 0.0)
        code, browser, _ = self.run_reaper(server, 30 * HOUR)
        self.assertEqual(code, 0)
        self.assertEqual(browser["would_close"], 1)
        self.assertEqual(server.closed, [])

    def test_first_observation_starts_clock_and_closes_nothing_even_with_apply(self):
        server = self.cdp([page("AAAAAAAA", "https://example.com/a"), page("BBBBBBBB", "https://example.com/b")])
        _, browser, _ = self.run_reaper(server, 1000 * HOUR, "--apply")
        self.assertEqual(browser["closed"], 0)
        self.assertEqual(server.closed, [])

    def test_closes_only_idle_unprotected_and_keeps_minimum(self):
        server = self.cdp([
            page("IDLETAB1", "https://example.com/old"),
            page("AUTHTAB1", "https://auth.openai.com/log-in"),
            page("INTERNAL", "chrome://settings"),
            page("BOUNDTAB", "https://chatgpt.com/c/active-conversation-1"),
            page("ABANDON1", "https://chatgpt.com/c/finished-conversation-9"),
        ])
        self.task("live", "active-conversation-1", "RUNNING")
        self.task("done", "finished-conversation-9", "CONCLUIDO")
        self.run_reaper(server, 0.0)
        _, browser, _ = self.run_reaper(server, 25 * HOUR, "--apply")
        self.assertEqual(sorted(server.closed), ["ABANDON1", "IDLETAB1"])
        self.assertEqual(browser["protected"], {"login_page": 1, "internal_page": 1, "bound_to_active_task": 1})
        self.assertEqual(len(server.tabs), 3)

    def test_never_closes_last_tab(self):
        server = self.cdp([page("ONLYTAB1", "https://example.com/only")])
        self.run_reaper(server, 0.0)
        _, browser, _ = self.run_reaper(server, 99 * HOUR, "--apply")
        self.assertEqual(server.closed, [])
        self.assertEqual(browser["closed"], 0)

    def test_changed_title_resets_idle_clock(self):
        server = self.cdp([page("TABAAAAA", "https://example.com/a", "one"), page("TABBBBBB", "https://example.com/b")])
        self.run_reaper(server, 0.0)
        server.tabs[0]["title"] = "two"
        _, browser, _ = self.run_reaper(server, 25 * HOUR, "--apply")
        self.assertEqual(server.closed, ["TABBBBBB"])
        self.assertEqual(browser["closed"], 1)

    def test_unreadable_task_state_protects_chatgpt_tabs_and_fails_closed(self):
        missing = self.root / "missing"
        server = self.cdp([page("GPTTAB01", "https://chatgpt.com/c/some-conversation-1"),
                           page("GPTTAB02", "https://chatgpt.com/c/some-conversation-2"),
                           page("OTHER001", "https://example.com/x")])
        args = ["--browser", f"dev={server.port}", "--state-dir", str(missing),
                "--state-file", str(self.state_file)]
        reaper.main(args, now=0.0)
        import contextlib
        import io
        with contextlib.redirect_stdout(io.StringIO()):
            code = reaper.main(args + ["--apply"], now=99 * HOUR)
        self.assertEqual(code, 3)
        self.assertEqual(server.closed, [])

    def test_corrupt_task_state_file_fails_closed(self):
        (self.state_dir / "broken.json").write_text("{not json", encoding="utf-8")
        self.assertIsNone(reaper.load_active_conversations(self.state_dir))

    def test_max_closes_is_honoured(self):
        tabs = [page(f"TAB{i:05d}", f"https://example.com/{i}") for i in range(6)]
        server = self.cdp(tabs)
        self.run_reaper(server, 0.0)
        _, browser, _ = self.run_reaper(server, 30 * HOUR, "--apply", "--max-closes", "2")
        self.assertEqual(len(server.closed), 2)
        self.assertEqual(browser["closed"], 2)

    def test_report_has_no_urls_or_conversation_ids(self):
        server = self.cdp([page("TABAAAAA", "https://chatgpt.com/c/secret-conversation-id"),
                           page("TABBBBBB", "https://example.com/private/path?token=abc")])
        self.run_reaper(server, 0.0)
        import contextlib
        import io
        buffer = io.StringIO()
        with contextlib.redirect_stdout(buffer):
            reaper.main(["--browser", f"dev={server.port}", "--state-dir", str(self.state_dir),
                         "--state-file", str(self.state_file)], now=30 * HOUR)
        text = buffer.getvalue()
        self.assertNotIn("secret-conversation-id", text)
        self.assertNotIn("token=abc", text)
        self.assertNotIn("/private/path", text)

    def test_unreachable_browser_is_reported_not_fatal(self):
        dead = FakeCdp([])
        dead.stop()
        code, browser, _ = self.run_reaper(dead, 0.0)
        self.assertEqual(code, 0)
        self.assertEqual(browser["status"], "UNREACHABLE")

    def test_state_file_is_private(self):
        server = self.cdp([page("TABAAAAA", "https://example.com/a")])
        self.run_reaper(server, 0.0)
        self.assertEqual(self.state_file.stat().st_mode & 0o777, 0o600)

    def test_active_task_on_this_browser_protects_non_chatgpt_tabs_only(self):
        # A form or SPA keeps URL and title unchanged while a task types in it.
        server = self.cdp([
            page("FORMTAB1", "https://admin.example.com/orders/edit"),
            page("GPTOLD01", "https://chatgpt.com/c/unbound-old-conversation-1"),
            page("GPTOLD02", "https://chatgpt.com/c/unbound-old-conversation-2"),
        ])
        self.task("live", "some-other-conversation", "RUNNING", session="dev")
        self.run_reaper(server, 0.0)
        _, browser, _ = self.run_reaper(server, 5 * HOUR, "--apply")
        self.assertEqual([t["id"] for t in server.tabs], ["FORMTAB1"])
        self.assertEqual(browser["protected"], {"browser_in_use_by_active_task": 1})
        self.assertEqual(sorted(server.closed), ["GPTOLD01", "GPTOLD02"])

    def age_task(self, name, hours):
        import os
        import time
        stamp = time.time() - hours * HOUR
        os.utime(self.state_dir / f"{name}.json", (stamp, stamp))

    def test_abandoned_running_checkpoint_does_not_keep_browser_busy_forever(self):
        server = self.cdp([page("FORMTAB1", "https://admin.example.com/a"),
                           page("FORMTAB2", "https://admin.example.com/b")])
        self.task("abandoned", "dead-conversation-1", "RUNNING", session="dev")
        self.age_task("abandoned", 10)  # default freshness window is 6 h
        self.run_reaper(server, 0.0)
        _, browser, _ = self.run_reaper(server, 5 * HOUR, "--apply")
        self.assertEqual(browser["closed"], 1)
        self.assertNotIn("browser_in_use_by_active_task", browser["protected"])

    def test_task_fresh_window_is_configurable_and_boundary_protects(self):
        server = self.cdp([page("FORMTAB1", "https://admin.example.com/a"),
                           page("FORMTAB2", "https://admin.example.com/b")])
        self.task("slow", "slow-conversation-1", "RUNNING", session="dev")
        self.age_task("slow", 10)
        self.run_reaper(server, 0.0)
        _, browser, _ = self.run_reaper(server, 5 * HOUR, "--apply", "--task-fresh-hours", "24")
        self.assertEqual(browser["closed"], 0)
        self.assertEqual(browser["protected"], {"browser_in_use_by_active_task": 2})

    def test_stale_task_still_protects_its_own_bound_conversation_tab(self):
        server = self.cdp([page("BOUNDTAB", "https://chatgpt.com/c/old-but-running-conv"),
                           page("OTHER001", "https://example.com/x"),
                           page("OTHER002", "https://example.com/y")])
        self.task("old", "old-but-running-conv", "RUNNING", session="dev")
        self.age_task("old", 72)
        self.run_reaper(server, 0.0)
        self.run_reaper(server, 5 * HOUR, "--apply")
        self.assertIn("BOUNDTAB", [t["id"] for t in server.tabs])

    def test_task_fresh_hours_must_be_at_least_a_quarter_hour(self):
        self.assertEqual(reaper.main(["--task-fresh-hours", "0.1"]), 2)

    def test_task_bound_to_another_browser_does_not_protect_this_one(self):
        server = self.cdp([page("TABAAAAA", "https://example.com/a"), page("TABBBBBB", "https://example.com/b")])
        self.task("live", "conversation-on-atendimento", "RUNNING", session="atendimento")
        self.run_reaper(server, 0.0)
        _, browser, _ = self.run_reaper(server, 5 * HOUR, "--apply")
        self.assertEqual(browser["closed"], 1)
        self.assertEqual(browser["protected"], {})

    def test_terminal_task_session_does_not_protect_tabs(self):
        server = self.cdp([page("TABAAAAA", "https://example.com/a"), page("TABBBBBB", "https://example.com/b")])
        self.task("done", "finished-conversation-1", "CONCLUIDO", session="dev")
        self.run_reaper(server, 0.0)
        _, browser, _ = self.run_reaper(server, 5 * HOUR, "--apply")
        self.assertEqual(browser["closed"], 1)

    def test_dev_aux_follows_the_dev_session(self):
        self.assertTrue(reaper.browser_busy("dev-aux", {"dev"}))
        self.assertFalse(reaper.browser_busy("dev-aux", {"atendimento"}))
        self.assertTrue(reaper.browser_busy("custom", {"atendimento"}))
        self.assertFalse(reaper.browser_busy("custom", set()))

    def test_defaults_converge_to_single_most_recent_tab(self):
        tabs = [page(f"TAB{i:05d}", f"https://chatgpt.com/c/old-conversation-{i}") for i in range(5)]
        server = self.cdp(tabs)
        self.run_reaper(server, 0.0)
        server.tabs[4]["title"] = "recent"
        _, browser, _ = self.run_reaper(server, 2 * HOUR, "--apply")
        self.assertEqual(browser["closed"], 4)
        self.assertEqual([t["id"] for t in server.tabs], ["TAB00004"])

    def test_default_idle_grace_is_one_hour(self):
        server = self.cdp([page("TABAAAAA", "https://example.com/a"), page("TABBBBBB", "https://example.com/b")])
        self.run_reaper(server, 0.0)
        _, browser, _ = self.run_reaper(server, 0.5 * HOUR, "--apply")
        self.assertEqual(browser["closed"], 0)
        _, browser, _ = self.run_reaper(server, 1.01 * HOUR, "--apply")
        self.assertEqual(browser["closed"], 1)
        self.assertEqual(len(server.tabs), 1)

    def test_active_task_tabs_and_login_survive_total_cleanup(self):
        server = self.cdp([
            page("BOUNDTAB", "https://chatgpt.com/c/in-progress-conversation"),
            page("LOGINTAB", "https://accounts.google.com/signin"),
            page("STALE001", "https://example.com/1"),
            page("STALE002", "https://example.com/2"),
        ])
        self.task("live", "in-progress-conversation", "READY_TO_COMPLETE")
        self.run_reaper(server, 0.0)
        _, browser, _ = self.run_reaper(server, 3 * HOUR, "--apply")
        self.assertEqual(sorted(t["id"] for t in server.tabs), ["BOUNDTAB", "LOGINTAB"])
        self.assertEqual(browser["closed"], 2)

    def test_apply_env_must_be_exactly_one(self):
        import os
        from unittest import mock
        server = self.cdp([page("TABAAAAA", "https://example.com/a"), page("TABBBBBB", "https://example.com/b")])
        self.run_reaper(server, 0.0)
        with mock.patch.dict(os.environ, {"SHOPVIVALIZ_TAB_REAPER_APPLY": "true"}):
            _, browser, report = self.run_reaper(server, 30 * HOUR)
        self.assertEqual((report["mode"], browser["closed"]), ("dry-run", 0))
        with mock.patch.dict(os.environ, {"SHOPVIVALIZ_TAB_REAPER_APPLY": "1"}):
            _, browser, report = self.run_reaper(server, 30 * HOUR)
        self.assertEqual((report["mode"], browser["closed"]), ("apply", 1))

    def test_invalid_browser_argument_is_usage_error(self):
        self.assertEqual(reaper.main(["--browser", "x=22"]), 2)
        self.assertEqual(reaper.main(["--idle-hours", "0"]), 2)


if __name__ == "__main__":
    unittest.main()
