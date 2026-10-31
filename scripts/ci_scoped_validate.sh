#!/usr/bin/env bash
set -Eeuo pipefail
# Mandatory gate is always present. Only the known isolated test suites may
# be scoped; shared code and security-policy paths must run the original full
# scripts/repository-governance-validate.sh ci entrypoint.
cd "$(git rev-parse --show-toplevel)"
groups_csv="${1:-}"
[[ -n "$groups_csv" ]] || { echo "scoped_validation_groups_missing" >&2; exit 64; }
IFS=',' read -r -a groups <<< "$groups_csv"
for group in "${groups[@]}"; do
  case "$group" in
    shopee|mail|continuity|docs) ;;
    *) echo "unsupported_scoped_validation_group=$group" >&2; exit 64 ;;
  esac
done

# Cross-cutting governance, security, release and agent guardrails are NEVER
# omitted, including for a documentation-only PR.
bash -n .githooks/pre-commit .githooks/pre-push scripts/repository-governance-validate.sh scripts/ci_scoped_validate.sh
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
python3 scripts/check-agent-docs-enforcement.py
python3 -m unittest tests.test_agent_docs_gate tests.test_agent_operations_docs_scope -v
bash tests/chatgpt-account-diagnostic-contract-test.sh

for group in "${groups[@]}"; do
  case "$group" in
    docs)
      echo "scoped_docs_guardrails_validated=true"
      ;;
    shopee)
      python3 -m unittest tests.test_shopee_logistics_worker tests.test_shopee_logistics_watchdog tests.test_shopee_logistics_systemd -v
      ;;
    continuity)
      python3 -m unittest tests.test_task_continuation_watchdog tests.test_task_resume_dispatcher tests.test_task_resume_queue tests.test_global_task_continuity_v8 tests.test_checkpoint_first_zero_window tests.test_chatgpt_continuity_nudge_dispatcher -v
      node tests/chatgpt-continuity-bridge-worker-test.mjs
      ;;
    mail)
      python3 tests/shopvivaliz-mail-legacy-provider-guard-test.py
      php tests/cloudflare-email-titan-finalize-test.php
      php tests/shopvivaliz-brevo-mailer-contract-test.php
      php -d zend.assertions=1 -d assert.exception=1 tests/shopvivaliz-brevo-mailer-runtime-test.php
      python3 -m unittest tests.test_stock_alerts_brevo_mail -v
      ;;
  esac
done
echo "scoped_validation_completed=$groups_csv"
