from __future__ import annotations

import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / 'scripts' / 'shopee_logistics_watchdog.py'


def load_module():
    spec = importlib.util.spec_from_file_location('shopee_logistics_watchdog', MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


class WatchdogHealthTests(unittest.TestCase):
    def test_fresh_cycle_and_active_timer_is_healthy(self):
        m = load_module()
        status = m.evaluate_health(timer_enabled=True, timer_active=True, last_cycle_epoch=1_000, now=1_500, stale_after=720)
        self.assertTrue(status['healthy'])
        self.assertEqual(status['reasons'], [])

    def test_stale_cycle_is_unhealthy(self):
        m = load_module()
        status = m.evaluate_health(timer_enabled=True, timer_active=True, last_cycle_epoch=1_000, now=2_000, stale_after=720)
        self.assertFalse(status['healthy'])
        self.assertIn('cycle_stale', status['reasons'])

    def test_inactive_timer_is_unhealthy_even_with_fresh_cycle(self):
        m = load_module()
        status = m.evaluate_health(timer_enabled=True, timer_active=False, last_cycle_epoch=1_400, now=1_500, stale_after=720)
        self.assertFalse(status['healthy'])
        self.assertIn('timer_inactive', status['reasons'])

    def test_missing_cycle_is_unhealthy(self):
        m = load_module()
        status = m.evaluate_health(timer_enabled=True, timer_active=True, last_cycle_epoch=None, now=1_500, stale_after=720)
        self.assertFalse(status['healthy'])
        self.assertIn('cycle_missing', status['reasons'])

    def test_last_cycle_reader_uses_latest_cycle_event(self):
        m = load_module()
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'worker.jsonl'
            path.write_text('\n'.join([
                json.dumps({'kind':'cycle','at':'2026-09-30T20:00:00+00:00','errors':0}),
                json.dumps({'kind':'alert','at':'2026-09-30T20:02:00+00:00'}),
                json.dumps({'kind':'cycle','at':'2026-09-30T20:05:00+00:00','errors':0}),
            ]) + '\n')
            row = m.read_last_cycle(path)
        self.assertEqual(row['at'], '2026-09-30T20:05:00+00:00')


class WatchdogRunTests(unittest.TestCase):
    def test_healthy_run_does_not_repair_or_alert(self):
        m = load_module()
        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(m, 'systemctl_state', side_effect=['enabled','active']), \
             patch.object(m, 'read_last_cycle', return_value={'kind':'cycle','at':'1970-01-01T00:16:40+00:00','errors':0}), \
             patch.object(m, 'repair_worker') as repair, \
             patch.object(m, 'send_alert') as alert:
            result = m.run_watchdog(shared_root=Path(tmp), now=1_100, stale_after=720)
        self.assertTrue(result['healthy'])
        repair.assert_not_called()
        alert.assert_not_called()

    def test_unhealthy_run_repairs_and_alerts_once(self):
        m = load_module()
        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(m, 'systemctl_state', side_effect=['enabled','inactive','enabled','active']), \
             patch.object(m, 'read_last_cycle', side_effect=[
                 {'kind':'cycle','at':'1970-01-01T00:00:10+00:00','errors':0},
                 {'kind':'cycle','at':'1970-01-01T00:18:20+00:00','errors':0},
             ]), \
             patch.object(m, 'repair_worker', return_value=True) as repair, \
             patch.object(m, 'send_alert', return_value=True) as alert:
            result = m.run_watchdog(shared_root=Path(tmp), now=1_100, stale_after=720)
        self.assertTrue(result['repaired'])
        self.assertTrue(result['healthy_after_repair'])
        repair.assert_called_once()
        alert.assert_called_once()

    def test_persistent_failure_throttles_duplicate_alert(self):
        m = load_module()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            state_dir = root / 'storage/shopee-logistics-watchdog'
            state_dir.mkdir(parents=True)
            (state_dir / 'state.json').write_text(json.dumps({'last_fingerprint':'timer_inactive','last_alert_at':1_000}))
            with patch.object(m, 'systemctl_state', side_effect=['enabled','inactive','enabled','inactive']), \
                 patch.object(m, 'read_last_cycle', return_value={'kind':'cycle','at':'1970-01-01T00:18:20+00:00','errors':0}), \
                 patch.object(m, 'repair_worker', return_value=False), \
                 patch.object(m, 'send_alert') as alert:
                result = m.run_watchdog(shared_root=root, now=1_100, stale_after=720, alert_cooldown=900)
            self.assertFalse(result['healthy_after_repair'])
            self.assertTrue(result['alert_throttled'])
            alert.assert_not_called()


if __name__ == '__main__':
    unittest.main()

class WatchdogRecoveryStateTests(unittest.TestCase):
    def test_healthy_cycle_clears_failure_fingerprint_so_recurrence_alerts(self):
        m = load_module()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            state_dir = root / 'storage/shopee-logistics-watchdog'
            state_dir.mkdir(parents=True)
            state_path = state_dir / 'state.json'
            state_path.write_text(json.dumps({'last_fingerprint':'timer_inactive','last_alert_at':1_000}))
            with patch.object(m, 'systemctl_state', side_effect=['enabled','active']), \
                 patch.object(m, 'read_last_cycle', return_value={'kind':'cycle','at':'1970-01-01T00:18:20+00:00','errors':0}), \
                 patch.object(m, 'repair_worker') as repair, \
                 patch.object(m, 'send_alert') as alert:
                first = m.run_watchdog(shared_root=root, now=1_100, stale_after=720, alert_cooldown=900)
            self.assertTrue(first['healthy'])
            repair.assert_not_called()
            alert.assert_not_called()
            state = json.loads(state_path.read_text())
            self.assertEqual(state.get('last_fingerprint'), '')
            self.assertEqual(state.get('last_check_at'), 1_100)

            with patch.object(m, 'systemctl_state', side_effect=['enabled','inactive','enabled','inactive']), \
                 patch.object(m, 'read_last_cycle', return_value={'kind':'cycle','at':'1970-01-01T00:18:20+00:00','errors':0}), \
                 patch.object(m, 'repair_worker', return_value=False), \
                 patch.object(m, 'send_alert', return_value=True) as alert2:
                second = m.run_watchdog(shared_root=root, now=1_150, stale_after=720, alert_cooldown=900)
            self.assertFalse(second['healthy_after_repair'])
            self.assertFalse(second['alert_throttled'])
            alert2.assert_called_once()
