#!/usr/bin/env python3
"""MCP server for read-only Google Ads diagnostics.

The tools in this server never mutate Google Ads. They either inspect local
configuration or call the Google Ads API with SELECT queries only.
"""

from __future__ import annotations

import asyncio
import json
import os
import subprocess
import sys
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
REQUIRED_ENV = [
    "GOOGLE_OAUTH_CLIENT_ID",
    "GOOGLE_OAUTH_CLIENT_SECRET",
    "GOOGLE_ADS_CUSTOMER_ID",
    "GOOGLE_ADS_DEVELOPER_TOKEN",
    "GOOGLE_ADS_REFRESH_TOKEN",
]
MANUAL_CONVERSION_ENV = ["GOOGLE_ADS_ID", "GOOGLE_ADS_CONVERSION_LABEL"]
GA4_IMPORT_ENV = ["GOOGLE_ANALYTICS_ID"]


def load_dotenv(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.is_file():
        return values
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip("\"'")
    return values


def is_placeholder(value: str) -> bool:
    lowered = value.strip().lower()
    return (
        lowered == ""
        or lowered in {"placeholder", "changeme", "xxx"}
        or lowered.startswith(("obter", "g-xx", "your_"))
        or "developer_token" in lowered
    )


def run_script(script: str) -> dict[str, Any]:
    result = subprocess.run(
        [sys.executable, str(ROOT / "scripts" / script)],
        cwd=str(ROOT),
        text=True,
        capture_output=True,
        timeout=120,
    )
    return {
        "status": "COMPROVADO" if result.returncode == 0 else "FALHOU",
        "returncode": result.returncode,
        "stdout": result.stdout.strip(),
        "stderr": result.stderr.strip(),
    }


def summarize_config(path: Path) -> dict[str, Any]:
    if not path.is_file():
        return {"path": str(path), "exists": False}
    config = json.loads(path.read_text(encoding="utf-8"))
    campaign = config.get("campaign", {}) or config.get("campanha_agressiva", {})
    if not isinstance(campaign, dict):
        campaign = {}
    ad = config.get("responsive_search_ad", {}) or config.get("ads", {}) or config.get("anuncios_agressivos", {})
    if not isinstance(ad, dict):
        ad = {}
    keywords = flatten_keywords(config)
    negative_keywords = config.get("negative_keywords", [])
    if not isinstance(negative_keywords, list):
        negative_keywords = config.get("negativas_agressivas", {}).get("keywords", [])
    if not isinstance(negative_keywords, list):
        negative_keywords = []
    headlines = ad.get("headlines", []) or ad.get("headlines_focus_kit_grande", [])
    if not isinstance(headlines, list):
        headlines = []
    descriptions = ad.get("descriptions", []) or ad.get("descriptions_focus_economia", [])
    if not isinstance(descriptions, list):
        descriptions = []
    return {
        "path": str(path),
        "exists": True,
        "campaign_name": campaign.get("name") or campaign.get("nome"),
        "status_on_create": campaign.get("status_on_create") or campaign.get("status"),
        "daily_budget_brl": campaign.get("daily_budget_brl") or campaign.get("daily_budget") or campaign.get("budget_diario"),
        "bidding": campaign.get("bidding"),
        "keywords": len(keywords),
        "broad_keywords": sum(
            1
            for item in keywords
            if isinstance(item, dict)
            and str(item.get("match_type") or item.get("match") or item.get("tipo") or "").upper() == "BROAD"
        ),
        "negative_keywords": len(negative_keywords),
        "headlines": len(headlines),
        "long_headlines": [text for text in headlines if isinstance(text, str) and len(text) > 30],
        "descriptions": len(descriptions),
        "long_descriptions": [text for text in descriptions if isinstance(text, str) and len(text) > 90],
    }


def flatten_keywords(config: dict[str, Any]) -> list[Any]:
    raw = config.get("keywords")
    if isinstance(raw, list):
        return raw
    if isinstance(raw, dict):
        items: list[Any] = []
        for value in raw.values():
            if isinstance(value, list):
                items.extend(value)
        return items
    aggressive = config.get("keywords_agressivas", {})
    if isinstance(aggressive, dict) and isinstance(aggressive.get("keywords"), list):
        return aggressive["keywords"]
    return []


class GoogleAdsReadonlyMCP:
    def __init__(self) -> None:
        self.tools = [
            {
                "name": "google_ads_env_status",
                "description": "Check whether required Google Ads environment variables are present without exposing values.",
                "inputSchema": {"type": "object", "properties": {}},
            },
            {
                "name": "google_ads_readiness",
                "description": "Run the local fail-closed Google Ads readiness diagnostic.",
                "inputSchema": {"type": "object", "properties": {}},
            },
            {
                "name": "google_ads_local_config_summary",
                "description": "Summarize local Google Ads campaign JSON configs without changing anything.",
                "inputSchema": {"type": "object", "properties": {}},
            },
            {
                "name": "google_ads_review_campaigns",
                "description": "Read recent live Google Ads campaign metrics via API. Requires valid env credentials.",
                "inputSchema": {"type": "object", "properties": {}},
            },
        ]

    async def initialize(self, request: dict[str, Any]) -> dict[str, Any]:
        return {
            "jsonrpc": "2.0",
            "id": request.get("id"),
            "result": {
                "protocolVersion": "2024-11-05",
                "capabilities": {"tools": {}},
                "serverInfo": {"name": "shopvivaliz-google-ads-readonly", "version": "1.0.0"},
            },
        }

    async def tools_list(self, request: dict[str, Any]) -> dict[str, Any]:
        return {"jsonrpc": "2.0", "id": request.get("id"), "result": {"tools": self.tools}}

    async def tools_call(self, request: dict[str, Any]) -> dict[str, Any]:
        params = request.get("params", {})
        name = params.get("name")
        try:
            if name == "google_ads_env_status":
                result = self.google_ads_env_status()
            elif name == "google_ads_readiness":
                result = run_script("google_ads_real_readiness.py")
            elif name == "google_ads_local_config_summary":
                result = self.google_ads_local_config_summary()
            elif name == "google_ads_review_campaigns":
                result = run_script("google_ads_review_campaigns.py")
            else:
                raise ValueError(f"Unknown tool: {name}")
            return {
                "jsonrpc": "2.0",
                "id": request.get("id"),
                "result": {"content": [{"type": "text", "text": json.dumps(result, indent=2)}], "isError": False},
            }
        except Exception as exc:
            return {
                "jsonrpc": "2.0",
                "id": request.get("id"),
                "result": {"content": [{"type": "text", "text": str(exc)}], "isError": True},
            }

    def google_ads_env_status(self) -> dict[str, Any]:
        env = {**load_dotenv(ROOT / ".env"), **os.environ}
        conversion_source = env.get("GOOGLE_ADS_CONVERSION_SOURCE", "MANUAL_GTAG").strip().upper()
        required = REQUIRED_ENV + (GA4_IMPORT_ENV if conversion_source == "GA4_IMPORT" else MANUAL_CONVERSION_ENV)
        keys = {
            key: {
                "present": bool(env.get(key, "")),
                "placeholder_or_missing": is_placeholder(env.get(key, "")),
            }
            for key in required
        }
        missing = [key for key, info in keys.items() if info["placeholder_or_missing"]]
        return {
            "status": "COMPROVADO" if not missing else "FALHOU",
            "conversion_source": conversion_source,
            "required_env": keys,
            "missing_or_placeholder_env": missing,
        }

    def google_ads_local_config_summary(self) -> dict[str, Any]:
        files = [
            ROOT / "scripts" / "google_ads_campaign_config.json",
            ROOT / "scripts" / "google_ads_campaign_live_ready.json",
            ROOT / "scripts" / "google_ads_campaign_10x_roi.json",
        ]
        return {"status": "COMPROVADO", "configs": [summarize_config(path) for path in files]}

    async def handle(self, request: dict[str, Any]) -> dict[str, Any] | None:
        method = request.get("method")
        if method == "initialize":
            return await self.initialize(request)
        if method == "notifications/initialized":
            return None
        if method == "tools/list":
            return await self.tools_list(request)
        if method == "tools/call":
            return await self.tools_call(request)
        return {
            "jsonrpc": "2.0",
            "id": request.get("id"),
            "error": {"code": -32601, "message": f"Unknown method: {method}"},
        }


async def main() -> None:
    server = GoogleAdsReadonlyMCP()
    while True:
        line = sys.stdin.readline()
        if not line:
            break
        try:
            request = json.loads(line)
            response = await server.handle(request)
        except Exception as exc:
            response = {"jsonrpc": "2.0", "id": None, "error": {"code": -32603, "message": str(exc)}}
        if response is not None:
            sys.stdout.write(json.dumps(response, ensure_ascii=True) + "\n")
            sys.stdout.flush()


if __name__ == "__main__":
    asyncio.run(main())
