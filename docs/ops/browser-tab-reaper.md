# Browser tab reaper

Policy (owner, 2026-11-05): agent browsers must not stay open abandoned.

`scripts/browser_tab_reaper.py` closes abandoned tabs in the backend agent browsers
(`dev` 9559, `atendimento` 9556, `dev-aux` 9557). It is a **dry run by default**.

## When a tab is closed

All of these must hold:

- it is a normal page (not `chrome://`, devtools, an extension, or a login page on
  `auth.openai.com`, `accounts.google.com`, `login.microsoftonline.com`, `appleid.apple.com`);
- its ChatGPT conversation is **not** bound (`bind-conversation`) to a task whose status is
  `RUNNING` or `READY_TO_COMPLETE` in the agent task-state directory;
- its URL and title have not changed for `--idle-hours` (default 24), counted from the
  reaper's own first observation. CDP exposes no last-activity time, so a tab seen for the
  first time always starts a new clock and cannot be closed on that run;
- the browser keeps at least `--keep-min` tabs (default 1), and at most `--max-closes`
  (default 5) tabs are closed per browser per run.

Fail closed: if the task-state directory is missing or contains an unreadable file, no
ChatGPT tab is closed and the run exits 3.

The report prints host names and counts only. It never prints URLs, titles or conversation
identifiers. Only loopback CDP endpoints are used. The state file
(`~/.local/state/shopvivaliz/browser-tab-reaper.json`) is mode 0600.

## What it does not do

It does not stop browser processes or services, restart anything, or touch the Docker
workloads of other projects. Profiles that should not exist at all are a separate decision.

## Install (manual, not done by merging this file)

```bash
sudo install -m 644 deploy/systemd/shopvivaliz-browser-tab-reaper.service /etc/systemd/system/
sudo install -m 644 deploy/systemd/shopvivaliz-browser-tab-reaper.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now shopvivaliz-browser-tab-reaper.timer
```

Review dry-run output first:

```bash
journalctl -u shopvivaliz-browser-tab-reaper.service -n 20 --no-pager -o cat
```

## Enable closing, and roll back

```bash
echo 'SHOPVIVALIZ_TAB_REAPER_APPLY=1' | sudo tee /etc/default/shopvivaliz-browser-tab-reaper
# back to dry run:
sudo rm /etc/default/shopvivaliz-browser-tab-reaper
# remove entirely:
sudo systemctl disable --now shopvivaliz-browser-tab-reaper.timer
```

Only the value `1` enables closing. Closed ChatGPT tabs can be reopened from the server-side
conversation history; this tool never deletes a conversation.
