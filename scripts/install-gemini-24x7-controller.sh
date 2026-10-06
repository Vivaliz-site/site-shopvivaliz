#!/usr/bin/env bash
set -Eeuo pipefail

# Install from an immutable release path; callers must never edit current or a
# release in place. The service environment points directly at the copied,
# immutable release selected by this installer.
release_dir="${1:?immutable release directory required}"
release_id="${2:-}"
unit_name="shopvivaliz-gemini-24x7-controller.service"
resume_worker_service_name="shopvivaliz-task-resume-worker.service"
unit_source="$release_dir/deploy/systemd/$unit_name"
unit_target="/etc/systemd/system/$unit_name"
resume_worker_service_source="$release_dir/deploy/systemd/$resume_worker_service_name"
resume_worker_service_target="/etc/systemd/system/$resume_worker_service_name"
watchdog_service_name="shopvivaliz-continuity-watchdog.service"
watchdog_timer_name="shopvivaliz-continuity-watchdog.timer"
nudge_service_name="shopvivaliz-chatgpt-nudge-dispatcher.service"
nudge_timer_name="shopvivaliz-chatgpt-nudge-dispatcher.timer"
watchdog_service_source="$release_dir/deploy/systemd/$watchdog_service_name"
watchdog_timer_source="$release_dir/deploy/systemd/$watchdog_timer_name"
nudge_service_source="$release_dir/deploy/systemd/$nudge_service_name"
nudge_timer_source="$release_dir/deploy/systemd/$nudge_timer_name"
watchdog_service_target="/etc/systemd/system/$watchdog_service_name"
watchdog_timer_target="/etc/systemd/system/$watchdog_timer_name"
nudge_service_target="/etc/systemd/system/$nudge_service_name"
nudge_timer_target="/etc/systemd/system/$nudge_timer_name"
base_dir="/opt/shopvivaliz-gemini-24x7-controller"
releases_dir="$base_dir/releases"
environment_target="/etc/shopvivaliz-gemini-24x7-controller.env"
runtime_dir="/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state"
runtime_lock_dir="$runtime_dir/_runtime-lock"
e2e_failures_dir="/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state-e2e-failures"
gemini_cli_version="${SHOPVIVALIZ_GEMINI_CLI_VERSION:-0.62.0}"
gemini_cli_bin="/home/ubuntu/.local/bin/gemini"
gemini_cli_package_json="/home/ubuntu/.local/lib/node_modules/@google/gemini-cli/package.json"
durable_handoff="${SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF:-0}"
case "$durable_handoff" in 0|1) ;; *) echo "ERROR invalid SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF=$durable_handoff" >&2; exit 64 ;; esac

test -d "$release_dir"
test -f "$release_dir/scripts/gemini_24x7_controller.py"
test -f "$unit_source"
test -f "$resume_worker_service_source"
test -f "$watchdog_service_source"
test -f "$watchdog_timer_source"
test -f "$nudge_service_source"
test -f "$nudge_timer_source"
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
sudo install -d -o ubuntu -g ubuntu -m 2770 "$runtime_lock_dir"
for artifact in lock.json lock.lock; do
  if [ -e "$runtime_lock_dir/$artifact" ]; then
    sudo chown ubuntu:ubuntu "$runtime_lock_dir/$artifact"
    sudo chmod 0660 "$runtime_lock_dir/$artifact"
  fi
done
sudo install -d -o root -g root -m 0755 "$releases_dir"
target_dir="$releases_dir/$release_id"
if [ ! -d "$target_dir" ]; then
  stage_dir="$(sudo mktemp -d "$releases_dir/.${release_id}.XXXXXX")"
  cleanup() { sudo rm -rf "$stage_dir"; }
  trap cleanup EXIT
  sudo install -d -o root -g root -m 0755 "$stage_dir/scripts"
  sudo install -d -o root -g root -m 0755 "$stage_dir/scripts/continuity"
  for source in agent_task_state.py task_continuation_watchdog.py task_resume_queue.py task_resume_dispatcher.py task_resume_worker.py chatgpt_continuity_nudge_dispatcher.py run_background_gemini.py autonomous-provider-failover.sh safe_git_push.py gemini_24x7_controller.py; do
    sudo install -o root -g root -m 0755 "$release_dir/scripts/$source" "$stage_dir/scripts/$source"
  done
  for source in "$release_dir"/scripts/continuity/*.py; do
    sudo install -o root -g root -m 0644 "$source" "$stage_dir/scripts/continuity/$(basename "$source")"
  done
  sudo install -o root -g root -m 0644 "$release_dir/AGENTS.md" "$stage_dir/AGENTS.md"
  continuity_doc="$release_dir/docs/knowledge/task-continuity.md"
  if [ -f "$continuity_doc" ]; then
    sudo install -o root -g root -m 0644 "$continuity_doc" "$stage_dir/README"
  else
    # Runtime deployment artifacts may intentionally omit docs/. Keep the
    # systemd Documentation= target valid without mutating the source release.
    sudo install -o root -g root -m 0644 "$release_dir/AGENTS.md" "$stage_dir/README"
  fi
  # The systemd process runs as ubuntu and must traverse this immutable code
  # directory; runtime state remains under the separately protected shared
  # directory and is not copied into the release.
  sudo chmod 0755 "$stage_dir" "$stage_dir/scripts" "$stage_dir/scripts/continuity"
  sudo mv "$stage_dir" "$target_dir"
  trap - EXIT
fi

if ! command -v npm >/dev/null 2>&1; then
  echo "ERROR: npm is required to install Gemini CLI" >&2
  exit 69
fi

# Do not execute the Gemini CLI merely to inspect its installed version.
# A live incident proved that `gemini --version` can hang indefinitely and
# block an otherwise healthy immutable controller promotion. Read package
# metadata instead; the CLI itself remains validated by the runtime path.
read_gemini_package_version() {
  [ -r "$gemini_cli_package_json" ] || return 1
  node -e 'const fs=require("fs");try{const p=JSON.parse(fs.readFileSync(process.argv[1],"utf8"));process.stdout.write(String(p.version||""));}catch(e){process.exit(1)}' "$gemini_cli_package_json"
}

installed_gemini_version="$(read_gemini_package_version || true)"
if [ "$installed_gemini_version" != "$gemini_cli_version" ]; then
  npm install -g "@google/gemini-cli@$gemini_cli_version" --prefix /home/ubuntu/.local --no-audit --no-fund
fi
test -x "$gemini_cli_bin"
installed_gemini_version="$(read_gemini_package_version || true)"
if [ "$installed_gemini_version" != "$gemini_cli_version" ]; then
  echo "ERROR: Gemini CLI package version mismatch after install" >&2
  exit 70
fi

environment_temp="$(mktemp)"
trap 'rm -f "$environment_temp"' EXIT
printf 'SHOPVIVALIZ_GEMINI_CONTROLLER_ENTRY=%s\nSHOPVIVALIZ_RESUME_WORKER_ENTRY=%s\nSHOPVIVALIZ_CONTINUITY_WATCHDOG_ENTRY=%s\nSHOPVIVALIZ_CHATGPT_NUDGE_ENTRY=%s\nSHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF=%s\nSHOPVIVALIZ_AGENT_TASK_STATE_DIR=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state\nCHATGPT_CONTINUITY_MONITOR_REQUIRED=0\nCHATGPT_CONTINUITY_BRIDGE_URL=http://127.0.0.1:18081/api/chatgpt-continuity/bridge.php\nCHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE=/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token\nCHATGPT_CONTINUITY_BRIDGE_HOST_HEADER=shopvivaliz.com.br\nGEMINI_ENV_FILE=/home/ubuntu/.config/shopvivaliz-gemini-24x7/gemini.env\nSHOPVIVALIZ_BACKGROUND_CLAUDE_FALLBACK=1\nSHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK=1\nCLAUDE_BIN=/home/ubuntu/.local/bin/claude\nCODEX_AUTO_BIN=/home/ubuntu/.local/bin/codex-auto\n' \
  "$target_dir/scripts/gemini_24x7_controller.py" \
  "$target_dir/scripts/task_resume_worker.py" \
  "$target_dir/scripts/task_continuation_watchdog.py" \
  "$target_dir/scripts/chatgpt_continuity_nudge_dispatcher.py" \
  "$durable_handoff" > "$environment_temp"
sudo install -o root -g root -m 0640 "$environment_temp" "$environment_target"
rm -f "$environment_temp"
trap - EXIT
sudo install -o root -g root -m 0644 "$unit_source" "$unit_target"
sudo install -o root -g root -m 0644 "$resume_worker_service_source" "$resume_worker_service_target"
sudo install -o root -g root -m 0644 "$watchdog_service_source" "$watchdog_service_target"
sudo install -o root -g root -m 0644 "$watchdog_timer_source" "$watchdog_timer_target"
sudo install -o root -g root -m 0644 "$nudge_service_source" "$nudge_service_target"
sudo install -o root -g root -m 0644 "$nudge_timer_source" "$nudge_timer_target"
sudo systemd-analyze verify \
  "$unit_target" "$resume_worker_service_target" \
  "$watchdog_service_target" "$watchdog_timer_target" \
  "$nudge_service_target" "$nudge_timer_target"
sudo systemctl daemon-reload
sudo systemctl enable "$unit_name"
sudo systemctl enable "$resume_worker_service_name"
sudo systemctl enable --now "$watchdog_timer_name" "$nudge_timer_name"
sudo systemctl reset-failed "$unit_name" "$resume_worker_service_name"
sudo systemctl restart "$unit_name"
sudo systemctl restart "$resume_worker_service_name"
sudo systemctl start "$watchdog_service_name" "$nudge_service_name"
sudo systemctl is-enabled --quiet "$unit_name"
sudo systemctl is-enabled --quiet "$resume_worker_service_name"
sudo systemctl is-enabled --quiet "$watchdog_timer_name"
sudo systemctl is-enabled --quiet "$nudge_timer_name"
sudo systemctl is-active --quiet "$unit_name"
sudo systemctl is-active --quiet "$resume_worker_service_name"
sudo systemctl is-active --quiet "$watchdog_timer_name"
sudo systemctl is-active --quiet "$nudge_timer_name"
