from pathlib import Path

workflow = Path(".github/workflows/pr-conflict-auto-healer.yml").read_text()
completion = Path(".github/workflows/pr-completion-enforcer.yml").read_text()
healer = Path("scripts/pr_conflict_vm_heal.sh").read_text()
assert "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]" in workflow
assert "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]" in completion
assert "independent_backend_operator_verified=true" in workflow
assert "ssh -o BatchMode" not in workflow and "REMOTE_BASE" not in workflow
assert "SHOPVIVALIZ_VM_SSH_KEY" not in workflow and "ORACLE_VM_SSH_KEY" not in workflow
assert "trusted/.github/scripts/ai_conflict_resolver.py" in workflow
assert "trusted/scripts/pr_conflict_vm_heal.sh" in workflow
assert "/tmp/shopvivaliz-pr-heal-" not in healer
assert "work_parent=/home/ubuntu/.cache/shopvivaliz-pr-healer-work" in healer
assert "flock -n 9" in healer
assert "GEMINI_ENV_FILE=\"$shared_env\"" in healer
assert "OLLAMA_MODEL=qwen2.5-coder:1.5b" in healer
assert "trusted_local_healer" in healer
assert "pr_heal_concurrent_owner_busy" in healer
assert "external_github_auth_used=true" in healer
assert "pr_finalizer_host=always-free-arm-1787907847-26" in completion
print("pr-conflict-healer-staging-contract: ok")
