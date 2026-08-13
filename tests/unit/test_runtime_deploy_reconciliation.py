from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


class RuntimeDeployReconciliationContractTest(unittest.TestCase):
    def test_master_pipeline_always_stages_exact_runner(self) -> None:
        text = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
        self.assertIn('expected_runner_blob="$(git -C "$repo" rev-parse "${expected_sha}:scripts/deploy-production.sh")"', text)
        self.assertIn('current_runner_blob="$(git -C "$repo" hash-object -- "$deploy_script")"', text)
        self.assertIn('test "$(git -C "$repo" hash-object -- "$deploy_script")" = "$expected_runner_blob"', text)

    def test_master_pipeline_ignores_only_untracked_clone_noise(self) -> None:
        text = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
        self.assertIn('git -C "$repo" config status.showUntrackedFiles no', text)
        self.assertIn('status --porcelain=v1 --untracked-files=no', text)
        self.assertNotIn('status --porcelain=v1 --untracked-files=all', text)
        self.assertIn('Unexpected dirty deploy checkout:', text)
        self.assertIn("git archive", (ROOT / "scripts/deploy-production.sh").read_text(encoding="utf-8"))

    def test_busy_lock_waits_long_enough_for_canonical_cron_then_reconciles(self) -> None:
        text = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
        self.assertIn('timeout-minutes: 20', text)
        self.assertIn('flock -w 600 -E 75 /var/lock/shopvivaliz-deploy.lock "$deploy_script" "$expected_sha"', text)
        self.assertIn('Waiting up to 600s to run exact runner after concurrent deployment', text)
        self.assertNotIn('flock -w 180 -E 75 /var/lock/shopvivaliz-deploy.lock', text)

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
