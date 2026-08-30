from pathlib import Path

WORKFLOW = Path('.github/workflows/pr-completion-enforcer.yml').read_text(encoding='utf-8')
REPAIR = Path('.github/workflows/ai-stale-pr-repair.yml').read_text(encoding='utf-8')


def test_enforcer_uses_only_latest_run_per_required_workflow():
    assert "latest_required_runs" in WORKFLOW
    assert "historical failures ignored after newer success" in WORKFLOW


def test_enforcer_requests_branch_repair_instead_of_only_labeling():
    assert "workflow_dispatch" in WORKFLOW
    assert "repair-pr-branch" in WORKFLOW
    assert "ai-stale-pr-repair.yml" in WORKFLOW


def test_draft_prs_are_not_silently_ignored():
    assert "draft == false" not in WORKFLOW
    assert "draft_policy" in WORKFLOW


def test_stale_repair_uses_free_local_ai_and_git_data_api():
    assert "workflow_dispatch" in REPAIR
    assert "qwen2.5-coder:1.5b" in REPAIR
    assert "ollama" in REPAIR.lower()
    assert "github.rest.git.createCommit" in REPAIR
    assert "github.rest.git.updateRef" in REPAIR
    assert "git push" not in REPAIR
