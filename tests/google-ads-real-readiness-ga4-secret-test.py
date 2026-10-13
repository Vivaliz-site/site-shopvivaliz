#!/usr/bin/env python3
"""Regression: Google Ads GA4-import readiness must require server-side purchase secret."""

from __future__ import annotations

import contextlib
import importlib.util
import io
import json
import os
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "google_ads_real_readiness.py"


def main() -> int:
    spec = importlib.util.spec_from_file_location("google_ads_real_readiness", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)

    config = json.loads((ROOT / "scripts" / "google_ads_campaign_live_ready.json").read_text(encoding="utf-8"))
    guardrails = config.setdefault("guardrails", {})
    guardrails.update(
        {
            "target_average_order_value_brl": 1000.0,
            "target_conversion_rate_percent": 10.0,
            "target_roi": 10.0,
        }
    )

    env = {
        "GOOGLE_OAUTH_CLIENT_ID": "client.apps.googleusercontent.com",
        "GOOGLE_OAUTH_CLIENT_SECRET": "secret-value",
        "GOOGLE_ADS_CUSTOMER_ID": "5283091103",
        "GOOGLE_ADS_DEVELOPER_TOKEN": "ads-token-value",
        "GOOGLE_ADS_REFRESH_TOKEN": "refresh-value",
        "GOOGLE_ADS_CONVERSION_SOURCE": "GA4_IMPORT",
        "GOOGLE_ANALYTICS_ID": "G-1H55K1TZ5D",
        "GOOGLE_ADS_GA4_IMPORT_VERIFIED": "true",
    }
    previous = {key: os.environ.get(key) for key in set(env) | {"GA4_SECRET"}}
    try:
        os.environ.update(env)
        os.environ.pop("GA4_SECRET", None)
        with tempfile.TemporaryDirectory() as tmp:
            config_path = Path(tmp) / "config.json"
            config_path.write_text(json.dumps(config), encoding="utf-8")
            module.CONFIG = config_path
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                code = module.main()
            text = output.getvalue()
    finally:
        for key, value in previous.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value

    assert code == 1, f"expected NOT_READY without GA4_SECRET, got code={code}: {text}"
    assert "ga4_purchase_server_side_secret_missing" in text, text
    print("google-ads-real-readiness-ga4-secret-test: ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
