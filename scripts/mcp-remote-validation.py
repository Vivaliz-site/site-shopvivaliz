#!/usr/bin/env python3
"""
Validar os servidores MCP definidos em mcp-servers.json.
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, List

SCRIPTS_DIR = Path(__file__).resolve().parent
if str(SCRIPTS_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_DIR))

from mcp_client import MCPCloudManager


REPO_ROOT = Path(__file__).resolve().parent.parent
REPORTS_DIR = REPO_ROOT / "reports"


def write_report(report: Dict[str, Any]) -> Path:
    REPORTS_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y-%m-%d")
    path = REPORTS_DIR / f"mcp-remote-validation-{stamp}.md"
    lines = [
        "# MCP Remote Validation",
        "",
        f"- Timestamp: `{report['timestamp']}`",
        f"- Online: `{report['summary']['online']}`",
        f"- Total: `{report['summary']['total']}`",
        "",
        "## Servers",
    ]
    for item in report["servers"]:
        lines.extend(
            [
                f"- `{item['name']}`",
                f"  - enabled: `{item['enabled']}`",
                f"  - status: `{item['status']}`",
                f"  - url: `{item['url']}`",
                f"  - health_error: `{item.get('health_error', '')}`",
            ]
        )
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return path


def main() -> int:
    parser = argparse.ArgumentParser(description="Validate configured MCP servers")
    parser.add_argument("--enabled-only", action="store_true", help="Check only enabled servers")
    parser.add_argument("--timeout", type=int, default=3, help="HTTP timeout in seconds")
    args = parser.parse_args()

    manager = MCPCloudManager()
    results: List[Dict[str, Any]] = []

    for name, client in manager.servers.items():
        client.timeout = args.timeout
        meta = manager.server_meta.get(name, {})
        enabled = bool(meta.get("enabled", True))
        if args.enabled_only and not enabled:
            continue

        health = client.health_check()
        item: Dict[str, Any] = {
            "name": name,
            "url": client.server_url,
            "enabled": enabled,
            "status": "online" if health.get("status") == "ok" else "offline",
            "health": health,
        }
        if "error" in health:
            item["health_error"] = health["error"]
        if item["status"] == "online":
            resources = client.list_resources()
            tools = client.list_tools()
            item["resources_ok"] = "resources" in resources
            item["tools_ok"] = "tools" in tools
        results.append(item)

    report = {
        "timestamp": datetime.now().isoformat(),
        "servers": results,
        "summary": {
            "online": sum(1 for item in results if item["status"] == "online"),
            "total": len(results),
        },
    }
    report_path = write_report(report)
    print(json.dumps({**report, "report_path": str(report_path)}, indent=2))
    return 0 if report["summary"]["online"] > 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
