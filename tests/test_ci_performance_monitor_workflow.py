from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "ci-performance-monitor.yml"

if not WORKFLOW.is_file():
    raise SystemExit("CI performance monitor workflow is missing")

text = WORKFLOW.read_text(encoding="utf-8")
required = [
    "name: CI Performance Monitor",
    "schedule:",
    "cron: '17 10 * * *'",
    "workflow_dispatch:",
    "contents: read",
    "actions: read",
    "issues: write",
    "runs-on: ubuntu-latest",
    "timeout-minutes: 10",
    "group: ci-performance-monitor",
    "cancel-in-progress: true",
    "scripts/ci_performance_fetch.py",
    "--repository \"$GITHUB_REPOSITORY\"",
    "--window-hours 24",
    "--slice-minutes 60",
    "scripts/ci_performance_monitor.py",
    "--window-hours 24",
    "--min-samples 3",
    "--avg-seconds-threshold 150",
    "--failure-rate-threshold 0.20",
    "artifacts/ci-performance-24h.json",
    "artifacts/ci-performance-24h.md",
    "GITHUB_STEP_SUMMARY",
    "uses: actions/upload-artifact@v7",
    "retention-days: 1",
    "<!-- ci-performance-monitor -->",
    "[CI Performance] Regression monitor",
    "issuesAndPullRequests",
    "issues.create",
    "issues.update",
    "state: 'closed'",
]
missing = [fragment for fragment in required if fragment not in text]
if missing:
    raise SystemExit("CI performance monitor workflow contract missing: " + ", ".join(missing))

if "gh api " in text:
    raise SystemExit("workflow YAML must delegate Actions fetching to the bounded fetch helper")
if "self-hosted" in text or "shopvivaliz-a1-deploy" in text or "shopvivaliz-backend-browser" in text:
    raise SystemExit("CI performance monitor must never reserve a self-hosted runner")
if "sleep " in text:
    raise SystemExit("CI performance monitor must not poll or sleep")
fetch_step = text.split("- name: Fetch complete workflow-run window", 1)[1].split("- name: Analyze CI performance", 1)[0]
if "/jobs?per_page=" in fetch_step or "actions/runs/" in fetch_step:
    raise SystemExit("workflow YAML must not issue per-run API requests")
if "retention-days: 30" in text or "retention-days: 90" in text:
    raise SystemExit("CI performance artifact retention must match the repository's 1-day policy")

print("ci performance monitor workflow contract: PASS")
