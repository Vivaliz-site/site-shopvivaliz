#!/usr/bin/env python3
"""
Validate the local stdio MCP bridge.

This project now uses scripts/codex-mesh-bridge.py instead of the legacy
HTTP server. The script runs a short initialize/tools/list exchange and
records the result in reports/.
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
from datetime import datetime
from pathlib import Path
from typing import Any


REPO_ROOT = Path(__file__).resolve().parent.parent
REPORTS_DIR = REPO_ROOT / "reports"
BRIDGE = REPO_ROOT / "scripts" / "codex-mesh-bridge.py"


def write_report(report: dict[str, Any]) -> Path:
    if not REPORTS_DIR.is_dir() or not REPORTS_DIR.exists():
        raise FileNotFoundError(f"Report directory unavailable: {REPORTS_DIR}")
    stamp = datetime.now().strftime("%Y-%m-%d")
    path = REPORTS_DIR / f"mcp-local-autostart-{stamp}.md"
    lines = [
        "# MCP Local Bridge Validation",
        "",
        f"- Timestamp: `{report['timestamp']}`",
        f"- Healthy: `{report['healthy']}`",
        f"- Script: `{report['script']}`",
        f"- Error: `{report.get('error', 'none')}`",
    ]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return path


def probe_bridge(script: Path, timeout: int) -> dict[str, Any]:
    requests = [
        {
            "jsonrpc": "2.0",
            "id": 1,
            "method": "initialize",
            "params": {
                "protocolVersion": "2025-11-25",
                "capabilities": {},
                "clientInfo": {"name": "mcp-local-autostart", "version": "1"},
            },
        },
        {"jsonrpc": "2.0", "method": "notifications/initialized", "params": {}},
        {"jsonrpc": "2.0", "id": 2, "method": "tools/list", "params": {}},
    ]
    completed = subprocess.run(
        [sys.executable, str(script)],
        input="".join(json.dumps(item) + "\n" for item in requests).encode("utf-8"),
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        cwd=REPO_ROOT,
        timeout=timeout,
        check=True,
    )
    responses = [json.loads(line) for line in completed.stdout.decode("utf-8").splitlines() if line.strip()]
    tools_response = next(item for item in responses if item.get("id") == 2)
    tool_names = {tool["name"] for tool in tools_response["result"]["tools"]}
    expected = {"post_message", "read_messages", "bridge_status"}
    if tool_names != expected:
        raise RuntimeError(f"unexpected tools: {sorted(tool_names)}")
    return {"healthy": True, "tool_names": sorted(tool_names)}


def main() -> int:
    parser = argparse.ArgumentParser(description="Validate the local MCP stdio bridge")
    parser.add_argument("--timeout", type=int, default=10)
    parser.add_argument("--script", default=str(BRIDGE))
    args = parser.parse_args()

    report: dict[str, Any] = {
        "timestamp": datetime.now().isoformat(),
        "healthy": False,
        "script": str(Path(args.script).resolve()),
    }

    try:
        report.update(probe_bridge(Path(args.script), args.timeout))
    except Exception as exc:
        report["error"] = str(exc)

    report_path = write_report(report)
    print(json.dumps({**report, "report_path": str(report_path)}, indent=2))
    return 0 if report["healthy"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
