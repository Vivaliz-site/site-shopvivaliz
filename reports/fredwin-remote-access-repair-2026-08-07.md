# Fred-Win remote access / tunnel — persistent operator context

> **DOCUMENTO HISTÓRICO COMPLEMENTAR.** A norma operacional atual e obrigatória está em [`docs/FRED-WIN-PRIVATE-RELAY.md`](../docs/FRED-WIN-PRIVATE-RELAY.md). Antes de declarar o Fred-Win inativo, execute a ação allowlisted `health` pela rota privada canônica. Sem esse teste, o resultado é `INCONCLUSIVO`. O endpoint `rce-shopvivaliz.trycloudflare.com` nunca deve ser usado para determinar o estado atual.

> IMPORTANT FOR FUTURE CHATGPT SESSIONS: this remote path already exists and has been validated. Do not ask the user to rediscover or recreate the tunnel before checking this document, `docs/FRED-WIN-PRIVATE-RELAY.md`, and the current workflow status.

## Working architecture

`ChatGPT -> GitHub connector -> GitHub Actions -> Oracle VM SSH -> VM 127.0.0.1:5557 -> reverse SSH -> Fred-Win 127.0.0.1:5557 -> MCP`

The Fred-Win Windows machine keeps a managed reverse SSH tunnel to Oracle VM `137.131.156.17` using user `ubuntu`.

Managed reverse forwards:

- VM `127.0.0.1:2222` -> Fred-Win `127.0.0.1:22` (diagnostics/SSH)
- VM `127.0.0.1:5557` -> Fred-Win `127.0.0.1:5557` (MCP control plane)

Local MCP health endpoint on Fred-Win:

`http://127.0.0.1:5557/health`

Expected health response includes `status=ok`, `environment=fred-win`, `mcp_version=1.0.0`.

## Important files

- `scripts/ssh-tunnel-service-managed.ps1` — persistent reverse tunnel, automatic reconnect, forwards 2222 and 5557.
- `scripts/fredwin-remote-bootstrap.ps1` — ensures MCP is listening on loopback 5557 and starts/reuses the managed tunnel.
- `scripts/local-auto-sync.ps1` — scheduled Windows repo sync/bootstrap entry point.
- `scripts/mcp-server.py` — Fred-Win MCP server.
- `.github/workflows/fred-win-remote-action.yml` — audited GitHub -> VM -> Fred-Win relay with allowlisted actions.
- `ops/fredwin-request.json` — action request consumed by the workflow.
- `docs/FRED-WIN-PRIVATE-RELAY.md` — canonical status and diagnostic protocol.
- Windows logs: `C:\site-shopvivaliz\logs\fredwin-remote-bootstrap.log` and `C:\site-shopvivaliz\logs\fredwin-managed-tunnel.log`.

Windows repo path: `C:\site-shopvivaliz`.

## Validated activation

On 2026-08-07 the following was confirmed operational:

1. Fred-Win MCP answered locally on `127.0.0.1:5557`.
2. Managed tunnel process was running and forwarding `2222->127.0.0.1:22` and `5557->127.0.0.1:5557`.
3. GitHub Actions successfully reached `http://127.0.0.1:5557/health` through Oracle VM and the reverse tunnel.
4. Remote allowlisted Windows actions executed successfully through this route.
5. Opening browser pages from the background MCP required an interactive-session launcher; the workflow was extended with Task Scheduler-based interactive actions such as `open_email_login_pair`.
6. Exchange Admin was successfully opened on Fred-Win and the user authenticated there.

Therefore, in a new chat, first inspect `docs/FRED-WIN-PRIVATE-RELAY.md`, `.github/workflows/fred-win-remote-action.yml`, `ops/fredwin-request.json`, this document, and recent workflow runs. Treat the tunnel as an existing capability unless a health check through the canonical route proves it is currently down.

## Browser/session limitation

A plain `Start-Process` from the MCP can run in a non-interactive Windows session and may not display a browser window. Browser-opening actions that must appear on Fred-Win should use the interactive Task Scheduler launcher pattern already implemented in the workflow. Authentication secrets, Windows Hello PINs, passwords and MFA codes must never be stored in the repo or relay.

## Historical endpoints / paths

- `http://100.71.51.106:5557` was a private/local path mentioned during diagnosis, but the Oracle VM could not use that Tailscale/private route directly.
- `https://rce-shopvivaliz.trycloudflare.com` was tested historically but was abandoned as the control plane because GitHub-hosted runners could not reliably reach it and exposing MCP/RCE publicly is undesirable.

Do not use the public Cloudflare RCE hostname for health, status or access. Do not replace the working private relay with it.

## GitHub authentication

The Oracle VM SSH private key is not stored in repository text. GitHub Actions uses the existing secret `SHOPVIVALIZ_VM_SSH_KEY`.

## Email-project context related to Fred-Win

The email project is at `C:\mei-mg-email`. Production email must use Microsoft Graph/Exchange, not Gmail SMTP or Microsoft SMTP. The approved sender is `naoresponda@dev.shopvivaliz.com.br`, with the approved Contabilidade Melo HTML template. SMTP fallbacks are being removed specifically to prevent accidental Gmail/SMTP test sends.

## Fast diagnostic sequence for future sessions

1. Read `docs/FRED-WIN-PRIVATE-RELAY.md` and this document.
2. Inspect recent runs of `.github/workflows/fred-win-remote-action.yml`.
3. Run/request the allowlisted `health` action.
4. If health passes, classify `COMPROVADO / ATIVO` and use the existing relay immediately; do not ask the user to recreate the tunnel.
5. If health cannot be executed or evidence cannot be read, classify `INCONCLUSIVO`, not `INATIVO`.
6. If health fails, inspect the bootstrap/tunnel logs and managed processes before changing architecture or classifying `FALHOU / INATIVO`.

## Security decision

Do **not** expose `mcp-server.py` / `execute_command` directly through a public unauthenticated hostname. Keep MCP loopback-only on Fred-Win and loopback-only on the Oracle VM, gated by the GitHub Actions SSH secret and allowlisted relay actions.
