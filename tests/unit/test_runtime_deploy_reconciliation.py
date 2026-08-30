from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class RuntimeDeployReconciliationContractTest(unittest.TestCase):
    def test_master_pipeline_uses_immutable_a1_release(self) -> None:
        text = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
        self.assertIn("tar --exclude=.git -czf /tmp/shopvivaliz-release.tgz .", text)
        self.assertIn("ubuntu@163.176.103.253", text)
        self.assertNotIn("ubuntu@137.131.156.17", text)
        self.assertIn("mv -Tf \"$root/current.next\" \"$current\"", text)
        self.assertIn("current.rollback", text)

    def test_master_pipeline_reconciles_runtime_services(self) -> None:
        text = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
        self.assertIn('sudo bash "$current/scripts/install-catalog-sync-service.sh"', text)
        installer = (ROOT / "scripts/install-catalog-sync-service.sh").read_text(encoding="utf-8")
        self.assertIn("shopvivaliz-token-renewer.service", installer)
        self.assertIn("shopvivaliz-shopee-token-renewer.service", installer)

    def test_master_pipeline_preserves_shared_runtime_state(self) -> None:
        text = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
        self.assertIn("for name in .env tasks-queue.json logs storage cache uploads sessions", text)
        self.assertIn("shared/runtime-secrets.php", text)
        self.assertIn("tail -n +6", text)

    def test_runtime_checks_wait_for_exact_release_on_push(self) -> None:
        for relative in (
            ".github/workflows/runtime-env-guard.yml",
            ".github/workflows/runtime-token-security.yml",
        ):
            text = (ROOT / relative).read_text(encoding="utf-8")
            self.assertIn("github.event_name == 'push'", text)
            self.assertIn('deployed_sha', text)
            self.assertIn('GITHUB_SHA', text)
            self.assertIn('.release-sha', text)

    def test_runtime_env_guard_smokes_local_release(self) -> None:
        text = (ROOT / ".github/workflows/runtime-env-guard.yml").read_text(encoding="utf-8")
        self.assertIn("http://127.0.0.1/auth/login.php", text)
        self.assertIn("-H 'Host: shopvivaliz.com.br'", text)
        self.assertNotIn("https://shopvivaliz.com.br/auth/login.php", text)


if __name__ == "__main__":
    unittest.main()
