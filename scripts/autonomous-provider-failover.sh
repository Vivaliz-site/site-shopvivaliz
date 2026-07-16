#!/usr/bin/env bash
set -u

PROMPT_FILE="${1:?prompt file required}"
LOG_DIR="logs"
ATTEMPTS="$LOG_DIR/autonomous-provider-attempts.jsonl"
OUTPUT="$LOG_DIR/autonomous-provider-output.txt"
mkdir -p "$LOG_DIR"
: > "$ATTEMPTS"
: > "$OUTPUT"

has_change() {
  ! git diff --quiet || [ -n "$(git ls-files --others --exclude-standard)" ]
}

record() {
  local provider="$1" status="$2" code="$3"
  printf '{"provider":"%s","status":"%s","exit_code":%s,"timestamp":"%s"}\n' \
    "$provider" "$status" "$code" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$ATTEMPTS"
}

cleanup_attempt() {
  git restore --worktree --staged .
  git clean -fd --exclude="$ATTEMPTS" --exclude="$OUTPUT"
}

try_provider() {
  local provider="$1"
  shift
  if "$@" >> "$OUTPUT" 2>&1; then code=0; else code=$?; fi
  if has_change; then
    record "$provider" "change_produced" "$code"
    echo "Provider $provider produziu alteração auditável."
    return 0
  fi
  record "$provider" "no_change_or_failure" "$code"
  cleanup_attempt
  return 1
}

PROMPT="$(cat "$PROMPT_FILE")"

# A ordem gira por hora para distribuir quota e evitar dependência de um primário fixo.
case $((10#$(date -u +%H) % 3)) in
  0) ORDER=(openai gemini anthropic) ;;
  1) ORDER=(gemini anthropic openai) ;;
  2) ORDER=(anthropic openai gemini) ;;
esac

for provider in "${ORDER[@]}"; do
  case "$provider" in
    openai)
      [ -n "${OPENAI_API_KEY:-}" ] || { record openai missing_key 127; continue; }
      try_provider openai codex exec --full-auto "$PROMPT" && exit 0
      ;;
    gemini)
      [ -n "${GEMINI_API_KEY:-}" ] || { record gemini missing_key 127; continue; }
      try_provider gemini gemini --yolo --prompt "$PROMPT" && exit 0
      ;;
    anthropic)
      [ -n "${ANTHROPIC_API_KEY:-}" ] || { record anthropic missing_key 127; continue; }
      try_provider anthropic claude --print --dangerously-skip-permissions "$PROMPT" && exit 0
      ;;
  esac
done

echo "Nenhum dos três provedores produziu mudança real; ciclo idle." | tee -a "$OUTPUT"
exit 0
