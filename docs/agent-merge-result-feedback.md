# Agent merge result feedback

This repository requires every agent merge, direct-to-main change, or attempted merge to publish machine-readable feedback for other autonomous agents.

## Purpose

Agents must be able to detect whether work was completed, stayed pending, or needs automatic correction without relying on chat history, a human comment, or unavailable connector endpoints.

## Required output

Every merge-related agent run must produce or update a JSON result using this schema:

```json
{
  "schema": "shopvivaliz.agent_merge_result.v1",
  "recorded_at": "2026-08-18T23:58:00Z",
  "agent": "chatgpt|claude|gemini|github-actions|other",
  "repository": "Vivaliz-site/site-shopvivaliz",
  "status": "MERGED|DIRECT_TO_MAIN|FIXED_THEN_MERGED|PENDING_AGENT_FIX|NOT_MERGED",
  "pr": null,
  "branch": "main",
  "head_sha": "",
  "merge_commit_sha": "",
  "checks": [
    {
      "name": "",
      "run_id": "",
      "job_id": "",
      "status": "completed|queued|in_progress",
      "conclusion": "success|failure|cancelled|skipped|null",
      "url": ""
    }
  ],
  "pending": false,
  "pending_reason": "",
  "auto_fix_required": false,
  "auto_fix_action": "none|inspect_logs|fix_code|rerun_checks|resolve_conflict|request_human",
  "next_agent_instruction": "",
  "evidence": {
    "run_index_file": "ops/actions-run-index.json",
    "logs": [],
    "artifacts": []
  }
}
```

## Canonical locations

- Latest aggregate result: `ops/agent-merge-result.json`
- Historical per-run results: `ops/agent-merge-results/<timestamp-or-run-id>.json`
- Actions discovery: `ops/actions-run-index.json`

## Status semantics

- `MERGED`: PR merged successfully; no pending fix.
- `DIRECT_TO_MAIN`: change committed directly to `main`; no pending fix.
- `FIXED_THEN_MERGED`: an error appeared, the agent fixed it, validations passed, and work was merged.
- `PENDING_AGENT_FIX`: actionable failure remains; the next autonomous agent must inspect and fix it before merge.
- `NOT_MERGED`: blocked by non-agent condition such as missing approval, branch protection, unavailable credential, or human product decision.

## Required behavior when pending

If `pending=true` and `auto_fix_required=true`, the next agent must not start unrelated work. It must:

1. Read `ops/agent-merge-result.json`.
2. Read any referenced per-run result.
3. Read `ops/actions-run-index.json` if checks or run IDs are needed.
4. Fetch logs/artifacts using the known run IDs or job IDs.
5. Apply the smallest safe correction.
6. Re-run validation.
7. Merge when safe.
8. Publish a new merge result.

## Required behavior after success

After a successful merge or direct-to-main update, agents must publish `pending=false`, `auto_fix_required=false`, and include the final commit SHA and validation evidence.

## Prohibited behavior

Agents must not leave a PR/check pending without publishing this feedback. A chat response alone is not enough.

Agents must not set `NOT_MERGED` for actionable failures that the agent can inspect and fix.

Agents must not hide failing checks by omitting them from the result.
