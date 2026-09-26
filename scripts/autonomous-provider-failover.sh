#!/usr/bin/env bash
set -Eeuo pipefail

PROMPT_FILE="${1:?prompt file required}"
SHOPVIVALIZ_TASK_ID="${SHOPVIVALIZ_TASK_ID:-}"
SHOPVIVALIZ_RESUME_STAGE="${SHOPVIVALIZ_RESUME_STAGE:-}"
LOG_DIR="logs"
ATTEMPTS="$LOG_DIR/autonomous-provider-attempts.jsonl"
OUTPUT="$LOG_DIR/autonomous-provider-output.txt"
CODEX_MODEL="${CODEX_MODEL:-${OPENAI_MODEL:-gpt-5.6}}"
GEMINI_MODEL="${GEMINI_MODEL:-gemini-2.5-flash}"
ANTHROPIC_MODEL="${ANTHROPIC_MODEL:-claude-haiku-4-5-20251001}"
CLAUDE_MAX_BUDGET_USD="${CLAUDE_MAX_BUDGET_USD:-0.05}"
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

persist_running_checkpoint() {
  [ -n "$SHOPVIVALIZ_TASK_ID" ] || return 0

  if ! python3 scripts/agent_task_state.py show --task "$SHOPVIVALIZ_TASK_ID" >/dev/null 2>&1; then
    python3 scripts/agent_task_state.py start       --task "$SHOPVIVALIZ_TASK_ID"       --goal "Continuar tarefa finita delegada ate estado terminal"       --agent executor-failover >/dev/null
  fi

  python3 scripts/agent_task_state.py progress     --task "$SHOPVIVALIZ_TASK_ID"     --next-action "retomar a tarefa com a proxima rota segura disponivel; Codex permanece ultima opcao"     --evidence "todos os executores finitos desta rodada ficaram indisponiveis ou nao produziram mudanca verificavel"     >/dev/null
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

# CHATGPT_RESUME_ORDER_V5: CLI is the final fallback only.
if [ "$SHOPVIVALIZ_RESUME_STAGE" != "cli_last" ]; then
  echo "CLI bloqueada: ordem obrigatoria = chatgpt_common -> chatgpt_work -> cli_last." | tee -a "$OUTPUT"
  persist_running_checkpoint
  exit 75
fi

# Esta e a terceira camada (CLI) da politica de retomada.
# Dentro da CLI, preservar cota: Gemini -> Claude -> Codex.
# Codex continua sendo a ultima opcao dentro da ultima camada.
ORDER=(gemini anthropic codex)

for provider in "${ORDER[@]}"; do
  case "$provider" in
    gemini)
      command -v gemini >/dev/null 2>&1 || { record gemini missing_cli 127; continue; }
      try_provider gemini env -u GEMINI_API_KEY -u GOOGLE_API_KEY gemini --model "$GEMINI_MODEL" --approval-mode auto_edit --prompt "$PROMPT" && exit 0
      ;;
    anthropic)
      command -v claude >/dev/null 2>&1 || { record anthropic missing_cli 127; continue; }
      try_provider anthropic env -u ANTHROPIC_API_KEY claude --print --model "$ANTHROPIC_MODEL" --effort low --max-budget-usd "$CLAUDE_MAX_BUDGET_USD" --permission-mode acceptEdits "$PROMPT" && exit 0
      ;;
    codex)
      # Never let a platform API key take precedence over the approved native
      # ChatGPT Business login. Capacity/auth failures here are non-terminal;
      # the caller keeps the durable task checkpoint RUNNING.
      try_provider codex env -u OPENAI_API_KEY -u CODEX_API_KEY codex exec --model "$CODEX_MODEL" -c 'model_reasoning_effort="low"' -c 'model_verbosity="low"' "$PROMPT" && exit 0
      ;;
  esac
done

echo "Nenhum executor produziu mudança real; preservar checkpoint RUNNING para retomada." | tee -a "$OUTPUT"
if [ -n "$SHOPVIVALIZ_TASK_ID" ]; then
  persist_running_checkpoint
  exit 75
fi
exit 0
