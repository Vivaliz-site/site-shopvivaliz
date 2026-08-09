#!/usr/bin/env python3
"""Run evidence-based readiness audits for documented agents.

This command audits repository readiness. It never reports that an external
sync, deploy, payment, advertising, or marketplace mutation occurred.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "reports" / "all-documented-agents"
REPORT_JSON = OUT / "latest.json"
REPORT_MD = OUT / "latest.md"
TEXT_SUFFIXES = {".php", ".js", ".ts", ".tsx", ".jsx", ".html", ".css", ".json", ".md", ".py", ".sh", ".yml", ".yaml"}
SKIP_PARTS = {".git", "node_modules", "vendor", "reports", "storage", "test-results", "artifacts"}
ALLOWED_STATES = {"blocked", "failed", "completed_verified"}


@dataclass(frozen=True)
class AgentSpec:
    name: str
    patterns: tuple[str, ...] = ()
    required_paths: tuple[str, ...] = ()
    required_env: tuple[str, ...] = ()
    external: bool = False


@dataclass(frozen=True)
class AgentResult:
    name: str
    status: str
    scope: str
    external_operation_performed: bool
    reason: str
    evidence: dict[str, object]


SPECS: tuple[AgentSpec, ...] = (
    AgentSpec("designer", (r"<main\b", r"<img\b")),
    AgentSpec("analytics", (r"gtag\(|dataLayer|google-analytics", r"purchase|transaction_id")),
    AgentSpec("deploy-manager", (r"deploy|production",), (".github/workflows/deploy.yml",)),
    AgentSpec("security", (r"gitleaks|security|secret",), (".github/workflows/quality-gate.yml",)),
    AgentSpec("image-optimization", (r"webp|loading=[\"']lazy",)),
    AgentSpec("checkout-flow", (r"checkout", r"carrinho|cart")),
    AgentSpec("freight-calculation", (r"frete|shipping|freight", r"\bCEP\b|postal")),
    AgentSpec("release-manager", (r"release|changelog",)),
    AgentSpec("qa-self-test", (), (".github/workflows/quality-gate.yml", "tests/unit")),
    AgentSpec("selenium-test-runner", (r"selenium|playwright",)),
    AgentSpec("config-validator", (), ("config",)),
    AgentSpec("tri-environment-sync", (r"Oracle|production|localhost",)),
    AgentSpec("inventory-monitor", (r"estoque|inventory|stock",)),
    AgentSpec("seo-optimization", (r"rel=[\"']canonical", r"application/ld\+json|sitemap")),
    AgentSpec("sensor", (r"health|latency|performance",)),
    AgentSpec("director", (r"orchestrator|director|priorit",)),
    AgentSpec("executor", (r"executor|execute_task|process_task", r"evidence|artifact|sha256")),
    AgentSpec("validator", (r"validation|policy|audit",)),
    AgentSpec("orchestrator", (r"orchestrator",), (".ai/agents.js",)),
    AgentSpec("olist-sync", (r"olist|tiny\s*erp",), required_env=("OLIST_ACCESS_TOKEN",), external=True),
    AgentSpec("shopee", (r"shopee",), required_env=("SHOPEE_PARTNER_ID", "SHOPEE_PARTNER_KEY", "SHOPEE_SHOP_ID"), external=True),
    AgentSpec("mercado-livre", (r"mercado\s*livre|mercadolivre",), required_env=("MERCADOLIVRE_ACCESS_TOKEN",), external=True),
    AgentSpec("google-ads", (r"google\s*ads|google_ads",), required_env=("GOOGLE_ADS_DEVELOPER_TOKEN",), external=True),
)


def repository_files() -> list[Path]:
    result: list[Path] = []
    for path in ROOT.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in TEXT_SUFFIXES:
            continue
        relative = path.relative_to(ROOT)
        if any(part in SKIP_PARTS for part in relative.parts):
            continue
        result.append(path)
    return sorted(result)


def commit_sha() -> str:
    result = subprocess.run(
        ["git", "rev-parse", "HEAD"],
        cwd=ROOT,
        text=True,
        encoding="utf-8",
        errors="replace",
        capture_output=True,
        check=True,
    )
    value = result.stdout.strip()
    if not re.fullmatch(r"[0-9a-f]{40}", value):
        raise RuntimeError("git rev-parse did not return a full commit SHA")
    return value


def read_corpus(paths: Iterable[Path]) -> list[tuple[Path, str]]:
    return [(path, path.read_text(encoding="utf-8", errors="replace")) for path in paths]


def evaluate_agent(spec: AgentSpec, corpus: list[tuple[Path, str]], sha: str) -> AgentResult:
    missing_paths = [path for path in spec.required_paths if not (ROOT / path).exists()]
    pattern_counts: dict[str, int] = {}
    matched_files: set[str] = set()
    for pattern in spec.patterns:
        regex = re.compile(pattern, re.I | re.S)
        count = 0
        for path, text in corpus:
            matches = list(regex.finditer(text))
            if matches:
                count += len(matches)
                matched_files.add(path.relative_to(ROOT).as_posix())
        pattern_counts[pattern] = count

    missing_patterns = [pattern for pattern, count in pattern_counts.items() if count == 0]
    missing_env = [name for name in spec.required_env if not os.getenv(name)]
    evidence: dict[str, object] = {
        "commit_sha": sha,
        "artifact": REPORT_JSON.relative_to(ROOT).as_posix(),
        "verification": "repository_readiness_audit",
        "required_paths": list(spec.required_paths),
        "missing_paths": missing_paths,
        "pattern_counts": pattern_counts,
        "matched_files": sorted(matched_files)[:100],
        "required_environment_names": list(spec.required_env),
        "configured_environment_names": [name for name in spec.required_env if name not in missing_env],
    }

    if missing_paths or missing_patterns:
        return AgentResult(
            spec.name,
            "failed",
            "readiness_audit",
            False,
            "required repository evidence is missing",
            {**evidence, "missing_patterns": missing_patterns},
        )

    if spec.external and missing_env:
        return AgentResult(
            spec.name,
            "blocked",
            "readiness_audit",
            False,
            "required external credentials are not configured",
            {**evidence, "missing_environment_names": missing_env},
        )

    return AgentResult(
        spec.name,
        "completed_verified",
        "readiness_audit",
        False,
        "repository readiness audit completed with verifiable local evidence",
        evidence,
    )


def render_report(results: list[AgentResult], sha: str, generated_at: str) -> dict[str, object]:
    payload: dict[str, object] = {
        "schema_version": 2,
        "generated_at": generated_at,
        "commit_sha": sha,
        "operation_scope": "repository_readiness_audit",
        "external_operations_performed": False,
        "allowed_states": sorted(ALLOWED_STATES),
        "agents": {result.name: asdict(result) for result in results},
        "summary": {
            state: sum(result.status == state for result in results)
            for state in sorted(ALLOWED_STATES)
        },
    }
    OUT.mkdir(parents=True, exist_ok=True)
    REPORT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    digest = hashlib.sha256(REPORT_JSON.read_bytes()).hexdigest()
    payload["report_sha256"] = digest
    REPORT_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Auditoria de prontidão dos agentes documentados",
        "",
        f"Commit: `{sha}`",
        f"Gerado: `{generated_at}`",
        "Escopo: auditoria local de prontidão; nenhuma operação externa foi executada.",
        "",
    ]
    for result in results:
        lines.extend([
            f"## {result.name}",
            f"- Estado: `{result.status}`",
            f"- Motivo: {result.reason}",
            f"- Evidência: `{json.dumps(result.evidence, ensure_ascii=False, sort_keys=True)}`",
            "",
        ])
    lines.append(f"SHA-256 JSON: `{digest}`")
    REPORT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return payload


def main() -> int:
    parser = argparse.ArgumentParser(description="Audit documented agent readiness with evidence")
    parser.add_argument("agent", nargs="?", default="all")
    args = parser.parse_args()

    selected = list(SPECS) if args.agent == "all" else [spec for spec in SPECS if spec.name == args.agent]
    if not selected:
        print(json.dumps({"error": "unknown agent", "allowed": [spec.name for spec in SPECS]}))
        return 64

    sha = commit_sha()
    corpus = read_corpus(repository_files())
    results = [evaluate_agent(spec, corpus, sha) for spec in selected]
    payload = render_report(results, sha, datetime.now(timezone.utc).isoformat())
    print(json.dumps(payload["summary"], ensure_ascii=False, sort_keys=True))

    if any(result.status == "failed" for result in results):
        return 2
    if any(result.status == "blocked" for result in results):
        return 3
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
