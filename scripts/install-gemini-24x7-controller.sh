#!/usr/bin/env bash
set -Eeuo pipefail

# Install from an immutable release path; callers must never edit current or a
# release in place.  The service follows the atomically switched current link.
release_dir="${1:?immutable release directory required}"
release_id="${2:-}"
unit_name="shopvivaliz-gemini-24x7-controller.service"
unit_source="$release_dir/deploy/systemd/$unit_name"
unit_target="/etc/systemd/system/$unit_name"
base_dir="/opt/shopvivaliz-gemini-24x7-controller"
releases_dir="$base_dir/releases"
environment_target="/etc/shopvivaliz-gemini-24x7-controller.env"

test -d "$release_dir"
test -f "$release_dir/scripts/gemini_24x7_controller.py"
test -f "$unit_source"
python3 -m py_compile "$release_dir/scripts/gemini_24x7_controller.py"
if [ -z "$release_id" ] && [ -f "$release_dir/.release-sha" ]; then
  release_id="$(tr -d '[:space:]' < "$release_dir/.release-sha")"
fi
if ! [[ "$release_id" =~ ^[A-Za-z0-9._-]{7,160}$ ]]; then
  echo "ERROR: immutable release id is required" >&2
  exit 64
fi

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
  sudo mv "$stage_dir" "$target_dir"
  trap - EXIT
fi

environment_temp="$(mktemp)"
trap 'rm -f "$environment_temp"' EXIT
printf 'SHOPVIVALIZ_GEMINI_CONTROLLER_ENTRY=%s\nSHOPVIVALIZ_AGENT_TASK_STATE_DIR=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state\n' "$target_dir/scripts/gemini_24x7_controller.py" > "$environment_temp"
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
