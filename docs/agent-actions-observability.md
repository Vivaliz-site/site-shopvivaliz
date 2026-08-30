# GitHub Actions observability for all agents

This repository has a connector limitation: some agents can read files and known workflow jobs, but cannot discover GitHub Actions run IDs through the ChatGPT GitHub connector.

The permanent repository-level workaround is to expose workflow run discovery through versioned files that every agent can read.

## Canonical files

- `.github/workflows/actions-run-index.yml` builds the index.
- `ops/actions-run-index-request.json` requests which workflow to index.
- `ops/actions-run-index.json` is the generated read model containing run IDs, statuses, conclusions, commit SHAs, timestamps, and GitHub run URLs.
- `ops/fredwin-last-result.json` is the generated read model for Fred-Win private relay health.

## Required agent workflow

When an agent needs GitHub Actions run discovery and the connector cannot list runs directly:

1. Update `ops/actions-run-index-request.json` with the target workflow filename and limit.
2. Commit to `main`.
3. Read `ops/actions-run-index.json`.
4. Use the returned `runs[].id` with available connector actions such as `fetch_workflow_run_jobs`, `fetch_workflow_job_steps`, `fetch_workflow_job_logs`, or `fetch_workflow_run_artifacts`.

Agents must not claim that run discovery is unavailable until this index path has been attempted.

## Request example

```json
{
  "workflow": "fred-win-remote-action.yml",
  "limit": 10,
  "requested_at": "2026-08-18T23:05:00Z",
  "reason": "Expose GitHub Actions runs through a versioned file readable by the connector"
}
```

## Generated index shape

```json
{
  "recorded_at": "2026-08-18T23:06:04Z",
  "repository": "Vivaliz-site/site-shopvivaliz",
  "queried_workflow": "fred-win-remote-action.yml",
  "total_count": 57,
  "runs": [
    {
      "id": 32195299588,
      "name": "Fred-Win Remote Action",
      "event": "push",
      "status": "completed",
      "conclusion": "success",
      "head_branch": "main",
      "head_sha": "60b23527082b36c69d4a8c5c867502bc5034b8c1",
      "created_at": "2026-08-18T23:00:30Z",
      "updated_at": "2026-08-18T23:00:59Z",
      "html_url": "https://github.com/Vivaliz-site/site-shopvivaliz/actions/runs/32195299588"
    }
  ]
}
```

## Fred-Win private relay rule

Fred-Win must be accessed only through the private relay architecture:

`GitHub Actions -> backend A1 144.22.157.209 via SSH -> VM 127.0.0.1:5557 -> reverse SSH tunnel -> Fred-Win 127.0.0.1:5557 -> MCP`

Do not validate Fred-Win by `https://rce-shopvivaliz.trycloudflare.com`; that endpoint is historical and not canonical.

## Success criteria

The limitation is considered removed for repository operations when:

- `ops/actions-run-index.json` contains the needed run ID and conclusion.
- The target workflow can be followed by run ID through connector job/log/artifact actions.
- `ops/fredwin-last-result.json` confirms Fred-Win health when the task depends on Fred-Win.

## Maintenance

If additional agents need other workflow metadata, extend `actions-run-index.yml` to add fields to `ops/actions-run-index.json` instead of asking each agent to call blocked Actions list endpoints directly.
