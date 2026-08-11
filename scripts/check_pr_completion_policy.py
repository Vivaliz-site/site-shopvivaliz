#!/usr/bin/env python3
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
REQUIRED = {
    "REGRA-PR-FALHOU-CORRIGIR-NA-HORA.md": [
        "PR Conflict Auto-Healer",
        "PR Completion Enforcer",
        "PR verde não pode permanecer aberto",
        "credenciais Gemini",
    ],
    ".github/workflows/pr-conflict-auto-healer.yml": [
        "pull_request_target:",
        "schedule:",
        "cancel-in-progress: true",
        "GH_REPO_TOKEN",
        "GEMINI_API_KEY",
        "GOOGLE_GEMINI_API_KEY",
        "GOOGLE_IMAGEN_API_KEYS",
        "gemini-3.6-flash",
        "gemini_credentials_configured",
        "gemini_credential_rotation_enabled=true",
        "head_repo",
        "scripts/pr_conflict_gemini_healer.py",
        "scripts/publish_pr_branch_update.sh",
    ],
    ".github/workflows/pr-completion-enforcer.yml": [
        "workflow_run:",
        "schedule:",
        "cancel-in-progress: true",
        "GH_REPO_TOKEN",
        "Quality Gate",
        "ShopVivaliz QA",
        "Repository Governance",
        "Policy Engine",
        "Autonomy Boundary",
        "History Integrity",
        "Ecommerce Excellence Audit",
        "PR Policy Enforcement",
        "compare/main...",
        "merge_method='squash'",
    ],
    ".github/workflows/pr-policy-enforcement.yml": [
        "pull_request:",
        "workflow_dispatch:",
        "test_pr_conflict_gemini_healer",
        "check_pr_completion_policy.py",
        "publish_pr_branch_update.sh",
    ],
    "scripts/pr_conflict_gemini_healer.py": [
        "KEY_ENV_NAMES",
        "GOOGLE_IMAGEN_API_KEYS",
        "gemini-3.6-flash",
        "gemini_pool_exhausted_for",
        "secret_values_printed=false",
    ],
    "scripts/publish_pr_branch_update.sh": [
        "ALLOW_SCOPED_PUSH",
        "protected branch publication is forbidden",
        "checked-out branch does not match requested PR head ref",
        "update is not fast-forward",
        "git push",
    ],
    "tests/test_pr_conflict_gemini_healer.py": [
        "test_rotates_to_next_key_after_quota_exhaustion",
        "test_model_fallback_does_not_discard_working_key_on_404",
    ],
}

failures = []
for relative, markers in REQUIRED.items():
    path = ROOT / relative
    if not path.is_file():
        failures.append(f"missing:{relative}")
        continue
    text = path.read_text(encoding="utf-8", errors="replace")
    for marker in markers:
        if marker not in text:
            failures.append(f"missing_marker:{relative}:{marker}")

if failures:
    for failure in failures:
        print(f"ERROR {failure}", file=sys.stderr)
    raise SystemExit(2)

print("OK PR_COMPLETION_POLICY_V2 enforcement is intact")
