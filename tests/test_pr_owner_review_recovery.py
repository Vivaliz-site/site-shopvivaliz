"""Regression of #3560: repair the original failed job, never its approved HEAD."""
import copy
import importlib
import importlib.util
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.find_spec('scripts.pr_owner_review_recovery')
MODULE = importlib.import_module('scripts.pr_owner_review_recovery') if SPEC else None
REPO = 'Vivaliz-site/site-shopvivaliz'
HEAD = 'a' * 40
PR = {'number': 3560, 'state': 'open', 'draft': False,
      'head': {'sha': HEAD, 'ref': 'security/example', 'repo': {'full_name': REPO}},
      'base': {'ref': 'main'}, 'labels': [{'name': 'no-auto-merge'}]}
FILES = [{'filename': 'includes/pricing-sync-3560.php'}]
REVIEWS = [{'user': {'login': 'shopvivaliz-human'}, 'state': 'APPROVED',
            'commit_id': HEAD, 'submitted_at': '2026-11-02T23:19:13Z'}]
RUN = {'id': 100, 'run_attempt': 1, 'name': 'Mandatory Validation Gate',
       'path': '.github/workflows/mandatory-validation-gate.yml',
       'event': 'pull_request', 'status': 'completed', 'conclusion': 'failure',
       'head_sha': HEAD, 'head_branch': 'security/example',
       'head_repository': {'full_name': REPO},
       'pull_requests': [{'number': 3560, 'head': {'sha': HEAD}}]}
JOB = {'id': 101, 'run_id': 100, 'run_attempt': 1, 'head_sha': HEAD,
       'name': 'validation-gate', 'status': 'completed', 'conclusion': 'failure',
       'completed_at': '2026-11-02T23:16:44Z',
       'steps': [{'name': 'Set up job', 'status': 'completed', 'conclusion': 'success'},
                 {'name': 'Enforce exact human APPROVED review on owner-protected PR heads',
                  'status': 'completed', 'conclusion': 'failure'}]}
CHECK = {'id': 101, 'head_sha': HEAD, 'app': {'id': 15368}}
LOG = '2026-11-02T23:16:44Z OWNER_REVIEW_GATE=DENIED reason=owner_approval_missing\n'


class FakeAPI:
    """Only the external GitHub boundary is replaced; policy remains production code."""
    def __init__(self):
        self.pr, self.files, self.reviews = map(copy.deepcopy, (PR, FILES, REVIEWS))
        self.run, self.job, self.check = map(copy.deepcopy, (RUN, JOB, CHECK))
        self.log = LOG
        self.extra_runs = []
        self.associations = [copy.deepcopy(PR)]
        self.writes = []
        self.pr_reads = 0
        self.move_on_recheck = False
        self.dismiss_on_recheck = False
        self.fail_read = False

    def __call__(self, path, method='GET', raw=False):
        if self.fail_read:
            raise subprocess.CalledProcessError(1, 'gh', stderr='DO_NOT_PRINT_SECRET')
        if method == 'POST':
            self.writes.append(path)
            self.run['status'] = 'queued'
            self.run['run_attempt'] = 2
            return ''
        if path == f'repos/{REPO}/pulls/3560':
            self.pr_reads += 1
            if self.move_on_recheck and self.pr_reads > 1:
                self.pr['head']['sha'] = 'b' * 40
            if self.dismiss_on_recheck and self.pr_reads > 1:
                self.reviews[0]['state'] = 'DISMISSED'
            return copy.deepcopy(self.pr)
        if path.endswith('/pulls?per_page=100') and '/commits/' in path:
            return copy.deepcopy(self.associations)
        if path.endswith('/files?per_page=100'):
            return copy.deepcopy(self.files)
        if path.endswith('/reviews?per_page=100'):
            return copy.deepcopy(self.reviews)
        if '/actions/runs?head_sha=' in path:
            return {'total_count': 1 + len(self.extra_runs), 'workflow_runs': copy.deepcopy([self.run] + self.extra_runs)}
        if path.endswith('/actions/runs/100/jobs?per_page=100'):
            return {'total_count': 1, 'jobs': [copy.deepcopy(self.job)]}
        if path.endswith('/actions/runs/100'):
            return copy.deepcopy(self.run)
        if path.endswith('/check-runs/101'):
            return copy.deepcopy(self.check)
        if path.endswith('/actions/jobs/101/logs') and raw:
            return self.log
        raise AssertionError('Unexpected GitHub request: ' + path)


class OwnerReviewRecoveryTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(MODULE, 'Missing original-job recovery in finalizer')
        self.api = FakeAPI()

    def recover(self):
        return MODULE.recover(REPO, 3560, self.api)

    def test_original_failed_job_is_replayed_after_exact_owner_approval(self):
        self.assertEqual(self.recover(), 1)
        self.assertEqual(self.api.writes, [f'repos/{REPO}/actions/jobs/101/rerun'])
        self.assertEqual(self.api.pr['head']['sha'], HEAD)

    def test_second_sweep_never_replays_pending_or_second_attempt(self):
        self.recover()
        self.assertEqual(self.recover(), 0)
        self.assertEqual(len(self.api.writes), 1)
        self.api.run.update(status='completed', conclusion='failure')
        self.assertEqual(self.recover(), 0)
        self.assertEqual(len(self.api.writes), 1)

    def test_newer_success_or_pending_original_run_suppresses_old_failure(self):
        for status, conclusion in [('completed', 'success'), ('in_progress', None)]:
            self.api = FakeAPI()
            newer = copy.deepcopy(RUN)
            newer.update(id=102, status=status, conclusion=conclusion)
            self.api.extra_runs = [newer]
            self.assertEqual(self.recover(), 0)
            self.assertEqual(self.api.writes, [])

    def test_real_github_empty_run_prs_uses_independent_commit_association(self):
        # GitHub run 41544120846 returned pull_requests=[] even for PR events.
        self.api.run['pull_requests'] = []
        self.assertEqual(self.recover(), 1)
        self.assertEqual(self.api.writes, [f'repos/{REPO}/actions/jobs/101/rerun'])

    def test_empty_run_prs_without_unambiguous_commit_association_never_replays(self):
        for associated in [[], [PR, dict(PR, number=3561)]]:
            self.api = FakeAPI()
            self.api.run['pull_requests'] = []
            self.api.associations = copy.deepcopy(associated)
            self.assertEqual(self.recover(), 0)
            self.assertEqual(self.api.writes, [])

    def test_explicit_mismatched_run_pr_is_never_overridden_by_lookup(self):
        self.api.run['pull_requests'][0]['number'] = 9999
        self.assertEqual(self.recover(), 0)
        self.assertEqual(self.api.writes, [])

    def test_invalid_or_stale_owner_approval_never_mutates(self):
        for key, value in [('state', 'DISMISSED'), ('state', 'CHANGES_REQUESTED'),
                           ('commit_id', 'b' * 40), ('user', {'login': 'fredmourao-ai'})]:
            with self.subTest(key=key, value=value):
                self.api = FakeAPI()
                self.api.reviews[0][key] = value
                self.assertEqual(self.recover(), 0)
                self.assertEqual(self.api.writes, [])

    def test_runtime_or_external_hold_prevents_recovery(self):
        for hold in ['blocked-by-runtime-lease', 'blocked-external']:
            self.api = FakeAPI()
            self.api.pr['labels'].append({'name': hold})
            self.assertEqual(self.recover(), 0)
            self.assertEqual(self.api.writes, [])

    def test_unprotected_draft_closed_or_fork_pr_is_not_targeted(self):
        for kind in ['unprotected', 'draft', 'closed', 'fork']:
            self.api = FakeAPI()
            if kind == 'unprotected':
                self.api.files = [{'filename': 'README.md'}]
            elif kind == 'draft':
                self.api.pr['draft'] = True
            elif kind == 'closed':
                self.api.pr['state'] = 'closed'
            else:
                self.api.pr['head']['repo']['full_name'] = 'outside/fork'
            self.assertEqual(self.recover(), 0, kind)
            self.assertEqual(self.api.writes, [])

    def test_wrong_workflow_event_head_or_attempt_is_not_replayed(self):
        for key, value in [('path', '.github/workflows/other.yml'), ('event', 'pull_request_review'),
                           ('head_sha', 'b' * 40), ('status', 'in_progress'),
                           ('conclusion', 'success'), ('run_attempt', 2), ('pull_requests', None)]:
            self.api = FakeAPI()
            self.api.run[key] = value
            self.assertEqual(self.recover(), 0, key)
            self.assertEqual(self.api.writes, [])

    def test_other_failure_step_does_not_trigger_blind_retry(self):
        self.api.job['steps'][1]['name'] = 'Validate repository change'
        self.assertEqual(self.recover(), 0)
        self.assertEqual(self.api.writes, [])

    def test_nonofficial_app_or_wrong_check_head_is_not_replayed(self):
        for key, value in [('app', {'id': 1}), ('head_sha', 'b' * 40)]:
            self.api = FakeAPI()
            self.api.check[key] = value
            self.assertEqual(self.recover(), 0)
            self.assertEqual(self.api.writes, [])

    def test_api_error_reason_or_echoed_shell_text_is_not_approval_failure(self):
        for log in ['OWNER_REVIEW_GATE=DENIED reason=github_verification_unavailable\n',
                    "echo 'OWNER_REVIEW_GATE=DENIED reason=owner_approval_missing'\n"]:
            self.api = FakeAPI()
            self.api.log = log
            self.assertEqual(self.recover(), 0)
            self.assertEqual(self.api.writes, [])

    def test_failure_after_approval_is_not_replayed(self):
        self.api.job['completed_at'] = '2026-11-02T23:20:00Z'
        self.assertEqual(self.recover(), 0)
        self.assertEqual(self.api.writes, [])

    def test_head_race_or_dismissal_before_post_fails_closed(self):
        for attr in ['move_on_recheck', 'dismiss_on_recheck']:
            self.api = FakeAPI()
            setattr(self.api, attr, True)
            self.assertEqual(self.recover(), 0)
            self.assertEqual(self.api.writes, [])

    def test_api_failure_is_not_silently_reported_as_repaired(self):
        self.api.fail_read = True
        with self.assertRaises(subprocess.CalledProcessError):
            self.recover()
        self.assertEqual(self.api.writes, [])

    def test_lookup_uses_exact_current_pr_head_and_rejects_ambiguity(self):
        self.assertEqual(MODULE.resolve_target([PR], REPO, HEAD), 3560)
        self.assertIsNone(MODULE.resolve_target([PR], REPO, 'b' * 40))
        duplicate = copy.deepcopy(PR)
        duplicate['number'] = 3561
        self.assertIsNone(MODULE.resolve_target([PR, duplicate], REPO, HEAD))
        self.assertIsNone(MODULE.resolve_target([PR] * 100, REPO, HEAD))
        self.assertIsNone(MODULE.resolve_target(None, REPO, HEAD))


if __name__ == '__main__':
    unittest.main()
