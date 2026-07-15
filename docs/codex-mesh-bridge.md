# Codex Mesh Bridge

This repo now exposes a small MCP bridge for Codex-to-Codex coordination.

## What it does

- Stores messages in `storage/codex-bridge/messages.jsonl`
- Exposes MCP tools:
  - `post_message`
  - `read_messages`
  - `bridge_status`
- Works from any machine that has the same repo and the same `.codex/config.toml`

## How to use

- Keep the repo synced on both machines.
- Start Codex normally.
- Use the `codex_bridge` MCP server.
- Use `post_message` to send a task update or handoff.
- Use `read_messages` to consume updates from another machine.

## Notes

- This is a repo-backed mailbox, so git sync is the transport between machines.
- It is intentionally simple and auditable.
