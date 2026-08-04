#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="${SHOPVIVALIZ_PROJECT_DIR:-/home/ubuntu/shopvivaliz-deploy/current}"
INTERVAL_SECONDS="${SHOPVIVALIZ_AGENT_INTERVAL_SECONDS:-900}"
LOG_FILE="${SHOPVIVALIZ_AGENT_LOG:-$PROJECT_DIR/logs/autonomous-agent.log}"
RESPONSE_FILE="${SHOPVIVALIZ_AGENT_RESPONSE:-$PROJECT_DIR/logs/execution/last-real-agent-response.json}"
VALIDATION_FILE="${SHOPVIVALIZ_AGENT_VALIDATION:-$PROJECT_DIR/logs/execution/last-real-agent-validation.json}"
STOP_FILE="${SHOPVIVALIZ_AGENT_STOP_FILE:-$PROJECT_DIR/.agent-stop}"
EXECUTION_LOCK="${SHOPVIVALIZ_AGENT_EXECUTION_LOCK:-/tmp/shopvivaliz-real-work.lock}"
PERSISTED_EVIDENCE="$PROJECT_DIR/storage/agent-evidence/latest-agent-cycle.json"

now() {
  date -u +"%Y-%m-%dT%H:%M:%SZ"
}

log() {
  printf '[%s] %s\n' "$(now)" "$*"
}

require_file() {
  local path="$1"
  if [[ ! -r "$path" ]]; then
    log "ERROR required file is missing or unreadable: $path"
    return 1
  fi
}

validate_cycle() {
  local response="$1"
  local persisted="$2"
  local release_sha="$3"

  python3 - "$response" "$persisted" "$release_sha" "$VALIDATION_FILE" <<'PY'
import datetime as dt
import hashlib
import json
import os
import re
import sys
from pathlib import Path

response_path, persisted_path, release_sha, validation_path = sys.argv[1:]
with open(response_path, encoding="utf-8") as handle:
    response = json.load(handle)
with open(persisted_path, encoding="utf-8") as handle:
    persisted = json.load(handle)

if re.fullmatch(r"[0-9a-f]{40}", release_sha) is None:
    raise SystemExit("release_sha_invalid")

required = {
    "ok": True,
    "execution_status": "completed",
    "execution_completed": True,
    "execution_accepted": True,
    "active_agent_count": 5,
    "schedule_minutes": 15,
}
for key, expected in required.items():
    if response.get(key) != expected:
        raise SystemExit(f"agent_response_invalid:{key}")

expected_agents = {
    "operational_work",
    "catalog_metadata",
    "conversion_funnel",
    "media_quality",
    "media_mismatch",
}
if set(response.get("active_agents", [])) != expected_agents:
    raise SystemExit("active_agent_registry_invalid")
if int(response.get("work_evidence_count", 0) or 0) < 12:
    raise SystemExit("agent_evidence_count_below_minimum")
if re.fullmatch(r"[0-9a-f]{64}", str(response.get("registry_sha256", ""))) is None:
    raise SystemExit("registry_hash_invalid")

components = response.get("components")
if not isinstance(components, dict) or set(components) != expected_agents:
    raise SystemExit("agent_components_invalid")
for name, component in components.items():
    if component.get("registry_id") != name:
        raise SystemExit(f"component_registry_id_invalid:{name}")
    if component.get("ok") is not True:
        raise SystemExit(f"component_failed:{name}")
    if component.get("execution_completed") is not True:
        raise SystemExit(f"component_incomplete:{name}")
    if component.get("execution_status") != "completed":
        raise SystemExit(f"component_status_invalid:{name}")
    actions = component.get("actions")
    if not isinstance(actions, list) or not actions:
        raise SystemExit(f"component_evidence_missing:{name}")

for key in ("cycle_id", "registry_sha256", "active_agents", "work_evidence_count", "execution_accepted"):
    if persisted.get(key) != response.get(key):
        raise SystemExit(f"persisted_cycle_mismatch:{key}")

reported_release = str(response.get("release_sha") or persisted.get("release_sha") or "")
if reported_release and reported_release != release_sha:
    raise SystemExit("cycle_release_sha_mismatch")

forbidden = {"queued", "degraded", "simulated", "mock", "dry_run", "dry-run"}
def walk(value):
    if isinstance(value, dict):
        for child in value.values():
            walk(child)
    elif isinstance(value, list):
        for child in value:
            walk(child)
    elif isinstance(value, str) and value.strip().lower() in forbidden:
        raise SystemExit(f"forbidden_execution_status:{value}")
walk(response)

finished_raw = str(response.get("finished_at", "")).replace("Z", "+00:00")
try:
    finished = dt.datetime.fromisoformat(finished_raw)
except ValueError as exc:
    raise SystemExit("finished_at_invalid") from exc
if finished.tzinfo is None:
    finished = finished.replace(tzinfo=dt.timezone.utc)
age = (dt.datetime.now(dt.timezone.utc) - finished.astimezone(dt.timezone.utc)).total_seconds()
if age < -120 or age > 1800:
    raise SystemExit(f"agent_evidence_stale:{int(age)}")

validation = {
    "ok": True,
    "validated_at": dt.datetime.now(dt.timezone.utc).isoformat().replace("+00:00", "Z"),
    "release_sha": release_sha,
    "cycle_id": response["cycle_id"],
    "active_agent_count": response["active_agent_count"],
    "work_evidence_count": response["work_evidence_count"],
    "changed_items": response.get("changed_items", 0),
    "response_sha256": hashlib.sha256(Path(response_path).read_bytes()).hexdigest(),
    "persisted_sha256": hashlib.sha256(Path(persisted_path).read_bytes()).hexdigest(),
}
path = Path(validation_path)
path.parent.mkdir(parents=True, exist_ok=True)
tmp = path.with_suffix(path.suffix + ".tmp")
tmp.write_text(json.dumps(validation, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
os.replace(tmp, path)
print(json.dumps(validation, separators=(",", ":")))
PY
}

run_cycle() {
  if [[ -f "$STOP_FILE" ]]; then
    log "Cycle intentionally skipped because stop file exists: $STOP_FILE"
    return 0
  fi

  require_file "$PROJECT_DIR/api/agent/real-work-orchestrator.php"
  require_file "$PROJECT_DIR/includes/production-agent-registry.php"
  require_file "$PROJECT_DIR/.release-sha"

  local release_sha
  release_sha="$(tr -d '\r\n' < "$PROJECT_DIR/.release-sha")"
  if [[ ! "$release_sha" =~ ^[0-9a-f]{40}$ ]]; then
    log "ERROR invalid release SHA: $release_sha"
    return 1
  fi

  local tmp_response
  tmp_response="$(mktemp "$PROJECT_DIR/logs/execution/agent-response.XXXXXX.json")"
  trap 'rm -f "${tmp_response:-}"' RETURN

  log "Starting canonical production-agent cycle for release $release_sha"
  if ! timeout 1500s flock -w 120 "$EXECUTION_LOCK" \
      /usr/bin/php "$PROJECT_DIR/api/agent/real-work-orchestrator.php" > "$tmp_response"; then
    log "ERROR canonical production-agent orchestrator failed"
    return 1
  fi

  # The persisted evidence is generated by the cycle itself, so it is required
  # only after the orchestrator returns. This permits a clean first deployment.
  require_file "$PERSISTED_EVIDENCE"
  if ! validate_cycle "$tmp_response" "$PERSISTED_EVIDENCE" "$release_sha"; then
    log "ERROR production-agent evidence validation failed"
    return 1
  fi

  install -m 0640 "$tmp_response" "${RESPONSE_FILE}.tmp"
  mv -f "${RESPONSE_FILE}.tmp" "$RESPONSE_FILE"
  rm -f "$tmp_response"
  trap - RETURN
  log "Cycle completed with verified immutable evidence for release $release_sha"
}

if [[ ! -d "$PROJECT_DIR" ]]; then
  printf '[%s] ERROR project directory not found: %s\n' "$(now)" "$PROJECT_DIR" >&2
  exit 1
fi

mkdir -p "$PROJECT_DIR/logs/execution"
touch "$LOG_FILE"
cd "$PROJECT_DIR"
exec >> "$LOG_FILE" 2>&1

shutdown_requested=0
trap 'shutdown_requested=1; log "Shutdown requested; current cycle will finish before exit."' INT TERM

log "Canonical ShopVivaliz production-agent loop started; interval=${INTERVAL_SECONDS}s"
while [[ "$shutdown_requested" -eq 0 ]]; do
  if ! run_cycle; then
    log "ERROR cycle failed; exiting non-zero so systemd records the failure"
    exit 1
  fi

  slept=0
  while [[ "$slept" -lt "$INTERVAL_SECONDS" && "$shutdown_requested" -eq 0 ]]; do
    sleep 5
    slept=$((slept + 5))
  done
done
log "Canonical ShopVivaliz production-agent loop stopped cleanly"
