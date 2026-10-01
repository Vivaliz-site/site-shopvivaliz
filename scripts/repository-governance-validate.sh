#!/usr/bin/env bash
set -Eeuo pipefail
root="$(git rev-parse --show-toplevel)"; cd "$root"
phase="${1:-manual}"

if [ "$phase" = "absolute-audit" ]; then
  exec bash scripts/absolute-audit-governance-validate.sh ci
fi


bash -n .githooks/pre-commit .githooks/pre-push scripts/repository-governance-validate.sh
python3 scripts/validate-recurring-ai-policy.py
python3 scripts/validate-retired-windows-tasks.py
python3 scripts/validate-final-response-deploy-gate.py
python3 scripts/validate-audit-governance.py
python3 scripts/validate-task-continuity-enforcement.py
bash tests/chatgpt-account-diagnostic-contract-test.sh
python3 -m unittest tests.test_task_continuity_enforcement -v
python3 -m unittest tests.test_task_continuation_watchdog -v
python3 -m unittest tests.test_task_resume_dispatcher -v
python3 -m unittest tests.test_task_resume_queue -v
python3 -m unittest tests.test_global_task_continuity_v8 -v
python3 -m unittest tests.test_checkpoint_first_zero_window -v
python3 -m unittest tests.test_chatgpt_continuity_nudge_dispatcher -v
python3 -m unittest tests.test_chatgpt_continuity_backend_runtime -v
node tests/chatgpt-continuity-bridge-worker-test.mjs
bash -n scripts/install-chatgpt-continuity-backend-bridge.sh
python3 -m unittest tests.test_executor_fallback_order -v
env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE -u GIT_PREFIX -u GIT_OBJECT_DIRECTORY -u GIT_ALTERNATE_OBJECT_DIRECTORIES python3 -m unittest tests.test_background_gemini_runner -v
python3 -m unittest tests.test_ci_feedback_optimization -v
python3 -m unittest tests.test_pr_gate_scope -v
python3 -m unittest tests.test_pr_gate_replay -v
python3 -m unittest tests.test_workflow_latency_budget -v
python3 -m unittest tests.unit.test_runtime_deploy_reconciliation -v
python3 -m unittest tests.test_ci_performance_monitor -v
python3 tests/test_ci_performance_monitor_workflow.py
python3 -m unittest tests.test_ci_performance_fetch -v

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
    if [ "${GITHUB_EVENT_NAME:-}" = "pull_request" ] && [ -n "${GITHUB_BASE_REF:-}" ]; then
      base_ref="$(git merge-base HEAD "origin/$GITHUB_BASE_REF")"
      git diff --name-only -z --diff-filter=ACMR "$base_ref"..HEAD -- '*.php' > "$tmp_php_list"
      lint_php_list "$tmp_php_list"
    elif [ "${GITHUB_EVENT_NAME:-}" = "push" ] && [ -n "${PUSH_BEFORE_SHA:-}" ] && ! printf '%s' "$PUSH_BEFORE_SHA" | grep -Eq '^0+$'; then
      git diff --name-only -z --diff-filter=ACMR "$PUSH_BEFORE_SHA"..HEAD -- '*.php' > "$tmp_php_list"
      lint_php_list "$tmp_php_list"
    else
      find . -path './vendor' -prune -o -path './.git' -prune -o -path './.worktrees' -prune -o -path './node_modules' -prune -o -name '*.php' -type f -print0 | xargs -0 -r -n1 php -l >/dev/null
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
