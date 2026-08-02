#!/usr/bin/env python3
"""Fail closed when production canary automation can create or repeat side effects."""
from __future__ import annotations

import json
import re
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "artifacts" / "canary-safety" / "report.json"
RECOVERY_WORKFLOW = ROOT / ".github" / "workflows" / "recover-boleto-canary.yml"
RECOVERY_SCRIPT = ROOT / "scripts" / "recover-boleto-canary.php"
LIVE_WORKFLOW = ROOT / ".github" / "workflows" / "live-order-canary.yml"
LIVE_SCRIPT = ROOT / "scripts" / "live-order-canary.php"


@dataclass(frozen=True)
class Finding:
    severity: str
    rule: str
    path: str
    line: int
    message: str


def line_number(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def add_matches(
    findings: list[Finding],
    *,
    path: Path,
    text: str,
    rule: str,
    pattern: str,
    message: str,
    flags: int = re.IGNORECASE | re.MULTILINE,
) -> None:
    for match in re.finditer(pattern, text, flags):
        findings.append(Finding(
            severity="critical",
            rule=rule,
            path=path.relative_to(ROOT).as_posix(),
            line=line_number(text, match.start()),
            message=message,
        ))


def main() -> int:
    findings: list[Finding] = []

    for required in (RECOVERY_WORKFLOW, RECOVERY_SCRIPT, LIVE_WORKFLOW, LIVE_SCRIPT):
        if not required.is_file():
            findings.append(Finding(
                severity="critical",
                rule="required_reconciliation_control_missing",
                path=required.relative_to(ROOT).as_posix(),
                line=1,
                message="Read-only reconciliation control is missing.",
            ))

    if LIVE_WORKFLOW.is_file():
        text = LIVE_WORKFLOW.read_text(encoding="utf-8", errors="replace")
        for pattern, rule, message in (
            (r"^\s{2}(?:push|schedule|pull_request)\s*:", "live_canary_automatic_trigger", "Disabled live-order canary cannot have an automatic trigger."),
            (r"environment\s*:\s*production", "live_canary_production_environment", "Disabled live-order canary cannot request the production environment."),
            (r"(?:ssh|scp|curl|php\s+.*live-order-canary)", "live_canary_execution_path", "Disabled live-order workflow cannot reach production or execute order code."),
        ):
            add_matches(findings, path=LIVE_WORKFLOW, text=text, rule=rule, pattern=pattern, message=message)
        if "exit 1" not in text or "Live Order Boleto Canary (Disabled)" not in text:
            findings.append(Finding(
                severity="critical",
                rule="live_canary_disable_guard_missing",
                path=LIVE_WORKFLOW.relative_to(ROOT).as_posix(),
                line=1,
                message="Live-order workflow must remain an explicit failing safety stub.",
            ))

    if LIVE_SCRIPT.is_file():
        text = LIVE_SCRIPT.read_text(encoding="utf-8", errors="replace")
        for pattern, rule, message in (
            (r"svmp_create_boleto|svem_send_order_email|MERCADOPAGO_ACCESS_TOKEN", "live_canary_side_effect_code", "Disabled live-order script cannot contain provider or email operations."),
            (r"SHOPVIVALIZ_LIVE_CANARY", "live_canary_enable_switch", "A local environment switch must not re-enable the production canary."),
        ):
            add_matches(findings, path=LIVE_SCRIPT, text=text, rule=rule, pattern=pattern, message=message)
        if "live_order_canary_disabled" not in text or "exit(2)" not in text:
            findings.append(Finding(
                severity="critical",
                rule="live_canary_stub_missing",
                path=LIVE_SCRIPT.relative_to(ROOT).as_posix(),
                line=1,
                message="Live-order script must remain a non-zero disabled stub.",
            ))

    if RECOVERY_WORKFLOW.is_file():
        text = RECOVERY_WORKFLOW.read_text(encoding="utf-8", errors="replace")
        add_matches(
            findings,
            path=RECOVERY_WORKFLOW,
            text=text,
            rule="production_recovery_on_push",
            pattern=r"^\s{2}push\s*:",
            message="Production recovery/reconciliation must never run from a push event.",
        )
        add_matches(
            findings,
            path=RECOVERY_WORKFLOW,
            text=text,
            rule="conditional_manual_confirmation",
            pattern=r"if\s*:\s*github\.event_name\s*==\s*['\"]workflow_dispatch['\"]",
            message="Confirmation cannot be conditional when the job can reach production.",
        )
        add_matches(
            findings,
            path=RECOVERY_WORKFLOW,
            text=text,
            rule="shell_fail_fast_disabled",
            pattern=r"^\s*set\s+\+e\s*$",
            message="Production reconciliation cannot disable shell fail-fast behavior.",
        )
        add_matches(
            findings,
            path=RECOVERY_WORKFLOW,
            text=text,
            rule="prior_evidence_deletion",
            pattern=r"rm\s+-f\s+['\"]?\$(?:marker|legacy_marker|evidence)['\"]?",
            message="Prior or immutable evidence must never be deleted before reconciliation.",
        )
        add_matches(
            findings,
            path=RECOVERY_WORKFLOW,
            text=text,
            rule="self_attested_verification",
            pattern=r"(?:email_delivery_verified|provider_result_verified)\s*['\"]?\]?\s*=.*(?:email_sent|boleto_created|digitable_line_present)",
            message="A result produced by the same operation cannot be labeled as independently verified.",
        )
        add_matches(
            findings,
            path=RECOVERY_WORKFLOW,
            text=text,
            rule="unknown_state_retry",
            pattern=r"(?:enrich_existing|retry_safe|retry_changed_schema|retry_pre_provider)",
            message="Unknown or partial state must block retries and require human/provider reconciliation.",
        )
        for required_text, rule, message in (
            ("workflow_dispatch:", "manual_dispatch_missing", "Workflow must be manual-only."),
            ("test \"$CONFIRMATION\" = 'RECONCILE'", "confirmation_missing", "Exact RECONCILE confirmation is required."),
            ("side_effects_attempted': False", "side_effect_contract_missing", "Evidence must explicitly assert that no side effect was attempted."),
            ("legacy_marker_unchanged", "immutable_marker_check_missing", "Workflow must prove that prior marker evidence was unchanged."),
        ):
            if required_text not in text:
                findings.append(Finding(
                    severity="critical",
                    rule=rule,
                    path=RECOVERY_WORKFLOW.relative_to(ROOT).as_posix(),
                    line=1,
                    message=message,
                ))

    if RECOVERY_SCRIPT.is_file():
        text = RECOVERY_SCRIPT.read_text(encoding="utf-8", errors="replace")
        forbidden = (
            (r"\bsvmp_create_boleto", "provider_creation_call", "Reconciliation script cannot create a boleto or provider order."),
            (r"\bsvem_send_order_email", "email_send_call", "Reconciliation script cannot send or resend email."),
            (r"MERCADOPAGO_ACCESS_TOKEN", "provider_credential_access", "Read-only reconciliation must not access provider credentials."),
            (r"LOCK_EX", "exclusive_order_lock", "Read-only reconciliation must not request an exclusive order lock."),
            (r"fopen\s*\([^\n]*['\"]r\+['\"]", "writable_order_open", "Order file must not be opened in a writable mode."),
            (r"\bf(?:write|truncate|flush)\s*\(", "order_write_primitive", "Read-only reconciliation cannot contain file-write primitives."),
        )
        for pattern, rule, message in forbidden:
            add_matches(
                findings,
                path=RECOVERY_SCRIPT,
                text=text,
                rule=rule,
                pattern=pattern,
                message=message,
            )
        for required_text, rule, message in (
            ("fopen($path, 'rb')", "readonly_open_missing", "Order file must be opened as rb."),
            ("flock($handle, LOCK_SH)", "shared_lock_missing", "Order file must use a shared lock."),
            ("'reconciliation_only' => true", "reconciliation_contract_missing", "Output must identify read-only reconciliation."),
            ("'side_effects_attempted' => false", "side_effect_contract_missing", "Output must state that no side effect was attempted."),
            ("ambiguous_state_retry_forbidden", "ambiguous_state_guard_missing", "Ambiguous state must fail closed without retry."),
        ):
            if required_text not in text:
                findings.append(Finding(
                    severity="critical",
                    rule=rule,
                    path=RECOVERY_SCRIPT.relative_to(ROOT).as_posix(),
                    line=1,
                    message=message,
                ))

    payload = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "status": "pass" if not findings else "fail",
        "finding_count": len(findings),
        "findings": [asdict(item) for item in findings],
    }
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 1 if findings else 0


if __name__ == "__main__":
    raise SystemExit(main())
