#!/usr/bin/env bash
set -euo pipefail
W=.github/workflows/agents-audit-schedule-watchdog.yml
grep -Fq "const maximumAgeMinutes = 390;" "$W" || { echo "watchdog stale budget must tolerate observed GitHub schedule delivery lag" >&2; exit 1; }
grep -Fq "event: 'schedule'" "$W" || { echo "watchdog must continue checking scheduled runs" >&2; exit 1; }
grep -Fq "latest.status === 'completed' && latest.conclusion !== 'success'" "$W" || { echo "watchdog must still fail unsuccessful scheduled audits" >&2; exit 1; }
echo AGENTS_AUDIT_SCHEDULE_WATCHDOG_REGRESSION_OK
