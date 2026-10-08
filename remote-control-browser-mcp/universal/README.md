# Universal Browser MCP (isolated)

This adapter provides 14 tools for public-site browser interaction through a separate Chromium/Playwright profile. It does **not** reuse Atendimento (9556), Dev (9559), or the graphical fredconsole profile. The universal browser runtime is hosted on backend `always-free-arm-1787907847-26`, not on production web or Windows hosts.

## Runtime

- `universal-browser.mjs`: bounded browser action executor, one process per request, isolated persistent profile and logical tabs file.
- `mcp-server.mjs`: token-authenticated loopback HTTP MCP endpoint at `127.0.0.1:5595/mcp` (override via `SHOPVIVALIZ_BROWSER_UNIVERSAL_PORT`).
- Parent `remote-control-browser-mcp/server.py`: advertises and forwards the 14 tools to local adapter, sanitizes audit fields. The adapter token must be supplied by existing `SHOPVIVALIZ_REMOTE_MCP_TOKEN` secret environment; do not save credentials in repository.
- Install dependencies using `npm ci --omit=dev` in the `universal/` folder with Node >=20, and deploy this folder as a whole. Chrome path may be provided by `SHOPVIVALIZ_BROWSER_UNIVERSAL_BINARY`.
- The isolated service runs as the unprivileged `ubuntu` user, with home/private profile owned by that user. Install systemd unit with `Restart=on-failure`, `EnvironmentFile=/var/lib/shopvivaliz-remote-control/service.env`, `ExecStart=/usr/local/bin/node /path/to/universal/mcp-server.mjs`, and `MemoryMax=1200M`. The parent service must be redeployed separately.
- Never expose either service to a public interface: both bind loopback. Ensure the remote-control host transport authenticates every caller.
- **Installer caution:** Production currently runs an independently deployed copy of the parent `server.py`, which diverged from the repository before this work. Review the diff against the deployed file before installing; do not deploy an older repo copy blindly.

## Operating contract and limitations

- `tabs_*` are **logical, persisted bookmarks**, not live CDP tabs. Switching reopens the saved URL; unsaved JavaScript/UI state is lost.
- `upload` only reads a named file in `upload-staging/`; `download` saves into `downloads/`, both below the isolated runtime folder. No arbitrary path arguments.
- Private/loopback addresses are rejected when resolving URLs and HTTP(S) resource requests. Additional DNS rebinding, proxy, IPv6 and cross-origin security testing is required before treating this as a complete SSRF defense.
- Browser clicks and form submits can cause real-world effects on third-party sites. Require explicit task-specific authorization for consequential financial or irreversible actions.
- No CAPTCHA bypass or credential extraction. Do not log passwords, text entered into forms, cookies or authentication headers.
- Source launcher still creates a browser process for each action. The profile is persistent but live pages are not. Further work is needed for stateful multi-page automation.
- Download file results are local to the host and do not automatically appear as ChatGPT attachments.

## Validation (backend VM)

```sh
node --check universal-browser.mjs
node --check mcp-server.mjs
printf '{}' | node universal-browser.mjs probe
```

Expected probe fields: `ok:true`, `title:"Clicked"`, `selected:"b"`, `checked:true`, `uploadFile:true`, `downloadBytes:5`.

Verify `GET http://127.0.0.1:5595/health`, `tools/list` via authenticated MCP, parent port `5581` exposes 14 `browser_universal_*` tools, and both systemd services are active after restarting *services* (no host reboot during business-critical tasks). Do not put bearer token into command output.

## Known remaining tasks

1. Expose newly advertised tools through ChatGPT connector catalog refresh/reconnection. Updating MCP `tools/list` on the server alone does not refresh this conversation's tool list.
2. Stateful tab management and action chaining within the same page, with recovery.
3. Test strict DNS rebinding, cross-origin redirects, symlink uploads, download limits and file path confinement.
4. Add deploy automation with rollback and E2E CI tests.
5. Validate full host reboot only in scheduled maintenance window with user approval.
