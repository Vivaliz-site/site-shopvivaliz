#!/usr/bin/env bash
set -Eeuo pipefail

PROMPT_FILE="${1:?prompt file required}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
SHOPVIVALIZ_TASK_ID="${SHOPVIVALIZ_TASK_ID:-}"
SHOPVIVALIZ_RESUME_STAGE="${SHOPVIVALIZ_RESUME_STAGE:-}"
SHOPVIVALIZ_RESUME_RESULT_MODE="${SHOPVIVALIZ_RESUME_RESULT_MODE:-git_diff}"
SHOPVIVALIZ_RESUME_BACKGROUND="${SHOPVIVALIZ_RESUME_BACKGROUND:-0}"
LOG_DIR="logs"
ATTEMPTS="$LOG_DIR/autonomous-provider-attempts.jsonl"
OUTPUT="$LOG_DIR/autonomous-provider-output.txt"
CODEX_MODEL="${CODEX_MODEL:-${OPENAI_MODEL:-gpt-5.6-terra}}"
GEMINI_MODEL="${GEMINI_MODEL:-gemini-flash-latest}"
ANTHROPIC_MODEL="${ANTHROPIC_MODEL:-claude-haiku-4-5-20251001}"
CLAUDE_MAX_BUDGET_USD="${CLAUDE_MAX_BUDGET_USD:-0.05}"
CODEX_AUTO_BIN="${CODEX_AUTO_BIN:-/home/ubuntu/.local/bin/codex-auto}"
SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK="${SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK:-0}"
mkdir -p "$LOG_DIR"
: > "$ATTEMPTS"
: > "$OUTPUT"

has_change() {
  ! git diff --quiet || [ -n "$(git ls-files --others --exclude-standard)" ]
}

task_state_signature() {
  [ -n "$SHOPVIVALIZ_TASK_ID" ] || return 0
  python3 scripts/agent_task_state.py show --task "$SHOPVIVALIZ_TASK_ID" 2>/dev/null | python3 -c '
import hashlib, json, sys
p=json.load(sys.stdin)
e=p.get("evidence") if isinstance(p.get("evidence"), list) else []
basis={
  "status": str(p.get("status", "")).strip(),
  "next_action": str(p.get("next_action", "")).strip(),
  "verification": str(p.get("verification") or "").strip(),
  "evidence_count": len(e),
  "last_evidence": str(e[-1]) if e else "",
  "blocker": p.get("blocker"),
}
raw=json.dumps(basis, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
print(hashlib.sha256(raw.encode()).hexdigest())
'
}

record() {
  local provider="$1" status="$2" code="$3"
  printf '{"provider":"%s","status":"%s","exit_code":%s,"timestamp":"%s"}
' \
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
  local before_state="" after_state=""
  if [ "$SHOPVIVALIZ_RESUME_RESULT_MODE" = "task_state" ]; then
    before_state="$(task_state_signature || true)"
  fi
  if "$@" >> "$OUTPUT" 2>&1; then code=0; else code=$?; fi

  if [ "$SHOPVIVALIZ_RESUME_RESULT_MODE" = "task_state" ]; then
    after_state="$(task_state_signature || true)"
    if [ -n "$before_state" ] && [ -n "$after_state" ] && [ "$after_state" != "$before_state" ]; then
      record "$provider" "task_state_advanced" "$code"
      echo "Provider $provider avançou o checkpoint durável."
      return 0
    fi
    record "$provider" "no_task_state_progress" "$code"
    cleanup_attempt
    return 1
  fi

  if has_change; then
    record "$provider" "change_produced" "$code"
    echo "Provider $provider produziu alteração auditável."
    return 0
  fi
  record "$provider" "no_change_or_failure" "$code"
  cleanup_attempt
  return 1
}

run_codex_auto() (
  unset OPENAI_API_KEY CODEX_API_KEY
  local launcher="$CODEX_AUTO_BIN"
  if [ ! -x "$launcher" ]; then
    launcher="$(command -v codex-auto || true)"
  fi
  [ -n "$launcher" ] && [ -x "$launcher" ] || return 127
  "$launcher" \
    --model "$CODEX_MODEL" \
    --sandbox danger-full-access \
    --ask-for-approval never \
    -c 'model_reasoning_effort="low"' \
    -c 'model_verbosity="low"' \
    exec - < "$PROMPT_FILE"
)

PROMPT="$(cat "$PROMPT_FILE")"

# CHATGPT_RESUME_ORDER_V5: CLI is the final fallback only.
if [ "$SHOPVIVALIZ_RESUME_STAGE" != "cli_last" ]; then
  echo "CLI bloqueada: ordem obrigatoria = chatgpt_common -> chatgpt_work -> cli_last." | tee -a "$OUTPUT"
  if [ "$SHOPVIVALIZ_RESUME_RESULT_MODE" != "task_state" ]; then
    persist_running_checkpoint
  fi
  exit 75
fi

# Esta e a terceira camada (CLI) da politica de retomada.
# Em recovery de background, a politica recorrente permite apenas IA gratuita/local
# aprovada. Claude/Codex continuam exigindo gatilho humano explicito.
BACKGROUND_ORDER=(gemini)
if [ "$SHOPVIVALIZ_RESUME_BACKGROUND" = "1" ]; then
  if [ "$SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK" = "1" ]; then
    BACKGROUND_ORDER+=(codex_auto)
    echo "background_codex_fallback_authorized=true" | tee -a "$OUTPUT"
  else
    echo "background_paid_fallback_forbidden=true" | tee -a "$OUTPUT"
  fi
  ORDER=("${BACKGROUND_ORDER[@]}")
else
  # Execucao finita/interativa: preservar cota, Gemini -> Claude -> Codex.
  ORDER=(gemini anthropic codex)
fi

for provider in "${ORDER[@]}"; do
  case "$provider" in
    gemini)
      if [ "$SHOPVIVALIZ_RESUME_BACKGROUND" = "1" ]; then
        try_provider gemini \
          python3 "$SCRIPT_DIR/run_background_gemini.py" \
            --model "$GEMINI_MODEL" \
            --prompt-file "$PROMPT_FILE" && exit 0
        continue
      fi
      command -v gemini >/dev/null 2>&1 || { record gemini missing_cli 127; continue; }
      try_provider gemini env -u GEMINI_API_KEY -u GOOGLE_API_KEY gemini --model "$GEMINI_MODEL" --approval-mode auto_edit --prompt "$PROMPT" && exit 0
      ;;
    anthropic)
      command -v claude >/dev/null 2>&1 || { record anthropic missing_cli 127; continue; }
      try_provider anthropic env -u ANTHROPIC_API_KEY claude --print --model "$ANTHROPIC_MODEL" --effort low --max-budget-usd "$CLAUDE_MAX_BUDGET_USD" --permission-mode acceptEdits "$PROMPT" && exit 0
      ;;
    codex_auto)
      try_provider codex_auto run_codex_auto && exit 0
      ;;
    codex)
      # Never let a platform API key take precedence over the approved native
      # ChatGPT Business login. Capacity/auth failures here are non-terminal;
      # the caller keeps the durable task checkpoint RUNNING.
      try_provider codex env -u OPENAI_API_KEY -u CODEX_API_KEY codex exec --model "$CODEX_MODEL" -c 'model_reasoning_effort="low"' -c 'model_verbosity="low"' "$PROMPT" && exit 0
      ;;
  esac
done

echo "Nenhum executor produziu progresso durável; preservar checkpoint RUNNING para retomada." | tee -a "$OUTPUT"
if [ -n "$SHOPVIVALIZ_TASK_ID" ]; then
  if [ "$SHOPVIVALIZ_RESUME_RESULT_MODE" != "task_state" ]; then
    persist_running_checkpoint
  fi
  exit 75
fi
exit 0
