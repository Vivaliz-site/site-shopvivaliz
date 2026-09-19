"""Legacy Mercado Livre renewer contract, now gated by credential ownership.

After the MLRR OAuth ownership plan (Task 8/9) ShopVivaLiz still ships the legacy
renewer unit, but deploy, rollback and the catalog installer must keep it
disabled whenever the shared runtime declares ``ML_TOKEN_OWNER=mlrr``.
"""

from __future__ import annotations

import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEPLOY = ROOT / "scripts/deploy-production.sh"
INSTALLER = ROOT / "scripts/install-catalog-sync-service.sh"
ML_SERVICE = "shopvivaliz-mercadolivre-token-renewer.service"


def extract_bash_function(text: str, name: str) -> str:
    start = text.index(f"{name}() {{")
    end = text.index("\n}\n", start) + len("\n}\n")
    return text[start:end]


def run_owner_probe(tmp_path: Path, script_body: str, runtime_contents: str | None) -> str:
    shared = tmp_path / "shared"
    shared.mkdir(parents=True, exist_ok=True)
    if runtime_contents is not None:
        (shared / "runtime-secrets.php").write_text(runtime_contents, encoding="utf-8")
    probe = tmp_path / "probe.sh"
    probe.write_text(
        "#!/usr/bin/env bash\nset -Eeuo pipefail\n"
        f'SHARED_DIR="{shared}"\n'
        f"{script_body}\nshared_ml_token_owner\n",
        encoding="utf-8",
    )
    completed = subprocess.run(
        ["bash", str(probe)], capture_output=True, text=True, timeout=60, check=False
    )
    assert completed.returncode == 0, completed.stderr
    return completed.stdout.strip()


# --------------------------------------------------------------------------
# Daemon
# --------------------------------------------------------------------------


def test_ml_renewer_daemon_uses_ownership_aware_self_heal() -> None:
    daemon = ROOT / "daemon-mercadolivre-token-renewer.php"
    assert daemon.is_file()
    text = daemon.read_text(encoding="utf-8")
    assert "svih_ml(ml_token_owner() !== 'mlrr')" in text
    assert "svih_ml(true)" not in text
    assert "svih_check_all(true)" not in text


def test_ml_renewer_systemd_unit_runs_as_runtime_user() -> None:
    unit = (ROOT / "deploy/systemd/shopvivaliz-mercadolivre-token-renewer.service").read_text(encoding="utf-8")
    assert "User=ubuntu" in unit
    assert "Group=www-data" in unit
    assert "EnvironmentFile=/home/ubuntu/shopvivaliz-deploy/shared/.env" in unit
    assert "/usr/bin/php /home/ubuntu/shopvivaliz-deploy/current/daemon-mercadolivre-token-renewer.php --interval 300" in unit


# --------------------------------------------------------------------------
# Shared-owner resolution
# --------------------------------------------------------------------------


def test_deploy_resolves_owner_from_the_protected_runtime_not_from_env(tmp_path: Path) -> None:
    body = extract_bash_function(DEPLOY.read_text(encoding="utf-8"), "shared_ml_token_owner")
    assert "runtime-secrets.php" in body
    assert "source " not in body and ". $SHARED_DIR" not in body
    assert "/.env" not in body

    assert run_owner_probe(
        tmp_path / "mlrr", body, "<?php return ['ML_TOKEN_OWNER' => 'mlrr'];\n"
    ) == "mlrr"
    assert run_owner_probe(
        tmp_path / "legacy", body, "<?php return ['ML_TOKEN_OWNER' => 'legacy'];\n"
    ) == "legacy"
    assert run_owner_probe(tmp_path / "absent", body, None) == "legacy"
    assert run_owner_probe(tmp_path / "empty", body, "<?php return [];\n") == "legacy"
    assert run_owner_probe(tmp_path / "broken", body, "not php at all\n") == "legacy"
    assert run_owner_probe(
        tmp_path / "upper", body, "<?php return ['ML_TOKEN_OWNER' => ' MLRR '];\n"
    ) == "mlrr"


def test_deploy_excludes_the_legacy_renewer_when_mlrr_owns_credentials(tmp_path: Path) -> None:
    text = DEPLOY.read_text(encoding="utf-8")
    start = text.index("readonly -a RUNTIME_SERVICES=(")
    services_decl = text[start : text.index(")\n", start) + 2]
    owner_fn = extract_bash_function(text, "shared_ml_token_owner")
    active_fn = extract_bash_function(text, "active_runtime_services")

    def probe(owner: str) -> list[str]:
        shared = tmp_path / owner
        shared.mkdir(parents=True, exist_ok=True)
        (shared / "runtime-secrets.php").write_text(
            f"<?php return ['ML_TOKEN_OWNER' => '{owner}'];\n", encoding="utf-8"
        )
        script = tmp_path / f"probe-{owner}.sh"
        script.write_text(
            "#!/usr/bin/env bash\nset -Eeuo pipefail\n"
            f'SHARED_DIR="{shared}"\n'
            f'ML_RENEWER_SERVICE="{ML_SERVICE}"\n'
            f"{services_decl}\n{owner_fn}\n{active_fn}\nactive_runtime_services\n",
            encoding="utf-8",
        )
        completed = subprocess.run(
            ["bash", str(script)], capture_output=True, text=True, timeout=60, check=False
        )
        assert completed.returncode == 0, completed.stderr
        return completed.stdout.split()

    legacy_services = probe("legacy")
    mlrr_services = probe("mlrr")

    assert ML_SERVICE in legacy_services
    assert ML_SERVICE not in mlrr_services
    assert "shopvivaliz-queue-worker.service" in mlrr_services
    assert "shopvivaliz-token-renewer.service" in mlrr_services
    assert "shopvivaliz-shopee-token-renewer.service" in mlrr_services


def test_deploy_disables_the_legacy_renewer_under_mlrr_ownership() -> None:
    text = DEPLOY.read_text(encoding="utf-8")
    reconcile = extract_bash_function(text, "reconcile_runtime_service_units")
    owner_check = reconcile.index('= "mlrr" ]')
    disable = reconcile.index(f'systemctl disable --now "$ML_RENEWER_SERVICE"')
    enable = reconcile.index('systemctl enable "$ML_RENEWER_SERVICE"')
    assert owner_check < disable < enable, "MLRR branch must disable before the legacy enable path"


def test_rollback_reuses_the_current_shared_owner() -> None:
    text = DEPLOY.read_text(encoding="utf-8")
    rollback = extract_bash_function(text, "rollback_to")
    # Rollback reconciles and restarts through the same owner-aware helpers, so
    # an older release can never resurrect the legacy renewer.
    assert "reconcile_runtime_service_units" in rollback
    assert "restart_runtime_services" in rollback
    restart = extract_bash_function(text, "restart_runtime_services")
    assert "active_runtime_services" in restart
    assert '"${RUNTIME_SERVICES[@]}"' not in restart


def test_deploy_restarts_ml_renewer_after_release_swap() -> None:
    text = DEPLOY.read_text(encoding="utf-8")
    assert f'"{ML_SERVICE}"' in text


def test_deploy_installs_ml_unit_before_runtime_restart() -> None:
    text = DEPLOY.read_text(encoding="utf-8")
    switch = text.rfind('mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"')
    reconcile = text.find('if ! reconcile_runtime_service_units "$NEW_RELEASE_PATH"; then', switch)
    restart = text.find("if ! restart_runtime_services; then", switch)
    assert switch >= 0
    assert switch < reconcile < restart
    assert ML_SERVICE in text[:switch]
    assert "systemctl daemon-reload" in text[:switch]


# --------------------------------------------------------------------------
# Catalog sync installer
# --------------------------------------------------------------------------


def test_catalog_service_installer_follows_shared_ownership(tmp_path: Path) -> None:
    text = INSTALLER.read_text(encoding="utf-8")
    body = extract_bash_function(text, "shared_ml_token_owner")
    assert "runtime-secrets.php" in body

    owner_check = text.index('= "mlrr" ]')
    disable = text.index(f"systemctl disable --now {ML_SERVICE}")
    enable = text.index(f"systemctl enable {ML_SERVICE}")
    restart = text.index(f"systemctl restart {ML_SERVICE}")
    active = text.index(f"systemctl is-active --quiet {ML_SERVICE}")

    assert owner_check < disable < enable < restart < active
    assert "systemctl is-active --quiet shopvivaliz-catalog-reconcile.timer" in text
    assert "systemctl is-enabled --quiet shopvivaliz-catalog-reconcile.timer" in text


def test_catalog_service_installer_is_valid_bash() -> None:
    completed = subprocess.run(
        ["bash", "-n", str(INSTALLER)], capture_output=True, text=True, check=False
    )
    assert completed.returncode == 0, completed.stderr
