# CI Remediation — 2026-07-25

## Security scanner

The Agent Dual Validation workflow was failing because it scanned the entire repository and surfaced legacy or fixture-like matches unrelated to the pull request under validation.

## Remediation

- Scan only changed `.js`, `.ts`, and `.php` files for pull requests and pushes.
- Fetch full history so the workflow can compare the current revision with the event base SHA.
- Ignore dependency, fixture, scratch, documentation, and example paths.
- Keep the workflow blocking when a matching assignment is introduced in an actionable changed source file.
- Preserve the existing non-blocking repository-wide advisory scan in self-validation.

## Scope

No application, Liz, deployment, Apache, database, or production behavior is changed by this remediation.
