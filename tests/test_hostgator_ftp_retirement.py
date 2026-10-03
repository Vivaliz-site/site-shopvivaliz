from __future__ import annotations

import json
import unittest
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


class HostGatorFtpRetirementTests(unittest.TestCase):
    def test_retired_hostgator_bootstrap_paths_are_gone(self):
        existing = [str(path.relative_to(ROOT)) for path in RETIRED_PATHS if path.exists()]
        self.assertEqual(existing, [], f"retired HostGator bootstrap paths still exist: {existing}")

    def test_active_runtime_has_no_ftp_credentials_or_network_client(self):
        errors = []
        for path in ACTIVE_FILES:
            text = path.read_text(encoding="utf-8", errors="replace")
            for marker in FORBIDDEN_RUNTIME_MARKERS:
                if marker in text:
                    errors.append(f"{path.relative_to(ROOT)} contains {marker}")
        self.assertEqual(errors, [], "\n".join(errors))

    def test_autonomous_config_has_no_ftp_deploy(self):
        payload = json.loads((ROOT / "config" / "autonomous-settings.json").read_text(encoding="utf-8"))
        serialized = json.dumps(payload, ensure_ascii=False).lower()
        self.assertNotIn("autonomous-ftp-deploy", serialized)
        self.assertNotIn("auto ftp deploy", serialized)
        self.assertNotIn('"ftp"', serialized)

    def test_secret_groups_have_no_ftp_scope(self):
        payload = json.loads((ROOT / "config" / "secrets-groups.json").read_text(encoding="utf-8"))
        self.assertNotIn("ftp", payload)

    def test_ai_image_pipeline_uses_persistent_public_storage(self):
        generator = (ROOT / "scripts" / "generate-ai-images.py").read_text(encoding="utf-8")
        uploader = (ROOT / "scripts" / "upload_images.py").read_text(encoding="utf-8")
        self.assertIn("publish_file", generator)
        self.assertIn("publish_file", uploader)


if __name__ == "__main__":
    unittest.main()
