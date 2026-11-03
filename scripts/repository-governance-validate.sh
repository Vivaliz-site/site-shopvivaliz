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

# ChatGPT continuity and browser suites are expensive (~70s of Node and
# unittest runtime). On pull_request they run only when the diff touches their
# paths. Push to main and merge_group always run them, so main and the merge
# queue keep full coverage. Any doubt, including an uncomputable diff, runs them.
continuity_suites_needed() {
  case "${GITHUB_EVENT_NAME:-}" in
    pull_request|pull_request_review) ;;
    *) return 0 ;;
  esac
  [ -n "${GITHUB_BASE_REF:-}" ] || return 0
  local base_ref changed
  base_ref="$(git merge-base HEAD "origin/$GITHUB_BASE_REF" 2>/dev/null)" || return 0
  changed="$(git diff --name-only "$base_ref"..HEAD 2>/dev/null)" || return 0
  printf '%s\n' "$changed" | grep -Eq '^(scripts/chatgpt-continuity/|scripts/install-chatgpt-continuity|scripts/validate-task-continuity|scripts/agent_task_state\.py|scripts/repository-governance-validate\.sh|tests/chatgpt-|tests/test_chatgpt_|tests/test_task_|tests/test_global_task_continuity|tests/test_checkpoint_first|tests/test_executor_fallback_order|remote-control-browser-mcp/|scripts/(chatgpt|agent-bridge|continuity))'
}


# Prevent blanket GitHub human review requests before expensive CI.
python3 scripts/check_pr_review_routing.py
python3 -m unittest tests/test_pr_review_routing.py -v
bash -n .githooks/pre-commit .githooks/pre-push scripts/repository-governance-validate.sh
python3 scripts/validate-recurring-ai-policy.py
python3 scripts/validate-retired-windows-tasks.py
python3 scripts/validate-final-response-deploy-gate.py
python3 scripts/validate-audit-governance.py
bash tests/disk-hygiene-policy-contract-test.sh
bash tests/backend-disk-hygiene-reconcile-contract-test.sh
python3 tests/test_retired_source_workflow_guards.py
python3 tests/test_workspace_housekeeper_safety.py
python3 tests/test_production_functional_audit_security.py
python3 scripts/validate-task-continuity-enforcement.py
# Agent docs preflight is a merge gate, not an optional README instruction.
python3 scripts/check-agent-docs-enforcement.py
python3 -m unittest tests.test_agent_docs_gate tests.test_agent_operations_docs_scope -v
bash tests/chatgpt-account-diagnostic-contract-test.sh
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
bash -n scripts/install-chatgpt-continuity-backend-bridge.sh
python3 -m unittest tests.test_executor_fallback_order -v
if [ "$(uname -s)" = "Linux" ]; then
  env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE -u GIT_PREFIX -u GIT_OBJECT_DIRECTORY -u GIT_ALTERNATE_OBJECT_DIRECTORIES python3 -m unittest tests.test_background_gemini_runner -v
else
  echo "SKIP POSIX_BACKGROUND_GEMINI_TEST=$(uname -s); required in Linux CI"
fi
python3 -m unittest tests.test_ci_feedback_optimization tests.test_pr_gate_scope tests.test_pr_gate_replay -v
if [ "$(uname -s)" = "Linux" ]; then
  python3 -m unittest tests.test_pr_owner_review_recovery tests.test_pr_owner_recovery_workflow tests.test_ci_php_lint_scope -v
else
  echo "SKIP POSIX_OWNER_RECOVERY_TEST=$(uname -s); required in Linux CI"
fi
python3 -m unittest tests.test_workflow_latency_budget tests.unit.test_runtime_deploy_reconciliation tests.test_ci_performance_monitor -v
python3 tests/test_ci_performance_monitor_workflow.py
python3 tests/shopvivaliz-mail-legacy-provider-guard-test.py
php tests/cloudflare-email-titan-finalize-test.php
php tests/shopvivaliz-brevo-mailer-contract-test.php
php -d zend.assertions=1 -d assert.exception=1 tests/shopvivaliz-brevo-mailer-runtime-test.php
if [ "$(uname -s)" = "Linux" ]; then
  python3 -m unittest tests.test_stock_alerts_brevo_mail -v
else
  echo "SKIP POSIX_STOCK_ALERTS_TEST=$(uname -s); required in Linux CI"
fi
python3 -m unittest tests.test_shopee_logistics_worker -v
if [ "$(uname -s)" = "Linux" ]; then
  python3 -m unittest tests.test_ci_performance_fetch -v
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
  if [ -x vendor/bin/phpunit ]; then
    if [ -f phpunit.xml ] || [ -f phpunit.xml.dist ]; then
      vendor/bin/phpunit
    elif [ -d tests ] && find tests -type f \( -name '*Test.php' -o -name '*.phpt' \) -print -quit | grep -q .; then
      vendor/bin/phpunit tests
    fi
  fi
fi
