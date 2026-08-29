#!/usr/bin/env bash
set -euo pipefail

workflow='.github/workflows/vm-dc-direct-device-flow-once.yml'
[[ -f "$workflow" ]] || { echo 'FAIL workflow safety tombstone missing' >&2; exit 1; }

for forbidden in 'crypto.randomUUID()' 'DEVICE_CODE=' 'systemctl stop shopvivaliz-desktop-commander.service' 'device/start' 'device/poll'; do
  if grep -Fq "$forbidden" "$workflow"; then
    echo "FAIL unsafe Desktop Commander device-flow behavior remains: $forbidden" >&2
    exit 1
  fi
done

grep -Fq 'workflow_dispatch:' "$workflow"
grep -Fq 'This workflow is intentionally decommissioned' "$workflow"
grep -Fq 'exit 1' "$workflow"

echo 'PASS unsafe direct device-flow path is fail-closed'
