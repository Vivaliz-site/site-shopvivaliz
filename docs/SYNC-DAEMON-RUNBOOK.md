# Sync daemon runbook

**Updated:** 2026-08-03  
**Policy:** reviewed commits only; no test commits, direct pushes, force pushes, or destructive resets.

## Purpose

The sync daemon may deploy only a commit that has already passed repository review and required checks. The verification script is read-only: it polls the VM and proves that a supplied immutable SHA reached the remote checkout.

## Components

- `scripts/git-auto-sync.py`: performs the configured fast-forward-only synchronization.
- `scripts/install-git-sync-cron.sh`: installs the scheduled synchronization job.
- `scripts/verify-sync-daemon.sh`: verifies an expected SHA without creating commits or pushing branches.

## Required protections

- The production checkout must use `git fetch` and `git merge --ff-only`.
- A dirty working tree must stop synchronization.
- `git reset --hard`, force push, direct push to `main`, and automatic commit creation are prohibited.
- Production data and mutable state must remain outside the deployment checkout or be ignored and backed up.
- SSH host-key verification must remain enabled.

## Verify an approved deployment

Obtain the full 40-character SHA from the merged, reviewed pull request. Do not create an empty commit for testing.

```bash
export EXPECTED_SHA="0123456789abcdef0123456789abcdef01234567"
export SSH_HOST="production-host.example"
export SSH_USER="ubuntu"
export SSH_KEY="$HOME/.ssh/shopvivaliz-production"
export REMOTE_REPO_PATH="/home/ubuntu/shopvivaliz-deploy/repo"

bash scripts/verify-sync-daemon.sh
```

Optional timing controls:

```bash
export WAIT_SECONDS=300
export POLL_SECONDS=15
```

The command exits with code `0` only after the remote repository reports exactly `EXPECTED_SHA`. It records each observation in `/tmp/sync-daemon-verify-<timestamp>.log` unless `LOG_FILE` is set.

## Install or inspect the daemon

```bash
cd /home/ubuntu/site-shopvivaliz
bash scripts/install-git-sync-cron.sh
crontab -l | grep git-auto-sync
tail -f /var/log/shopvivaliz/git-auto-sync-*.log
```

Before installation, inspect the existing service or cron entry and confirm that it points to the reviewed script version.

## Troubleshooting

### Expected SHA never appears

1. Check the synchronization logs.
2. Confirm the production working tree is clean.
3. Confirm the remote branch contains the reviewed SHA.
4. Confirm the daemon uses fast-forward-only synchronization.
5. Inspect network and credential failures without changing repository history.

### SSH verification fails

1. Confirm `SSH_HOST`, `SSH_USER`, and `SSH_KEY` are correct.
2. Confirm the key file exists and has restricted permissions.
3. Confirm the host is present in `known_hosts`; do not disable strict host-key checking.
4. Confirm `REMOTE_REPO_PATH` points to the production checkout.

### Production working tree is dirty

Do not reset or discard files automatically. Identify the owner of each change, preserve evidence, move mutable data outside the checkout, and resolve through a reviewed operational procedure.

## Evidence requirements

A deployment verification is valid only when it contains:

- reviewed pull request and merge SHA;
- required check results;
- expected SHA;
- observed production SHA;
- UTC timestamps;
- command exit code and retained log.

No queue item, agent task, deployment, or audit may be marked successful without those concrete artifacts.
