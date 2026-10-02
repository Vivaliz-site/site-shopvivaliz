#!/usr/bin/env bash
set -Eeuo pipefail

# Install from an immutable release path; callers must never edit current or a
# release in place. The service environment points directly at the copied,
# immutable release selected by this installer.
release_dir="${1:?immutable release directory required}"
release_id="${2:-}"
unit_name="shopvivaliz-gemini-24x7-controller.service"
unit_source="$release_dir/deploy/systemd/$unit_name"
unit_target="/etc/systemd/system/$unit_name"
base_dir="/opt/shopvivaliz-gemini-24x7-controller"
releases_dir="$base_dir/releases"
environment_target="/etc/shopvivaliz-gemini-24x7-controller.env"
runtime_dir="/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state"
e2e_failures_dir="/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state-e2e-failures"
gemini_cli_version="${SHOPVIVALIZ_GEMINI_CLI_VERSION:-0.62.0}"
gemini_cli_bin="/home/ubuntu/.local/bin/gemini"

test -d "$release_dir"
test -f "$release_dir/scripts/gemini_24x7_controller.py"
test -f "$unit_source"
compile_cache="$(mktemp -d)"
trap 'rm -rf "$compile_cache"' EXIT
PYTHONPYCACHEPREFIX="$compile_cache" python3 -m py_compile "$release_dir/scripts/gemini_24x7_controller.py"
rm -rf "$compile_cache"
trap - EXIT
if [ -z "$release_id" ] && [ -f "$release_dir/.release-sha" ]; then
  release_id="$(tr -d '[:space:]' < "$release_dir/.release-sha")"
fi
if ! [[ "$release_id" =~ ^[A-Za-z0-9._-]{7,160}$ ]]; then
  echo "ERROR: immutable release id is required" >&2
  exit 64
fi

# The daemon and E2E probe both run as ubuntu. Provision the active state and
# preferred failed-probe archive explicitly so quarantine never falls back to
# a RUNNING JSON in the active runtime root because of historical root ownership.
sudo install -d -o ubuntu -g ubuntu -m 0700 "$runtime_dir" "$e2e_failures_dir"
sudo install -d -o root -g root -m 0755 "$releases_dir"
target_dir="$releases_dir/$release_id"
if [ ! -d "$target_dir" ]; then
  stage_dir="$(sudo mktemp -d "$releases_dir/.${release_id}.XXXXXX")"
  cleanup() { sudo rm -rf "$stage_dir"; }
  trap cleanup EXIT
  sudo install -d -o root -g root -m 0755 "$stage_dir/scripts"
  for source in agent_task_state.py task_continuation_watchdog.py task_resume_queue.py task_resume_dispatcher.py chatgpt_continuity_nudge_dispatcher.py run_background_gemini.py autonomous-provider-failover.sh safe_git_push.py gemini_24x7_controller.py; do
    sudo install -o root -g root -m 0755 "$release_dir/scripts/$source" "$stage_dir/scripts/$source"
  done
  sudo install -o root -g root -m 0644 "$release_dir/AGENTS.md" "$stage_dir/AGENTS.md"
  sudo install -o root -g root -m 0644 "$release_dir/docs/knowledge/task-continuity.md" "$stage_dir/README"
  # The systemd process runs as ubuntu and must traverse this immutable code
  # directory; runtime state remains under the separately protected shared
  # directory and is not copied into the release.
  sudo chmod 0755 "$stage_dir" "$stage_dir/scripts"
  sudo mv "$stage_dir" "$target_dir"
  trap - EXIT
fi

if ! command -v npm >/dev/null 2>&1; then
  echo "ERROR: npm is required to install Gemini CLI" >&2
  exit 69
fi
installed_gemini_version=""
if [ -x "$gemini_cli_bin" ]; then
  installed_gemini_version="$("$gemini_cli_bin" --version 2>/dev/null | head -n1 | tr -d '[:space:]' || true)"
fi
if [ "$installed_gemini_version" != "$gemini_cli_version" ]; then
  npm install -g "@google/gemini-cli@$gemini_cli_version" --prefix /home/ubuntu/.local --no-audit --no-fund
fi
test -x "$gemini_cli_bin"
installed_gemini_version="$("$gemini_cli_bin" --version | head -n1 | tr -d '[:space:]')"
if [ "$installed_gemini_version" != "$gemini_cli_version" ]; then
  echo "ERROR: Gemini CLI version mismatch after install" >&2
  exit 70
fi

environment_temp="$(mktemp)"
trap 'rm -f "$environment_temp"' EXIT
printf 'SHOPVIVALIZ_GEMINI_CONTROLLER_ENTRY=%s\nSHOPVIVALIZ_AGENT_TASK_STATE_DIR=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state\nCHATGPT_CONTINUITY_BRIDGE_URL=http://127.0.0.1:18081/api/chatgpt-continuity/bridge.php\nCHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE=/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token\nCHATGPT_CONTINUITY_BRIDGE_HOST_HEADER=shopvivaliz.com.br\nGEMINI_ENV_FILE=/home/ubuntu/.config/shopvivaliz-gemini-24x7/gemini.env\nSHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK=1\nCODEX_AUTO_BIN=/home/ubuntu/.local/bin/codex-auto\n' "$target_dir/scripts/gemini_24x7_controller.py" > "$environment_temp"
sudo install -o root -g root -m 0640 "$environment_temp" "$environment_target"
rm -f "$environment_temp"
trap - EXIT
sudo install -o root -g root -m 0644 "$unit_source" "$unit_target"
sudo systemd-analyze verify "$unit_target"
sudo systemctl daemon-reload
sudo systemctl enable "$unit_name"
sudo systemctl restart "$unit_name"
sudo systemctl is-enabled --quiet "$unit_name"
sudo systemctl is-active --quiet "$unit_name"
