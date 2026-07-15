#!/usr/bin/env python3
"""
Deploy Validator - valida se a publicacao esta realmente servindo a versao esperada.
"""
from __future__ import annotations

import argparse
import json
import sys
import urllib.error
import urllib.request
from pathlib import Path


def fetch(url: str, timeout: int) -> tuple[int, str]:
    request = urllib.request.Request(
        url,
        headers={
            "User-Agent": "ShopVivalizDeployValidator/1.0",
            "Cache-Control": "no-cache",
            "Pragma": "no-cache",
        },
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        body = response.read().decode("utf-8", errors="replace")
        return response.getcode(), body


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", required=True)
    parser.add_argument("--timeout", type=int, default=20)
    parser.add_argument("--expect", action="append", default=[])
    parser.add_argument("--reject", action="append", default=[])
    parser.add_argument("--report", default="reports/deploy-validation.json")
    args = parser.parse_args()

    report = {
        "ok": False,
        "url": args.url,
        "status_code": None,
        "checks": [],
        "errors": [],
    }

    try:
        status_code, body = fetch(args.url, args.timeout)
        report["status_code"] = status_code
    except urllib.error.HTTPError as exc:
        report["status_code"] = exc.code
        report["errors"].append(f"HTTP {exc.code} ao acessar {args.url}")
        body = exc.read().decode("utf-8", errors="replace")
    except Exception as exc:  # pragma: no cover
        report["errors"].append(f"Falha de rede ao acessar {args.url}: {exc}")
        body = ""

    if report["status_code"] != 200:
        report["errors"].append(f"Status inesperado: {report['status_code']}")

    lowered = body.lower()
    generic_rejects = [
        "fatal error",
        "parse error",
        "uncaught exception",
        "warning: ",
        "not found",
        "<title>error",
        "deprecated",
        "there has been a critical error",
        "<<<<<<<",
        ">>>>>>>",
    ]
    for token in generic_rejects + list(args.reject):
        ok = token.lower() not in lowered
        report["checks"].append({"type": "reject", "token": token, "ok": ok})
        if not ok:
            report["errors"].append(f"Token proibido encontrado: {token}")

    for token in args.expect:
        ok = token in body
        report["checks"].append({"type": "expect", "token": token, "ok": ok})
        if not ok:
            report["errors"].append(f"Token esperado ausente: {token}")

    report["ok"] = len(report["errors"]) == 0

    report_path = Path(args.report)
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
