# MCP central / Orquestrador — publicação e validação do catálogo ChatGPT

**Estado atualizado:** 2026-11-06. **Servidor e dois clientes ChatGPT com comando administrativo nativo aprovado nesta sessão; paridade total MCP/RDC ainda não certificada.**
Esta página é a referência atual para esta falha e prevalece sobre snapshots históricos
que apresentem a VM excluída como ativa ou a Marina como somente leitura.

## O que já foi corrigido

- PR técnica [#3721](https://github.com/Vivaliz-site/site-shopvivaliz/pull/3721)
  integrada à `main`; controller/backend nas portas `5580` e `5581` com
  admin grant P2 #3670 propagado e `hosts_list.admin_enabled=true` para
  `marina-a1-4c24g`.
- `tools/list` autenticado **nos dois endpoints** anuncia Marina em
  `admin_command_run`; `tools/call` para comando inofensivo
  `hostname -s; id -un` executado com `exit_code=0` na Marina.
- Adaptadores stdio Claude e Codex: 74 ferramentas anunciadas e
  `admin_command_run` Marina invocado com `exit_code=0`.
- MCP **independente** Marina por Cloudflare Access: 9 ferramentas anunciadas;
  `marina_admin_shell` executado na identidade root aprovada, sem RDC.
- Backend, Fred-Win e KOCEPSV: comandos de identificação via MCP passaram,
  e as sessões gráficas Windows foram verificadas separadamente.

## Resultado atual: bloqueio de catálogo não reproduzido

Em 06/11/2026, os esquemas de ferramentas efetivamente expostos **nesta sessão do ChatGPT** pelos aplicativos ShopVivaliz Remote Control e ShopVivaliz Remote Control - Orquestrador reconhecem `marina-a1-4c24g` em `admin_command_run`, `task_submit` e `service_action`. A VM aposentada `shopvivaliz-free-a1` não consta desses seis enums.

Testes nativos do cliente (sem SSH, RDC ou salto indireto):
- Central: `admin_command_run(host=marina-a1-4c24g, command=hostname -s; id -un, timeout=5)` => `exit_code=0`, saída `marina-a1-4c24g / ubuntu`, audit_id `9571c723-f6d6-4dce-a2f6-5bdeff286def`.
- Orquestrador: mesmo comando => `exit_code=0`, audit_id `8436a1dc-a386-4804-b600-f7f2cc89ee04`.
- `inputSchema.host.enum`: quatro hosts ativos, nenhum aposentado, nos dois apps e nas três ferramentas citadas.

**CHATGPT_CLIENT_ADMIN_COMMAND_PARITY=APTO_NESTA_SESSAO**; **PARIDADE_TOTAL_MCP_RDC=NAO_CERTIFICADA**. Não bloquear operação da Marina com base em snapshot antigo: verificar esquema real e efetuar tentativa permitida.

### Histórico da falha, preservado para regressão

Em snapshot anterior, o servidor anunciava Marina, mas o catálogo congelado do ChatGPT não aceitava o host administrativo; o Orquestrador ainda anunciava a VM extinta. Essa evidência histórica era válida naquele momento, mas deixou de se reproduzir. `hosts_list` isolado não comprova uma ferramenta; o canário nativo por aplicativo confirma a correção específica.
## Correção oficial se a regressão de catálogo reaparecer

1. Na sessão legítima do proprietário/admin do workspace, abrir a gestão de
   **Apps/Plugins** do ChatGPT e selecionar **ShopVivaliz Remote Control**.
   Utilizar a opção oficial de atualizar/rever/republicar as ações MCP que
   estiver disponível. Repetir para **ShopVivaliz Remote Control — Orquestrador**.
   Não recriar conectores, copiar credenciais ou alterar grants para tentar
   acelerar o refresh.
2. Confirmar que `admin_command_run`, `task_submit` e `service_action`
   de **cada aplicativo** incluem exatamente os destinos atualmente
   autorizados: `always-free-arm-1787907847-26`, `Fred-Win`,
   `KOCEPSV`, `marina-a1-4c24g`. Não incluir
   `shopvivaliz-free-a1`.
3. Abrir **nova sessão** do agente após a publicação. Inspecionar o
   `inputSchema` **do aplicativo**, não apenas `hosts_list`.
4. Pela ferramenta nativa oficialmente publicada, executar somente
   `hostname -s; id -un` na Marina e registrar o resultado/audit_id.
   Não usar SSH/RDC, shell indireto, API privada de administração,
   alteração de nome/host nem outro transporte para contornar recusa de
   política/plataforma. A rota independente é distinta e não serve como prova
   dessa etapa.
5. Conferir ausência de regressões em Backend, Fred-Win, KOCEPSV e o
   MCP independente. Somente então marcar
   `CHATGPT_APP_TOOL_CATALOG_PARITY=APTO`.

### Auditoria reproduzível, sem credenciais

O diagnóstico offline `scripts/ops/verify-mcp-published-client-catalog.py`
compara um arquivo JSON da resposta autenticada **do servidor** a um arquivo
JSON do catálogo **efetivamente publicado** no cliente, exportado por método
oficial autorizado. Nenhum bearer/token deve constar desses arquivos.

```bash
python3 scripts/ops/verify-mcp-published-client-catalog.py \
  --server /tmp/mcp-server-tools.json \
  --client /tmp/mcp-client-tools.json \
  --client-name shopvivaliz-remote-control
```

Repetir com `--client-name orquestrador` usando o outro catálogo.
O verificador rejeita `marina-a1-4c24g` ausente, a VM antiga presente,
tool inexistente, schema quebrado e ausência de qualquer dos dois arquivos.
Ele **não** publica alterações nem executa comandos.

**Nota de gestão de aplicativos (06/11/2026):** o catálogo já foi atualizado no cliente e testado nesta sessão, dispensando republicação manual nesta rodada. A atualização das permissões de plugins para `full_access` retornou `404 Action not found` para os três plugins solicitados; nenhuma permissão foi alterada, e a configuração global segue intacta. Este é um problema separado do catálogo MCP. O procedimento de correção futura deve usar somente a interface oficial, sem contornar recusas do provedor.
