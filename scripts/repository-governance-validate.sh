#!/usr/bin/env bash
set -Eeuo pipefail
root="$(git rev-parse --show-toplevel)"; cd "$root"
phase="${1:-manual}"
# Queue base SHA reaches only the lint phase as a plain shell variable. It is not
# exported, so unit tests run by this script never inherit it.
merge_group_lint_base_sha="${2:-}"

if [ "$phase" = "absolute-audit" ]; then
  exec bash scripts/absolute-audit-governance-validate.sh ci
fi

# ChatGPT continuity and browser suites are expensive (~70s). On PRs and
# merge groups, use the verified base..HEAD diff to select related suites.
# Missing/untrusted comparison data always runs the full suites (fail closed).
# Push to main retains the full post-merge validation.
continuity_suites_needed() {
  local base_ref changed
  case "${GITHUB_EVENT_NAME:-}" in
    pull_request|pull_request_review)
      [ -n "${GITHUB_BASE_REF:-}" ] || return 0
      base_ref="$(git merge-base HEAD "origin/$GITHUB_BASE_REF" 2>/dev/null)" || return 0 ;;
    merge_group)
      [ -n "${MERGE_GROUP_BASE_SHA:-}" ] || return 0
      base_ref="$MERGE_GROUP_BASE_SHA"
      git cat-file -e "${base_ref}^{commit}" 2>/dev/null || return 0 ;;
    *) return 0 ;;
  esac
  changed="$(git diff --name-only "$base_ref"..HEAD 2>/dev/null)" || return 0
  [ -n "$changed" ] || return 0
  printf '%s\n' "$changed" | grep -Eq '^(scripts/chatgpt-continuity/|scripts/install-chatgpt-continuity|scripts/validate-task-continuity|scripts/agent_task_state\.py|scripts/repository-governance-validate\.sh|tests/chatgpt-|tests/test_chatgpt_|tests/test_task_|tests/test_global_task_continuity|tests/test_checkpoint_first|tests/test_executor_fallback_order|remote-control-browser-mcp/|scripts/(chatgpt|agent-bridge|continuity)|\.github/workflows/mandatory-validation-gate\.yml)'
}


# Relevance: a suite runs only when the PR diff names it, imports it or changes what it tests.
# Gate-core changes, push and merge_group runs (no PR diff) run every suite.
CI_CHANGED_LIST="$(mktemp)"
trap 'rm -f "$CI_CHANGED_LIST"' EXIT
case "${GITHUB_EVENT_NAME:-}" in
  pull_request|pull_request_review)
    if [ -n "${GITHUB_BASE_REF:-}" ]; then
      git diff --name-only "$(git merge-base HEAD "origin/$GITHUB_BASE_REF")"..HEAD > "$CI_CHANGED_LIST" || : > "$CI_CHANGED_LIST"
    fi ;;
  merge_group)
    if [ -n "${MERGE_GROUP_BASE_SHA:-}" ]; then
      git diff --name-only "$MERGE_GROUP_BASE_SHA"..HEAD > "$CI_CHANGED_LIST" || : > "$CI_CHANGED_LIST"
    fi ;;
  push)
    if [ -n "${PUSH_BEFORE_SHA:-}" ] && ! printf '%s' "$PUSH_BEFORE_SHA" | grep -Eq '^0+$'; then
      git diff --name-only "$PUSH_BEFORE_SHA"..HEAD > "$CI_CHANGED_LIST" || : > "$CI_CHANGED_LIST"
    fi ;;
esac
run_suite() {
  local verdict
  verdict="$(python3 scripts/ci_suite_relevance.py --command "$*" --changed-list "$CI_CHANGED_LIST")" || verdict="RUN	relevance_helper_failed"
  case "$verdict" in
    RUN*) echo "RUN suite: ${verdict#RUN	} :: $*"; "$@" ;;
    *) echo "SKIP suite: ${verdict#SKIP	} :: $*" ;;
  esac
}

# Web Agent is an isolated Node service; generic Python/PHP governance cannot
# replace its own security, takeover and navigation regression tests.
# Run only when its path is affected, or when the comparison is unavailable.
if [ ! -s "$CI_CHANGED_LIST" ] || grep -Eq '^apps/shopvivaliz-web-agent/' "$CI_CHANGED_LIST"; then
  echo 'RUN_WEB_AGENT_NODE_TESTS=web_agent_path_or_unknown_diff'
  (cd apps/shopvivaliz-web-agent && npm test && npm run check)
else
  echo 'SKIP_WEB_AGENT_NODE_TESTS=no_web_agent_files_changed'
fi

# Prevent blanket GitHub human review requests before expensive CI.
python3 -m unittest tests.test_ci_suite_relevance -v
run_suite python3 scripts/check_pr_review_routing.py
run_suite python3 -m unittest tests/test_pr_review_routing.py -v
run_suite python3 -m unittest tests/test_owner_review_request_router.py -v
bash -n .githooks/pre-commit .githooks/pre-push scripts/repository-governance-validate.sh
run_suite python3 scripts/validate-recurring-ai-policy.py
run_suite python3 scripts/validate-retired-windows-tasks.py
run_suite python3 scripts/validate-final-response-deploy-gate.py
run_suite python3 scripts/validate-audit-governance.py
run_suite bash tests/disk-hygiene-policy-contract-test.sh
run_suite python3 tests/test_retired_source_workflow_guards.py
run_suite python3 tests/test_workspace_housekeeper_safety.py
run_suite python3 tests/test_production_functional_audit_security.py
run_suite python3 scripts/validate-task-continuity-enforcement.py
# Agent docs preflight is a merge gate, not an optional README instruction.
run_suite python3 scripts/check-agent-docs-enforcement.py
run_suite python3 -m unittest tests.test_agent_docs_gate tests.test_agent_operations_docs_scope -v
run_suite bash tests/chatgpt-account-diagnostic-contract-test.sh
if ! continuity_suites_needed; then
  echo 'SKIP CHATGPT_CONTINUITY_SUITES=no_related_paths_changed_on_pull_request'
elif python3 -c 'import fcntl' >/dev/null 2>&1; then
  # One interpreter start for the whole continuity suite; any failing module still fails the step.
  python3 -m unittest tests.test_task_continuity_enforcement tests.test_task_continuation_watchdog \
    tests.test_task_resume_dispatcher tests.test_task_resume_queue tests.test_global_task_continuity_v8 \
    tests.test_checkpoint_first_zero_window tests.test_chatgpt_continuity_nudge_dispatcher \
    tests.test_chatgpt_continuity_backend_runtime -v
else
  echo 'SKIP POSIX_CONTINUITY_UNIT_SUITE=fcntl_unavailable; required in Linux CI'
fi
if ! continuity_suites_needed; then
  echo 'SKIP POSIX_BRIDGE_WORKER_TEST=no_related_paths_changed_on_pull_request'
elif [ "$(uname -s)" = "Linux" ]; then
  node tests/chatgpt-continuity-bridge-worker-test.mjs
else
  echo "SKIP POSIX_BRIDGE_WORKER_TEST=$(uname -s); required in Linux CI"
fi
run_suite bash -n scripts/install-chatgpt-continuity-backend-bridge.sh
run_suite python3 -m unittest tests.test_executor_fallback_order -v
if [ "$(uname -s)" = "Linux" ]; then
  run_suite env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE -u GIT_PREFIX -u GIT_OBJECT_DIRECTORY -u GIT_ALTERNATE_OBJECT_DIRECTORIES python3 -m unittest tests.test_background_gemini_runner -v
else
  echo "SKIP POSIX_BACKGROUND_GEMINI_TEST=$(uname -s); required in Linux CI"
fi
run_suite python3 -m unittest tests.test_ci_feedback_optimization tests.test_pr_gate_scope tests.test_pr_gate_replay -v
if [ "$(uname -s)" = "Linux" ]; then
  run_suite python3 -m unittest tests.test_pr_owner_review_recovery tests.test_pr_owner_recovery_workflow tests.test_ci_php_lint_scope -v
else
  echo "SKIP POSIX_OWNER_RECOVERY_TEST=$(uname -s); required in Linux CI"
fi
run_suite python3 -m unittest tests.test_workflow_latency_budget tests.unit.test_runtime_deploy_reconciliation tests.test_ci_performance_monitor -v
run_suite python3 tests/test_ci_performance_monitor_workflow.py
run_suite python3 tests/shopvivaliz-mail-legacy-provider-guard-test.py
run_suite php tests/cloudflare-email-titan-finalize-test.php
run_suite php tests/shopvivaliz-brevo-mailer-contract-test.php
run_suite php -d zend.assertions=1 -d assert.exception=1 tests/shopvivaliz-brevo-mailer-runtime-test.php
if [ "$(uname -s)" = "Linux" ]; then
  run_suite python3 -m unittest tests.test_stock_alerts_brevo_mail -v
else
  echo "SKIP POSIX_STOCK_ALERTS_TEST=$(uname -s); required in Linux CI"
fi
run_suite python3 -m unittest tests.test_shopee_logistics_worker -v
if [ "$(uname -s)" = "Linux" ]; then
  run_suite python3 -m unittest tests.test_ci_performance_fetch -v
else
  echo "SKIP POSIX_CI_PERFORMANCE_FETCH_TEST=$(uname -s); required in Linux CI"
fi

if command -v composer >/dev/null 2>&1; then
  composer validate --no-check-publish --strict
fi

lint_php_list() {
  local list_file="$1"
  while IFS= read -r -d '' file; do
    if [ -f "$file" ]; then
      php -l "$file" >/dev/null
    fi
  done < "$list_file"
}

tmp_php_list="$(mktemp)"
trap 'rm -f "$tmp_php_list"' EXIT

case "$phase" in
  pre-commit)
    git diff --cached --name-only -z --diff-filter=ACMR -- '*.php' > "$tmp_php_list"
    lint_php_list "$tmp_php_list"
    ;;
  pre-push)
    upstream_ref=""
    if upstream_ref="$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null)"; then
      base_ref="$(git merge-base HEAD "$upstream_ref")"
    else
      base_ref="$(git merge-base HEAD origin/main)"
    fi
    git diff --name-only -z --diff-filter=ACMR "$base_ref"..HEAD -- '*.php' > "$tmp_php_list"
    lint_php_list "$tmp_php_list"
    ;;
  ci)
    if [[ "${GITHUB_EVENT_NAME:-}" == "pull_request" || "${GITHUB_EVENT_NAME:-}" == "pull_request_review" ]] && [ -n "${GITHUB_BASE_REF:-}" ]; then
      base_ref="$(git merge-base HEAD "origin/$GITHUB_BASE_REF")"
      git diff --name-only -z --diff-filter=ACMR "$base_ref"..HEAD -- '*.php' > "$tmp_php_list"
      lint_php_list "$tmp_php_list"
    elif [ "${GITHUB_EVENT_NAME:-}" = "merge_group" ] && [ -n "${merge_group_lint_base_sha:-}" ]; then
      # The queue candidate differs from main only by the PR change set; untouched
      # PHP was already linted on main. Without a base SHA this falls through to full.
      base_ref="$(git merge-base HEAD "$merge_group_lint_base_sha")"
      git diff --name-only -z --diff-filter=ACMR "$base_ref"..HEAD -- '*.php' > "$tmp_php_list"
      lint_php_list "$tmp_php_list"
    elif [ "${GITHUB_EVENT_NAME:-}" = "push" ] && [ -n "${PUSH_BEFORE_SHA:-}" ] && ! printf '%s' "$PUSH_BEFORE_SHA" | grep -Eq '^0+$'; then
      git diff --name-only -z --diff-filter=ACMR "$PUSH_BEFORE_SHA"..HEAD -- '*.php' > "$tmp_php_list"
      lint_php_list "$tmp_php_list"
    else
      # Full CI coverage means every tracked PHP file, not nested trusted
      # checkouts or untracked worktrees created by the validation workflow.
      git ls-files -z -- '*.php' ':!:vendor/**' ':!:node_modules/**' ':!:.worktrees/**' > "$tmp_php_list"
      lint_php_list "$tmp_php_list"
    fi
    ;;
  manual|full)
    find . -path './vendor' -prune -o -path './.git' -prune -o -path './.worktrees' -prune -o -path './node_modules' -prune -o -name '*.php' -type f -print0 | xargs -0 -r -n1 php -l >/dev/null
    ;;
  *)
    echo "Unknown validation phase: $phase" >&2
    exit 64
    ;;
esac

if [ "$phase" = "ci" ]; then
  if command -v composer >/dev/null 2>&1 && [ ! -d vendor ]; then
    composer install --no-interaction --prefer-dist
  fi
  php_relevant=true
  if [ -s "$CI_CHANGED_LIST" ] && ! grep -Eq '\.(php|phpt)$|^composer\.(json|lock)$|^phpunit\.xml' "$CI_CHANGED_LIST"; then
    php_relevant=false
    echo 'SKIP phpunit: no PHP or Composer files changed in this diff'
  fi
  if [ "$php_relevant" = true ] && [ -x vendor/bin/phpunit ]; then
    if [ -f phpunit.xml ] || [ -f phpunit.xml.dist ]; then
      vendor/bin/phpunit
    elif [ -d tests ] && find tests -type f \( -name '*Test.php' -o -name '*.phpt' \) -print -quit | grep -q .; then
      vendor/bin/phpunit tests
    fi
  fi
fi
