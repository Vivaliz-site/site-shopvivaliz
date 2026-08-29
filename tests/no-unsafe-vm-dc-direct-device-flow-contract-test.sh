#!/usr/bin/env bash
set -euo pipefail

unsafe='.github/workflows/vm-dc-direct-device-flow-once.yml'

if [[ -e "$unsafe" ]]; then
  echo "FAIL unsafe VM Desktop Commander direct device-flow workflow still exists: $unsafe" >&2
  exit 1
fi

echo 'PASS unsafe direct device-flow workflow is absent'
