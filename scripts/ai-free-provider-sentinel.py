#!/usr/bin/env python3
"""Read-only paid-provider usage sentinel for the two ShopVivaliz VMs.

No generative API calls, no credential reads, no process termination, and no
session manipulation. Record anomalous provider usage and policy drift without
mistaking an idle Claude Remote Control daemon for model consumption.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import ProxyHandler, Request, build_opener

DEFAULT_STATE = Path("/var/lib/shopvivaliz-ai-free-guard")
ISSUE = "1586"
REPO = "Vivaliz-site/site-shopvivaliz"
PORTS = {"codex": 17656, "claude": 17657}
FLAGS = (
    "SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK",
    "SHOPVIVALIZ_BACKGROUND_CLAUDE_FALLBACK",
)
INTERACTIVE_FLAG = "SHOPVIVALIZ_INTERACTIVE_PAID_FALLBACK"
MAX_FILE_TAIL = 512 * 1024
REMINDER_SECONDS = 24 * 3600


def systemctl(*args: str) -> str:
    result = subprocess.run(
        ["/usr/bin/systemctl", *args], capture_output=True, text=True,
        timeout=7, check=False,
    )
    return result.stdout.strip() if result.returncode == 0 else ""


def active(unit: str) -> bool:
    return systemctl("is-active", unit) == "active"


def service_pid(unit: str) -> int:
    raw = systemctl("show", unit, "-p", "MainPID", "--value")
    return int(raw) if raw.isdecimal() else 0


def selected_environment(pid: int) -> dict[str, str]:
    if pid < 1:
        return {}
    result = {}
    try:
        for pair in Path(f"/proc/{pid}/environ").read_bytes().split(b"\0"):
            if b"=" not in pair:
                continue
            key, value = pair.split(b"=", 1)
            name = key.decode("ascii", errors="ignore")
            if name in (*FLAGS, INTERACTIVE_FLAG):
                result[name] = value.decode("ascii", errors="replace")
    except OSError:
        return {}
    return result


def classify_health(role: str, payload: object) -> bool:
    return (
        role in PORTS and isinstance(payload, dict)
        and payload.get("endpoint") == f"ai-squad-{role}-bridge"
        and payload.get("ok") is False
        and payload.get("auth_mode") == "disabled"
        and payload.get("policy") == "manual_approval_required"
    )


def bridge_disabled(role: str) -> bool:
    opener = build_opener(ProxyHandler({}))
    try:
        request = Request(
            f"http://127.0.0.1:{PORTS[role]}/health", method="GET",
        )
        with opener.open(request, timeout=4) as response:
            payload = response.read(16385)
            if len(payload) > 16384:
                return False
            return classify_health(role, json.loads(payload))
    except (KeyError, HTTPError, URLError, TimeoutError, OSError, ValueError):
        return False


def read_runtime_entry() -> Path | None:
    try:
        config = Path("/etc/shopvivaliz-gemini-24x7-controller.env")
        for line in config.read_text().splitlines():
            if line.startswith("SHOPVIVALIZ_GEMINI_CONTROLLER_ENTRY="):
                value = line.partition("=")[2].strip().strip('"').strip("'")
                return Path(value)
    except OSError:
        return None
    return None


def free_router_present(is_site: bool) -> bool:
    if is_site:
        script = Path("/home/ubuntu/shopvivaliz-deploy/current/scripts/autonomous-provider-failover.sh")
        sha = Path("/home/ubuntu/shopvivaliz-deploy/current/.release-sha")
        if not sha.is_file():
            return False
    else:
        entry = read_runtime_entry()
        if not entry:
            return False
        script = entry.parent / "autonomous-provider-failover.sh"
    try:
        data = script.read_text()
        return "FREE_PROVIDER_FALLBACK_V2" in data and "BACKGROUND_ORDER=(gemini ollama)" in data
    except OSError:
        return False


def gemini_free_billing_guard_present(script_dir: Path) -> bool:
    """Verify the active Gemini runner and its calling router fail closed.

    Static verification only: never call an inference endpoint, read API keys,
    execute Gemini CLI, or falsely alert on an intentionally unverified key.
    """
    try:
        runner = (script_dir / "run_background_gemini.py").read_text()
        router = (script_dir / "autonomous-provider-failover.sh").read_text()
    except OSError:
        return False
    required_runner = (
        "FREE_TIER_BILLING_GUARD_V1",
        "free_tier_approved_credentials(",
        "background_gemini_error=free_tier_not_verified",
        "SHOPVIVALIZ_RESUME_BACKGROUND",
    )
    return (
        all(token in runner for token in required_runner)
        and 'env SHOPVIVALIZ_RESUME_BACKGROUND=1' in router
    )


def runtime_gemini_guard_present(is_site: bool) -> bool:
    if is_site:
        script_dir = Path("/home/ubuntu/shopvivaliz-deploy/current/scripts")
        if not Path("/home/ubuntu/shopvivaliz-deploy/current/.release-sha").is_file():
            return False
    else:
        entry = read_runtime_entry()
        if entry is None:
            return False
        script_dir = entry.parent
    return gemini_free_billing_guard_present(script_dir)


def proc_potential_paid(remote_daemon_pid: int, proc_root: Path = Path("/proc")) -> list[str]:
    findings: list[str] = []
    try:
        entries = proc_root.iterdir()
    except OSError:
        return ["PROC_UNAVAILABLE"]
    for entry in entries:
        if not entry.name.isdecimal():
            continue
        try:
            comm = (entry / "comm").read_text().strip().lower()
            if comm in ("claude", "codex"):
                if int(entry.name) == remote_daemon_pid and comm == "claude":
                    # The daemon itself is idle infrastructure; generated
                    # Claude sessions must still be detected independently.
                    continue
                findings.append(f"PAID_PROCESS_{comm.upper()}")
            elif comm == "node":
                # Detect binaries invoked with Node, but never log cmdline,
                # arguments, environment variables or private file paths.
                cmdline = (entry / "cmdline").read_bytes()[:1024].lower()
                if b"@openai/codex/bin/codex" in cmdline:
                    findings.append("PAID_PROCESS_CODEX")
                elif b"@anthropic-ai/claude-code/cli" in cmdline:
                    findings.append("PAID_PROCESS_CLAUDE")
        except (OSError, ValueError):
            continue
    return sorted(set(findings))


def codex_counter(db_file: Path) -> tuple[int, int]:
    # SQLite read-only does not call the model or modify the account.
    uri = db_file.resolve().as_uri() + "?mode=ro"
    con = sqlite3.connect(uri, uri=True, timeout=1)
    try:
        total, threads = con.execute(
            "SELECT COALESCE(SUM(tokens_used),0), COUNT(*) FROM threads"
        ).fetchone()
        return int(total), int(threads)
    finally:
        con.close()


def codex_snapshot() -> tuple[dict[str, list[int]], list[str]]:
    root = Path("/home/ubuntu/.codex-business")
    results: dict[str, list[int]] = {}
    anomalies: list[str] = []
    try:
        paths = sorted(root.glob("*/state_5.sqlite"))
    except OSError:
        return results, ["CODEX_STORE_UNAVAILABLE"]
    for path in paths:
        identifier = hashlib.sha256(str(path).encode()).hexdigest()[:10]
        try:
            results[identifier] = list(codex_counter(path))
        except (OSError, sqlite3.DatabaseError, ValueError):
            anomalies.append("CODEX_COUNTER_UNREADABLE")
    return results, sorted(set(anomalies))


def assistant_usage_after(path: Path, epoch: float, now: float) -> int:
    """Count only newly timestamped assistant/model usage events.

    Do not count file mtimes (the Claude Remote Control daemon can rewrite
    old files without any new inference). Never read or emit prompt contents.
    """
    count = 0
    try:
        import datetime
        with path.open("rb") as handle:
            size = handle.seek(0, 2)
            handle.seek(max(0, size - MAX_FILE_TAIL))
            if size > MAX_FILE_TAIL:
                handle.readline()  # discard truncated first JSONL record
            for line in handle:
                if b'"assistant"' not in line or b'"usage"' not in line:
                    continue
                try:
                    record = json.loads(line)
                    if record.get("type") != "assistant":
                        continue
                    stamp = record.get("timestamp")
                    if not isinstance(stamp, str):
                        continue
                    date = datetime.datetime.fromisoformat(stamp.replace("Z", "+00:00"))
                    event_epoch = date.timestamp()
                    if not (epoch < event_epoch <= now + 10):
                        continue
                    message = record.get("message") or {}
                    if not isinstance(message, dict):
                        continue
                    model = message.get("model")
                    usage = message.get("usage") or {}
                    if (isinstance(model, str) and model.startswith("claude-")
                        and isinstance(usage, dict)
                        and int(usage.get("output_tokens") or 0) > 0):
                        count += 1
                except (ValueError, TypeError, OverflowError, AttributeError):
                    continue
    except OSError:
        return 0
    return count


def recent_claude_usage(since: float, now: float) -> int:
    if since <= 0:
        return 0  # baseline; never infer fresh usage from old files
    root = Path("/home/ubuntu/.claude/projects")
    if not root.is_dir():
        return 0
    count = 0
    # Scan metadata only until finding recently written JSONL; no prompts
    # are read except the bounded JSONL tail whose content is never emitted.
    for dirpath, _dirs, files in os.walk(root):
        for filename in files:
            if not filename.endswith(".jsonl"):
                continue
            path = Path(dirpath) / filename
            try:
                if path.stat().st_mtime < since - 120:
                    continue
            except OSError:
                continue
            count += assistant_usage_after(path, since, now)
    return count


def compare_counters(prior: dict, current: dict[str, list[int]]) -> list[str]:
    old = prior.get("codex_counters", {})
    if not isinstance(old, dict):
        return ["STATE_COUNTERS_INVALID"]
    alerts: list[str] = []
    for profile_id, counts in current.items():
        if profile_id not in old:
            continue  # baseline newly discovered profile
        previous = old[profile_id]
        if not isinstance(previous, list) or len(previous) != 2:
            alerts.append("STATE_COUNTERS_INVALID")
            continue
        if counts[0] > previous[0] or counts[1] > previous[1]:
            alerts.append("CODEX_TOKEN_COUNTER_INCREASE")
    return sorted(set(alerts))


def policy_anomalies(host: str) -> list[str]:
    is_site = host == "shopvivaliz-free-a1"
    is_backend = host == "always-free-arm-1787907847-26"
    if not (is_site or is_backend):
        return ["HOST_IDENTITY_UNKNOWN"]
    issues = []
    unit_names = (["shopvivaliz-agent.service", "ollama.service"] if is_site else [
        "shopvivaliz-gemini-24x7-controller.service",
        "shopvivaliz-task-resume-worker.service",
        "ollama.service",
    ])
    for unit in unit_names:
        if not active(unit):
            issues.append("REQUIRED_SERVICE_DOWN_" + unit.split(".")[0].upper().replace("-", "_"))
    for unit in unit_names:
        if unit == "ollama.service":
            continue
        env = selected_environment(service_pid(unit))
        required = (*FLAGS, INTERACTIVE_FLAG) if is_site else FLAGS
        if any(env.get(flag) != "0" for flag in required):
            issues.append("PAID_FALLBACK_ENV_DRIFT")
    if not free_router_present(is_site):
        issues.append("FREE_ROUTER_MISSING")
    if not runtime_gemini_guard_present(is_site):
        issues.append("GEMINI_FREE_BILLING_GUARD_DRIFT")
    for role in PORTS:
        if not bridge_disabled(role):
            issues.append(f"PAID_BRIDGE_POLICY_DRIFT_{role.upper()}")
    return issues


def load_state(filename: Path) -> dict:
    try:
        result = json.loads(filename.read_text())
        return result if isinstance(result, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def store_state(filename: Path, state: dict) -> None:
    filename.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(filename.parent, 0o700)
    fd, temporary = tempfile.mkstemp(prefix=".state-", dir=filename.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            json.dump(state, f, sort_keys=True, separators=(",", ":"))
            f.write("\n")
            f.flush()
            os.fsync(f.fileno())
        os.chmod(temporary, 0o600)
        os.replace(temporary, filename)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def alert_message(host: str, status: str, findings: list[str], now: int) -> str:
    # Only static codes are shared with the existing infrastructure issue.
    return (
        f"AI_FREE_PROVIDER_SENTINEL_{status}\n"
        f"host={host}\n"
        f"timestamp_utc={time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(now))}\n"
        f"findings={','.join(sorted(set(findings))) if findings else 'none'}\n"
        "read_only_monitor=true; paid_inference_invoked=false; "
        "no_sessions_stopped=true\n"
        "action=inspect provider authorization and process origin, "
        "preserve approved interactive sessions"
    )


def github_comment(body: str) -> bool:
    try:
        command = [
            "/usr/bin/sudo", "-u", "ubuntu", "-H", "gh", "issue",
            "comment", ISSUE, "--repo", REPO, "--body", body,
        ]
        response = subprocess.run(command, timeout=20, capture_output=True, check=False)
        return response.returncode == 0
    except (OSError, subprocess.TimeoutExpired):
        return False


def evaluate_notification(old: dict, issues: list[str], now: int) -> tuple[str, str]:
    fingerprint = hashlib.sha256(",".join(sorted(set(issues))).encode()).hexdigest()
    old_fp = str(old.get("notified_fingerprint", ""))
    last_epoch = int(old.get("notified_epoch", 0) or 0)
    if issues:
        if old_fp != fingerprint or now - last_epoch >= REMINDER_SECONDS:
            return "ALERT", fingerprint
        return "", fingerprint
    if old_fp and old_fp != hashlib.sha256(b"").hexdigest():
        return "RECOVERED", fingerprint
    return "", fingerprint


def run(*, state_dir: Path, do_notify: bool = True, now: int | None = None) -> tuple[list[str], str]:
    started = int(time.time()) if now is None else now
    host = socket.gethostname()
    filename = state_dir / "state.json"
    previous = load_state(filename)
    issues = policy_anomalies(host)
    daemon = service_pid("shopvivaliz-claude-remote-control.service") if host == "always-free-arm-1787907847-26" else 0
    issues += proc_potential_paid(daemon)
    counters, counter_errors = codex_snapshot()
    issues += counter_errors
    issues += compare_counters(previous, counters)
    if recent_claude_usage(float(previous.get("last_checked_epoch", 0)), started):
        issues.append("CLAUDE_ASSISTANT_TOKEN_USAGE_NEW")
    issues = sorted(set(issues))
    notification, fp = evaluate_notification(previous, issues, started)
    notified = False
    if notification and do_notify:
        notified = github_comment(alert_message(host, notification, issues, started))
    latest = {
        "host": host,
        "last_checked_epoch": started,
        "last_status": "ALERT" if issues else "HEALTHY",
        "last_issue_codes": issues,
        "codex_counters": counters,
        # Failed notification retries next execution. Do not overwrite
        # the previously acknowledged fingerprint on network failure.
        "notified_fingerprint": (
            fp if notification and notified else previous.get("notified_fingerprint", "")
        ),
        "notified_epoch": (
            started if notification and notified else previous.get("notified_epoch", 0)
        ),
    }
    store_state(filename, latest)
    summary = (
        f"AI_FREE_PROVIDER_SENTINEL host={host} "
        f"status={'ALERT' if issues else 'HEALTHY'} "
        f"findings={','.join(issues) if issues else 'none'} "
        f"notification={notification if notification else 'not_needed'} "
        f"notification_sent={str(notified).lower()}"
    )
    print(summary)
    return issues, summary


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--state-dir", type=Path, default=DEFAULT_STATE)
    parser.add_argument("--no-github-comment", action="store_true")
    args = parser.parse_args()
    run(state_dir=args.state_dir, do_notify=not args.no_github_comment)
    # Anomaly is not a service failure: keep the timer alive while alerts
    # and audit state continue updating. Service crashes still fail hard.
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
