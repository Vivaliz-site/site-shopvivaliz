"""Run the real finalizer shell with a local fake for the GitHub API boundary."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest
from tests.test_pr_owner_review_recovery import FakeAPI, REPO

ROOT = Path(__file__).resolve().parents[1]


class RecoveryWorkflowTests(unittest.TestCase):
    def run_finalizer(self, api):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'trusted').symlink_to(ROOT, target_is_directory=True)
            state = root / 'api.json'
            state.write_text(json.dumps(api.__dict__))
            responses = root / 'responses'
            responses.mkdir()
            for name, value in {
                'pr': api.pr, 'files': api.files, 'reviews': api.reviews,
                'runs': {'total_count': 1, 'workflow_runs': [api.run]},
                'jobs': {'total_count': 1, 'jobs': [api.job]},
                'run': api.run, 'check': api.check,
            }.items():
                (responses / name).write_text(json.dumps(value))
            (responses / 'log').write_text(api.log)
            fake_bin = root / 'bin'
            fake_bin.mkdir()
            gh = fake_bin / 'gh'
            gh.write_text(textwrap.dedent('''\
                #!/bin/sh
                test "$1" = api || exit 80
                shift
                method=GET
                if [ "$1" = --method ]; then method="$2"; shift 2; fi
                path="$1"
                if [ "$method" = POST ]; then
                  test "$path" = repos/Vivaliz-site/site-shopvivaliz/actions/jobs/101/rerun || exit 81
                  printf '%s\\n' "$path" >> "$FAKE_GH_RESPONSES/writes"
                  exit 0
                fi
                case "$path" in
                  */pulls/3560) file=pr ;;
                  */files?per_page=100) file=files ;;
                  */reviews?per_page=100) file=reviews ;;
                  */actions/runs?head_sha=*) file=runs ;;
                  */actions/runs/100/jobs?per_page=100) file=jobs ;;
                  */actions/runs/100) file=run ;;
                  */check-runs/101) file=check ;;
                  */actions/jobs/101/logs) file=log ;;
                  *) echo "Unexpected fake API route" >&2; exit 82 ;;
                esac
                cat "$FAKE_GH_RESPONSES/$file"
                '''))
            gh.chmod(0o755)
            workflow = (ROOT / '.github/workflows/pr-completion-enforcer.yml').read_text()
            step = workflow.split('      - name: Repair and finalize triggering PR or scheduled sweep\n', 1)[1]
            code = textwrap.dedent(step.split('        run: |\n', 1)[1].split('\n      - name:', 1)[0])
            env = dict(os.environ, PATH=str(fake_bin)+os.pathsep+os.environ['PATH'],
                       PYTHONPATH=str(ROOT), FAKE_GH_RESPONSES=str(responses), REPO=REPO,
                       TARGET_PR='3560', GITHUB_EVENT_NAME='workflow_run',
                       GITHUB_OUTPUT=str(root / 'output'))
            result = subprocess.run(['bash', '-c', code], cwd=root, env=env,
                                    capture_output=True, text=True, timeout=20)
            snapshot = json.loads(state.read_text())
            writes = responses / 'writes'
            snapshot['writes'] = writes.read_text().splitlines() if writes.exists() else []
            return result, snapshot

    def test_protected_manual_merge_hold_still_recovers_resolved_check(self):
        result, state = self.run_finalizer(FakeAPI())
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(state['writes'], [f'repos/{REPO}/actions/jobs/101/rerun'])
        self.assertIn('operator_blocked_by_label=true', result.stdout)
        self.assertNotIn('merged_via_oracle=true', result.stdout)

    def test_runtime_hold_never_replays_or_merges(self):
        api = FakeAPI()
        api.pr['labels'].append({'name': 'blocked-by-runtime-lease'})
        result, state = self.run_finalizer(api)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(state['writes'], [])

    def test_no_human_approval_never_replays_or_merges(self):
        api = FakeAPI()
        api.reviews = []
        result, state = self.run_finalizer(api)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(state['writes'], [])


class NativeQueueHandoffTests(unittest.TestCase):
    """Exercise the exact trusted workflow guard, never fabricate a merge."""
    def run_guard(self, payload, api_failure=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'rules.json').write_text(json.dumps(payload))
            gh = root / 'gh'
            gh.write_text('#!/bin/sh\n'
                          'test "$1" = api || exit 80\n'
                          'test "$2" = repos/Vivaliz-site/site-shopvivaliz/rules/branches/main || exit 81\n'
                          'test "$RULES_FAIL" = 0 || exit 82\n'
                          'cat "$RULES_FILE"\n')
            gh.chmod(0o755)
            text = (ROOT / '.github/workflows/pr-completion-enforcer.yml').read_text()
            start = '            # BEGIN NATIVE QUEUE HANDOFF\n'
            end = '            # END NATIVE QUEUE HANDOFF\n'
            # Before the fix the finalizer falls through into the direct merge.
            block = text.split(start, 1)[1].split(end, 1)[0] if start in text else ''
            block = textwrap.dedent(block)
            code = ('set -Eeuo pipefail\nwaiting_count=0\nmerged_count=0\n'
                    'for pr in 3563; do\n' + block +
                    '\necho would_attempt_direct_merge=true\n'
                    'done\necho waiting_count=$waiting_count\necho merged_count=$merged_count\n')
            env = dict(os.environ, PATH=str(root)+os.pathsep+os.environ['PATH'],
                       RULES_FILE=str(root / 'rules.json'), RULES_FAIL='1' if api_failure else '0',
                       REPO=REPO)
            return subprocess.run(['bash', '-c', code], env=env, text=True,
                                  capture_output=True, timeout=10)

    def test_native_queue_is_waiting_not_direct_merge_or_false_merged(self):
        result = self.run_guard([{'type': 'pull_request'}, {'type': 'merge_queue'}])
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('pr_3563_native_queue_handoff=true', result.stdout)
        self.assertIn('waiting_count=1', result.stdout)
        self.assertIn('merged_count=0', result.stdout)
        self.assertNotIn('would_attempt_direct_merge', result.stdout)

    def test_nonqueue_repository_retains_existing_merge_route(self):
        result = self.run_guard([{'type': 'pull_request'}])
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('would_attempt_direct_merge=true', result.stdout)
        self.assertIn('waiting_count=0', result.stdout)

    def test_rules_api_failure_never_falls_through_to_merge(self):
        result = self.run_guard([], api_failure=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('would_attempt_direct_merge', result.stdout)

    def test_missing_or_malformed_rules_never_fall_through_to_merge(self):
        for payload in [None, {}, [None], ['merge_queue'], [{'type': None}]]:
            with self.subTest(payload=payload):
                result = self.run_guard(payload)
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn('would_attempt_direct_merge', result.stdout)


if __name__ == '__main__':
    unittest.main()
