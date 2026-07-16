#!/usr/bin/env python3
"""
Garantir que o MCP local esteja online.

Se o servidor local não responder em /health, este script inicia
scripts/mcp-server.py em background e aguarda a subida.
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path
from typing import Any, Dict

try:
    import requests
except ImportError:
    print("❌ requests não instalado. Execute: pip install -r requirements.txt")
    raise SystemExit(1)


REPO_ROOT = Path(__file__).resolve().parent.parent
LOGS_DIR = REPO_ROOT / "logs"
REPORTS_DIR = REPO_ROOT / "reports"
MCP_SERVER = REPO_ROOT / "scripts" / "mcp-server.py"
MCP_URL = "http://127.0.0.1:5555"
PID_FILE = LOGS_DIR / "mcp-local.pid"
LOG_FILE = LOGS_DIR / "mcp-local-autostart.log"


def health_check(url: str) -> Dict[str, Any]:
    try:
        response = requests.get(f"{url}/health", timeout=3)
        response.raise_for_status()
        return response.json()
    except Exception as exc:
        return {"error": str(exc)}


def is_healthy(url: str) -> bool:
    health = health_check(url)
    return health.get("status") == "ok"


def start_server(port: int, env_name: str) -> subprocess.Popen[str]:
    LOGS_DIR.mkdir(parents=True, exist_ok=True)
    logfile = open(LOG_FILE, "a", encoding="utf-8")
    cmd = [
        sys.executable,
        str(MCP_SERVER),
        "--port",
        str(port),
        "--env",
        env_name,
    ]
    creationflags = 0
    if os.name == "nt":
        creationflags = subprocess.CREATE_NO_WINDOW  # type: ignore[attr-defined]
    return subprocess.Popen(
        cmd,
        cwd=REPO_ROOT,
        stdout=logfile,
        stderr=logfile,
        stdin=subprocess.DEVNULL,
        creationflags=creationflags,
        start_new_session=True,
        text=True,
    )


def write_report(report: Dict[str, Any]) -> Path:
    REPORTS_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y-%m-%d")
    path = REPORTS_DIR / f"mcp-local-autostart-{stamp}.md"
    lines = [
        "# MCP Local Auto-Start",
        "",
        f"- Timestamp: `{report['timestamp']}`",
        f"- URL: `{report['url']}`",
        f"- Started: `{report['started']}`",
        f"- Healthy: `{report['healthy']}`",
        f"- PID: `{report.get('pid', 'n/a')}`",
        f"- Error: `{report.get('error', 'none')}`",
    ]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return path


def main() -> int:
    parser = argparse.ArgumentParser(description="Ensure local MCP is running")
    parser.add_argument("--port", type=int, default=5555)
    parser.add_argument("--env", default="windows-local")
    parser.add_argument("--timeout", type=int, default=25)
    parser.add_argument("--start", action="store_true", help="Start if offline")
    args = parser.parse_args()

    url = f"http://127.0.0.1:{args.port}"
    report: Dict[str, Any] = {
        "timestamp": datetime.now().isoformat(),
        "url": url,
        "started": False,
        "healthy": False,
    }

    if is_healthy(url):
        report["healthy"] = True
        report_path = write_report(report)
        print(json.dumps({**report, "report_path": str(report_path)}, indent=2))
        return 0

    if not args.start:
        report["error"] = "offline"
        report_path = write_report(report)
        print(json.dumps({**report, "report_path": str(report_path)}, indent=2))
        return 1

    proc = start_server(args.port, args.env)
    report["pid"] = proc.pid
    report["started"] = True

    deadline = time.time() + args.timeout
    while time.time() < deadline:
        if is_healthy(url):
            report["healthy"] = True
            PID_FILE.write_text(str(proc.pid), encoding="utf-8")
            report_path = write_report(report)
            print(json.dumps({**report, "report_path": str(report_path)}, indent=2))
            return 0
        if proc.poll() is not None:
            report["error"] = f"server exited with code {proc.returncode}"
            break
        time.sleep(1)

    report.setdefault("error", "timeout waiting for MCP health")
    report_path = write_report(report)
    print(json.dumps({**report, "report_path": str(report_path)}, indent=2))
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
