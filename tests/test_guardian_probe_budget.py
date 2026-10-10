"""Real guardian subprocess tests with local HTTP; GUI/systemd are isolated.

Removing the negative-state cache must make repeated-probe tests fail.
Removing the readiness check must allow an unauthenticated fixture and fail.
No external account, browser profile or service is touched by this suite.
"""
from __future__ import annotations
import json
import os
from pathlib import Path
import subprocess
import tempfile
import threading
import unittest
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = Path(__file__).resolve().parents[1]
GUARDIAN = ROOT / 'scripts/chatgpt-continuity/chatgpt-browser-guardian.sh'
INSTALLER = ROOT / 'scripts/install-chatgpt-continuity-backend-bridge.sh'

class GuardianProbeBudgetTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.tasks = self.root / 'tasks'
        self.tasks.mkdir()
        self.health = self.root / 'health.json'
        self.node_log = self.root / 'node.log'
        self.system_log = self.root / 'system.log'
        self.session_file = self.root / 'session.txt'
        self.session_file.write_text('AUTH_TERMINAL')
        self.page_url = 'https://auth.openai.com/email-verification?state=not-to-be-stored'
        self.browser_id = 'fixture-browser'
        self.transport_up = True
        owner = self
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass
            def do_GET(self):
                if not owner.transport_up:
                    self.send_response(503)
                    self.end_headers()
                    return
                payload = ({'webSocketDebuggerUrl': 'ws://127.0.0.1/' + owner.browser_id}
                           if self.path == '/json/version' else
                           [{'type': 'page', 'id': 'fixture-page', 'url': owner.page_url}])
                raw = json.dumps(payload).encode()
                self.send_response(200)
                self.send_header('Content-Length', str(len(raw)))
                self.end_headers()
                self.wfile.write(raw)
        self.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.env = os.environ.copy()
        self.env.update({
            'PATH': str(self.bin) + ':' + self.env.get('PATH', ''),
            'CHATGPT_BROWSER_CDP_BASE': 'http://127.0.0.1:' + str(self.server.server_port),
            'CHATGPT_BROWSER_CDP_URL': 'http://127.0.0.1:' + str(self.server.server_port) + '/json/version',
            'CHATGPT_BROWSER_HEALTH_FILE': str(self.health),
            'SHOPVIVALIZ_AGENT_TASK_STATE_DIR': str(self.tasks),
            'FIXTURE_NODE_LOG': str(self.node_log),
            'FIXTURE_SYSTEM_LOG': str(self.system_log),
            'FIXTURE_SESSION_FILE': str(self.session_file),
        })
        for key in ['CHATGPT_BROWSER_FORCE_SESSION_PROBE', 'CHATGPT_BROWSER_PROBE_CACHE_HELPER']:
            self.env.pop(key, None)
        self.executable('node', '''printf 'probe\n' >> "$FIXTURE_NODE_LOG"
if [[ "$*" == *CONTINUITY_BROWSER_SESSION_STATE_PROBE* ]]; then cat "$FIXTURE_SESSION_FILE"; printf '\n'; fi
''')
        self.executable('pgrep', "printf '424242\\n'\n")
        self.executable('sleep', 'exit 0\n')
        self.executable('systemctl', 'printf "%s\\n" "$*" >> "$FIXTURE_SYSTEM_LOG"; exit 0\n')

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=2)
        self.tmp.cleanup()

    def executable(self, name, body):
        p = self.bin / name
        p.write_text('#!/usr/bin/env bash\nset -eu\n' + body)
        p.chmod(0o755)

    def run_guardian(self, *, force=False):
        env = self.env.copy()
        if force:
            env['CHATGPT_BROWSER_FORCE_SESSION_PROBE'] = '1'
        return subprocess.run(['bash', str(GUARDIAN)], env=env, capture_output=True, text=True, timeout=15)

    def probes(self):
        return len(self.node_log.read_text().splitlines()) if self.node_log.exists() else 0

    def assert_cache_skip(self):
        before = self.probes()
        result = self.run_guardian()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.probes(), before, 'unchanged negative auth must not run Node probes')
        data = json.loads(self.health.read_text())
        self.assertTrue(data.get('probe_skipped'))
        self.assertFalse(data['authenticated'])
        self.assertIn('session_observed_at', data)
        return data

    def test_terminal_auth_repeat_skips_expensive_probes(self):
        first = self.run_guardian()
        self.assertEqual(first.returncode, 0, first.stderr)
        observed = json.loads(self.health.read_text()).get('session_observed_at')
        self.assertIsNotNone(observed, 'health must separate observation time from heartbeat')
        data = self.assert_cache_skip()
        self.assertEqual(data['session_observed_at'], observed)
        self.assertNotIn('not-to-be-stored', self.health.read_text())

    def test_auth_flow_is_not_an_execution_failure_and_is_throttled(self):
        self.session_file.write_text('AUTH_FLOW')
        first = self.run_guardian()
        self.assertEqual(first.returncode, 0, first.stderr)
        self.assertEqual(json.loads(self.health.read_text())['session_state'], 'AUTH_FLOW')
        self.assert_cache_skip()

    def test_logged_out_is_not_an_execution_failure_and_is_throttled(self):
        self.session_file.write_text('LOGGED_OUT')
        first = self.run_guardian()
        self.assertEqual(first.returncode, 0, first.stderr)
        self.assert_cache_skip()

    def test_wrong_account_fails_closed_and_cached_mismatch_stays_failed(self):
        self.session_file.write_text('IDENTITY_MISMATCH')
        first = self.run_guardian()
        self.assertNotEqual(first.returncode, 0)
        self.assertIn('DEGRADED_IDENTITY_MISMATCH', first.stdout)
        data = json.loads(self.health.read_text())
        self.assertEqual(data['session_state'], 'IDENTITY_MISMATCH')
        self.assertFalse(data['authenticated'])
        before = self.probes()
        second = self.run_guardian()
        self.assertNotEqual(second.returncode, 0)
        self.assertEqual(self.probes(), before)
        self.assertIn('QUIESCENT_AUTH_CACHE', second.stdout)

    def test_changed_page_resumes_probe_and_can_observe_authentication(self):
        self.run_guardian()
        before = self.probes()
        self.page_url = 'https://chatgpt.com/c/fixture'
        self.session_file.write_text('AUTHENTICATED')
        result = self.run_guardian()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertGreater(self.probes(), before)
        self.assertTrue(json.loads(self.health.read_text())['authenticated'])

    def test_new_checkpoint_wakes_once_not_on_progress_text(self):
        self.run_guardian()
        before = self.probes()
        p = self.tasks / 'fixture-task.json'
        p.write_text(json.dumps({'task_id': 'fixture-task', 'status': 'RUNNING', 'next_action': 'one'}))
        self.run_guardian()
        self.assertGreater(self.probes(), before)
        p.write_text(json.dumps({'task_id': 'fixture-task', 'status': 'RUNNING', 'next_action': 'two'}))
        self.assert_cache_skip()

    def test_expired_negative_observation_is_reprobed(self):
        self.run_guardian()
        d = json.loads(self.health.read_text())
        self.assertIn('session_observed_at', d)
        d['session_observed_at'] = (datetime.now(timezone.utc) - timedelta(seconds=301)).isoformat()
        self.health.write_text(json.dumps(d))
        before = self.probes()
        self.run_guardian()
        self.assertGreater(self.probes(), before)

    def test_future_observation_does_not_extend_wait_forever(self):
        self.run_guardian()
        d = json.loads(self.health.read_text())
        d['session_observed_at'] = (datetime.now(timezone.utc) + timedelta(days=1)).isoformat()
        self.health.write_text(json.dumps(d))
        before = self.probes()
        self.run_guardian()
        self.assertGreater(self.probes(), before)

    def test_force_probe_bypasses_negative_cache(self):
        self.run_guardian()
        self.assert_cache_skip()
        before = self.probes()
        self.run_guardian(force=True)
        self.assertGreater(self.probes(), before)

    def test_positive_state_is_never_reused_as_fresh_authentication(self):
        self.session_file.write_text('AUTHENTICATED')
        self.run_guardian()
        before = self.probes()
        self.run_guardian()
        self.assertGreater(self.probes(), before)

    def test_healthy_browser_recovers_stopped_continuity_worker(self):
        self.session_file.write_text('AUTHENTICATED')
        worker_state = self.root / 'worker.active'
        self.executable(
            'systemctl',
            f"""printf '%s\\n' "$*" >> "$FIXTURE_SYSTEM_LOG"
if [[ "$*" == *"--user --machine=ubuntu@ is-active --quiet shopvivaliz-chatgpt-continuity.service"* ]]; then
  [[ -f "{worker_state}" ]] && exit 0 || exit 3
fi
if [[ "$*" == *"--user --machine=ubuntu@ start shopvivaliz-chatgpt-continuity.service"* ]]; then
  : > "{worker_state}"
  exit 0
fi
exit 0
""",
        )
        result = self.run_guardian()
        self.assertEqual(result.returncode, 0, result.stderr)
        calls = self.system_log.read_text() if self.system_log.exists() else ''
        self.assertIn('--user --machine=ubuntu@ start shopvivaliz-chatgpt-continuity.service', calls)
        self.assertTrue(worker_state.exists())

    def test_healthy_browser_does_not_restart_active_continuity_worker(self):
        self.session_file.write_text('AUTHENTICATED')
        worker_state = self.root / 'worker.active'
        worker_state.write_text('active')
        self.executable(
            'systemctl',
            f"""printf '%s\\n' "$*" >> "$FIXTURE_SYSTEM_LOG"
if [[ "$*" == *"--user --machine=ubuntu@ is-active --quiet shopvivaliz-chatgpt-continuity.service"* ]]; then
  [[ -f "{worker_state}" ]] && exit 0 || exit 3
fi
if [[ "$*" == *"--user --machine=ubuntu@ start shopvivaliz-chatgpt-continuity.service"* ]]; then
  : > "{worker_state}"
  exit 0
fi
exit 0
""",
        )
        result = self.run_guardian()
        self.assertEqual(result.returncode, 0, result.stderr)
        calls = self.system_log.read_text() if self.system_log.exists() else ''
        self.assertNotIn('--user --machine=ubuntu@ start shopvivaliz-chatgpt-continuity.service', calls)

    def test_failed_transport_is_not_hidden_by_negative_cache(self):
        self.run_guardian()
        self.assert_cache_skip()
        self.transport_up = False
        result = self.run_guardian()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('restart shopvivaliz-dev-browser.service', self.system_log.read_text())

    def test_corrupt_cache_forces_real_probe(self):
        self.run_guardian()
        self.health.write_text('{broken')
        before = self.probes()
        self.run_guardian()
        self.assertGreater(self.probes(), before)

    def test_unknown_state_remains_failed_and_is_never_cached(self):
        self.session_file.write_text('UNKNOWN')
        self.assertNotEqual(self.run_guardian().returncode, 0)
        before = self.probes()
        self.assertNotEqual(self.run_guardian().returncode, 0)
        self.assertGreater(self.probes(), before)

    def test_fresh_authentication_passes_the_readiness_gate(self):
        self.session_file.write_text('AUTHENTICATED')
        self.assertEqual(self.run_guardian().returncode, 0)
        helper = ROOT / 'scripts/chatgpt-continuity/chatgpt-browser-probe-cache.py'
        result = subprocess.run(['python3', str(helper), 'verify-ready', '--health', str(self.health)],
                                capture_output=True, text=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('BACKEND_READY=PASS', result.stdout)

    def test_stale_authentication_cannot_pass_readiness(self):
        self.session_file.write_text('AUTHENTICATED')
        self.run_guardian()
        data = json.loads(self.health.read_text())
        data['session_observed_at'] = (datetime.now(timezone.utc) - timedelta(seconds=91)).isoformat()
        self.health.write_text(json.dumps(data))
        helper = ROOT / 'scripts/chatgpt-continuity/chatgpt-browser-probe-cache.py'
        result = subprocess.run(['python3', str(helper), 'verify-ready', '--health', str(self.health)],
                                capture_output=True, text=True, timeout=5)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('BACKEND_READY=FAIL', result.stdout)

    def test_bootstrap_routes_include_the_new_helper(self):
        for rel in ['.github/workflows/oci-bastion-private-access-bootstrap.yml',
                    '.github/workflows/shopvivaliz-remote-access.yml']:
            with self.subTest(route=rel):
                self.assertIn('scripts/chatgpt-continuity/chatgpt-browser-probe-cache.py', (ROOT / rel).read_text())

    def test_installer_readiness_gate_rejects_unauthenticated_health(self):
        text = INSTALLER.read_text()
        marker = '# AUTH_SESSION_READINESS_GATE\n'
        self.assertTrue(marker in text, 'service exit 0 alone must not mean authenticated readiness')
        gate = text.split(marker, 1)[1]
        self.run_guardian()
        env = self.env.copy()
        helper = ROOT / 'scripts/chatgpt-continuity/chatgpt-browser-probe-cache.py'
        prefix = 'set -eu\nprobe_cache_helper=' + str(helper) + '\nbrowser_health_file=' + str(self.health) + '\n'
        result = subprocess.run(['bash', '-c', prefix + gate], env=env, capture_output=True, text=True, timeout=10)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('BACKEND_READY=PASS', result.stdout)

if __name__ == '__main__':
    unittest.main()
