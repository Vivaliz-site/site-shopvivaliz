# Matriz de rotas de acesso aos quatro hosts (2026-11-05)

**Meta do proprietario:** os quatro hosts (backend, Marina, Fred-Win, KOCEPSV) devem ter **MCP administrativo**, **interface grafica via SSH funcionando** e **RDC online**. Este arquivo lista **todas** as rotas conhecidas por host, para nenhum agente declarar um host inacessivel depois de testar so uma delas. Estado = evidencia datada; confirmar ao vivo antes de agir (`list_devices`, `preflight`, `hostname`). Detalhes e historico: `host-access.md`, `current-topology-and-access-2026-10-29.md`, `marina-mcp-admin-agent-access.md`.

Hosts: `marina-a1-4c24g` (OCI ESPOSA, `10.80.1.116`, Tailscale `100.118.170.46`), `always-free-arm-1787907847-26` (OCI PRINCIPAL, `10.0.1.38`, Tailscale `100.66.174.74`), `Fred-Win` = `LAPTOP-NIG4IFUU`, `KOCEPSV` = `DESKTOP-KOCEPSV`. `shopvivaliz-free-a1` foi excluida: nao e rota.

## Rotas por host

| Rota | Marina | Backend | Fred-Win | KOCEPSV |
|---|---|---|---|---|
| **MCP administrativo** | `shopvivaliz-marina-mcp` em `https://mcp-marina.shopvivaliz.com.br/mcp` (Cloudflare Access, service token). Ferramentas `marina_admin_shell` (`as_root` auditado), `marina_gui_session`, `marina_host_health`... Cliente: `scripts/marina_mcp_agent_client.py`. **OK em 2026-11-05** (`marina_host_health` isError=false). | Remote Control MCP (controller, `127.0.0.1:5580`, loopback): `admin_command_run host="always-free-arm-1787907847-26"`, `task_submit`, `desktop_*`. Browser MCP em `5581`. | Via Remote Control do backend (`hosts_list`), reverse SSH `127.0.0.1:2222 -> Windows:22`. Sem MCP admin proprio verificado. | Via Remote Control do backend, `127.0.0.1:2223 -> Windows:22`; **nao comprovado**. |
| **RDC (Desktop Commander)** | online (2026-11-05); `start_process` com `deviceId` = ID do `list_devices` pelo nome | agente offline desde ~2026-10-27; reautenticacao por device flow | online (2026-11-05), PowerShell | offline desde ~2026-10-17 |
| **SSH privado** | `ubuntu` em `10.80.1.116` / Tailscale; a partir do runner da Marina | `shopvivaliz-agent` (restrito) ou `ubuntu` em `10.0.1.38` / Tailscale; a partir do runner da Marina (`ubuntu@10.0.1.38`) | `sshd` ativo; reverse SSH `2222` | reverse SSH `2223` (a provar) |
| **GUI / SSH grafico** | `marina_gui_session` start/status/stop, Xvfb `:99` sob demanda (running em 2026-11-05); RustDesk ativo | Xvfb isolado `:97` (`shopvivaliz-gui-xvfb.service`, cookie `gui-xvfb.xauth`); browsers corporativos dev/atendimento em `:99`/CDP 9559/9556; RustDesk | bridge nativo `shopvivaliz-native-desktop-bridge.ps1` (reverse SSH), RustDesk ativo | `desktop_*` opera o cliente RustDesk na sessao `fredconsole` da backend |
| **GitHub Actions (runner self-hosted)** | runner `marina-a1-deploy-canonical`, label `shopvivaliz-a1-deploy` | runners `shopvivaliz-backend-browser` e outros; workflows de recovery (`backend-control-plane-recovery`, `backend-continuity-direct-recovery`) | `fred-win-desktop-commander-action.yml` | `desktopkocepsv-desktop-commander-action.yml`, `kocepsv-private-peer-probe.yml` |
| **OCI** (bootstrap/recovery) | conta ESPOSA: Run Command, Bastion, console serial | conta PRINCIPAL (perfil `AGENTS`): idem; `backend-oci-agent-readonly-diagnostic.yml` | OCI de contingencia | nao se aplica |
| **Cloudflare** | tunnel `shopvivaliz-oci-marina-admin` (admin e MCP) | nao se aplica | nao se aplica | nao se aplica |
| **RustDesk self-hosted** | `rustdesk.service` ativo | GUI da backend | ativo | cliente na `fredconsole` da backend |

## Ordem de tentativa (nao e restricao nova; so organiza o esgotamento de rotas)
1. MCP administrativo do proprio host (Marina) ou Remote Control do backend. 2. RDC online do host. 3. Workflow GitHub Actions no runner da Marina (probes sanitizados `desktop-commander-three-host-quick-probe`, `kocepsv-private-peer-probe`) alcanca o backend por SSH privado. 4. SSH privado/Tailscale (`shopvivaliz-agent`). 5. OCI Bastion/Run Command/console serial e Actions de recovery. 6. RustDesk para GUI.

## Notas
- **Aliases SSH do laptop do proprietario** (`shopvivaliz-a1-backend[-raw]`, `shopvivaliz-a1-marina[-raw]`) sao pessoais; automacoes nao os usam.
- **Credenciais do MCP da Marina** (`MARINA_MCP_ACCESS_CLIENT_ID/SECRET`) ficam em `~/.shopvivaliz/secrets/` (0600) na Marina e no backend; sessoes na nuvem so as usam se o MCP for registrado em escopo de conta ou o cliente rodar num host que as tenha. Service token vence em 2026-12-02.
- Para cada tentativa, registrar em checkpoint `remote_access_attempts`: canal, resultado, causa e proximo fallback.
