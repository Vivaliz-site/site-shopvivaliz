#!/usr/bin/env bash
# Verify that an already-reviewed commit reached the deployment VM.
# This script never creates commits and never pushes branches.

set -Eeuo pipefail

EXPECTED_SHA="${1:-${EXPECTED_SHA:-}}"
SSH_HOST="${SSH_HOST:-}"
SSH_USER="${SSH_USER:-ubuntu}"
SSH_KEY="${SSH_KEY:-}"
REMOTE_REPO_PATH="${REMOTE_REPO_PATH:-/home/ubuntu/shopvivaliz-deploy/repo}"
WAIT_SECONDS="${WAIT_SECONDS:-300}"
POLL_SECONDS="${POLL_SECONDS:-15}"
LOG_FILE="${LOG_FILE:-/tmp/sync-daemon-verify-$(date +%s).log}"

fail() {
    printf 'ERROR: %s\n' "$1" | tee -a "$LOG_FILE" >&2
    exit 1
}

[[ "$EXPECTED_SHA" =~ ^[0-9a-fA-F]{40}$ ]] || fail "Provide a full 40-character EXPECTED_SHA as argument or environment variable."
[[ -n "$SSH_HOST" ]] || fail "SSH_HOST is required."
[[ -n "$SSH_KEY" ]] || fail "SSH_KEY is required."
[[ -f "$SSH_KEY" ]] || fail "SSH key not found: $SSH_KEY"
[[ "$REMOTE_REPO_PATH" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail "REMOTE_REPO_PATH contains unsupported characters."
[[ "$WAIT_SECONDS" =~ ^[0-9]+$ ]] || fail "WAIT_SECONDS must be an integer."
[[ "$POLL_SECONDS" =~ ^[0-9]+$ ]] || fail "POLL_SECONDS must be an integer."
(( POLL_SECONDS > 0 )) || fail "POLL_SECONDS must be greater than zero."

chmod 600 "$SSH_KEY"
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
DEADLINE=$(( $(date +%s) + WAIT_SECONDS ))
SSH_TARGET="${SSH_USER}@${SSH_HOST}"

{
    echo "verification=sync-daemon"
    echo "expected_sha=$EXPECTED_SHA"
    echo "ssh_target=$SSH_TARGET"
    echo "remote_repo_path=$REMOTE_REPO_PATH"
    echo "started_at=$STARTED_AT"
} | tee "$LOG_FILE"

while (( $(date +%s) <= DEADLINE )); do
    ACTUAL_SHA="$({
        ssh \
            -o BatchMode=yes \
            -o ConnectTimeout=10 \
            -o StrictHostKeyChecking=yes \
            -i "$SSH_KEY" \
            "$SSH_TARGET" \
            "git -C '$REMOTE_REPO_PATH' rev-parse HEAD"
    } 2>>"$LOG_FILE" || true)"

    ACTUAL_SHA="$(printf '%s' "$ACTUAL_SHA" | tr -d '\r\n')"
    printf 'observed_at=%s actual_sha=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${ACTUAL_SHA:-unavailable}" | tee -a "$LOG_FILE"

    if [[ "$ACTUAL_SHA" == "$EXPECTED_SHA" ]]; then
        printf 'verified=true verified_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" | tee -a "$LOG_FILE"
        exit 0
    fi

    sleep "$POLL_SECONDS"
done

fail "Remote repository did not reach the expected reviewed SHA within ${WAIT_SECONDS}s."
