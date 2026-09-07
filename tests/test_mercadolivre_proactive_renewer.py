from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_ml_renewer_daemon_uses_targeted_self_heal() -> None:
    daemon = ROOT / "daemon-mercadolivre-token-renewer.php"
    assert daemon.is_file()
    text = daemon.read_text(encoding="utf-8")
    assert "svih_ml(true)" in text
    assert "svih_check_all(true)" not in text


def test_ml_renewer_systemd_unit_runs_as_runtime_user() -> None:
    unit = (ROOT / "deploy/systemd/shopvivaliz-mercadolivre-token-renewer.service").read_text(encoding="utf-8")
    assert "User=ubuntu" in unit
    assert "Group=www-data" in unit
    assert "EnvironmentFile=/home/ubuntu/shopvivaliz-deploy/shared/.env" in unit
    assert "/usr/bin/php /home/ubuntu/shopvivaliz-deploy/current/daemon-mercadolivre-token-renewer.php --interval 300" in unit


def test_catalog_service_installer_manages_ml_renewer() -> None:
    text = (ROOT / "scripts/install-catalog-sync-service.sh").read_text(encoding="utf-8")
    assert "shopvivaliz-mercadolivre-token-renewer.service" in text
    assert "systemctl enable shopvivaliz-mercadolivre-token-renewer.service" in text
    assert "systemctl restart shopvivaliz-mercadolivre-token-renewer.service" in text
    assert "systemctl is-active --quiet shopvivaliz-mercadolivre-token-renewer.service" in text


def test_deploy_restarts_ml_renewer_after_release_swap() -> None:
    text = (ROOT / "scripts/deploy-production.sh").read_text(encoding="utf-8")
    assert '"shopvivaliz-mercadolivre-token-renewer.service"' in text


def test_deploy_installs_ml_unit_before_runtime_restart() -> None:
    text = (ROOT / "scripts/deploy-production.sh").read_text(encoding="utf-8")
    switch = text.rfind('mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"')
    reconcile = text.find('if ! reconcile_runtime_service_units "$NEW_RELEASE_PATH"; then', switch)
    restart = text.find("if ! restart_runtime_services; then", switch)
    assert switch >= 0
    assert switch < reconcile < restart
    assert "shopvivaliz-mercadolivre-token-renewer.service" in text[:switch]
    assert "systemctl daemon-reload" in text[:switch]
