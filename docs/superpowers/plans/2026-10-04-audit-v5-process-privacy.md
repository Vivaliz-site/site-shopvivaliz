# Process privacy remediation implementation plan

> For agentic workers: use superpowers:executing-plans for this bounded task.

Goal: stop collecting process arguments in the Remote Control inventory, preserve useful metadata and validate the deployed behavior.
Architecture: isolate the previously reviewed process-privacy patch on current main; do not touch certifier or global policy blobs. Preserve existing authenticated transports.
Tech stack: Python 3, unittest, procps, systemd, GitHub checks.
Spec: remote-control-mcp/SPEC.md (Security Model and durable tasks).

## Global constraints
One repository at a time. No secret disclosure, authentication bypass, active release edits, gate weakening or force merge. R3 stays preserved in the original audit worktree. R4 remains blocked; do not retry it through another route. No browser account switching. Tests use synthetic values only.

## Review focus
- Linux metadata must not include args/command/commandline: exact fields and child sentinel tests.
- Windows inventory remains metadata-only: existing contract test.
- Supported sensitive flags are redacted: synthetic quoted and equals-form tests.
- Benign flags remain usable: unchanged-output assertion.
- Real process output follows the selected fields: test and post-install metadata-only probe; never print old argv output.

## Task 1: Privacy patch
Files: remote-control-mcp/server.py, tests/remote-control-mcp-test.py.
Interface: processes_command(platform) -> command string; redact_text(text) -> sanitized text. No interface or credential changes.
- [x] Preserve original audit worktree and collect pending task result.
- [x] Clean main baseline: python3 tests/remote-control-mcp-test.py -q (165 PASS).
- [x] Transplant only four privacy regression tests; expect seven assertion failures before the production change.
- [x] Apply reviewed server.py privacy diff; run the full MCP suite (169 PASS expected).
- [ ] Run repository-governance-validate.sh using a durable bounded task with sufficient budget; check every exit code.
- [ ] Verify exact reviewed production diff identity; perform fresh review of integrated diff if needed.
- [ ] Commit, PR, required checks, protected merge, canonical controller installer and runtime regression.

## Execution rulings
- The stale staging-unit test is already fixed on main; do not copy its unrelated older hunk.
- Full tests took more than300s previously. Use a900s durable budget and retain per-command logs; do not weaken tests.
- General GUI still returns a black screenshot; do not count API or structural health as browser E2E.
- No overall APTO is issued by this bounded patch.

## Review follow-up
Fresh independent review166e3477 found no narrow blockers but identified Bearer interaction. Regraded Important: reproduced3 failures and moved CLI scrubbing after existing secret patterns. Final MCP suite170 PASS; benign tokenizer argument and Authorization protection preserved. No certifier/policy changes. Final CI must validate this post-review snapshot.
