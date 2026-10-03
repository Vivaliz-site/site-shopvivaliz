from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

RETIRED_PATHS = [
    ROOT / ".github" / "workflows" / "deploy.yml",
    ROOT / "scripts" / "setup-auto-deploy.py",
    ROOT / "scripts" / "setup-auto-deploy.ps1",
    ROOT / "scripts" / "testar-ftp.ps1",
    ROOT / "scripts" / ".ftp-credentials.example",
]

ACTIVE_FILES = [
    ROOT / "scripts" / "generate-ai-images.py",
    ROOT / "scripts" / "upload_images.py",
    ROOT / "scripts" / "automation" / "update_dashboard.py",
    ROOT / "scripts" / "utils" / "ftp_client.py",
    ROOT / "scripts" / "integrations" / "ftp_uploader.py",
    ROOT / "config" / "secrets.py",
    ROOT / "config" / "constants.php",
    ROOT / "scripts" / "test_pipeline.py",
    ROOT / "scripts" / "deploy-diagnostic.py",
]

FORBIDDEN_RUNTIME_MARKERS = (
    "import ftplib",
    "from ftplib import",
    "smtp.titan.email",
    "smtp0101.titan.email",
    "FTP_SERVER",
    "FTP_USERNAME",
    "FTP_PASSWORD",
    "FTP_HOST",
    "FTP_USER",
    "FTP_PASS",
)


def test_retired_hostgator_bootstrap_paths_are_gone():
    existing = [str(path.relative_to(ROOT)) for path in RETIRED_PATHS if path.exists()]
    assert not existing, f"retired HostGator bootstrap paths still exist: {existing}"


def test_active_runtime_has_no_ftp_credentials_or_network_client():
    errors = []
    for path in ACTIVE_FILES:
        text = path.read_text(encoding="utf-8", errors="replace")
        for marker in FORBIDDEN_RUNTIME_MARKERS:
            if marker in text:
                errors.append(f"{path.relative_to(ROOT)} contains {marker}")
    assert not errors, "\n".join(errors)


def test_autonomous_config_has_no_ftp_deploy():
    payload = json.loads((ROOT / "config" / "autonomous-settings.json").read_text(encoding="utf-8"))
    serialized = json.dumps(payload, ensure_ascii=False).lower()
    assert "autonomous-ftp-deploy" not in serialized
    assert "auto ftp deploy" not in serialized
    assert '"ftp"' not in serialized


def test_secret_groups_have_no_ftp_scope():
    payload = json.loads((ROOT / "config" / "secrets-groups.json").read_text(encoding="utf-8"))
    assert "ftp" not in payload


def test_ai_image_pipeline_uses_persistent_public_storage():
    generator = (ROOT / "scripts" / "generate-ai-images.py").read_text(encoding="utf-8")
    uploader = (ROOT / "scripts" / "upload_images.py").read_text(encoding="utf-8")
    assert "publish_file" in generator
    assert "publish_file" in uploader
