# Codex Bridge Enabled

- Date: 2026-07-13
- Scope: Codex MCP bridge in `.codex/config.toml`

## What Changed

- Added `codex_bridge` as an enabled MCP server using `codex mcp-server`.
- Kept the bridge low-risk by leaving approval mode at `prompt`.

## Verification

- `codex mcp list` shows `codex_bridge` as `enabled`.
- `toml-ok` validation passed for `.codex/config.toml`.

## Note

- This enables a shared Codex MCP bridge locally.
- For direct machine-to-machine transport, the other machine still needs network reachability or the same bridge config plus a remote transport/tunnel.
