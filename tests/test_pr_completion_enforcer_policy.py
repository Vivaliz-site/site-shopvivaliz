from pathlib import Path

WORKFLOW = Path('.github/workflows/pr-completion-enforcer.yml').read_text(encoding='utf-8')
RESOLVER = Path('.github/workflows/ai-conflict-resolver.yml').read_text(encoding='utf-8')


def test_enforcer_uses_only_latest_run_per_required_workflow():
    assert "latest_required_runs" in WORKFLOW
    assert "historical failures ignored after newer success" in WORKFLOW


def test_enforcer_requests_branch_repair_instead_of_only_labeling():
    assert "workflow_dispatch" in WORKFLOW
    assert "repair-pr-branch" in WORKFLOW


def test_draft_prs_are_not_silently_ignored():
    assert "draft == false" not in WORKFLOW
    assert "draft_policy" in WORKFLOW


def test_conflict_resolver_can_run_for_stale_pr_repair():
    assert "workflow_dispatch" in RESOLVER
    assert "repair-pr-branch" in RESOLVER
