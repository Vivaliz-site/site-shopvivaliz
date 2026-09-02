from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


class RuntimeDeployReconciliationContractTest(unittest.TestCase):
    def test_master_pipeline_packages_the_exact_deploy_sha(self) -> None:
        text = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
        self.assertIn('ref: ${{ env.DEPLOY_SHA }}', text)
        self.assertIn('test "$(git rev-parse HEAD)" = "$DEPLOY_SHA"', text)
        self.assertIn('tar --exclude=.git -czf /tmp/shopvivaliz-release.tgz .', text)
        self.assertIn('/tmp/shopvivaliz-release-${DEPLOY_SHA}.tgz', text)

    def test_master_pipeline_activates_an_immutable_release_atomically(self) -> None:
        text = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
        self.assertIn('printf \'%s\\n\' "$sha" > "$release/.release-sha"', text)
        self.assertIn('ln -sfn "releases/$(basename "$release")" "$root/current.next"', text)
        self.assertIn('mv -Tf "$root/current.next" "$current"', text)
        self.assertNotIn('/var/lock/shopvivaliz-deploy.lock', text)
        self.assertNotIn('expected_runner_blob=', text)

    def test_master_pipeline_rolls_back_a_failed_release(self) -> None:
        text = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
        self.assertIn('previous="$(readlink -f "$current" 2>/dev/null || true)"', text)
        self.assertIn('[ "$served_sha" = "$sha" ] || fail=1', text)
        self.assertIn('ln -sfn "releases/$(basename "$previous")" "$root/current.rollback"', text)
        self.assertIn('mv -Tf "$root/current.rollback" "$current"', text)

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
        self.assertIn("http://127.0.0.1:8080/auth/login.php", text)
        self.assertIn("http://127.0.0.1:8080/auth/google-start.php", text)
        self.assertNotIn("http://127.0.0.1/auth/login.php", text)
        self.assertIn("-H 'Host: shopvivaliz.com.br'", text)
        self.assertNotIn("https://shopvivaliz.com.br/auth/login.php", text)


if __name__ == "__main__":
    unittest.main()
