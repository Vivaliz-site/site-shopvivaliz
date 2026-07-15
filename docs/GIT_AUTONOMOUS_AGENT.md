# Git Autonomous Agent

This repository now includes a local Git guard layer for safer work across multiple machines and ongoing deploy automation.

## Covered roles

- Git Guardian: branch state, upstream sync, detached-head detection, non-fast-forward push blocking
- Merge Resolver: auto-resolves only trivial conflict blocks and validates the resulting file
- Workspace Sync: fetch + optional `pull --rebase` with a backup branch
- Code Review: blocks secrets, merge markers, and newly staged forbidden paths
- Deploy Guardian: local preflight checks before relying on GitHub Actions deploy

## Install

```bash
python scripts/git_autonomous_agent.py install-hooks
```

## Common commands

```bash
python scripts/git_autonomous_agent.py status
python scripts/git_autonomous_agent.py workspace-sync
python scripts/git_autonomous_agent.py workspace-sync --apply --autostash
python scripts/git_autonomous_agent.py review
python scripts/git_autonomous_agent.py resolve-conflicts
python scripts/git_autonomous_agent.py deploy-guardian
```

## Current repo-specific notes

- This repository already has remote deploy automation in `.github/workflows/deploy.yml`.
- The local agent is intentionally conservative: it protects Git flow and validates files, but it does not auto-push or auto-deploy on your behalf.
- `pre-push` blocks detached-head pushes, which is especially useful here because this repo can end up in detached state during merges or ad-hoc checkouts.
- Team rule: always run `pull --rebase` before starting work on either PC, preferably through `python scripts/git_autonomous_agent.py workspace-sync`.
