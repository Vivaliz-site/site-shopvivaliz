from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PIPELINE = ROOT / ".github" / "workflows" / "master-production-pipeline.yml"
INSTALLER = ROOT / "scripts" / "install-shopvivaliz-agent-service.sh"
SERVICE = ROOT / "deploy" / "systemd" / "shopvivaliz-agent.service"


def test_master_pipeline_installs_continuity_agent_after_release_switch() -> None:
    text = PIPELINE.read_text(encoding="utf-8")
    switch = 'mv -Tf "$root/current.next" "$current"'
    install = 'sudo bash "$current/scripts/install-shopvivaliz-agent-service.sh" "$current"'
    assert switch in text
    assert install in text
    assert text.index(switch) < text.index(install)


def test_master_pipeline_monitors_continuity_agent_runtime() -> None:
    text = PIPELINE.read_text(encoding="utf-8")
    monitor = text.split("  monitor:", 1)[1]
    assert "systemctl is-active --quiet shopvivaliz-agent.service" in monitor
    assert "systemctl is-enabled --quiet shopvivaliz-agent.service" in monitor


def test_agent_installer_does_not_mutate_active_immutable_release() -> None:
    text = INSTALLER.read_text(encoding="utf-8")
    assert 'chown ubuntu:ubuntu "$PROJECT_DIR/scripts/autonomous-agent-loop.sh"' not in text
    assert 'chmod 0755 "$PROJECT_DIR/scripts/autonomous-agent-loop.sh"' not in text


def test_agent_service_runs_dynamic_current_release_with_restart() -> None:
    text = SERVICE.read_text(encoding="utf-8")
    assert "WorkingDirectory=/home/ubuntu/shopvivaliz-deploy/current" in text
    assert "ExecStart=/bin/bash /home/ubuntu/shopvivaliz-deploy/current/scripts/autonomous-agent-loop.sh" in text
    assert "Restart=always" in text
