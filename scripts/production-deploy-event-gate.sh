#!/usr/bin/env bash
set -Eeuo pipefail

: "${EVENT_NAME:=}"
: "${SOURCE_RUN_ID:=}"
: "${EXPECTED_SHA:=}"
: "${SOURCE_CONCLUSION:=}"
: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
: "${GITHUB_OUTPUT:?GITHUB_OUTPUT is required}"

emit_skip() {
  printf 'should_run=false\nproduction_sha=\n' >> "$GITHUB_OUTPUT"
}

main() {
if [[ "$EVENT_NAME" == 'workflow_run' ]]; then
  if [[ "$SOURCE_CONCLUSION" != 'success' ]]; then
    echo "source_pipeline_not_success=$SOURCE_CONCLUSION"
    emit_skip
    return 0
  fi
  [[ "$SOURCE_RUN_ID" =~ ^[0-9]+$ ]] || { echo '::error::Invalid source run id'; exit 2; }
  [[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo '::error::Invalid expected deploy SHA'; exit 2; }

  jobs_json="$(gh api "repos/${GITHUB_REPOSITORY}/actions/runs/${SOURCE_RUN_ID}/jobs?per_page=100")"
  deploy_conclusion="$(jq -r '[.jobs[] | select(.name == "deploy")] | last | .conclusion // "missing"' <<<"$jobs_json")"
  case "$deploy_conclusion" in
    skipped)
      echo 'source_deploy_skipped=true'
      emit_skip
      return 0
      ;;
    success)
      ;;
    *)
      echo "::error::Source deploy job is not a successful terminal deploy: $deploy_conclusion"
      exit 2
      ;;
  esac
elif [[ "$EVENT_NAME" == 'schedule' || "$EVENT_NAME" == 'workflow_dispatch' ]]; then
  EXPECTED_SHA=''
else
  echo "unsupported_audit_event=$EVENT_NAME"
  emit_skip
  exit 0
fi

evidence_file="$(mktemp)"
trap 'rm -f "$evidence_file"' EXIT
gh api -H 'Accept: application/vnd.github.raw+json' \
  "repos/${GITHUB_REPOSITORY}/contents/deployment/latest.json?ref=deployment-evidence" \
  > "$evidence_file"

read -r deployed_sha status validate deploy smoke < <(python3 - "$evidence_file" <<'PY'
import json, re, sys
data = json.load(open(sys.argv[1], encoding='utf-8'))
jobs = data.get('jobs') or {}
sha = str(data.get('sha') or '').strip().lower()
print(
    sha if re.fullmatch(r'[0-9a-f]{40}', sha) else '',
    str(data.get('status') or ''),
    str(jobs.get('validate') or ''),
    str(jobs.get('deploy') or ''),
    str(jobs.get('smoke_test') or ''),
)
PY
)

[[ "$deployed_sha" =~ ^[0-9a-f]{40}$ ]] || { echo '::error::Deployment evidence has invalid SHA'; exit 3; }
[[ "$status" == 'PRODUCTION_UPDATED' ]] || { echo "::error::Deployment evidence status is $status"; exit 3; }
[[ "$validate" == 'success' && "$deploy" == 'success' && "$smoke" == 'success' ]] || {
  echo "::error::Deployment evidence jobs are not fully successful: validate=$validate deploy=$deploy smoke=$smoke"
  exit 3
}
if [[ -n "$EXPECTED_SHA" && "$deployed_sha" != "$EXPECTED_SHA" ]]; then
  echo "::error::Deployment evidence SHA mismatch expected=$EXPECTED_SHA observed=$deployed_sha"
  exit 4
fi

printf 'should_run=true\nproduction_sha=%s\n' "$deployed_sha" >> "$GITHUB_OUTPUT"
echo "production_audit_ready=true production_sha=$deployed_sha"
}

main "$@"
