# Marina MCP admin: acesso dos agentes (2026-11-05)

**Prevalece sobre qualquer trecho antigo que diga "MCP independente Marina inativo / NAO APTO".** O MCP do Marina esta ativo e aceita comandos admin. Confirme sempre com `preflight` e `tools` antes de agir; este texto e um snapshot.

## Evidencia (2026-11-05, rota publica `mcp-marina.shopvivaliz.com.br`, service token do Access)
- `tools`: 9 ferramentas (`marina_admin_shell`, `marina_browser_navigate`, `marina_gui_session`, `marina_host_health`, `marina_kernel_telemetry`, `marina_kernel_telemetry_batch`, `marina_processes_list`, `marina_service_status`, `marina_system_usage`).
- `marina_admin_shell` com `id -un; id -u`: `shopvivaliz-marina-mcp` / `992`, exit 0.
- `marina_admin_shell` com `as_root: true` e `id -u`: `0`, exit 0.
- Nao exercitadas nesta data: `marina_gui_session`, `marina_browser_navigate`.

## Como os agentes enxergam o MCP
1. **Servidor MCP nativo** (Claude Code): `shopvivaliz-marina-admin`, tipo `http`, em escopo de usuario. Os cabecalhos vem de um `headersHelper` local (`~/.shopvivaliz/bin/marina-mcp-headers.py`, modo 0700) que le as credenciais dos arquivos 0600 e nunca grava valor em configuracao. Registrar em outra maquina: `claude mcp add-json -s user shopvivaliz-marina-admin '{"type":"http","url":"https://mcp-marina.shopvivaliz.com.br/mcp","headersHelper":"<caminho do helper>"}'`.
2. **Cliente de linha de comando** (qualquer agente com shell): `python3 scripts/marina_mcp_agent_client.py preflight | tools | call TOOL --args '{"command":"id -un"}'`.

## Regras
- As credenciais ficam so em `~/.shopvivaliz/secrets/` (modo 0600, dono do chamador), nunca em repositorio, log, chat ou argumento de processo. Quem le esses arquivos tem root na Marina.
- Comandos admin e `as_root` sao auditados por hash no host. Use comandos minimos, de preferencia com `id`/leitura antes de mutar.
- Detalhes do grant, units e promocao de release: `marina-direct-mcp/README.md` e `docs/ops/marina-mcp-admin-validation-20261104.md`.
