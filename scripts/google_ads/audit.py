from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

if __package__ in (None, ""):
    sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.google_ads.client import GoogleAdsClient, load_env
from scripts.google_ads.collector import collect_account
from scripts.google_ads.report import build_report


ENV_DEFAULT = "/home/ubuntu/shopvivaliz-deploy/shared/.env"
CONFIG_DEFAULT = "ops/google-ads/config.json"
OUTPUT_DEFAULT = "ops/google-ads/latest-readonly-audit.json"

AUTH_FAILURES = {"UNAUTHENTICATED", "PERMISSION_DENIED", "HTTP_401", "INVALID_GRANT"}


def _write_atomic(path: Path, report: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    temporary.replace(path)


def _arguments(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run the strictly read-only Google Ads audit")
    parser.add_argument("--env", default=ENV_DEFAULT)
    parser.add_argument("--config", default=CONFIG_DEFAULT)
    parser.add_argument("--output", default=OUTPUT_DEFAULT)
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = _arguments(argv)
    try:
        env = load_env(Path(args.env))
        config = json.loads(Path(args.config).read_text(encoding="utf-8"))
        client = GoogleAdsClient.from_env(env)
    except (OSError, ValueError, TypeError, json.JSONDecodeError) as error:
        print("GOOGLE_ADS_READONLY_AUDIT_NOT_CONFIGURED")
        print(f"reason={type(error).__name__}")
        return 2

    collected = collect_account(client)
    report = build_report(collected, config)
    _write_atomic(Path(args.output), report)

    auth_failure = any(
        error.get("reason") in AUTH_FAILURES for error in collected.get("errors", [])
    ) and not any(
        collected.get("windows", {}).get(window, {}).get(dataset)
        for window in collected.get("windows", {})
        for dataset in collected.get("windows", {}).get(window, {})
    )
    if auth_failure:
        print("GOOGLE_ADS_READONLY_AUDIT_AUTH_FAILED")
        print(f"report={args.output}")
        return 3

    marker = "GOOGLE_ADS_READONLY_AUDIT_PARTIAL" if report["partial"] else "GOOGLE_ADS_READONLY_AUDIT_OK"
    print(marker)
    print(f"report={args.output}")
    print(f"partial={str(report['partial']).lower()}")
    print(f"recommendations={report['optimization']['recommendation_count']}")
    print(f"tracking_health={report['tracking_health']['status']}")
    return 4 if report["partial"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
