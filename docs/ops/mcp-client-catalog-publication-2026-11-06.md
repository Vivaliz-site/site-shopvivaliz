# MCP central / Orquestrador — publicação e validação do catálogo ChatGPT

**Estado observado:** 2026-11-06. **Servidor operacional; catálogo ChatGPT ainda não certificado.**
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

## A inconsistência que permanece

O aplicativo **ShopVivaliz Remote Control** e o **Orquestrador ShopVivaliz**
retornam `hosts_list` já atualizado, mas a **definição publicada** das
ferramentas `admin_command_run`, `task_submit` e `service_action` ainda
não contém `marina-a1-4c24g` no enum do argumento `host`.

Pior: o catálogo antigo do Orquestrador ainda contém
`shopvivaliz-free-a1`, uma VM definitivamente excluída. O servidor
continua rejeitando esse destino; sua presença no catálogo não autoriza nem
justifica qualquer tentativa de acesso.

**Não confundir:**
1. Servidor responde 200 / grant válido = saúde do transporte e autorização
   em runtime, **não** prova que o agente conhece a ferramenta.
2. `hosts_list` anuncia Marina = informação do servidor, **não** prova que
   o inputSchema congelado do cliente aceite Marina.
3. Um cliente só está **APTO** quando a ferramenta oficial aparece no cliente,
   o argumento `host` admite a Marina, nenhuma VM excluída consta da allowlist,
   e um comando de identificação é realmente executado por aquela ferramenta.

## Correção do catálogo publicado — fluxo oficial

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

**Limite da automação nesta sessão:** os conectores disponíveis de gestão de
apps permitem consultar/alterar permissões, mas não expõem operação para
republicar o catálogo de tools desses dois apps. A interação gráfica com as
configurações foi recusada pela plataforma; nenhuma rota alternativa foi
usada para contornar essa recusa. Este passo requer o mecanismo de
administração **oficial** do workspace.

Evidências e retomada:
[issue #3152](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3152),
[checkpoint de E2E dos clientes](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3152#issuecomment-6677266585).
