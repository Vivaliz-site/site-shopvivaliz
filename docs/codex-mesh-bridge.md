# Codex Mesh Bridge

This repo now exposes a small MCP bridge for Codex-to-Codex coordination.

## What it does

- Stores messages in `storage/codex-bridge/messages.jsonl`
- Exposes MCP tools:
  - `post_message`
  - `read_messages`
  - `bridge_status`
- Works from any machine that has the same repo and the same `.codex/config.toml`
- Uses stdio MCP only; it does not open an HTTP port

## How to use

- Keep the repo synced on both machines.
- Start Codex normally.
- Use the `codex_bridge` MCP server.
- Use `post_message` to send a task update or handoff.
- Use `read_messages` to consume updates from another machine.

## Notes

- This is a repo-backed mailbox, so git sync is the transport between machines.
- The bridge now requires `initialize` before `tools/list` or `tools/call`.
- The legacy HTTP server in `scripts/mcp-server.py` is not the recommended path for Codex MCP usage.
- It is intentionally simple and auditable.
