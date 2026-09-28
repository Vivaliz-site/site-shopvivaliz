#!/usr/bin/env python3
from __future__ import annotations

import collections
import json
import os
import pwd
import subprocess
from pathlib import Path

TASK_ID = "global-continuity-cleanup-v2-20260927"
STATE_DIR = Path("/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state")


def emit(prefix: str, payload) -> None:
    if isinstance(payload, (dict, list)):
        value = json.dumps(payload, sort_keys=True, separators=(",", ":"))
    else:
        value = str(payload)
    print(f"{prefix}={value}")


def run_text(args: list[str]) -> str:
    try:
        cp = subprocess.run(args, text=True, capture_output=True, timeout=10, check=False)
    except Exception as exc:
        return f"error:{type(exc).__name__}"
    return (cp.stdout or "").strip()


def process_snapshot() -> None:
    proc = Path("/proc")
    uptime = float((proc / "uptime").read_text().split()[0])
    hz = os.sysconf(os.sysconf_names["SC_CLK_TCK"])
    by_pid: dict[int, list[str]] = {}

    def args_for(pid: int) -> list[str]:
        cached = by_pid.get(pid)
        if cached is not None:
            return cached
        try:
            raw = (proc / str(pid) / "cmdline").read_bytes()
            args = [x.decode("utf-8", "replace") for x in raw.split(b"\0") if x]
        except OSError:
            args = []
        by_pid[pid] = args
        return args

    def stat_after_comm(pid: int) -> list[str]:
        try:
            raw = (proc / str(pid) / "stat").read_text()
        except OSError:
            return []
        close = raw.rfind(")")
        if close < 0:
            return []
        return raw[close + 2 :].split()

    def ppid(pid: int) -> int:
        fields = stat_after_comm(pid)
        try:
            return int(fields[1]) if len(fields) > 1 else 0
        except (TypeError, ValueError):
            return 0

    def age(pid: int) -> int:
        fields = stat_after_comm(pid)
        try:
            return max(0, int(uptime - int(fields[19]) / hz)) if len(fields) > 19 else -1
        except (TypeError, ValueError):
            return -1

    def user(pid: int) -> str:
        try:
            return pwd.getpwuid((proc / str(pid)).stat().st_uid).pw_name
        except Exception:
            return "unknown"

    def safe_identity(pid: int) -> dict[str, object]:
        args = args_for(pid)
        first = os.path.basename(args[0]) if args else ""
        second = ""
        if len(args) > 1 and first in {"python", "python3", "node", "nodejs", "bash", "sh"}:
            candidate = args[1]
            if not candidate.startswith("-") and (
                "/" in candidate or candidate.endswith((".py", ".mjs", ".js", ".sh"))
            ):
                second = os.path.basename(candidate)
        try:
            exe = os.path.basename(os.readlink(proc / str(pid) / "exe"))
        except OSError:
            exe = ""
        return {
            "pid": pid,
            "argv0_basename": first,
            "script_basename": second,
            "exe_basename": exe,
        }

    def kind(args: list[str]) -> str:
        text = " ".join(args).lower()
        first = os.path.basename(args[0]) if args else ""
        second = os.path.basename(args[1]) if len(args) > 1 else ""
        if "codex-native-profile-failover.py" in text:
            return "codex_profile_failover"
        if second == "codex-bridge.mjs":
            return "codex_bridge_service"
        if first in {"codex", "codex.js"} or second == "codex.js":
            return "codex"
        if "autonomous-agent-loop.sh" in text:
            return "autonomous_agent_loop"
        if "task_resume_dispatcher.py" in text:
            return "task_resume_dispatcher"
        if "run_background_gemini.py" in text:
            return "background_gemini_wrapper"
        if first == "gemini":
            return "gemini"
        if first == "tmux" or first.startswith("tmux:"):
            return "tmux"
        return ""

    rows = []
    for entry in proc.iterdir():
        if not entry.name.isdigit():
            continue
        pid = int(entry.name)
        args = args_for(pid)
        k = kind(args)
        if not k:
            continue
        row = {
            "pid": pid,
            "ppid": ppid(pid),
            "age_seconds": age(pid),
            "user": user(pid),
            "kind": k,
            "dangerous_bypass_flag": "--dangerously-bypass-approvals-and-sandbox" in args,
            "exec_subcommand": "exec" in args,
            "argv_count": len(args),
        }
        rows.append(row)

    for row in sorted(rows, key=lambda x: x["pid"]):
        emit("PROC", row)
        emit("PROC_IDENTITY", safe_identity(row["pid"]))

    emitted_ancestors = set()
    for row in rows:
        if row["kind"] not in {"codex", "codex_profile_failover"}:
            continue
        chain = []
        seen = set()
        current = row["pid"]
        for _ in range(14):
            if current <= 1 or current in seen:
                break
            seen.add(current)
            chain.append({"pid": current, "kind": kind(args_for(current)) or "other"})
            if current not in emitted_ancestors:
                emit("ANCESTOR_IDENTITY", safe_identity(current))
                emitted_ancestors.add(current)
            current = ppid(current)
        if current == 1:
            chain.append({"pid": 1, "kind": "init"})
        emit("CODEX_ANCESTRY", {"pid": row["pid"], "chain": chain})
        meta = []
        for item in chain:
            pid = int(item.get("pid") or 0)
            a = args_for(pid)
            shaped = []
            for idx, token in enumerate(a[:8]):
                if idx == 0 or "/" in token or "\\" in token:
                    shaped.append(os.path.basename(token.replace("\\", "/")))
                elif token.startswith("-"):
                    shaped.append(token.split("=", 1)[0])
                else:
                    shaped.append("<arg>")
            try:
                cwd = os.readlink(proc / str(pid) / "cwd")
            except OSError:
                cwd = ""
            meta.append({"pid": pid, "argv_shape": shaped, "cwd": cwd})
        emit("CODEX_ANCESTOR_META", {"pid": row["pid"], "meta": meta})
        try:
            paths = [
                line.split(":", 2)[-1]
                for line in (proc / str(row["pid"]) / "cgroup").read_text().splitlines()
                if ":" in line
            ]
        except OSError:
            paths = []
        safe = []
        for path in paths:
            parts = [p for p in path.split("/") if p]
            keep = [
                p
                for p in parts
                if p.endswith((".service", ".scope", ".slice")) or p.startswith("session-")
            ]
            if keep:
                safe.append("/".join(keep[-3:]))
        emit("CODEX_CGROUP", {"pid": row["pid"], "cgroup": ";".join(sorted(set(safe)))[:500]})


def service_snapshot() -> None:
    emit("A1_SNAPSHOT_HOST", run_text(["hostname"]))
    emit("A1_SNAPSHOT_UTC", run_text(["date", "-u", "+%FT%TZ"]))
    emit("ACTIVE_RELEASE", os.path.realpath("/home/ubuntu/shopvivaliz-deploy/current"))
    emit("AGENT_SERVICE_ACTIVE", run_text(["systemctl", "is-active", "shopvivaliz-agent.service"]))
    emit("AGENT_SERVICE_ENABLED", run_text(["systemctl", "is-enabled", "shopvivaliz-agent.service"]))
    show = run_text([
        "systemctl",
        "show",
        "shopvivaliz-agent.service",
        "-p",
        "MainPID",
        "-p",
        "ActiveState",
        "-p",
        "SubState",
        "-p",
        "FragmentPath",
        "--no-pager",
    ])
    for line in show.splitlines():
        if line:
            emit("AGENT_SERVICE_META", line)


def tmux_snapshot() -> None:
    sessions = run_text([
        "tmux",
        "list-sessions",
        "-F",
        "#{session_name}|#{session_attached}|#{session_windows}",
    ])
    if sessions.startswith("error:"):
        return
    for line in sessions.splitlines():
        if line:
            name, attached, windows = (line.split("|", 2) + ["", ""])[:3]
            emit("TMUX_SESSION", {"name": name, "attached": attached, "windows": windows})
    panes = run_text([
        "tmux",
        "list-panes",
        "-a",
        "-F",
        "#{session_name}|#{pane_pid}|#{pane_current_command}|#{pane_current_path}",
    ])
    if panes.startswith("error:"):
        return
    for line in panes.splitlines():
        if line:
            session, pid, command, current_path = (line.split("|", 3) + ["", "", ""])[:4]
            emit("TMUX_PANE", {"session": session, "pid": pid, "command": command, "path": current_path})


def task_snapshot() -> None:
    state_path = STATE_DIR / f"{TASK_ID}.json"
    try:
        state = json.loads(state_path.read_text(encoding="utf-8"))
        emit(
            "TASK_STATE",
            {
                "task_id": state.get("task_id"),
                "status": state.get("status"),
                "updated_at": state.get("updated_at"),
                "verification": state.get("verification"),
                "has_blocker": bool(state.get("blocker")),
                "next_action_present": bool(str(state.get("next_action") or "").strip()),
                "evidence_count": len(state.get("evidence") or []),
                "history_tail": [
                    {"at": row.get("at"), "event": row.get("event")}
                    for row in (state.get("history") or [])[-12:]
                    if isinstance(row, dict)
                ],
            },
        )
    except Exception as exc:
        emit("TASK_STATE_ERROR", type(exc).__name__)

    ledger = STATE_DIR / "_resume-executions.jsonl"
    rows = []
    if ledger.is_file():
        for line in ledger.read_text(encoding="utf-8", errors="replace").splitlines():
            try:
                item = json.loads(line)
            except json.JSONDecodeError:
                continue
            if item.get("task_id") == TASK_ID:
                rows.append(item)
    counts = collections.Counter(str(x.get("result") or "") for x in rows)
    providers = collections.Counter(
        str((x.get("diagnostic") or {}).get("provider") or "")
        for x in rows
        if isinstance(x.get("diagnostic"), dict)
    )
    emit(
        "LEDGER_SUMMARY",
        {
            "task_id": TASK_ID,
            "count": len(rows),
            "results": dict(sorted(counts.items())),
            "providers": dict(sorted(providers.items())),
        },
    )
    allowed = {
        "provider",
        "provider_status",
        "provider_attempt_exit_code",
        "background_gemini_error",
        "background_gemini_exit_code",
        "background_paid_fallback_forbidden",
    }
    for item in rows[-5:]:
        diag = item.get("diagnostic") if isinstance(item.get("diagnostic"), dict) else {}
        emit(
            "LEDGER_ROW",
            {
                "result": item.get("result"),
                "executor_exit_code": item.get("executor_exit_code"),
                "timestamp": item.get("timestamp") or item.get("executed_at") or item.get("created_at"),
                "diagnostic": {k: diag.get(k) for k in allowed if k in diag},
            },
        )


def main() -> int:
    service_snapshot()
    process_snapshot()
    tmux_snapshot()
    task_snapshot()
    emit("A1_CONTINUITY_RUNTIME_SNAPSHOT", "PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
