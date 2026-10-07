from __future__ import annotations

import importlib.util
import json
import os
import subprocess
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "scripts"
import sys
sys.path.insert(0, str(SCRIPTS))

import agent_task_state as task_state
import task_continuation_watchdog as watchdog


def load_dispatcher():
    path = SCRIPTS / "chatgpt_continuity_nudge_dispatcher.py"
    spec = importlib.util.spec_from_file_location("chatgpt_continuity_nudge_dispatcher_backend_runtime_test", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load dispatcher")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class ChatgptContinuityBackendRuntimeTests(unittest.TestCase):
    def test_dispatcher_reads_protected_token_file_when_env_value_is_absent(self) -> None:
        dispatcher = load_dispatcher()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            token_file = root / "bridge.token"
            token_file.write_text("file-token-1234567890\n", encoding="utf-8")
            token_file.chmod(0o600)
            original_state_runtime = task_state.RUNTIME_DIR
            original_watchdog_runtime = watchdog.RUNTIME_DIR
            task_state.RUNTIME_DIR = root
            watchdog.RUNTIME_DIR = root
            try:
                task_state.start_task("task-1", "goal", "gpt")
                task_state.bind_conversation(
                    "task-1",
                    conversation_id="12345678-2222-3333-4444-555555555555",
                )
                task_state.record_progress("task-1", next_action="continue safely")
                state_path = root / "task-1.json"
                payload = json.loads(state_path.read_text(encoding="utf-8"))
                payload["updated_at"] = (
                    datetime.now(timezone.utc) - timedelta(minutes=5)
                ).replace(microsecond=0).isoformat().replace("+00:00", "Z")
                state_path.write_text(json.dumps(payload), encoding="utf-8")
                watchdog_result = watchdog.run_once(stale_seconds=1, runtime_dir=root)
                self.assertEqual(watchdog_result["dispatched"], 1, watchdog_result)
                self.assertEqual(len(watchdog.read_requests(root)), 1)
            finally:
                task_state.RUNTIME_DIR = original_state_runtime
                watchdog.RUNTIME_DIR = original_watchdog_runtime
            calls: list[dict] = []

            def fake_enqueue(**kwargs):
                calls.append(kwargs)
                return {"ok": True, "http_status": 200, "body": {"status": "OK"}}

            with patch.dict(
                os.environ,
                {
                    "CHATGPT_CONTINUITY_BRIDGE_TOKEN": "",
                    "CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE": str(token_file),
                },
                clear=False,
            ):
                result = dispatcher.run_once(
                    runtime_dir=root,
                    bridge_url="https://example.invalid/bridge.php",
                    token="",
                    enqueue=fake_enqueue,
                )

            self.assertEqual(result["dispatched"], 1, result)
            self.assertEqual(result["skipped_no_token"], 0)
            self.assertEqual(result["skipped_unbound"], 0)
            self.assertEqual(calls[0]["conversation_id"], "12345678-2222-3333-4444-555555555555")
            self.assertEqual(calls[0]["token"], "file-token-1234567890")
            self.assertNotIn("file-token-1234567890", json.dumps(result))

    def test_backend_installer_wires_single_durable_handoff_flag_default_on(self) -> None:
        body = (ROOT / "scripts" / "install-chatgpt-continuity-backend-bridge.sh").read_text(encoding="utf-8")
        self.assertIn('durable_handoff="${SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF:-1}"', body)
        self.assertIn('case "$durable_handoff" in 0|1)', body)
        self.assertIn('Environment=SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF=$durable_handoff', body)
        self.assertNotIn('rm -rf /home/ubuntu/shopvivaliz-deploy/shared/agent-task-state/_conversation-leases', body)
        self.assertNotIn('rm -rf /home/ubuntu/shopvivaliz-deploy/shared/agent-task-state/_runtime-lock', body)

    def test_backend_installer_is_vm_native_and_attaches_to_canonical_cdp(self) -> None:
        installer = ROOT / "scripts" / "install-chatgpt-continuity-backend-bridge.sh"
        self.assertTrue(installer.is_file(), "backend continuity installer must exist")
        body = installer.read_text(encoding="utf-8")
        self.assertIn("shopvivaliz-chatgpt-continuity.service", body)
        self.assertIn("http://127.0.0.1:9559", body)
        self.assertIn("/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token", body)
        self.assertIn("systemctl --user enable --now", body)
        self.assertIn(
            "Environment=SHOPVIVALIZ_AGENT_TASK_STATE_DIR=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state",
            body,
        )
        self.assertIn(
            "ReadWritePaths=$install_root $config_root /home/ubuntu/shopvivaliz-deploy/shared/agent-task-state",
            body,
        )
        self.assertIn("Environment=CHATGPT_CONTINUITY_STALL_MONITOR=0", body)
        self.assertNotIn("Environment=CHATGPT_CONTINUITY_STALL_MONITOR=1", body)
        self.assertNotIn("C:\\ShopVivaliz", body)
        self.assertIn("90-atendimento-cdp.conf", body)
        self.assertIn("90-atendimento-browser.conf", body)
        self.assertIn("rm -f", body)

    def test_backend_installer_persists_restart_intent_across_partial_failures(self) -> None:
        installer = ROOT / "scripts" / "install-chatgpt-continuity-backend-bridge.sh"
        body = installer.read_text(encoding="utf-8")
        self.assertIn("install_if_changed()", body)
        self.assertIn("worker_changed=false", body)
        self.assertIn("tunnel_unit_changed=false", body)
        self.assertIn("continuity_unit_changed=false", body)
        self.assertIn('restart_pending="$install_root/.continuity-restart-required"', body)
        self.assertIn('if [[ "$tunnel_unit_changed" = true ]]', body)
        self.assertIn('if [[ "$worker_changed" = true || "$continuity_unit_changed" = true ]]', body)
        self.assertIn(': > "$restart_pending"', body)
        self.assertIn('if [[ -f "$restart_pending" ]]; then', body)
        self.assertIn('systemctl --user try-restart "$unit"', body)
        self.assertIn('rm -f "$restart_pending"', body)
        self.assertLess(body.index(': > "$restart_pending"'), body.index('sudo -n systemctl start "$browser_guardian_service"'))
        self.assertLess(body.index('systemctl --user try-restart "$unit"'), body.index("continuity service is not active"))
        self.assertLess(body.index("continuity service is not active"), body.index('rm -f "$restart_pending"'))
        self.assertNotIn('systemctl --user restart "$tunnel_unit"\n', body)
        self.assertNotIn('systemctl --user restart "$unit"\n', body)


    def test_backend_installer_waits_for_canonical_cdp_readiness(self) -> None:
        installer = ROOT / "scripts" / "install-chatgpt-continuity-backend-bridge.sh"
        body = installer.read_text(encoding="utf-8")
        self.assertIn("cdp_ready=false", body)
        self.assertIn("for _ in $(seq 1 30); do", body)
        self.assertIn("--connect-timeout 1 --max-time 2", body)
        self.assertIn("cdp_ready", body)
        self.assertIn("CDP endpoint did not become ready", body)

    def test_remote_workflows_copy_canonical_browser_unit(self) -> None:
        for rel in [
            ".github/workflows/shopvivaliz-remote-access.yml",
            ".github/workflows/oci-bastion-private-access-bootstrap.yml",
        ]:
            body = (ROOT / rel).read_text(encoding="utf-8")
            self.assertIn("ops/systemd/shopvivaliz-dev-browser.service", body)
            self.assertNotIn("ops/systemd/shopvivaliz-atendimento-browser.service", body)

    def test_backend_installer_retires_legacy_browser_healthcheck(self) -> None:
        installer = ROOT / "scripts" / "install-chatgpt-continuity-backend-bridge.sh"
        body = installer.read_text(encoding="utf-8")
        self.assertIn("shopvivaliz-browser-healthcheck.timer", body)
        self.assertIn("shopvivaliz-browser-healthcheck.service", body)
        self.assertIn('sudo -n systemctl disable --now "$legacy_browser_healthcheck_timer"', body)
        self.assertIn('sudo -n systemctl stop "$legacy_browser_healthcheck_service"', body)
        self.assertNotIn('systemctl disable --now "$legacy_browser_healthcheck_timer" >/dev/null 2>&1 || true', body)
        self.assertNotIn('systemctl stop "$legacy_browser_healthcheck_service" >/dev/null 2>&1 || true', body)
        self.assertIn('sudo -n rm -f "$legacy_browser_healthcheck_timer_path"', body)
        self.assertIn('sudo -n rm -f "$legacy_browser_healthcheck_service_path"', body)
        self.assertIn('sudo -n rm -f "$legacy_browser_healthcheck_script"', body)

    def test_canonical_chatgpt_browser_is_supervised_by_systemd(self) -> None:
        installer = ROOT / "scripts" / "install-chatgpt-continuity-backend-bridge.sh"
        unit = ROOT / "ops" / "systemd" / "shopvivaliz-dev-browser.service"
        self.assertTrue(unit.is_file(), "canonical ChatGPT browser systemd unit must exist")
        body = unit.read_text(encoding="utf-8")
        self.assertIn("User=fredrdp", body)
        self.assertIn("Environment=HOME=/home/fredrdp", body)
        self.assertIn("Environment=DISPLAY=:99", body)
        self.assertIn("Environment=XAUTHORITY=/home/fredrdp/.Xauthority", body)
        self.assertIn("ExecStartPre=/usr/bin/test -x /usr/bin/dbus-run-session", body)
        self.assertIn("ExecStart=/usr/bin/dbus-run-session -- /opt/shopvivaliz-browser/chrome-linux/chrome", body)
        self.assertIn("PrivateTmp=true", body)
        self.assertIn("BindReadOnlyPaths=/tmp/.X11-unix", body)
        self.assertIn("--remote-debugging-port=9559", body)
        self.assertIn("--user-data-dir=/home/fredrdp/.config/shopvivaliz-dev-chromium", body)
        self.assertIn("Restart=always", body)
        self.assertIn("ExecStartPre=/usr/bin/test -S /tmp/.X11-unix/X99", body)
        install_body = installer.read_text(encoding="utf-8")
        self.assertIn("shopvivaliz-dev-browser.service", install_body)
        self.assertIn("sudo -n systemctl enable", install_body)
        self.assertIn("sudo_install_if_changed()", install_body)

    def test_chatgpt_browser_guardian_starts_managed_browser_without_killing_live_profile(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        service = ROOT / "ops" / "systemd" / "shopvivaliz-chatgpt-browser-guardian.service"
        timer = ROOT / "ops" / "systemd" / "shopvivaliz-chatgpt-browser-guardian.timer"
        installer = ROOT / "scripts" / "install-chatgpt-continuity-backend-bridge.sh"
        self.assertTrue(guardian.is_file())
        self.assertTrue(service.is_file())
        self.assertTrue(timer.is_file())
        guardian_body = guardian.read_text(encoding="utf-8")
        self.assertIn("http://127.0.0.1:9559/json/version", guardian_body)
        self.assertIn('curl -fsS --connect-timeout 2 --max-time 3 "$cdp_url" 2>/dev/null', guardian_body)
        self.assertIn("pgrep -u fredrdp", guardian_body)
        self.assertIn('systemctl start "$browser_unit"', guardian_body)
        self.assertIn('[[ "${#canonical_pids[@]}" -eq 1 ]]', guardian_body)
        self.assertIn('kill -TERM "$canonical_pid"', guardian_body)
        self.assertNotIn("pkill", guardian_body)
        self.assertNotIn("kill -KILL", guardian_body)
        # Cached observations and real recovery share the same final status.
        # Behavioral tests retain transport-failure coverage during deferral.
        self.assertIn('QUIESCENT_AUTH_CACHE', guardian_body)
        self.assertNotIn('exit 0', guardian_body)
        self.assertEqual(guardian_body.count('exit "$status"'), 1)
        timer_body = timer.read_text(encoding="utf-8")
        self.assertIn("OnUnitActiveSec=30s", timer_body)
        self.assertIn("AccuracySec=1s", timer_body)
        install_body = installer.read_text(encoding="utf-8")
        self.assertIn("shopvivaliz-chatgpt-browser-guardian.timer", install_body)
        self.assertIn('sudo -n systemctl enable --now "$browser_guardian_timer"', install_body)

    def test_chatgpt_browser_guardian_preserves_oauth_flow_without_restart(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        body = guardian.read_text(encoding="utf-8")
        self.assertIn("accounts.google.com", body)
        self.assertIn("login.microsoftonline.com", body)
        self.assertIn("auth.openai.com", body)
        self.assertIn("CONTINUITY_BROWSER_SESSION_STATE_PROBE", body)
        self.assertIn("_chatgpt-browser-health.json", body)

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            fake_bin = root / "bin"
            fake_bin.mkdir()
            systemctl_log = root / "systemctl.log"
            health_file = root / "browser-health.json"

            def executable(name: str, body: str) -> None:
                path = fake_bin / name
                path.write_text("#!/usr/bin/env bash\nset -Eeuo pipefail\n" + body, encoding="utf-8")
                path.chmod(0o755)

            executable("curl", "printf '{\\\"webSocketDebuggerUrl\\\":\\\"ws://127.0.0.1/test\\\"}'\n")
            executable(
                "node",
                'if [[ "$*" == *"CONTINUITY_BROWSER_SESSION_STATE_PROBE"* ]]; then printf "AUTH_FLOW\\n"; fi; exit 0\n',
            )
            executable("pgrep", "printf '424242\\n'\n")
            executable(
                "systemctl",
                'printf "%s\\n" "$*" >>"$GUARDIAN_SYSTEMCTL_LOG"; exit 0\n',
            )

            env = os.environ.copy()
            env["PATH"] = f"{fake_bin}:{env.get('PATH', '')}"
            env["GUARDIAN_SYSTEMCTL_LOG"] = str(systemctl_log)
            env["CHATGPT_BROWSER_HEALTH_FILE"] = str(health_file)
            result = subprocess.run(
                ["bash", str(guardian)],
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )

            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("CHATGPT_BROWSER_GUARDIAN=AUTH_PENDING", result.stdout)
            self.assertIn("CHATGPT_BROWSER_SESSION=AUTH_FLOW", result.stdout)
            calls = systemctl_log.read_text(encoding="utf-8") if systemctl_log.exists() else ""
            self.assertNotIn("restart shopvivaliz-dev-browser.service", calls)
            state = json.loads(health_file.read_text(encoding="utf-8"))
            self.assertEqual(state["session_state"], "AUTH_FLOW")
            self.assertFalse(state["authenticated"])

    def test_chatgpt_browser_guardian_marks_terminal_auth_as_degraded_without_restart(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        body = guardian.read_text(encoding="utf-8")
        self.assertIn("AUTH_TERMINAL", body)
        self.assertIn("invalid_state", body)
        self.assertIn("session ended", body)
        self.assertIn("operation timed out", body)

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            fake_bin = root / "bin"
            fake_bin.mkdir()
            systemctl_log = root / "systemctl.log"
            health_file = root / "browser-health.json"

            def executable(name: str, body: str) -> None:
                path = fake_bin / name
                path.write_text("#!/usr/bin/env bash\nset -Eeuo pipefail\n" + body, encoding="utf-8")
                path.chmod(0o755)

            executable("curl", "printf '{\\\"webSocketDebuggerUrl\\\":\\\"ws://127.0.0.1/test\\\"}'\n")
            executable(
                "node",
                'if [[ "$*" == *"CONTINUITY_BROWSER_SESSION_STATE_PROBE"* ]]; then printf "AUTH_TERMINAL\\n"; fi; exit 0\n',
            )
            executable("pgrep", "printf '424242\\n'\n")
            executable(
                "systemctl",
                'printf "%s\\n" "$*" >>"$GUARDIAN_SYSTEMCTL_LOG"; exit 0\n',
            )

            env = os.environ.copy()
            env["PATH"] = f"{fake_bin}:{env.get('PATH', '')}"
            env["GUARDIAN_SYSTEMCTL_LOG"] = str(systemctl_log)
            env["CHATGPT_BROWSER_HEALTH_FILE"] = str(health_file)
            result = subprocess.run(
                ["bash", str(guardian)],
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )

            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("CHATGPT_BROWSER_GUARDIAN=QUIESCENT_AUTH_TERMINAL", result.stdout)
            self.assertIn("CHATGPT_BROWSER_SESSION=AUTH_TERMINAL", result.stdout)
            calls = systemctl_log.read_text(encoding="utf-8") if systemctl_log.exists() else ""
            self.assertNotIn("restart shopvivaliz-dev-browser.service", calls)
            state = json.loads(health_file.read_text(encoding="utf-8"))
            self.assertEqual(state["session_state"], "AUTH_TERMINAL")
            self.assertFalse(state["authenticated"])

    def test_chatgpt_browser_guardian_live_openai_verification_precedes_residual_provider_terminal(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        body = guardian.read_text(encoding="utf-8")
        marker = "// CONTINUITY_BROWSER_SESSION_STATE_PROBE"
        self.assertIn(marker, body)
        probe = body.split(marker, 1)[1].split("' 2>/dev/null ||", 1)[0]
        self.assertIn("validOpenAiAuthFlow", probe)
        self.assertIn("residualAuthTerminal", probe)
        self.assertIn('pageHost === "auth.openai.com"', probe)
        self.assertIn('location.pathname === "/email-verification"', probe)
        self.assertIn('document.querySelector("input[name=code]")', probe)
        self.assertIn("authTerminal = (authTerminal || residualAuthTerminal) && !validOpenAiAuthFlow;", probe)

    def test_chatgpt_browser_guardian_active_openai_verification_precedes_stale_openai_terminal(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        body = guardian.read_text(encoding="utf-8")
        marker = "// CONTINUITY_BROWSER_SESSION_STATE_PROBE"
        self.assertIn(marker, body)
        probe = body.split(marker, 1)[1].split("' 2>/dev/null ||", 1)[0]
        self.assertIn("validOpenAiAuthFlow", probe)
        self.assertIn(
            "authTerminal = (authTerminal || residualAuthTerminal) && !validOpenAiAuthFlow;",
            probe,
        )

    def test_chatgpt_browser_guardian_authenticated_chatgpt_precedes_residual_oauth(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        body = guardian.read_text(encoding="utf-8")
        marker = "// CONTINUITY_BROWSER_SESSION_STATE_PROBE"
        self.assertIn(marker, body)
        probe = body.split(marker, 1)[1].split("' 2>/dev/null ||", 1)[0]
        connect_idx = probe.index("const c = await connectFirstUsableChatgptTab")
        authenticated_idx = probe.index('state === "AUTHENTICATED"')
        oauth_fallback_idx = probe.rindex('if (authFlow)')
        self.assertLess(connect_idx, oauth_fallback_idx)
        self.assertLess(authenticated_idx, oauth_fallback_idx)
        self.assertIn('console.log("AUTHENTICATED")', probe)
        self.assertIn('console.log("AUTH_FLOW")', probe)

    def test_chatgpt_browser_guardian_prefers_authenticated_tab_over_stale_logged_out_root(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        body = guardian.read_text(encoding="utf-8")
        marker = "// CONTINUITY_BROWSER_SESSION_STATE_PROBE"
        self.assertIn(marker, body)
        probe = body.split(marker, 1)[1].split("' 2>/dev/null ||", 1)[0]
        self.assertIn("CONTINUITY_BROWSER_PREFER_AUTHENTICATED_TAB", probe)
        self.assertIn("probeChatgptSessionState", probe)
        self.assertIn("state === \"AUTHENTICATED\"", probe)
        self.assertIn("connectFirstUsableChatgptTab(tabs", probe)


    def test_guardian_accepts_authenticated_identity_without_exposed_access_token(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        body = guardian.read_text(encoding="utf-8")
        marker = "// CONTINUITY_BROWSER_SESSION_STATE_PROBE"
        self.assertIn(marker, body)
        probe = body.split(marker, 1)[1].split("' 2>/dev/null ||", 1)[0]
        self.assertIn("const hasIdentity = Boolean(session?.account || session?.user);", probe)
        self.assertIn('if (hasIdentity) return "AUTHENTICATED";', probe)
        self.assertNotIn('if (hasIdentity && hasAccessToken) return "AUTHENTICATED";', probe)


    def test_guardian_accepts_authenticated_shell_when_session_payload_omits_identity(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        body = guardian.read_text(encoding="utf-8")
        marker = "// CONTINUITY_BROWSER_SESSION_STATE_PROBE"
        self.assertIn(marker, body)
        probe = body.split(marker, 1)[1].split("' 2>/dev/null ||", 1)[0]
        ui_marker = 'document.querySelector(\'[aria-label="Open profile menu"]\')'
        self.assertIn(ui_marker, probe)
        self.assertIn('return "AUTHENTICATED";', probe)
        self.assertLess(
            probe.index(ui_marker),
            probe.index('const loggedOut ='),
            "authenticated app-shell evidence must beat stale login/MFA remnants",
        )

    def test_chatgpt_browser_guardian_recovers_hung_managed_browser(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            fake_bin = root / "bin"
            fake_bin.mkdir()
            count_file = root / "curl-count"
            systemctl_log = root / "systemctl.log"
            health_file = root / "browser-health.json"

            def executable(name: str, body: str) -> None:
                path = fake_bin / name
                path.write_text("#!/usr/bin/env bash\nset -Eeuo pipefail\n" + body, encoding="utf-8")
                path.chmod(0o755)

            executable(
                "curl",
                'count=0; [[ -f "$GUARDIAN_CURL_COUNT_FILE" ]] && count="$(cat "$GUARDIAN_CURL_COUNT_FILE")"; '
                'count=$((count + 1)); printf "%s" "$count" >"$GUARDIAN_CURL_COUNT_FILE"; '
                'if [[ "$count" -ge 3 ]]; then printf \'{"webSocketDebuggerUrl":"ws://127.0.0.1/test"}\'; exit 0; fi; exit 22\n',
            )
            executable("pgrep", "printf \'424242\\n\'\n")
            executable(
                "systemctl",
                'printf "%s\\n" "$*" >>"$GUARDIAN_SYSTEMCTL_LOG"; '
                'if [[ "${1:-}" == "is-active" ]]; then exit 0; fi; exit 0\n',
            )
            executable("sleep", "exit 0\n")
            # Forward-compatible with the runtime-evaluate health probe: once
            # the endpoint recovers, the browser target must also execute JS
            # and prove the canonical ChatGPT session is authenticated.
            executable(
                "node",
                'if [[ "$*" == *"CONTINUITY_BROWSER_SESSION_STATE_PROBE"* ]]; then printf "AUTHENTICATED\\n"; fi; exit 0\n',
            )

            env = os.environ.copy()
            env["PATH"] = f"{fake_bin}:{env.get('PATH', '')}"
            env["GUARDIAN_CURL_COUNT_FILE"] = str(count_file)
            env["GUARDIAN_SYSTEMCTL_LOG"] = str(systemctl_log)
            env["CHATGPT_BROWSER_HEALTH_FILE"] = str(health_file)
            result = subprocess.run(
                ["bash", str(guardian)],
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )

            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertTrue(health_file.exists())
            calls = systemctl_log.read_text(encoding="utf-8")
            self.assertIn("is-active --quiet shopvivaliz-dev-browser.service", calls)
            self.assertIn("restart shopvivaliz-dev-browser.service", calls)
            self.assertIn("CHATGPT_BROWSER_GUARDIAN=RECOVERED_MANAGED_RESTART", result.stdout)

    def test_chatgpt_browser_guardian_restarts_active_managed_browser_despite_profile_command_drift(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            fake_bin = root / "bin"
            fake_bin.mkdir()
            count_file = root / "curl-count"
            systemctl_log = root / "systemctl.log"
            health_file = root / "browser-health.json"

            def executable(name: str, body: str) -> None:
                path = fake_bin / name
                path.write_text("#!/usr/bin/env bash\nset -Eeuo pipefail\n" + body, encoding="utf-8")
                path.chmod(0o755)

            executable(
                "curl",
                'count=0; [[ -f "$GUARDIAN_CURL_COUNT_FILE" ]] && count="$(cat "$GUARDIAN_CURL_COUNT_FILE")"; '
                'count=$((count + 1)); printf "%s" "$count" >"$GUARDIAN_CURL_COUNT_FILE"; '
                'if [[ "$count" -ge 3 ]]; then printf \'{"webSocketDebuggerUrl":"ws://127.0.0.1/test"}\'; exit 0; fi; exit 22\n',
            )
            executable("pgrep", "exit 1\n")
            executable(
                "systemctl",
                'printf "%s\\n" "$*" >>"$GUARDIAN_SYSTEMCTL_LOG"; '
                'if [[ "${1:-}" == "is-active" ]]; then exit 0; fi; exit 0\n',
            )
            executable("sleep", "exit 0\n")
            executable(
                "node",
                'if [[ "$*" == *"CONTINUITY_BROWSER_SESSION_STATE_PROBE"* ]]; then printf "AUTHENTICATED\\n"; fi; exit 0\n',
            )

            env = os.environ.copy()
            env["PATH"] = f"{fake_bin}:{env.get('PATH', '')}"
            env["GUARDIAN_CURL_COUNT_FILE"] = str(count_file)
            env["GUARDIAN_SYSTEMCTL_LOG"] = str(systemctl_log)
            env["CHATGPT_BROWSER_HEALTH_FILE"] = str(health_file)
            result = subprocess.run(
                ["bash", str(guardian)],
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )

            calls = systemctl_log.read_text(encoding="utf-8") if systemctl_log.exists() else ""
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("is-active --quiet shopvivaliz-dev-browser.service", calls)
            self.assertIn("restart shopvivaliz-dev-browser.service", calls)
            self.assertNotIn("\nstart shopvivaliz-dev-browser.service\n", "\n" + calls)
            self.assertIn("CHATGPT_BROWSER_GUARDIAN=RECOVERED_MANAGED_RESTART", result.stdout)

    def test_chatgpt_browser_guardian_takes_over_single_hung_unmanaged_browser(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            fake_bin = root / "bin"
            fake_bin.mkdir()
            node_count = root / "node-count"
            systemctl_log = root / "systemctl.log"
            health_file = root / "browser-health.json"

            def executable(name: str, body: str) -> None:
                path = fake_bin / name
                path.write_text("#!/usr/bin/env bash\nset -Eeuo pipefail\n" + body, encoding="utf-8")
                path.chmod(0o755)

            executable("curl", "printf '{\\\"webSocketDebuggerUrl\\\":\\\"ws://127.0.0.1/test\\\"}'\n")
            executable(
                "node",
                'count=0; [[ -f "$GUARDIAN_NODE_COUNT_FILE" ]] && count="$(cat "$GUARDIAN_NODE_COUNT_FILE")"; '
                'count=$((count + 1)); printf "%s" "$count" >"$GUARDIAN_NODE_COUNT_FILE"; '
                'if [[ "$*" == *"CONTINUITY_BROWSER_SESSION_STATE_PROBE"* ]]; then printf "AUTHENTICATED\\n"; exit 0; fi; '
                'if [[ "$count" -ge 2 ]]; then exit 0; fi; exit 1\n',
            )
            executable("pgrep", 'printf "%s\\n" "${GUARDIAN_FAKE_PID:-99999999}"\n')
            executable(
                "systemctl",
                'printf "%s\\n" "$*" >>"$GUARDIAN_SYSTEMCTL_LOG"; '
                'if [[ "${1:-}" == "is-active" ]]; then exit 3; fi; exit 0\n',
            )
            executable("sleep", "exit 0\n")

            env = os.environ.copy()
            env["PATH"] = f"{fake_bin}:{env.get('PATH', '')}"
            env["GUARDIAN_NODE_COUNT_FILE"] = str(node_count)
            env["GUARDIAN_SYSTEMCTL_LOG"] = str(systemctl_log)
            env["CHATGPT_BROWSER_HEALTH_FILE"] = str(health_file)
            env["GUARDIAN_FAKE_PID"] = "99999999"
            result = subprocess.run(
                ["bash", str(guardian)],
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )

            calls = systemctl_log.read_text(encoding="utf-8") if systemctl_log.exists() else ""
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertTrue(health_file.exists())
            self.assertIn("is-active --quiet shopvivaliz-dev-browser.service", calls)
            self.assertIn("start shopvivaliz-dev-browser.service", calls)
            self.assertIn("CHATGPT_BROWSER_GUARDIAN=RECOVERED_UNMANAGED_TAKEOVER", result.stdout)
            # The process may exit before signalling. Record that outcome and
            # still require the existing absence check before starting a browser.
            self.assertIn("CHATGPT_BROWSER_SIGNAL=NOT_DELIVERED_RECHECK_REQUIRED", result.stderr)

    def test_chatgpt_browser_guardian_recognizes_authenticated_session_without_composer(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        body = guardian.read_text(encoding="utf-8")
        marker = "// CONTINUITY_BROWSER_SESSION_STATE_PROBE"
        self.assertIn(marker, body)
        probe = body.split(marker, 1)[1].split("' 2>/dev/null ||", 1)[0]
        self.assertIn("/api/auth/session", probe)
        self.assertIn("sessionResponse.ok", probe)
        self.assertIn("session?.account", probe)
        self.assertIn("const hasIdentity = Boolean(session?.account || session?.user);", probe)
        self.assertIn('if (hasIdentity) return "AUTHENTICATED";', probe)
        self.assertLess(probe.index("/api/auth/session"), probe.index("document.querySelector(\"[contenteditable=true]\")"))

    def test_chatgpt_browser_guardian_authenticated_session_precedes_residual_logout_dom(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        body = guardian.read_text(encoding="utf-8")
        marker = "// CONTINUITY_BROWSER_SESSION_STATE_PROBE"
        probe = body.split(marker, 1)[1].split("' 2>/dev/null ||", 1)[0]
        self.assertLess(
            probe.index('/api/auth/session'),
            probe.index('const loggedOut'),
            "authoritative authenticated session must be checked before residual logout URL/DOM markers",
        )

    def test_chatgpt_browser_guardian_session_probe_skips_unresponsive_target(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        body = guardian.read_text(encoding="utf-8")
        marker = "// CONTINUITY_BROWSER_SESSION_STATE_PROBE"
        probe = body.split(marker, 1)[1].split("' 2>/dev/null ||", 1)[0]
        connector = probe.split("const c = await connectFirstUsableChatgptTab", 1)[1]
        self.assertIn('candidate.evaluate("true")', connector)
        self.assertIn('evaluate timeout', connector)
        self.assertIn('candidate?.close()', connector)

    def test_chatgpt_browser_guardian_requires_runtime_evaluate_health(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        body = guardian.read_text(encoding="utf-8")
        self.assertIn("connectFirstUsableChatgptTab", body)
        self.assertIn("c.evaluate", body)
        self.assertNotIn("Cdp.connectToChatgptTab", body)

    def test_chatgpt_browser_guardian_runtime_eval_prefers_chatgpt_before_stale_auth_tab(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        body = guardian.read_text(encoding="utf-8")
        probe = body.split("runtime_eval_ready() {", 1)[1].split("browser_session_state() {", 1)[0]
        chatgpt_first = "let c = await connectFirstUsableChatgptTab(tabs, connect);"
        auth_fallback = "if (!c && authPage)"
        self.assertIn(chatgpt_first, probe)
        self.assertIn(auth_fallback, probe)
        self.assertLess(probe.index(chatgpt_first), probe.index(auth_fallback))
        self.assertNotIn(
            "authPage ? await connect(authPage) : await connectFirstUsableChatgptTab(tabs, connect)",
            probe,
        )

    def test_chatgpt_browser_guardian_restarts_managed_browser_when_runtime_evaluate_fails(self) -> None:
        guardian = ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-browser-guardian.sh"
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            fake_bin = root / "bin"
            fake_bin.mkdir()
            node_count = root / "node-count"
            systemctl_log = root / "systemctl.log"
            health_file = root / "browser-health.json"

            def executable(name: str, body: str) -> None:
                path = fake_bin / name
                path.write_text("#!/usr/bin/env bash\nset -Eeuo pipefail\n" + body, encoding="utf-8")
                path.chmod(0o755)

            executable("curl", "printf '{\\\"webSocketDebuggerUrl\\\":\\\"ws://127.0.0.1/test\\\"}'\n")
            executable(
                "node",
                'count=0; [[ -f "$GUARDIAN_NODE_COUNT_FILE" ]] && count="$(cat "$GUARDIAN_NODE_COUNT_FILE")"; '
                'count=$((count + 1)); printf "%s" "$count" >"$GUARDIAN_NODE_COUNT_FILE"; '
                'if [[ "$*" == *"CONTINUITY_BROWSER_SESSION_STATE_PROBE"* ]]; then printf "AUTHENTICATED\\n"; exit 0; fi; '
                'if [[ "$count" -ge 3 ]]; then exit 0; fi; exit 1\n',
            )
            executable("pgrep", "printf \'424242\\n\'\n")
            executable(
                "systemctl",
                'printf "%s\\\\n" "$*" >>"$GUARDIAN_SYSTEMCTL_LOG"; '
                'if [[ "${1:-}" == "is-active" ]]; then exit 0; fi; exit 0\n',
            )
            executable("sleep", "exit 0\n")

            env = os.environ.copy()
            env["PATH"] = f"{fake_bin}:{env.get('PATH', '')}"
            env["GUARDIAN_NODE_COUNT_FILE"] = str(node_count)
            env["GUARDIAN_SYSTEMCTL_LOG"] = str(systemctl_log)
            env["CHATGPT_BROWSER_HEALTH_FILE"] = str(health_file)
            result = subprocess.run(
                ["bash", str(guardian)],
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )

            calls = systemctl_log.read_text(encoding="utf-8") if systemctl_log.exists() else ""
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertTrue(health_file.exists())
            self.assertIn("is-active --quiet shopvivaliz-dev-browser.service", calls)
            self.assertIn("restart shopvivaliz-dev-browser.service", calls)
            self.assertIn("CHATGPT_BROWSER_GUARDIAN=RECOVERED_MANAGED_RESTART", result.stdout)

    def test_php_bridge_supports_file_backed_secret(self) -> None:
        bridge = (ROOT / "api" / "chatgpt-continuity" / "bridge.php").read_text(encoding="utf-8")
        self.assertIn("CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE", bridge)
        self.assertIn("bridge.token", bridge)

    def test_docs_pin_chatgpt_session_reentry_to_backend_vm(self) -> None:
        docs = (ROOT / "docs" / "knowledge" / "task-continuity.md").read_text(encoding="utf-8")
        rules = (ROOT / "docs" / "knowledge" / "agent-rules.md").read_text(encoding="utf-8")
        self.assertIn("CHATGPT_SESSION_REENTRY_V10", docs)
        self.assertIn("CDP9559", docs)
        self.assertIn("always-free-arm-1787907847-26", rules)
        self.assertIn("shopvivaliz-chatgpt-continuity.service", rules)


    def test_runtime_transport_bypasses_public_cloudflare_path(self) -> None:
        installer = (ROOT / "scripts" / "install-chatgpt-continuity-backend-bridge.sh").read_text(encoding="utf-8")
        dispatcher = (ROOT / "scripts" / "chatgpt_continuity_nudge_dispatcher.py").read_text(encoding="utf-8")
        worker = (ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-continuity-bridge-worker.mjs").read_text(encoding="utf-8")
        self.assertIn("http://127.0.0.1:18081/api/chatgpt-continuity/bridge.php", installer)
        self.assertIn("shopvivaliz-chatgpt-continuity-a1-tunnel.service", installer)
        self.assertIn("/home/ubuntu/.ssh/shopvivaliz-free-a1-monitor", installer)
        self.assertIn("-L 127.0.0.1:18081:127.0.0.1:8080", installer)
        self.assertIn("ubuntu@10.0.1.112", installer)
        self.assertIn("CHATGPT_CONTINUITY_BRIDGE_HOST_HEADER", installer)
        self.assertIn("http://127.0.0.1:18081/api/chatgpt-continuity/bridge.php", dispatcher)
        self.assertIn("Host", dispatcher)
        self.assertIn("http://127.0.0.1:18081/api/chatgpt-continuity/bridge.php", worker)
        self.assertIn("CHATGPT_CONTINUITY_BRIDGE_HOST_HEADER", worker)


if __name__ == "__main__":
    unittest.main()
