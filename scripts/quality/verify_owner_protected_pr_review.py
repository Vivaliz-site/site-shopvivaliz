#!/usr/bin/env python3
"""Fail-closed status gate for owner-only PRs (VM deletion and price changes).

Intended to run from a *trusted base checkout* in the required validation gate.
A CI-green status alone is never an APPROVED review by shopvivaliz-human.
This tool grants no privileges and makes no GitHub mutations.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
from urllib.request import Request, urlopen

OWNER = "shopvivaliz-human"
OPERATOR = "fredmourao-ai"
DUAL_REQUIRED_REVIEWERS = frozenset({OWNER, OPERATOR})
SHA40 = re.compile(r"^[0-9a-f]{40}$")
REPO = re.compile(r"^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$")

# Only two change classes need the owner's approval. Each rule pairs the
# directories it applies to with the file-name terms that mark the change.
OWNER_SENSITIVE_RULES = (
    # VM deletion: tooling that terminates or destroys an instance.
    (("scripts/ops/", "deploy/"), ("terminate", "destroy", "vm-delete", "delete-instance")),
    # Price changes: pricing code in the storefront runtime.
    (("includes/", "api/"), ("pricing", "price", "preco", "preço")),
)

GOVERNANCE_PREFIXES = ("security/owner-approvals/", "security/requests/")
GOVERNANCE_FILES = frozenset({
    ".github/CODEOWNERS",
    "docs/knowledge/security-approval-via-pr.md",
    "scripts/validate-owner-pr-grant.py",
    "scripts/ops/activate_owner_merge_queue_ruleset.py",
})

RULES_FILES = frozenset({
    "AGENTS.md",
    "CLAUDE.md",
    "GEMINI.md",
    "AI-TO-CLI-PROTOCOL.md",
    "AI-AGENT-SAFETY.md",
    "AI-AGENT-CLI-SESSION-POLICY.md",
    "GLOBAL-CONTINUITY-PROTOCOL.md",
    "docs/knowledge/agent-rules.md",
})
RULES_PREFIXES = ("docs/knowledge/agent-rules/",)

TASKS_PREFIXES = (".github/workflows/",)
TASKS_FILES = frozenset({
    "scripts/agent_task_state.py",
    "scripts/ops/owner_approved_merge_queue.py",
    "scripts/ops/request_owner_pr_review.py",
    "scripts/quality/verify_owner_protected_pr_review.py",
})

SENSITIVE_FILES = frozenset({
    "marina-direct-mcp/root_broker.py",
    "marina-direct-mcp/owner_pr_gate.py",
    "remote-control-mcp/test_marina_admin_gate.py",
    "scripts/ops/activate-mcp-central-marina-readonly.py",
})

POLICY_DIRECTIVE_MARKERS = (
    "OWNER_DIRECTIVES_",
    "OWNER_NO_UNAPPROVED_RESTRICTIONS_",
    "SOLO_OWNER_PROPORTIONAL_GOVERNANCE_V1",
    "PR_HUMAN_VISIBILITY_ROUTING_V1",
    "PROTECTED_OWNER_REVIEW_REQUEST_VISIBILITY_V1",
    "OWNER_ONE_CLICK_MERGE_QUEUE_POLICY_P2",
    "DESKTOP_GUI_LOCK_PROHIBITION_",
    "ALL_LOCK_INSERTION_DUAL_APPROVAL_",
)

LOCK_PATTERNS = (
    # Desktop GUI actions in mutating / lock sets
    re.compile(r"""['"]desktop_(?:open|click|type)['"]\s*:"""),
    re.compile(r"""MUTATING_RUNTIME_ACTIONS.*['"]desktop_"""),
    re.compile(r"""_assert_runtime_mutation\(\s*['"]desktop_"""),
    re.compile(r"""runtime_lock\.assert_runtime_lock\(.*desktop_"""),
    re.compile(r"""assert_runtime_lock\(.*['"]desktop_"""),
    re.compile(r"""desktop_(?:open|click|type).*runtime_lease_id"""),
    re.compile(r"""desktop_(?:open|click|type).*runtime_fencing_token"""),
    re.compile(r"""(?:desktop_(?:open|click|type).*runtime_lock_required|runtime_lock_required.*desktop_(?:open|click|type))"""),

    # Generic MCP tool / runtime lock & lease additions
    re.compile(r"""\bMUTATING_RUNTIME_ACTIONS\b"""),
    re.compile(r"""\bMUTATING_ACTIONS\b\s*(?:\.add|\+=|\|?=|:)"""),
    re.compile(r"""\bassert_runtime_lock\b"""),
    re.compile(r"""\b_assert_runtime_mutation\b"""),
    re.compile(r"""['"]runtime_lock_required['"]"""),
    re.compile(r"""\bruntime_lease_id\b"""),
    re.compile(r"""\bruntime_fencing_token\b"""),
    re.compile(r"""\bassert_runtime_lease\b"""),

    # Command / tool / action restriction lists
    re.compile(r"""\b(?:BLOCKED|RESTRICTED|DISALLOWED|FORBIDDEN)_(?:COMMANDS|ACTIONS|TOOLS|OPERATIONS)\b"""),
    re.compile(r"""\b(?:blocked|restricted|disallowed|forbidden)_(?:commands|actions|tools|operations)\b\s*="""),
    re.compile(r"""raise\s+(?:\w+Error|Exception)\(['"].*(?:runtime_lock|lock_required|action_blocked|command_blocked|operacao_bloqueada|trava_obrigatoria).*['"]\)"""),

    # Policy / prompt restriction markers
    re.compile(r"""(?i)\b(?:nova\s+trava|novo\s+bloqueio|bloqueio\s+de\s+ferramenta|trava\s+de\s+seguranca)\b"""),
)
DESKTOP_LOCK_PATTERNS = LOCK_PATTERNS


# Classificacao por EFEITO do diff, nunca somente pelo caminho.
POLICY_DENIAL_PATTERNS = (
    re.compile(r"(?i)\b(?:proibid[oa]|vedad[oa]|bloque(?:ar|ia|ado|ada)|imped(?:ir|e)|restringir)\b.{0,140}\b(?:execu[cç][aã]o|processo|comando|ferramenta|acesso|agente|workflow|deploy|merge)\b"),
    re.compile(r"(?i)\b(?:n[aã]o\s+(?:deve|pode|poder[aá])|fica\s+proibido)\b.{0,120}\b(?:executar|acessar|utilizar|invocar|integrar|mesclar|publicar)\b"),
)
TEXT_EXTENSIONS = (
    ".md", ".rst", ".txt", ".py", ".sh", ".php", ".js", ".jsx", ".ts",
    ".tsx", ".mjs", ".cjs", ".json", ".yml", ".yaml", ".toml", ".ini",
    ".cfg", ".xml", ".sql", ".go", ".rb", ".rs", ".java",
)


def missing_inspectable_patch(files: list[dict]) -> bool:
    """Diff textual ausente impede a classificacao; nao exige revisores por caminho."""
    for f in files:
        path = f.get("filename", "")
        if f.get("status") != "removed" and (
            path.endswith(TEXT_EXTENSIONS) or path in RULES_FILES
        ) and not isinstance(f.get("patch"), str):
            return True
    return False


def is_lock_insertion(f: dict) -> bool:
    """Detecta adicoes RESTRITIVAS reais, sem converter toda edicao em gate duplo."""
    if not isinstance(f, dict) or not isinstance(f.get("patch"), str):
        return False
    path = f.get("filename", "")
    lines = f["patch"].splitlines()
    removed = {line[1:].strip() for line in lines if line.startswith("-") and not line.startswith("---")}
    for line in lines:
        if not line.startswith("+") or line.startswith("+++"):
            continue
        added = line[1:].strip()
        if not added or added in removed:
            continue  # linha movida/inalterada nao cria novo controle
        if path.endswith((".md", ".rst", ".txt")):
            if any(pattern.search(added) for pattern in POLICY_DENIAL_PATTERNS):
                return True
        elif path.startswith("tests/") or path.rsplit("/", 1)[-1].startswith("test_"):
            continue  # exemplos/fixtures de teste nao sao regras de runtime
        elif any(pattern.search(added) for pattern in LOCK_PATTERNS):
            return True
    return False


is_desktop_gui_lock_restoration = is_lock_insertion


def is_protected_file(f: dict | str) -> bool:
    """Somente uma adicao ou agravamento de trava exige dupla revisao."""
    return isinstance(f, dict) and is_lock_insertion(f)


def requires_dual_approval(files: list[dict | str]) -> bool:
    """A revisao dupla nunca decorre apenas do nome do arquivo."""
    return any(is_protected_file(f) for f in files)


requires_lock_dual_approval = requires_dual_approval
requires_desktop_lock_dual_approval = requires_dual_approval


def is_protected_path(path: str) -> bool:
    name = path.rsplit("/", 1)[-1].lower()
    return any(path.startswith(dirs) and any(term in name for term in terms)
               for dirs, terms in OWNER_SENSITIVE_RULES)


def verify_approvals(reviews: list[dict], required_users: set[str] | frozenset[str], head_sha: str) -> tuple[bool, str]:
    if not isinstance(reviews, list) or len(reviews) >= 100:
        return False, "owner_reviews_unavailable_or_incomplete"
    approved_users: set[str] = set()
    for user_login in required_users:
        user_reviews = []
        for review in reviews:
            if not isinstance(review, dict):
                return False, "review_shape_invalid"
            user = review.get("user")
            if not isinstance(user, dict):
                return False, "review_actor_missing"
            if str(user.get("login", "")).casefold() == user_login.casefold():
                if review.get("state") in ("APPROVED", "CHANGES_REQUESTED", "DISMISSED"):
                    user_reviews.append(review)
        if not user_reviews:
            continue
        if any(not isinstance(r.get("submitted_at"), str) or not r["submitted_at"] for r in user_reviews):
            return False, "owner_review_timestamp_missing"
        latest = max(user_reviews, key=lambda r: r["submitted_at"])
        if latest.get("state") != "APPROVED":
            return False, "latest_owner_review_not_approved" if len(required_users) == 1 else f"latest_review_by_{user_login}_not_approved"
        if latest.get("commit_id") != head_sha:
            return False, "approval_not_bound_to_current_head" if len(required_users) == 1 else f"approval_by_{user_login}_not_bound_to_current_head"
        approved_users.add(user_login.casefold())

    missing = {u for u in required_users if u.casefold() not in approved_users}
    if missing:
        if len(missing) == len(required_users):
            return False, "dual_owner_approval_missing" if len(required_users) > 1 else "owner_approval_missing"
        return False, f"dual_approval_missing_{sorted(missing)[0]}"
    return True, "dual_owner_review_approved_exact_head" if len(required_users) > 1 else "owner_review_approved_exact_head"


def decide(files: object, reviews: object, head_sha: str, changed_files: object = None, pr_author: str | None = None) -> tuple[bool, str]:
    """Fail closed for untrusted/incomplete GitHub pages and stale approvals."""
    if not isinstance(head_sha, str) or not SHA40.fullmatch(head_sha):
        return False, "invalid_head_sha"
    if not isinstance(files, list) or len(files) >= 100:
        return False, "pr_files_unavailable_or_incomplete"
    if not files:
        if changed_files == 0:
            return True, "non_protected_pr"
        return False, "pr_files_unavailable_or_incomplete"
    if any(not isinstance(f, dict) or not isinstance(f.get("filename"), str) for f in files):
        return False, "pr_files_invalid"

    # Ausencia de diff textual e erro de classificacao, nao revisor obrigatorio.
    if missing_inspectable_patch(files):
        return False, "pr_text_patch_unavailable"
    if requires_dual_approval(files):
        required = set(DUAL_REQUIRED_REVIEWERS)
        if pr_author and pr_author.casefold() in {u.casefold() for u in required}:
            # Nenhuma identidade pode aprovar sua propria PR no GitHub.
            return False, "protected_author_must_be_independent"
        return verify_approvals(reviews, required, head_sha)

    # Standard Check: VM deletion or price changes require shopvivaliz-human
    protected = sorted({f["filename"] for f in files if is_protected_path(f["filename"])})
    if not protected:
        return True, "non_protected_pr"

    return verify_approvals(reviews, {OWNER}, head_sha)


def github_json(repo: str, api_path: str, token: str):
    request = Request(
        "https://api.github.com/repos/" + repo + api_path,
        headers={
            "Authorization": "Bearer " + token,
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "shopvivaliz-owner-review-gate",
        },
    )
    with urlopen(request, timeout=12) as response:
        raw = response.read(1_000_001)
    if len(raw) > 1_000_000:
        raise ValueError("github_response_too_large")
    return json.loads(raw)


def main(argv=None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo", required=True)
    parser.add_argument("--pr", required=True, type=int)
    parser.add_argument("--head", required=True)
    args = parser.parse_args(argv)
    token = os.getenv("GITHUB_TOKEN", "")
    if not REPO.fullmatch(args.repo) or args.pr <= 0 or not SHA40.fullmatch(args.head):
        print("OWNER_REVIEW_GATE=DENIED reason=invalid_args")
        return 2
    if not token or len(token) < 10:
        print("OWNER_REVIEW_GATE=DENIED reason=github_token_unavailable")
        return 2
    try:
        pr = github_json(args.repo, f"/pulls/{args.pr}", token)
        if not isinstance(pr, dict) or pr.get("state") != "open":
            raise ValueError("pr_not_open")
        if pr.get("head", {}).get("sha") != args.head:
            raise ValueError("pr_head_moved")
        if pr.get("base", {}).get("ref") != "main":
            raise ValueError("unexpected_base")
        if pr.get("head", {}).get("repo", {}).get("full_name") != args.repo:
            raise ValueError("untrusted_head_repo")
        files = github_json(args.repo, f"/pulls/{args.pr}/files?per_page=100", token)
        if not isinstance(files, list) or len(files) >= 100:
            raise ValueError("pr_files_unavailable_or_incomplete")
        protected = requires_dual_approval(files) or any(
            is_protected_path(f.get("filename", "")) for f in files if isinstance(f, dict)
        )
        reviews = (github_json(args.repo, f"/pulls/{args.pr}/reviews?per_page=100", token)
                   if protected else [])
        pr_author = pr.get("user", {}).get("login") if isinstance(pr.get("user"), dict) else None
        approved, reason = decide(files, reviews, args.head, pr.get("changed_files"), pr_author)
    except (OSError, ValueError, KeyError, TypeError, json.JSONDecodeError) as exc:
        print("OWNER_REVIEW_GATE=DENIED reason=github_verification_unavailable")
        print(f"OWNER_REVIEW_DIAGNOSTIC={type(exc).__name__}")
        return 2
    print(f"OWNER_REVIEW_GATE={'PASS' if approved else 'DENIED'} reason={reason}")
    return 0 if approved else 3


if __name__ == "__main__":
    sys.exit(main())
