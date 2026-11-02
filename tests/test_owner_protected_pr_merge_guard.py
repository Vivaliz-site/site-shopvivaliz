"""Regression for finalizer bot merge of PR #3499 without any human review."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts/merge_green_pr_via_vm.sh"
SHA = "a" * 40

FAKE_GH = r'''#!/usr/bin/env python3
import json, os, sys
args = sys.argv[1:]
if args[:2] == ['auth', 'status']:
    sys.exit(0)
if args[0] != 'api':
    sys.exit(2)
if '--method' in args:
    with open(os.environ['MERGE_MARKER'], 'w') as f:
        f.write('MERGE_ATTEMPTED')
    print(json.dumps({'merged': True, 'sha': 'b'*40}))
    sys.exit(0)
if any('/files?' in v for v in args):
    if os.environ.get('FAIL_FILES'):
        sys.exit(1)
    print(os.environ.get('CHANGED_FILES', ''))
    sys.exit(0)
if any('/compare/' in v for v in args):
    print('ahead')
    sys.exit(0)
if any('/pulls/' in v for v in args):
    labels = [dict(name=x) for x in os.environ.get('LABELS', '').split(',') if x]
    print(json.dumps({'state':'open','draft':False, 'base':{'ref':'main'},
      'head':{'repo':{'full_name':'Vivaliz-site/site-shopvivaliz'}, 'sha':'a'*40},
      'labels':labels}))
    sys.exit(0)
sys.exit(2)
'''


class OwnerOnlyMergeGuardTests(unittest.TestCase):
    def run_case(self, paths, *, labels='', fail_files=False):
        with tempfile.TemporaryDirectory() as d:
            fake_gh = Path(d) / 'gh'
            fake_gh.write_text(FAKE_GH)
            fake_gh.chmod(0o755)
            marker = Path(d) / 'attempted'
            env = dict(os.environ, PATH=f'{d}:' + os.environ['PATH'],
                       MERGE_MARKER=str(marker), CHANGED_FILES='\n'.join(paths),
                       LABELS=labels)
            if fail_files:
                env['FAIL_FILES'] = '1'
            else:
                env.pop('FAIL_FILES', None)
            proc = subprocess.run(['bash', str(SCRIPT), '123', SHA],
                                  capture_output=True, text=True, env=env, timeout=5)
            return proc, marker.exists()

    def test_unreviewed_owner_grant_must_not_be_bot_merged(self):
        for path in ['security/owner-approvals/3499.json',
                     'security/requests/controller-continuity-3498-20261102.md',
                     'docs/knowledge/security-approval-via-pr.md',
                     'scripts/validate-owner-pr-grant.py',
                     '.github/CODEOWNERS']:
            with self.subTest(path=path):
                result, attempted = self.run_case([path])
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('owner-protected', result.stderr)
                self.assertFalse(attempted)

    def test_label_hold_is_an_independent_guard(self):
        result, attempted = self.run_case(['docs/safe.md'], labels='no-auto-merge')
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(attempted)

    def test_api_failure_is_not_permission_to_merge(self):
        result, attempted = self.run_case(['docs/safe.md'], fail_files=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(attempted)

    def test_normal_green_technical_pr_can_still_merge(self):
        result, attempted = self.run_case(['docs/safe.md'])
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(attempted)


if __name__ == '__main__':
    unittest.main()
