#!/usr/bin/env bash
set -euo pipefail
wf=.github/workflows/seo-integrity-audit.yml
grep -Fq 'workflow_run:' "$wf"
grep -Fq 'workflows: [Master Production Pipeline 24/7]' "$wf"
grep -Fq 'types: [completed]' "$wf"
! grep -Eq '^[[:space:]]+push:' "$wf"
grep -Fq "github.event.workflow_run.conclusion == 'success'" "$wf"
grep -Fq 'ref: ${{ github.event_name == '\''workflow_run'\'' && github.event.workflow_run.head_sha || github.sha }}' "$wf"
echo seo-integrity-postdeploy-trigger-test: ok
grep -Fq 'bash tests/seo-integrity-postdeploy-trigger-test.sh' .github/workflows/quality-gate.yml
