from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


class RuntimeDeployReconciliationContractTest(unittest.TestCase):
    def test_master_pipeline_packages_the_exact_deploy_sha(self) -> None:
        text = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
        self.assertIn('ref: ${{ env.DEPLOY_SHA }}', text)
        self.assertIn('test "$(git rev-parse HEAD)" = "$DEPLOY_SHA"', text)
        self.assertIn('rsync -a --checksum --delete --link-dest=', text)
        self.assertIn('release_dir="/home/ubuntu/shopvivaliz-deploy/releases/', text)
        self.assertIn('\"ubuntu@127.0.0.1:$release_dir/\"', text)
        self.assertEqual(text.count('runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]'), 2)
        self.assertNotIn('ubuntu@163.176.103.253', text)
        self.assertNotIn('|| true', text)

    def test_master_pipeline_activates_an_immutable_release_atomically(self) -> None:
        text = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
        self.assertIn('printf \'%s\\n\' "$sha" > "$release/.release-sha"', text)
        self.assertIn('ln -sfn "releases/$(basename "$release")" "$root/current.next"', text)
        self.assertIn('mv -Tf "$root/current.next" "$current"', text)
        self.assertNotIn('/var/lock/shopvivaliz-deploy.lock', text)
        self.assertNotIn('expected_runner_blob=', text)

    def test_master_pipeline_reconciles_runtime_permissions_and_sqlite_dependency(self) -> None:
        text = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
        self.assertIn("php8.3-sqlite3", text)
        self.assertIn('sudo chgrp -R www-data "$shared/$name"', text)
        self.assertIn('sudo chmod -R g+rwX "$shared/$name"', text)
        self.assertIn('sudo find "$shared/$name" -type d -exec chmod g+s {} +', text)
        self.assertIn('sudo chgrp www-data "$shared/tasks-queue.json"', text)
        self.assertIn('sudo chmod g+rw "$shared/tasks-queue.json"', text)
        self.assertIn("pdo_sqlite", text)
        self.assertIn("extension_loaded(\"pdo_sqlite\")", text)
        self.assertNotIn("php -m | grep -Fxq pdo_sqlite", text)

    def test_master_pipeline_rolls_back_a_failed_release(self) -> None:
        text = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
        self.assertIn('previous="$(readlink -f "$current" 2>/dev/null)"', text)
        self.assertIn('[ "$served_sha" = "$sha" ] || fail=1', text)
        self.assertIn('ln -sfn "releases/$(basename "$previous")" "$root/current.rollback"', text)
        self.assertIn('mv -Tf "$root/current.rollback" "$current"', text)

    def test_runtime_checks_wait_off_the_oracle_runner(self) -> None:
        reusable = (ROOT / ".github/workflows/production-release-await.yml").read_text(encoding="utf-8")
        self.assertIn("workflow_call:", reusable)
        self.assertIn("runs-on: ubuntu-latest", reusable)
        self.assertIn("deployment/latest.json?ref=deployment-evidence", reusable)
        self.assertIn("production_release_relation=exact", reusable)

        env_guard = (ROOT / ".github/workflows/runtime-env-guard.yml").read_text(encoding="utf-8")
        self.assertIn("runs-on: ubuntu-latest", env_guard)
        self.assertIn("deployment/latest.json?ref=deployment-evidence", env_guard)
        self.assertIn("needs: await-release", env_guard)
        guard_job = env_guard.split("  guard:\n", 1)[1]
        self.assertNotIn("deployment_wait_attempt", guard_job)

        token = (ROOT / ".github/workflows/runtime-token-security.yml").read_text(encoding="utf-8")
        self.assertIn("uses: ./.github/workflows/production-release-await.yml", token)
        self.assertIn("expected_sha: ${{ needs.preflight.outputs.expected_sha }}", token)
        audit_job = token.split("  audit:\n", 1)[1]
        self.assertNotIn("deployment_wait_attempt", audit_job)

    def test_runtime_env_guard_smokes_local_release(self) -> None:
        text = (ROOT / ".github/workflows/runtime-env-guard.yml").read_text(encoding="utf-8")
        self.assertIn("http://127.0.0.1:8080/auth/login.php", text)
        self.assertIn("http://127.0.0.1:8080/auth/google-start.php", text)
        self.assertNotIn("http://127.0.0.1/auth/login.php", text)
        self.assertIn("Banco de dados indisponível", text)
        self.assertNotIn("indisponÃ­vel", text)
        self.assertIn("-H 'Host: shopvivaliz.com.br'", text)
        self.assertNotIn("https://shopvivaliz.com.br/auth/login.php", text)


if __name__ == "__main__":
    unittest.main()
