# Fred-Win remote access repair — 2026-08-07

## Root cause confirmed

- The public hostname `rce-shopvivaliz.trycloudflare.com` is not reachable from GitHub-hosted runners.
- Oracle VM `137.131.156.17` is reachable with the existing GitHub Actions secret `SHOPVIVALIZ_VM_SSH_KEY`.
- The existing Windows -> Oracle reverse SSH tunnel is alive on VM loopback port `2222`.
- The VM cannot reach `100.71.51.106:5557` directly (Tailscale/private address path unavailable from VM).
- Connecting to VM `127.0.0.1:2222` reaches the reverse-forward listener, but the forwarded Windows SSH endpoint closes the connection; therefore this path is not suitable as the control plane.
- No active self-hosted GitHub runner was available for the repository at audit time.

## Repair implemented

The new design removes the public RCE dependency entirely:

`ChatGPT -> GitHub Actions -> Oracle VM SSH -> VM 127.0.0.1:5557 -> reverse SSH -> Fred-Win 127.0.0.1:5557 -> MCP`

### Files added

- `scripts/ssh-tunnel-service-managed.ps1`
  - keeps `2222 -> Windows:22` for diagnostics;
  - adds `5557 -> Windows:5557` specifically for the Fred-Win MCP;
  - reconnects automatically;
  - does not contain private key material.

- `scripts/fredwin-remote-bootstrap.ps1`
  - ensures MCP is running only on `127.0.0.1:5557`;
  - starts the repo-managed reverse tunnel;
  - replaces only legacy ShopVivaliz tunnel processes;
  - keeps the MCP off the public internet.

- `scripts/local-auto-sync.ps1`
  - restores the documented Windows scheduled-task entry point;
  - performs safe fast-forward sync only;
  - invokes the Fred-Win bootstrap after syncing.

- `.github/workflows/fred-win-remote-action.yml`
  - provides an audited relay through the Oracle VM;
  - accepts only allowlisted browser actions, not arbitrary commands from request files;
  - supports health, Exchange Admin, Microsoft 365, Google Ads, Merchant Center, Search Console, GA4, Tag Manager and opening the complete login bundle.

- `ops/fredwin-request.json`
  - current requested action for the audited relay.

## Activation behavior

The existing documentation states that Fred-Win/local Windows has a Task Scheduler auto-sync at `C:\site-shopvivaliz\scripts\local-auto-sync.ps1`. Once that scheduled sync obtains these commits, the bootstrap should start the local MCP and restart the reverse tunnel with port 5557 included.

After activation, the health request must pass from GitHub Actions through the VM at:

`http://127.0.0.1:5557/health`

Only after that health check is green should browser actions be sent.

## Security decision

Do **not** expose `mcp-server.py` / `execute_command` directly through a public unauthenticated Cloudflare hostname. The repaired route keeps the MCP loopback-only on Fred-Win and loopback-only on the Oracle VM, with access gated by the existing GitHub Actions SSH secret to the VM.

## Current status at time of commit

- VM SSH: READY
- Reverse SSH listener 2222: OPEN
- Fred-Win private MCP forward 5557: waiting for Windows scheduled auto-sync/bootstrap
- Public `trycloudflare.com` RCE route: abandoned for control use
- First `Fred-Win Remote Action` health run: expected failure before bootstrap activation
