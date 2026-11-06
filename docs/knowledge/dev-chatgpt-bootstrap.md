<!-- MCP_CLIENT_CATALOG_PARITY_20261106 -->
> **ESTADO PREVALENTE EM 06/11/2026:** MCP central `5580/5581` e clientes stdio Claude/Codex executam comandos administrativos de identificacao na Marina sob grant vigente. **Os aplicativos ChatGPT/Orquestrador continuam com o schema de ferramentas do cliente desatualizado**: Marina nao aceita como `host` em `admin_command_run`, `task_submit` e `service_action`; o Orquestrador ainda anuncia o host extinto `shopvivaliz-free-a1`. O runtime / `hosts_list` ser verde nao certifica o cliente. Status: `SERVER_ADMIN_APTO=true` e `CHATGPT_CLIENT_CATALOG_APTO=NAO_COMPROVADO`. Nunca usar a VM extinta; aplicar autorizacao por operacao e registrar `MCP_NOT_EXPOSED` quando o inputSchema da sessao nao expor Marina.
> **Proxima etapa oficial:** publicar/refrescar as actions MCP em **ambos** apps no workspace e validar uma ferramenta nativa, numa nova sessao, com `hostname -s; id -un` em Marina. Nao contornar bloqueio do provedor com outra rota. [Runbook e testes](../ops/mcp-client-catalog-publication-2026-11-06.md); [Evidencias persistentes](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3152#issuecomment-6677266585).
<!-- /MCP_CLIENT_CATALOG_PARITY_20261106 -->

# ChatGPT Dev — bootstrap da conta corporativa

**Conta:** `dev@shopvivaliz.com.br`  
**Papel:** engenharia/desenvolvimento ShopVivaliz  
**Migração:** substituição curada do contexto operacional da conta `fredmourao`; não é cópia integral do histórico.

## Ordem obrigatória de leitura

1. `docs/knowledge/host-access.md`
2. `docs/knowledge/README.md`
3. `docs/knowledge/agent-rules.md`
4. `docs/knowledge/project.md`
5. `docs/knowledge/browser-sessions.md`
6. `docs/knowledge/chatgpt-account-migration-curated.md`
7. `docs/knowledge/dev-agent-briefing.md`
8. documentação específica do projeto afetado.

## Identidade e sessão

- Usar exclusivamente o perfil `/home/fredrdp/.config/shopvivaliz-dev-chromium`.
- Porta CDP reservada: `9559`.
- Nunca fazer logout, trocar conta ou copiar cookies/storage dos perfis `fredmourao` ou `atendimento`.
- A existência deste documento não comprova autenticação; validar a identidade ao vivo antes de operar.

## Contexto migrado

Preservar somente conhecimento durável, decisões vigentes, regras, arquitetura, incidentes reutilizáveis e tarefas abertas revalidadas. Chats casuais, duplicados, tentativas intermediárias e status antigos ficam fora.

Histórico nunca supera evidência viva, código atual ou documentação canônica.

## Segurança

Nunca migrar ou registrar senha, token, cookie, chave, OTP/TOTP, seed ou conteúdo de secret. Usar apenas fontes seguras já provisionadas e validar autenticação por chamada real.

## Critério antes de remover fredmourao do Business

A troca de assento só está pronta quando `dev@shopvivaliz.com.br`:
- estiver adicionada ao workspace Business correto;
- estiver autenticada em seu perfil isolado;
- conseguir acessar os repositórios e ferramentas necessários;
- tiver plugins/conectores necessários configurados e testados;
- passar o bootstrap em conversa nova;
- identificar corretamente hosts, repositórios, regras de deploy e continuidade;
- executar um teste inofensivo pelo Remote Control com evidência fresca.

Até esses itens passarem, não remover `fredmourao` do workspace.

## Account access parity

Dev and Atendimento must have the same effective ShopVivaliz tool access,
workspace role and application permissions, including separate authorized
RDC 2 and ShopVivaliz MCP connections. Follow [`chatgpt-access-parity.md`](chatgpt-access-parity.md)
for the verification and acceptance gates. Do not treat two provider links
bound to the same Dev identity as independent Dev/Atendimento access.
