"""Guard against unattended commercial writes to legacy derived MySQL products.

The scheduled catalog refresher is allowed to update ERP-backed cache; it must
not apply prices, stock, IDs or activation to the legacy MySQL table. A separate
owner-approved, transactional, lease-protected executor owns that operation.
"""
from pathlib import Path
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "products-active-sync-loop.sh"


class ProductsActiveSyncHumanOnlyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = SCRIPT.read_text(encoding="utf-8")

    def test_canonical_erp_cache_daemon_is_still_present(self):
        self.assertIn("/usr/bin/python3 daemon-sync-products.py --once --workers 4", self.source)
        self.assertIn('if /usr/bin/python3 daemon-sync-products.py --once --workers 4; then', self.source)

    def test_unattended_mysql_importer_must_never_execute(self):
        for line in self.source.splitlines():
            code = line.split("#", 1)[0].strip()
            self.assertNotIn("sync-daemon-to-db.php", code)
            self.assertNotIn("sync-cache-to-admin.php", code)
        self.assertIn("MYSQL_DERIVED_HUMAN_ONLY", self.source)
        self.assertIn("MYSQL_DERIVED_WRITE=SKIPPED reason=human_only_commercial_sync", self.source)


    def test_postdeploy_stock_refresh_is_also_human_only(self):
        # A manual GitHub workflow is still an unattended executor of SQL once
        # dispatched.  Keep both deployment and periodic paths fail-closed.
        workflow = (
            Path(__file__).resolve().parents[1]
            / ".github/workflows/active-stock-refresh-after-deploy.yml"
        ).read_text(encoding="utf-8")
        executable = "\n".join(
            line.split("#", 1)[0]
            for line in workflow.splitlines()
            if not line.lstrip().startswith("#")
        )
        self.assertNotIn("sync-daemon-to-db.php", executable)
        self.assertNotIn("sync-cache-to-admin.php", executable)
        self.assertIn("php olist/sync-on-webhook.php", executable)
        self.assertIn("php olist/fetch-estoque-v3.php", executable)
        self.assertIn("MYSQL_DERIVED_WRITE=SKIPPED reason=human_only_commercial_sync", workflow)

    def test_shell_stays_bounded_and_fails_on_daemon_error(self):
        self.assertIn("set -Eeuo pipefail", self.source)
        self.assertIn("Falha ao buscar produtos ativos", self.source)
        self.assertIn('INTERVAL_SECONDS="${PRODUCTS_ACTIVE_SYNC_INTERVAL:-10800}"', self.source)


if __name__ == "__main__":
    unittest.main()
