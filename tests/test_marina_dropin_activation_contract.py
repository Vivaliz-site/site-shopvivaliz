"""Activation contract for the Cloudflare Access drop-in that the release unit replaces."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UNIT = (ROOT / 'marina-direct-mcp/systemd/shopvivaliz-marina-mcp.service').read_text()
SCRIPT = (ROOT / 'marina-direct-mcp/operator_upgrade_release.sh').read_text()
ACCESS = '/var/lib/shopvivaliz-marina-mcp/.config/shopvivaliz-marina-mcp/cloudflare-access.json'
DROPIN = '/etc/systemd/system/shopvivaliz-marina-mcp.service.d/40-cloudflare-access.conf'


class DropinActivationContractTests(unittest.TestCase):
    def test_release_unit_carries_cloudflare_access_config(self):
        execstart = [line for line in UNIT.splitlines() if line.startswith('ExecStart=')]
        self.assertEqual(len(execstart), 1)
        self.assertIn(f'--access-config {ACCESS}', execstart[0])

    def test_access_argument_extraction_matches_release_unit(self):
        pattern = r'^ExecStart=.* --access-config ([^ \n]*)( .*)?$'
        match = re.search(pattern, UNIT, re.MULTILINE)
        self.assertIsNotNone(match)
        self.assertEqual(match.group(1), ACCESS)

    def test_unexpected_dropin_is_refused_before_any_mutation(self):
        self.assertIn('[[ -z "$dropin_paths" || "$dropin_paths" == "$dropin_path" ]] || refuse service_dropin_unexpected', SCRIPT)
        self.assertIn(f'dropin_path={DROPIN}', SCRIPT)

    def test_dropin_is_moved_aside_atomically_and_restored_on_rollback(self):
        self.assertIn('mv -f "$dropin_path" "$dropin_backup"', SCRIPT)
        self.assertIn('mv -f "$dropin_backup" "$dropin_path"', SCRIPT)
        move_index = SCRIPT.index('mv -f "$dropin_path" "$dropin_backup"')
        unit_index = SCRIPT.index('mv -Tf "$temp_unit" "$unit"')
        self.assertLess(move_index, unit_index)

    def test_dropin_access_config_must_match_release_before_mutation(self):
        self.assertIn('[[ "$dropin_access" == "$access_arg" ]] || refuse dropin_access_config_mismatch', SCRIPT)
        self.assertIn('[[ -n "$access_arg" ]] || refuse release_access_config_missing', SCRIPT)

    def test_effective_access_config_and_dropin_absence_checked_after_restart(self):
        self.assertIn('grep -Fq -- "--access-config $access_arg" || refuse access_config_not_effective', SCRIPT)
        self.assertIn('-p DropInPaths --value)" == "" ]] || refuse dropin_still_loaded', SCRIPT)


if __name__ == '__main__':
    unittest.main(verbosity=2)
