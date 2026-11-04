# Marina MCP: validação administrativa pela rota pública (2026-11-04)

**Status:** evidências para revisão do certifier. **Este documento não declara APTO.** O veredito é exclusivo do certifier absoluto.

## Escopo validado

Rota pública `https://mcp-marina.shopvivaliz.com.br/mcp`, autenticada pelo service token do Cloudflare Access (política Service Auth do app MCP), a partir da VM backend `always-free-arm-1787907847-26`, com o cliente `scripts/marina_mcp_agent_client.py`.

## Resultados

| Etapa | Comando / ferramenta | Resultado |
|---|---|---|
| Credenciais | `preflight` | `ready: true` (somente metadados; arquivos 0600 do usuário de serviço) |
| Descoberta | `tools` | 9 ferramentas: `marina_admin_shell`, `marina_browser_navigate`, `marina_gui_session`, `marina_host_health`, `marina_kernel_telemetry`, `marina_kernel_telemetry_batch`, `marina_processes_list`, `marina_service_status`, `marina_system_usage` |
| 1. Identidade | `marina_admin_shell`: `id -un; id -u` | `shopvivaliz-marina-mcp`, uid 992, exit 0 |
| 2. Root via sudo | `marina_admin_shell` com `as_root: true`: `id -u` | uid 0, exit 0 |
| 3. Host | `marina_admin_shell`: `echo ok-$(hostname -s)` | `ok-marina-a1-4c24g`, exit 0 |
| 4. Browser | `marina_browser_navigate`: `http://127.0.0.1:5580/health` | `ok: true`, screenshot gerado |
| 5. GUI | `marina_gui_session`: `status` | `ok: true`, `running: false` (sem sessão iniciada) |

Smoke de borda (somente leitura, `scripts/marina_mcp_edge_smoke.py`): `status=OK`, 4 ferramentas esperadas presentes, 9 servidas.

## Auditoria

Os registros de auditoria do host (`/var/lib/shopvivaliz-marina-mcp/state/admin-shell-audit.log` e `gui-browser-audit.log`) guardam somente hashes SHA-256 dos comandos e das URLs, nunca o texto. Hashes conferidos:

- identidade (`id -un; id -u`): `9ea5aa4035b0b9db…`, `start` e `end` com `exit_code 0`;
- `as_root` (`id -u`): `380e0b75466acd32…`, `start` e `end` com `exit_code 0`;
- hostname: `97495072855a6db3…`, `start` e `end` com `exit_code 0`;
- URL do browser: `7596eff028a1eacc…`, `browser_start` e `browser_end` com `outcome: ok`;
- GUI: evento `gui_session` com `action: status`.

Cada chamada tem entrada `start` seguida de `end`. Nenhum segredo aparece nos logs.

## Correções feitas durante a validação

- **Bloqueio de borda por User-Agent:** o hostname público respondia `403` ao User-Agent padrão do `urllib` antes da checagem de credenciais, enquanto o mesmo token funcionava com curl. O cliente passou a enviar um User-Agent identificado. Teste de regressão: 7 testes falham sem a correção e passam com ela. PR #3635, mergeada (`da9bbb4d`).
- **Cliente em `origin/main`:** a PR #3634 foi mergeada por squash; a correção foi reaplicada sobre o `origin/main` real.

## Controles negativos

- Sem `marina_admin_shell` no grant, a ferramenta não é anunciada (`test_marina_jwt_gated_tools`).
- Com User-Agent do `urllib`, o stub responde `403` e o smoke reporta `EDGE_OR_ACCESS_FAILURE`.

## Itens em aberto (impedem APTO até resolvidos ou aceitos pelo certifier)

1. **Nome do servidor:** o `initialize` responde `shopvivaliz-marina-direct-readonly`, embora o build servido tenha as ferramentas administrativas. Pendente de correção e de confirmação do build servido em relação ao `main`.
2. **Escopo do grant:** o grant aceita `sudo NOPASSWD: ALL`, shell root e browser sem restrição de destino. Riscos aceitos pelo proprietário, registrados no grant; não são defeito de implementação, mas o certifier deve considerá-los.
3. **Expiração do service token:** vence em 2026-12-02. Rotação pendente.
4. **Monitoramento contínuo:** o smoke de borda existe, mas não está agendado. Decisão de agendamento pendente.

## Não validado

- Execução do build em produção além das cinco chamadas acima.
- Certificação independente: nenhum certifier revisou esta evidência até a data deste documento.
