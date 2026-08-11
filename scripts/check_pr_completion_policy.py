#!/usr/bin/env python3
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
REQUIRED = {
    "REGRA-PR-FALHOU-CORRIGIR-NA-HORA.md": [
        "PR Conflict Auto-Healer",
        "PR Completion Enforcer",
        "PR verde não pode permanecer aberto",
        "Oracle",
    ],
    ".github/workflows/pr-conflict-auto-healer.yml": [
        "pull_request_target:",
        "schedule:",
        "cancel-in-progress: true",
        "SHOPVIVALIZ_VM_SSH_KEY",
        "ORACLE_VM_SSH_KEY",
        "github.token",
        "Preflight private Gemini credential pool",
        "GEMINI_ENV_FILE=/home/ubuntu/shopvivaliz-deploy/shared/.env",
        "scripts/pr_conflict_gemini_healer.py",
        "scripts/pr_conflict_vm_heal.sh",
        "github_write_token_copied_to_actions=false",
        "gemini_keys_copied_to_actions=false",
    ],
    ".github/workflows/pr-completion-enforcer.yml": [
        "workflow_run:",
        "schedule:",
        "cancel-in-progress: true",
        "SHOPVIVALIZ_VM_SSH_KEY",
        "ORACLE_VM_SSH_KEY",
        "github.token",
        "Quality Gate",
        "ShopVivaliz QA",
        "Repository Governance",
        "Policy Engine",
        "Autonomy Boundary",
        "History Integrity",
        "Ecommerce Excellence Audit",
        "PR Policy Enforcement",
        "compare/main...",
        "scripts/merge_green_pr_via_vm.sh",
        "merged_via_oracle=true",
        "github_write_token_copied_to_actions=false",
    ],
    ".github/workflows/pr-policy-enforcement.yml": [
        "pull_request:",
        "workflow_dispatch:",
        "test_pr_conflict_gemini_healer",
        "check_pr_completion_policy.py",
        "pr_conflict_vm_heal.sh",
        "merge_green_pr_via_vm.sh",
    ],
    "scripts/pr_conflict_gemini_healer.py": [
        "KEY_ENV_NAMES",
        "GOOGLE_IMAGEN_API_KEYS",
        "gemini-3.6-flash",
        "GEMINI_ENV_FILE",
        "parse_env_file",
        "credential_preflight",
        "gemini_pool_exhausted_for",
        "secret_values_printed=false",
    ],
    "scripts/pr_conflict_vm_heal.sh": [
        "ALLOW_SCOPED_PUSH",
        "protected branch publication is forbidden",
        "Oracle GitHub authentication unavailable",
        "GEMINI_ENV_FILE=\"$shared_env\"",
        "remote PR branch moved before publication",
        "publication would not be fast-forward",
        "git push origin",
    ],
    "scripts/merge_green_pr_via_vm.sh": [
        "Oracle GitHub authentication unavailable",
        "PR head SHA changed before merge",
        "PR head does not contain current main",
        "merge_method='squash'",
        "external_github_auth_used=true",
    ],
    "tests/test_pr_conflict_gemini_healer.py": [
        "test_private_env_parser_reads_only_gemini_names",
        "test_credential_environment_uses_private_file_and_deduplicates",
        "test_credential_preflight_reports_count_not_values",
        "test_rotates_to_next_key_after_quota_exhaustion",
        "test_model_fallback_does_not_discard_working_key_on_404",
    ],
}

FORBIDDEN = {
    ".github/workflows/pr-conflict-auto-healer.yml": [
        "GH_REPO_TOKEN",
        "secrets.GEMINI_API_KEY",
        "secrets.GOOGLE_GEMINI_API_KEY",
        "secrets.GOOGLE_IMAGEN_API_KEY",
        "secrets.GOOGLE_API_KEY",
        "git push",
    ],
    ".github/workflows/pr-completion-enforcer.yml": [
        "GH_REPO_TOKEN",
        "gh pr merge",
        "git push",
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

for relative, markers in FORBIDDEN.items():
    path = ROOT / relative
    if not path.is_file():
        continue
    text = path.read_text(encoding="utf-8", errors="replace")
    for marker in markers:
        if marker in text:
            failures.append(f"forbidden_marker:{relative}:{marker}")

if failures:
    for failure in failures:
        print(f"ERROR {failure}", file=sys.stderr)
    raise SystemExit(2)

print("OK PR_COMPLETION_POLICY_V3 Oracle-private enforcement is intact")
