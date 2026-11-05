# Marina MCP: rotação de service token (Cloudflare Access)

Rota pública: `https://mcp-marina.shopvivaliz.com.br/mcp`. Aplicação Access: `961c1049-6e70-46ae-bcfa-7827fbdf7553`.
Não registrar valores de Client ID, Client secret ou tokens em documentos, PRs ou logs.

## Como a autenticação funciona

1. A borda (Cloudflare Access) valida o par `CF-Access-Client-Id` / `CF-Access-Client-Secret` pela regra **Service Auth**
   da aplicação e encaminha a requisição com `Cf-Access-Jwt-Assertion`.
2. A origem (`marina-direct-mcp/server.py`, `--access-config`) valida o JWT e aceita o service token só se o
   `common_name` (o Client ID `<id>.access`) estiver em `allowed_service_tokens` de `cloudflare-access.json`.

Essas duas listas são independentes. Trocar o token na borda **sem** atualizar a allowlist da origem produz:

- sintoma: smoke retorna `EDGE_OR_ACCESS_FAILURE`, HTTP 401 com corpo `{"error":"unauthorized"}` (sem `Www-Authenticate`);
- sem credencial: 401 com `Www-Authenticate` (desafio OAuth do Access). Se o corpo for o desafio, é a borda; se for o JSON, é a origem.

## Procedimento de rotação

1. **Criar** o novo service token no Cloudflare One (Access > Service credentials). Guardar o secret só no cofre local
   do operador (`~/.shopvivaliz/secrets`, modo `0600`).
2. **Adicionar** o novo token à regra **Service Auth** da aplicação (não alterar a regra Allow de owner consent).
   Verificar que a regra lista os dois tokens antes da próxima etapa.
3. **Atualizar a allowlist da origem** na Marina (sudo como usuário do serviço, backup antes, escrita atômica, `0600`):
   - incluir o Client ID do token novo em `allowed_service_tokens`;
   - manter o token antigo até a validação completa.
   - reiniciar `shopvivaliz-marina-mcp.service` e confirmar `active` com `NRestarts=0`.
4. **Validar** pela rota pública, a partir do backend:
   - `python3 scripts/marina_mcp_edge_smoke.py` → `status=OK`, exit 0;
   - `python3 scripts/marina_mcp_agent_client.py call marina_host_health` → `isError: false`;
   - controle negativo sem credencial → 401 com `Www-Authenticate`;
   - controle negativo com credencial fictícia → 401 emitido pela borda (HTML "Cloudflare Access").
5. **Desativar** o token antigo (Disable). Observar pelo menos um ciclo do smoke horário (`shopvivaliz-marina-edge-smoke.timer`).
6. **Excluir** o token antigo. A exclusão só é aceita depois de removê-lo da regra Service Auth; excluir antes
   retorna "remove all references to it".
7. **Remover** o Client ID antigo da allowlist da origem, reiniciar o serviço e repetir o passo 4.

## Smoke horário na backend

`scripts/install-marina-edge-smoke-timer.sh` instala as unidades do timer. Como o release da backend pode não conter
`scripts/marina_mcp_edge_smoke.py`, use `SHOPVIVALIZ_SMOKE_DIR=<checkout verificado>`: o instalador grava um drop-in que
aponta o `ExecStart` para esse caminho. Testes: `tests/test_install_marina_edge_smoke_timer.py`.

## Evidência de referência (2026-11-05)

- Causa do 401 observada: Client ID antigo na allowlist; o novo token aparecia como Allowed na borda.
- Após a correção: smoke OK; `marina_host_health` com `isError: false`; controles negativos 401 conforme acima.
- A certificação APTO é decisão exclusiva do certificador; este documento registra só procedimento e evidência.
