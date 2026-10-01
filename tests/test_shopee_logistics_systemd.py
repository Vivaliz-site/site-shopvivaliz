from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class ShopeeLogisticsSystemdTests(unittest.TestCase):
    def test_service_runs_apply_from_current_with_shared_env(self):
        text = (ROOT / 'deploy/systemd/shopvivaliz-shopee-logistics-worker.service').read_text()
        self.assertIn('EnvironmentFile=/home/ubuntu/shopvivaliz-deploy/shared/.env', text)
        self.assertIn('/home/ubuntu/shopvivaliz-deploy/current/scripts/shopee_logistics_worker.py --apply', text)
        self.assertIn('ReadWritePaths=/home/ubuntu/shopvivaliz-deploy/shared', text)
        self.assertIn('SupplementaryGroups=ubuntu', text)

    def test_timer_is_frequent_enough_for_turbo_sla(self):
        text = (ROOT / 'deploy/systemd/shopvivaliz-shopee-logistics-worker.timer').read_text()
        self.assertIn('OnUnitActiveSec=5min', text)
        self.assertIn('Persistent=true', text)

    def test_installer_enables_timer(self):
        text = (ROOT / 'scripts/install-shopee-logistics-worker.sh').read_text()
        self.assertIn('systemctl enable --now "$TIMER"', text)
        self.assertIn('systemd-analyze verify', text)

    def test_deploy_reconciles_and_rolls_back_worker(self):
        text = (ROOT / 'scripts/deploy-production.sh').read_text()
        self.assertIn('reconcile_shopee_logistics_units', text)
        self.assertIn('disable_shopee_logistics_timer', text)
        self.assertGreaterEqual(text.count('reconcile_shopee_logistics_units'), 3)

    def test_watchdog_service_has_repair_access_and_shared_env(self):
        text = (ROOT / 'deploy/systemd/shopvivaliz-shopee-logistics-watchdog.service').read_text()
        self.assertIn('EnvironmentFile=/home/ubuntu/shopvivaliz-deploy/shared/.env', text)
        self.assertIn('/home/ubuntu/shopvivaliz-deploy/current/scripts/shopee_logistics_watchdog.py', text)
        self.assertIn('ReadWritePaths=/home/ubuntu/shopvivaliz-deploy/shared /etc/systemd/system', text)
        self.assertIn('SHOPEE_LOGISTICS_STALE_AFTER_SECONDS=540', text)

    def test_watchdog_timer_runs_every_five_minutes(self):
        text = (ROOT / 'deploy/systemd/shopvivaliz-shopee-logistics-watchdog.timer').read_text()
        self.assertIn('OnUnitActiveSec=5min', text)
        self.assertIn('Persistent=true', text)

    def test_installer_enables_worker_and_watchdog_timers(self):
        text = (ROOT / 'scripts/install-shopee-logistics-worker.sh').read_text()
        self.assertIn('systemctl enable --now "$TIMER" "$WATCHDOG_TIMER"', text)
        self.assertIn('shopvivaliz-shopee-logistics-watchdog.service', text)
        self.assertIn('shopvivaliz-shopee-logistics-watchdog.timer', text)

    def test_rollback_disables_watchdog_too(self):
        text = (ROOT / 'scripts/deploy-production.sh').read_text()
        self.assertIn('shopvivaliz-shopee-logistics-watchdog.timer', text)
        self.assertIn('shopvivaliz-shopee-logistics-watchdog.service', text)


if __name__ == '__main__':
    unittest.main()
