#!/usr/bin/env python3
"""Replay one original owner-review failure after real, exact-HEAD approval.

Run from the finalizer's trusted main checkout. Never edit a branch, submit a
review, synthesize check results, grant privileges, or merge a PR. The original
GitHub job reruns all its existing gates. A run can be recovered only once.
"""
from __future__ import annotations

import argparse
from datetime import datetime
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))
from scripts.quality.verify_owner_protected_pr_review import OWNER, SHA40, decide, is_protected_path

REPOSITORY = 'Vivaliz-site/site-shopvivaliz'
WORKFLOW = '.github/workflows/mandatory-validation-gate.yml'
OWNER_STEP = 'Enforce exact human APPROVED review on owner-protected PR heads'
DENIAL = re.compile(
    r'^(?:\ufeff)?(?:\d{4}-\d\d-\d\dT[0-9:.]+Z )?'
    r'OWNER_REVIEW_GATE=DENIED reason='
    r'(owner_approval_missing|latest_owner_review_not_approved|approval_not_bound_to_current_head)$',
    re.MULTILINE,
)


def positive(value):
    return type(value) is int and value > 0


def pr_matches(pr, repo, head):
    return (isinstance(pr, dict) and pr.get('state') == 'open'
            and pr.get('draft') is False and positive(pr.get('number'))
            and pr.get('base', {}).get('ref') == 'main'
            and pr.get('head', {}).get('sha') == head
            and pr.get('head', {}).get('repo', {}).get('full_name') == repo)


def resolve_target(prs, repo, head):
    """Review-triggered workflow_run events may omit their PR association."""
    if (repo != REPOSITORY or not SHA40.fullmatch(head or '')
            or not isinstance(prs, list) or len(prs) >= 100):
        return None
    matches = [p['number'] for p in prs if pr_matches(p, repo, head)]
    return matches[0] if len(matches) == 1 else None


def approval(pr, files, reviews, repo, head):
    if repo != REPOSITORY or not pr_matches(pr, repo, head):
        return None
    labels = pr.get('labels')
    if not isinstance(labels, list) or any(not isinstance(x, dict) for x in labels):
        return None
    if {x.get('name') for x in labels} & {'blocked-by-runtime-lease', 'blocked-external'}:
        return None
    # no-auto-merge remains a merge hold. This helper never merges anything.
    allowed, reason = decide(files, reviews, head)
    if not allowed or reason != 'owner_review_approved_exact_head':
        return None
    owner_reviews = [r for r in reviews if r['user'].get('login', '').casefold() == OWNER
                     and r.get('state') in ('APPROVED', 'DISMISSED', 'CHANGES_REQUESTED')]
    stamp = max(owner_reviews, key=lambda r: r['submitted_at'])['submitted_at']
    return datetime.fromisoformat(stamp.replace('Z', '+00:00'))


def original_failure(run, repo, number, head, branch, associated_pr=None):
    return (isinstance(run, dict) and positive(run.get('id'))
            and run.get('path') == WORKFLOW and run.get('event') == 'pull_request'
            and run.get('status') == 'completed' and run.get('conclusion') == 'failure'
            and type(run.get('run_attempt')) is int and run['run_attempt'] == 1
            and run.get('head_sha') == head and run.get('head_branch') == branch
            and run.get('head_repository', {}).get('full_name') == repo
            and isinstance(run.get('pull_requests'), list)
            and (any(p.get('number') == number and p.get('head', {}).get('sha') == head
                     for p in run['pull_requests'] if isinstance(p, dict))
                 or (run['pull_requests'] == [] and associated_pr == number)))


def page(payload, key):
    if not isinstance(payload, dict) or not isinstance(payload.get(key), list):
        raise ValueError('invalid_page')
    items = payload[key]
    total = payload.get('total_count')
    if type(total) is not int or total != len(items) or total >= 100:
        raise ValueError('incomplete_page')
    return items


def github_api(path, method='GET', raw=False):
    # Existing workflow token carries only its declared permissions. No secret
    # reads, alternate identity, shell interpolation, or raw error output.
    command = ['gh', 'api', '--method', method, path]
    proc = subprocess.run(command, capture_output=True, text=True, check=True, timeout=40)
    if len(proc.stdout) > 4_000_000:
        raise ValueError('response_too_large')
    return proc.stdout if raw or method == 'POST' else json.loads(proc.stdout)


def recover(repo, number, api=github_api):
    if repo != REPOSITORY or not positive(number):
        raise ValueError('invalid_target')
    prefix = f'repos/{repo}'
    pr_path = f'{prefix}/pulls/{number}'
    pr = api(pr_path)
    head = pr.get('head', {}).get('sha', '') if isinstance(pr, dict) else ''
    if not SHA40.fullmatch(head) or not pr_matches(pr, repo, head):
        return 0
    files = api(pr_path + '/files?per_page=100')
    if not isinstance(files, list) or not 0 < len(files) < 100:
        return 0
    if any(not isinstance(f, dict) or not isinstance(f.get('filename'), str) for f in files):
        return 0
    if not any(is_protected_path(f['filename']) for f in files):
        return 0
    reviews = api(pr_path + '/reviews?per_page=100')
    approved_at = approval(pr, files, reviews, repo, head)
    if approved_at is None:
        return 0
    branch = pr['head']['ref']
    runs = page(api(f'{prefix}/actions/runs?head_sha={head}&per_page=100'), 'workflow_runs')
    # Only the latest original PR-event run can still require recovery. A
    # newer success/pending run already supersedes older failures in that lane.
    originals = [r for r in runs if isinstance(r, dict) and positive(r.get('id'))
                 and r.get('path') == WORKFLOW and r.get('event') == 'pull_request'
                 and r.get('head_sha') == head and r.get('head_branch') == branch]
    if not originals:
        return 0
    for run in [max(originals, key=lambda r: r['id'])]:
        associated_pr = None
        if run.get('pull_requests') == []:
            # GitHub also omits this association on original PR runs. Require
            # an independent, unambiguous exact-HEAD association instead.
            associated_pr = resolve_target(
                api(f'{prefix}/commits/{head}/pulls?per_page=100'), repo, head)
        if not original_failure(run, repo, number, head, branch, associated_pr):
            continue
        run_path = f'{prefix}/actions/runs/{run["id"]}'
        jobs = page(api(run_path + '/jobs?per_page=100'), 'jobs')
        for job in jobs:
            if (not isinstance(job, dict) or not positive(job.get('id'))
                    or job.get('name') != 'validation-gate'
                    or job.get('run_id') != run['id'] or job.get('run_attempt') != 1
                    or job.get('head_sha') != head or job.get('status') != 'completed'
                    or job.get('conclusion') != 'failure'):
                continue
            steps = job.get('steps')
            if not isinstance(steps, list) or any(not isinstance(s, dict) for s in steps):
                continue
            failures = [s for s in steps if s.get('conclusion') == 'failure']
            if len(failures) != 1 or failures[0].get('name') != OWNER_STEP:
                continue
            ended = datetime.fromisoformat(job['completed_at'].replace('Z', '+00:00'))
            if ended >= approved_at:
                continue
            check = api(f'{prefix}/check-runs/{job["id"]}')
            if (not isinstance(check, dict) or check.get('id') != job['id']
                    or check.get('head_sha') != head or check.get('app', {}).get('id') != 15368):
                continue
            log = api(f'{prefix}/actions/jobs/{job["id"]}/logs', raw=True)
            if not isinstance(log, str) or not DENIAL.search(log.replace('\r\n', '\n')):
                continue
            # Recheck just before the only mutation. Rerun is bounded, native,
            # and cannot make a stale HEAD or dismissed approval pass its gate.
            current = api(pr_path)
            current_reviews = api(pr_path + '/reviews?per_page=100')
            if approval(current, files, current_reviews, repo, head) != approved_at:
                return 0
            current_run = api(run_path)
            if current_run.get('pull_requests') == []:
                associated_pr = resolve_target(
                    api(f'{prefix}/commits/{head}/pulls?per_page=100'), repo, head)
            if not original_failure(current_run, repo, number, head, branch, associated_pr):
                return 0
            api(f'{prefix}/actions/jobs/{job["id"]}/rerun', method='POST')
            print(f'owner_review_recovery_pr={number} job={job["id"]} head={head} state=replayed')
            return 1
    return 0


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--lookup-head')
    mode.add_argument('--pr', type=int)
    args = parser.parse_args(argv)
    try:
        if args.lookup_head is not None:
            number = resolve_target(json.load(sys.stdin), args.repo, args.lookup_head)
            if number is not None:
                print(number)
        else:
            count = recover(args.repo, args.pr)
            print(f'owner_review_recovery_count={count}')
        return 0
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError) as exc:
        # Preserve the real failure without printing API bodies or credentials.
        print('owner_review_recovery=deferred error=' + type(exc).__name__, file=sys.stderr)
        return 3


if __name__ == '__main__':
    raise SystemExit(main())
